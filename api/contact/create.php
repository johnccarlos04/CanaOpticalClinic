<?php
// ================================================================
//  CANAOPTICALCLINIC — api/contact/create.php
//  POST — public endpoint, no auth required.
//  Body: { name, email, service, message }
//  → { success:true, id:N }
// ================================================================

require_once '../../config/db.php';
require_once '../../config/smtp.php';
require_once '../helpers.php';

requireMethod('POST');
rateLimit('contact', 5, 600); // 5 per IP per 10 min — public spam target

$b = getBody();

$name    = trim($b['name']    ?? '');
$email   = trim($b['email']   ?? '');
$service = trim($b['service'] ?? '');
$message = trim($b['message'] ?? '');

if (!$name || !$email || !$service || !$message) {
    jsonResponse(['success' => false, 'message' => 'Name, email, inquiry type and message are required.']);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'message' => 'Please enter a valid email address.']);
}

if (mb_strlen($name) > 150 || mb_strlen($email) > 150 || mb_strlen($service) > 100) {
    jsonResponse(['success' => false, 'message' => 'One of the fields is too long.']);
}

try {
    $pdo = getDB();

    $pdo->prepare(
        'INSERT INTO contact_messages (name, email, service, message) VALUES (?, ?, ?, ?)'
    )->execute([$name, $email, $service ?: null, $message]);

    $newId = (int)$pdo->lastInsertId();

    $excerpt = mb_strlen($message) > 80 ? mb_substr($message, 0, 80) . '…' : $message;
    notifyAdminStaff($pdo, 'contact_message', 'New Contact Message',
        "{$name} sent a message: \"{$excerpt}\""
    );

    // Email notification to clinic — non-critical, never blocks the response
    try {
        $clinicRow  = $pdo->query('SELECT email FROM clinic_settings WHERE id = 1 LIMIT 1')->fetch();
        $adminEmail = ($clinicRow['email'] ?? '') ?: SMTP_FROM;

        sendEmail(
            $adminEmail, 'Cana Optical Clinic',
            "New Contact Message from {$name}",
            _contactEmailBody($name, $email, $service, $message),
            "From: {$name} <{$email}>\nService: " . ($service ?: 'Not specified') . "\n\n{$message}"
        );
    } catch (\Throwable $e) {
        // Non-critical — message is already saved to DB and in-app notification sent
    }

    jsonResponse(['success' => true, 'id' => $newId]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Database error. Please try again.'], 500);
}

function _contactEmailBody(string $name, string $email, string $service, string $message): string {
    $safeName    = htmlspecialchars($name,                        ENT_QUOTES, 'UTF-8');
    $safeEmail   = htmlspecialchars($email,                       ENT_QUOTES, 'UTF-8');
    $safeService = htmlspecialchars($service ?: 'Not specified',  ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($message,               ENT_QUOTES, 'UTF-8'));
    $dateStr     = date('F j, Y · g:i A');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>New Contact Message — Cana Optical Clinic</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 16px">
    <tr><td align="center">
      <table width="100%" style="max-width:520px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08)">
        <tr>
          <td style="background:#E8760A;padding:28px 32px;text-align:center">
            <div style="font-size:22px;font-weight:700;color:#ffffff;letter-spacing:-0.02em">&#128236; New Contact Message</div>
            <div style="font-size:13px;color:rgba(255,255,255,.85);margin-top:4px">Cana Optical Clinic</div>
          </td>
        </tr>
        <tr>
          <td style="padding:28px 32px">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="padding:10px 0;border-bottom:1px solid #f0f0f0">
                  <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px">From</div>
                  <div style="font-size:15px;color:#111827;font-weight:500">{$safeName}</div>
                </td>
              </tr>
              <tr>
                <td style="padding:10px 0;border-bottom:1px solid #f0f0f0">
                  <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px">Email</div>
                  <div style="font-size:15px"><a href="mailto:{$safeEmail}" style="color:#F59E0B;text-decoration:none">{$safeEmail}</a></div>
                </td>
              </tr>
              <tr>
                <td style="padding:10px 0;border-bottom:1px solid #f0f0f0">
                  <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px">Service</div>
                  <div style="font-size:15px;color:#111827">{$safeService}</div>
                </td>
              </tr>
              <tr>
                <td style="padding:14px 0 0">
                  <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">Message</div>
                  <div style="font-size:15px;color:#374151;line-height:1.7;background:#f9fafb;border-radius:8px;padding:16px 18px;border-left:3px solid #F59E0B">{$safeMessage}</div>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:14px 32px 24px;text-align:center;border-top:1px solid #f0f0f0">
            <div style="font-size:12px;color:#9ca3af">Received {$dateStr}</div>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}
