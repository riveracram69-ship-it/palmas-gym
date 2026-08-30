<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/rate_limiter.php';

if (isset($_SESSION['member_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $membership_id = trim($_POST['membership_id'] ?? '');
    $credential    = trim($_POST['credential'] ?? '');

    // Check progressive rate limit
    $rate_check = check_rate_limit($pdo, $membership_id, 'member_portal_login');
    if (!$rate_check['allowed']) {
        $error = $rate_check['message'];
    } elseif (empty($membership_id) || empty($credential)) {
        $error = "Please enter both your Membership ID / Email and Password.";
    } else {
        $clean_id = str_replace('-', '', strtoupper($membership_id));
        try {
            // Fetch member by membership_id, email, or contact number
            $stmt = $pdo->prepare("SELECT id, full_name, status, password_hash FROM members 
                                   WHERE REPLACE(UPPER(membership_id), '-', '') = ? 
                                      OR LOWER(email) = LOWER(?) 
                                      OR contact_number = ? LIMIT 1");
            $stmt->execute([$clean_id, $membership_id, $membership_id]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($member) {
                if ($member['status'] !== 'Active') {
                    $error = "Your membership is not currently active. Please speak with the front desk.";
                } else {
                    if (empty($member['password_hash'])) {
                        // First time login setup
                        $stmt_legacy = $pdo->prepare("SELECT id FROM members WHERE id = ? AND (LOWER(email) = ? OR contact_number = ?)");
                        $stmt_legacy->execute([$member['id'], strtolower($credential), $credential]);
                        if ($stmt_legacy->fetch()) {
                            clear_rate_limit($pdo, $membership_id, 'member_portal_login');
                            $_SESSION['setup_member_id'] = $member['id'];
                            header('Location: setup_password.php');
                            exit;
                        } else {
                            $failed = record_failed_login($pdo, $membership_id, 'member_portal_login');
                            $error = $failed['lockout'] ? $failed['message'] : "Invalid verification details for first-time account setup.";
                        }
                    } else {
                        // Password verify
                        if (password_verify($credential, $member['password_hash'])) {
                            clear_rate_limit($pdo, $membership_id, 'member_portal_login');
                            session_regenerate_id(true);
                            $_SESSION['member_id']   = $member['id'];
                            $_SESSION['member_name'] = $member['full_name'];
                            header('Location: index.php');
                            exit;
                        } else {
                            $failed = record_failed_login($pdo, $membership_id, 'member_portal_login');
                            $error = $failed['lockout'] ? $failed['message'] : "Incorrect Member ID/Email or Password.";
                        }
                    }
                }
            } else {
                $failed = record_failed_login($pdo, $membership_id, 'member_portal_login');
                $error = $failed['lockout'] ? $failed['message'] : "Account not found with that Member ID or Email.";
            }
        } catch (Exception $e) {
            $error = "A system error occurred. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Member Login | <?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></title>
    <meta name="description" content="Sign in to the exclusive member portal of <?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?>">
    
    <!-- Google Fonts: Outfit (Headings) & Inter (Body) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <!-- PWA Setup -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#060e0a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Palma's Elite">
    <link rel="apple-touch-icon" href="../assets/images/palmas-logo.png">

    <style>
        :root {
            --bg-dark: #060e0a;
            --bg-card: rgba(15, 30, 22, 0.75);
            --bg-input: #09160f;
            --primary: #2d6a4f;
            --primary-light: #52b788;
            --primary-accent: #74c69d;
            --primary-glow: rgba(82, 183, 136, 0.28);
            --gold: #d4af37;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-sub: #64748b;
            --border-card: rgba(82, 183, 136, 0.18);
            --border-input: rgba(82, 183, 136, 0.22);
            --radius-card: 20px;
            --radius-btn: 14px;
            --radius-input: 12px;
            --transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.25rem;
            background-image: 
                radial-gradient(circle at 15% 10%, rgba(45, 106, 79, 0.35) 0%, transparent 45%),
                radial-gradient(circle at 85% 90%, rgba(82, 183, 136, 0.2) 0%, transparent 50%),
                linear-gradient(180deg, #060e0a 0%, #030705 100%);
            background-attachment: fixed;
        }

        .auth-wrapper {
            width: 100%;
            max-width: 440px;
            margin: 0 auto;
        }

        /* ── BRANDING HEADER ── */
        .brand-header {
            text-align: center;
            margin-bottom: 1.75rem;
        }

        .brand-logo-wrap {
            width: 76px;
            height: 76px;
            margin: 0 auto 0.85rem auto;
            border-radius: 20px;
            background: linear-gradient(135deg, #1b4332 0%, #0d281e 100%);
            border: 1px solid rgba(82, 183, 136, 0.35);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 25px rgba(45, 106, 79, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.15);
            overflow: hidden;
            padding: 8px;
        }

        .brand-logo-wrap img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .brand-header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.65rem;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.5px;
            margin-bottom: 0.25rem;
        }

        .brand-header p {
            font-size: 0.9rem;
            color: var(--primary-accent);
            font-weight: 500;
            letter-spacing: 0.3px;
        }

        /* ── GLASS CARD ── */
        .auth-card {
            background: var(--bg-card);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid var(--border-card);
            border-radius: var(--radius-card);
            padding: 2.25rem 1.85rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.05);
        }

        /* ── ALERTS ── */
        .alert-box {
            padding: 0.95rem 1.15rem;
            border-radius: 12px;
            font-size: 0.88rem;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 1.5rem;
            line-height: 1.45;
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .alert-danger i {
            color: #ef4444;
            font-size: 1.1rem;
            margin-top: 2px;
        }

        /* ── FORM ELEMENTS ── */
        .form-group {
            margin-bottom: 1.35rem;
        }

        .form-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.82rem;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 0.45rem;
            letter-spacing: 0.3px;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 1.1rem;
            color: var(--text-sub);
            font-size: 0.95rem;
            pointer-events: none;
            transition: var(--transition);
        }

        .input-field {
            width: 100%;
            height: 50px;
            background: var(--bg-input);
            border: 1.5px solid var(--border-input);
            border-radius: var(--radius-input);
            padding: 0 1.1rem 0 2.85rem;
            color: #ffffff;
            font-family: 'Inter', sans-serif;
            font-size: 0.94rem;
            transition: var(--transition);
            outline: none;
        }

        .input-field:focus {
            border-color: var(--primary-light);
            background: #0d1e16;
            box-shadow: 0 0 0 4px var(--primary-glow);
        }

        .input-field:focus + .input-icon,
        .input-field:focus ~ .input-icon {
            color: var(--primary-light);
        }

        .input-field::placeholder {
            color: #475569;
            font-size: 0.88rem;
        }

        .pw-toggle {
            position: absolute;
            right: 1.1rem;
            background: none;
            border: none;
            color: var(--text-sub);
            font-size: 0.95rem;
            cursor: pointer;
            padding: 4px;
            transition: var(--transition);
        }

        .pw-toggle:hover {
            color: var(--primary-light);
        }

        /* ── HELPER TIPS ── */
        .helper-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 0.45rem;
            font-size: 0.76rem;
        }

        .helper-text {
            color: var(--text-sub);
        }

        .forgot-link {
            color: var(--primary-accent);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
        }

        .forgot-link:hover {
            color: #ffffff;
            text-decoration: underline;
        }

        .first-time-box {
            background: rgba(82, 183, 136, 0.08);
            border: 1px dashed rgba(82, 183, 136, 0.25);
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 0.78rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 0.5rem;
        }

        .first-time-box i {
            color: var(--primary-light);
            font-size: 0.85rem;
        }

        /* ── PRIMARY CTA ── */
        .btn-primary-glow {
            width: 100%;
            height: 52px;
            min-height: 48px;
            background: linear-gradient(135deg, #2d6a4f 0%, #1b4332 100%);
            border: 1px solid rgba(82, 183, 136, 0.4);
            border-radius: var(--radius-btn);
            color: #ffffff;
            font-family: 'Outfit', sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
            letter-spacing: 0.4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 1.5rem;
            box-shadow: 0 8px 24px rgba(45, 106, 79, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.15);
            transition: var(--transition);
        }

        .btn-primary-glow:hover {
            background: linear-gradient(135deg, #40916c 0%, #2d6a4f 100%);
            box-shadow: 0 12px 30px rgba(82, 183, 136, 0.45);
            transform: translateY(-1px);
        }

        .btn-primary-glow:active {
            transform: translateY(1px);
        }

        /* ── TRUST BADGE ── */
        .trust-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: 0.72rem;
            color: #64748b;
            margin-top: 1rem;
            letter-spacing: 0.3px;
        }

        .trust-badge i {
            color: var(--primary-accent);
            font-size: 0.8rem;
        }

        /* ── DIVIDER & TERTIARY LINKS ── */
        .auth-divider {
            display: flex;
            align-items: center;
            margin: 1.75rem 0 1.25rem 0;
            color: #475569;
            font-size: 0.78rem;
        }

        .auth-divider::before,
        .auth-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(255, 255, 255, 0.08);
        }

        .auth-divider span {
            padding: 0 12px;
        }

        .create-acc-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            height: 46px;
            background: rgba(82, 183, 136, 0.08);
            border: 1.5px solid rgba(82, 183, 136, 0.3);
            border-radius: var(--radius-btn);
            color: var(--primary-light);
            font-size: 0.9rem;
            font-weight: 700;
            text-decoration: none;
            transition: var(--transition);
        }

        .create-acc-btn:hover {
            background: rgba(82, 183, 136, 0.18);
            border-color: var(--primary-light);
            color: #ffffff;
        }

        /* ── SECONDARY CTA: APK DOWNLOAD BANNER ── */
        .apk-download-card {
            margin-top: 1.5rem;
            background: rgba(15, 30, 22, 0.6);
            border: 1px solid rgba(82, 183, 136, 0.25);
            border-radius: 16px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-decoration: none;
            transition: var(--transition);
        }

        .apk-download-card:hover {
            background: rgba(45, 106, 79, 0.25);
            border-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(45, 106, 79, 0.25);
        }

        .apk-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .apk-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: rgba(82, 183, 136, 0.15);
            border: 1px solid rgba(82, 183, 136, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-light);
            font-size: 1.25rem;
        }

        .apk-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: #ffffff;
        }

        .apk-subtitle {
            font-size: 0.74rem;
            color: var(--text-muted);
        }

        .apk-badge {
            background: #2d6a4f;
            color: #ffffff;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .help-footer {
            margin-top: 1.5rem;
            text-align: center;
            font-size: 0.78rem;
            color: #64748b;
            line-height: 1.5;
        }
    </style>
</head>
<body>

<div class="auth-wrapper">

    <!-- Brand Header -->
    <div class="brand-header">
        <div class="brand-logo-wrap">
            <img src="../assets/images/palmas-logo.png" alt="<?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?> Logo">
        </div>
        <h1><?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></h1>
        <p>Exclusive Member Portal</p>
    </div>

    <!-- Login Card -->
    <div class="auth-card">

        <?php if ($error): ?>
            <div class="alert-box alert-danger">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST" id="login-form" novalidate>

            <!-- Member ID or Email -->
            <div class="form-group">
                <label class="form-label" for="membership_id">
                    <span>Member ID or Email</span>
                </label>
                <div class="input-group">
                    <i class="fa-solid fa-id-badge input-icon"></i>
                    <input type="text" name="membership_id" id="membership_id" class="input-field" 
                           placeholder="e.g. GYM-9537F6 or your email" 
                           value="<?php echo htmlspecialchars($_POST['membership_id'] ?? ''); ?>" 
                           required autocomplete="off" autofocus>
                </div>
                <div class="helper-row">
                    <span class="helper-text">Enter your Membership ID or registered email</span>
                </div>
            </div>

            <!-- Password -->
            <div class="form-group">
                <label class="form-label" for="credential">
                    <span>Password</span>
                    <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
                </label>
                <div class="input-group">
                    <i class="fa-solid fa-lock input-icon"></i>
                    <input type="password" name="credential" id="credential" class="input-field" 
                           placeholder="Enter your password" 
                           required autocomplete="off">
                    <button type="button" class="pw-toggle" onclick="togglePassword('credential', this)" aria-label="Toggle Password Visibility">
                        <i class="fa-regular fa-eye"></i>
                    </button>
                </div>

                <div class="first-time-box">
                    <i class="fa-solid fa-circle-info"></i>
                    <span><b>First-time login?</b> Enter your registered email to set up your password.</span>
                </div>
            </div>

            <!-- Primary CTA Button -->
            <button type="submit" class="btn-primary-glow" id="submit-btn">
                <i class="fa-solid fa-right-to-bracket"></i> Access Portal
            </button>

            <!-- Trust Microcopy -->
            <div class="trust-badge">
                <i class="fa-solid fa-lock"></i> 256-bit SSL Encrypted Connection
            </div>
        </form>

        <!-- Tertiary Links -->
        <div class="auth-divider">
            <span>DON'T HAVE AN ACCOUNT?</span>
        </div>

        <a href="register.php" class="create-acc-btn">
            <i class="fa-solid fa-user-plus"></i> Create an Account
        </a>
    </div>

    <!-- Secondary CTA: Mobile APK Download Banner -->
    <a href="../download.php" class="apk-download-card">
        <div class="apk-left">
            <div class="apk-icon-box">
                <i class="fa-brands fa-android"></i>
            </div>
            <div>
                <div class="apk-title">Download Member App</div>
                <div class="apk-subtitle">Android APK &bull; Digital QR Pass</div>
            </div>
        </div>
        <div class="apk-badge">
            <i class="fa-solid fa-download"></i> Get App
        </div>
    </a>

    <div class="help-footer">
        Need assistance? Contact the gym front desk to retrieve your Membership ID.
    </div>

</div>

<!-- Interactive JS -->
<script>
function togglePassword(inputId, toggleBtn) {
    const input = document.getElementById(inputId);
    const icon = toggleBtn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

document.getElementById('login-form')?.addEventListener('submit', function() {
    const btn = document.getElementById('submit-btn');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying...';
    btn.disabled = true;
});

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js');
    });
}
</script>

</body>
</html>
