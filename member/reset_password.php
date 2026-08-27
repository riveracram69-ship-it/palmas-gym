<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
if (empty($token)) {
    die('Invalid or missing reset token.');
}

// Validate Token
$stmt = $pdo->prepare("SELECT id FROM members WHERE reset_token = ? AND reset_expires_at > NOW() AND status = 'Active'");
$stmt->execute([$token]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$member) {
    die('Invalid, expired, or already used reset token. Please request a new one.');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    if (empty($password) || empty($confirm)) {
        $error = "Please fill in all fields.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        $update = $pdo->prepare("UPDATE members SET password_hash = ?, reset_token = NULL, reset_expires_at = NULL WHERE id = ?");
        $update->execute([$hash, $member['id']]);
        
        $success = "Password successfully reset. You can now login with your new password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Reset Password | <?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></title>
    <link rel="stylesheet" href="/gym/assets/css/member.css?v=<?php echo time(); ?>">
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
                <img src="/gym/assets/images/palmas-logo.png" alt="<?php echo htmlspecialchars($app_settings['gym_name'] ?? "Gym"); ?> Logo">
            </div>
            <h1>Set New Password</h1>
            <p>Create a secure password for your account.</p>
        </div>

        <div class="login-form-card fade-up fade-up-d1">
            <?php if ($error): ?>
                <div class="error-banner"><i class="fas fa-triangle-exclamation"></i> <span><?php echo htmlspecialchars($error); ?></span></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="error-banner" style="background:rgba(82,183,136,0.15); color:var(--accent); border-color:rgba(82,183,136,0.3);"><i class="fas fa-check-circle"></i> <span><?php echo htmlspecialchars($success); ?></span></div>
                <div style="text-align:center; margin-top:20px;">
                    <a href="login.php" class="btn"><i class="fas fa-sign-in-alt"></i> Proceed to Login</a>
                </div>
            <?php else: ?>
                <form action="" method="POST" id="reset-form">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    
                    <div class="form-group">
                        <label for="password"><i class="fas fa-lock"></i> New Password</label>
                        <div class="input-wrap">
                            <i class="fas fa-key input-icon"></i>
                            <input type="password" name="password" id="password" class="form-control" placeholder="Min 6 characters" required autofocus>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password"><i class="fas fa-lock"></i> Confirm Password</label>
                        <div class="input-wrap">
                            <i class="fas fa-check input-icon"></i>
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Re-enter password" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn" id="submit-btn"><i class="fas fa-save"></i> Save Password</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
