<?php
// ================================================================
//  CANAOPTICALCLINIC — api/appointments/update.php
//
//  POST { id, action, ...fields }
//
//  action:'status'             → { status, cancellationReason? }
//  action:'reschedule'         → { date, time, rescheduleNote? }
//  action:'request_reschedule' → { reason, preferredDate? }
//  action:'dismiss_reschedule' → (no extra fields)
//  action:'assign_doctor'      → { doctorId }  — admin/staff only, for
//                                 "any available optometrist" requests
//                                 (doctor_id still NULL)
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}

$role      = $_SESSION['role']       ?? '';
$profileId = $_SESSION['profile_id'] ?? '';
$userId    = (int)$_SESSION['user_id'];

$b      = getBody();
$id     = trim($b['id']     ?? '');
$action = trim($b['action'] ?? '');

if (!$id || !$action) {
    jsonResponse(['success' => false, 'message' => 'id and action are required.']);
}

try {
    $pdo = getDB();

    // Load current appointment
    $stmt = $pdo->prepare('SELECT * FROM appointments WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $appt = $stmt->fetch();
    if (!$appt) {
        jsonResponse(['success' => false, 'message' => 'Appointment not found.']);
    }

    // Access control: patients may only touch their own appointments
    if ($role === 'patient' && $appt['patient_id'] !== $profileId) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
    }
    // Doctors may only touch their own appointments (for status changes)
    if ($role === 'doctor' && $appt['doctor_id'] !== $profileId) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
    }

    // Helper: get patient's user_id for notification targeting
    $getPatientUserId = function() use ($pdo, $appt): ?int {
        $ps = $pdo->prepare('SELECT user_id FROM patients WHERE id = ? LIMIT 1');
        $ps->execute([$appt['patient_id']]);
        $row = $ps->fetch();
        return ($row && $row['user_id']) ? (int)$row['user_id'] : null;
    };

    // ── Status change (approve / cancel / disapprove / completed) ──
    if ($action === 'status') {
        $newStatus = trim($b['status'] ?? '');
        $allowed   = ['pending', 'approved', 'cancelled', 'disapproved', 'completed', 'no-show'];
        if (!in_array($newStatus, $allowed, true)) {
            jsonResponse(['success' => false, 'message' => 'Invalid status.']);
        }
        $cancelReason      = trim($b['cancellationReason'] ?? '');
        $disapprovalReason = trim($b['disapprovalReason'] ?? '');

        // Patients may only cancel their own
        if ($role === 'patient' && !in_array($newStatus, ['cancelled'], true)) {
            jsonResponse(['success' => false, 'message' => 'Patients may only cancel appointments.'], 403);
        }

        // An "any available optometrist" request (doctor_id still NULL — see
        // appointments/create.php's anyDoctor path) needs an actual doctor
        // assigned (action=assign_doctor) before it can be approved — mirrors
        // the disabled Approve button in the frontend's appointment detail
        // modal, enforced here too so a direct API call can't skip it.
        if ($newStatus === 'approved' && !$appt['doctor_id']) {
            jsonResponse(['success' => false, 'message' => 'Please assign a doctor to this appointment before approving it.']);
        }

        // Patients can only cancel up to CANCEL_DEADLINE_HOURS before the
        // appointment — mirrors the same window enforced client-side
        // (pages.js's CANCEL_DEADLINE_HOURS) so a direct API call can't bypass it.
        if ($role === 'patient' && $newStatus === 'cancelled') {
            $apptDt = DateTime::createFromFormat('Y-m-d g:i A', $appt['date'] . ' ' . $appt['time']);
            if ($apptDt && (time() > $apptDt->getTimestamp() - 24 * 3600)) {
                jsonResponse(['success' => false, 'message' => "This appointment can no longer be cancelled online — cancellations require at least 24 hours' notice. Please call the clinic directly."]);
            }
        }

        // Deciding the appointment's status (approve/disapprove/cancel/complete)
        // supersedes any reschedule request still sitting on it — otherwise the
        // "Reschedule Req." flag keeps showing after the appointment has already
        // moved on, even though nobody addressed the patient's actual ask.
        $pdo->prepare(
            'UPDATE appointments SET status = ?, cancellation_reason = ?, disapproval_reason = ?, reschedule_request = NULL WHERE id = ?'
        )->execute([$newStatus, $cancelReason ?: null, $newStatus === 'disapproved' ? ($disapprovalReason ?: null) : null, $id]);

        // Update last_visit on patient if completed
        if ($newStatus === 'completed' && $appt['patient_id']) {
            $pdo->prepare('UPDATE patients SET last_visit = ? WHERE id = ?')
                ->execute([$appt['date'], $appt['patient_id']]);
        }

        // The visit already happened — this slot is now permanently locked
        // in, even more finally than a patient just confirming attendance
        // (see confirm.php). Anyone still waitlisted for this exact
        // doctor+date+time is queued on something that will never open up,
        // so clear them out and let them know, same as confirm.php does.
        if ($newStatus === 'completed' && $appt['doctor_id']) {
            $fmtDate = date('M j, Y', strtotime($appt['date']));
            $noticeMsg = "The {$appt['doctor_name']} appointment on {$fmtDate} at {$appt['time']} you were waitlisted for has already taken place, "
              . "so that slot will not be opening up. You've been removed from the waitlist, please select another available time.";
            clearWaitlistForLockedSlot($pdo, $appt['doctor_id'], $appt['date'], $appt['time'], $noticeMsg);
        }

        // Manually marking a no-show (same-day, before the auto-transition in
        // appointments/index.php would otherwise catch it tomorrow) counts
        // against the patient the same way the automatic path does.
        $justRestricted = false;
        if ($newStatus === 'no-show' && $appt['patient_id']) {
            $justRestricted = recordNoShow($pdo, $appt['patient_id']);
        }

        // The slot just freed up — offer it to whoever's first on the
        // waitlist for this exact doctor+date+time, if anyone is waiting.
        // Mirrors checkApptConflict()'s own definition of "not occupying a
        // slot" (status NOT IN ('cancelled','disapproved')) — 'no-show' is
        // included too, though it's a same-day/already-past status by
        // definition so it's rarely the trigger in practice.
        if (in_array($newStatus, ['cancelled', 'disapproved', 'no-show'], true) && $appt['doctor_id']) {
            offerNextWaitlistSlot($pdo, $appt['doctor_id'], $appt['date'], $appt['time']);
        }

        // Notify patient when status changes (not for patient-initiated cancel)
        if ($role !== 'patient' && $patientUid = $getPatientUserId()) {
            $fmtDate = date('M j, Y', strtotime($appt['date']));
            $doctor  = $appt['doctor_name'] ?? 'your doctor';
            if ($newStatus === 'approved') {
                createNotification($pdo, $patientUid, 'approved',
                    'Appointment Approved',
                    "Your appointment with {$doctor} on {$fmtDate} at {$appt['time']} has been approved."
                );
            } elseif ($newStatus === 'cancelled') {
                createNotification($pdo, $patientUid, 'cancelled',
                    'Appointment Cancelled',
                    "Your appointment with {$doctor} on {$fmtDate} has been cancelled by the clinic."
                    . ($cancelReason ? " Reason: {$cancelReason}" : '')
                );
            } elseif ($newStatus === 'disapproved') {
                createNotification($pdo, $patientUid, 'disapproved',
                    'Appointment Not Approved',
                    "Your appointment request with {$doctor} on {$fmtDate} could not be approved."
                    . ($disapprovalReason ? " Reason: {$disapprovalReason}" : '')
                );
            } elseif ($newStatus === 'no-show') {
                $msg = "You were marked as a no-show for your appointment with {$doctor} on {$fmtDate} at {$appt['time']}.";
                if ($justRestricted) {
                    $msg .= ' Due to repeated missed appointments, online booking has been temporarily restricted for your account. Please contact the clinic directly to schedule.';
                }
                createNotification($pdo, $patientUid, 'no_show', 'Missed Appointment', $msg);
            }
        }

        jsonResponse(['success' => true]);
    }

    // ── Admin/staff reschedule (change date + time) ────────────────
    if ($action === 'reschedule') {
        if (!in_array($role, ['admin', 'staff'], true)) {
            jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
        }
        $newDate = trim($b['date'] ?? '');
        $newTime = trim($b['time'] ?? '');
        $note    = trim($b['rescheduleNote'] ?? '');
        // True when this reschedule is fulfilling a specific patient-submitted
        // request (the "Accept & Reschedule" button), as opposed to an ad-hoc
        // reschedule the staff/admin initiated on their own.
        $fulfillRequest = !empty($b['fulfillRequest']);
        if (!$newDate || !$newTime) {
            jsonResponse(['success' => false, 'message' => 'Date and time are required.']);
        }

        // Conflict check — exclude the appointment being rescheduled itself.
        $durStr = $pdo->query('SELECT default_duration FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn();
        preg_match('/(\d+)/', $durStr ?: '30', $dm);
        $durationMin = isset($dm[1]) ? (int)$dm[1] : 30;
        $conflict = checkApptConflict($pdo, $appt['doctor_id'], $newDate, $newTime, $durationMin, $id);
        if ($conflict !== null) {
            jsonResponse(['success' => false, 'message' =>
                "This time conflicts with an existing appointment at {$conflict}. "
              . "Please choose a different slot."]);
        }

        if ($fulfillRequest) {
            // Only apply if the request is still pending — guards against the
            // same request being accepted twice from two different sessions/tabs
            // (e.g. admin and staff both viewing it), which would otherwise let
            // a stale second click silently overwrite the already-applied change.
            $upd = $pdo->prepare(
                'UPDATE appointments SET date = ?, time = ?, reschedule_note = ?, reschedule_request = NULL, rescheduled_at = NOW()
                 WHERE id = ? AND reschedule_request IS NOT NULL'
            );
            $upd->execute([$newDate, $newTime, $note ?: null, $id]);
            if ($upd->rowCount() === 0) {
                jsonResponse(['success' => false, 'message' => 'This reschedule request was already handled.']);
            }
        } else {
            $pdo->prepare(
                'UPDATE appointments SET date = ?, time = ?, reschedule_note = ?, reschedule_request = NULL, rescheduled_at = NOW() WHERE id = ?'
            )->execute([$newDate, $newTime, $note ?: null, $id]);
        }

        // The appointment just vacated its *original* doctor+date+time slot
        // (the $appt values fetched above, before this update) — offer that
        // slot to the waitlist the same way a cancellation does below. This
        // was missing entirely: rescheduling an appointment away from a slot
        // never told anyone waiting on it that it had actually opened up.
        if ($appt['doctor_id']) {
            offerNextWaitlistSlot($pdo, $appt['doctor_id'], $appt['date'], $appt['time']);
        }

        // Notify patient of reschedule — email too, not just in-app, since
        // showing up at the old date/time (rather than just missing a
        // "good to know" update) is a real, tangible consequence of not
        // seeing this in time. Same reasoning as the waitlist-offer and
        // cron-reminder emails elsewhere in this file/helpers.php.
        if ($patientUid = $getPatientUserId()) {
            $fmtDate = date('M j, Y', strtotime($newDate));
            $rescheduleMsg = "Your appointment has been rescheduled to {$fmtDate} at {$newTime}."
                . ($note ? " Note: {$note}" : '');
            createNotification($pdo, $patientUid, 'rescheduled', 'Appointment Rescheduled', $rescheduleMsg);
            _emailPatientNotice($pdo, $patientUid, 'Appointment Rescheduled', $rescheduleMsg);
        }

        jsonResponse(['success' => true]);
    }

    // ── Patient requests a reschedule ──────────────────────────────
    if ($action === 'request_reschedule') {
        if ($role !== 'patient') {
            jsonResponse(['success' => false, 'message' => 'Only patients may submit reschedule requests.'], 403);
        }

        // Same 24h cutoff as cancellation (see the 'cancelled' branch above) —
        // mirrors pages.js's RESCHEDULE_DEADLINE_HOURS so a direct API call
        // can't bypass it.
        $apptDt = DateTime::createFromFormat('Y-m-d g:i A', $appt['date'] . ' ' . $appt['time']);
        if ($apptDt && (time() > $apptDt->getTimestamp() - 24 * 3600)) {
            jsonResponse(['success' => false, 'message' => "This appointment can no longer be rescheduled online — reschedule requests require at least 24 hours' notice. Please call the clinic directly."]);
        }

        $reason   = trim($b['reason']        ?? '');
        $prefDate = trim($b['preferredDate'] ?? '');
        $prefTime = trim($b['preferredTime'] ?? '');
        if (!$reason) {
            jsonResponse(['success' => false, 'message' => 'Please provide a reason.']);
        }
        $payload = json_encode([
            'reason'        => $reason,
            'preferredDate' => $prefDate ?: null,
            'preferredTime' => $prefTime ?: null,
            'requestedAt'   => date('Y-m-d H:i'),
        ]);
        $pdo->prepare('UPDATE appointments SET reschedule_request = ? WHERE id = ?')
            ->execute([$payload, $id]);

        // Notify admin/staff of the reschedule request
        $patName = $appt['patient_name'] ?? 'A patient';
        $prefSlot = $prefDate ? (" Preferred: {$prefDate}" . ($prefTime ? " at {$prefTime}" : '') . '.') : '';
        notifyAdminStaff($pdo, 'reschedule_request',
            'Reschedule Request',
            "{$patName} has requested to reschedule appointment #{$id}.{$prefSlot}"
        );

        jsonResponse(['success' => true]);
    }

    // ── Admin/staff dismiss reschedule request ─────────────────────
    if ($action === 'dismiss_reschedule') {
        if (!in_array($role, ['admin', 'staff'], true)) {
            jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
        }
        $pdo->prepare('UPDATE appointments SET reschedule_request = NULL WHERE id = ?')
            ->execute([$id]);
        jsonResponse(['success' => true]);
    }

    // ── Admin/staff assign a doctor to an "any available optometrist" ──
    // request (created with doctor_id NULL — see appointments/create.php's
    // anyDoctor path). Only valid while no doctor is assigned yet, so this
    // can't be used to silently swap a patient's already-chosen doctor
    // (that's what 'reschedule' plus picking a new doctor would be for).
    if ($action === 'assign_doctor') {
        if (!in_array($role, ['admin', 'staff'], true)) {
            jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
        }
        if ($appt['doctor_id']) {
            jsonResponse(['success' => false, 'message' => 'This appointment already has a doctor assigned.']);
        }
        $newDoctorId = trim($b['doctorId'] ?? '');
        if (!$newDoctorId) {
            jsonResponse(['success' => false, 'message' => 'Please choose a doctor.']);
        }
        $ds = $pdo->prepare('SELECT first_name, middle_name, last_name FROM doctors WHERE id = ? AND status = ? LIMIT 1');
        $ds->execute([$newDoctorId, 'active']);
        $doc = $ds->fetch();
        if (!$doc) {
            jsonResponse(['success' => false, 'message' => 'Doctor not found.']);
        }
        $newDoctorName = 'Dr. ' . trim($doc['first_name'] . _mi($doc['middle_name']) . ' ' . $doc['last_name']);

        $durStr = $pdo->query('SELECT default_duration FROM clinic_settings WHERE id = 1 LIMIT 1')->fetchColumn();
        preg_match('/(\d+)/', $durStr ?: '30', $dm);
        $durationMin = isset($dm[1]) ? (int)$dm[1] : 30;
        $conflict = checkApptConflict($pdo, $newDoctorId, $appt['date'], $appt['time'], $durationMin, $id);
        if ($conflict !== null) {
            jsonResponse(['success' => false, 'message' =>
                "This doctor already has an appointment at {$conflict}. Please choose a different doctor."]);
        }

        $pdo->prepare('UPDATE appointments SET doctor_id = ?, doctor_name = ? WHERE id = ?')
            ->execute([$newDoctorId, $newDoctorName, $id]);

        if ($patientUid = $getPatientUserId()) {
            $fmtDate = date('M j, Y', strtotime($appt['date']));
            createNotification($pdo, $patientUid, 'new_appointment',
                'Doctor Assigned',
                "{$newDoctorName} has been assigned to your appointment on {$fmtDate} at {$appt['time']}."
            );
        }

        jsonResponse(['success' => true, 'doctorName' => $newDoctorName]);
    }

    jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
