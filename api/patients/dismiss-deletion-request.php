<?php
// ================================================================
//  CANAOPTICALCLINIC — api/patients/dismiss-deletion-request.php
//  POST { patientId }  — admin/staff only.
//  Clears a pending account-deletion request WITHOUT archiving/deleting
//  anything — the other resolution path besides actually archiving the
//  patient (api/archive/create.php, which clears the same flag as a side
//  effect of fulfilling the request). Use this when the account should
//  stay as-is (e.g. an open balance, an upcoming appointment, or admin
//  just wants to follow up with the patient directly first).
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if (!in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$b         = getBody();
$patientId = trim($b['patientId'] ?? '');
if (!$patientId) {
    jsonResponse(['success' => false, 'message' => 'patientId is required.']);
}

try {
    $pdo = getDB();

    $s = $pdo->prepare('SELECT first_name, middle_name, last_name, user_id, deletion_requested_at FROM patients WHERE id = ? LIMIT 1');
    $s->execute([$patientId]);
    $pt = $s->fetch();
    if (!$pt) {
        jsonResponse(['success' => false, 'message' => 'Patient not found.']);
    }
    if (!$pt['deletion_requested_at']) {
        jsonResponse(['success' => false, 'message' => 'This patient has no pending deletion request.']);
    }

    $pdo->prepare(
        'UPDATE patients SET deletion_requested_at = NULL, deletion_request_reason = NULL WHERE id = ?'
    )->execute([$patientId]);

    if ($pt['user_id']) {
        createNotification($pdo, (int)$pt['user_id'], 'info', 'Deletion Request Reviewed',
            'Clinic staff reviewed your account deletion request and your account will remain active. Contact the clinic directly if you have questions.'
        );
    }

    jsonResponse(['success' => true]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
