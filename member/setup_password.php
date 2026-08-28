<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';

if (!isset($_SESSION['setup_member_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($password) || empty($confirm_password)) {
        $error = "Please fill in all fields.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE members SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $_SESSION['setup_member_id']]);
            
            // Log them in automatically
            $stmt2 = $pdo->prepare("SELECT id, full_name FROM members WHERE id = ?");
            $stmt2->execute([$_SESSION['setup_member_id']]);
            $member = $stmt2->fetch(PDO::FETCH_ASSOC);
            
            session_regenerate_id(true);
            $_SESSION['member_id']   = $member['id'];
            $_SESSION['member_name'] = $member['full_name'];
            unset($_SESSION['setup_member_id']);
            
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            $error = "A database error occurred. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Setup Password | <?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></title>
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
        .help-text { font-size: 0.68rem; color: var(--text-muted); margin-top: 0.25rem; }
    </style>
</head>
<body>
<div class="mobile-container" style="padding-bottom:0;">
    <div class="login-screen">
        <div class="login-bg-glow"></div>
        <div class="login-brand fade-up">
            <div class="login-logo-wrap">
                <img src="../assets/images/palmas-logo.png" alt="Logo">
            </div>
            <h1>Setup Your Password</h1>
            <p>Please create a secure password for future logins.</p>
        </div>

        <div class="login-form-card fade-up fade-up-d1">
            <?php if ($error): ?>
                <div class="error-banner">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form action="setup_password.php" method="POST" id="login-form">
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
                        <i class="fas fa-key input-icon"></i>
                        <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Re-enter password" required>
                    </div>
                </div>

                <button type="submit" class="btn" id="submit-btn">
                    <i class="fas fa-save"></i> Save & Login
                </button>
            </form>
        </div>
    </div>
</div>
<script>
document.getElementById('login-form').addEventListener('submit', function() {
    const btn = document.getElementById('submit-btn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    btn.disabled = true;
});
</script>
</body>
</html>
