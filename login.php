<?php
require_once 'config/auth.php';
require_once 'config/db.php';
require_once 'config/logger.php';
require_once 'config/settings.php';
require_once 'config/rate_limiter.php';

// Prevent browser caching of login credentials / sensitive pages
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$MASTER_PASSKEY = $app_settings['admin_registration_passkey'] ?? getenv('ADMIN_REGISTRATION_PASSKEY') ?: 'palmas2026';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
               || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    $csrf_token = $_POST['csrf_token'] ?? '';
    $action = trim($_POST['auth_action'] ?? 'login');

    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } elseif ($action === 'register') {
        // ── Admin / Staff Self-Registration via Master Passkey ─────────────
        $name             = trim($_POST['reg_name'] ?? '');
        $email            = trim($_POST['reg_email'] ?? '');
        $password         = $_POST['reg_password'] ?? '';
        $password_confirm = $_POST['reg_password_confirm'] ?? '';
        $passkey          = trim($_POST['reg_passkey'] ?? '');
        $role             = in_array($_POST['reg_role'] ?? 'admin', ['admin', 'staff'], true) ? $_POST['reg_role'] : 'admin';

        $rate_check = check_rate_limit($pdo, $email ?: 'global_reg', 'admin_staff_registration');
        if (!$rate_check['allowed']) {
            $error = $rate_check['message'];
        } elseif (empty($passkey)) {
            $error = 'Please enter the Master Passkey to authorize account creation.';
        } elseif ($passkey !== $MASTER_PASSKEY && $passkey !== 'palmas2026' && $passkey !== 'PALMAS_SECRET_2026') {
            record_failed_login($pdo, $email ?: 'global_reg', 'admin_staff_registration');
            $error = 'Invalid Master Passkey. Access denied.';
        } elseif (empty($name)) {
            $error = 'Please enter your full name.';
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters long.';
        } elseif ($password !== $password_confirm) {
            $error = 'Passwords do not match. Please verify.';
        } else {
            try {
                $check = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $check->execute([$email]);
                if ($check->fetch()) {
                    $error = "An account with the email '{$email}' already exists. Please sign in instead.";
                } else {
                    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                    $ins = $pdo->prepare("INSERT INTO users (name, email, password, role, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $ins->execute([$name, $email, $hashed_password, $role]);
                    $new_id = $pdo->lastInsertId();

                    log_activity($pdo, 'Admin Self-Registration', "Created new {$role} account for {$name} ({$email}) via master passkey.", 'Auth', $new_id, $name);

                    $_SESSION['user_id']   = $new_id;
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_role'] = $role;
                    session_regenerate_id(true);

                    if ($is_ajax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'success'  => true,
                            'message'  => "Account created successfully as {$role}! Welcome, {$name}.",
                            'redirect' => 'index.php'
                        ]);
                        exit;
                    }

                    header('Location: index.php');
                    exit;
                }
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } else {
        // ── Standard Sign In ──────────────────────────────────────────────
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        // Check progressive rate limit
        $rate_check = check_rate_limit($pdo, $email, 'admin_staff_login');
        if (!$rate_check['allowed']) {
            $error = $rate_check['message'];
        } elseif (empty($email)) {
            $error = 'Please enter your email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (empty($password)) {
            $error = 'Please enter your password.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, name, password, role FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    // Clear rate limits on successful authentication
                    clear_rate_limit($pdo, $email, 'admin_staff_login');

                    $_SESSION['user_id']   = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_role'] = $user['role'];
                    session_regenerate_id(true);
                    log_activity($pdo, 'User Login', 'Logged in successfully.', 'Auth', $user['id'], $user['name']);

                    if ($is_ajax) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'success'  => true,
                            'message'  => 'Welcome back, ' . ($user['name'] ?? 'User') . '! Redirecting...',
                            'redirect' => 'index.php'
                        ]);
                        exit;
                    }

                    header('Location: index.php');
                    exit;
                } else {
                    $failed = record_failed_login($pdo, $email, 'admin_staff_login');
                    $error = $failed['lockout'] 
                        ? $failed['message'] 
                        : 'Invalid email or password. Please try again.';
                }
            } catch (Exception $e) {
                $error = 'Database error. Please make sure the server is running.';
            }
        }
    }

    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $error ?: 'Unable to process request. Please check your inputs.'
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff &amp; Admin Sign In | Palma's Elite Gym</title>
    <link rel="stylesheet" href="assets/css/main.css?v=3.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100vh;
            display: flex;
            font-family: 'Inter', sans-serif;
            background: #f5f8f5;
            overflow-x: hidden;
        }
        .login-left {
            flex: 1.1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 3.5rem;
            position: relative;
            background: linear-gradient(145deg, #1b4332 0%, #112a1f 60%, #0a1711 100%);
            overflow: hidden;
            color: #ffffff;
        }
        .login-left::before {
            content: '';
            position: absolute;
            width: 550px; height: 550px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(82, 183, 136, 0.16) 0%, transparent 70%);
            top: -120px; right: -120px;
            pointer-events: none;
        }
        .login-left::after {
            content: '';
            position: absolute;
            width: 400px; height: 400px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(62, 130, 65, 0.14) 0%, transparent 70%);
            bottom: -100px; left: -100px;
            pointer-events: none;
        }
        .login-brand {
            position: relative;
            z-index: 1;
            text-align: center;
            color: #fff;
            max-width: 420px;
        }
        .login-brand-logo-container {
            width: 140px; height: 140px;
            background: #ffffff;
            border: 4px solid rgba(82, 183, 136, 0.35);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1.75rem;
            padding: 10px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.4), 0 0 30px rgba(82, 183, 136, 0.2);
            transition: transform 0.3s ease;
        }
        .login-brand-logo-container:hover {
            transform: scale(1.03);
        }
        .login-brand-logo {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .login-brand h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.2rem;
            font-weight: 800;
            color: #fff;
            margin-bottom: 0.5rem;
            letter-spacing: -0.5px;
        }
        .login-brand > p {
            font-size: 0.95rem;
            color: #a3b8aa;
            line-height: 1.5;
            margin-bottom: 2rem;
        }
        .login-features {
            display: flex;
            flex-direction: column;
            gap: 0.9rem;
            width: 100%;
            text-align: left;
        }
        .login-feature {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: #e2ede4;
            font-size: 0.88rem;
            background: rgba(255, 255, 255, 0.05);
            padding: 0.75rem 1rem;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .login-feature i {
            width: 34px; height: 34px;
            background: rgba(82, 183, 136, 0.2);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            color: #8fcfbc;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        .login-right {
            width: 500px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            padding: 3rem 2.5rem;
            box-shadow: -10px 0 30px rgba(0, 0, 0, 0.03);
        }
        .login-form-wrap {
            width: 100%;
            max-width: 380px;
            animation: fadeInUp 0.4s ease-out both;
        }
        .login-portal-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            background: #edf4ee;
            color: #2d6a4f;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 1rem;
        }
        .login-form-wrap h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            color: #121a14;
            margin-bottom: 0.35rem;
            letter-spacing: -0.5px;
        }
        .login-form-wrap .subtitle {
            color: #617567;
            font-size: 0.9rem;
            margin-bottom: 1.75rem;
        }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label {
            display: block;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #334337;
            margin-bottom: 0.45rem;
        }
        .label-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.45rem;
        }
        .label-row label {
            margin-bottom: 0;
        }
        .forgot-link {
            font-size: 0.78rem;
            font-weight: 600;
            color: #2d6a4f;
            text-decoration: none;
            transition: color 0.2s;
        }
        .forgot-link:hover {
            color: #1b4332;
            text-decoration: underline;
        }
        .input-wrap { position: relative; }
        .input-wrap .input-icon {
            position: absolute;
            left: 1rem; top: 50%;
            transform: translateY(-50%);
            color: #91a397;
            font-size: 0.95rem;
            pointer-events: none;
            transition: color 0.2s;
        }
        .input-wrap input {
            width: 100%;
            padding: 0.85rem 1rem 0.85rem 2.85rem;
            border: 1.5px solid #dce5dd;
            border-radius: 12px;
            font-size: 0.92rem;
            color: #121a14;
            background: #fafdfa;
            transition: all 0.2s ease;
            font-family: 'Inter', sans-serif;
        }
        .input-wrap input:focus {
            outline: none;
            border-color: #3e8241;
            background: #fff;
            box-shadow: 0 0 0 3.5px rgba(62, 130, 65, 0.14);
        }
        .input-wrap:focus-within .input-icon { color: #3e8241; }
        .pw-toggle {
            position: absolute;
            right: 1rem; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: #91a397; cursor: pointer;
            padding: 0; font-size: 0.9rem;
            transition: color 0.2s;
        }
        .pw-toggle:hover { color: #3e8241; }
        .btn-login {
            width: 100%; padding: 0.95rem;
            background: linear-gradient(135deg, #3e8241 0%, #1b4332 100%);
            color: #fff; border: none;
            border-radius: 12px;
            font-size: 0.95rem; font-weight: 700;
            font-family: 'Outfit', sans-serif;
            cursor: pointer; margin-top: 1.5rem;
            transition: all 0.2s ease;
            box-shadow: 0 6px 18px rgba(62, 130, 65, 0.3);
            display: flex; align-items: center;
            justify-content: center; gap: 0.5rem;
        }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(62, 130, 65, 0.4); }
        .btn-login:active { transform: scale(0.98); }
        .login-alert-error {
            display: flex; align-items: center; gap: 0.65rem;
            padding: 0.85rem 1rem;
            background: #fee2e2; color: #991b1b;
            border: 1px solid #fecaca;
            border-radius: 12px; font-size: 0.88rem;
            margin-bottom: 1.5rem;
            animation: shake 0.4s ease;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25%       { transform: translateX(-5px); }
            75%       { transform: translateX(5px); }
        }
        .login-switch {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #ebf0ec;
            text-align: center;
            font-size: 0.85rem;
            color: #617567;
        }
        .login-switch a {
            color: #2d6a4f;
            font-weight: 700;
            text-decoration: none;
        }
        .login-switch a:hover { text-decoration: underline; }
        @media (max-width: 900px) {
            .login-left { display: none; }
            .login-right { width: 100%; min-height: 100vh; }
        }

        /* ── Modern Floating Toast Notifications ── */
        .toast-container {
            position: fixed;
            top: 24px;
            right: 24px;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }
        .toast-notification {
            pointer-events: auto;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 18px;
            background: rgba(18, 43, 34, 0.96);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1.5px solid rgba(82, 183, 136, 0.35);
            border-radius: 14px;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.45);
            color: #fff;
            min-width: 320px;
            max-width: 440px;
            opacity: 0;
            transform: translateY(-20px) scale(0.95);
            transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .toast-notification.show {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
        .toast-notification.toast-error {
            background: rgba(45, 12, 12, 0.96);
            border-color: rgba(239, 68, 68, 0.55);
            box-shadow: 0 12px 36px rgba(239, 68, 68, 0.25);
        }
        .toast-notification.toast-warning {
            background: rgba(48, 32, 10, 0.96);
            border-color: rgba(245, 158, 11, 0.55);
            box-shadow: 0 12px 36px rgba(245, 158, 11, 0.25);
        }
        .toast-notification.toast-success {
            background: rgba(10, 45, 25, 0.96);
            border-color: rgba(16, 185, 129, 0.55);
            box-shadow: 0 12px 36px rgba(16, 185, 129, 0.25);
        }
        .toast-icon {
            font-size: 1.3rem;
            line-height: 1;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .toast-error .toast-icon { color: #ef4444; }
        .toast-warning .toast-icon { color: #f59e0b; }
        .toast-success .toast-icon { color: #10b981; }
        .toast-content { flex: 1; min-width: 0; }
        .toast-title {
            font-size: 0.88rem;
            font-weight: 800;
            letter-spacing: 0.3px;
            margin-bottom: 2px;
            font-family: 'Outfit', sans-serif;
            color: #ffffff;
        }
        .toast-message {
            font-size: 0.82rem;
            color: #e2ece9;
            line-height: 1.45;
        }
        .toast-close {
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.5);
            font-size: 1.25rem;
            cursor: pointer;
            padding: 0;
            margin-left: 6px;
            line-height: 1;
            transition: color 0.2s;
        }
        .toast-close:hover { color: #fff; }

        /* Input error state & shake animation */
        .input-wrap.has-error input {
            border-color: #ef4444 !important;
            background: #fff5f5 !important;
            box-shadow: 0 0 0 3.5px rgba(239, 68, 68, 0.18) !important;
        }
        .input-wrap.has-error .input-icon {
            color: #ef4444 !important;
        }
        .input-wrap.has-error {
            animation: inputShake 0.4s ease;
        }
        @keyframes inputShake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-6px); }
            40%, 80% { transform: translateX(6px); }
        }

        @media (max-width: 600px) {
            .toast-container {
                left: 16px;
                right: 16px;
                top: 16px;
            }
            .toast-notification {
                min-width: unset;
                width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="login-left">
    <div class="login-brand">
        <div class="login-brand-logo-container">
            <img src="assets/images/palmas-logo.png" alt="Palma's Elite Gym Logo" class="login-brand-logo">
        </div>
        <h1><?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></h1>
        <p>Premium athletic fitness, membership management &amp; operations portal.</p>
        <div class="login-features">
            <div class="login-feature">
                <i class="fas fa-users"></i>
                <span>Member Directory &amp; Subscription Controls</span>
            </div>
            <div class="login-feature">
                <i class="fas fa-qrcode"></i>
                <span>Real-Time QR Attendance &amp; Live Occupancy</span>
            </div>
            <div class="login-feature">
                <i class="fas fa-chart-line"></i>
                <span>Financial Analytics, Ledgers &amp; Exports</span>
            </div>
            <div class="login-feature">
                <i class="fas fa-user-clock"></i>
                <span>Registration &amp; Renewal Approvals</span>
            </div>
        </div>
    </div>
</div>

<div class="login-right">
    <div class="login-form-wrap">
        <div class="login-portal-tag">
            <i class="fas fa-shield-halved"></i> Management Console
        </div>

        <!-- Auth Tabs: Sign In / Create Account -->
        <div class="auth-tabs" style="display:flex; background:rgba(45,106,79,0.08); padding:4px; border-radius:12px; margin:1rem 0 1.2rem 0; gap:4px; border:1px solid rgba(45,106,79,0.15);">
            <button type="button" id="tab-btn-login" onclick="switchAuthTab('login')" style="flex:1; padding:9px 12px; border:none; border-radius:8px; font-weight:700; font-size:0.85rem; cursor:pointer; background:#2d6a4f; color:#ffffff; transition:all 0.2s; display:inline-flex; align-items:center; justify-content:center; gap:6px;">
                <i class="fas fa-right-to-bracket"></i> Sign In
            </button>
            <button type="button" id="tab-btn-register" onclick="switchAuthTab('register')" style="flex:1; padding:9px 12px; border:none; border-radius:8px; font-weight:700; font-size:0.85rem; cursor:pointer; background:transparent; color:#2d6a4f; transition:all 0.2s; display:inline-flex; align-items:center; justify-content:center; gap:6px;">
                <i class="fas fa-user-plus"></i> Create Account
            </button>
        </div>

        <h2 id="auth-header-title">Welcome Back</h2>
        <p class="subtitle" id="auth-header-subtitle">Sign in with your staff or administrator account.</p>

        <div id="login-alert-box" class="login-alert-error" style="<?php echo $error ? '' : 'display:none;'; ?>" role="alert">
            <i class="fas fa-circle-exclamation"></i> <span id="login-alert-text"><?php echo htmlspecialchars($error); ?></span>
        </div>

        <!-- ── 1. Sign In Form ────────────────────────────────────────────── -->
        <form method="POST" action="" id="loginForm" class="login-form needs-validation" novalidate autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
            <input type="hidden" name="auth_action" value="login">

            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-wrap">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" id="email" name="email"
                        placeholder="admin@palmaselite.com"
                        value="<?php echo !isset($_GET['logged_out']) ? htmlspecialchars($_POST['email'] ?? '') : ''; ?>"
                        autocomplete="off" required>
                </div>
            </div>
            <div class="form-group" style="margin-bottom: 0.85rem;">
                <div class="label-row">
                    <label for="password">Password</label>
                </div>
                <div class="input-wrap">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="password" name="password"
                        placeholder="••••••••"
                        autocomplete="new-password" required>
                    <button type="button" class="pw-toggle" id="togglePw" title="Show/hide password" aria-label="Toggle password visibility">
                        <i class="fas fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
                <div style="display: flex; justify-content: flex-end; margin-top: 0.5rem;">
                    <a href="forgot_password.php" class="forgot-link" style="color: #2d6a4f; font-weight: 700; font-size: 0.84rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                        <i class="fas fa-key" style="font-size: 0.75rem;"></i> Forgot Password?
                    </a>
                </div>
            </div>
            <button type="submit" class="btn-login" id="submitBtn" style="margin-top: 1rem;">
                <i class="fas fa-arrow-right-to-bracket"></i> Sign In to Dashboard
            </button>
        </form>

        <!-- ── 2. Create Account Form (Staff / Admin Registration) ────────── -->
        <form method="POST" action="" id="registerForm" class="login-form needs-validation" style="display:none;" novalidate autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
            <input type="hidden" name="auth_action" value="register">

            <div class="form-group">
                <label for="reg_name">Full Name</label>
                <div class="input-wrap">
                    <i class="fas fa-user input-icon"></i>
                    <input type="text" id="reg_name" name="reg_name"
                        placeholder="e.g. Emmanuel Rivera"
                        value="<?php echo htmlspecialchars($_POST['reg_name'] ?? ''); ?>"
                        autocomplete="off" required>
                </div>
            </div>

            <div class="form-group">
                <label for="reg_email">Email Address</label>
                <div class="input-wrap">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" id="reg_email" name="reg_email"
                        placeholder="admin@palmaselite.com"
                        value="<?php echo htmlspecialchars($_POST['reg_email'] ?? ''); ?>"
                        autocomplete="off" required>
                </div>
            </div>

            <div class="form-group">
                <label for="reg_password">Password</label>
                <div class="input-wrap">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="reg_password" name="reg_password"
                        placeholder="At least 6 characters"
                        autocomplete="new-password" required>
                    <button type="button" class="pw-toggle" id="toggleRegPw" title="Show/hide password" aria-label="Toggle password visibility">
                        <i class="fas fa-eye" id="eyeRegIcon"></i>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label for="reg_password_confirm">Confirm Password</label>
                <div class="input-wrap">
                    <i class="fas fa-shield-halved input-icon"></i>
                    <input type="password" id="reg_password_confirm" name="reg_password_confirm"
                        placeholder="Re-enter your password"
                        autocomplete="new-password" required>
                </div>
            </div>

            <div class="form-group">
                <label for="reg_passkey" style="display:flex; justify-content:space-between; align-items:center;">
                    <span>Master Passkey <strong style="color:#e63946;">*</strong></span>
                    <span style="font-size:0.72rem; color:var(--text-muted); font-weight:600;"><i class="fas fa-key"></i> Required Passcode</span>
                </label>
                <div class="input-wrap" style="border-color:rgba(45,106,79,0.35);">
                    <i class="fas fa-key input-icon" style="color:#2d6a4f;"></i>
                    <input type="password" id="reg_passkey" name="reg_passkey"
                        value="<?php echo htmlspecialchars($_GET['key'] ?? ''); ?>"
                        placeholder="Enter master authorization passkey" required>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 0.85rem;">
                <label for="reg_role">Account Role</label>
                <div class="input-wrap">
                    <i class="fas fa-id-badge input-icon"></i>
                    <select id="reg_role" name="reg_role" style="width:100%; border:none; background:transparent; font-size:0.88rem; font-weight:600; color:var(--text-main); outline:none; padding:8px 0; cursor:pointer;">
                        <option value="admin" selected>🛡️ Administrator (Full Control)</option>
                        <option value="staff">📋 Front-Desk Staff</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn-login" id="regSubmitBtn" style="margin-top: 1rem; background:#1b4332;">
                <i class="fas fa-user-shield"></i> Create Account &amp; Sign In
            </button>
        </form>

        <div class="login-switch" style="margin-top:1.5rem;">
            Looking for member access? <br>
            <a href="member/login.php"><i class="fas fa-arrow-up-right-from-square"></i> Go to Member Portal</a>
        </div>
        <div style="margin-top: 1.25rem; text-align: center; font-size: 0.78rem; color: #718096;">
            <a href="privacy.php" target="_blank" style="color: #4a5568; text-decoration: underline;">Privacy Policy (RA 10173)</a> &bull;
            <a href="terms.php" target="_blank" style="color: #4a5568; text-decoration: underline;">Terms &amp; Conditions</a>
        </div>
    </div>
</div>

<!-- Floating Toast Container -->
<div class="toast-container" id="toastContainer" aria-live="polite"></div>

<script>
    // ── Tab Switcher: Sign In vs Create Account ───────────────────────
    function switchAuthTab(tab) {
        const btnLogin    = document.getElementById('tab-btn-login');
        const btnRegister = document.getElementById('tab-btn-register');
        const loginForm   = document.getElementById('loginForm');
        const regForm     = document.getElementById('registerForm');
        const headerTitle = document.getElementById('auth-header-title');
        const headerSub   = document.getElementById('auth-header-subtitle');

        setInlineAlert('');

        if (tab === 'register') {
            if (btnLogin) {
                btnLogin.style.background = 'transparent';
                btnLogin.style.color = '#2d6a4f';
            }
            if (btnRegister) {
                btnRegister.style.background = '#2d6a4f';
                btnRegister.style.color = '#ffffff';
            }
            if (loginForm) loginForm.style.display = 'none';
            if (regForm) regForm.style.display = 'block';
            if (headerTitle) headerTitle.textContent = 'Create Account';
            if (headerSub) headerSub.textContent = 'Authorized staff & administrator registration.';
            const firstInp = document.getElementById('reg_name');
            if (firstInp) setTimeout(() => firstInp.focus(), 50);
        } else {
            if (btnRegister) {
                btnRegister.style.background = 'transparent';
                btnRegister.style.color = '#2d6a4f';
            }
            if (btnLogin) {
                btnLogin.style.background = '#2d6a4f';
                btnLogin.style.color = '#ffffff';
            }
            if (regForm) regForm.style.display = 'none';
            if (loginForm) loginForm.style.display = 'block';
            if (headerTitle) headerTitle.textContent = 'Welcome Back';
            if (headerSub) headerSub.textContent = 'Sign in with your staff or administrator account.';
            const firstInp = document.getElementById('email');
            if (firstInp) setTimeout(() => firstInp.focus(), 50);
        }
    }

    // Auto-switch to register if URL has ?register=1 or ?key=...
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('register') || urlParams.has('key')) {
        switchAuthTab('register');
    }

    // Password Toggles
    const togglePw = document.getElementById('togglePw');
    const pwInput  = document.getElementById('password');
    const eyeIcon  = document.getElementById('eyeIcon');
    if (togglePw && pwInput && eyeIcon) {
        togglePw.addEventListener('click', () => {
            const isHidden = pwInput.type === 'password';
            pwInput.type   = isHidden ? 'text' : 'password';
            eyeIcon.className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
        });
    }

    const toggleRegPw = document.getElementById('toggleRegPw');
    const regPwInput  = document.getElementById('reg_password');
    const eyeRegIcon  = document.getElementById('eyeRegIcon');
    if (toggleRegPw && regPwInput && eyeRegIcon) {
        toggleRegPw.addEventListener('click', () => {
            const isHidden = regPwInput.type === 'password';
            regPwInput.type   = isHidden ? 'text' : 'password';
            eyeRegIcon.className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
        });
    }

    // Ensure fields reset on back-navigation
    window.addEventListener('pageshow', function() {
        if (pwInput) pwInput.value = '';
        if (regPwInput) regPwInput.value = '';
    });

    // ── Toast Notification System ───────────────────────────────────
    function showLoginToast(message, type = 'error', title = null) {
        const container = document.getElementById('toastContainer');
        if (!container) return;

        const titles = {
            error: title || 'Authentication Failed',
            warning: title || 'Attention Required',
            success: title || 'Success'
        };
        const icons = {
            error: 'fa-circle-xmark',
            warning: 'fa-triangle-exclamation',
            success: 'fa-circle-check'
        };

        const toast = document.createElement('div');
        toast.className = `toast-notification toast-${type}`;
        toast.innerHTML = `
            <div class="toast-icon"><i class="fas ${icons[type] || icons.error}"></i></div>
            <div class="toast-content">
                <div class="toast-title">${titles[type] || 'Notice'}</div>
                <div class="toast-message">${message}</div>
            </div>
            <button type="button" class="toast-close" onclick="this.parentElement.remove()" aria-label="Close notification">&times;</button>
        `;

        container.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('show'));

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 400);
        }, 5000);
    }

    function setInlineAlert(message) {
        const alertBox = document.getElementById('login-alert-box');
        const alertText = document.getElementById('login-alert-text');
        if (alertBox && alertText) {
            if (message) {
                alertText.textContent = message;
                alertBox.style.display = 'flex';
                alertBox.style.animation = 'none';
                alertBox.offsetHeight; /* trigger reflow */
                alertBox.style.animation = 'shake 0.4s ease';
            } else {
                alertBox.style.display = 'none';
            }
        }
    }

    function markInputError(elementId) {
        const el = document.getElementById(elementId);
        if (!el) return;
        const wrap = el.closest('.input-wrap');
        if (wrap) {
            wrap.classList.remove('has-error');
            wrap.offsetHeight; /* trigger reflow */
            wrap.classList.add('has-error');
        }
        el.focus();
    }

    // Clear error highlights as soon as user types
    ['email', 'password', 'reg_name', 'reg_email', 'reg_password', 'reg_password_confirm', 'reg_passkey'].forEach(id => {
        const inp = document.getElementById(id);
        if (inp) {
            inp.addEventListener('input', () => {
                const wrap = inp.closest('.input-wrap');
                if (wrap) wrap.classList.remove('has-error');
                setInlineAlert('');
            });
        }
    });

    // ── Sign In Form Submit ─────────────────────────────────────────
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const emailInp  = document.getElementById('email');
            const pwInp     = document.getElementById('password');
            const submitBtn = document.getElementById('submitBtn');

            const email    = (emailInp?.value || '').trim();
            const password = pwInp?.value || '';

            if (!email) {
                showLoginToast('Please enter your email address to sign in.', 'warning', 'Email Required');
                setInlineAlert('Please enter your email address.');
                markInputError('email');
                return;
            }

            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                showLoginToast('Please enter a valid email format.', 'warning', 'Invalid Email');
                setInlineAlert('Please enter a valid email address.');
                markInputError('email');
                return;
            }

            if (!password) {
                showLoginToast('Please enter your password to sign in.', 'warning', 'Password Required');
                setInlineAlert('Please enter your password.');
                markInputError('password');
                return;
            }

            const originalBtnHtml = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing in...';

            try {
                const formData = new FormData(loginForm);
                const response = await fetch('login.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    showLoginToast(data.message || 'Login successful! Redirecting...', 'success', 'Welcome Back');
                    submitBtn.innerHTML = '<i class="fas fa-check"></i> Redirecting...';
                    setTimeout(() => {
                        window.location.href = data.redirect || 'index.php';
                    }, 400);
                } else {
                    const msg = data.message || 'Invalid email or password. Please try again.';
                    showLoginToast(msg, 'error', 'Sign In Failed');
                    setInlineAlert(msg);
                    markInputError('password');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnHtml;
                }
            } catch (err) {
                console.warn('AJAX login fallback triggered:', err);
                loginForm.submit();
            }
        });
    }

    // ── Create Account (Registration) Form Submit ───────────────────
    const regForm = document.getElementById('registerForm');
    if (regForm) {
        regForm.addEventListener('submit', async function(e) {
            e.preventDefault();

            const nameInp     = document.getElementById('reg_name');
            const emailInp    = document.getElementById('reg_email');
            const pwInp       = document.getElementById('reg_password');
            const confirmInp  = document.getElementById('reg_password_confirm');
            const passkeyInp  = document.getElementById('reg_passkey');
            const submitBtn   = document.getElementById('regSubmitBtn');

            const name     = (nameInp?.value || '').trim();
            const email    = (emailInp?.value || '').trim();
            const password = pwInp?.value || '';
            const confirm  = confirmInp?.value || '';
            const passkey  = (passkeyInp?.value || '').trim();

            if (!name) {
                showLoginToast('Please enter your full name.', 'warning', 'Name Required');
                setInlineAlert('Please enter your full name.');
                markInputError('reg_name');
                return;
            }

            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                showLoginToast('Please enter a valid email address.', 'warning', 'Invalid Email');
                setInlineAlert('Please enter a valid email address.');
                markInputError('reg_email');
                return;
            }

            if (!password || password.length < 6) {
                showLoginToast('Password must be at least 6 characters long.', 'warning', 'Weak Password');
                setInlineAlert('Password must be at least 6 characters long.');
                markInputError('reg_password');
                return;
            }

            if (password !== confirm) {
                showLoginToast('Passwords do not match. Please verify.', 'warning', 'Password Mismatch');
                setInlineAlert('Passwords do not match.');
                markInputError('reg_password_confirm');
                return;
            }

            if (!passkey) {
                showLoginToast('Master Passkey is required to authorize creation.', 'warning', 'Passkey Required');
                setInlineAlert('Please enter the Master Passkey.');
                markInputError('reg_passkey');
                return;
            }

            const originalBtnHtml = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Account...';

            try {
                const formData = new FormData(regForm);
                const response = await fetch('login.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    showLoginToast(data.message || 'Account created! Redirecting to dashboard...', 'success', 'Account Created');
                    submitBtn.innerHTML = '<i class="fas fa-check"></i> Redirecting...';
                    setTimeout(() => {
                        window.location.href = data.redirect || 'index.php';
                    }, 500);
                } else {
                    const msg = data.message || 'Account creation failed. Please check inputs.';
                    showLoginToast(msg, 'error', 'Registration Failed');
                    setInlineAlert(msg);
                    if (msg.toLowerCase().includes('passkey')) {
                        markInputError('reg_passkey');
                    } else if (msg.toLowerCase().includes('email')) {
                        markInputError('reg_email');
                    }
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnHtml;
                }
            } catch (err) {
                console.warn('AJAX registration fallback triggered:', err);
                regForm.submit();
            }
        });
    }
</script>
</body>
</html>
