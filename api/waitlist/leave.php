<?php
// ================================================================
//  CANAOPTICALCLINIC — api/waitlist/leave.php
//  Patient: voluntarily withdraws from their own waitlist entry.
//  Admin/staff: removes any patient's entry on their behalf (e.g. a
//  phone request to be taken off the list).
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
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['patient', 'admin', 'staff'], true)) {
    jsonResponse(['success' => false, 'message' => 'Not authorized.'], 403);
}

$b  = getBody();
$id = (int)($b['id'] ?? 0);

if (!$id) {
    jsonResponse(['success' => false, 'message' => 'id is required.']);
}

try {
    $pdo = getDB();

    if ($role === 'patient') {
        $patientId = $_SESSION['profile_id'] ?? '';
        $stmt = $pdo->prepare(
            "SELECT * FROM appointment_waitlist WHERE id = ? AND patient_id = ? AND status IN ('waiting','offered') LIMIT 1"
        );
        $stmt->execute([$id, $patientId]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT * FROM appointment_waitlist WHERE id = ? AND status IN ('waiting','offered') LIMIT 1"
        );
        $stmt->execute([$id]);
    }
    $row = $stmt->fetch();
    if (!$row) {
        jsonResponse(['success' => false, 'message' => 'Waitlist entry not found.']);
    }

    $wasOffered = $row['status'] === 'offered';

    $pdo->prepare("UPDATE appointment_waitlist SET status = 'cancelled' WHERE id = ?")->execute([$id]);

    // Leaving an active offer forfeits it the same way declining does —
    // pass it along to the next patient in line right away.
    if ($wasOffered) {
        offerNextWaitlistSlot($pdo, $row['doctor_id'], $row['date'], $row['time']);
    }

    // Admin/staff removed the patient on their behalf — they weren't the
    // one clicking the button, so let them know it happened (a patient
    // removing themselves already knows).
    if ($role !== 'patient') {
        $ps = $pdo->prepare('SELECT user_id FROM patients WHERE id = ? LIMIT 1');
        $ps->execute([$row['patient_id']]);
        $userId = $ps->fetchColumn();
        if ($userId) {
            $fmtDate = date('M j, Y', strtotime($row['date']));
            $noticeMsg = "You've been removed from the waitlist for {$row['doctor_name']} on {$fmtDate} at {$row['time']}.";
            createNotification($pdo, (int)$userId, 'waitlist_removed', 'Removed From Waitlist', $noticeMsg);
            _emailPatientNotice($pdo, (int)$userId, 'Removed From Waitlist', $noticeMsg);
        }
    } else {
        // Mirror join.php: a genuine self-service departure is worth telling
        // admin/staff about — when they themselves remove a patient, they
        // already know (handled above).
        $fmtDate = date('M j, Y', strtotime($row['date']));
        notifyAdminStaff($pdo, 'waitlist_left', 'Patient Left Waitlist',
            "{$row['patient_name']} left the waitlist for {$row['doctor_name']} on {$fmtDate} at {$row['time']}."
        );
    }

    jsonResponse(['success' => true]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
