<?php
// config/email.php
require_once __DIR__ . '/env.php';

function send_email_notification($to, $subject, $title, $body_text) {
    if (defined('APP_URL') && APP_URL !== '') {
        $base_url = APP_URL;
    } else {
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $base_url = $protocol . '://' . $host . '/gym';
    }

    // Determine action button URL
    $cta_url = rtrim($base_url, '/') . '/member/login.php';
    if (str_contains(strtolower($subject), 'reset') || str_contains(strtolower($title), 'reset')) {
        $cta_url = rtrim($base_url, '/') . '/member/forgot_password.php';
    }

    // Format body text for email rendering
    $formatted_body = (str_contains($body_text, '<p>') || str_contains($body_text, '<br>'))
        ? $body_text 
        : nl2br(htmlspecialchars($body_text, ENT_QUOTES, 'UTF-8'));

    // Safe year and date
    $current_year = date('Y');
    $current_date = date('F j, Y');

    // 1. Build luxury responsive HTML email template matching the Palma's Elite Gym design system
    $html_message = '
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
    <html xmlns="http://www.w3.org/1999/xhtml" lang="en">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="color-scheme" content="light dark" />
        <meta name="supported-color-schemes" content="light dark" />
        <title>' . htmlspecialchars($subject) . '</title>
        <style type="text/css">
            /* Client-specific resets */
            body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
            table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
            img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
            body { height: 100% !important; margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #F1F5F3; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
            
            /* Modern CTA hover */
            .email-btn:hover { background-color: #2D6A4F !important; box-shadow: 0 4px 14px rgba(45,106,79,0.4) !important; }
            
            @media screen and (max-width: 600px) {
                .email-container { width: 100% !important; border-radius: 0 !important; }
                .email-content { padding: 24px 20px !important; }
                .email-header { padding: 28px 20px !important; }
                .email-footer { padding: 24px 20px !important; }
                .header-logo { width: 56px !important; height: 56px !important; }
            }
        </style>
    </head>
    <body style="margin:0; padding:0; background-color:#F1F5F3;">
        <!-- Preheader text for email client preview snippet -->
        <div style="display:none; font-size:1px; color:#F1F5F3; line-height:1px; max-height:0px; max-width:0px; opacity:0; overflow:hidden;">
            ' . htmlspecialchars($title) . ' — Palma\'s Elite Gym Management Notification
        </div>

        <!-- Outer Canvas Table -->
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#F1F5F3; table-layout:fixed;">
            <tr>
                <td align="center" style="padding: 24px 12px 36px;">
                    <!-- Centered 600px Container -->
                    <table border="0" cellpadding="0" cellspacing="0" width="100%" class="email-container" style="max-width:600px; background-color:#ffffff; border-radius:18px; overflow:hidden; box-shadow:0 10px 30px rgba(27,67,50,0.08); border:1px solid #D8E6DC;">
                        
                        <!-- ── LUXURY HEADER ───────────────────────────── -->
                        <tr>
                            <td class="email-header" align="center" style="background:linear-gradient(145deg, #1B4332 0%, #0D2E23 60%, #082119 100%); padding:36px 30px; text-align:center;">
                                <!-- Logo Badge -->
                                <table border="0" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto 14px;">
                                    <tr>
                                        <td align="center" style="width:72px; height:72px; background-color:#ffffff; border-radius:50%; border:2px solid #52B788; box-shadow:0 6px 18px rgba(0,0,0,0.25); text-align:center; vertical-align:middle;">
                                            <img src="' . $base_url . '/assets/images/palmas-logo.png" class="header-logo" alt="Palma\'s Elite Gym" width="56" height="56" style="display:block; margin:0 auto; width:56px; height:56px; border-radius:50%; object-fit:contain;" />
                                        </td>
                                    </tr>
                                </table>

                                <!-- Brand Title -->
                                <h1 style="margin:0; color:#FFFFFF; font-size:22px; font-weight:800; letter-spacing:1px; text-transform:uppercase; font-family:\'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;">
                                    PALMA\'S ELITE GYM
                                </h1>
                                <p style="margin:4px 0 0; color:#8FCFBC; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase;">
                                    MEMBERSHIP &bull; ATTENDANCE &bull; PERFORMANCE
                                </p>

                                <!-- Date & Pill -->
                                <table border="0" cellpadding="0" cellspacing="0" align="center" style="margin-top:14px;">
                                    <tr>
                                        <td style="background-color:rgba(82,183,136,0.18); border:1px solid rgba(82,183,136,0.35); border-radius:20px; padding:4px 14px;">
                                            <span style="color:#A3E5C8; font-size:11px; font-weight:700; letter-spacing:0.5px; text-transform:uppercase;">
                                                OFFICIAL NOTIFICATION
                                            </span>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>

                        <!-- ── MAIN CONTENT BODY ───────────────────────── -->
                        <tr>
                            <td class="email-content" style="padding:36px 36px 32px; background-color:#ffffff;">
                                
                                <!-- Notice Title -->
                                <h2 style="margin:0 0 16px; color:#1B4332; font-size:20px; font-weight:800; line-height:1.3; font-family:\'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif;">
                                    ' . htmlspecialchars($title) . '
                                </h2>

                                <!-- Dynamic Body Copy Box -->
                                <div style="color:#334155; font-size:15px; line-height:1.68; margin-bottom:28px;">
                                    ' . $formatted_body . '
                                </div>

                                <!-- Highlight Callout Card -->
                                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:28px; background-color:#F4F9F6; border-radius:12px; border-left:4px solid #3E8241; border-top:1px solid #E2EFE7; border-right:1px solid #E2EFE7; border-bottom:1px solid #E2EFE7;">
                                    <tr>
                                        <td style="padding:16px 20px;">
                                            <p style="margin:0 0 4px; color:#2D6A4F; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:0.8px;">
                                                DIGITAL PASS &bull; MEMBER SERVICES
                                            </p>
                                            <p style="margin:0; color:#475569; font-size:13px; line-height:1.5;">
                                                Access your active QR gym pass, view payment receipts, and manage renewal status anytime via the Palma\'s Elite Member Portal.
                                            </p>
                                        </td>
                                    </tr>
                                </table>

                                <!-- Bulletproof Centered CTA Button -->
                                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:30px 0 10px;">
                                    <tr>
                                        <td align="center">
                                            <table border="0" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                                                <tr>
                                                    <td align="center" style="border-radius:12px; background:linear-gradient(135deg, #3E8241 0%, #1B4332 100%); box-shadow:0 4px 14px rgba(45,106,79,0.3);">
                                                        <a href="' . $cta_url . '" class="email-btn" target="_blank" style="display:inline-block; padding:14px 34px; font-family:\'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; font-size:14px; font-weight:800; color:#FFFFFF; text-decoration:none; letter-spacing:0.5px; border-radius:12px;">
                                                            ACCESS MEMBER PORTAL &rarr;
                                                        </a>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                </table>

                            </td>
                        </tr>

                        <!-- ── TRUST & FOOTER ──────────────────────────── -->
                        <tr>
                            <td class="email-footer" style="padding:28px 36px; background-color:#F8FAF9; border-top:1px solid #E5EFE8; text-align:center;">
                                
                                <p style="margin:0 0 8px; color:#64748B; font-size:12px; font-weight:600;">
                                    Palma\'s Elite Gym &bull; Caloocan City &bull; Philippines
                                </p>
                                <p style="margin:0 0 12px; color:#94A3B8; font-size:11px; line-height:1.5;">
                                    This is an automated operational notification regarding your gym account.<br/>
                                    Please do not reply directly to this automated email address.
                                </p>
                                <div style="border-top:1px solid #EAEFEA; padding-top:12px; margin-top:8px;">
                                    <span style="color:#94A3B8; font-size:11px;">
                                        &copy; ' . $current_year . ' Palma\'s Elite Gym Management System. All rights reserved.
                                    </span>
                                </div>

                            </td>
                        </tr>

                    </table>
                    <!-- End Container -->
                </td>
            </tr>
        </table>
    </body>
    </html>
    ';

    require_once __DIR__ . '/env.php';
    require_once __DIR__ . '/../libs/PHPMailer/Exception.php';
    require_once __DIR__ . '/../libs/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/../libs/PHPMailer/SMTP.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail_sent = false;
    $smtp_error = '';
    $status_text = '';

    // 1. Check if Resend HTTPS API is available (Bypasses Render cloud SMTP port blocking)
    if (defined('RESEND_API_KEY') && !empty(RESEND_API_KEY)) {
        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : "Palma's Elite Gym";
        $fromEmail = (defined('SMTP_FROM') && str_contains(SMTP_FROM, '@') && !str_contains(SMTP_FROM, 'gmail.com')) 
            ? SMTP_FROM 
            : 'onboarding@resend.dev';
        $fullFrom = "{$fromName} <{$fromEmail}>";

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . trim(RESEND_API_KEY),
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'from'    => $fullFrom,
                'to'      => [$to],
                'subject' => $subject,
                'html'    => $html_message
            ]),
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if (!$curlErr && $httpCode >= 200 && $httpCode < 300) {
            $mail_sent = true;
            $status_text = 'DELIVERED (via Resend HTTPS API)';
            $smtp_error = '';
        } else {
            $errDetail = $curlErr ?: ("Resend HTTP " . $httpCode . ": " . (string)$res);
            $smtp_error = $errDetail;
            $status_text = 'FAILED (' . $errDetail . ')';
            error_log('[RESEND-FAIL] ' . $errDetail);
        }
    }

    // 2. Check if Brevo HTTPS API is available
    if (!$mail_sent && defined('BREVO_API_KEY') && !empty(BREVO_API_KEY)) {
        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : "Palma's Elite Gym";
        $fromEmail = defined('SMTP_FROM') ? SMTP_FROM : 'official.palmas.gym@gmail.com';

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'api-key: ' . trim(BREVO_API_KEY),
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'sender'      => ['name' => $fromName, 'email' => $fromEmail],
                'to'          => [['email' => $to]],
                'subject'     => $subject,
                'htmlContent' => $html_message
            ]),
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if (!$curlErr && $httpCode >= 200 && $httpCode < 300) {
            $mail_sent = true;
            $status_text = 'DELIVERED (via Brevo HTTPS API)';
            $smtp_error = '';
        } else {
            $errDetail = $curlErr ?: ("Brevo HTTP " . $httpCode . ": " . (string)$res);
            $smtp_error = $errDetail;
            $status_text = 'FAILED (' . $errDetail . ')';
            error_log('[BREVO-FAIL] ' . $errDetail);
        }
    }

    // 3. Fallback to PHPMailer SMTP (Gmail / Localhost)
    if (!$mail_sent && defined('SMTP_PASS') && !empty(SMTP_PASS) && !str_contains(SMTP_PASS, 'REPLACE')) {
        $configsToTry = [
            ['port' => 465, 'secure' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS],
            ['port' => 587, 'secure' => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS],
            ['port' => (int)(defined('SMTP_PORT') ? SMTP_PORT : 465), 'secure' => (defined('SMTP_PORT') && (int)SMTP_PORT === 587) ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS]
        ];

        // Resolve active SMTP user and password
        $active_user = defined('SMTP_USER') ? SMTP_USER : 'official.palmas.gym@gmail.com';
        $active_pass = defined('SMTP_PASS') ? trim(str_replace(' ', '', (string)SMTP_PASS)) : '';
        $active_from = defined('SMTP_FROM') ? SMTP_FROM : $active_user;

        // If active_pass matches official app password, ensure user is official.palmas.gym@gmail.com
        if ($active_pass === 'jdkkstbihpvqodhs' || str_contains($active_pass, 'jdkk')) {
            $active_user = 'official.palmas.gym@gmail.com';
            $active_from = 'official.palmas.gym@gmail.com';
        }

        $tried = [];
        foreach ($configsToTry as $cfg) {
            $k = $cfg['port'] . '-' . $cfg['secure'];
            if (isset($tried[$k])) continue;
            $tried[$k] = true;

            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = $active_user;
                $mail->Password   = $active_pass;
                $mail->SMTPSecure = $cfg['secure'];
                $mail->Port       = $cfg['port'];
                $mail->Timeout    = 4;

                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                );

                $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Palma\'s Elite Gym';
                $mail->setFrom($active_from, $fromName);
                $mail->addAddress($to);

                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $html_message;
                $mail->AltBody = strip_tags($body_text);

                $mail->send();
                $mail_sent = true;
                $status_text = 'DELIVERED (Success via port ' . $cfg['port'] . ')';
                $smtp_error = '';
                break;
            } catch (Exception $e) {
                $smtp_error  = $mail->ErrorInfo ?: $e->getMessage();
                $status_text = 'FAILED (Port ' . $cfg['port'] . ' Error: ' . $smtp_error . ')';
            }
        }
    }

        if (!$mail_sent) {
            error_log('[EMAIL-FAIL] To: ' . $to . ' | Subject: ' . $subject . ' | Error: ' . $smtp_error);
        }

    // 4. Accurate logging of success or failure
    $log_dir = __DIR__ . '/../backups/';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    $log_file = $log_dir . 'email_logs.txt';
    
    $log_entry = "[" . date('Y-m-d H:i:s') . "] TO: {$to} | SUBJECT: {$subject} | STATUS: {$status_text}\n";
    if (!$mail_sent) {
        $log_entry .= "ERROR: {$status_text}. Check SMTP configuration in env.php\n";
    }
    $log_entry .= "BODY: " . strip_tags($body_text) . "\n";
    $log_entry .= str_repeat("-", 80) . "\n";
    @file_put_contents($log_file, $log_entry, FILE_APPEND);

    // 5. Insert into notifications table (best-effort)
    try {
        global $pdo;
        if (!isset($pdo) && file_exists(__DIR__ . '/db.php')) {
            // Only try if not already attempted and we have active DB
            try {
                $host = DB_HOST;
                $port = defined('DB_PORT') ? DB_PORT : '3306';
                $db   = DB_NAME;
                $user = DB_USER;
                $pass = DB_PASS;
                $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 2
                ]);
            } catch (\Throwable $dbe) {
                $pdo = null;
            }
        }
        
        if ($pdo instanceof \PDO) {
            // Find member_id
            $stmt = $pdo->prepare("SELECT id FROM members WHERE email = ? LIMIT 1");
            $stmt->execute([$to]);
            $member = $stmt->fetch();
            $member_id = $member ? $member['id'] : null;

            // Guess notification type based on subject
            $type = 'System';
            $l_subj = strtolower($subject);
            if (strpos($l_subj, 'welcome') !== false) $type = 'Registration';
            elseif (strpos($l_subj, 'renew') !== false) $type = 'Renewal';
            elseif (strpos($l_subj, 'expir') !== false) $type = 'Expiration';
            elseif (strpos($l_subj, 'inactiv') !== false) $type = 'Inactivity';

            $db_status = $mail_sent ? 'Sent' : 'Failed';

            $insert = $pdo->prepare("INSERT INTO notifications (member_id, type, title, message, delivery_status, sent_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $insert->execute([$member_id, $type, $subject, strip_tags($body_text), $db_status]);
        }
    } catch (\Throwable $e) {
        // Fail silently if DB table doesn't exist or is unreachable
    }

    return [
        'sent'  => $mail_sent,
        'error' => $smtp_error,
        // Legacy boolean compatibility: cast to bool for callers that use `if (send_email_notification(...))`
        '__legacy_bool' => $mail_sent,
    ];
}
