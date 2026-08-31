<?php
// ================================================================
//  CANAOPTICALCLINIC — api/auth/logout.php
//  POST (no body required) — destroys the PHP session.
// ================================================================

require_once '../helpers.php';

requireMethod('POST');
startSession();

$userId = $_SESSION['user_id'] ?? null;
$sid    = session_id();

// Signing out here also clears every OTHER session on this account that's
// the exact same browser AND the exact same IP as this one — the same
// same-device-and-IP cascade the Security & Sign-in page's own Sign Out
// button already does (revokeSessionByShortId(), api/helpers.php), now
// also on the everyday sidebar Sign Out. This only ever matches genuine
// duplicates of THIS physical device/network (an old tab, a session that
// lingered from an earlier open of the app) — a different device (a
// desktop while this is a phone, or a different network entirely) is
// never touched, so this still never signs out anywhere else on the
// account, matching this app's own multi-device design elsewhere.
if ($userId && $sid) {
    try {
        $pdo = getDB();
        $cur = $pdo->prepare('SELECT user_agent, ip_address FROM sessions WHERE id = ? LIMIT 1');
        $cur->execute([$sid]);
        $row = $cur->fetch();
        if ($row && $row['user_agent'] && $row['ip_address']) {
            $pdo->prepare('DELETE FROM sessions WHERE user_id = ? AND user_agent = ? AND ip_address = ?')
                ->execute([$userId, $row['user_agent'], $row['ip_address']]);
        }
    } catch (PDOException) {
        // Non-critical — session_destroy() below still signs this device
        // out for real even if this extra cleanup pass fails.
    }
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']
    );
}

session_destroy();

jsonResponse(['success' => true]);
