<?php
// ================================================================
//  CANAOPTICALCLINIC — api/patients/request-deletion.php
//  POST { reason? }  — patient only.
//  Marks the caller's own patient record as having requested account
//  deletion, and notifies admin/staff. Does NOT delete anything itself —
//  admin/staff reviews the request and either archives the account
//  (the existing Archive flow, api/archive/create.php, which now also
//  clears this flag) or dismisses it. See schema.sql's migration comment
//  for the full reasoning.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if (($_SESSION['role'] ?? '') !== 'patient') {
    jsonResponse(['success' => false, 'message' => 'Only patients may request deletion of their own account.'], 403);
}

$profileId = $_SESSION['profile_id'] ?? '';
if (!$profileId) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}

// Each successful call here (and its counterpart in cancel-deletion-
// request.php) notifies every admin/staff account — without a limit, a
// patient could request/cancel in a fast loop and flood their inboxes with
// nothing to actually act on. Per-user, not per-IP alone, so one account's
// retries can't eat another account's budget on a shared network.
rateLimit('request-deletion:' . $_SESSION['user_id'], 5, 3600); // 5 per user per hour

$b      = getBody();
$reason = trim($b['reason'] ?? '');
// A hard cap, not a soft one — matches the field being TEXT, and keeps a
// pasted essay from bloating the admin-facing notification body.
if (mb_strlen($reason) > 500) $reason = mb_substr($reason, 0, 500);

try {
    $pdo = getDB();

    $s = $pdo->prepare('SELECT first_name, middle_name, last_name, deletion_requested_at FROM patients WHERE id = ? LIMIT 1');
    $s->execute([$profileId]);
    $pt = $s->fetch();
    if (!$pt) {
        jsonResponse(['success' => false, 'message' => 'Patient record not found.']);
    }
    if ($pt['deletion_requested_at']) {
        jsonResponse(['success' => false, 'message' => 'You already have a pending deletion request.']);
    }

    $pdo->prepare(
        'UPDATE patients SET deletion_requested_at = NOW(), deletion_request_reason = ? WHERE id = ?'
    )->execute([$reason ?: null, $profileId]);

    $patientName = trim($pt['first_name'] . _mi($pt['middle_name'] ?? '') . ' ' . $pt['last_name']);
    // Sent to admin AND staff alike (notifyAdminStaff() below), but only an
    // admin can actually archive (api/archive/create.php enforces this
    // server-side, same rule the Patient Profile page's own banner UI
    // follows) — worded so it never tells staff they can do something they
    // can't.
    $msg = "{$patientName} has requested that their account be deleted."
         . ($reason ? " Reason given: \"{$reason}\"." : '')
         . ' An admin can archive to proceed, or dismiss if the account should stay.';
    notifyAdminStaff($pdo, 'deletion_request', 'Account Deletion Requested', $msg, $profileId);

    jsonResponse(['success' => true]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
