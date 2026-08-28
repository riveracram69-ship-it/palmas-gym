<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/logger.php';

if (isset($_SESSION['member_id'])) {
    header('Location: index.php');
    exit;
}

// Fetch active plans for selection
$plans = [];
try {
    if (isset($pdo) && $pdo) {
        $stmt = $pdo->query("SELECT id, name, price, duration_months, benefits FROM membership_plans ORDER BY price ASC");
        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

$error   = '';
$success = '';
$new_membership_id = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name        = trim($_POST['full_name'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $contact_number   = trim($_POST['contact_number'] ?? '');
    $gender           = trim($_POST['gender'] ?? 'Other');
    $plan_id          = intval($_POST['plan_id'] ?? 0);
    $password         = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    $validation_errors = [];

    if (empty($full_name)) {
        $validation_errors[] = "Full Name is required.";
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $validation_errors[] = "Please provide a valid email address.";
    }

    if (!empty($contact_number) && !preg_match('/^09[0-9]{9}$/', $contact_number)) {
        $validation_errors[] = "Contact number must be 11 digits starting with 09 (e.g. 09123456789).";
    }

    if (empty($password) || strlen($password) < 6) {
        $validation_errors[] = "Password must be at least 6 characters long.";
    }

    if ($password !== $confirm_password) {
        $validation_errors[] = "Passwords do not match.";
    }

    // Check duplicate email
    if (empty($validation_errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM members WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $validation_errors[] = "This email is already registered. Please sign in instead.";
            }

            if (!empty($contact_number)) {
                $stmt = $pdo->prepare("SELECT id FROM members WHERE contact_number = ? LIMIT 1");
                $stmt->execute([$contact_number]);
                if ($stmt->fetch()) {
                    $validation_errors[] = "This contact number is already registered.";
                }
            }
        } catch (Exception $e) {
            $validation_errors[] = "Database validation error: " . $e->getMessage();
        }
    }

    if (empty($validation_errors)) {
        try {
            $pdo->beginTransaction();

            $membership_id = 'GYM-' . strtoupper(substr(uniqid(), -6));
            $check_id = $pdo->prepare("SELECT id FROM members WHERE membership_id = ?");
            $check_id->execute([$membership_id]);
            while ($check_id->fetch()) {
                $membership_id = 'GYM-' . strtoupper(substr(uniqid(), -6));
                $check_id->execute([$membership_id]);
            }

            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO members (membership_id, full_name, email, contact_number, gender, photo, status, password_hash, created_at)
                VALUES (?, ?, ?, ?, ?, NULL, 'Active', ?, NOW())
            ");
            $stmt->execute([$membership_id, $full_name, $email, $contact_number, $gender, $password_hash]);
            $member_id = (int)$pdo->lastInsertId();

            if ($plan_id <= 0 && !empty($plans)) {
                $plan_id = (int)$plans[0]['id'];
            }

            if ($plan_id > 0) {
                $plan_stmt = $pdo->prepare("SELECT duration_months FROM membership_plans WHERE id = ?");
                $plan_stmt->execute([$plan_id]);
                $selected_plan = $plan_stmt->fetch();
                $duration = $selected_plan ? (int)$selected_plan['duration_months'] : 1;

                $start_date = date('Y-m-d');
                $expiry_date = date('Y-m-d', strtotime("+{$duration} months"));

                $sub_stmt = $pdo->prepare("
                    INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date)
                    VALUES (?, ?, ?, ?)
                ");
                $sub_stmt->execute([$member_id, $plan_id, $start_date, $expiry_date]);
            }

            $pdo->commit();

            // Log activity
            log_activity($pdo, 'Member Registration', "Web self-registered member: {$full_name} ({$membership_id})", 'Member');

            // Email Notification
            try {
                require_once __DIR__ . '/../config/email.php';
                $email_subject = "Welcome to Palma's Elite Gym!";
                $email_title = "Welcome, {$full_name}!";
                $email_body = "Your membership account has been created successfully! Your Membership ID is: <strong>{$membership_id}</strong>. You can use this ID or your email along with your password to log in to the Member Portal and Mobile App.";
                send_email_notification($email, $email_subject, $email_title, $email_body);
            } catch (Exception $emErr) {}

            // Set session and redirect or show success
            $_SESSION['member_id']   = $member_id;
            $_SESSION['member_name'] = $full_name;
            $new_membership_id       = $membership_id;
            $success                 = "Account successfully created! Welcome to Palma's Elite Gym.";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Registration Error in register.php: ' . $e->getMessage());
            $error = "An error occurred while creating your account. Please try again.";
        }
    } else {
        $error = implode('<br>', $validation_errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Create Account | <?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></title>
    <meta name="description" content="Register for an exclusive membership account at <?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?>">
    <link rel="stylesheet" href="../assets/css/member.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            background: radial-gradient(ellipse at top, rgba(27,67,50,0.3) 0%, var(--bg-primary) 60%);
            min-height: 100vh;
            padding: 1.5rem 0 3rem 0;
        }

        .register-container {
            max-width: 480px;
            margin: 0 auto;
            padding: 0 1.25rem;
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

        .form-row {
            display: flex;
            gap: 12px;
        }

        .form-row > div {
            flex: 1;
        }

        .success-box {
            background: rgba(45, 106, 79, 0.35);
            border: 2px solid #52b788;
            border-radius: 16px;
            padding: 24px 20px;
            text-align: center;
            margin-bottom: 20px;
            box-shadow: 0 8px 30px rgba(82, 183, 136, 0.2);
        }

        .id-badge-display {
            display: inline-block;
            background: #1b4332;
            color: #74c69d;
            font-family: 'Courier New', monospace;
            font-size: 1.4rem;
            font-weight: 800;
            padding: 8px 20px;
            border-radius: 10px;
            border: 1px dashed #52b788;
            margin: 12px 0;
            letter-spacing: 2px;
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
    </style>
    <link rel="manifest" href="manifest.json">
</head>
<body>
<div class="register-container">

    <!-- Branding -->
    <div class="login-brand fade-up" style="margin-bottom: 1.25rem;">
        <div class="login-logo-wrap" style="width:68px; height:68px;">
            <img src="../assets/images/palmas-logo.png" alt="<?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?> Logo">
        </div>
        <h1 style="font-size:1.5rem;"><?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></h1>
        <p>Member Account Registration</p>
    </div>

    <!-- Registration Card -->
    <div class="login-form-card fade-up fade-up-d1" style="padding: 1.75rem 1.5rem;">

        <?php if ($success): ?>
            <div class="success-box">
                <div style="width: 54px; height: 54px; background: #52b788; color: #081c15; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px auto; font-size: 24px;">
                    <i class="fas fa-check"></i>
                </div>
                <h3 style="color: #fff; margin: 0 0 6px 0; font-size: 1.25rem;">Welcome, <?php echo htmlspecialchars($_SESSION['member_name']); ?>!</h3>
                <p style="color: #b7e4c7; font-size: 0.85rem; margin: 0;">Your membership account has been registered.</p>
                
                <div style="margin-top: 14px;">
                    <span style="font-size: 0.75rem; color: #95d5b2; text-transform: uppercase; letter-spacing: 1px;">Your Membership ID</span><br>
                    <div class="id-badge-display"><?php echo htmlspecialchars($new_membership_id); ?></div>
                </div>
                
                <p style="color: var(--text-muted); font-size: 0.75rem; margin-top: 4px;">
                    Save this ID. You can use it or your email to sign in on both Web and Mobile App.
                </p>

                <div style="margin-top: 20px; display: flex; flex-direction: column; gap: 10px;">
                    <a href="index.php" class="btn" style="background: #2d6a4f; color: #fff; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <i class="fas fa-gauge"></i> Go to Member Dashboard
                    </a>
                    <a href="id-card.php" class="btn" style="background: transparent; border: 1px solid #52b788; color: #74c69d; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <i class="fas fa-qrcode"></i> View Digital QR Pass
                    </a>
                </div>
            </div>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="error-banner" style="margin-bottom: 1.25rem;">
                    <i class="fas fa-triangle-exclamation"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <form action="register.php" method="POST" id="register-form" class="needs-validation" novalidate>

                <div class="form-group">
                    <label for="full_name"><i class="fas fa-user"></i> Full Name *</label>
                    <div class="input-wrap">
                        <i class="fas fa-id-card input-icon"></i>
                        <input type="text" name="full_name" id="full_name" class="form-control" placeholder="e.g. Juan Dela Cruz" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> Email Address *</label>
                    <div class="input-wrap">
                        <i class="fas fa-at input-icon"></i>
                        <input type="email" name="email" id="email" class="form-control" placeholder="e.g. juan@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="contact_number"><i class="fas fa-phone"></i> Contact (09...)</label>
                        <div class="input-wrap">
                            <i class="fas fa-mobile-screen input-icon"></i>
                            <input type="tel" name="contact_number" id="contact_number" class="form-control" placeholder="09123456789" maxlength="11" value="<?php echo htmlspecialchars($_POST['contact_number'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="gender"><i class="fas fa-venus-mars"></i> Gender</label>
                        <div class="input-wrap">
                            <i class="fas fa-user-tag input-icon"></i>
                            <select name="gender" id="gender" class="form-control" style="padding-left: 2.5rem; background:#081c15; color:#fff;">
                                <option value="Male" <?php echo (($_POST['gender'] ?? '') === 'Male') ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo (($_POST['gender'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
                                <option value="Other" <?php echo (($_POST['gender'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>
                </div>

                <?php if (!empty($plans)): ?>
                <div class="form-group">
                    <label for="plan_id"><i class="fas fa-tag"></i> Membership Plan</label>
                    <div class="input-wrap">
                        <i class="fas fa-gem input-icon"></i>
                        <select name="plan_id" id="plan_id" class="form-control" style="padding-left: 2.5rem; background:#081c15; color:#fff;">
                            <?php foreach ($plans as $p): ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo (($_POST['plan_id'] ?? '') == $p['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['name']); ?> — ₱<?php echo number_format($p['price'], 2); ?> (<?php echo $p['duration_months']; ?> mo.)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label for="password"><i class="fas fa-lock"></i> Password *</label>
                        <div class="input-wrap">
                            <i class="fas fa-key input-icon"></i>
                            <input type="password" name="password" id="password" class="form-control" placeholder="Min. 6 chars" required minlength="6">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password"><i class="fas fa-shield"></i> Confirm *</label>
                        <div class="input-wrap">
                            <i class="fas fa-check-double input-icon"></i>
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Repeat password" required minlength="6">
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn" id="submit-btn" style="margin-top: 1rem;">
                    <i class="fas fa-user-plus"></i> Create Account
                </button>

                <div class="secure-note">
                    <i class="fas fa-shield-halved"></i>
                    Secured with encrypted password protection
                </div>
            </form>
        <?php endif; ?>

        <div style="margin-top: 1.5rem; text-align: center; border-top: 1px solid var(--border); padding-top: 1rem;">
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 8px;">
                Already have an account?
            </p>
            <a href="login.php" style="display: inline-flex; align-items: center; gap: 6px; color: #74c69d; font-weight: 700; text-decoration: none; font-size: 0.9rem;">
                <i class="fas fa-right-to-bracket"></i> Sign In to Member Portal
            </a>
        </div>
    </div>

    <!-- Download App Banner -->
    <div class="login-footer fade-up fade-up-d2" style="margin-top: 1.5rem; text-align: center;">
        <a href="../download.php" style="display:inline-flex; align-items:center; gap:8px; background:rgba(82, 183, 136, 0.15); border:1px solid #52b788; color:#74c69d; padding:8px 16px; border-radius:20px; text-decoration:none; font-size:0.85rem; font-weight:600;">
            <i class="fab fa-android" style="font-size:1.1rem;"></i> Download Member Mobile App (.APK)
        </a>
    </div>

</div>

<script>
document.getElementById('register-form')?.addEventListener('submit', function(e) {
    const p1 = document.getElementById('password').value;
    const p2 = document.getElementById('confirm_password').value;
    if (p1 !== p2) {
        e.preventDefault();
        alert('Passwords do not match. Please re-enter your password.');
        return;
    }
    const btn = document.getElementById('submit-btn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Account...';
    btn.disabled = true;
});
</script>
</body>
</html>
