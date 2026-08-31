<?php
// ================================================================
//  CANAOPTICALCLINIC — api/auth/reset-password.php
//  POST { token, password }
//  → 200 { success:true }
//  → 200 { success:false, message }
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');

$b        = getBody();
$token    = trim($b['token']    ?? '');
$password = $b['password'] ?? '';

if (!$token) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.']);
}
if ($pwError = validatePasswordPolicy($password)) {
    jsonResponse(['success' => false, 'message' => $pwError]);
}

try {
    $pdo = getDB();

    // Find a valid, unused token that hasn't expired
    $s = $pdo->prepare(
        'SELECT id, email FROM password_resets
          WHERE token = ? AND used = 0 AND expires_at > NOW()
          LIMIT 1'
    );
    $s->execute([$token]);
    $row = $s->fetch();

    if (!$row) {
        jsonResponse(['success' => false, 'message' => 'Reset link is invalid or has expired.']);
    }

    $userStmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE LOWER(email) = ? LIMIT 1');
    $userStmt->execute([strtolower($row['email'])]);
    $userRow = $userStmt->fetch();

    if ($userRow && passwordWasUsedBefore($pdo, (int)$userRow['id'], $password)) {
        jsonResponse(['success' => false, 'message' => 'You can\'t reuse a previous password. Please choose a different one.']);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $pdo->beginTransaction();

    if ($userRow) recordPasswordHistory($pdo, (int)$userRow['id'], $userRow['password_hash']);

    // Update password on the users table
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE LOWER(email) = ?')
        ->execute([$hash, strtolower($row['email'])]);

    // Mark the token as used
    $pdo->prepare('UPDATE password_resets SET used = 1 WHERE id = ?')
        ->execute([$row['id']]);

    $pdo->commit();

    // Forgot-password reset — revoke every session on the account, no
    // exceptions. The requester wasn't authenticated as this user
    // anywhere during this flow (they proved ownership via the emailed
    // token instead), so there's no "current device" to spare.
    if ($userRow) revokeAllSessions($pdo, (int)$userRow['id']);

    jsonResponse(['success' => true]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['success' => false, 'message' => 'Server error. Please try again.'], 500);
}
