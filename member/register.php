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
    $gender           = trim($_POST['gender'] ?? 'Male');
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

$selected_gender = $_POST['gender'] ?? 'Male';
$selected_plan_id = intval($_POST['plan_id'] ?? ($plans[0]['id'] ?? 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Create Account | <?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></title>
    <meta name="description" content="Register for an exclusive membership account at <?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?>">
    
    <!-- Google Fonts: Outfit (Headings) & Inter (Body) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
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
            max-width: 500px;
            margin: 0 auto;
        }

        /* ── BRANDING HEADER ── */
        .brand-header {
            text-align: center;
            margin-bottom: 1.75rem;
        }

        .brand-logo-wrap {
            width: 72px;
            height: 72px;
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
            padding: 1rem 1.15rem;
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
            margin-bottom: 1.25rem;
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

        .form-label span.required {
            color: var(--primary-light);
            margin-left: 2px;
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
            height: 48px;
            background: var(--bg-input);
            border: 1.5px solid var(--border-input);
            border-radius: var(--radius-input);
            padding: 0 1.1rem 0 2.85rem;
            color: #ffffff;
            font-family: 'Inter', sans-serif;
            font-size: 0.92rem;
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

        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        @media (max-width: 480px) {
            .form-grid-2 {
                grid-template-columns: 1fr;
            }
        }

        /* ── GENDER CHIPS ── */
        .chip-group {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }

        .chip-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 44px;
            background: var(--bg-input);
            border: 1.5px solid var(--border-input);
            border-radius: 10px;
            color: #94a3b8;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            user-select: none;
        }

        .chip-label input {
            display: none;
        }

        .chip-label:hover {
            border-color: rgba(82, 183, 136, 0.4);
            color: #ffffff;
        }

        .chip-label.active,
        .chip-label:has(input:checked) {
            background: rgba(45, 106, 79, 0.35);
            border-color: var(--primary-light);
            color: #ffffff;
            box-shadow: 0 0 12px var(--primary-glow);
        }

        /* ── PLAN SELECTION CARDS ── */
        .plan-cards-grid {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .plan-card-option {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg-input);
            border: 1.5px solid var(--border-input);
            border-radius: 12px;
            padding: 12px 16px;
            cursor: pointer;
            transition: var(--transition);
        }

        .plan-card-option input {
            display: none;
        }

        .plan-card-option:hover {
            border-color: rgba(82, 183, 136, 0.5);
            background: #0c1c14;
        }

        .plan-card-option.active,
        .plan-card-option:has(input:checked) {
            background: linear-gradient(90deg, rgba(45, 106, 79, 0.4) 0%, rgba(15, 40, 28, 0.4) 100%);
            border-color: var(--primary-light);
            box-shadow: 0 0 16px var(--primary-glow);
        }

        .plan-info-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .plan-radio-circle {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 2px solid var(--text-sub);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        .plan-card-option:has(input:checked) .plan-radio-circle {
            border-color: var(--primary-light);
            background: var(--primary-light);
            box-shadow: 0 0 8px var(--primary-light);
        }

        .plan-radio-circle::after {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #081c15;
            display: none;
        }

        .plan-card-option:has(input:checked) .plan-radio-circle::after {
            display: block;
        }

        .plan-name {
            font-size: 0.92rem;
            font-weight: 700;
            color: #ffffff;
        }

        .plan-duration {
            font-size: 0.75rem;
            color: var(--primary-accent);
        }

        .plan-price {
            font-family: 'Outfit', sans-serif;
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--gold);
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

        .auth-switch-text {
            text-align: center;
            font-size: 0.88rem;
            color: #94a3b8;
        }

        .auth-switch-text a {
            color: var(--primary-light);
            font-weight: 700;
            text-decoration: none;
            margin-left: 4px;
            transition: var(--transition);
        }

        .auth-switch-text a:hover {
            color: #ffffff;
            text-decoration: underline;
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

        /* ── SUCCESS STATE ── */
        .success-box {
            text-align: center;
            padding: 1rem 0.5rem;
        }

        .success-icon-wrap {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #52b788 0%, #2d6a4f 100%);
            color: #060e0a;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin: 0 auto 1.25rem auto;
            box-shadow: 0 0 25px rgba(82, 183, 136, 0.5);
        }

        .id-badge {
            display: inline-block;
            background: #091a12;
            color: var(--primary-accent);
            font-family: 'Courier New', monospace;
            font-size: 1.45rem;
            font-weight: 800;
            padding: 10px 22px;
            border-radius: 12px;
            border: 1.5px dashed var(--primary-light);
            margin: 14px 0;
            letter-spacing: 2.5px;
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
        <p>Member Account Registration</p>
    </div>

    <!-- Registration Card -->
    <div class="auth-card">

        <?php if ($success): ?>
            <!-- Success Screen -->
            <div class="success-box">
                <div class="success-icon-wrap">
                    <i class="fa-solid fa-check"></i>
                </div>
                <h2 style="font-family:'Outfit',sans-serif; color:#ffffff; font-size:1.5rem; margin-bottom:6px;">Welcome, <?php echo htmlspecialchars($_SESSION['member_name']); ?>!</h2>
                <p style="color:#94a3b8; font-size:0.9rem;">Your membership account is now registered and active.</p>

                <div style="margin: 1.25rem 0;">
                    <div style="font-size:0.72rem; color:#74c69d; text-transform:uppercase; letter-spacing:1px; font-weight:700;">Your Membership ID</div>
                    <div class="id-badge"><?php echo htmlspecialchars($new_membership_id); ?></div>
                    <div style="font-size:0.76rem; color:#64748b;">Save this ID to sign in to the portal and mobile app.</div>
                </div>

                <div style="display:flex; flex-direction:column; gap:10px; margin-top:1.5rem;">
                    <a href="index.php" class="btn-primary-glow" style="text-decoration:none; margin-top:0;">
                        <i class="fa-solid fa-gauge-high"></i> Go to Member Dashboard
                    </a>
                    <a href="id-card.php" class="plan-card-option" style="text-decoration:none; justify-content:center; gap:8px; border-color:var(--primary-light); color:var(--primary-light); font-weight:600;">
                        <i class="fa-solid fa-qrcode"></i> View Digital QR Pass
                    </a>
                </div>
            </div>

        <?php else: ?>

            <?php if ($error): ?>
                <div class="alert-box alert-danger">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div><?php echo $error; ?></div>
                </div>
            <?php endif; ?>

            <form action="register.php" method="POST" id="register-form" novalidate>

                <!-- Full Name -->
                <div class="form-group">
                    <label class="form-label" for="full_name">
                        <span>Full Name <span class="required">*</span></span>
                    </label>
                    <div class="input-group">
                        <i class="fa-solid fa-user input-icon"></i>
                        <input type="text" name="full_name" id="full_name" class="input-field" placeholder="e.g. Juan Dela Cruz" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required autofocus>
                    </div>
                </div>

                <!-- Email Address -->
                <div class="form-group">
                    <label class="form-label" for="email">
                        <span>Email Address <span class="required">*</span></span>
                    </label>
                    <div class="input-group">
                        <i class="fa-solid fa-envelope input-icon"></i>
                        <input type="email" name="email" id="email" class="input-field" placeholder="e.g. juan@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                    </div>
                </div>

                <!-- Contact & Gender (Grid) -->
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="contact_number">
                            <span>Mobile Number</span>
                        </label>
                        <div class="input-group">
                            <i class="fa-solid fa-mobile-screen-button input-icon"></i>
                            <input type="tel" name="contact_number" id="contact_number" class="input-field" placeholder="09123456789" maxlength="11" value="<?php echo htmlspecialchars($_POST['contact_number'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <span>Gender</span>
                        </label>
                        <div class="chip-group">
                            <label class="chip-label <?php echo ($selected_gender === 'Male') ? 'active' : ''; ?>">
                                <input type="radio" name="gender" value="Male" <?php echo ($selected_gender === 'Male') ? 'checked' : ''; ?>>
                                <i class="fa-solid fa-mars"></i> Male
                            </label>
                            <label class="chip-label <?php echo ($selected_gender === 'Female') ? 'active' : ''; ?>">
                                <input type="radio" name="gender" value="Female" <?php echo ($selected_gender === 'Female') ? 'checked' : ''; ?>>
                                <i class="fa-solid fa-venus"></i> Female
                            </label>
                            <label class="chip-label <?php echo ($selected_gender === 'Other') ? 'active' : ''; ?>">
                                <input type="radio" name="gender" value="Other" <?php echo ($selected_gender === 'Other') ? 'checked' : ''; ?>>
                                Other
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Membership Plan Selection (Visual Cards) -->
                <?php if (!empty($plans)): ?>
                <div class="form-group">
                    <label class="form-label">
                        <span>Select Membership Plan</span>
                    </label>
                    <div class="plan-cards-grid">
                        <?php foreach ($plans as $p): ?>
                            <?php $is_selected = ($selected_plan_id === (int)$p['id']); ?>
                            <label class="plan-card-option <?php echo $is_selected ? 'active' : ''; ?>">
                                <input type="radio" name="plan_id" value="<?php echo $p['id']; ?>" <?php echo $is_selected ? 'checked' : ''; ?>>
                                <div class="plan-info-left">
                                    <div class="plan-radio-circle"></div>
                                    <div>
                                        <div class="plan-name"><?php echo htmlspecialchars($p['name']); ?></div>
                                        <div class="plan-duration"><i class="fa-regular fa-clock"></i> <?php echo $p['duration_months']; ?> month duration</div>
                                    </div>
                                </div>
                                <div class="plan-price">₱<?php echo number_format($p['price'], 2); ?></div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Passwords (Grid) -->
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="password">
                            <span>Password <span class="required">*</span></span>
                        </label>
                        <div class="input-group">
                            <i class="fa-solid fa-lock input-icon"></i>
                            <input type="password" name="password" id="password" class="input-field" placeholder="Min. 6 chars" required minlength="6">
                            <button type="button" class="pw-toggle" onclick="togglePassword('password', this)" aria-label="Toggle Password Visibility">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="confirm_password">
                            <span>Confirm <span class="required">*</span></span>
                        </label>
                        <div class="input-group">
                            <i class="fa-solid fa-shield-halved input-icon"></i>
                            <input type="password" name="confirm_password" id="confirm_password" class="input-field" placeholder="Repeat password" required minlength="6">
                            <button type="button" class="pw-toggle" onclick="togglePassword('confirm_password', this)" aria-label="Toggle Confirm Password Visibility">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Primary CTA Button -->
                <button type="submit" class="btn-primary-glow" id="submit-btn">
                    <i class="fa-solid fa-user-plus"></i> Create Account
                </button>

                <!-- Trust Microcopy -->
                <div class="trust-badge">
                    <i class="fa-solid fa-lock"></i> 256-bit SSL Encrypted & Protected
                </div>
            </form>

            <!-- Tertiary Links -->
            <div class="auth-divider">
                <span>OR</span>
            </div>

            <div class="auth-switch-text">
                Already registered?
                <a href="login.php">Sign In to Member Portal &rarr;</a>
            </div>

        <?php endif; ?>
    </div>

    <!-- Secondary CTA: Mobile APK Download Banner -->
    <a href="../download.php" class="apk-download-card">
        <div class="apk-left">
            <div class="apk-icon-box">
                <i class="fa-brands fa-android"></i>
            </div>
            <div>
                <div class="apk-title">Palma's Elite Gym App</div>
                <div class="apk-subtitle">Fast QR check-in & digital pass</div>
            </div>
        </div>
        <div class="apk-badge">
            <i class="fa-solid fa-download"></i> Get APK
        </div>
    </a>

</div>

<!-- Interactive JS -->
<script>
// Password show/hide toggle
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

// Plan selection visual toggle
document.querySelectorAll('.plan-card-option').forEach(card => {
    card.addEventListener('click', function() {
        document.querySelectorAll('.plan-card-option').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        const radio = this.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;
    });
});

// Gender chip selection toggle
document.querySelectorAll('.chip-label').forEach(chip => {
    chip.addEventListener('click', function() {
        document.querySelectorAll('.chip-label').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
    });
});

// Submit loader & password validation
document.getElementById('register-form')?.addEventListener('submit', function(e) {
    const p1 = document.getElementById('password').value;
    const p2 = document.getElementById('confirm_password').value;
    if (p1 !== p2) {
        e.preventDefault();
        alert('Passwords do not match. Please verify your password.');
        return;
    }
    const btn = document.getElementById('submit-btn');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating Account...';
    btn.disabled = true;
});
</script>

</body>
</html>
