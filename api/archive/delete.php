<?php
// ================================================================
//  CANAOPTICALCLINIC — api/archive/delete.php
//  Admin only. Permanently deletes an archived account/patient —
//  removes the role row (cascading to appointments/examinations/
//  prescriptions/consultations), the login row, and the archive
//  entry itself. This is irreversible.
//
//  POST { id }   — archived_records.id
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if ($_SESSION['role'] !== 'admin') {
    jsonResponse(['success' => false, 'message' => 'Only admins may permanently delete records.'], 403);
}

$b  = getBody();
$id = trim($b['id'] ?? '');
if (!$id) {
    jsonResponse(['success' => false, 'message' => 'id is required.']);
}

try {
    $pdo = getDB();

    $s = $pdo->prepare('SELECT * FROM archived_records WHERE id = ? LIMIT 1');
    $s->execute([$id]);
    $rec = $s->fetch();
    if (!$rec) {
        jsonResponse(['success' => false, 'message' => 'Archived record not found.']);
    }

    // Services have no FK references from appointments (type is stored as
    // plain text there), so a hard delete is safe — no cascade needed.
    if ($rec['type'] === 'Service') {
        $pdo->prepare('DELETE FROM clinic_services WHERE id = ?')->execute([$rec['ref_id']]);
        $pdo->prepare('DELETE FROM archived_records WHERE id = ?')->execute([$id]);
        jsonResponse(['success' => true]);
    }

    // Examinations — same cascade api/examinations/delete.php used to do in
    // one step (that hard-delete path now only runs via this Archives-page
    // "Delete Forever" action, after the exam has already been archived).
    if ($rec['type'] === 'Examination') {
        $ex = $pdo->prepare('SELECT id, consultation_id FROM examinations WHERE id = ? LIMIT 1');
        $ex->execute([$rec['ref_id']]);
        $exam = $ex->fetch();

        if ($exam) {
            $pdo->beginTransaction();
            // Prescription links back via exam_id.
            $pdo->prepare('DELETE FROM prescriptions WHERE exam_id = ?')->execute([$rec['ref_id']]);
            // Consultation links via examinations.consultation_id — delete it
            // explicitly before the exam row itself (there's no ON DELETE
            // CASCADE from examinations to consultations, only the reverse FK).
            if ($exam['consultation_id']) {
                $pdo->prepare('DELETE FROM consultations WHERE id = ?')->execute([$exam['consultation_id']]);
            }
            $pdo->prepare('DELETE FROM examinations WHERE id = ?')->execute([$rec['ref_id']]);
            $pdo->commit();
        }
        // Exam may already be gone (e.g. deleted some other way) — still
        // clean up the archive entry either way.
        $pdo->prepare('DELETE FROM archived_records WHERE id = ?')->execute([$id]);
        jsonResponse(['success' => true]);
    }

    $data  = $rec['data_json'] ? json_decode($rec['data_json'], true) : [];
    $role  = $data['role'] ?? ($rec['type'] === 'Patient' ? 'Patient' : null);
    $tableMap = ['Admin' => 'admins', 'Staff' => 'staff', 'Doctor' => 'doctors', 'Patient' => 'patients'];
    $table = $tableMap[$role] ?? null;

    if ($table) {
        $rs = $pdo->prepare("SELECT user_id FROM `{$table}` WHERE id = ? LIMIT 1");
        $rs->execute([$rec['ref_id']]);
        $row = $rs->fetch();

        // Cascades to appointments/examinations/prescriptions/consultations
        $pdo->prepare("DELETE FROM `{$table}` WHERE id = ?")->execute([$rec['ref_id']]);

        if ($row && $row['user_id']) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$row['user_id']]);
        }
    }

    $pdo->prepare('DELETE FROM archived_records WHERE id = ?')->execute([$id]);

    jsonResponse(['success' => true]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
