<?php
// ================================================================
//  CANAOPTICALCLINIC — api/examinations/delete.php
//  POST { examId, patientId }
//  → { success:true } | { success:false, message }
//
//  Deletes an examination together with its linked consultation and
//  prescription (if any) — the three rows create.php writes together for
//  one visit are removed together too, instead of leaving orphaned
//  fragments behind. Doctors may only delete an exam they themselves
//  created; admin/staff may delete any doctor's.
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

if (!in_array($role, ['admin', 'staff', 'doctor'], true)) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$b = getBody();
$examId    = trim($b['examId']    ?? '');
$patientId = trim($b['patientId'] ?? '');

if (!$examId || !$patientId) {
    jsonResponse(['success' => false, 'message' => 'examId and patientId are required.']);
}

try {
    $pdo = getDB();

    $exam = $pdo->prepare('SELECT id, doctor_id, consultation_id FROM examinations WHERE id = ? AND patient_id = ?');
    $exam->execute([$examId, $patientId]);
    $exam = $exam->fetch();
    if (!$exam) {
        jsonResponse(['success' => false, 'message' => 'Examination record not found.'], 404);
    }

    // A doctor may only delete an exam they themselves created — admin/staff
    // may delete any doctor's exam for administrative cleanup.
    if ($role === 'doctor' && $exam['doctor_id'] !== $profileId) {
        jsonResponse(['success' => false, 'message' => 'You can only delete examinations you created yourself.'], 403);
    }

    $pdo->beginTransaction();

    // Prescription links back via exam_id.
    $pdo->prepare('DELETE FROM prescriptions WHERE exam_id = ?')->execute([$examId]);

    // Consultation links via examinations.consultation_id — delete it
    // explicitly before the exam row itself (there's no ON DELETE CASCADE
    // from examinations to consultations, only the reverse FK).
    if ($exam['consultation_id']) {
        $pdo->prepare('DELETE FROM consultations WHERE id = ?')->execute([$exam['consultation_id']]);
    }

    $pdo->prepare('DELETE FROM examinations WHERE id = ?')->execute([$examId]);

    // ── Activity log ───────────────────────────────────────────────
    $ptRow = $pdo->prepare('SELECT first_name, last_name FROM patients WHERE id = ? LIMIT 1');
    $ptRow->execute([$patientId]);
    $ptRow = $ptRow->fetch();
    $ptName = $ptRow ? $ptRow['first_name'] . ' ' . $ptRow['last_name'] : $patientId;

    $staffName = '';
    switch ($role) {
        case 'doctor':
            $drRow = $pdo->prepare('SELECT first_name, last_name FROM doctors WHERE id = ? LIMIT 1');
            $drRow->execute([$profileId]);
            $drRow = $drRow->fetch();
            $staffName = $drRow ? 'Dr. ' . $drRow['first_name'] . ' ' . $drRow['last_name'] : 'Doctor';
            break;
        default:
            $uRow = $pdo->prepare('SELECT u.id, p.first_name, p.last_name FROM users u LEFT JOIN admins p ON p.user_id = u.id WHERE u.id = ? LIMIT 1');
            $uRow->execute([(int)$_SESSION['user_id']]);
            $uRow = $uRow->fetch();
            $staffName = $uRow ? ($uRow['first_name'] . ' ' . $uRow['last_name']) : 'Staff';
    }

    $logId = 'L' . date('YmdHis') . rand(100, 999);
    $pdo->prepare(
        'INSERT IGNORE INTO activity_log (id, user_name, role, action, timestamp, type)
         VALUES (?,?,?,?,NOW(),?)'
    )->execute([
        substr($logId, 0, 20),
        $staffName,
        ucfirst($role),
        "Deleted optical examination {$examId} for {$ptName} ({$patientId})",
        'examination',
    ]);

    $pdo->commit();

    jsonResponse(['success' => true]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
