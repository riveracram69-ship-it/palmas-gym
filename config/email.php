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

    // 1. Build HTML email template matching the forest green brand design
    $html_message = "
    <html>
    <head>
        <title>" . htmlspecialchars($subject) . "</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7f6; color: #333333; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border: 1px solid #e1e8e5; }
            .header { background: linear-gradient(135deg, #1b4332 0%, #0d2a1c 100%); padding: 30px; text-align: center; color: #ffffff; }
            .logo { width: 60px; height: 60px; margin-bottom: 10px; }
            .header h1 { font-size: 20px; margin: 0; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; }
            .body { padding: 40px 30px; line-height: 1.6; font-size: 15px; }
            .body h2 { color: #1b4332; font-size: 18px; margin-top: 0; margin-bottom: 15px; }
            .body p { margin: 0 0 15px 0; color: #555555; }
            .button-container { text-align: center; margin: 30px 0; }
            .button { background-color: #2d6a4f; color: #ffffff !important; text-decoration: none; padding: 12px 24px; border-radius: 8px; font-weight: bold; font-size: 14px; display: inline-block; }
            .footer { background-color: #f8fafc; padding: 20px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #f1f5f9; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <img src='{$base_url}/assets/images/palmas-logo.png' class='logo' alt='Palma\'s Elite Gym Logo'>
                <h1>Palma's Elite Gym</h1>
            </div>
            <div class='body'>
                <h2>" . htmlspecialchars($title) . "</h2>
                <p>{$body_text}</p>
                <div class='button-container'>
                    <a href='{$base_url}/member/login.php' class='button'>Access Member Portal</a>
                </div>
            </div>
            <div class='footer'>
                <p>This is an automated notification. Please do not reply directly to this email.</p>
                <p>&copy; " . date('Y') . " Palma's Elite Gym. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";

    require_once __DIR__ . '/env.php';
    require_once __DIR__ . '/../libs/PHPMailer/Exception.php';
    require_once __DIR__ . '/../libs/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/../libs/PHPMailer/SMTP.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail_sent = false;
    $status_text = '';

    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        // SSL options for local environment / Windows OpenSSL compatibility
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        // Recipients
        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Palma\'s Elite Gym';
        $mail->setFrom(SMTP_FROM, $fromName);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_message;
        $mail->AltBody = strip_tags($body_text);

        $mail->send();
        $mail_sent = true;
        $status_text = 'DELIVERED (Success)';
    } catch (Exception $e) {
        $status_text = 'FAILED (SMTP Error: ' . $mail->ErrorInfo . ')';
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

    // 5. Insert into notifications table
    try {
        global $pdo;
        if (!isset($pdo)) {
            require_once __DIR__ . '/db.php';
        }
        
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
    } catch (Exception $e) {
        // Fail silently if table doesn't exist yet
    }

    return $mail_sent;
}
