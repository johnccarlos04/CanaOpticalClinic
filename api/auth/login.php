<?php
// ================================================================
//  CANAOPTICALCLINIC — api/auth/login.php
//  POST { email, password }
//  → 200 { success:true,  role, user }
//  → 200 { success:false, message }
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
rateLimit('login', 60, 900); // 60 attempts per IP per 15 min

$body     = getBody();
$email    = trim($body['email']    ?? '');
$pass     = trim($body['password'] ?? '');
$remember = !empty($body['remember']);

startSession($remember ? 30 : 1);

if (!$email || !$pass) {
    jsonResponse(['success' => false, 'message' => 'Email and password are required.']);
}

$maxAttempts = 5;
$lockMinutes = 15;

try {
    $pdo  = getDB();
    // Fetch without is_active filter so we can give specific inactive messages
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}

// Account temporarily locked from too many failed attempts
if ($user && $user['locked_until'] && new DateTime($user['locked_until']) > new DateTime()) {
    $minutesLeft = (int)ceil((strtotime($user['locked_until']) - time()) / 60);
    jsonResponse(['success' => false, 'message' =>
        "Too many failed attempts. Please try again in {$minutesLeft} minute" . ($minutesLeft === 1 ? '' : 's') . "."]);
}

if (!$user || !password_verify($pass, $user['password_hash'])) {
    if ($user) {
        $attempts = (int)$user['failed_attempts'] + 1;
        if ($attempts >= $maxAttempts) {
            $lockedUntil = (new DateTime())->modify("+{$lockMinutes} minutes")->format('Y-m-d H:i:s');
            $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = ? WHERE id = ?')
                ->execute([$lockedUntil, (int)$user['id']]);
            jsonResponse(['success' => false, 'message' =>
                "Too many failed attempts. Your account has been locked for {$lockMinutes} minutes."]);
        }
        $pdo->prepare('UPDATE users SET failed_attempts = ? WHERE id = ?')->execute([$attempts, (int)$user['id']]);
    }
    jsonResponse(['success' => false, 'message' => 'The email or password you entered is incorrect. Please try again.']);
}

// Correct password — reset any prior failed-attempt tracking
if ((int)$user['failed_attempts'] > 0 || $user['locked_until']) {
    $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')
        ->execute([(int)$user['id']]);
}


// Auto-deactivate if inactive for 1 year
// Use last_login_at if set, otherwise fall back to created_at
$referenceDate = $user['last_login_at'] ?? $user['created_at'];
$inactiveSince = new DateTime($referenceDate);
$now           = new DateTime();
$yearsSince    = (int)$inactiveSince->diff($now)->y;

if ($yearsSince >= 1 && $user['is_active']) {
    try {
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')
            ->execute([(int)$user['id']]);

        $tableMap = ['admin' => 'admins', 'staff' => 'staff', 'doctor' => 'doctors', 'patient' => 'patients'];
        $table    = $tableMap[$user['role']] ?? null;
        if ($table) {
            $pdo->prepare("UPDATE `{$table}` SET status = 'inactive' WHERE user_id = ?")
                ->execute([(int)$user['id']]);
        }
    } catch (PDOException $e) { /* non-critical */ }
    $user['is_active'] = 0;
}

if (!$user['is_active']) {
    jsonResponse(['success' => false, 'message' => 'Your account is inactive. Please contact the clinic to have it reactivated.']);
}

$role   = $user['role'];
$result = loadUserProfile($pdo, (int)$user['id'], $role);

if (!$result) {
    jsonResponse(['success' => false, 'message' => 'User profile not found. Contact support.']);
}

$userObj = buildUserObject($role, $result['profile'], $user['email'], $result['days'], (int)$user['id']);

$_SESSION['user_id']    = (int)$user['id'];
$_SESSION['profile_id'] = $result['profile']['id'];
$_SESSION['role']       = $role;

// Tag this session row with its owner + device/IP so it shows up in that
// user's own Settings > Active Sessions list.
tagSessionOwner($pdo, (int)$user['id']);

// Record this login time (used for 1-year inactivity check)
try {
    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
        ->execute([(int)$user['id']]);
} catch (PDOException $e) { /* non-critical */ }

// Persist login to activity log
try {
    $fullName = ($role === 'doctor' ? 'Dr. ' : '') . trim(($result['profile']['first_name'] ?? '') . ' ' . ($result['profile']['last_name'] ?? ''));
    $logId    = 'L' . date('YmdHis') . substr((string)microtime(true), -3);
    $pdo->prepare(
        'INSERT IGNORE INTO activity_log (id, users_id, user_name, role, action, timestamp, type, ip_address)
         VALUES (?,?,?,?,\'Logged in to the system\',NOW(),\'login\',?)'
    )->execute([substr($logId, 0, 20), (int)$user['id'], $fullName, ucfirst($role), clientIp()]);
} catch (PDOException $e) { /* non-critical */ }

jsonResponse(['success' => true, 'role' => $role, 'user' => $userObj]);
