<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/email.php';

if (isset($_SESSION['member_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limit check
    if (isset($_SESSION['reset_lockout']) && time() < $_SESSION['reset_lockout']) {
        $wait_time = ceil(($_SESSION['reset_lockout'] - time()) / 60);
        $error = "Too many attempts. Please try again in {$wait_time} minutes.";
    } else {
        $email = trim($_POST['email'] ?? '');
        
        if (empty($email)) {
            $error = "Please enter your registered email address.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, full_name, email FROM members WHERE email = ? AND status = 'Active'");
                $stmt->execute([$email]);
                $member = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($member) {
                    $token = bin2hex(random_bytes(32));
                    $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry
                    
                    $update_stmt = $pdo->prepare("UPDATE members SET reset_token = ?, reset_expires_at = ? WHERE id = ?");
                    $update_stmt->execute([$token, $expires_at, $member['id']]);
                    
                    $reset_link = "https://" . $_SERVER['HTTP_HOST'] . "reset_password.php?token=" . $token;
                    
                    $subject = "Password Reset Request - " . ($app_settings['gym_name'] ?? "Gym");
                    $title = "Reset Your Password";
                    $body = "Hi {$member['full_name']},<br><br>We received a request to reset your password. Click the link below to set a new password. This link will expire in 1 hour.<br><br><a href='{$reset_link}' style='display:inline-block; padding:10px 20px; background-color:#2d6a4f; color:#ffffff; text-decoration:none; border-radius:5px;'>Reset Password</a><br><br>If you did not request this, please ignore this email.";
                    
                    send_email_notification($member['email'], $subject, $title, $body);
                }
                
                // Always show success message to prevent email enumeration
                $success = "If an active account exists with that email, a password reset link has been sent.";
                
                // Track attempts
                if (!isset($_SESSION['reset_attempts'])) $_SESSION['reset_attempts'] = 0;
                $_SESSION['reset_attempts']++;
                if ($_SESSION['reset_attempts'] >= 3) {
                    $_SESSION['reset_lockout'] = time() + (15 * 60); // 15 mins
                }
                
            } catch (Exception $e) {
                $error = "A system error occurred. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Forgot Password | <?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></title>
    <link rel="stylesheet" href="../assets/css/member.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            background: radial-gradient(ellipse at top, rgba(27,67,50,0.25) 0%, var(--bg-primary) 50%);
            min-height: 100vh;
        }
        .input-wrap { position: relative; }
        .input-wrap .input-icon { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.85rem; }
        .input-wrap .form-control { padding-left: 2.5rem; }
    </style>
</head>
<body>
<div class="mobile-container" style="padding-bottom:0;">
    <div class="login-screen">
        <div class="login-bg-glow"></div>
        <div class="login-brand fade-up">
            <div class="login-logo-wrap">
                <img src="../assets/images/palmas-logo.png" alt="<?php echo htmlspecialchars($app_settings['gym_name'] ?? "Gym"); ?> Logo">
            </div>
            <h1>Forgot Password</h1>
            <p>Enter your email to receive a reset link.</p>
        </div>

        <div class="login-form-card fade-up fade-up-d1">
            <?php if ($error): ?>
                <div class="error-banner"><i class="fas fa-triangle-exclamation"></i> <span><?php echo htmlspecialchars($error); ?></span></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="error-banner" style="background:rgba(82,183,136,0.15); color:var(--accent); border-color:rgba(82,183,136,0.3);"><i class="fas fa-check-circle"></i> <span><?php echo htmlspecialchars($success); ?></span></div>
            <?php else: ?>
            <form action="" method="POST" id="reset-form">
                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> Registered Email</label>
                    <div class="input-wrap">
                        <i class="fas fa-at input-icon"></i>
                        <input type="email" name="email" id="email" class="form-control" placeholder="john@example.com" required autofocus>
                    </div>
                </div>
                <button type="submit" class="btn" id="submit-btn"><i class="fas fa-paper-plane"></i> Send Reset Link</button>
            </form>
            <?php endif; ?>
            <div style="text-align:center; margin-top:1.5rem;">
                <a href="login.php" style="color:var(--text-muted); font-size:0.85rem; text-decoration:none;"><i class="fas fa-arrow-left"></i> Back to Login</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
