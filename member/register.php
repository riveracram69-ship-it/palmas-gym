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

            log_activity($pdo, 'Member Registration', "Web self-registered member: {$full_name} ({$membership_id})", 'Member');

            try {
                require_once __DIR__ . '/../config/email.php';
                $email_subject = "Welcome to Palma's Elite Gym!";
                $email_title   = "Welcome, {$full_name}!";
                $email_body    = "Your membership account has been created! Your Membership ID is: <strong>{$membership_id}</strong>. Use it or your email to sign in.";
                send_email_notification($email, $email_subject, $email_title, $email_body);
            } catch (Exception $emErr) {}

            $_SESSION['member_id']   = $member_id;
            $_SESSION['member_name'] = $full_name;
            $new_membership_id       = $membership_id;
            $success                 = "Account successfully created! Welcome to Palma's Elite Gym.";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Registration Error: ' . $e->getMessage());
            $error = "An error occurred while creating your account. Please try again.";
        }
    } else {
        $error = implode('<br>', $validation_errors);
    }
}

$selected_gender  = $_POST['gender'] ?? 'Male';
$selected_plan_id = intval($_POST['plan_id'] ?? ($plans[0]['id'] ?? 0));
$gym_name         = htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Create Account | <?php echo $gym_name; ?></title>
<meta name="description" content="Register for a membership at <?php echo $gym_name; ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── TOKENS ── */
:root{
  --c-bg:       #F2F5F3;
  --c-card:     #FFFFFF;
  --c-input:    #F8FAF9;
  --c-input-f:  #EDF7F2;
  --c-border:   #E2E8E5;
  --c-border-f: #3AAA6E;

  --c-p:        #1A5C3A;
  --c-p-mid:    #2D7A52;
  --c-p-lt:     #3AAA6E;
  --c-p-pale:   #E8F5EE;
  --c-p-glow:   rgba(58,170,110,.18);

  --c-gold:     #C07A10;
  --c-gold-p:   #FEF3E2;

  --c-h:        #111827;
  --c-body:     #374151;
  --c-muted:    #6B7280;
  --c-faint:    #9CA3AF;

  --c-err:      #DC2626;
  --c-err-p:    #FEF2F2;
  --c-err-b:    #FECACA;
  --c-ok:       #16A34A;
  --c-ok-p:     #F0FDF4;

  --r-card:  20px;
  --r-input: 11px;
  --r-btn:   11px;
  --r-chip:  9px;

  --sh-card: 0 2px 4px rgba(0,0,0,.05), 0 8px 24px rgba(0,0,0,.08);
  --sh-btn:  0 4px 14px rgba(26,92,58,.28);
  --sh-fo:   0 0 0 3px rgba(58,170,110,.20);

  --tr: all .19s cubic-bezier(.4,0,.2,1);
}

*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{scroll-behavior:smooth}
body{
  font-family:'Inter',system-ui,sans-serif;
  background:var(--c-bg);
  background-image:
    radial-gradient(ellipse at 0% 0%, rgba(58,170,110,.09) 0%, transparent 50%),
    radial-gradient(ellipse at 100% 100%, rgba(26,92,58,.07) 0%, transparent 50%);
  background-attachment:fixed;
  color:var(--c-body);
  min-height:100vh;
  display:flex;
  flex-direction:column;
  align-items:center;
  padding:24px 16px 52px;
}
img{max-width:100%;display:block}

/* ── WRAPPER ── */
.wrap{width:100%;max-width:510px}

/* ── BRAND ── */
.brand{text-align:center;margin-bottom:18px}
.logo-ring{
  width:84px;height:84px;
  margin:0 auto 12px;
  border-radius:50%;
  background:#fff;
  border:2.5px solid #C8E6D4;
  box-shadow:0 4px 20px rgba(26,92,58,.14);
  display:flex;align-items:center;justify-content:center;
  overflow:hidden;padding:6px;
}
.logo-ring img{width:100%;height:100%;object-fit:contain}
.logo-fb{display:none;font-size:2rem;color:var(--c-p)}
.brand h1{
  font-family:'Poppins',sans-serif;
  font-size:1.5rem;font-weight:800;
  color:var(--c-h);letter-spacing:-.3px;line-height:1.2;
}
.brand .sub{font-size:.83rem;color:var(--c-muted);margin-top:3px}
.brand .tag{font-size:.79rem;color:var(--c-p-mid);margin-top:5px;font-style:italic;font-weight:500}

/* ── CARD ── */
.card{
  background:var(--c-card);
  border-radius:var(--r-card);
  padding:26px 22px;
  box-shadow:var(--sh-card);
  border:1px solid var(--c-border);
}

/* ── ALERTS ── */
.alert{
  display:flex;align-items:flex-start;gap:10px;
  padding:11px 14px;border-radius:10px;
  font-size:.875rem;line-height:1.5;margin-bottom:18px;
}
.alert i{margin-top:2px;flex-shrink:0}
.alert-err{background:var(--c-err-p);border:1px solid var(--c-err-b);color:#7F1D1D}
.alert-err i{color:var(--c-err)}
.alert-ok{background:var(--c-ok-p);border:1px solid #BBF7D0;color:#14532D}
.alert-ok i{color:var(--c-ok)}

/* ── FORM ── */
.fg{margin-bottom:16px}
.lbl{
  display:flex;align-items:center;justify-content:space-between;
  font-size:.81rem;font-weight:600;color:var(--c-h);
  margin-bottom:5px;letter-spacing:.05px;
}
.req{color:var(--c-err);margin-left:1px}
.iw{position:relative;display:flex;align-items:center}
.ii{
  position:absolute;left:12px;
  color:var(--c-faint);font-size:.88rem;
  pointer-events:none;transition:var(--tr);z-index:1;
}
.if{
  width:100%;height:50px;
  background:var(--c-input);
  border:1.5px solid var(--c-border);
  border-radius:var(--r-input);
  padding:0 14px 0 38px;
  color:var(--c-h);
  font-family:'Inter',sans-serif;font-size:.93rem;
  outline:none;transition:var(--tr);
  -webkit-appearance:none;appearance:none;
}
.if::placeholder{color:var(--c-faint);font-size:.84rem}
.if:focus{
  border-color:var(--c-border-f);
  background:var(--c-input-f);
  box-shadow:var(--sh-fo);
}
.iw:focus-within .ii{color:var(--c-p-lt)}
select.if{
  padding-right:34px;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 11px center;cursor:pointer;
}
.pw-btn{
  position:absolute;right:12px;
  background:none;border:none;
  color:var(--c-faint);font-size:.88rem;
  cursor:pointer;padding:6px;border-radius:6px;
  display:flex;align-items:center;justify-content:center;
  min-width:32px;min-height:32px;transition:var(--tr);
}
.pw-btn:hover{color:var(--c-p-mid)}
.pw-btn:focus-visible{outline:2px solid var(--c-border-f);outline-offset:2px}
.if.has-pw{padding-right:44px}
.hint{font-size:.73rem;color:var(--c-faint);margin-top:4px;display:flex;align-items:center;gap:4px;min-height:18px}

/* ── 2-COL GRID (480px+) ── */
.g2{display:grid;grid-template-columns:1fr;gap:0}
@media(min-width:480px){.g2{grid-template-columns:1fr 1fr;gap:12px}}

/* ── GENDER CHIPS ── */
.chips{display:flex;gap:7px}
.chip{
  flex:1;display:flex;align-items:center;justify-content:center;gap:5px;
  height:48px;
  background:var(--c-input);border:1.5px solid var(--c-border);
  border-radius:var(--r-chip);
  color:var(--c-muted);font-size:.8rem;font-weight:600;
  cursor:pointer;transition:var(--tr);user-select:none;
}
.chip input[type=radio]{display:none}
.chip:hover{border-color:var(--c-p-lt);color:var(--c-p);background:var(--c-p-pale)}
.chip.on{border-color:var(--c-p-lt);background:var(--c-p-pale);color:var(--c-p);box-shadow:0 0 0 3px var(--c-p-glow)}
.chip i{font-size:.82rem}

/* ── PLAN CARDS ── */
.plans{display:flex;flex-direction:column;gap:7px}
.plan{
  display:flex;align-items:center;justify-content:space-between;
  padding:12px 14px;
  background:var(--c-input);border:1.5px solid var(--c-border);
  border-radius:var(--r-input);cursor:pointer;transition:var(--tr);
}
.plan input[type=radio]{display:none}
.plan:hover{border-color:var(--c-p-lt);background:var(--c-p-pale)}
.plan.on{border-color:var(--c-p-lt);background:var(--c-p-pale);box-shadow:0 0 0 3px var(--c-p-glow)}
.plan-l{display:flex;align-items:center;gap:10px}
.radio-dot{
  width:17px;height:17px;border-radius:50%;
  border:2px solid var(--c-border);
  background:#fff;flex-shrink:0;
  position:relative;transition:var(--tr);
}
.plan.on .radio-dot{border-color:var(--c-p-lt);background:var(--c-p-lt)}
.plan.on .radio-dot::after{
  content:'';position:absolute;
  width:5px;height:5px;background:#fff;
  border-radius:50%;top:50%;left:50%;transform:translate(-50%,-50%);
}
.plan-name{font-size:.86rem;font-weight:700;color:var(--c-h)}
.plan-dur{font-size:.73rem;color:var(--c-muted);margin-top:1px}
.plan-price{font-size:.98rem;font-weight:800;color:var(--c-p);font-family:'Poppins',sans-serif}

/* ── SECTION TAG ── */
.sec-tag{
  font-size:.68rem;font-weight:700;text-transform:uppercase;
  letter-spacing:.7px;color:var(--c-faint);
  display:flex;align-items:center;gap:8px;margin:4px 0 14px;
}
.sec-tag::after{content:'';flex:1;height:1px;background:var(--c-border)}

/* ── BUTTONS ── */
.btn-primary{
  width:100%;height:52px;
  background:linear-gradient(135deg,var(--c-p-lt) 0%,var(--c-p) 100%);
  border:none;border-radius:var(--r-btn);
  color:#fff;
  font-family:'Poppins',sans-serif;font-size:.96rem;font-weight:700;
  letter-spacing:.2px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:9px;
  margin-top:22px;
  box-shadow:var(--sh-btn);transition:var(--tr);
}
.btn-primary:hover:not(:disabled){
  background:linear-gradient(135deg,#46C47E 0%,var(--c-p-mid) 100%);
  transform:translateY(-1px);
  box-shadow:0 7px 22px rgba(26,92,58,.38);
}
.btn-primary:active:not(:disabled){transform:translateY(1px);box-shadow:var(--sh-btn)}
.btn-primary:disabled{opacity:.65;cursor:not-allowed;transform:none}

.btn-outline{
  width:100%;height:47px;
  background:transparent;
  border:1.5px solid var(--c-p-lt);border-radius:var(--r-btn);
  color:var(--c-p);
  font-family:'Inter',sans-serif;font-size:.875rem;font-weight:600;
  cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;
  text-decoration:none;transition:var(--tr);
}
.btn-outline:hover{background:var(--c-p-pale);border-color:var(--c-p)}

/* ── TRUST ── */
.trust{
  display:flex;align-items:center;justify-content:center;gap:6px;
  font-size:.71rem;color:var(--c-faint);margin-top:11px;
}
.trust i{color:var(--c-p-lt);font-size:.73rem}

/* ── DIVIDER ── */
.divider{
  display:flex;align-items:center;gap:11px;
  margin:20px 0;
  color:var(--c-faint);font-size:.73rem;font-weight:600;text-transform:uppercase;letter-spacing:.4px;
}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--c-border)}

/* ── SIGN-IN ROW ── */
.signin-row{text-align:center;font-size:.875rem;color:var(--c-muted)}
.signin-row a{color:var(--c-p);font-weight:700;text-decoration:none;margin-left:4px;transition:var(--tr)}
.signin-row a:hover{color:var(--c-p-mid);text-decoration:underline}

/* ── APK CARD ── */
.apk-card{
  margin-top:12px;
  background:#fff;
  border:1.5px solid var(--c-border);
  border-radius:18px;
  padding:13px 16px;
  display:flex;align-items:center;justify-content:space-between;
  text-decoration:none;
  box-shadow:0 2px 8px rgba(0,0,0,.04);
  transition:var(--tr);
}
.apk-card:hover{
  border-color:var(--c-p-lt);background:var(--c-p-pale);
  transform:translateY(-2px);
  box-shadow:0 6px 18px rgba(26,92,58,.11);
}
.apk-l{display:flex;align-items:center;gap:12px}
.apk-ico{
  width:44px;height:44px;border-radius:12px;
  background:var(--c-p-pale);border:1px solid #BBF7D0;
  display:flex;align-items:center;justify-content:center;
  color:var(--c-p);font-size:1.3rem;flex-shrink:0;
}
.apk-t{font-size:.875rem;font-weight:700;color:var(--c-h)}
.apk-s{font-size:.73rem;color:var(--c-muted);margin-top:2px}
.apk-badge{
  background:var(--c-p);color:#fff;
  font-size:.7rem;font-weight:700;
  padding:6px 12px;border-radius:20px;
  display:flex;align-items:center;gap:5px;flex-shrink:0;
}

/* ── SUCCESS ── */
.success-scr{text-align:center;padding:8px 0}
.success-ico{
  width:66px;height:66px;
  background:linear-gradient(135deg,var(--c-p-lt),var(--c-p));
  border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  margin:0 auto 16px;
  box-shadow:0 8px 24px rgba(26,92,58,.28);
  font-size:1.8rem;color:#fff;
}
.success-scr h2{font-family:'Poppins',sans-serif;font-size:1.35rem;font-weight:800;color:var(--c-h);margin-bottom:5px}
.success-scr p{font-size:.875rem;color:var(--c-muted)}
.mid-box{
  background:var(--c-p-pale);border:1.5px dashed var(--c-p-lt);
  border-radius:12px;padding:13px 18px;margin:18px 0;
}
.mid-lbl{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--c-p-mid);margin-bottom:3px}
.mid-val{font-family:'Courier New',monospace;font-size:1.45rem;font-weight:800;color:var(--c-p);letter-spacing:3px}
.mid-hint{font-size:.73rem;color:var(--c-muted);margin-top:4px}

/* ── RESPONSIVE ── */
@media(max-width:359px){
  .card{padding:20px 14px}
  .brand h1{font-size:1.3rem}
  .chip{font-size:.74rem}
  .logo-ring{width:74px;height:74px}
}
@media(min-width:640px){
  body{padding-top:40px}
  .card{padding:34px 34px}
}
</style>
</head>
<body>
<div class="wrap">

  <!-- BRAND HEADER -->
  <header class="brand">
    <div class="logo-ring" id="logo-wrap">
      <img
        src="../assets/images/palmas-logo.png"
        alt="<?php echo $gym_name; ?> Logo"
        onerror="this.style.display='none';document.getElementById('logo-fb').style.display='flex'"
      >
      <span class="logo-fb" id="logo-fb" aria-hidden="true"><i class="fa-solid fa-dumbbell"></i></span>
    </div>
    <h1><?php echo $gym_name; ?></h1>
    <p class="sub">Membership &amp; Attendance Management</p>
    <p class="tag">&ldquo;Join us and manage your membership easily.&rdquo;</p>
  </header>

  <!-- CARD -->
  <main class="card" id="main-content">

    <?php if ($success): ?>
    <!-- SUCCESS SCREEN -->
    <div class="success-scr">
      <div class="success-ico"><i class="fa-solid fa-check"></i></div>
      <h2>Welcome, <?php echo htmlspecialchars($_SESSION['member_name']); ?>!</h2>
      <p>Your membership is now active and ready to use.</p>
      <div class="mid-box">
        <div class="mid-lbl">Your Membership ID</div>
        <div class="mid-val"><?php echo htmlspecialchars($new_membership_id); ?></div>
        <div class="mid-hint">Save this ID &mdash; use it to sign in to the portal and mobile app.</div>
      </div>
      <div style="display:flex;flex-direction:column;gap:10px">
        <a href="index.php" class="btn-primary" style="text-decoration:none;margin-top:0">
          <i class="fa-solid fa-gauge-high" aria-hidden="true"></i> Go to Dashboard
        </a>
        <a href="id-card.php" class="btn-outline">
          <i class="fa-solid fa-qrcode" aria-hidden="true"></i> View Digital QR Pass
        </a>
      </div>
    </div>

    <?php else: ?>

    <?php if ($error): ?>
    <div class="alert alert-err" role="alert" aria-live="assertive">
      <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
      <div><?php echo $error; ?></div>
    </div>
    <?php endif; ?>

    <form action="register.php" method="POST" id="reg-form" novalidate>

      <!-- Full Name -->
      <div class="fg">
        <label class="lbl" for="full_name">
          Full Name <span class="req" aria-hidden="true">*</span>
        </label>
        <div class="iw">
          <i class="fa-solid fa-user ii" aria-hidden="true"></i>
          <input type="text" name="full_name" id="full_name" class="if"
            placeholder="e.g. Juan Dela Cruz"
            value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
            required autofocus autocomplete="name" aria-required="true">
        </div>
      </div>

      <!-- Email -->
      <div class="fg">
        <label class="lbl" for="email">
          Email Address <span class="req" aria-hidden="true">*</span>
        </label>
        <div class="iw">
          <i class="fa-solid fa-envelope ii" aria-hidden="true"></i>
          <input type="email" name="email" id="email" class="if"
            placeholder="e.g. juan@example.com"
            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
            required autocomplete="email" aria-required="true">
        </div>
      </div>

      <!-- Contact & Gender -->
      <div class="g2">
        <div class="fg">
          <label class="lbl" for="contact_number">Contact Number</label>
          <div class="iw">
            <i class="fa-solid fa-mobile-screen-button ii" aria-hidden="true"></i>
            <input type="tel" name="contact_number" id="contact_number" class="if"
              placeholder="09XXXXXXXXX" maxlength="11"
              value="<?php echo htmlspecialchars($_POST['contact_number'] ?? ''); ?>"
              autocomplete="tel">
          </div>
        </div>
        <div class="fg">
          <div class="lbl" id="gender-lbl">Gender</div>
          <div class="chips" role="radiogroup" aria-labelledby="gender-lbl">
            <label class="chip <?php echo ($selected_gender==='Male')?'on':''; ?>" aria-label="Male">
              <input type="radio" name="gender" value="Male" <?php echo ($selected_gender==='Male')?'checked':''; ?>>
              <i class="fa-solid fa-mars" aria-hidden="true"></i> Male
            </label>
            <label class="chip <?php echo ($selected_gender==='Female')?'on':''; ?>" aria-label="Female">
              <input type="radio" name="gender" value="Female" <?php echo ($selected_gender==='Female')?'checked':''; ?>>
              <i class="fa-solid fa-venus" aria-hidden="true"></i> Female
            </label>
            <label class="chip <?php echo ($selected_gender==='Other')?'on':''; ?>" aria-label="Other">
              <input type="radio" name="gender" value="Other" <?php echo ($selected_gender==='Other')?'checked':''; ?>>
              Other
            </label>
          </div>
        </div>
      </div>

      <!-- Plan -->
      <?php if (!empty($plans)): ?>
      <div class="fg">
        <div class="lbl" id="plan-lbl">Membership Plan</div>
        <div class="plans" role="radiogroup" aria-labelledby="plan-lbl">
          <?php foreach($plans as $p): $sel=($selected_plan_id===(int)$p['id']); ?>
          <label class="plan <?php echo $sel?'on':''; ?>">
            <input type="radio" name="plan_id" value="<?php echo $p['id']; ?>" <?php echo $sel?'checked':''; ?>>
            <div class="plan-l">
              <div class="radio-dot" aria-hidden="true"></div>
              <div>
                <div class="plan-name"><?php echo htmlspecialchars($p['name']); ?></div>
                <div class="plan-dur"><i class="fa-regular fa-clock" aria-hidden="true"></i> <?php echo $p['duration_months']; ?> month<?php echo $p['duration_months']!=1?'s':''; ?></div>
              </div>
            </div>
            <div class="plan-price">&#8369;<?php echo number_format($p['price'],2); ?></div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Passwords -->
      <div class="sec-tag">Security</div>

      <div class="fg">
        <label class="lbl" for="password">
          Password <span class="req" aria-hidden="true">*</span>
        </label>
        <div class="iw">
          <i class="fa-solid fa-lock ii" aria-hidden="true"></i>
          <input type="password" name="password" id="password" class="if has-pw"
            placeholder="Minimum 6 characters"
            required minlength="6" autocomplete="new-password" aria-required="true">
          <button type="button" class="pw-btn" onclick="togglePw('password',this)" aria-label="Show or hide password">
            <i class="fa-regular fa-eye" aria-hidden="true"></i>
          </button>
        </div>
      </div>

      <div class="fg">
        <label class="lbl" for="confirm_password">
          Confirm Password <span class="req" aria-hidden="true">*</span>
        </label>
        <div class="iw">
          <i class="fa-solid fa-shield-halved ii" aria-hidden="true"></i>
          <input type="password" name="confirm_password" id="confirm_password" class="if has-pw"
            placeholder="Repeat your password"
            required minlength="6" autocomplete="new-password" aria-required="true">
          <button type="button" class="pw-btn" onclick="togglePw('confirm_password',this)" aria-label="Show or hide confirm password">
            <i class="fa-regular fa-eye" aria-hidden="true"></i>
          </button>
        </div>
        <div class="hint" id="pw-hint" aria-live="polite"></div>
      </div>

      <button type="submit" class="btn-primary" id="sub-btn">
        <i class="fa-solid fa-user-plus" aria-hidden="true"></i> Create Account
      </button>

      <div class="trust">
        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
        Your account information is securely protected.
      </div>

    </form>

    <div class="divider">Already have an account?</div>
    <div class="signin-row">
      <a href="login.php">Sign In to Member Portal &rarr;</a>
    </div>

    <?php endif; ?>
  </main><!-- /.card -->

  <!-- APK DOWNLOAD -->
  <a href="../download.php" class="apk-card" aria-label="Download Android app">
    <div class="apk-l">
      <div class="apk-ico" aria-hidden="true"><i class="fa-brands fa-android"></i></div>
      <div>
        <div class="apk-t">Download Member App</div>
        <div class="apk-s">Android APK &bull; Fast QR check-in</div>
      </div>
    </div>
    <div class="apk-badge"><i class="fa-solid fa-download" aria-hidden="true"></i> Get APK</div>
  </a>

</div><!-- /.wrap -->

<script>
function togglePw(id,btn){
  const inp=document.getElementById(id);
  const ic=btn.querySelector('i');
  const hide=inp.type==='password';
  inp.type=hide?'text':'password';
  ic.classList.toggle('fa-eye',!hide);
  ic.classList.toggle('fa-eye-slash',hide);
  btn.setAttribute('aria-pressed',String(hide));
}

/* Gender chips */
document.querySelectorAll('.chip').forEach(c=>{
  c.addEventListener('change',()=>{
    document.querySelectorAll('.chip').forEach(x=>x.classList.remove('on'));
    c.classList.add('on');
  });
});

/* Plan cards */
document.querySelectorAll('.plan').forEach(p=>{
  p.addEventListener('change',()=>{
    document.querySelectorAll('.plan').forEach(x=>x.classList.remove('on'));
    p.classList.add('on');
  });
});

/* Live pw match */
const pw1=document.getElementById('password');
const pw2=document.getElementById('confirm_password');
const hint=document.getElementById('pw-hint');
function chkMatch(){
  if(!pw2||!pw2.value){if(hint)hint.innerHTML='';return;}
  if(pw1.value===pw2.value){
    hint.innerHTML='<i class="fa-solid fa-circle-check" style="color:#16A34A" aria-hidden="true"></i> <span style="color:#16A34A">Passwords match</span>';
  }else{
    hint.innerHTML='<i class="fa-solid fa-circle-xmark" style="color:#DC2626" aria-hidden="true"></i> <span style="color:#DC2626">Passwords do not match</span>';
  }
}
pw1&&pw1.addEventListener('input',chkMatch);
pw2&&pw2.addEventListener('input',chkMatch);

/* Submit guard */
document.getElementById('reg-form')&&document.getElementById('reg-form').addEventListener('submit',function(e){
  if(pw1.value!==pw2.value){e.preventDefault();pw2.focus();return;}
  const b=document.getElementById('sub-btn');
  b.innerHTML='<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Creating Account\u2026';
  b.disabled=true;
});
</script>
</body>
</html>
