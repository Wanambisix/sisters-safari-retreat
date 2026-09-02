<?php
/**
 * Inquiry Handler — Email via Gmail SMTP
 *
 * Replaces FormSubmit.co for the inquiry form.
 * Uses the same SMTP credentials as booking.php.
 *
 * 1. User submits inquiry form → POST to this file
 * 2. Validates required fields
 * 3. Sends email to admin via Gmail SMTP
 * 4. Shows branded success/error page
 */

// ===== CONFIG (same as booking.php) =====
$smtpHost     = 'smtp.gmail.com';
$smtpPort     = 587;
$smtpUsername = 'halalsafarioperator@gmail.com';
$smtpPassword = 'YOUR_GMAIL_APP_PASSWORD';  // App Password from Google
$smtpSecure   = 'tls';
$smtpFromEmail = 'halalsafarioperator@gmail.com';
$smtpFromName  = "Sisters' Safari Retreat Inquiry";
$adminEmail    = 'halalsafarioperator@gmail.com';
$siteName      = "Sisters' Safari Retreat";
$baseUrl       = 'https://sisters.halalsafarioperator.com';
// ========================================

// ---------------------------------------------------------------------------
// SMTP SEND (same raw-socket implementation as booking.php)
// ---------------------------------------------------------------------------
function smtp_send($to, $subject, $body, $replyTo = '', $replyName = '') {
    global $smtpHost, $smtpPort, $smtpUsername, $smtpPassword, $smtpSecure, $smtpFromEmail, $smtpFromName;

    $prefix = ($smtpSecure === 'ssl') ? 'ssl://' : '';
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client($prefix . $smtpHost . ':' . $smtpPort, $errno, $errstr, 15);
    if (!$fp) {
        error_log("[Inquiry] SMTP connect failed: $errstr ($errno)");
        return false;
    }

    $r = function($expect = '') use($fp) {
        $line = fgets($fp, 512);
        if ($expect && substr($line, 0, 3) !== $expect) return false;
        return $line;
    };
    $w = function($cmd) use($fp) { fputs($fp, $cmd . "\r\n"); };

    $r(); // banner

    $w("EHLO " . gethostname());
    while ($l = fgets($fp, 512)) { if (str_starts_with($l, '250 ')) break; }

    if ($smtpSecure === 'tls') {
        $w("STARTTLS"); if (!$r('220')) return false;
        @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $w("EHLO " . gethostname());
        while ($l = fgets($fp, 512)) { if (str_starts_with($l, '250 ')) break; }
    }

    $w("AUTH LOGIN");
    if (!$r('334')) return false;
    $w(base64_encode($smtpUsername));
    if (!$r('334')) return false;
    $w(base64_encode($smtpPassword));
    if (!$r('235')) return false;

    $w("MAIL FROM:<$smtpFromEmail>"); $r('250');
    $w("RCPT TO:<$to>"); $r('250');
    $w("DATA"); $r('354');

    $headers = "From: $smtpFromName <$smtpFromEmail>\r\n";
    $headers .= "To: <$to>\r\n";
    $headers .= "Subject: $subject\r\n";
    if ($replyTo) $headers .= "Reply-To: $replyName <$replyTo>\r\n";
    $headers .= "X-Mailer: PHP\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    $w($headers . "\r\n" . $body);
    $w(".");
    $r('250');

    $w("QUIT");
    fclose($fp);
    return true;
}

// ---------------------------------------------------------------------------
// Show success/error page
// ---------------------------------------------------------------------------
function show_result($success, $name = '') {
    global $siteName;
    $icon  = $success ? 'check-circle' : 'exclamation-triangle';
    $color = $success ? '16a34a' : 'dc2626';
    $title = $success ? 'Inquiry Sent!' : 'Something Went Wrong';
    $msg   = $success
        ? "Thank you, " . htmlspecialchars($name) . "!<br>We've received your inquiry and will get back to you within 24 hours."
        : "We couldn't send your inquiry. Please email us directly at <a href=\"mailto:halalsafarioperator@gmail.com\" style=\"color:#824e40\">halalsafarioperator@gmail.com</a> or try again.";
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= $title ?> — <?= htmlspecialchars($siteName) ?></title>
        <script>if(top!==self)top.location.href=self.location.href;</script>
        <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Instrument Sans', sans-serif; background: #faf9f7; color: #1f2937; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 24px; }
            .card { background: white; border-radius: 24px; padding: 48px; max-width: 520px; width: 100%; text-align: center; box-shadow: 0 12px 40px rgba(0,0,0,.06); border: 1px solid #f0eeeb; }
            .icon { width: 72px; height: 72px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 30px; color: white; background: #<?= $color ?>; }
            h1 { font-family: 'Playfair Display', serif; font-size: 28px; color: #824e40; margin-bottom: 8px; }
            p { color: #6b7280; font-size: 15px; line-height: 1.7; margin-bottom: 8px; }
            .btn { display: inline-flex; align-items: center; gap: 8px; padding: 14px 32px; border-radius: 999px; font-size: 14px; font-weight: 600; text-decoration: none; background: linear-gradient(135deg, #824e40, #F9A03F); color: white; transition: all .3s; margin-top: 20px; }
            .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(212,81,19,.35); }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon"><i class="fas fa-<?= $icon ?>"></i></div>
            <h1><?= $title ?></h1>
            <p><?= $msg ?></p>
            <a href="index.html" class="btn"><i class="fas fa-arrow-left"></i> Back to Tour</a>
        </div>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    </body>
    </html>
    <?php
}

// ===== MAIN HANDLER =====
header('Content-Type: text/html; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    show_result(false);
    exit;
}

// Collect and sanitize fields
$fullName = trim($_POST['full_name'] ?? '');
$email    = trim($_POST['email'] ?? '');
$phone    = '';
$message  = trim($_POST['message'] ?? '');
$tourName = '10 Day Bush & Beach Safari Kenya 2027';

// Combine phone code + number
if (!empty($_POST['phone_code']) && !empty($_POST['phone'])) {
    $phone = trim($_POST['phone_code']) . ' ' . trim($_POST['phone']);
} elseif (!empty($_POST['phone'])) {
    $phone = trim($_POST['phone']);
}

// Validate required fields
if (empty($fullName) || empty($email) || empty($message)) {
    show_result(false);
    exit;
}

// Build email body
$adminSubject = "New Tour Inquiry - Bush & Beach Safari 2027 - $fullName";
$adminBody = "
=============================================
NEW INQUIRY — TOUR QUESTION
=============================================

Tour: $tourName
Date: " . date('Y-m-d H:i:s T') . "

--- GUEST DETAILS ---
Full Name: $fullName
Email:      $email
Phone:      " . ($phone ?: 'Not provided') . "

--- INQUIRY ---
$message

=============================================
";

// Send to admin
$sent = smtp_send($adminEmail, $adminSubject, $adminBody, $email, $fullName);

if ($sent) {
    show_result(true, $fullName);
} else {
    show_result(false);
}
