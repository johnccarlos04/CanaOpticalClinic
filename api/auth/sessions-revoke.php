<?php
// ================================================================
//  CANAOPTICALCLINIC — api/auth/sessions-revoke.php
//  POST { id }  — id is the short, opaque handle listSessions() (see
//  api/auth/sessions.php) returned for one row, not a real session id.
//  Logs that one device out immediately. Scoped to the caller's own
//  account throughout — there is no way to revoke another account's
//  session, even by guessing an id.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}

$userId = (int)$_SESSION['user_id'];
$b      = getBody();
$id     = trim($b['id'] ?? '');

if (!$id) {
    jsonResponse(['success' => false, 'message' => 'id is required.']);
}

// Revoking the device making this exact request doesn't make sense here
// (that's what Log Out is for) — and would actually get silently undone
// anyway: PHP's session write still fires at the end of THIS request,
// re-creating the row that was just deleted. The Active Sessions UI never
// offers a Log Out button for "This device" in the first place; this is
// just a defensive backend guard against a stale/tampered request.
if ($id === _sessionShortId(session_id())) {
    jsonResponse(['success' => false, 'message' => 'Use Log Out to sign out of this device.']);
}

try {
    $pdo = getDB();
    $revoked = revokeSessionByShortId($pdo, $userId, $id);
    if (!$revoked) {
        jsonResponse(['success' => false, 'message' => 'That session was not found — it may already be signed out.']);
    }
    jsonResponse(['success' => true]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
}
