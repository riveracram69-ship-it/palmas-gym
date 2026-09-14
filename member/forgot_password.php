<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../config/logger.php';
require_once __DIR__ . '/../config/rate_limiter.php';

$error = '';
$success = '';
$token = trim($_GET['token'] ?? '');
$step = !empty($token) ? 'reset' : 'request';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'request';

    if ($action === 'request') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid registered email address.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, membership_id, full_name, email FROM members WHERE LOWER(email) = ? LIMIT 1");
                $stmt->execute([$email]);
                $member = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($member) {
                    $otp = strval(random_int(100000, 999999));
                    $reset_token = bin2hex(random_bytes(24));

                    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        member_id INT NOT NULL,
                        email VARCHAR(191) NOT NULL,
                        otp VARCHAR(10) NOT NULL,
                        token VARCHAR(64) NOT NULL UNIQUE,
                        expires_at DATETIME NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        KEY idx_member (member_id),
                        KEY idx_token (token)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                    $pdo->prepare("DELETE FROM password_resets WHERE member_id = ? OR email = ?")->execute([$member['id'], $email]);

                    $insert = $pdo->prepare("INSERT INTO password_resets (member_id, email, otp, token, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
                    $insert->execute([$member['id'], $email, $otp, $reset_token]);

                    $base = rtrim(defined('APP_URL') ? APP_URL : 'https://palmas-gym-4oxn.onrender.com', '/');
                    $reset_url = $base . '/member/forgot_password.php?token=' . urlencode($reset_token);

                    $subject = "Password Reset Request — Palma's Elite Gym";
                    $title = "Reset Your Account Password";
                    $body = '
                    <p>Dear <strong>' . htmlspecialchars($member['full_name']) . '</strong>,</p>
                    <p>We received a request to reset your password for your Palma\'s Elite Gym account (<strong>' . htmlspecialchars($member['membership_id']) . '</strong>).</p>
                    
                    <div style="background-color:#F4F9F6; border:1px solid #D8E6DC; border-radius:10px; padding:18px; margin:20px 0; text-align:center;">
                        <p style="margin:0 0 6px; font-size:12px; color:#2D6A4F; font-weight:bold; letter-spacing:1px; text-transform:uppercase;">Your 6-Digit Verification Code</p>
                        <span style="font-size:28px; font-weight:800; letter-spacing:6px; color:#1B4332; font-family:monospace;">' . $otp . '</span>
                        <p style="margin:8px 0 0; font-size:11px; color:#64748B;">This code is valid for 1 hour.</p>
                    </div>

                    <p><a href="' . $reset_url . '" style="display:inline-block; padding:10px 20px; background:#1B4332; color:#fff; text-decoration:none; border-radius:8px; font-weight:bold;">Click Here to Reset Password</a></p>
                    <p style="font-size:12px; color:#94A3B8; margin-top:20px;">If you did not make this request, you can safely ignore this email.</p>
                    ';

                    @send_email_notification($email, $subject, $title, $body);
                    log_activity($pdo, 'Password Reset Requested', "Password reset requested for member {$member['full_name']} ({$member['membership_id']})", 'Auth', $member['id'], $member['full_name']);
                }

                $success = 'If that email address is in our system, password reset instructions have been sent! Please check your email inbox and spam folder.';
            } catch (Throwable $e) {
                $error = 'Unable to process password reset. Please try again or visit the gym front desk.';
            }
        }
    } elseif ($action === 'reset_with_token') {
        $r_token  = trim($_POST['token'] ?? '');
        $new_pass = trim($_POST['password'] ?? '');
        $cfm_pass = trim($_POST['confirm_password'] ?? '');

        if (empty($new_pass) || strlen($new_pass) < 6) {
            $error = 'Password must be at least 6 characters long.';
            $step = 'reset';
            $token = $r_token;
        } elseif ($new_pass !== $cfm_pass) {
            $error = 'Passwords do not match.';
            $step = 'reset';
            $token = $r_token;
        } else {
            try {
                $stmt = $pdo->prepare("SELECT member_id FROM password_resets WHERE token = ? AND expires_at > NOW() LIMIT 1");
                $stmt->execute([$r_token]);
                $reset_row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$reset_row) {
                    $error = 'Invalid or expired password reset link. Please request a new one.';
                    $step = 'request';
                } else {
                    $member_id = $reset_row['member_id'];
                    $password_hash = password_hash($new_pass, PASSWORD_DEFAULT);

                    $pdo->prepare("UPDATE members SET password_hash = ? WHERE id = ?")->execute([$password_hash, $member_id]);
                    $pdo->prepare("DELETE FROM password_resets WHERE member_id = ?")->execute([$member_id]);

                    log_activity($pdo, 'Password Reset Completed', "Member ID {$member_id} successfully reset their password.", 'Auth', $member_id);

                    $success = 'Your password has been successfully updated! You can now log in with your new password.';
                    $step = 'done';
                }
            } catch (Throwable $e) {
                $error = 'An error occurred while updating your password. Please try again.';
                $step = 'reset';
                $token = $r_token;
            }
        }
    }
}

$gym_name = htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Forgot Password | <?php echo $gym_name; ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --c-bg:#F4F7F5;--c-card:#FFFFFF;--c-input:#FAFDFA;--c-border:#DCE5DD;--c-border-f:#3E8241;
  --c-p:#3E8241;--c-p-mid:#2D6A4F;--c-p-lt:#52B788;--c-p-pale:#EDF4EE;
  --c-h:#121A14;--c-body:#334337;--c-muted:#617567;--c-faint:#91A397;
  --r-card:20px;--r-input:12px;--r-btn:12px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{
  min-height:100vh;background:var(--c-bg);font-family:'Inter',sans-serif;color:var(--c-body);
  display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px 16px;
}
.card{
  background:var(--c-card);border:1px solid var(--c-border);border-radius:var(--r-card);
  padding:36px 30px;width:100%;max-width:440px;box-shadow:0 8px 30px rgba(27,67,50,0.06);
}
.brand{text-align:center;margin-bottom:24px;}
.brand img{width:64px;height:64px;border-radius:50%;object-fit:contain;margin-bottom:12px;}
.brand h1{font-family:'Outfit',sans-serif;font-size:1.35rem;font-weight:800;color:var(--c-h);letter-spacing:0.5px;}
.brand p{font-size:0.85rem;color:var(--c-muted);margin-top:4px;}

.fg{margin-bottom:18px;}
.lbl{display:block;font-size:0.85rem;font-weight:600;color:var(--c-h);margin-bottom:6px;}
.if{
  width:100%;height:48px;background:var(--c-input);border:1.5px solid var(--c-border);
  border-radius:var(--r-input);padding:0 14px;color:var(--c-h);font-size:0.95rem;outline:none;
}
.if:focus{border-color:var(--c-border-f);box-shadow:0 0 0 3px rgba(62,130,65,0.18);}

.btn-primary{
  width:100%;height:50px;background:linear-gradient(135deg,var(--c-p-lt) 0%,var(--c-p) 100%);
  border:none;border-radius:var(--r-btn);color:#fff;font-family:'Outfit',sans-serif;
  font-size:1rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;
  gap:8px;box-shadow:0 4px 14px rgba(45,106,79,0.3);transition:all 0.2s;
}
.btn-primary:hover{opacity:0.95;transform:translateY(-1px);}

.alert{padding:12px 16px;border-radius:10px;font-size:0.875rem;margin-bottom:18px;display:flex;align-items:center;gap:10px;}
.alert-err{background:#FEE2E2;border:1px solid #FECACA;color:#DC2626;}
.alert-ok{background:#DCFCE7;border:1px solid #BBF7D0;color:#15803D;}

.back-link{
  display:inline-flex;align-items:center;gap:6px;color:var(--c-p-mid);
  text-decoration:none;font-size:0.85rem;font-weight:600;margin-top:20px;
}
.back-link:hover{text-decoration:underline;}
</style>
</head>
<body>

<div class="card">
  <div class="brand">
    <img src="../assets/images/palmas-logo.png" alt="Logo">
    <h1>Reset Password</h1>
    <p>Palma's Elite Gym Member Portal</p>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-err"><i class="fa-solid fa-circle-exclamation"></i> <div><?php echo htmlspecialchars($error); ?></div></div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i> <div><?php echo htmlspecialchars($success); ?></div></div>
  <?php endif; ?>

  <?php if ($step === 'request' && empty($success)): ?>
    <form method="POST">
      <input type="hidden" name="action" value="request">
      <div class="fg">
        <label class="lbl" for="email">Enter Registered Email</label>
        <input type="email" name="email" id="email" class="if" placeholder="e.g. yourname@example.com" required autofocus>
      </div>
      <button type="submit" class="btn-primary"><i class="fa-solid fa-paper-plane"></i> Send Password Reset Link</button>
    </form>
  <?php elseif ($step === 'reset'): ?>
    <form method="POST">
      <input type="hidden" name="action" value="reset_with_token">
      <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
      <div class="fg">
        <label class="lbl" for="password">New Password</label>
        <input type="password" name="password" id="password" class="if" placeholder="At least 6 characters" required autofocus>
      </div>
      <div class="fg">
        <label class="lbl" for="confirm_password">Confirm New Password</label>
        <input type="password" name="confirm_password" id="confirm_password" class="if" placeholder="Re-enter new password" required>
      </div>
      <button type="submit" class="btn-primary"><i class="fa-solid fa-check"></i> Update Password</button>
    </form>
  <?php endif; ?>

  <div style="text-align:center;">
    <a href="login.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Sign In</a>
  </div>
</div>

</body>
</html>
