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

// Same reasoning as request-deletion.php's own limit — this also notifies
// every admin/staff account, so a fast request/cancel loop needs the same
// ceiling on both ends, not just the request side.
rateLimit('cancel-deletion-request:' . $_SESSION['user_id'], 5, 3600); // 5 per user per hour

try {
    $pdo = getDB();

    $s = $pdo->prepare('SELECT first_name, middle_name, last_name FROM patients WHERE id = ? LIMIT 1');
    $s->execute([$profileId]);
    $pt = $s->fetch();

    $stmt = $pdo->prepare(
        'UPDATE patients SET deletion_requested_at = NULL, deletion_request_reason = NULL
         WHERE id = ? AND deletion_requested_at IS NOT NULL'
    );
    $stmt->execute([$profileId]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(['success' => false, 'message' => 'You don\'t have a pending deletion request.']);
    }

    // Mirror api/patients/request-deletion.php's own notifyAdminStaff() call
    // that raised this in the first place — otherwise staff can act on a
    // request the patient already withdrew, purely because nothing ever
    // told them it was gone. Same 'deletion_request' type as that original
    // notification, so it gets the same icon and click-through straight to
    // this patient's profile (see _notifNavTarget()/markNotifRead(), which
    // route on type + relatedId, not the notification's title/body).
    if ($pt) {
        $patientName = trim($pt['first_name'] . _mi($pt['middle_name'] ?? '') . ' ' . $pt['last_name']);
        notifyAdminStaff($pdo, 'deletion_request', 'Deletion Request Cancelled',
            "{$patientName} withdrew their account deletion request. No action needed.", $profileId
        );
    }

    jsonResponse(['success' => true]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
