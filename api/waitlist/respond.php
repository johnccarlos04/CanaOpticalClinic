<?php
// ================================================================
//  CANAOPTICALCLINIC — api/waitlist/respond.php
//  Patient-only. Responds to an active waitlist offer.
//  POST { id, action: 'claim' | 'decline' }
//  → claim:   { success:true, id:'A00N' }  (a new approved appointment)
//  → decline: { success:true }
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if (($_SESSION['role'] ?? '') !== 'patient') {
    jsonResponse(['success' => false, 'message' => 'Only patients may respond to a waitlist offer.'], 403);
}

$patientId = $_SESSION['profile_id'] ?? '';
$b      = getBody();
$id     = (int)($b['id']     ?? 0);
$action = trim($b['action']  ?? '');

if (!$id || !in_array($action, ['claim', 'decline'], true)) {
    jsonResponse(['success' => false, 'message' => 'id and a valid action are required.']);
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare('SELECT * FROM appointment_waitlist WHERE id = ? AND patient_id = ? LIMIT 1');
    $stmt->execute([$id, $patientId]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonResponse(['success' => false, 'message' => 'Waitlist entry not found.']);
    }

    if ($action === 'decline') {
        $upd = $pdo->prepare("UPDATE appointment_waitlist SET status = 'declined' WHERE id = ? AND status = 'offered'");
        $upd->execute([$id]);
        if ($upd->rowCount() === 0) {
            jsonResponse(['success' => false, 'message' => 'This offer is no longer active.']);
        }
        offerNextWaitlistSlot($pdo, $row['doctor_id'], $row['date'], $row['time']);
        jsonResponse(['success' => true]);
    }

    // ── Claim ──────────────────────────────────────────────────────
    // Same max-appointments-per-patient-per-day cap enforced in
    // appointments/create.php — claiming an offer creates a real
    // appointment too, so without this check a patient already at their
    // daily limit could sidestep it entirely through the waitlist. Checked
    // before the atomic claim UPDATE below so a blocked claim doesn't
    // consume the offer — it stays 'offered' for the patient to claim once
    // they're clear, or for the next person in line once it expires.
    $maxPerPatientDay = (int)($pdo->query('SELECT max_appts_per_patient_per_day FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn() ?: 1);
    $pcs = $pdo->prepare(
        "SELECT COUNT(*) FROM appointments
         WHERE patient_id = ? AND date = ? AND status NOT IN ('cancelled','disapproved')"
    );
    $pcs->execute([$row['patient_id'], $row['date']]);
    if ((int)$pcs->fetchColumn() >= $maxPerPatientDay) {
        jsonResponse(['success' => false, 'message' =>
            'You already have ' . ($maxPerPatientDay === 1 ? 'an appointment' : $maxPerPatientDay . ' appointments') . ' scheduled for this date, so this slot can\'t be claimed. Please contact the clinic directly if you need an additional visit the same day.']);
    }

    // Atomic conditional UPDATE as a mutex — same idiom as the
    // reschedule-request "fulfillRequest" flow in appointments/update.php —
    // so a claim racing the cron's expiry sweep can't double-allocate.
    $claim = $pdo->prepare(
        "UPDATE appointment_waitlist SET status = 'claimed'
         WHERE id = ? AND status = 'offered' AND offer_expires_at > NOW()"
    );
    $claim->execute([$id]);
    if ($claim->rowCount() === 0) {
        jsonResponse(['success' => false, 'message' => 'This offer has expired. Please choose a different slot.']);
    }

    $newId = createAppointmentRecord($pdo, [
        'patientId' => $row['patient_id'], 'patientName' => $row['patient_name'],
        'doctorId'  => $row['doctor_id'],  'doctorName'  => $row['doctor_name'],
        'date'      => $row['date'],       'time'        => $row['time'],
        'type'      => $row['type'],       'status'      => 'approved',
        'source'    => 'online', // this whole endpoint is patient-only (see the role check above)
        'notes'     => '',                 'termsAgreed' => $row['terms_agreed'],
    ]);

    $ps = $pdo->prepare('SELECT user_id FROM patients WHERE id = ? LIMIT 1');
    $ps->execute([$patientId]);
    $userId = $ps->fetchColumn();
    if ($userId) {
        $fmtDate = date('M j, Y', strtotime($row['date']));
        createNotification($pdo, (int)$userId, 'approved', 'Appointment Confirmed',
            "Your waitlisted appointment with {$row['doctor_name']} on {$fmtDate} at {$row['time']} is confirmed."
        );
    }

    jsonResponse(['success' => true, 'id' => $newId]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
