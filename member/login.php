<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/rate_limiter.php';

if (isset($_SESSION['member_id'])) {
    header('Location: /gym/member/index.php');
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
        $error = "Please fill in all fields.";
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
                    $error = "Your membership is not active. Please renew at the desk.";
                } else {
                    if (empty($member['password_hash'])) {
                        // First time login setup
                        $stmt_legacy = $pdo->prepare("SELECT id FROM members WHERE id = ? AND (LOWER(email) = ? OR contact_number = ?)");
                        $stmt_legacy->execute([$member['id'], strtolower($credential), $credential]);
                        if ($stmt_legacy->fetch()) {
                            clear_rate_limit($pdo, $membership_id, 'member_portal_login');
                            $_SESSION['setup_member_id'] = $member['id'];
                            header('Location: /gym/member/setup_password.php');
                            exit;
                        } else {
                            $failed = record_failed_login($pdo, $membership_id, 'member_portal_login');
                            $error = $failed['lockout'] ? $failed['message'] : "Invalid Email or Contact number for first-time setup.";
                        }
                    } else {
                        // Password verify
                        if (password_verify($credential, $member['password_hash'])) {
                            clear_rate_limit($pdo, $membership_id, 'member_portal_login');
                            session_regenerate_id(true);
                            $_SESSION['member_id']   = $member['id'];
                            $_SESSION['member_name'] = $member['full_name'];
                            header('Location: /gym/member/index.php');
                            exit;
                        } else {
                            $failed = record_failed_login($pdo, $membership_id, 'member_portal_login');
                            $error = $failed['lockout'] ? $failed['message'] : "Invalid Member ID/Email or Password.";
                        }
                    }
                }
            } else {
                $failed = record_failed_login($pdo, $membership_id, 'member_portal_login');
                $error = $failed['lockout'] ? $failed['message'] : "Invalid Member ID/Email or Password.";
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
    <link rel="stylesheet" href="/gym/assets/css/member.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* Extra login-specific polish */
        body {
            background: radial-gradient(ellipse at top, rgba(27,67,50,0.25) 0%, var(--bg-primary) 50%);
            min-height: 100vh;
        }

        .secure-note {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            font-size: 0.68rem;
            color: var(--text-muted);
            margin-top: 0.75rem;
        }

        .secure-note i { color: var(--accent-light); font-size: 0.65rem; }

        .divider {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 0.25rem 0 1rem;
        }

        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .divider span {
            font-size: 0.65rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .input-wrap {
            position: relative;
        }

        .input-wrap .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        .input-wrap .form-control {
            padding-left: 2.5rem;
        }

        .help-text {
            font-size: 0.68rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
    </style>
    <link rel="manifest" href="/gym/member/manifest.json">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Palma's Elite">
    <link rel="apple-touch-icon" href="/gym/assets/images/palmas-logo.png">
</head>
<body>
<div class="mobile-container" style="padding-bottom:0;">
    <div class="login-screen">

        <!-- Background glow -->
        <div class="login-bg-glow"></div>

        <!-- Branding -->
        <div class="login-brand fade-up">
            <div class="login-logo-wrap">
                <img src="/gym/assets/images/palmas-logo.png" alt="<?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?> Logo">
            </div>
            <h1><?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></h1>
            <p>Exclusive Member Portal</p>
        </div>

        <!-- Login Form Card -->
        <div class="login-form-card fade-up fade-up-d1">

            <?php if ($error): ?>
                <div class="error-banner">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" id="login-form" class="needs-validation" novalidate>

                <div class="form-group">
                    <label for="membership_id">
                        <i class="fas fa-id-badge"></i> Member ID or Email
                    </label>
                    <div class="input-wrap">
                        <i class="fas fa-hashtag input-icon"></i>
                        <input type="text" name="membership_id" id="membership_id"
                               class="form-control"
                               placeholder="e.g. GYM-9537F6 or your email"
                               value="<?php echo htmlspecialchars($_POST['membership_id'] ?? ''); ?>"
                               required autocomplete="off" autofocus>
                    </div>
                    <p class="help-text">Enter your Membership ID or registered email.</p>
                </div>

                <div class="form-group">
                    <label for="credential">
                        <i class="fas fa-lock"></i> Password
                    </label>
                    <div class="input-wrap">
                        <i class="fas fa-key input-icon"></i>
                        <input type="password" name="credential" id="credential"
                               class="form-control"
                               placeholder="Enter your password"
                               required autocomplete="off">
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-top:0.25rem;">
                        <p class="help-text" style="margin-top:0; flex:1;">First time login? Enter your registered email to setup password.</p>
                        <a href="forgot_password.php" style="font-size:0.75rem; color:var(--accent); text-decoration:none; font-weight:600; white-space:nowrap; margin-left:10px;">Forgot Password?</a>
                    </div>
                </div>

                <button type="submit" class="btn" id="submit-btn">
                    <i class="fas fa-right-to-bracket"></i> Access Portal
                </button>

                <div class="secure-note">
                    <i class="fas fa-shield-halved"></i>
                    Secured & encrypted connection
                </div>
            </form>

            <div style="margin-top: 1.5rem; text-align: center; border-top: 1px solid var(--border); padding-top: 1rem;">
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 8px;">
                    Don't have a gym membership account yet?
                </p>
                <a href="register.php" style="display: inline-flex; align-items: center; gap: 6px; color: #74c69d; font-weight: 700; text-decoration: none; font-size: 0.9rem; background: rgba(82, 183, 136, 0.15); border: 1px solid #52b788; padding: 8px 18px; border-radius: 20px;">
                    <i class="fas fa-user-plus"></i> Create an Account
                </a>
            </div>
        </div>

        <!-- Footer & App Download -->
        <div class="login-footer fade-up fade-up-d2">
            <div style="margin-bottom: 18px;">
                <a href="../download.php" style="display:inline-flex; align-items:center; gap:8px; background:rgba(82, 183, 136, 0.15); border:1px solid #52b788; color:#74c69d; padding:8px 16px; border-radius:20px; text-decoration:none; font-size:0.85rem; font-weight:600; transition:0.2s;">
                    <i class="fab fa-android" style="font-size:1.1rem;"></i> Download Member Mobile App (.APK)
                </a>
            </div>
            <p>Need help? Contact the gym front desk</p>
            <p>to retrieve your Membership ID.</p>
        </div>

    </div>
</div>

<script>
document.getElementById('login-form').addEventListener('submit', function() {
    const btn = document.getElementById('submit-btn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
    btn.disabled = true;
});
</script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/gym/member/sw.js');
    });
}
</script>
</body>
</html>
