<?php
// ================================================================
//  CANAOPTICALCLINIC — api/cron/appointment_reminders.php
//  Not user-facing. Meant to be hit on a schedule (every 5 minutes) by an
//  external scheduler — Railway's own cron/scheduled-job feature,
//  a pinger like cron-job.org, or a scheduled GitHub Action — since this
//  repo has no in-process job runner. Auth via a shared secret in the
//  `key` query param, set as the CRON_SECRET environment variable.
//
//  GET/POST /api/cron/appointment_reminders.php?key=...
//
//  Each run does three independent jobs:
//   1. Send the day-before reminder at the admin-configured reminder time
//      (Clinic Settings → Scheduling Rules, default noon) for approved
//      appointments happening tomorrow that haven't been reminded yet.
//   2. Auto-cancel approved appointments happening tomorrow that were
//      reminded but not confirmed by the admin-configured deadline (default
//      9 PM) that same day, freeing the slot to the next waitlisted patient.
//   3. Expire stale waitlist offers past their claim window, cascading
//      the offer to the next patient in line for that slot.
//   4. Remove still-"waiting" entries whose slot is now too close to ever
//      realistically be offered, and let the patient know — instead of
//      leaving them waiting indefinitely for something that's become
//      impossible.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

$providedKey = $_GET['key'] ?? $_POST['key'] ?? '';
$cronSecret  = getenv('CRON_SECRET') ?: 'cana-local-dev-cron-key';
if (!$providedKey || !hash_equals($cronSecret, $providedKey)) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
}

// Every patient-facing notice below previously only ever reached the
// in-app inbox via createNotification() — nothing outside the app itself
// (email, let alone SMS) ever told a patient their appointment needed
// confirming, so anyone not actively logged in when the cron fired would
// just find it auto-cancelled later with no warning. _emailPatientNotice()
// (api/helpers.php) sends the same notice by email too — also used by
// api/waitlist/leave.php's manual-removal path for the same reason.
$result = ['success' => true, 'remindersSent' => 0, 'autoCancelled' => 0, 'offersExpired' => 0, 'staleWaitlistRemoved' => 0];

try {
    $pdo = getDB();

    $reminderHourStr = settingTimeTo24h(reminderTimeSetting($pdo));
    $deadlineHourStr = settingTimeTo24h(confirmDeadlineTimeSetting($pdo));

    // ── Job 1: send day-before reminder ─────────────────────────────
    $dueReminders = $pdo->prepare(
        "SELECT id, patient_id, doctor_name, date, time FROM appointments
         WHERE status = 'approved' AND date = CURDATE() + INTERVAL 1 DAY
           AND reminder_sent_at IS NULL AND CURTIME() >= ?"
    );
    $dueReminders->execute([$reminderHourStr]);
    $reminderRows = $dueReminders->fetchAll();

    foreach ($reminderRows as $r) {
        $pdo->prepare('UPDATE appointments SET reminder_sent_at = NOW() WHERE id = ? AND reminder_sent_at IS NULL')
            ->execute([$r['id']]);

        if (!$r['patient_id']) continue;
        $ps = $pdo->prepare('SELECT user_id FROM patients WHERE id = ? LIMIT 1');
        $ps->execute([$r['patient_id']]);
        $userId = $ps->fetchColumn();
        if ($userId) {
            $fmtDate = date('M j, Y', strtotime($r['date']));
            $noticeMsg = "You have an appointment with {$r['doctor_name']} tomorrow ({$fmtDate}) at {$r['time']}. "
              . "Please confirm by " . date('g:i A', strtotime($deadlineHourStr)) . " today or it will be automatically cancelled.";
            createNotification($pdo, (int)$userId, 'reminder', 'Confirm Your Appointment', $noticeMsg, $r['id']);
            _emailPatientNotice($pdo, (int)$userId, 'Confirm Your Appointment', $noticeMsg);
        }
    }
    $result['remindersSent'] = count($reminderRows);

    // ── Job 2: auto-cancel unconfirmed appointments past the deadline ──
    $dueCancel = $pdo->prepare(
        "SELECT id, patient_id, doctor_id, doctor_name, date, time FROM appointments
         WHERE status = 'approved' AND date = CURDATE() + INTERVAL 1 DAY
           AND reminder_sent_at IS NOT NULL AND confirmed_at IS NULL AND CURTIME() >= ?"
    );
    $dueCancel->execute([$deadlineHourStr]);
    $cancelRows = $dueCancel->fetchAll();

    $deadlineLabel = date('g:i A', strtotime($deadlineHourStr));
    foreach ($cancelRows as $r) {
        $upd = $pdo->prepare(
            "UPDATE appointments SET status = 'cancelled',
                cancellation_reason = ?
             WHERE id = ? AND status = 'approved'"
        );
        $upd->execute(["Automatically cancelled. No confirmation received by {$deadlineLabel}.", $r['id']]);
        if ($upd->rowCount() === 0) continue;

        if ($r['doctor_id']) {
            offerNextWaitlistSlot($pdo, $r['doctor_id'], $r['date'], $r['time']);
        }
        if (!$r['patient_id']) continue;
        $ps = $pdo->prepare('SELECT user_id FROM patients WHERE id = ? LIMIT 1');
        $ps->execute([$r['patient_id']]);
        $userId = $ps->fetchColumn();
        if ($userId) {
            $fmtDate = date('M j, Y', strtotime($r['date']));
            $noticeMsg = "Your appointment with {$r['doctor_name']} on {$fmtDate} at {$r['time']} was automatically cancelled because no confirmation was received.";
            createNotification($pdo, (int)$userId, 'cancelled', 'Appointment Automatically Cancelled', $noticeMsg);
            _emailPatientNotice($pdo, (int)$userId, 'Appointment Automatically Cancelled', $noticeMsg);
        }
    }
    $result['autoCancelled'] = count($cancelRows);

    // ── Job 3: expire stale waitlist offers and cascade to next in line ──
    $dueExpire = $pdo->query(
        "SELECT id, doctor_id, date, time FROM appointment_waitlist
         WHERE status = 'offered' AND offer_expires_at <= NOW()"
    )->fetchAll();

    foreach ($dueExpire as $r) {
        $upd = $pdo->prepare("UPDATE appointment_waitlist SET status = 'expired' WHERE id = ? AND status = 'offered'");
        $upd->execute([$r['id']]);
        if ($upd->rowCount() === 0) continue; // already claimed in the meantime

        offerNextWaitlistSlot($pdo, $r['doctor_id'], $r['date'], $r['time']);
    }
    $result['offersExpired'] = count($dueExpire);

    // ── Job 4: remove stale "waiting" entries that can no longer ever be
    //    offered, and notify the patient ─────────────────────────────
    // A "waiting" entry is only ever fulfilled by some other appointment
    // for the exact same slot getting cancelled, which then runs through
    // waitlistHasEnoughLeadTime() before an offer is made — so that same
    // check is the single source of truth for "is this entry still
    // fulfillable." Deliberately NOT layering the day-level Minimum Advance
    // Booking policy on top of it here: that policy governs brand-new,
    // self-service bookings, not an already-waitlisted patient. The
    // reminder → 9pm confirm-deadline → auto-cancel cycle for a "tomorrow"
    // appointment runs entirely within 24 hours of the slot — a day-based
    // cutoff would expire the waitlisted patient hours before that
    // auto-cancel even has a chance to run, defeating the exact case the
    // waitlist exists for.
    $waitingRows = $pdo->query(
        "SELECT id, patient_id, doctor_name, date, time FROM appointment_waitlist WHERE status = 'waiting'"
    )->fetchAll();

    $staleRemoved = 0;
    foreach ($waitingRows as $w) {
        if (waitlistHasEnoughLeadTime($pdo, $w['date'], $w['time'])) continue; // still fulfillable — leave it waiting

        $upd = $pdo->prepare("UPDATE appointment_waitlist SET status = 'expired' WHERE id = ? AND status = 'waiting'");
        $upd->execute([$w['id']]);
        if ($upd->rowCount() === 0) continue; // changed by another process in the meantime

        $staleRemoved++;
        if (!$w['patient_id']) continue;
        $ps = $pdo->prepare('SELECT user_id FROM patients WHERE id = ? LIMIT 1');
        $ps->execute([$w['patient_id']]);
        $userId = $ps->fetchColumn();
        if ($userId) {
            $fmtDate = date('M j, Y', strtotime($w['date']));
            $noticeMsg = "You've been removed from the waitlist for {$w['doctor_name']} on {$fmtDate} at {$w['time']}. "
              . "It's too close to that time now for the slot to open up for you. "
              . "Please request a new appointment if you'd still like to be seen.";
            createNotification($pdo, (int)$userId, 'info', 'Removed From Waitlist', $noticeMsg);
            _emailPatientNotice($pdo, (int)$userId, 'Removed From Waitlist', $noticeMsg);
        }
    }
    $result['staleWaitlistRemoved'] = $staleRemoved;

    jsonResponse($result);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
}
