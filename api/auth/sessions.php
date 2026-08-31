<?php
// ================================================================
//  CANAOPTICALCLINIC — api/auth/sessions.php
//  GET → returns the current user's own active sessions (every device
//        currently signed in to their account), for Settings > Active
//        Sessions. A user can only ever see their own — scoped to
//        $_SESSION['user_id'] throughout, never a caller-supplied id.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}

$userId = (int)$_SESSION['user_id'];

try {
    $pdo = getDB();
    $sessions = listSessions($pdo, $userId);
    jsonResponse(['success' => true, 'sessions' => $sessions]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error.'], 500);
}
