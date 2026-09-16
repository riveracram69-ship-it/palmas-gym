<?php
/**
 * Staff & Admin Password Recovery System
 * Palma's Elite Gym Management Portal
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/settings.php';
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/email.php';
require_once __DIR__ . '/config/logger.php';
require_once __DIR__ . '/config/rate_limiter.php';

// Prevent caching of recovery pages
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$token = trim($_GET['token'] ?? '');
$step = !empty($token) ? 'reset_token' : 'request';
$email_val = '';
$masked_email = '';

// Ensure user_password_resets table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        email VARCHAR(191) NOT NULL,
        otp VARCHAR(10) NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user (user_id),
        KEY idx_token (token),
        KEY idx_user_email_otp (email, otp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    error_log("Failed to ensure user_password_resets table: " . $e->getMessage());
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'request';
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        // ── STEP 1: REQUEST OTP / RESET ──────────────────────────
        if ($action === 'request') {
            $email = strtolower(trim($_POST['email'] ?? ''));
            $email_val = $email;

            $rate_check = check_rate_limit($pdo, $email, 'admin_staff_forgot_password');
            if (!$rate_check['allowed']) {
                $error = $rate_check['message'];
            } elseif (empty($email)) {
                $error = 'Please enter your registered staff/admin email address.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE LOWER(email) = ? LIMIT 1");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user && !empty($user['email'])) {
                        // Generate 6-digit OTP & 48-char hex Token
                        $otp = strval(random_int(100000, 999999));
                        $reset_token = bin2hex(random_bytes(24));

                        // Clear old requests for this user
                        $pdo->prepare("DELETE FROM user_password_resets WHERE user_id = ? OR email = ?")->execute([$user['id'], $email]);

                        // Insert new reset token valid for 30 minutes
                        $insert = $pdo->prepare("INSERT INTO user_password_resets (user_id, email, otp, token, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))");
                        $insert->execute([$user['id'], $email, $otp, $reset_token]);

                        // Build URL
                        $base = rtrim(defined('APP_URL') ? APP_URL : 'https://palmas-gym-4oxn.onrender.com', '/');
                        if ($base === '' || str_contains($base, 'localhost')) {
                            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                            $base = $proto . '://' . $host . '/gym';
                        }
                        $reset_url = rtrim($base, '/') . '/forgot_password.php?token=' . urlencode($reset_token);

                        $subject = "Staff Password Reset Code: {$otp} — Palma's Elite Gym";
                        $title = "Reset Your Staff Account Password";
                        $body = '
                        <p>Hello <strong>' . htmlspecialchars($user['name']) . '</strong>,</p>
                        <p>A request was submitted to reset the password for your Palma\'s Elite Gym staff/management account (<strong>' . htmlspecialchars($user['email']) . '</strong>).</p>
                        
                        <div style="background-color:#F4F9F6; border:2px solid #52B788; border-radius:12px; padding:20px 16px; margin:22px 0; text-align:center;">
                            <p style="margin:0 0 6px; font-size:12px; color:#2D6A4F; font-weight:bold; letter-spacing:1px; text-transform:uppercase;">Your 6-Digit Verification Code</p>
                            <span style="font-size:32px; font-weight:900; letter-spacing:8px; color:#1B4332; font-family:monospace; display:block; margin:6px 0;">' . $otp . '</span>
                            <p style="margin:6px 0 0; font-size:11px; color:#64748B;">Enter this code on the verification page. Valid for 30 minutes.</p>
                        </div>

                        <p style="text-align:center; margin:20px 0;">
                            <a href="' . $reset_url . '" style="display:inline-block; padding:12px 24px; background:#1B4332; color:#fff; text-decoration:none; border-radius:8px; font-weight:bold; font-size:14px;">Click Here to Reset in Browser</a>
                        </p>
                        <p style="font-size:12px; color:#94A3B8; margin-top:20px;">If you did not request a password reset, please notify management or ignore this message.</p>
                        ';

                        @send_email_notification($email, $subject, $title, $body);
                        log_activity($pdo, 'Staff Password Reset Requested', "OTP {$otp} generated and sent to {$email} for {$user['name']} (Role: {$user['role']})", 'Auth', $user['id'], $user['name']);

                        // Mask email for privacy
                        $parts = explode('@', $email);
                        $masked_email = substr($parts[0], 0, 2) . '***@' . $parts[1];
                        $step = 'enter_otp';
                        $success = "Verification code sent to {$masked_email}! Please check your email inbox or spam folder.";
                    } else {
                        // Record attempt for rate limiting
                        record_failed_login($pdo, $email, 'admin_staff_forgot_password');
                        $error = 'No registered staff or admin account found with that email address.';
                    }
                } catch (Throwable $e) {
                    error_log("Staff Forgot Password Error: " . $e->getMessage());
                    $error = 'Unable to process your request at the moment. Please try again later.';
                }
            }
        }
        // ── STEP 2: VERIFY OTP & SET PASSWORD ────────────────────
        elseif ($action === 'verify_otp') {
            $email      = strtolower(trim($_POST['email'] ?? ''));
            $otp        = trim($_POST['otp'] ?? '');
            $new_pass   = $_POST['password'] ?? '';
            $cfm_pass   = $_POST['confirm_password'] ?? '';
            $email_val  = $email;

            if (empty($email)) {
                $error = 'Email address is missing. Please start over.';
                $step = 'request';
            } elseif (empty($otp) || strlen($otp) < 6) {
                $error = 'Please enter the full 6-digit OTP verification code.';
                $step = 'enter_otp';
            } elseif (empty($new_pass) || strlen($new_pass) < 6) {
                $error = 'Password must be at least 6 characters long.';
                $step = 'enter_otp';
            } elseif ($new_pass !== $cfm_pass) {
                $error = 'Passwords do not match. Please verify and re-type.';
                $step = 'enter_otp';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        SELECT pr.id, pr.user_id, pr.email, u.name, u.role
                        FROM user_password_resets pr
                        JOIN users u ON u.id = pr.user_id
                        WHERE LOWER(pr.email) = ?
                          AND pr.otp = ?
                          AND pr.expires_at > NOW()
                        ORDER BY pr.id DESC LIMIT 1
                    ");
                    $stmt->execute([$email, $otp]);
                    $reset_row = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$reset_row) {
                        record_failed_login($pdo, $email, 'admin_staff_forgot_password');
                        $error = 'Invalid or expired verification code. Please check the code or request a new one.';
                        $step = 'enter_otp';
                    } else {
                        $user_id = $reset_row['user_id'];
                        $password_hash = password_hash($new_pass, PASSWORD_BCRYPT);

                        // Update password in users table
                        $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $update->execute([$password_hash, $user_id]);

                        // Clean up resets and rate limits
                        $pdo->prepare("DELETE FROM user_password_resets WHERE user_id = ?")->execute([$user_id]);
                        clear_rate_limit($pdo, $email, 'admin_staff_forgot_password');
                        clear_rate_limit($pdo, $email, 'admin_staff_login');

                        // Log Activity
                        log_activity($pdo, 'Staff Password Reset Completed', "Account password reset via OTP for {$reset_row['name']} (Role: {$reset_row['role']})", 'Auth', $user_id, $reset_row['name']);

                        // Send Security Confirmation Email
                        $confirm_subject = "Security Notice: Your Staff Password Was Updated — Palma's Elite Gym";
                        $confirm_title = "Password Successfully Reset";
                        $confirm_body = "
                        <p>Hello <strong>" . htmlspecialchars($reset_row['name']) . "</strong>,</p>
                        <p>The password for your Palma's Elite Gym staff/admin account was successfully updated on <strong>" . date('F j, Y, g:i A') . "</strong>.</p>
                        <p style='color:#b91c1c; font-size:13px; margin-top:16px;'>If you did NOT make this change, please alert the system administrator immediately to lock and secure your account.</p>
                        ";
                        @send_email_notification($reset_row['email'], $confirm_subject, $confirm_title, $confirm_body);

                        $success = 'Your password has been successfully updated! You can now log in to the management dashboard.';
                        $step = 'done';
                    }
                } catch (Throwable $e) {
                    error_log("Staff OTP Verify Error: " . $e->getMessage());
                    $error = 'An error occurred while updating your password. Please try again.';
                    $step = 'enter_otp';
                }
            }
        }
        // ── STEP 3: RESET VIA URL TOKEN LINK ─────────────────────
        elseif ($action === 'reset_with_token') {
            $r_token  = trim($_POST['token'] ?? '');
            $new_pass = $_POST['password'] ?? '';
            $cfm_pass = $_POST['confirm_password'] ?? '';

            if (empty($r_token)) {
                $error = 'Invalid or missing reset token. Please request a new link.';
                $step = 'request';
            } elseif (empty($new_pass) || strlen($new_pass) < 6) {
                $error = 'Password must be at least 6 characters long.';
                $step = 'reset_token';
                $token = $r_token;
            } elseif ($new_pass !== $cfm_pass) {
                $error = 'Passwords do not match.';
                $step = 'reset_token';
                $token = $r_token;
            } else {
                try {
                    $stmt = $pdo->prepare("
                        SELECT pr.id, pr.user_id, pr.email, u.name, u.role
                        FROM user_password_resets pr
                        JOIN users u ON u.id = pr.user_id
                        WHERE pr.token = ?
                          AND pr.expires_at > NOW()
                        LIMIT 1
                    ");
                    $stmt->execute([$r_token]);
                    $reset_row = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$reset_row) {
                        $error = 'This reset link has expired or is no longer valid. Please request a new code.';
                        $step = 'request';
                    } else {
                        $user_id = $reset_row['user_id'];
                        $password_hash = password_hash($new_pass, PASSWORD_BCRYPT);

                        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$password_hash, $user_id]);
                        $pdo->prepare("DELETE FROM user_password_resets WHERE user_id = ?")->execute([$user_id]);
                        clear_rate_limit($pdo, $reset_row['email'], 'admin_staff_forgot_password');
                        clear_rate_limit($pdo, $reset_row['email'], 'admin_staff_login');

                        log_activity($pdo, 'Staff Password Reset Completed', "Account password reset via direct token link for {$reset_row['name']} (Role: {$reset_row['role']})", 'Auth', $user_id, $reset_row['name']);

                        // Send Security Confirmation Email
                        $confirm_subject = "Security Notice: Your Staff Password Was Updated — Palma's Elite Gym";
                        $confirm_title = "Password Successfully Reset";
                        $confirm_body = "
                        <p>Hello <strong>" . htmlspecialchars($reset_row['name']) . "</strong>,</p>
                        <p>The password for your Palma's Elite Gym staff account was successfully updated via browser link on <strong>" . date('F j, Y, g:i A') . "</strong>.</p>
                        <p style='color:#b91c1c; font-size:13px; margin-top:16px;'>If you did NOT perform this action, please alert management immediately.</p>
                        ";
                        @send_email_notification($reset_row['email'], $confirm_subject, $confirm_title, $confirm_body);

                        $success = 'Your password has been successfully updated! You can now log in to the management dashboard.';
                        $step = 'done';
                    }
                } catch (Throwable $e) {
                    error_log("Staff Token Reset Error: " . $e->getMessage());
                    $error = 'An error occurred while updating your password. Please try again.';
                    $step = 'reset_token';
                    $token = $r_token;
                }
            }
        }
    }
}

// Token validation for direct GET access
if ($step === 'reset_token' && $_SERVER['REQUEST_METHOD'] === 'GET' && !empty($token)) {
    try {
        $check = $pdo->prepare("SELECT id FROM user_password_resets WHERE token = ? AND expires_at > NOW() LIMIT 1");
        $check->execute([$token]);
        if (!$check->fetch()) {
            $error = 'This reset link has expired or has already been used. Please request a new code.';
            $step = 'request';
            $token = '';
        }
    } catch (Throwable $e) {
        $error = 'Unable to validate reset token.';
        $step = 'request';
    }
}

$gym_name = htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Staff Password Recovery &bull; <?php echo $gym_name; ?></title>
    <link rel="icon" type="image/png" href="assets/images/palmas-logo.png">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        :root {
            --c-bg: #0d1510;
            --c-card: #ffffff;
            --c-primary: #3e8241;
            --c-primary-dark: #1b4332;
            --c-primary-light: #52b788;
            --c-primary-pale: #edf4ee;
            --c-text-h: #121a14;
            --c-text-body: #334337;
            --c-text-muted: #617567;
            --c-border: #dce5dd;
            --radius-card: 20px;
            --radius-input: 12px;
            --radius-btn: 12px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #0a110d 0%, #122117 50%, #183324 100%);
            font-family: 'Inter', sans-serif;
            color: var(--c-text-body);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(62, 130, 65, 0.15) 0%, rgba(0, 0, 0, 0) 70%);
            top: -100px;
            right: -100px;
            border-radius: 50%;
            pointer-events: none;
        }

        body::after {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(82, 183, 136, 0.12) 0%, rgba(0, 0, 0, 0) 70%);
            bottom: -100px;
            left: -100px;
            border-radius: 50%;
            pointer-events: none;
        }

        .recovery-card {
            background: var(--c-card);
            border-radius: var(--radius-card);
            width: 100%;
            max-width: 460px;
            padding: 2.75rem 2.25rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.1);
            position: relative;
            z-index: 10;
            animation: cardAppear 0.45s ease-out both;
        }

        @keyframes cardAppear {
            from { opacity: 0; transform: translateY(18px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .recovery-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo-wrap {
            width: 68px;
            height: 68px;
            margin: 0 auto 1.25rem;
            border-radius: 50%;
            background: var(--c-primary-pale);
            border: 2px solid var(--c-primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px;
            box-shadow: 0 4px 14px rgba(62, 130, 65, 0.18);
        }

        .logo-wrap img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .recovery-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            background: var(--c-primary-pale);
            color: var(--c-primary-dark);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 0.75rem;
        }

        .recovery-header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--c-text-h);
            margin-bottom: 0.35rem;
            letter-spacing: -0.5px;
        }

        .recovery-header p {
            font-size: 0.88rem;
            color: var(--c-text-muted);
            line-height: 1.45;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--c-text-body);
            margin-bottom: 0.45rem;
        }

        .input-wrap {
            position: relative;
        }

        .input-wrap .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #91a397;
            font-size: 0.95rem;
            pointer-events: none;
            transition: color 0.2s;
        }

        .input-wrap input {
            width: 100%;
            padding: 0.85rem 1rem 0.85rem 2.85rem;
            border: 1.5px solid var(--c-border);
            border-radius: var(--radius-input);
            font-size: 0.92rem;
            color: var(--c-text-h);
            background: #fafdfa;
            transition: all 0.2s ease;
            font-family: 'Inter', sans-serif;
        }

        .input-wrap input:focus {
            outline: none;
            border-color: var(--c-primary);
            background: #fff;
            box-shadow: 0 0 0 3.5px rgba(62, 130, 65, 0.14);
        }

        .input-wrap:focus-within .input-icon {
            color: var(--c-primary);
        }

        .input-otp {
            font-family: monospace !important;
            font-size: 1.45rem !important;
            font-weight: 800 !important;
            letter-spacing: 8px !important;
            text-align: center !important;
            padding-left: 1rem !important;
            padding-right: 1rem !important;
        }

        .pw-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #91a397;
            cursor: pointer;
            padding: 0;
            font-size: 0.9rem;
            transition: color 0.2s;
        }

        .pw-toggle:hover {
            color: var(--c-primary);
        }

        .btn-submit {
            width: 100%;
            padding: 0.95rem;
            background: linear-gradient(135deg, var(--c-primary) 0%, var(--c-primary-dark) 100%);
            color: #fff;
            border: none;
            border-radius: var(--radius-btn);
            font-size: 0.95rem;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            margin-top: 1.5rem;
            transition: all 0.2s ease;
            box-shadow: 0 6px 18px rgba(62, 130, 65, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(62, 130, 65, 0.4);
        }

        .btn-submit:active {
            transform: scale(0.98);
        }

        .alert-box {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            padding: 0.85rem 1rem;
            border-radius: 12px;
            font-size: 0.86rem;
            line-height: 1.45;
            margin-bottom: 1.5rem;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            animation: shake 0.4s ease;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25%       { transform: translateX(-5px); }
            75%       { transform: translateX(5px); }
        }

        .back-nav {
            margin-top: 1.75rem;
            padding-top: 1.25rem;
            border-top: 1px solid #ebf0ec;
            text-align: center;
        }

        .back-nav a {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            color: var(--c-primary-dark);
            font-size: 0.88rem;
            font-weight: 700;
            text-decoration: none;
            transition: color 0.2s;
        }

        .back-nav a:hover {
            color: var(--c-primary);
            text-decoration: underline;
        }

        .hint-text {
            font-size: 0.78rem;
            color: var(--c-text-muted);
            margin-top: 0.4rem;
            display: block;
        }
    </style>
</head>
<body>

<div class="recovery-card">
    <div class="recovery-header">
        <div class="logo-wrap">
            <img src="assets/images/palmas-logo.png" alt="Palma's Elite Gym Logo" onerror="this.style.display='none';">
        </div>
        <div class="recovery-tag">
            <i class="fas fa-shield-halved"></i> Staff Security Portal
        </div>
        <h1>
            <?php 
                if ($step === 'done') echo 'Password Updated';
                elseif ($step === 'enter_otp' || $step === 'reset_token') echo 'Set New Password';
                else echo 'Recover Account';
            ?>
        </h1>
        <p>
            <?php 
                if ($step === 'done') echo 'Your credentials have been securely changed.';
                elseif ($step === 'enter_otp') echo 'Enter the verification code sent to your email.';
                elseif ($step === 'reset_token') echo 'Choose a strong new password for your account.';
                else echo 'Enter your registered email to receive a recovery code.';
            ?>
        </p>
    </div>

    <?php if ($error): ?>
    <div class="alert-box alert-error" role="alert">
        <i class="fas fa-circle-exclamation" style="margin-top:2px;"></i>
        <div><?php echo htmlspecialchars($error); ?></div>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert-box alert-success" role="status">
        <i class="fas fa-circle-check" style="margin-top:2px;"></i>
        <div><?php echo htmlspecialchars($success); ?></div>
    </div>
    <?php endif; ?>

    <!-- STEP 1: REQUEST OTP -->
    <?php if ($step === 'request'): ?>
    <form method="POST" action="" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
        <input type="hidden" name="action" value="request">
        
        <div class="form-group">
            <label for="email">Staff / Admin Email Address</label>
            <div class="input-wrap">
                <i class="fas fa-envelope input-icon"></i>
                <input type="email" id="email" name="email"
                    placeholder="staff@palmaselite.com"
                    value="<?php echo htmlspecialchars($email_val); ?>"
                    required autofocus>
            </div>
            <span class="hint-text">We will send a 6-digit OTP and recovery link to this address.</span>
        </div>

        <button type="submit" class="btn-submit">
            <i class="fas fa-paper-plane"></i> Send Verification Code
        </button>
    </form>

    <!-- STEP 2: ENTER OTP & NEW PASSWORD -->
    <?php elseif ($step === 'enter_otp'): ?>
    <form method="POST" action="" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
        <input type="hidden" name="action" value="verify_otp">
        <input type="hidden" name="email" value="<?php echo htmlspecialchars($email_val); ?>">

        <div class="form-group">
            <label for="otp">6-Digit OTP Code</label>
            <div class="input-wrap">
                <input type="text" id="otp" name="otp" class="input-otp"
                    placeholder="123456" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" required autofocus>
            </div>
            <span class="hint-text">Check your inbox or spam folder for the 6-digit code.</span>
        </div>

        <div class="form-group">
            <label for="password">New Password</label>
            <div class="input-wrap">
                <i class="fas fa-lock input-icon"></i>
                <input type="password" id="password" name="password" placeholder="At least 6 characters" required autocomplete="new-password">
                <button type="button" class="pw-toggle" onclick="togglePasswordVisibility('password', 'eye1')">
                    <i class="fas fa-eye" id="eye1"></i>
                </button>
            </div>
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirm New Password</label>
            <div class="input-wrap">
                <i class="fas fa-shield-check input-icon"></i>
                <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-type new password" required autocomplete="new-password">
                <button type="button" class="pw-toggle" onclick="togglePasswordVisibility('confirm_password', 'eye2')">
                    <i class="fas fa-eye" id="eye2"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn-submit">
            <i class="fas fa-key"></i> Reset Password
        </button>
    </form>

    <div style="text-align:center; margin-top:14px;">
        <a href="forgot_password.php" style="font-size:0.82rem; color:var(--c-primary-dark); font-weight:600; text-decoration:none;">
            Didn't receive the email? Request a new code
        </a>
    </div>

    <!-- STEP 3: RESET WITH TOKEN LINK -->
    <?php elseif ($step === 'reset_token'): ?>
    <form method="POST" action="" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
        <input type="hidden" name="action" value="reset_with_token">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

        <div class="form-group">
            <label for="token_password">New Password</label>
            <div class="input-wrap">
                <i class="fas fa-lock input-icon"></i>
                <input type="password" id="token_password" name="password" placeholder="At least 6 characters" required autofocus autocomplete="new-password">
                <button type="button" class="pw-toggle" onclick="togglePasswordVisibility('token_password', 'eyeToken1')">
                    <i class="fas fa-eye" id="eyeToken1"></i>
                </button>
            </div>
        </div>

        <div class="form-group">
            <label for="token_confirm_password">Confirm New Password</label>
            <div class="input-wrap">
                <i class="fas fa-shield-check input-icon"></i>
                <input type="password" id="token_confirm_password" name="confirm_password" placeholder="Re-type new password" required autocomplete="new-password">
                <button type="button" class="pw-toggle" onclick="togglePasswordVisibility('token_confirm_password', 'eyeToken2')">
                    <i class="fas fa-eye" id="eyeToken2"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn-submit">
            <i class="fas fa-check"></i> Update Password
        </button>
    </form>

    <!-- STEP 4: COMPLETED -->
    <?php elseif ($step === 'done'): ?>
    <div style="text-align:center; margin-top:1rem;">
        <a href="login.php" class="btn-submit">
            <i class="fas fa-arrow-right-to-bracket"></i> Back to Staff Sign In
        </a>
    </div>
    <?php endif; ?>

    <?php if ($step !== 'done'): ?>
    <div class="back-nav">
        <a href="login.php"><i class="fas fa-arrow-left"></i> Return to Staff Login</a>
    </div>
    <?php endif; ?>
</div>

<script>
function togglePasswordVisibility(fieldId, iconId) {
    const input = document.getElementById(fieldId);
    const icon = document.getElementById(iconId);
    if (!input || !icon) return;
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fas fa-eye';
    }
}
</script>

</body>
</html>
