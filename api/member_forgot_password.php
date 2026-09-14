<?php
/**
 * api/member_forgot_password.php
 * ─────────────────────────────────────────────────────────────────
 * Handles forgot password requests, OTP generation & dispatch,
 * and OTP / Token verification for password resets.
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

$action = $data['action'] ?? '';
if (empty($action)) {
    if (!empty($data['otp']) && !empty($data['new_password'])) {
        $action = 'verify_otp_reset';
    } elseif (!empty($data['token']) && !empty($data['new_password'])) {
        $action = 'verify_token_reset';
    } else {
        $action = 'request_otp';
    }
}

// Ensure password_resets table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        member_id INT NOT NULL,
        email VARCHAR(191) NOT NULL,
        otp VARCHAR(10) NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_member (member_id),
        KEY idx_token (token),
        KEY idx_email_otp (email, otp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    // Non-fatal if table already exists
}

function mask_email($email) {
    $parts = explode('@', $email);
    if (count($parts) !== 2) return $email;
    $name = $parts[0];
    $len = strlen($name);
    if ($len <= 2) {
        $masked_name = substr($name, 0, 1) . '*';
    } else {
        $masked_name = substr($name, 0, 2) . str_repeat('*', max(2, $len - 3)) . substr($name, -1);
    }
    return $masked_name . '@' . $parts[1];
}

// ─────────────────────────────────────────────────────────────────
// ACTION 1: REQUEST OTP / RESET LINK
// ─────────────────────────────────────────────────────────────────
if ($action === 'request_otp' || $action === 'request') {
    $identifier = trim($data['email'] ?? $data['identifier'] ?? $data['username'] ?? '');

    if (empty($identifier)) {
        echo json_encode(['success' => false, 'message' => 'Please enter your registered email address or Membership ID.']);
        exit;
    }

    try {
        // Find member by Email OR Membership ID
        $stmt = $pdo->prepare("SELECT id, membership_id, full_name, email, account_status FROM members WHERE LOWER(email) = LOWER(?) OR LOWER(membership_id) = LOWER(?) LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member || empty($member['email'])) {
            // Friendly message
            echo json_encode([
                'success' => false,
                'message' => 'No account found matching that email or Membership ID. Please check and try again.'
            ]);
            exit;
        }

        $email = strtolower(trim($member['email']));
        $otp = strval(random_int(100000, 999999));
        $reset_token = bin2hex(random_bytes(24));

        // Clean existing active reset tokens for this member
        $pdo->prepare("DELETE FROM password_resets WHERE member_id = ? OR email = ?")->execute([$member['id'], $email]);

        // Insert new record valid for 1 hour
        $insert = $pdo->prepare("INSERT INTO password_resets (member_id, email, otp, token, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
        $insert->execute([$member['id'], $email, $otp, $reset_token]);

        $base = rtrim(defined('APP_URL') ? APP_URL : 'https://palmas-gym-4oxn.onrender.com', '/');
        $reset_url = $base . '/member/forgot_password.php?token=' . urlencode($reset_token);

        $subject = "Password Reset Code: {$otp} — Palma's Elite Gym";
        $title = "Reset Your Account Password";
        $body = '
        <p>Dear <strong>' . htmlspecialchars($member['full_name']) . '</strong>,</p>
        <p>We received a request to reset the password for your Palma\'s Elite Gym account (<strong>' . htmlspecialchars($member['membership_id']) . '</strong>).</p>
        
        <div style="background-color:#F4F9F6; border:2px solid #52B788; border-radius:12px; padding:22px 16px; margin:24px 0; text-align:center;">
            <p style="margin:0 0 6px; font-size:12px; color:#2D6A4F; font-weight:bold; letter-spacing:1.5px; text-transform:uppercase;">Your 6-Digit OTP Verification Code</p>
            <span style="font-size:34px; font-weight:900; letter-spacing:8px; color:#1B4332; font-family:monospace; display:block; margin:8px 0;">' . $otp . '</span>
            <p style="margin:6px 0 0; font-size:11px; color:#64748B;">Enter this code in the app or portal to set a new password. Valid for 1 hour.</p>
        </div>

        <p style="text-align:center; margin:20px 0;">
            <a href="' . $reset_url . '" style="display:inline-block; background-color:#1B4332; color:#ffffff; font-weight:bold; font-size:14px; padding:12px 24px; text-decoration:none; border-radius:8px;">Reset Password in Web Browser</a>
        </p>
        <p style="font-size:12px; color:#94A3B8; margin-top:20px;">If you did not request this, you can safely ignore this email. Your account remains secure.</p>
        ';

        $mail_res = send_email_notification($email, $subject, $title, $body);

        log_activity($pdo, 'Password Reset Requested', "OTP {$otp} dispatched to {$email} for member {$member['full_name']} ({$member['membership_id']})", 'Auth', $member['id'], $member['full_name']);

        $masked = mask_email($email);
        echo json_encode([
            'success' => true,
            'email' => $email,
            'masked_email' => $masked,
            'membership_id' => $member['membership_id'],
            'message' => "Verification code sent to {$masked}! Please check your email inbox and spam folder."
        ]);
        exit;

    } catch (Throwable $e) {
        error_log('Forgot password request error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Unable to send password reset code right now. Please try again or contact the front desk.'
        ]);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────
// ACTION 2: VERIFY OTP AND SET NEW PASSWORD
// ─────────────────────────────────────────────────────────────────
if ($action === 'verify_otp_reset' || $action === 'reset_with_otp') {
    $identifier = trim($data['email'] ?? $data['identifier'] ?? $data['username'] ?? '');
    $otp        = trim($data['otp'] ?? $data['code'] ?? '');
    $new_pass   = trim($data['new_password'] ?? $data['password'] ?? '');

    if (empty($identifier)) {
        echo json_encode(['success' => false, 'message' => 'Please provide your email or Membership ID.']);
        exit;
    }
    if (empty($otp) || strlen($otp) < 4) {
        echo json_encode(['success' => false, 'message' => 'Please enter the 6-digit OTP code sent to your email.']);
        exit;
    }
    if (empty($new_pass) || strlen($new_pass) < 6) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters long.']);
        exit;
    }

    try {
        // Find matching reset record by email/member_id & OTP
        $stmt = $pdo->prepare("
            SELECT pr.id, pr.member_id, pr.email, m.full_name, m.membership_id
            FROM password_resets pr
            JOIN members m ON m.id = pr.member_id
            WHERE (LOWER(pr.email) = LOWER(?) OR LOWER(m.membership_id) = LOWER(?))
              AND pr.otp = ?
              AND pr.expires_at > NOW()
            ORDER BY pr.id DESC
            LIMIT 1
        ");
        $stmt->execute([$identifier, $identifier, $otp]);
        $reset_record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reset_record) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid or expired OTP verification code. Please check the code or request a new one.'
            ]);
            exit;
        }

        $member_id = $reset_record['member_id'];
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);

        // Update password & remove used reset record
        $pdo->prepare("UPDATE members SET password_hash = ? WHERE id = ?")->execute([$hash, $member_id]);
        $pdo->prepare("DELETE FROM password_resets WHERE member_id = ?")->execute([$member_id]);

        log_activity($pdo, 'Password Reset Completed', "Member {$reset_record['full_name']} ({$reset_record['membership_id']}) reset password via OTP.", 'Auth', $member_id, $reset_record['full_name']);

        echo json_encode([
            'success' => true,
            'message' => 'Password reset successful! You can now log in with your new password.',
            'membership_id' => $reset_record['membership_id'],
            'email' => $reset_record['email']
        ]);
        exit;

    } catch (Throwable $e) {
        error_log('OTP reset error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to update password. Please try again.']);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────
// ACTION 3: VERIFY TOKEN (Direct Link from Email)
// ─────────────────────────────────────────────────────────────────
if ($action === 'verify_token_reset' || $action === 'reset_with_token') {
    $token    = trim($data['token'] ?? '');
    $new_pass = trim($data['new_password'] ?? $data['password'] ?? '');

    if (empty($token)) {
        echo json_encode(['success' => false, 'message' => 'Reset token is required.']);
        exit;
    }
    if (empty($new_pass) || strlen($new_pass) < 6) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters long.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT pr.id, pr.member_id, pr.email, m.full_name, m.membership_id
            FROM password_resets pr
            JOIN members m ON m.id = pr.member_id
            WHERE pr.token = ? AND pr.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $reset_record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reset_record) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid or expired password reset link. Please request a new code.'
            ]);
            exit;
        }

        $member_id = $reset_record['member_id'];
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);

        $pdo->prepare("UPDATE members SET password_hash = ? WHERE id = ?")->execute([$hash, $member_id]);
        $pdo->prepare("DELETE FROM password_resets WHERE member_id = ?")->execute([$member_id]);

        log_activity($pdo, 'Password Reset Completed', "Member {$reset_record['full_name']} ({$reset_record['membership_id']}) reset password via token link.", 'Auth', $member_id, $reset_record['full_name']);

        echo json_encode([
            'success' => true,
            'message' => 'Password reset successful! You can now log in with your new password.',
            'membership_id' => $reset_record['membership_id'],
            'email' => $reset_record['email']
        ]);
        exit;

    } catch (Throwable $e) {
        error_log('Token reset error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to update password. Please try again.']);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request action.']);
