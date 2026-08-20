<?php
// ================================================================
//  CANAOPTICALCLINIC — api/patients/update.php
//
//  POST { action:'profile', firstName, middleName, lastName, phone, email,
//         address, occupation, gender?, dob? }
//
//  Only authenticated patients may call this endpoint.
//  (Password changes for patients go through api/users/change_password.php,
//  same as every other role.)
//
//  gender/dob used to be admin-only (see api/patients/admin_update.php) —
//  low enough stakes for a clinic this size (nothing here is verified
//  against ID the way a hospital admission would be) that gating patients
//  from fixing their own typo'd birth year or a wrong gender select wasn't
//  worth the friction. Same validation/age-derivation as the admin path.
// ================================================================

require_once '../../config/db.php';
require_once '../helpers.php';

requireMethod('POST');
startSession();

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401);
}
if (($_SESSION['role'] ?? '') !== 'patient') {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$userId = (int)$_SESSION['user_id'];
$b      = getBody();
$action = trim($b['action'] ?? '');

try {
    $pdo = getDB();

    // ── Profile update ───────────────────────────────────────────
    if ($action === 'profile') {
        $fn         = trim($b['firstName']  ?? '');
        $mn         = trim($b['middleName'] ?? '');
        $ln         = trim($b['lastName']   ?? '');
        $phone      = trim($b['phone']      ?? '');
        $address    = trim($b['address']    ?? '');
        $email      = trim($b['email']      ?? '');
        $occupation = trim($b['occupation'] ?? '');
        $gender     = trim($b['gender']     ?? '');
        $dob        = trim($b['dob']        ?? '');

        if (!$fn || !$ln) {
            jsonResponse(['success' => false, 'message' => 'First and last name are required.']);
        }
        if ($gender && !in_array($gender, ['Male', 'Female', 'Other'], true)) {
            jsonResponse(['success' => false, 'message' => 'Invalid gender value.']);
        }

        $sets   = ['first_name = ?', 'middle_name = ?', 'last_name = ?', 'contact = ?', 'address = ?', 'occupation = ?'];
        $values = [$fn, $mn ?: null, $ln, $phone, $address, $occupation];

        if ($gender) { $sets[] = 'gender = ?'; $values[] = $gender; }
        if ($dob) {
            $bd  = new DateTime($dob);
            $age = (int)$bd->diff(new DateTime())->y;
            $sets[] = 'dob = ?'; $values[] = $dob;
            $sets[] = 'age = ?'; $values[] = $age;
        }

        $values[] = $userId;
        $pdo->prepare('UPDATE patients SET ' . implode(', ', $sets) . ' WHERE user_id = ?')->execute($values);

        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
            $chk->execute([$email, $userId]);
            if ($chk->fetch()) {
                jsonResponse(['success' => false, 'message' => 'An account with this email already exists.']);
            }
            $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $userId]);
        }

        jsonResponse(['success' => true, 'message' => 'Profile updated successfully.']);
    }

    jsonResponse(['success' => false, 'message' => 'Unknown action.'], 400);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}
