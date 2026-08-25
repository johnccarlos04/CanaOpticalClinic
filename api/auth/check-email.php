<?php
// ================================================================
//  CANAOPTICALCLINIC — api/auth/check-email.php
//  POST { email }
//  → { success:true, exists:boolean }
//
//  Lets the registration wizard's Step 2 (Contact) catch an
//  already-registered email immediately, instead of only failing at
//  final submission after the patient has already filled out steps
//  2-3. Checks confirmed accounts (users) only — NOT
//  pending_registrations, since a pending row there just means someone
//  (maybe this same person, retrying) started but never finished
//  verifying; register.php's own upsert already handles that case
//  cleanly on its own and shouldn't be blocked here.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
rateLimit('check-email', 30, 600); // 30 per IP per 10 min

$b     = getBody();
$email = strtolower(trim($b['email'] ?? ''));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // Not this endpoint's job to validate format — the wizard already does
    // that client-side before ever calling here. Just don't false-positive
    // "exists" on garbage input.
    jsonResponse(['success' => true, 'exists' => false]);
}

try {
    $pdo = getDB();
    $chk = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
    $chk->execute([$email]);
    jsonResponse(['success' => true, 'exists' => (bool)$chk->fetch()]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Server error.'], 500);
}
