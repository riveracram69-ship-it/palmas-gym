<?php
/**
 * api/member_forgot_password.php
 * ─────────────────────────────────────────────────────────────────
 * Handles forgot password requests and dispatches reset emails.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/cors.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../config/logger.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

$email = strtolower(trim($data['email'] ?? ''));

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

try {
    // Find active member with matching email
    $stmt = $pdo->prepare("SELECT id, membership_id, full_name, email, account_status FROM members WHERE LOWER(email) = ? LIMIT 1");
    $stmt->execute([$email]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        // Generic safe message to prevent email enumeration
        echo json_encode([
            'success' => true,
            'message' => 'If this email is registered in our system, a password reset email has been sent.'
        ]);
        exit;
    }

    // Generate 6-digit temporary OTP or secure token
    $otp = strval(random_int(100000, 999999));
    $reset_token = bin2hex(random_bytes(24));

    // Ensure password_resets table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        member_id INT NOT NULL,
        email VARCHAR(191) NOT NULL,
        otp VARCHAR(10) NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_member (member_id),
        KEY idx_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Clean old tokens for this member
    $pdo->prepare("DELETE FROM password_resets WHERE member_id = ? OR email = ?")->execute([$member['id'], $email]);

    // Insert new reset record valid for 1 hour
    $insert = $pdo->prepare("INSERT INTO password_resets (member_id, email, otp, token, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
    $insert->execute([$member['id'], $email, $otp, $reset_token]);

    $reset_url = rtrim(defined('APP_URL') ? APP_URL : 'https://palmas-gym-4oxn.onrender.com', '/') . '/member/forgot_password.php?token=' . urlencode($reset_token);

    $subject = "Password Reset Request — Palma's Elite Gym";
    $title = "Reset Your Account Password";
    $body = '
    <p>Dear <strong>' . htmlspecialchars($member['full_name']) . '</strong>,</p>
    <p>We received a request to reset your password for your Palma\'s Elite Gym account (<strong>' . htmlspecialchars($member['membership_id']) . '</strong>).</p>
    
    <div style="background-color:#F4F9F6; border:1px solid #D8E6DC; border-radius:10px; padding:18px; margin:20px 0; text-align:center;">
        <p style="margin:0 0 6px; font-size:12px; color:#2D6A4F; font-weight:bold; letter-spacing:1px; text-transform:uppercase;">Your 6-Digit Verification Code</p>
        <span style="font-size:28px; font-weight:800; letter-spacing:6px; color:#1B4332; font-family:monospace;">' . $otp . '</span>
        <p style="margin:8px 0 0; font-size:11px; color:#64748B;">This code is valid for 1 hour.</p>
    </div>

    <p>You can also click the button below to set a new password directly on the member portal.</p>
    <p style="font-size:12px; color:#94A3B8; margin-top:20px;">If you did not make this request, you can safely ignore this email. Your current password will remain unchanged.</p>
    ';

    @send_email_notification($email, $subject, $title, $body);

    log_activity($pdo, 'Password Reset Requested', "Password reset requested for member {$member['full_name']} ({$member['membership_id']})", 'Auth', $member['id'], $member['full_name']);

    echo json_encode([
        'success' => true,
        'message' => 'Password reset instructions have been sent to ' . htmlspecialchars($email) . '. Please check your inbox and spam folder.'
    ]);

} catch (Throwable $e) {
    error_log('Forgot password error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to send password reset email at this time. Please try again or contact the front desk.']);
}
