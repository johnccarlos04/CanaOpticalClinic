<?php
// ================================================================
//  CANAOPTICALCLINIC — config/smtp.php
//  SMTP credentials loaded from environment variables.
//  Set these in Railway → Variables dashboard:
//
//  SMTP_HOST      = smtp-relay.brevo.com        (or smtp.gmail.com)
//  SMTP_PORT      = 587
//  SMTP_USERNAME  = your-brevo-login@email.com  (or Gmail address)
//  SMTP_PASSWORD  = your-brevo-api-key          (or Gmail App Password)
//  SMTP_FROM      = sender@yourdomain.com
//  SMTP_FROM_NAME = Cana Optical Clinic
//
//  Brevo (recommended for Railway):  https://app.brevo.com → SMTP & API → SMTP
//  Gmail App Password (local dev):   https://myaccount.google.com/apppasswords
// ================================================================

define('SMTP_HOST',      getenv('SMTP_HOST')      ?: 'smtp.gmail.com');
define('SMTP_PORT',      (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_USERNAME',  getenv('SMTP_USERNAME')   ?: 'jcjcarlos892@gmail.com');
define('SMTP_PASSWORD',  getenv('SMTP_PASSWORD')   ?: 'ncpyohmvfhnnskiq');
define('SMTP_FROM',      getenv('SMTP_FROM')       ?: 'jcjcarlos892@gmail.com');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME')  ?: 'Cana Optical Clinic');
define('BREVO_API_KEY',  getenv('BREVO_API_KEY')   ?: '');

// ================================================================
//  Unified email sender — uses Brevo HTTP API when BREVO_API_KEY
//  is set (Railway), falls back to PHPMailer SMTP (local dev).
// ================================================================

function sendEmail(string $to, string $toName, string $subject, string $html, string $text): void {
    if (BREVO_API_KEY) {
        _brevoSend($to, $toName, $subject, $html, $text);
    } else {
        _smtpSend($to, $toName, $subject, $html, $text);
    }
}

function _brevoSend(string $to, string $toName, string $subject, string $html, string $text): void {
    $payload = json_encode([
        'sender'      => ['name' => SMTP_FROM_NAME, 'email' => SMTP_FROM],
        'to'          => [['email' => $to, 'name' => $toName ?: $to]],
        'subject'     => $subject,
        'htmlContent' => $html,
        'textContent' => $text,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'api-key: ' . BREVO_API_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);

    if ($curlErr) {
        throw new \RuntimeException("Email delivery error: $curlErr");
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new \RuntimeException("Email delivery error ($httpCode): $response");
    }
}

function _smtpSend(string $to, string $toName, string $subject, string $html, string $text): void {
    require_once __DIR__ . '/../vendor/autoload.php';
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host          = SMTP_HOST;
    $mail->SMTPAuth      = true;
    $mail->Username      = SMTP_USERNAME;
    $mail->Password      = SMTP_PASSWORD;
    $mail->SMTPSecure    = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port          = SMTP_PORT;
    $mail->Timeout       = 10;
    $mail->SMTPKeepAlive = false;
    $mail->SMTPOptions   = ['ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
    ]];
    $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    $toName ? $mail->addAddress($to, $toName) : $mail->addAddress($to);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $html;
    $mail->AltBody = $text;
    $mail->send();
}

// ================================================================
//  Welcome email — sent once, right after any account is successfully
//  created (self-registration, or admin/staff creating a patient/staff/
//  doctor/admin account). Shared here rather than duplicated per
//  create-account endpoint (api/auth/verify-email-otp.php,
//  api/patients/create.php, api/users/create.php). Same visual template
//  as api/patients/email.php's patientEmailBody() / api/contact/reply.php's
//  replyEmailBody() for consistency, with a credentials box swapped in
//  when the caller has a temp password to hand over (accounts an admin/
//  staff member creates on someone else's behalf get one; a self-
//  registered patient chose their own password already and has none).
// ================================================================
function welcomeEmailBody(string $name, string $roleLabel, string $loginEmail, ?string $tempPassword = null): string {
    $safeName  = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeRole  = htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($loginEmail, ENT_QUOTES, 'UTF-8');

    $credentialsBlock = $tempPassword ? <<<HTML
        <tr>
          <td style="padding:0 40px 36px;">
            <div style="background:#FFF8F0;border:2px solid #FFD9A8;border-radius:12px;padding:20px 22px;font-family:'Poppins','Segoe UI',Arial,sans-serif;">
              <div style="font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#B45309;margin-bottom:12px;">Your Login Credentials</div>
              <div style="font-size:13px;color:#6b7280;margin-bottom:2px;">Email</div>
              <div style="font-size:14px;color:#1C1C1C;font-weight:600;margin-bottom:12px;">{$safeEmail}</div>
              <div style="font-size:13px;color:#6b7280;margin-bottom:2px;">Temporary Password</div>
              <div style="font-size:14px;color:#1C1C1C;font-weight:600;font-family:'Courier New',monospace;">{$tempPassword}</div>
            </div>
            <p style="font-family:'Poppins','Segoe UI',Arial,sans-serif;margin:14px 0 0;font-size:12.5px;color:#9ca3af;line-height:1.6;">
              For your security, please sign in and change this password as soon as possible.
            </p>
          </td>
        </tr>
        HTML
        : <<<HTML
        <tr>
          <td style="padding:0 40px 36px;">
            <p style="font-family:'Poppins','Segoe UI',Arial,sans-serif;margin:0;font-size:14px;color:#6b7280;line-height:1.7;">
              You can now sign in anytime using <strong style="color:#1C1C1C;">{$safeEmail}</strong> and the password you chose.
            </p>
          </td>
        </tr>
        HTML;

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Welcome to Cana Optical Clinic</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body style="margin:0;padding:0;background:#f0f1f5;font-family:'Poppins','Segoe UI',Arial,sans-serif;">

  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f0f1f5;padding:48px 16px;">
    <tr><td align="center">

      <table width="520" cellpadding="0" cellspacing="0" role="presentation"
             style="background:#ffffff;border-radius:16px;overflow:hidden;
                    box-shadow:0 4px 24px rgba(0,0,0,0.09),0 1px 4px rgba(0,0,0,0.05);
                    max-width:520px;width:100%;">

        <!-- Header -->
        <tr>
          <td style="background:#E8760A;padding:32px 40px 28px;text-align:center;">
            <div style="font-family:'Poppins','Segoe UI',Arial,sans-serif;
                        font-size:22px;font-weight:800;color:#ffffff;
                        letter-spacing:1px;line-height:1;margin-bottom:4px;">
              Cana Optical Clinic
            </div>
            <div style="font-family:'Poppins','Segoe UI',Arial,sans-serif;
                        font-size:11px;font-weight:500;letter-spacing:2.5px;
                        color:rgba(255,255,255,0.7);text-transform:uppercase;">
              Welcome Aboard
            </div>
          </td>
        </tr>

        <!-- Greeting -->
        <tr>
          <td style="padding:40px 40px 0;">
            <h1 style="font-family:'Poppins','Segoe UI',Arial,sans-serif;
                       margin:0 0 12px;font-size:20px;font-weight:700;
                       color:#1C1C1C;letter-spacing:-0.3px;">
              Welcome, {$safeName}!
            </h1>
            <p style="font-family:'Poppins','Segoe UI',Arial,sans-serif;
                      margin:0;font-size:14px;color:#6b7280;line-height:1.7;">
              Your {$safeRole} account at Cana Optical Clinic has been created successfully.
            </p>
          </td>
        </tr>

        <!-- Credentials or plain confirmation -->
        <tr><td style="padding-top:24px;"></td></tr>
        {$credentialsBlock}

        <!-- Divider -->
        <tr>
          <td style="padding:0 40px;">
            <div style="border-top:1px solid #f0f0f4;"></div>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#f8f8fb;padding:20px 40px;
                     border-top:1px solid #f0f0f4;text-align:center;">
            <p style="font-family:'Poppins','Segoe UI',Arial,sans-serif;
                      margin:0;font-size:11px;color:#b0b7c3;">
              &copy; Cana Optical Clinic &nbsp;&bull;&nbsp; Do not reply to this email
            </p>
          </td>
        </tr>

      </table>

    </td></tr>
  </table>

</body>
</html>
HTML;
}

// ================================================================
//  System-notice email — automated, non-welcome notifications (appointment
//  reminders, auto-cancellations, waitlist updates, etc.) that previously
//  only ever reached the in-app inbox via createNotification(). Same
//  branded shell as welcomeEmailBody()/patientEmailBody(), but without
//  that one's "sent you a message" framing, since this isn't from a
//  person — just a title + the notice body.
// ================================================================
function systemEmailBody(string $name, string $title, string $message): string {
    $safeTitle   = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$safeTitle}</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body style="margin:0;padding:0;background:#f0f1f5;font-family:'Poppins','Segoe UI',Arial,sans-serif;">

  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f0f1f5;padding:48px 16px;">
    <tr><td align="center">

      <table width="520" cellpadding="0" cellspacing="0" role="presentation"
             style="background:#ffffff;border-radius:16px;overflow:hidden;
                    box-shadow:0 4px 24px rgba(0,0,0,0.09),0 1px 4px rgba(0,0,0,0.05);
                    max-width:520px;width:100%;">

        <!-- Header -->
        <tr>
          <td style="background:#E8760A;padding:32px 40px 28px;text-align:center;">
            <div style="font-family:'Poppins','Segoe UI',Arial,sans-serif;
                        font-size:22px;font-weight:800;color:#ffffff;
                        letter-spacing:1px;line-height:1;margin-bottom:4px;">
              Cana Optical Clinic
            </div>
            <div style="font-family:'Poppins','Segoe UI',Arial,sans-serif;
                        font-size:11px;font-weight:500;letter-spacing:2.5px;
                        color:rgba(255,255,255,0.7);text-transform:uppercase;">
              Appointment Notice
            </div>
          </td>
        </tr>

        <!-- Greeting -->
        <tr>
          <td style="padding:40px 40px 0;">
            <h1 style="font-family:'Poppins','Segoe UI',Arial,sans-serif;
                       margin:0 0 12px;font-size:20px;font-weight:700;
                       color:#1C1C1C;letter-spacing:-0.3px;">
              {$safeTitle}
            </h1>
            <p style="font-family:'Poppins','Segoe UI',Arial,sans-serif;
                      margin:0;font-size:14px;color:#1C1C1C;line-height:1.7;">
              Hi {$name},
            </p>
          </td>
        </tr>

        <!-- Message block -->
        <tr>
          <td style="padding:24px 40px 36px;">
            <div style="background:#FFF8F0;border:2px solid #FFD9A8;
                        border-radius:12px;padding:20px 22px;
                        font-family:'Poppins','Segoe UI',Arial,sans-serif;
                        font-size:14px;color:#1C1C1C;line-height:1.7;">
              {$safeMessage}
            </div>
          </td>
        </tr>

        <!-- Divider -->
        <tr>
          <td style="padding:0 40px;">
            <div style="border-top:1px solid #f0f0f4;"></div>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#f8f8fb;padding:20px 40px;
                     border-top:1px solid #f0f0f4;text-align:center;">
            <p style="font-family:'Poppins','Segoe UI',Arial,sans-serif;
                      margin:0;font-size:11px;color:#b0b7c3;">
              &copy; Cana Optical Clinic &nbsp;&bull;&nbsp; Do not reply to this email
            </p>
          </td>
        </tr>

      </table>

    </td></tr>
  </table>

</body>
</html>
HTML;
}
