<?php
// ================================================================
//  CANAOPTICALCLINIC — api/auth/verify-email-otp.php
//  POST { email, otp }
//  Reads from pending_registrations. On correct OTP, creates the
//  real users + patients rows, deletes the pending record, starts
//  the session, and returns the full user object.
//  → { success:true, role, user }
//  → { success:false, attemptsLeft?, locked?, message }
// ================================================================

require_once '../../config/db.php';
require_once '../../config/smtp.php';
require_once '../helpers.php';

requireMethod('POST');
rateLimit('verify-email-otp', 15, 600);
startSession();

$b     = getBody();
$email = strtolower(trim($b['email'] ?? ''));
$otp   = trim($b['otp'] ?? '');

if (!$email || strlen($otp) !== 6) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.']);
}

const MAX_EV_ATTEMPTS   = 5;
const MAX_TOTAL_ATTEMPTS = 10; // hard limit across all OTP resends

try {
    $pdo = getDB();

    $s = $pdo->prepare(
        'SELECT * FROM pending_registrations
          WHERE email = ? AND expires_at > NOW()
          ORDER BY id DESC LIMIT 1'
    );
    $s->execute([$email]);
    $row = $s->fetch();

    if (!$row) {
        jsonResponse(['success' => false, 'message' => 'Invalid or expired code. Please request a new one.']);
    }

    // Already burned all per-OTP attempts → need a new code
    if ((int)$row['attempts'] >= MAX_EV_ATTEMPTS) {
        jsonResponse(['success' => false, 'locked' => true,
            'message' => 'Too many wrong attempts. Please request a new code.']);
    }

    if ($row['otp'] !== $otp) {
        $newAttempts      = (int)$row['attempts'] + 1;
        $newTotalAttempts = (int)$row['total_attempts'] + 1;

        // Hard limit reached — delete the pending record entirely
        if ($newTotalAttempts >= MAX_TOTAL_ATTEMPTS) {
            $pdo->prepare('DELETE FROM pending_registrations WHERE id = ?')->execute([$row['id']]);
            jsonResponse(['success' => false, 'banned' => true,
                'message' => 'Too many failed attempts. Please register again with a valid email.']);
        }

        // Per-OTP limit reached → resend needed, but total counter preserved
        if ($newAttempts >= MAX_EV_ATTEMPTS) {
            $pdo->prepare('UPDATE pending_registrations SET attempts = ?, total_attempts = ? WHERE id = ?')
                ->execute([$newAttempts, $newTotalAttempts, $row['id']]);
            jsonResponse(['success' => false, 'locked' => true,
                'message' => 'Too many wrong attempts. Please request a new code.']);
        }

        $pdo->prepare('UPDATE pending_registrations SET attempts = ?, total_attempts = ? WHERE id = ?')
            ->execute([$newAttempts, $newTotalAttempts, $row['id']]);
        $left = MAX_EV_ATTEMPTS - $newAttempts;
        jsonResponse(['success' => false, 'attemptsLeft' => $left,
            'message' => 'Incorrect code. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' remaining.']);
    }

    // ── Correct OTP: create the real account now ──────────────────
    $first   = $row['first_name'];
    $middle  = $row['middle_name'];
    $last    = $row['last_name'];
    $dob     = $row['dob'];
    $gender  = $row['gender'];
    $address = $row['address'];
    $contact = $row['contact'];
    $blood   = $row['blood_type'];
    $hash    = $row['password_hash'];
    $today   = date('Y-m-d');

    $bd  = new DateTime($dob);
    $now = new DateTime();
    $age = (int)$bd->diff($now)->y;

    // Generate unique patient ID
    $cnt = (int)$pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn();
    $pid = 'P' . str_pad($cnt + 1, 3, '0', STR_PAD_LEFT);
    $dup = $pdo->prepare('SELECT id FROM patients WHERE id = ?');
    while (true) {
        $dup->execute([$pid]);
        if (!$dup->fetch()) break;
        $cnt++;
        $pid = 'P' . str_pad($cnt + 1, 3, '0', STR_PAD_LEFT);
    }

    $qr = 'CANA-' . $pid . '-' . strtoupper($first . $last);

    $pdo->beginTransaction();

    $pdo->prepare('INSERT INTO users (email, password_hash, role, email_verified) VALUES (?,?,?,1)')
        ->execute([$email, $hash, 'patient']);
    $uid = (int)$pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO patients
           (id, user_id, first_name, middle_name, last_name, gender, dob, age,
            contact, address, blood_type, occupation,
            medical_history, optical_history,
            qr_data, registered_date, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $pid, $uid, $first, $middle ?: null, $last, $gender, $dob, $age,
        $contact, $address, $blood, '',
        '', '',
        $qr, $today, 'active',
    ]);

    // Remove the pending record — real account now exists
    $pdo->prepare('DELETE FROM pending_registrations WHERE email = ?')->execute([$email]);

    $pdo->commit();

    // Welcome notification
    createNotification($pdo, $uid, 'welcome',
        'Welcome to Cana Optical Clinic',
        'Your patient account has been activated. You can now book appointments and view your records online.'
    );

    // Welcome email — non-critical, never blocks the response (same
    // reasoning as api/contact/create.php's clinic-notification email).
    // No temp password here — self-registration means they already chose
    // their own password.
    try {
        sendEmail(
            $email, "$first $last",
            'Welcome to Cana Optical Clinic',
            welcomeEmailBody("$first $last", 'patient', $email),
            "Welcome, $first $last!\n\nYour patient account at Cana Optical Clinic has been created successfully. You can now sign in anytime using $email and the password you chose."
        );
    } catch (\Throwable $e) { /* non-critical */ }

    // Activity log
    try {
        $logId = 'L' . date('YmdHis') . substr((string)microtime(true), -3);
        $pdo->prepare(
            'INSERT IGNORE INTO activity_log (id, users_id, user_name, role, action, timestamp, type, ip_address)
             VALUES (?,?,?,?,\'Registered and verified email\',NOW(),\'login\',?)'
        )->execute([substr($logId, 0, 20), $uid, "$first $last", 'Patient', clientIp()]);
    } catch (PDOException $e) { /* non-critical */ }

    $result  = loadUserProfile($pdo, $uid, 'patient');
    $userObj = buildUserObject('patient', $result['profile'], $email, [], $uid);

    $_SESSION['user_id']    = $uid;
    $_SESSION['profile_id'] = $pid;
    $_SESSION['role']       = 'patient';

    jsonResponse(['success' => true, 'role' => 'patient', 'user' => $userObj]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['success' => false, 'message' => 'Server error. Please try again.'], 500);
}
