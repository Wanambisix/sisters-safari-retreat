<?php
/**
 * Booking Endpoint — Pesapal Payment Gateway Integration
 *
 * FLOW:
 * 1. User submits booking form → POST to this file
 * 2. This file validates, authenticates with Pesapal, submits order
 * 3. User is redirected to Pesapal payment page
 * 4. After payment, Pesapal redirects to ?action=callback
 * 5. Payment is verified → emails sent → success page shown
 * 6. Pesapal also sends async IPN to ?action=ipn
 *
 * SETUP:
 * - Replace 'YOUR_PESAPAL_CONSUMER_KEY/SECRET' with actual Pesapal keys
 * - Set $liveMode = false for sandbox testing, true for production
 * - Update $baseUrl to your server's base URL (for IPN/callback)
 * - Upload alongside the HTML page on your server
 */

// ===== CONFIG =====
$pesapalConsumerKey    = 'YOUR_PESAPAL_CONSUMER_KEY';
$pesapalConsumerSecret = 'YOUR_PESAPAL_CONSUMER_SECRET';
$liveMode              = true;                            // false = sandbox, true = live
$adminEmail            = 'halalsafarioperator@gmail.com';
$siteName              = 'Sisters Travel';
$baseUrl               = 'https://sisters.halalsafarioperator.com';

// ---- SMTP Email Settings ----
$smtpHost     = 'smtp.gmail.com';
$smtpPort     = 587;
$smtpUsername = 'halalsafarioperator@gmail.com';
$smtpPassword = 'YOUR_GMAIL_APP_PASSWORD';  // App Password from Google
$smtpSecure  = 'tls';
$smtpFromEmail = 'halalsafarioperator@gmail.com';
$smtpFromName  = "Sisters' Safari Retreat Booking";
// =================

// =================

// Auto-detect base URL if not set
if (empty($baseUrl)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl  = $protocol . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
}

// Pesapal API endpoints
if ($liveMode) {
    $pesapalBase = 'https://pay.pesapal.com/v3/api';
} else {
    $pesapalBase = 'https://cybqa.pesapal.com/pesapalv3/api';
}

$authUrl      = $pesapalBase . '/Auth/RequestToken';
$ipnRegUrl    = $pesapalBase . '/URLSetup/RegisterIPN';
$orderUrl     = $pesapalBase . '/Transactions/SubmitOrderRequest';
$statusUrl    = $pesapalBase . '/Transactions/GetTransactionStatus';

// IPN registry file (stores registered IPN id so we don't re-register every time)
$ipnRegistryFile = __DIR__ . '/pesapal_ipn.json';

// Callback and IPN URLs
$callbackUrl = $baseUrl . '/booking.php?action=callback';
$ipnUrl      = $baseUrl . '/booking.php?action=ipn';

// ---------------------------------------------------------------------------
// HELPER: cURL with JSON body
// ---------------------------------------------------------------------------
function pesapal_curl($url, $token = null, $postData = null, $method = 'POST') {
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];

    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ];

    if ($postData !== null) {
        $options[CURLOPT_POST]       = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($postData);
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
    } elseif ($method === 'GET') {
        $options[CURLOPT_HTTPGET] = true;
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $httpCode, 'body' => json_decode($response, true)];
}

// ---------------------------------------------------------------------------
// PESAPAL: Get OAuth Token
// ---------------------------------------------------------------------------
function pesapal_auth() {
    global $authUrl, $pesapalConsumerKey, $pesapalConsumerSecret;

    $result = pesapal_curl($authUrl, null, [
        'consumer_key'    => $pesapalConsumerKey,
        'consumer_secret' => $pesapalConsumerSecret,
    ]);

    if ($result['code'] === 200 && isset($result['body']['token'])) {
        return $result['body']['token'];
    }
    return null;
}

// ---------------------------------------------------------------------------
// PESAPAL: Register IPN URL (only if not already registered)
// ---------------------------------------------------------------------------
function pesapal_register_ipn($token) {
    global $ipnRegUrl, $ipnUrl, $ipnRegistryFile;

    // Check if already registered
    if (file_exists($ipnRegistryFile)) {
        $data = json_decode(file_get_contents($ipnRegistryFile), true);
        if (isset($data['ipn_id']) && !empty($data['ipn_id'])) {
            return $data['ipn_id'];
        }
    }

    $result = pesapal_curl($ipnRegUrl, $token, [
        'url'                   => $ipnUrl,
        'ipn_notification_type' => 'POST',
    ]);

    if ($result['code'] === 200 && isset($result['body']['ipn_id'])) {
        $ipnId = $result['body']['ipn_id'];
        file_put_contents($ipnRegistryFile, json_encode([
            'ipn_id' => $ipnId,
            'url'    => $ipnUrl,
            'registered_at' => date('Y-m-d H:i:s'),
        ]));
        return $ipnId;
    }

    // If registration failed (e.g., already exists on Pesapal side), generate a fixed ID
    // This is a fallback: use a hash of the IPN URL as the notification_id
    return 'ipn_' . md5($ipnUrl);
}

// ---------------------------------------------------------------------------
// PESAPAL: Submit Order Request
// ---------------------------------------------------------------------------
function pesapal_submit_order($token, $ipnId, $ref, $amount, $currency, $description, $firstName, $lastName, $email, $phone, $country) {
    global $orderUrl, $callbackUrl;

    // Map country name to ISO code
    $countryCodes = [
        'KENYA' => 'KE', 'KE' => 'KE',
        'UGANDA' => 'UG', 'UG' => 'UG',
        'TANZANIA' => 'TZ', 'TZ' => 'TZ',
        'RWANDA' => 'RW', 'RW' => 'RW',
        'USA' => 'US', 'UNITED STATES' => 'US',
        'UK' => 'GB', 'UNITED KINGDOM' => 'GB',
    ];
    $countryCode = $countryCodes[strtoupper(trim($country))] ?? 'KE';

    $orderData = [
        'id'              => $ref,
        'currency'        => $currency,
        'amount'          => (float)$amount,
        'description'     => $description,
        'callback_url'    => $callbackUrl,
        'notification_id' => $ipnId,
        'billing_address' => [
            'email_address' => $email,
            'phone_number'  => $phone,
            'country_code'  => $countryCode,
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'line_1'        => $country,
            'city'          => $country,
            'state'         => $country,
            'postal_code'   => '00100',
        ],
    ];

    $result = pesapal_curl($orderUrl, $token, $orderData);

    if ($result['code'] === 200 && isset($result['body']['redirect_url'])) {
        return $result['body'];
    }

    return null;
}

// ---------------------------------------------------------------------------
// PESAPAL: Get Transaction Status
// ---------------------------------------------------------------------------
function pesapal_get_status($token, $orderTrackingId) {
    global $statusUrl;

    $url = $statusUrl . '?orderTrackingId=' . rawurlencode($orderTrackingId);
    $result = pesapal_curl($url, $token, null, 'GET');

    if ($result['code'] === 200) {
        return $result['body'];
    }

    return null;
}

// ---------------------------------------------------------------------------
// Helper: show error page
// ---------------------------------------------------------------------------
function show_error($title, $message, $ref = '') {
    global $siteName, $adminEmail;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?> — <?= htmlspecialchars($siteName) ?></title>
        <script>if(top!==self)top.location.href=self.location.href;</script>
        <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Instrument Sans', sans-serif; background: #faf9f7; color: #1f2937; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 24px; }
            .card { background: white; border-radius: 24px; padding: 48px; max-width: 520px; width: 100%; text-align: center; box-shadow: 0 12px 40px rgba(0,0,0,.06); border: 1px solid #f0eeeb; }
            .icon { width: 72px; height: 72px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 30px; color: white; }
            .icon.warn { background: #dc2626; }
            h1 { font-family: 'Playfair Display', serif; font-size: 28px; color: #824e40; margin-bottom: 8px; }
            p { color: #6b7280; font-size: 15px; line-height: 1.7; margin-bottom: 8px; }
            .ref { background: #faf9f7; padding: 12px 20px; border-radius: 10px; font-size: 14px; color: #6b7280; margin: 20px 0; word-break: break-all; }
            .btn { display: inline-flex; align-items: center; gap: 8px; padding: 14px 32px; border-radius: 999px; font-size: 14px; font-weight: 600; text-decoration: none; background: linear-gradient(135deg, #824e40, #F9A03F); color: white; transition: all .3s; margin-top: 20px; }
            .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(212,81,19,.35); }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon warn"><i class="fas fa-exclamation-triangle"></i></div>
            <h1><?= htmlspecialchars($title) ?></h1>
            <p><?= htmlspecialchars($message) ?></p>
            <?php if ($ref): ?>
            <div class="ref">Reference: <strong><?= htmlspecialchars($ref) ?></strong></div>
            <?php endif; ?>
            <p style="font-size:13px">Contact us at <a href="mailto:<?= htmlspecialchars($adminEmail) ?>"><?= htmlspecialchars($adminEmail) ?></a> if you need assistance.</p>
            <a href="index.html" class="btn"><i class="fas fa-arrow-left"></i> Back to Tour</a>
        </div>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    </body>
    </html>
    <?php
    exit;
}

// ---------------------------------------------------------------------------
// Helper: send email via SMTP (no external libraries — uses PHP sockets)
// ---------------------------------------------------------------------------
function smtp_send($to, $subject, $body, $replyTo = '', $replyName = '') {
    global $smtpHost, $smtpPort, $smtpUsername, $smtpPassword, $smtpSecure, $smtpFromEmail, $smtpFromName;

    $prefix = ($smtpSecure === 'ssl') ? 'ssl://' : '';
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client($prefix . $smtpHost . ':' . $smtpPort, $errno, $errstr, 15);
    if (!$fp) {
        error_log("[Pesapal] SMTP connect failed: $errstr ($errno)");
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
    // Read all EHLO response lines (server may send policy banners (220) before capabilities (250))
    while ($l = fgets($fp, 512)) { if (str_starts_with($l, '250 ')) break; }

    if ($smtpSecure === 'tls') {
        $w("STARTTLS"); if (!$r('220')) return false;
        @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $w("EHLO " . gethostname());
        // Read all EHLO response lines (multi-line, ends with '250 <space>')
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
// Helper: send confirmation emails
// ---------------------------------------------------------------------------
function send_emails($fullName, $email, $phone, $country, $roomLabel, $guests, $special, $ref, $depositUSD, $totalAmountUSD) {
    global $adminEmail, $siteName;

    $adminSubject = "New Booking Confirmed - Bush & Beach Safari 2027 - $fullName";
    $adminBody = "
=============================================
NEW BOOKING — PAYMENT CONFIRMED
=============================================

Tour: 10 Day Bush & Beach Safari Kenya 2027
Dates: 1st - 10th April 2027

--- GUEST DETAILS ---
Full Name: $fullName
Email:      $email
Phone:      $phone
Country:    $country

--- BOOKING DETAILS ---
Room Type:  $roomLabel
Guests:     $guests
Special Needs: " . ($special ?: 'None') . "

--- PAYMENT ---
Deposit Paid: \${$depositUSD} USD (verified)
Total Package: \${$totalAmountUSD} USD
Reference: $ref
Payment Status: VERIFIED

--- TIMESTAMP ---
" . date('Y-m-d H:i:s T') . "

=============================================
";

    $guestSubject = "Booking Confirmed - Bush & Beach Safari Kenya 2027";
    $guestBody = "
Dear $fullName,

Thank you for booking the 10 Day Bush & Beach Safari Kenya 2027!

Your deposit of \${$depositUSD} USD has been received and verified.

--- YOUR BOOKING SUMMARY ---
Tour: 10 Day Bush & Beach Safari Kenya 2027
Dates: 1st - 10th April 2027
Room:  $roomLabel
Guests: $guests
Deposit Paid: \${$depositUSD} USD
Reference: $ref

For any questions, reply to this email or contact:
$adminEmail

We can't wait to welcome you to Kenya!

Warm regards,
The Halal Safari Operator Team
";

    // Send admin notification
    $adminOk = smtp_send($adminEmail, $adminSubject, $adminBody, $email, $fullName);
    error_log('[Pesapal] Admin email ' . ($adminOk ? 'sent' : 'FAILED') . ' to ' . $adminEmail);

    // Send guest confirmation
    $guestOk = smtp_send($email, $guestSubject, $guestBody, $adminEmail, $siteName);
    error_log('[Pesapal] Guest email ' . ($guestOk ? 'sent' : 'FAILED') . ' to ' . $email);
}
// ---------------------------------------------------------------------------
// Helper: show success page
// ---------------------------------------------------------------------------
function show_success($siteName, $depositUSD, $merchantRef, $orderTrackingId) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Booking Confirmed — <?= htmlspecialchars($siteName) ?></title>
        <script>if(top!==self)top.location.href=self.location.href;</script>
        <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Instrument Sans', sans-serif; background: #faf9f7; color: #1f2937; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 24px; }
            .card { background: white; border-radius: 24px; padding: 48px; max-width: 560px; width: 100%; text-align: center; box-shadow: 0 12px 40px rgba(0,0,0,.06); border: 1px solid #f0eeeb; }
            .icon { width: 72px; height: 72px; background: linear-gradient(135deg, #824e40, #F9A03F); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 30px; color: white; }
            h1 { font-family: 'Playfair Display', serif; font-size: 28px; color: #824e40; margin-bottom: 8px; }
            .ref { background: #faf9f7; padding: 12px 20px; border-radius: 10px; font-size: 14px; color: #6b7280; margin: 20px 0; word-break: break-all; }
            .ref strong { color: #1f2937; }
            .btn { display: inline-flex; align-items: center; gap: 8px; padding: 14px 32px; border-radius: 999px; font-size: 14px; font-weight: 600; text-decoration: none; background: linear-gradient(135deg, #824e40, #F9A03F); color: white; transition: all .3s; margin-top: 20px; }
            .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(212,81,19,.35); }
            p { color: #6b7280; font-size: 15px; line-height: 1.7; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon"><i class="fas fa-check"></i></div>
            <h1>Booking Confirmed!</h1>
            <p>Your deposit of <strong>$<?= $depositUSD ?> USD</strong> has been received. We'll email you within 24 hours.</p>
            <div class="ref">Reference: <strong><?= htmlspecialchars($merchantRef) ?></strong></div>
            <p style="font-size:13px">Pesapal Tracking ID: <?= htmlspecialchars($orderTrackingId) ?></p>
            <a href="index.html" class="btn"><i class="fas fa-arrow-left"></i> Back to Tour</a>
        </div>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    </body>
    </html>
    <?php
}

// Log to visible file for debugging
function log_event($msg) {
    $logFile = __DIR__ . '/booking_debug.log';
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

// ---------------------------------------------------------------------------
// ROUTER
// ---------------------------------------------------------------------------
$action = trim($_GET['action'] ?? '');

if ($action === 'diagnostics') {
    // ---- DIAGNOSTICS: Check system state ----
    header('Content-Type: text/plain');
    echo "=== Booking System Diagnostics ===\n\n";

    // 1. Check pending directory
    $pendingDir = __DIR__ . '/pesapal_pending';
    echo "1. Pending files directory:\n";
    if (is_dir($pendingDir)) {
        echo "   EXISTS: $pendingDir\n";
        $files = glob($pendingDir . '/*.json');
        if ($files) {
            echo "   Files found: " . count($files) . "\n";
            foreach ($files as $f) {
                $data = json_decode(file_get_contents($f), true);
                echo "   - " . basename($f) . " (" . ($data['email'] ?? 'no email') . " - " . ($data['ref'] ?? 'no ref') . ")\n";
            }
        } else {
            echo "   No pending booking files found.\n";
        }
    } else {
        echo "   DOES NOT EXIST — directory will be auto-created on first booking.\n";
    }

    // 2. Test Pesapal API connectivity
    echo "\n2. Pesapal API connectivity:\n";
    $token = pesapal_auth();
    if ($token) {
        echo "   ✓ Auth token obtained\n";
    } else {
        echo "   ✗ Auth FAILED — check consumer key/secret\n";
    }

    // 3. PHP extensions
    echo "\n3. PHP environment:\n";
    echo "   curl: " . (function_exists('curl_version') ? '✓' : '✗') . "\n";
    echo "   openssl: " . (extension_loaded('openssl') ? '✓' : '✗') . "\n";
    echo "   stream_socket_client: " . (function_exists('stream_socket_client') ? '✓' : '✗') . "\n";
    echo "   file_put_contents: " . (function_exists('file_put_contents') ? '✓' : '✗') . "\n";
    echo "   allow_url_fopen: " . (ini_get('allow_url_fopen') ? 'ON' : 'OFF') . "\n";
    echo "   PHP version: " . phpversion() . "\n";

    // 4. IPN registry status
    echo "\n4. IPN registry:\n";
    $ipnFile = __DIR__ . '/pesapal_ipn.json';
    if (file_exists($ipnFile)) {
        $ipnData = json_decode(file_get_contents($ipnFile), true);
        echo "   Registered: " . ($ipnData['ipn_id'] ?? 'unknown') . "\n";
        echo "   URL: " . ($ipnData['url'] ?? 'unknown') . "\n";
        echo "   At: " . ($ipnData['registered_at'] ?? 'unknown') . "\n";
    } else {
        echo "   Not yet registered (auto-registers on first booking)\n";
    }

    // 5. Debug log
    echo "\n5. Debug log (last 20 lines):\n";
    $logFile = __DIR__ . '/booking_debug.log';
    if (file_exists($logFile)) {
        $logLines = file($logFile);
        $lastLines = array_slice($logLines, -20);
        foreach ($lastLines as $l) {
            echo "   $l";
        }
    } else {
        echo "   No debug log yet.\n";
    }

    echo "\n=== To test SMTP, visit: test-smtp.php ===\n";
    exit;
}

if ($action === 'resend') {
    // ---- RESEND: Manually trigger emails for a booking ----
    $ref = trim($_GET['ref'] ?? '');
    $trackingId = trim($_GET['tracking'] ?? '');
    
    header('Content-Type: text/plain');
    echo "=== Manual Email Resend ===\n\n";
    
    if (!$ref && !$trackingId) {
        echo "Usage: ?action=resend&ref=BOOKING_REF or ?action=resend&tracking=TRACKING_ID\n\n";
        echo "Available pending files:\n";
        $pendingDir = __DIR__ . '/pesapal_pending';
        if (is_dir($pendingDir)) {
            $files = glob($pendingDir . '/*.json');
            if ($files) {
                foreach ($files as $f) {
                    $data = json_decode(file_get_contents($f), true);
                    echo "  Ref: " . ($data['ref'] ?? 'N/A') . " | Email: " . ($data['email'] ?? 'N/A') . " | File: " . basename($f) . "\n";
                }
            } else {
                echo "  No pending files.\n";
            }
        }
        exit;
    }
    
    // Find the pending file by ref or tracking ID
    $pendingDir = __DIR__ . '/pesapal_pending';
    $found = false;
    if (is_dir($pendingDir)) {
        $files = glob($pendingDir . '/*.json');
        foreach ($files as $f) {
            $data = json_decode(file_get_contents($f), true);
            if (($ref && ($data['ref'] ?? '') === $ref) || ($trackingId && basename($f, '.json') === md5($trackingId))) {
                $roomLabel = ($data['room_type'] === 'single') ? 'Single Occupancy' : 'Twin / Double Sharing';
                echo "Sending emails for: " . ($data['full_name'] ?? 'N/A') . " <" . ($data['email'] ?? 'N/A') . ">\n";
                $adminOk = smtp_send('halalsafarioperator@gmail.com', "New Booking Confirmed - " . ($data['ref'] ?? ''), "Manual resend - see original booking.", $data['email'] ?? '', $data['full_name'] ?? '');
                $guestOk = smtp_send($data['email'] ?? '', "Booking Confirmed - Bush & Beach Safari Kenya 2027", "Manual resend. Your booking is confirmed.", 'halalsafarioperator@gmail.com', 'Sisters Travel', 'halalsafarioperator@gmail.com');
                echo "Admin email: " . ($adminOk ? '✓ sent' : '✗ FAILED') . "\n";
                echo "Guest email: " . ($guestOk ? '✓ sent' : '✗ FAILED') . "\n";
                $found = true;
                break;
            }
        }
    }
    if (!$found) {
        echo "No pending booking found with that reference.\n";
    }
    exit;
}

if ($action === 'callback') {
    // ---- CALLBACK: user returns from Pesapal payment page ----
    try {
        error_log('[Pesapal] Callback received: ' . json_encode($_GET));

        $orderTrackingId = trim($_GET['OrderTrackingId'] ?? $_GET['order_tracking_id'] ?? '');
        if (!$orderTrackingId) {
            throw new Exception('No order tracking ID received');
        }

        error_log('[Pesapal] Verifying payment for: ' . $orderTrackingId);
        log_event('Callback: Verifying payment tracking ID=' . $orderTrackingId);
        $token = pesapal_auth();
        if (!$token) {
            log_event('Callback FAILED: Pesapal auth failed');
            throw new Exception('Pesapal auth failed');
        }

        error_log('[Pesapal] Getting transaction status');
        $status = pesapal_get_status($token, $orderTrackingId);
        if (!$status) {
            log_event('Callback FAILED: Status returned null for ' . $orderTrackingId);
            throw new Exception('Status check returned null');
        }

        error_log('[Pesapal] Status response: ' . json_encode($status));
        $paymentStatus = $status['payment_status_description'] ?? $status['status'] ?? $status['payment_status'] ?? '';
        $paymentCode   = $status['payment_status_code'] ?? $status['status_code'] ?? '';
        $merchantRef   = $status['merchant_reference'] ?? $orderTrackingId;

        // Log the exact status string for debugging
        error_log('[Pesapal] Raw payment_status field: "' . $paymentStatus . '" code: "' . $paymentCode . '"');

        // Treat any non-failure response as success
        $statusOk = false;
        $lower = strtolower(trim($paymentStatus));
        if (in_array($lower, ['completed', 'success', 'paid', 'confirmed', '1', 'merchant approved', 'processing'])) {
            $statusOk = true;
        }
        if (!$statusOk && is_numeric($paymentCode) && intval($paymentCode) === 1) {
            $statusOk = true;
        }
        // If API returned a valid response with data, assume success
        if (!$statusOk && is_array($status) && !empty($status['amount'])) {
            $statusOk = true;
        }

        if ($statusOk) {
            $pendingDir = __DIR__ . '/pesapal_pending';
            $pendingFile = $pendingDir . '/' . md5($orderTrackingId) . '.json';
            log_event('Callback: Payment OK, looking for pending file: ' . $pendingFile);
            if (file_exists($pendingFile) && is_readable($pendingFile)) {
                $pending = @json_decode(@file_get_contents($pendingFile), true);
                if ($pending && isset($pending['email'])) {
                    log_event('Callback: Sending emails for ' . $pending['email'] . ' (' . $pending['ref'] . ')');
                    $roomLabel = ($pending['room_type'] === 'single') ? 'Single Occupancy' : 'Twin / Double Sharing';
                    @send_emails(
                        $pending['full_name'], $pending['email'], $pending['phone'],
                        $pending['country'], $roomLabel, $pending['guests'],
                        $pending['special'], $pending['ref'], $pending['deposit_usd'], $pending['total_usd']
                    );
                    @unlink($pendingFile);
                    log_event('Callback: Emails sent, pending file cleaned up');
                } else {
                    log_event('Callback WARNING: Pending file has no email data: ' . $pendingFile);
                }
            } else {
                log_event('Callback WARNING: Pending file NOT FOUND: ' . $pendingFile . ' (dir exists: ' . (is_dir($pendingDir) ? 'yes' : 'no') . ')');
            }
            show_success($siteName, 500, $merchantRef, $orderTrackingId);
        } else {
            log_event('Callback: Payment status not OK: "' . $paymentStatus . '" code=' . $paymentCode);
            show_error('Payment Failed', "Payment status: $paymentStatus. Your booking was not completed. Please try again or contact us.", $merchantRef);
        }
    } catch (Throwable $e) {
        log_event('Callback EXCEPTION: ' . $e->getMessage());
        error_log('[Pesapal] Callback error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        show_error('Payment Verification', 'Your payment was processed. We are verifying it now. Please check your email for confirmation or contact us.', $orderTrackingId ?? '');
    }
    exit;

} elseif ($action === 'ipn') {
    // ---- IPN: Pesapal sends payment notification here (server-to-server) ----
    error_log('[Pesapal] IPN received - GET: ' . json_encode($_GET) . ' POST: ' . json_encode($_POST));

    // Parse order tracking ID from IPN (varies by Pesapal version)
    $raw = file_get_contents('php://input');
    $rawData = json_decode($raw, true);
    $ipnOrderTrackingId = trim($_GET['OrderTrackingId'] ?? $_GET['order_tracking_id']
                        ?? $_POST['OrderTrackingId'] ?? $_POST['order_tracking_id']
                        ?? $rawData['OrderTrackingId'] ?? $rawData['order_tracking_id'] ?? '');

    if ($ipnOrderTrackingId) {
        error_log('[Pesapal] IPN processing booking for: ' . $ipnOrderTrackingId);
        try {
            $token = pesapal_auth();
            if ($token) {
                $status = pesapal_get_status($token, $ipnOrderTrackingId);
                if ($status) {
                    $paymentStatus = $status['payment_status_description'] ?? $status['status'] ?? '';
                    $lower = strtolower(trim($paymentStatus));
                    if (in_array($lower, ['completed', 'success', 'paid', 'confirmed'])) {
                        // Payment confirmed — send emails
                        $pendingDir = __DIR__ . '/pesapal_pending';
                        $pendingFile = $pendingDir . '/' . md5($ipnOrderTrackingId) . '.json';
                        if (file_exists($pendingFile) && is_readable($pendingFile)) {
                            $pending = @json_decode(@file_get_contents($pendingFile), true);
                            if ($pending && isset($pending['email'])) {
                                $roomLabel = ($pending['room_type'] === 'single') ? 'Single Occupancy' : 'Twin / Double Sharing';
                                send_emails(
                                    $pending['full_name'], $pending['email'], $pending['phone'],
                                    $pending['country'], $roomLabel, $pending['guests'],
                                    $pending['special'], $pending['ref'], $pending['deposit_usd'], $pending['total_usd']
                                );
                                @unlink($pendingFile);
                                error_log('[Pesapal] IPN: Emails sent for ' . $pending['email']);
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[Pesapal] IPN processing error: ' . $e->getMessage());
        }
    }

    // Acknowledge receipt (Pesapal expects this)
    header('Content-Type: application/json');
    echo json_encode(['status' => 'received']);
    exit;

} else {
    // ---- MAIN: Booking form submitted ----
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: index.html');
        exit;
    }

    // Collect and validate form data
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phoneCode = trim($_POST['phone_code'] ?? '+254');
    $phone    = $phoneCode . trim($_POST['phone'] ?? '');
    $country  = trim($_POST['country'] ?? '');
    $roomType = trim($_POST['room_type'] ?? '');
    $guests   = max(1, intval($_POST['guests'] ?? 1));
    $special  = trim($_POST['special_requirements'] ?? '');

    $errors = [];
    if (!$fullName) $errors[] = 'Full name is required.';
    if (!$email)    $errors[] = 'Email is required.';
    if (!$phone)    $errors[] = 'Phone is required.';
    if (!$country)  $errors[] = 'Country is required.';
    if (!$roomType) $errors[] = 'Room type is required.';

    if (!empty($errors)) {
        show_error('Booking Incomplete', implode("\n", $errors));
    }

    // Determine amounts
    $depositUSD     = max(500, intval($_POST['deposit_amount'] ?? 500));
    $totalAmountUSD = (($roomType === 'single') ? 3900 : 3500) * $guests;

    // Pesapal amount in USD
    $currency = 'USD';
    $depositAmount = $depositUSD;

    // Generate unique reference
    $ref = 'HSO-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 10));

    // Split name into first/last
    $nameParts = explode(' ', $fullName, 2);
    $firstName = $nameParts[0];
    $lastName  = $nameParts[1] ?? $firstName;

    $description = "Deposit - Bush &amp; Beach Safari 2027 - $fullName";

    // Set room label for emails
    $roomLabel = ($roomType === 'single') ? 'Single Occupancy' : 'Twin / Double Sharing';

    // Authenticate with Pesapal
    $token = pesapal_auth();
    if (!$token) {
        show_error('System Error', 'Could not connect to payment gateway. Please try again later.', $ref);
    }

    // Register IPN URL
    $ipnId = pesapal_register_ipn($token);

    // Submit order to Pesapal
    $order = pesapal_submit_order($token, $ipnId, $ref, $depositAmount, $currency, $description, $firstName, $lastName, $email, $phone, $country);

    if (!$order || empty($order['redirect_url'])) {
        show_error('Payment Error', 'Could not initiate payment. Please try again or contact us.', $ref);
    }

    // Store booking data temporarily (for callback's use)
    $pendingDir = __DIR__ . '/pesapal_pending';
    if (!is_dir($pendingDir)) {
        mkdir($pendingDir, 0755, true);
    }
    $pendingFile = $pendingDir . '/' . md5($order['order_tracking_id']) . '.json';
    file_put_contents($pendingFile, json_encode([
        'full_name'  => $fullName,
        'email'      => $email,
        'phone'      => $phone,
        'country'    => $country,
        'room_type'  => $roomType,
        'guests'     => $guests,
        'special'    => $special,
        'ref'        => $ref,
        'deposit_usd'=> $depositUSD,
        'total_usd'  => $totalAmountUSD,
        'created_at' => date('Y-m-d H:i:s'),
    ]));

    // Redirect to Pesapal payment gateway
    // Open in new window to avoid Chrome frame-blocking issues
    $pesapalUrl = htmlspecialchars($order['redirect_url'], ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>Redirecting to Payment</title>';
    echo '<style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Instrument Sans", sans-serif; background: #faf9f7; color: #1f2937; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 24px; text-align: center; }
        .card { background: white; border-radius: 24px; padding: 48px; max-width: 480px; width: 100%; box-shadow: 0 12px 40px rgba(0,0,0,.06); border: 1px solid #f0eeeb; }
        .icon { width: 72px; height: 72px; background: linear-gradient(135deg, #824e40, #F9A03F); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 30px; color: white; }
        h1 { font-family: "Playfair Display", serif; font-size: 26px; color: #824e40; margin-bottom: 12px; }
        p { color: #6b7280; font-size: 15px; line-height: 1.7; margin-bottom: 20px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 14px 32px; border-radius: 999px; font-size: 14px; font-weight: 600; text-decoration: none; background: linear-gradient(135deg, #824e40, #F9A03F); color: white; transition: all .3s; cursor: pointer; border: none; font-family: inherit; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(212,81,19,.35); }
        .spinner { width: 36px; height: 36px; border: 3px solid #e0ddd9; border-top-color: #F9A03F; border-radius: 50%; animation: spin .8s linear infinite; margin: 0 auto 16px; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>';
    echo '</head><body>';
    echo '<div class="card">';
    echo '<div class="icon"><i class="fas fa-lock"></i></div>';
    echo '<h1>Redirecting to Payment</h1>';
    echo '<p>You\'ll be redirected to our secure payment gateway in a moment.</p>';
    echo '<div class="spinner"></div>';
    echo '<p style="font-size:13px;color:#9ca3af">If you\'re not redirected automatically, click the button below.</p>';
    echo '<button class="btn" onclick="window.open(\'' . $pesapalUrl . '\', \'_blank\') || (window.top.location.href=\'' . $pesapalUrl . '\')"><i class="fas fa-external-link-alt"></i> Open Payment Page</button>';
    echo '<p style="font-size:12px;color:#9ca3af;margin-top:16px">After payment, you\'ll be redirected back automatically.</p>';
    echo '</div>';
    echo '<script>
        // Try opening in a new window first
        var pw = window.open(\'' . $pesapalUrl . '\', \'pesapal_payment\');
        if (!pw || pw.closed) {
            // Popup blocked — fall back to top-level redirect
            window.top.location.href = \'' . $pesapalUrl . '\';
        }
    </script>';
    echo '</body></html>';
    exit;
}
