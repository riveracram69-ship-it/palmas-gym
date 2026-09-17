<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/rate_limiter.php';

// Prevent caching of credentials / member session
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (empty($_SESSION['member_id']) && !isset($_GET['logged_out']) && isset($pdo)) {
    restore_member_session_from_cookie($pdo);
}

if (isset($_SESSION['member_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
               || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = "Security session expired. Please refresh the page and try again.";
    } else {
        $membership_id = trim($_POST['membership_id'] ?? '');
        $credential    = trim($_POST['credential'] ?? '');

        $rate_check = check_rate_limit($pdo, $membership_id, 'member_portal_login');
        if (!$rate_check['allowed']) {
            $error = $rate_check['message'];
        } elseif (empty($membership_id)) {
            $error = "Please enter your Member ID or Email.";
        } elseif (empty($credential)) {
            $error = "Please enter your password.";
        } else {
            $clean_id = str_replace(['-', ' '], '', strtoupper($membership_id));
            try {
                $stmt = $pdo->prepare("
                    SELECT m.id, m.full_name, m.account_status, m.status, m.rejection_reason, m.password_hash 
                    FROM members m
                    LEFT JOIN renewal_requests r ON r.member_id = m.id
                    WHERE REPLACE(REPLACE(UPPER(m.membership_id), '-', ''), ' ', '') = ?
                       OR LOWER(m.email) = LOWER(?)
                       OR m.contact_number = ?
                       OR UPPER(r.reference_no) = UPPER(?)
                    ORDER BY m.id DESC
                    LIMIT 1
                ");
                $stmt->execute([$clean_id, $membership_id, $membership_id, $membership_id]);
                $member = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($member) {
                    $acc_status = $member['account_status'] ?? 'Approved';

                    if ($acc_status === 'Pending') {
                        $error = "Your registration is currently waiting for staff approval. You will receive an email once approved.";
                    } elseif ($acc_status === 'Rejected') {
                        $reason = !empty($member['rejection_reason']) ? " Reason: " . htmlspecialchars($member['rejection_reason']) : "";
                        $error = "Your registration was not approved.{$reason} Please contact the gym for more information.";
                    } elseif ($acc_status === 'Suspended') {
                        $error = "Your account has been temporarily suspended. Please contact gym administration.";
                    } else {
                        // Account is Approved!
                        if (empty($member['password_hash'])) {
                            $stmt_legacy = $pdo->prepare("SELECT id FROM members WHERE id = ? AND (LOWER(email) = ? OR contact_number = ?)");
                            $stmt_legacy->execute([$member['id'], strtolower($credential), $credential]);
                            if ($stmt_legacy->fetch()) {
                                clear_rate_limit($pdo, $membership_id, 'member_portal_login');
                                $_SESSION['setup_member_id'] = $member['id'];

                                if ($is_ajax) {
                                    header('Content-Type: application/json; charset=utf-8');
                                    echo json_encode([
                                        'success'  => true,
                                        'message'  => 'First-time setup detected. Redirecting...',
                                        'redirect' => 'setup_password.php'
                                    ]);
                                    exit;
                                }

                                header('Location: setup_password.php');
                                exit;
                            } else {
                                $failed = record_failed_login($pdo, $membership_id, 'member_portal_login');
                                $error = $failed['lockout'] ? $failed['message'] : "Invalid verification details for first-time account setup.";
                            }
                        } else {
                            if (password_verify($credential, $member['password_hash'])) {
                                clear_rate_limit($pdo, $membership_id, 'member_portal_login');
                                session_regenerate_id(true);
                                $_SESSION['member_id']   = $member['id'];
                                $_SESSION['member_name'] = $member['full_name'];
                                set_member_remember_cookie($member['id'], $pdo);

                                if ($is_ajax) {
                                    header('Content-Type: application/json; charset=utf-8');
                                    echo json_encode([
                                        'success'  => true,
                                        'message'  => 'Welcome back, ' . ($member['full_name'] ?? 'Member') . '! Redirecting...',
                                        'redirect' => 'index.php'
                                    ]);
                                    exit;
                                }

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

    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $error ?: 'Unable to sign in. Please check your credentials.'
        ]);
        exit;
    }
}

$gym_name = htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Member Login | <?php echo $gym_name; ?></title>
<meta name="description" content="Sign in to the member portal of <?php echo $gym_name; ?>">
<!-- PWA -->
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1A5C3A">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Palma's Elite">
<link rel="apple-touch-icon" href="../assets/images/palmas-logo.png">
<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── PALMAS DESIGN TOKENS ── */
:root{
  --c-bg:       #F4F7F5;
  --c-card:     #FFFFFF;
  --c-input:    #FAFDFA;
  --c-input-f:  #FFFFFF;
  --c-border:   #DCE5DD;
  --c-border-f: #3E8241;

  --c-p:        #3E8241;
  --c-p-mid:    #2D6A4F;
  --c-p-lt:     #52B788;
  --c-p-pale:   #EDF4EE;
  --c-p-glow:   rgba(62,130,65,.18);

  --c-h:        #121A14;
  --c-body:     #334337;
  --c-muted:    #617567;
  --c-faint:    #91A397;

  --c-err:      #DC2626;
  --c-err-p:    #FEE2E2;
  --c-err-b:    #FECACA;

  --r-card:  20px;
  --r-input: 12px;
  --r-btn:   12px;

  --sh-card: 0 4px 20px rgba(18,26,20,.06), 0 1px 3px rgba(18,26,20,.03);
  --sh-btn:  0 4px 14px rgba(62,130,65,.28);
  --sh-fo:   0 0 0 3.5px rgba(62,130,65,.16);

  --tr: all .2s cubic-bezier(.4,0,.2,1);
}

*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{scroll-behavior:smooth}
body{
  font-family:'Inter',system-ui,sans-serif;
  background:var(--c-bg);
  background-image:
    radial-gradient(ellipse at 0% 0%,rgba(58,170,110,.10) 0%,transparent 55%),
    radial-gradient(ellipse at 100% 100%,rgba(26,92,58,.08) 0%,transparent 55%);
  background-attachment:fixed;
  color:var(--c-body);
  min-height:100vh;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  padding:28px 16px 48px;
}
img{max-width:100%;display:block}

/* ── WRAPPER ── */
.wrap{width:100%;max-width:420px}

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
  font-family:'Outfit',sans-serif;
  font-size:1.65rem;font-weight:800;
  color:var(--c-h);letter-spacing:-.4px;line-height:1.2;
}
.brand .sub{font-size:.83rem;color:var(--c-muted);margin-top:3px}
.brand .access-lbl{
  display:inline-block;
  background:var(--c-p-pale);
  color:var(--c-p-mid);
  font-size:.73rem;font-weight:700;
  text-transform:uppercase;letter-spacing:.6px;
  padding:4px 12px;border-radius:20px;margin-top:8px;
}

/* ── CARD ── */
.card{
  background:var(--c-card);
  border-radius:var(--r-card);
  padding:28px 24px;
  box-shadow:var(--sh-card);
  border:1px solid var(--c-border);
}

/* ── ALERT ── */
.alert{
  display:flex;align-items:flex-start;gap:10px;
  padding:11px 14px;border-radius:10px;
  font-size:.875rem;line-height:1.5;margin-bottom:18px;
}
.alert i{margin-top:2px;flex-shrink:0}
.alert-err{background:var(--c-err-p);border:1px solid var(--c-err-b);color:#7F1D1D}
.alert-err i{color:var(--c-err)}

/* ── Modern Floating Toast Notifications ── */
.toast-container {
  position: fixed;
  top: 24px;
  right: 24px;
  z-index: 99999;
  display: flex;
  flex-direction: column;
  gap: 10px;
  pointer-events: none;
}
.toast-notification {
  pointer-events: auto;
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px 18px;
  background: rgba(18, 43, 34, 0.96);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border: 1.5px solid rgba(82, 183, 136, 0.35);
  border-radius: 14px;
  box-shadow: 0 12px 36px rgba(0, 0, 0, 0.45);
  color: #fff;
  min-width: 320px;
  max-width: 440px;
  opacity: 0;
  transform: translateY(-20px) scale(0.95);
  transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.toast-notification.show {
  opacity: 1;
  transform: translateY(0) scale(1);
}
.toast-notification.toast-error {
  background: rgba(45, 12, 12, 0.96);
  border-color: rgba(239, 68, 68, 0.55);
  box-shadow: 0 12px 36px rgba(239, 68, 68, 0.25);
}
.toast-notification.toast-warning {
  background: rgba(48, 32, 10, 0.96);
  border-color: rgba(245, 158, 11, 0.55);
  box-shadow: 0 12px 36px rgba(245, 158, 11, 0.25);
}
.toast-notification.toast-success {
  background: rgba(10, 45, 25, 0.96);
  border-color: rgba(16, 185, 129, 0.55);
  box-shadow: 0 12px 36px rgba(16, 185, 129, 0.25);
}
.toast-icon {
  font-size: 1.3rem;
  line-height: 1;
  margin-top: 2px;
  flex-shrink: 0;
}
.toast-error .toast-icon { color: #ef4444; }
.toast-warning .toast-icon { color: #f59e0b; }
.toast-success .toast-icon { color: #10b981; }
.toast-content { flex: 1; min-width: 0; }
.toast-title {
  font-size: 0.88rem;
  font-weight: 800;
  letter-spacing: 0.3px;
  margin-bottom: 2px;
  font-family: 'Outfit', sans-serif;
  color: #ffffff;
}
.toast-message {
  font-size: 0.82rem;
  color: #e2ece9;
  line-height: 1.45;
}
.toast-close {
  background: none;
  border: none;
  color: rgba(255, 255, 255, 0.5);
  font-size: 1.25rem;
  cursor: pointer;
  padding: 0;
  margin-left: 6px;
  line-height: 1;
  transition: color 0.2s;
}
.toast-close:hover { color: #fff; }

.iw.has-error input {
  border-color: #ef4444 !important;
  background: #fff5f5 !important;
  box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.18) !important;
}
.iw.has-error .ii {
  color: #ef4444 !important;
}
.iw.has-error {
  animation: inputShake 0.4s ease;
}
@keyframes inputShake {
  0%, 100% { transform: translateX(0); }
  20%, 60% { transform: translateX(-6px); }
  40%, 80% { transform: translateX(6px); }
}

@media (max-width: 600px) {
  .toast-container {
    left: 16px;
    right: 16px;
    top: 16px;
  }
  .toast-notification {
    min-width: unset;
    width: 100%;
  }
}

/* ── FORM ── */
.fg{margin-bottom:16px}
.lbl{
  display:flex;align-items:center;justify-content:space-between;
  font-size:.81rem;font-weight:600;color:var(--c-h);
  margin-bottom:5px;
}
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
.if:focus{border-color:var(--c-border-f);background:var(--c-input-f);box-shadow:var(--sh-fo)}
.iw:focus-within .ii{color:var(--c-p-lt)}
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

/* ── FORGOT LINK ── */
.forgot{
  color:var(--c-p-mid);text-decoration:none;
  font-size:.78rem;font-weight:600;
  transition:var(--tr);flex-shrink:0;
}
.forgot:hover{color:var(--c-p);text-decoration:underline}

/* ── INFO BOX ── */
.info-box{
  background:var(--c-p-pale);border:1px solid #BBF7D0;
  border-radius:9px;padding:9px 12px;
  font-size:.78rem;color:#14532D;
  display:flex;align-items:flex-start;gap:8px;margin-top:8px;line-height:1.45;
}
.info-box i{color:var(--c-p-lt);margin-top:1px;flex-shrink:0}

/* ── BUTTONS ── */
.btn-primary{
  width:100%;height:52px;
  background:linear-gradient(135deg,var(--c-p-lt) 0%,var(--c-p) 100%);
  border:none;border-radius:var(--r-btn);
  color:#fff;
  font-family:'Outfit',sans-serif;font-size:1rem;font-weight:700;
  letter-spacing:.2px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:9px;
  margin-top:22px;
  box-shadow:var(--sh-btn);transition:var(--tr);
}
.btn-primary:hover:not(:disabled){
  background:linear-gradient(135deg,#46C47E 0%,var(--c-p-mid) 100%);
  transform:translateY(-1px);box-shadow:0 7px 22px rgba(26,92,58,.38);
}
.btn-primary:active:not(:disabled){transform:translateY(1px);box-shadow:var(--sh-btn)}
.btn-primary:disabled{opacity:.65;cursor:not-allowed;transform:none}

.btn-ghost{
  width:100%;height:47px;
  background:transparent;
  border:1.5px solid var(--c-p-lt);border-radius:var(--r-btn);
  color:var(--c-p);
  font-family:'Inter',sans-serif;font-size:.875rem;font-weight:600;
  cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;
  text-decoration:none;transition:var(--tr);
}
.btn-ghost:hover{background:var(--c-p-pale);border-color:var(--c-p)}

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
  color:var(--c-faint);font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.4px;
}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--c-border)}

/* ── APK CARD ── */
.apk-card{
  margin-top:12px;
  background:#fff;border:1.5px solid var(--c-border);
  border-radius:18px;padding:13px 16px;
  display:flex;align-items:center;justify-content:space-between;
  text-decoration:none;
  box-shadow:0 2px 8px rgba(0,0,0,.04);transition:var(--tr);
}
.apk-card:hover{
  border-color:var(--c-p-lt);background:var(--c-p-pale);
  transform:translateY(-2px);box-shadow:0 6px 18px rgba(26,92,58,.11);
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

/* ── HELP FOOTER ── */
.help-footer{
  margin-top:16px;text-align:center;
  font-size:.75rem;color:var(--c-faint);line-height:1.5;
}

/* ── RESPONSIVE ── */
@media(max-width:359px){
  .card{padding:22px 16px}
  .brand h1{font-size:1.3rem}
  .logo-ring{width:74px;height:74px}
}
@media(min-width:640px){
  body{padding-top:0}
  .card{padding:34px 34px}
}
</style>
</head>
<body>
<div class="wrap">

  <!-- BRAND -->
  <header class="brand">
    <div class="logo-ring">
      <img
        src="../assets/images/palmas-logo.png"
        alt="<?php echo $gym_name; ?> Logo"
        onerror="this.style.display='none';document.getElementById('logo-fb').style.display='flex'"
      >
      <span class="logo-fb" id="logo-fb" aria-hidden="true"><i class="fa-solid fa-dumbbell"></i></span>
    </div>
    <h1><?php echo $gym_name; ?></h1>
    <p class="sub">Membership &amp; Attendance Management</p>
    <span class="access-lbl">Member Portal</span>
  </header>

  <!-- CARD -->
  <main class="card" id="main-content">

    <div id="member-alert-box" class="alert alert-err" style="<?php echo $error ? '' : 'display:none;'; ?>" role="alert" aria-live="assertive">
      <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
      <div id="member-alert-text"><?php echo htmlspecialchars($error); ?></div>
    </div>

    <form action="login.php" method="POST" id="login-form" autocomplete="off" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">

      <!-- Member ID / Email -->
      <div class="fg">
        <label class="lbl" for="membership_id">Member ID or Email</label>
        <div class="iw">
          <i class="fa-solid fa-id-badge ii" aria-hidden="true"></i>
          <input type="text" name="membership_id" id="membership_id" class="if"
            placeholder="e.g. GYM-9537F6 or your email"
            value="<?php echo !isset($_GET['logged_out']) ? htmlspecialchars($_POST['membership_id'] ?? '') : ''; ?>"
            required autocomplete="off" autofocus aria-required="true">
        </div>
      </div>

      <!-- Password -->
      <div class="fg">
        <label class="lbl" for="credential">
          <span>Password</span>
        </label>
        <div class="iw">
          <i class="fa-solid fa-lock ii" aria-hidden="true"></i>
          <input type="password" name="credential" id="credential" class="if has-pw"
            placeholder="Enter your password"
            required autocomplete="new-password" aria-required="true">
          <button type="button" class="pw-btn" onclick="togglePw('credential',this)" aria-label="Show or hide password">
            <i class="fa-regular fa-eye" aria-hidden="true"></i>
          </button>
        </div>
        <div style="display:flex; justify-content:flex-end; margin-top:8px;">
          <a href="forgot_password.php" class="forgot" style="font-size:0.82rem; display:inline-flex; align-items:center; gap:5px;">
            <i class="fa-solid fa-key" style="font-size:0.75rem;"></i> Forgot Password?
          </a>
        </div>
      </div>

      <button type="submit" class="btn-primary" id="sub-btn">
        <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> Access Member Portal
      </button>

      <div class="trust">
        <i class="fa-solid fa-lock" aria-hidden="true"></i>
        256-bit SSL encrypted connection
      </div>

    </form>

    <div class="divider">New Member?</div>
    <a href="register.php" class="btn-ghost">
      <i class="fa-solid fa-user-plus" aria-hidden="true"></i> Create an Account
    </a>

  </main><!-- /.card -->

  <!-- APK -->
  <a href="../download.php" class="apk-card" aria-label="Download Android app">
    <div class="apk-l">
      <div class="apk-ico" aria-hidden="true"><i class="fa-brands fa-android"></i></div>
      <div>
        <div class="apk-t">Download Member App</div>
        <div class="apk-s">Android APK &bull; Digital QR Pass</div>
      </div>
    </div>
    <div class="apk-badge"><i class="fa-solid fa-download" aria-hidden="true"></i> Get App</div>
  </a>

  <p class="help-footer">
    Need help? Contact the gym front desk to retrieve your Membership ID.<br>
    <a href="privacy.php" style="color:var(--c-muted); text-decoration:underline; font-size:0.75rem;">Privacy Policy</a> &bull;
    <a href="terms.php" style="color:var(--c-muted); text-decoration:underline; font-size:0.75rem;">Terms &amp; Conditions</a>
  </p>

</div><!-- /.wrap -->

<!-- Floating Toast Container -->
<div class="toast-container" id="toastContainer" aria-live="polite"></div>

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

function showMemberToast(message, type = 'error', title = null) {
  const container = document.getElementById('toastContainer');
  if (!container) return;

  const titles = {
    error: title || 'Authentication Failed',
    warning: title || 'Attention Required',
    success: title || 'Success'
  };
  const icons = {
    error: 'fa-circle-xmark',
    warning: 'fa-triangle-exclamation',
    success: 'fa-circle-check'
  };

  const toast = document.createElement('div');
  toast.className = `toast-notification toast-${type}`;
  toast.innerHTML = `
    <div class="toast-icon"><i class="fas ${icons[type] || icons.error}"></i></div>
    <div class="toast-content">
      <div class="toast-title">${titles[type] || 'Notice'}</div>
      <div class="toast-message">${message}</div>
    </div>
    <button type="button" class="toast-close" onclick="this.parentElement.remove()" aria-label="Close notification">&times;</button>
  `;

  container.appendChild(toast);
  requestAnimationFrame(() => toast.classList.add('show'));

  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.remove(), 400);
  }, 5000);
}

function setMemberInlineAlert(message) {
  const alertBox = document.getElementById('member-alert-box');
  const alertText = document.getElementById('member-alert-text');
  if (alertBox && alertText) {
    if (message) {
      alertText.textContent = message;
      alertBox.style.display = 'flex';
      alertBox.style.animation = 'none';
      alertBox.offsetHeight;
      alertBox.style.animation = 'inputShake 0.4s ease';
    } else {
      alertBox.style.display = 'none';
    }
  }
}

function markMemberInputError(elementId) {
  const el = document.getElementById(elementId);
  if (!el) return;
  const wrap = el.closest('.iw');
  if (wrap) {
    wrap.classList.remove('has-error');
    wrap.offsetHeight;
    wrap.classList.add('has-error');
  }
  el.focus();
}

['membership_id', 'credential'].forEach(id => {
  const inp = document.getElementById(id);
  if (inp) {
    inp.addEventListener('input', () => {
      const wrap = inp.closest('.iw');
      if (wrap) wrap.classList.remove('has-error');
      setMemberInlineAlert('');
    });
  }
});

const memberLoginForm = document.getElementById('login-form');
if (memberLoginForm) {
  memberLoginForm.addEventListener('submit', async function(e) {
    e.preventDefault();

    const midInp  = document.getElementById('membership_id');
    const credInp = document.getElementById('credential');
    const subBtn  = document.getElementById('sub-btn');

    const mid  = (midInp?.value || '').trim();
    const cred = credInp?.value || '';

    if (!mid) {
      showMemberToast('Please enter your Membership ID or Email.', 'warning', 'ID / Email Required');
      setMemberInlineAlert('Please enter your Membership ID or Email.');
      markMemberInputError('membership_id');
      return;
    }

    if (!cred) {
      showMemberToast('Please enter your password to access your member pass.', 'warning', 'Password Required');
      setMemberInlineAlert('Please enter your password.');
      markMemberInputError('credential');
      return;
    }

    localStorage.setItem('peg_saved_login_id', mid);

    const origBtnHtml = subBtn.innerHTML;
    subBtn.disabled = true;
    subBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying credentials...';

    try {
      const formData = new FormData(memberLoginForm);
      const response = await fetch('login.php', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        },
        body: formData
      });

      const data = await response.json();

      if (data.success) {
        showMemberToast(data.message || 'Access granted! Opening member pass...', 'success', 'Welcome');
        subBtn.innerHTML = '<i class="fa-solid fa-check"></i> Redirecting...';
        setTimeout(() => {
          window.location.href = data.redirect || 'index.php';
        }, 400);
      } else {
        const msg = data.message || 'Incorrect Member ID/Email or Password.';
        showMemberToast(msg, 'error', 'Access Denied');
        setMemberInlineAlert(msg);
        markMemberInputError('credential');
        subBtn.disabled = false;
        subBtn.innerHTML = origBtnHtml;
      }
    } catch (err) {
      console.warn('AJAX member login fallback:', err);
      memberLoginForm.submit();
    }
  });
}

if('serviceWorker' in navigator){
  window.addEventListener('load',()=>navigator.serviceWorker.register('sw.js'));
}

window.addEventListener('pageshow', function() {
  const cred = document.getElementById('credential');
  if (cred) cred.value = '';
  const mid = document.getElementById('membership_id');
  const isLoggedOut = new URLSearchParams(window.location.search).has('logged_out');
  if (isLoggedOut) {
    if (mid) mid.value = '';
    localStorage.removeItem('peg_saved_login_id');
  } else if (mid && !mid.value) {
    const saved = localStorage.getItem('peg_saved_login_id');
    if (saved) mid.value = saved;
  }
});
</script>
</body>
</html>
