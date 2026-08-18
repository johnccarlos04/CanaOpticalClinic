<?php
// ================================================================
//  CANAOPTICALCLINIC — api/appointments/confirm.php
//  Patient-only. Confirms attendance after a reminder was sent, so the
//  cron's auto-cancel doesn't fire on this appointment.
//  POST { id }
//  → { success:true }
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if (($_SESSION['role'] ?? '') !== 'patient') {
    jsonResponse(['success' => false, 'message' => 'Only patients may confirm their own appointment.'], 403);
}

$patientId = $_SESSION['profile_id'] ?? '';
$b  = getBody();
$id = trim($b['id'] ?? '');

if (!$id) {
    jsonResponse(['success' => false, 'message' => 'id is required.']);
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare('SELECT * FROM appointments WHERE id = ? AND patient_id = ? LIMIT 1');
    $stmt->execute([$id, $patientId]);
    $appt = $stmt->fetch();
    if (!$appt) {
        jsonResponse(['success' => false, 'message' => 'Appointment not found.']);
    }
    if ($appt['status'] !== 'approved') {
        jsonResponse(['success' => false, 'message' => 'Only approved appointments can be confirmed.']);
    }
    if (!$appt['reminder_sent_at']) {
        jsonResponse(['success' => false, 'message' => 'There is no pending confirmation for this appointment.']);
    }
    if ($appt['confirmed_at']) {
        jsonResponse(['success' => true]); // already confirmed — idempotent
    }

    $pdo->prepare('UPDATE appointments SET confirmed_at = NOW() WHERE id = ?')->execute([$id]);

    // Let admin/staff know the patient confirmed attendance — so the front
    // desk isn't left guessing whether tomorrow's slot is still a go.
    $fmtDate = date('M j, Y', strtotime($appt['date']));
    notifyAdminStaff($pdo, 'appointment_confirmed', 'Patient Confirmed Attendance',
        "{$appt['patient_name']} confirmed attendance for their appointment with {$appt['doctor_name']} on {$fmtDate} at {$appt['time']}."
    );

    // The current holder just confirmed they're actually coming, so this
    // slot is no longer a realistic prospect — waiting on it any longer
    // just delays whoever's queued from booking something that's actually
    // available. Drop them from the waitlist and point them at picking a
    // new time, rather than leaving them queued on a slot that's now
    // effectively locked in.
    if ($appt['doctor_id']) {
        $noticeMsg = "The patient holding the {$appt['doctor_name']} appointment on {$fmtDate} at {$appt['time']} you were waitlisted for has confirmed their attendance, "
          . "so that slot is no longer expected to open up. You've been removed from the waitlist, please select another available time.";
        clearWaitlistForLockedSlot($pdo, $appt['doctor_id'], $appt['date'], $appt['time'], $noticeMsg);
    }

    jsonResponse(['success' => true]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
