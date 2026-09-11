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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Security session expired. Please refresh the page and try again.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

    // Check progressive rate limit
    $rate_check = check_rate_limit($pdo, $email, 'admin_staff_login');
    if (!$rate_check['allowed']) {
        $error = $rate_check['message'];
    } elseif (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
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
        <h2>Welcome Back</h2>
        <p class="subtitle">Sign in with your staff or administrator account.</p>

        <?php if ($error): ?>
        <div class="login-alert-error" role="alert">
            <i class="fas fa-circle-exclamation"></i> <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="" class="login-form needs-validation" novalidate autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
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
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="password" name="password"
                        placeholder="••••••••"
                        autocomplete="new-password" required>
                    <button type="button" class="pw-toggle" id="togglePw" title="Show/hide password" aria-label="Toggle password visibility">
                        <i class="fas fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-login" id="submitBtn">
                <i class="fas fa-arrow-right-to-bracket"></i> Sign In to Dashboard
            </button>
        </form>

        <div class="login-switch">
            Looking for member access? <br>
            <a href="member/login.php"><i class="fas fa-arrow-up-right-from-square"></i> Go to Member Portal</a>
        </div>
    </div>
</div>

<script>
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

    // Ensure email & password fields are reset when landing after sign out or back-navigation
    window.addEventListener('pageshow', function() {
        if (pwInput) pwInput.value = '';
        if (new URLSearchParams(window.location.search).has('logged_out')) {
            const emailInp = document.getElementById('email');
            if (emailInp) emailInp.value = '';
        }
    });
</script>
</body>
</html>
