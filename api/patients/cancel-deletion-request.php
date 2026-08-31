<?php
// ================================================================
//  CANAOPTICALCLINIC — api/patients/cancel-deletion-request.php
//  POST (no body)  — patient only.
//  Withdraws the caller's own pending account-deletion request before
//  admin/staff has acted on it. The original notification to admin/staff
//  stays in their inbox as history (same as every other notification in
//  this app — nothing retracts a notice once sent); this only clears the
//  pending-state flag so the patient's own UI goes back to normal and
//  admin/staff no longer sees it as an open item on that patient.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if (($_SESSION['role'] ?? '') !== 'patient') {
    jsonResponse(['success' => false, 'message' => 'Only patients may cancel their own deletion request.'], 403);
}

$profileId = $_SESSION['profile_id'] ?? '';
if (!$profileId) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}

try {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'UPDATE patients SET deletion_requested_at = NULL, deletion_request_reason = NULL
         WHERE id = ? AND deletion_requested_at IS NOT NULL'
    );
    $stmt->execute([$profileId]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(['success' => false, 'message' => 'You don\'t have a pending deletion request.']);
    }

    jsonResponse(['success' => true]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
