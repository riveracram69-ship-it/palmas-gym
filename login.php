<?php
require_once 'config/auth.php';
require_once 'config/db.php';
require_once 'config/logger.php';
require_once 'config/settings.php';
require_once 'config/rate_limiter.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Gym Pro Management</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100vh;
            display: flex;
            font-family: 'Inter', sans-serif;
            background: #0d0f14;
            overflow: hidden;
        }
        .login-left {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 3rem;
            position: relative;
            background: linear-gradient(145deg, #111521 0%, #0d0f14 60%, #0b1a14 100%);
            overflow: hidden;
        }
        .login-left::before {
            content: '';
            position: absolute;
            width: 500px; height: 500px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(45,106,79,0.12) 0%, transparent 70%);
            top: -100px; right: -100px;
        }
        .login-left::after {
            content: '';
            position: absolute;
            width: 350px; height: 350px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(45,106,79,0.08) 0%, transparent 70%);
            bottom: -80px; left: -80px;
        }
        .login-brand {
            position: relative;
            z-index: 1;
            text-align: center;
            color: #fff;
        }
        @keyframes floatLogo {
            0%, 100% { transform: translateY(0px); }
            50%       { transform: translateY(-10px); }
        }
        .login-brand-logo-container {
            width: 130px; height: 130px;
            background: rgba(255, 255, 255, 0.08);
            border: 2px solid rgba(45, 106, 79, 0.4);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 2rem;
            padding: 10px;
            backdrop-filter: blur(8px);
            box-shadow: 0 20px 50px rgba(45,106,79,0.25);
            animation: floatLogo 3.5s ease-in-out infinite;
        }
        .login-brand-logo {
            width: 100%;
            height: 100%;
            object-fit: contain;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.2));
        }
        .login-brand h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.2rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 0.6rem;
        }
        .login-brand > p {
            font-size: 0.95rem;
            color: rgba(255,255,255,0.45);
            max-width: 300px;
        }
        .login-features {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: 2.5rem;
            width: 100%;
            max-width: 300px;
        }
        .login-feature {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: rgba(255,255,255,0.55);
            font-size: 0.875rem;
        }
        .login-feature i {
            width: 34px; height: 34px;
            background: rgba(45,106,79,0.15);
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            color: #2d6a4f;
            font-size: 0.85rem;
            flex-shrink: 0;
        }
        .login-right {
            width: 460px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            padding: 2.5rem;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .login-form-wrap {
            width: 100%;
            max-width: 380px;
            animation: fadeInUp 0.5s ease-out both;
        }
        .login-form-wrap h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.8rem;
            font-weight: 700;
            color: #1a1d23;
            margin-bottom: 0.4rem;
        }
        .login-form-wrap .subtitle {
            color: #8a909e;
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }
        .form-group { margin-bottom: 1.1rem; }
        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #4a5060;
            margin-bottom: 0.45rem;
        }
        .input-wrap { position: relative; }
        .input-wrap .input-icon {
            position: absolute;
            left: 1rem; top: 50%;
            transform: translateY(-50%);
            color: #c0c6d4;
            font-size: 0.9rem;
            pointer-events: none;
            transition: color 0.2s;
        }
        .input-wrap input {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 2.75rem;
            border: 1.5px solid #e5e9f0;
            border-radius: 10px;
            font-size: 0.9rem;
            color: #1a1d23;
            background: #fafbfc;
            transition: all 0.2s ease;
            font-family: 'Inter', sans-serif;
        }
        .input-wrap input:focus {
            outline: none;
            border-color: #2d6a4f;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(45,106,79,0.1);
        }
        .input-wrap:focus-within .input-icon { color: #2d6a4f; }
        .pw-toggle {
            position: absolute;
            right: 1rem; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: #c0c6d4; cursor: pointer;
            padding: 0; font-size: 0.85rem;
            transition: color 0.2s;
        }
        .pw-toggle:hover { color: #2d6a4f; }
        .btn-login {
            width: 100%; padding: 0.9rem;
            background: linear-gradient(135deg, #2d6a4f 0%, #1b4332 100%);
            color: #fff; border: none;
            border-radius: 11px;
            font-size: 0.95rem; font-weight: 700;
            font-family: 'Outfit', sans-serif;
            cursor: pointer; margin-top: 1.5rem;
            transition: all 0.2s ease;
            box-shadow: 0 6px 20px rgba(45,106,79,0.3);
            display: flex; align-items: center;
            justify-content: center; gap: 0.5rem;
        }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(45,106,79,0.4); }
        .btn-login:active { transform: scale(0.98); }
        .login-alert-error {
            display: flex; align-items: center; gap: 0.6rem;
            padding: 0.8rem 1rem;
            background: #ffebee; color: #c62828;
            border: 1px solid rgba(198,40,40,0.2);
            border-radius: 10px; font-size: 0.85rem;
            margin-bottom: 1.25rem;
            animation: shake 0.4s ease;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25%       { transform: translateX(-5px); }
            75%       { transform: translateX(5px); }
        }
        .login-hint {
            margin-top: 1.5rem; padding: 0.9rem 1rem;
            background: #fafbfc; border: 1px solid #e5e9f0;
            border-radius: 10px; font-size: 0.8rem;
            color: #8a909e; text-align: center; line-height: 1.6;
        }
        .login-hint code {
            background: #f1f3f4; padding: 0.1rem 0.4rem;
            border-radius: 4px; color: #4a5060; font-size: 0.78rem;
        }
        @media (max-width: 768px) {
            .login-left { display: none; }
            .login-right { width: 100%; }
        }
    </style>
</head>
<body>

<div class="login-left">
    <div class="login-brand">
        <div class="login-brand-logo-container">
            <img src="assets/images/palmas-logo.png" alt="Palma's Elite Gym Logo" class="login-brand-logo">
        </div>
        <h1><?php echo htmlspecialchars($app_settings['gym_name'] ?? 'GYM PRO'); ?></h1>
        <p>Your all-in-one gym membership and attendance management platform.</p>
        <div class="login-features">
            <div class="login-feature">
                <i class="fas fa-users"></i>
                <span>Manage members and subscriptions</span>
            </div>
            <div class="login-feature">
                <i class="fas fa-qrcode"></i>
                <span>QR-based attendance tracking</span>
            </div>
            <div class="login-feature">
                <i class="fas fa-chart-line"></i>
                <span>Revenue reports and analytics</span>
            </div>
            <div class="login-feature">
                <i class="fas fa-bell"></i>
                <span>Renewal and expiry notifications</span>
            </div>
        </div>
    </div>
</div>

<div class="login-right">
    <div class="login-form-wrap">
        <h2>Welcome back 👋</h2>
        <p class="subtitle">Sign in to access your management dashboard.</p>

        <?php if ($error): ?>
        <div class="login-alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="" class="login-form needs-validation" novalidate>
            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-wrap">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" id="email" name="email"
                        placeholder="you@gym.com"
                        value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                        autocomplete="email" required>
                </div>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="password" name="password"
                        placeholder="••••••••"
                        autocomplete="current-password" required>
                    <button type="button" class="pw-toggle" id="togglePw" title="Show/hide password">
                        <i class="fas fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-login">
                <i class="fas fa-arrow-right-to-bracket"></i> Sign In
            </button>
        </form>
    </div>
</div>

<script>
    const togglePw = document.getElementById('togglePw');
    const pwInput  = document.getElementById('password');
    const eyeIcon  = document.getElementById('eyeIcon');
    togglePw.addEventListener('click', () => {
        const isHidden = pwInput.type === 'password';
        pwInput.type   = isHidden ? 'text' : 'password';
        eyeIcon.className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
    });
</script>
</body>
</html>
