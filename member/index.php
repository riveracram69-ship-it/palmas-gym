<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/member_helpers.php';
require_member_login();

$member = current_member($pdo);

if (!$member) {
    header('Location: logout.php');
    exit;
}

$is_official = is_official_member($member);
$ann_expiry = $member['annual_membership_expiry'] ?? null;
$ann_days_left = null;
$ann_can_renew = false;

if (!empty($ann_expiry)) {
    $ann_diff = strtotime($ann_expiry) - time();
    $ann_days_left = (int) ceil($ann_diff / 86400);
    $ann_can_renew = ($ann_days_left <= 30);
} else {
    $ann_can_renew = true;
}

// Gym Pass Subscription countdown (Decoupled from Annual Membership Fee)
$days_left = null;
$expired   = true;
$progress  = 0;
$can_renew_pass = true;
$cannot_renew_pass_reason = '';

if (!empty($member['expiry_date'])) {
    $expiry_ts  = strtotime($member['expiry_date']);
    $now_ts     = time();
    $diff       = $expiry_ts - $now_ts;
    $days_left  = (int) ceil($diff / 86400);
    $expired    = ($diff <= 0);

    // estimate progress based on a 30-day plan window
    $plan_days  = 30;
    $elapsed    = $plan_days - max(0, $days_left);
    $progress   = max(0, min(100, round(($elapsed / $plan_days) * 100)));
    $is_minute_promo = (!empty($member['duration_minutes']) && $member['duration_minutes'] > 0);

    if (!$expired) {
        $threshold_sec = (!empty($is_minute_promo)) ? 300 : (3 * 86400);
        if ($diff > $threshold_sec) {
            $can_renew_pass = false;
            $rem_text = (!empty($is_minute_promo) || $diff < 86400) ? ceil($diff / 60) . ' min(s)' : ceil($diff / 86400) . ' day(s)';
            $rule_text = (!empty($is_minute_promo)) ? 'within 5 minutes of expiration' : 'within 3 days of expiration';
            $cannot_renew_pass_reason = "Your gym pass is still active ({$rem_text} remaining). Extension is available when expired or {$rule_text}.";
        }
    }
}

// Recent attendance count (last 30 days)
$attendance_count = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE member_id = ? AND date >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $s->execute([$member['id']]);
    $attendance_count = $s->fetchColumn();
} catch (Exception $e) {}

// Last payment
$last_payment = null;
try {
    $s = $pdo->prepare("SELECT * FROM payments WHERE member_id = ? ORDER BY payment_date DESC LIMIT 1");
    $s->execute([$member['id']]);
    $last_payment = $s->fetch();
} catch (Exception $e) {}

// Membership plans with Tier Eligibility & Discount Annotations
$all_plans     = [];
$allowed_plans = [];
try {
    $raw_plans = $pdo->query("
        SELECT id, name, price, duration_months, duration_minutes, benefits, plan_category, floor_access 
        FROM membership_plans 
        WHERE is_active = 1 AND (plan_category IS NULL OR plan_category != 'legacy') 
        ORDER BY 
            CASE plan_category 
                WHEN 'membership_fee' THEN 1 
                WHEN 'member_pass' THEN 2 
                WHEN 'non_member_pass' THEN 3 
                WHEN 'test_promo' THEN 4 
                ELSE 5 
            END, 
            price ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($raw_plans as $p) {
        $elig = validate_plan_tier_eligibility($p, $member);
        $p['is_allowed'] = $elig['allowed'];
        $p['reason']     = $elig['reason'];
        $all_plans[]     = $p;
        if ($elig['allowed']) {
            $allowed_plans[] = $p;
        }
    }
} catch (Exception $e) {}
$plans = $all_plans;

// Check for pending renewal request
$pending_request = null;
try {
    $s = $pdo->prepare("SELECT r.*, p.name as plan_name, p.price as plan_price 
                        FROM renewal_requests r 
                        JOIN membership_plans p ON p.id = r.plan_id 
                        WHERE r.member_id = ? AND r.status = 'Pending' 
                        LIMIT 1");
    $s->execute([$member['id']]);
    $pending_request = $s->fetch();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Dashboard | <?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></title>
    <link rel="stylesheet" href="../assets/css/member.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <meta name="csrf-token" content="<?php echo get_csrf_token(); ?>">
    <link rel="manifest" href="manifest.json">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Palma's Elite">
    <link rel="apple-touch-icon" href="../assets/images/palmas-logo.png">
</head>
<body>
<div class="mobile-container">

    <!-- Header -->
    <header class="app-header">
        <div class="app-brand">
            <img src="../assets/images/palmas-logo.png" alt="Logo">
            <h1><?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's"); ?></h1>
        </div>
        <div class="header-actions">
            <!-- Notification Bell -->
            <button class="header-icon-btn notif-bell-wrap" id="notif-bell-btn" title="Notifications" onclick="openNotifDrawer()">
                <i class="fas fa-bell"></i>
                <span class="notif-badge" id="notif-badge">0</span>
            </button>
            <a href="logout.php" class="header-icon-btn danger" title="Sign Out">
                <i class="fas fa-right-from-bracket"></i>
            </a>
        </div>
    </header>

    <main class="app-content">

        <!-- Hero Welcome Card -->
        <div class="hero-card fade-up">
            <div class="hero-inner">
                <div class="hero-avatar">
                    <?php $photo_url = get_member_photo_url($member['photo']); ?>
                    <?php if ($photo_url): ?>
                        <img src="<?php echo htmlspecialchars($photo_url); ?>" alt="<?php echo htmlspecialchars($member['full_name']); ?>" onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                        <span class="avatar-initial" style="display:none;"><?php echo strtoupper(substr($member['full_name'], 0, 1)); ?></span>
                    <?php else: ?>
                        <span class="avatar-initial"><?php echo strtoupper(substr($member['full_name'], 0, 1)); ?></span>
                    <?php endif; ?>
                </div>
                <div class="hero-info">
                    <p class="hero-greeting">Welcome back,</p>
                    <h2 class="hero-name"><?php echo htmlspecialchars($member['full_name']); ?></h2>
                    <p class="hero-id"><?php echo htmlspecialchars($member['membership_id']); ?></p>
                </div>
                <?php if ($is_official): ?>
                    <span class="badge" style="background:#dcfce7; color:#15803d; border:1px solid #86efac; font-weight:700; font-size:0.75rem; padding:5px 12px; border-radius:20px; display:inline-flex; align-items:center; gap:5px;"><i class="fas fa-medal" style="color:#eab308;"></i> Official Member</span>
                <?php else: ?>
                    <span class="badge" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-weight:700; font-size:0.75rem; padding:5px 12px; border-radius:20px; display:inline-flex; align-items:center; gap:5px;"><i class="fas fa-user"></i> Non-Member</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($pending_request): ?>
            <!-- Pending Front Desk Renewal Banner -->
            <div class="card pending-renewal-banner fade-up" style="background: #fffbeb; border: 1.5px dashed #f59e0b; border-radius: 16px; padding: 1.25rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 1rem; box-shadow: var(--shadow-xs);">
                <div style="width: 42px; height: 42px; border-radius: 12px; background: #fef3c7; display: flex; align-items: center; justify-content: center; color: #d97706; font-size: 1.2rem; flex-shrink: 0;">
                    <i class="fas fa-clock"></i>
                </div>
                <div style="flex: 1; min-width: 0;">
                    <p style="margin: 0; font-size: 0.85rem; font-weight: 700; color: #b45309; text-transform: uppercase; letter-spacing: 0.5px;">Awaiting Front Desk Settlement</p>
                    <p style="margin: 4px 0 0 0; font-size: 0.84rem; color: #374151; line-height: 1.4;">
                        Your renewal request for <strong><?php echo htmlspecialchars($pending_request['plan_name']); ?></strong> (₱<?php echo number_format($pending_request['plan_price'], 2); ?>) has been received. Please settle your payment in cash at the front desk to activate your pass.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Annual Membership Tier Card -->
        <div class="card fade-up" style="background:#ffffff; border:1px solid rgba(62,130,65,0.2); border-radius:18px; padding:1.1rem 1.25rem; margin-bottom:1.25rem; box-shadow:var(--shadow-xs);">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:40px; height:40px; border-radius:12px; background:<?php echo $is_official ? '#dcfce7' : '#f1f5f9'; ?>; color:<?php echo $is_official ? '#15803d' : '#64748b'; ?>; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
                        <i class="fas <?php echo $is_official ? 'fa-award' : 'fa-id-card'; ?>"></i>
                    </div>
                    <div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="font-weight:800; font-size:0.95rem; color:var(--text-primary);">Annual Membership</span>
                            <?php if ($is_official): ?>
                                <span style="font-size:0.68rem; background:#dcfce7; color:#15803d; padding:2px 8px; border-radius:12px; font-weight:700;">ACTIVE</span>
                            <?php else: ?>
                                <span style="font-size:0.68rem; background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:12px; font-weight:700;">INACTIVE</span>
                            <?php endif; ?>
                        </div>
                        <p style="margin:2px 0 0 0; font-size:0.75rem; color:var(--text-secondary);">
                            <?php if ($is_official): ?>
                                Valid until <strong><?php echo date('M d, Y', strtotime($ann_expiry)); ?></strong> (<?php echo max(0, $ann_days_left); ?> days left) • Member rates active
                            <?php else: ?>
                                Unlock member rates (₱40/day, ₱750/mo) with the ₱1,000 annual fee
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                <div>
                    <?php if ($is_official && $ann_can_renew): ?>
                        <button type="button" class="btn" style="background:#f59e0b; color:#fff; font-size:0.78rem; font-weight:700; padding:6px 14px; border-radius:10px; border:none; cursor:pointer;" onclick="openRenewModal('membership')">
                            <i class="fas fa-arrows-rotate"></i> Renew (₱1,000)
                        </button>
                    <?php elseif (!$is_official): ?>
                        <button type="button" class="btn" style="background:#2d6a4f; color:#fff; font-size:0.78rem; font-weight:700; padding:7px 15px; border-radius:10px; border:none; cursor:pointer;" onclick="openRenewModal('membership')">
                            <i class="fas fa-medal"></i> Become a Member
                        </button>
                    <?php else: ?>
                        <span style="font-size:0.78rem; color:#16a34a; font-weight:700;"><i class="fas fa-circle-check"></i> In Good Standing</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="stats-row fade-up fade-up-d1">
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-value"><?php echo $attendance_count; ?></div>
                <div class="stat-label">Visits (30d)</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon <?php echo (!$member['has_active_gym_pass']) ? 'red' : 'gold'; ?>">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="stat-value">
                    <?php if (!$member['has_active_gym_pass']): ?>
                        <span style="color:#ff6b6b; font-size:0.95rem; font-weight:700;">No Pass</span>
                    <?php elseif ($days_left === 0): ?>
                        Today
                    <?php else: ?>
                        <?php echo $days_left; ?>d
                    <?php endif; ?>
                </div>
                <div class="stat-label">Pass Remaining</div>
            </div>
        </div>

        <!-- Gym Access Pass Card -->
        <div class="subscription-card fade-up fade-up-d2">
            <div class="sub-header">
                <div>
                    <p class="section-title"><i class="fas fa-dumbbell"></i> Gym Floor Access Pass</p>
                    <p class="plan-name"><?php echo htmlspecialchars($member['plan_name'] ?: 'No Active Gym Pass'); ?></p>
                </div>
                <?php if (!empty($member['has_active_gym_pass'])): ?>
                    <span class="badge badge-active"><i class="fas fa-circle" style="font-size:0.45rem;"></i> Pass Active</span>
                <?php else: ?>
                    <span class="badge badge-expired"><i class="fas fa-circle-xmark" style="font-size:0.65rem;"></i> No Access</span>
                <?php endif; ?>
            </div>

            <?php if (!empty($member['has_active_gym_pass']) && $days_left !== null): ?>
            <div class="progress-wrapper">
                <div class="progress-bar-track">
                    <div class="progress-bar-fill" style="width:<?php echo $progress; ?>%"></div>
                </div>
                <div class="progress-labels">
                    <span><?php echo $progress; ?>% elapsed</span>
                    <span><?php echo max(0, $days_left); ?> days remaining</span>
                </div>
            </div>
            <?php else: ?>
            <div style="background:#fef3c7; border:1px solid #fde68a; border-radius:12px; padding:10px 14px; margin:10px 0 14px; font-size:0.8rem; color:#92400e; line-height:1.45;">
                <i class="fas fa-circle-info" style="color:#d97706; margin-right:4px;"></i>
                <?php if ($is_official): ?>
                    <strong>You are an Official Member!</strong> To access the workout floor, purchase a Daily Pass (₱40) or Monthly Pass (₱750) at your discounted member rate.
                <?php else: ?>
                    <strong>No Active Gym Pass:</strong> Purchase a Daily Pass (₱50) or pay the Annual Membership Fee to unlock member rates.
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="sub-meta">
                <div class="sub-meta-item">
                    <p>Pass Expiration</p>
                    <p><?php 
                        if (!empty($member['expiry_date'])) {
                            $has_time = (!empty($member['duration_minutes']) && $member['duration_minutes'] > 0) || (date('H:i:s', strtotime($member['expiry_date'])) !== '00:00:00');
                            echo date($has_time ? 'M d, Y h:i A' : 'M d, Y', strtotime($member['expiry_date']));
                        } else {
                            echo 'None';
                        }
                    ?></p>
                </div>
                <div class="sub-meta-item" style="text-align:right;">
                    <p>Last Payment</p>
                    <p><?php echo $last_payment ? '₱' . number_format($last_payment['amount'], 2) : '—'; ?></p>
                </div>
            </div>

            <div style="margin-top:14px; padding-top:12px; border-top:1px dashed rgba(82,183,136,0.25);">
                <?php if (empty($member['has_active_gym_pass'])): ?>
                    <button type="button" class="renew-btn" onclick="openRenewModal('pass')">
                        <i class="fas fa-plus-circle"></i> Get Gym Access Pass
                    </button>
                <?php elseif ($days_left !== null && $days_left <= 7): ?>
                    <button type="button" class="renew-btn" onclick="openRenewModal('pass')">
                        <i class="fas fa-arrows-rotate"></i> Extend Gym Pass
                    </button>
                <?php else: ?>
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-size:0.78rem; color:var(--text-muted);">
                            <i class="fas fa-circle-check" style="color:#2d6a4f;"></i> Gym pass in good standing
                        </span>
                        <button type="button" style="background:transparent; border:none; color:var(--palmas-primary); font-weight:700; font-size:0.8rem; cursor:pointer; padding:4px;" onclick="openRenewModal('pass')">
                            Extend Pass <i class="fas fa-chevron-right" style="font-size:0.7rem;"></i>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Digital Pass & Quick Actions -->
        <div class="card fade-up fade-up-d3" style="background:linear-gradient(135deg, rgba(45,106,79,0.08) 0%, rgba(82,183,136,0.04) 100%); border:1px solid rgba(82,183,136,0.25); border-radius:18px; padding:1.2rem; margin-bottom:1.25rem;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:38px; height:38px; border-radius:10px; background:#d8f3dc; color:#1b4332; display:flex; align-items:center; justify-content:center; font-size:1.1rem;">
                        <i class="fas fa-qrcode"></i>
                    </div>
                    <div>
                        <p style="margin:0; font-weight:700; font-size:0.95rem; color:var(--text-primary);">Entrance Digital Pass</p>
                        <p style="margin:0; font-size:0.75rem; color:var(--text-secondary);">
                            <?php echo !empty($member['has_active_gym_pass']) ? 'Pass active • Ready for turnstile scanner' : 'Pass inactive • Requires active gym pass'; ?>
                        </p>
                    </div>
                </div>
                <a href="id-card.php" class="btn" style="background:#2d6a4f; color:#fff; font-size:0.8rem; font-weight:700; padding:7px 15px; border-radius:10px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                    <i class="fas fa-id-card"></i> View Pass
                </a>
            </div>
        </div>

        <!-- Gym Info -->
        <div class="list-card fade-up fade-up-d4">
            <div class="list-card-header">
                <p class="section-title"><i class="fas fa-dumbbell"></i> Club Information</p>
            </div>
            <div style="padding: 0.5rem 1.5rem 1rem;">
                <div class="info-list">
                    <div class="info-row">
                        <div class="info-icon"><i class="fas fa-clock"></i></div>
                        <div class="info-text">
                            <p>Operating Hours</p>
                            <p>Mon – Sat: 6:00 AM – 9:00 PM</p>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon"><i class="fas fa-location-dot"></i></div>
                        <div class="info-text">
                            <p>Location</p>
                            <p><?php echo htmlspecialchars($app_settings['gym_address'] ?? "Palma's Elite Gym Building, Ground Floor"); ?></p>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon"><i class="fas fa-phone"></i></div>
                        <div class="info-text">
                            <p>Contact</p>
                            <p><?php echo htmlspecialchars($app_settings['gym_phone'] ?? '(02) 8123-4567'); ?></p>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon"><i class="fas fa-envelope"></i></div>
                        <div class="info-text">
                            <p>Email</p>
                            <p><?php echo htmlspecialchars($app_settings['gym_email'] ?? 'support@palmaselite.com'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </main>

    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="index.php" class="nav-item active">
            <i class="fas fa-house"></i><span>Home</span>
        </a>
        <a href="id-card.php" class="nav-item">
            <i class="fas fa-id-card"></i><span>E-ID</span>
        </a>
        <a href="attendance.php" class="nav-item">
            <i class="fas fa-calendar-check"></i><span>Visits</span>
        </a>
        <a href="payments.php" class="nav-item">
            <i class="fas fa-receipt"></i><span>Payments</span>
        </a>
        <a href="profile.php" class="nav-item">
            <i class="fas fa-user"></i><span>Profile</span>
        </a>
    </nav>
</div>

<!-- ── Toast Container ── -->
<div class="toast-container" id="toast-container"></div>

<!-- ── Notification Overlay + Drawer ── -->
<div class="notif-overlay" id="notif-overlay" onclick="closeNotifDrawer()"></div>
<div class="notif-drawer" id="notif-drawer">
    <div class="notif-drawer-header">
        <h3><i class="fas fa-bell" style="color:var(--palmas-primary);"></i> Notifications</h3>
        <button class="notif-drawer-close" onclick="closeNotifDrawer()"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="notif-drawer-list" id="notif-drawer-list">
        <div class="notif-drawer-empty">
            <i class="fas fa-bell-slash"></i>
            <p>No notifications yet</p>
        </div>
    </div>
</div>

<!-- ── Renewal Modal ── -->
<div class="notif-overlay" id="renew-overlay" onclick="closeRenewModal()"></div>
<div class="notif-drawer" id="renew-drawer" style="width:min(400px,100vw);">

    <!-- Step 1: Plan Selection -->
    <div id="renew-step-1">
        <div class="notif-drawer-header">
            <h3 id="renew-modal-title"><i class="fas fa-rotate-right" style="color:var(--palmas-primary);"></i> Choose Plan / Pass</h3>
            <button class="notif-drawer-close" onclick="closeRenewModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <div style="padding:1rem; flex:1; overflow-y:auto;">
            <p id="renew-modal-desc" style="font-size:0.82rem; color:var(--text-secondary); margin-bottom:1rem; line-height:1.5;">
                Select a workout pass or annual membership to activate your access.
            </p>

            <!-- 🌟 NON-MEMBER UPSELL BANNER 🌟 -->
            <?php if (!$is_official): ?>
            <div class="member-upsell-card" style="background:linear-gradient(135deg, #133e29 0%, #1f5e3e 100%); color:#fff; border-radius:14px; padding:13px 15px; margin-bottom:14px; border:1.5px solid #4ade80; box-shadow:0 4px 14px rgba(19,62,41,0.2);">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:6px;">
                    <div style="font-weight:800; font-size:0.92rem; color:#fef08a; display:flex; align-items:center; gap:6px;">
                        <i class="fas fa-crown" style="color:#facc15;"></i> Gusto mo bang magpa-member?
                    </div>
                    <span style="font-size:0.65rem; background:rgba(250,204,21,0.2); color:#fef08a; border:1px solid #facc15; padding:1px 7px; border-radius:10px; font-weight:800;">
                        DISCOUNTS
                    </span>
                </div>
                <p style="font-size:0.78rem; color:#e2e8f0; margin:5px 0 9px; line-height:1.4;">
                    Mag-avail ng <strong>₱1,000 Annual Membership Fee</strong> (valid 1 year) para makuha ang discounted member rates sa lahat ng passes:
                </p>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:5px; font-size:0.73rem; margin-bottom:10px;">
                    <div style="background:rgba(255,255,255,0.08); border-radius:8px; padding:5px 7px;">
                        <span style="color:#86efac; font-weight:700;">Monthly:</span> <strong>₱750</strong> <span style="text-decoration:line-through; opacity:0.6; font-size:0.68rem;">₱850</span>
                    </div>
                    <div style="background:rgba(255,255,255,0.08); border-radius:8px; padding:5px 7px;">
                        <span style="color:#86efac; font-weight:700;">Yearly:</span> <strong>₱7,500</strong>
                    </div>
                    <div style="background:rgba(255,255,255,0.08); border-radius:8px; padding:5px 7px;">
                        <span style="color:#86efac; font-weight:700;">2nd Flr:</span> <strong>₱40</strong> <span style="text-decoration:line-through; opacity:0.6; font-size:0.68rem;">₱50</span>
                    </div>
                    <div style="background:rgba(255,255,255,0.08); border-radius:8px; padding:5px 7px;">
                        <span style="color:#86efac; font-weight:700;">Ground & 2nd:</span> <strong>₱50</strong> <span style="text-decoration:line-through; opacity:0.6; font-size:0.68rem;">₱60</span>
                    </div>
                </div>
                <button type="button" onclick="chooseAnnualMembershipFee()" style="width:100%; background:#facc15; color:#14532d; font-weight:800; font-size:0.8rem; border:none; padding:8px 12px; border-radius:8px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px;">
                    <i class="fas fa-sparkles"></i> Piliin ang Annual Membership (₱1,000)
                </button>
            </div>
            <?php endif; ?>

            <!-- Plan Cards Grouped by Section -->
            <div id="plan-list" style="display:flex; flex-direction:column; gap:1.1rem; margin-bottom:1.25rem;">
                <?php 
                $sec_fee = array_filter($all_plans, fn($p) => ($p['plan_category'] ?? '') === 'membership_fee');
                $sec_member = array_filter($all_plans, fn($p) => ($p['plan_category'] ?? '') === 'member_pass');
                $sec_non_member = array_filter($all_plans, fn($p) => ($p['plan_category'] ?? '') === 'non_member_pass');
                $sec_promo = array_filter($all_plans, fn($p) => ($p['plan_category'] ?? '') === 'test_promo');

                // Find paired non-member IDs for savings comparison
                $nm_monthly_id = null;
                $nm_2nd_id = null;
                $nm_both_id = null;
                foreach ($sec_non_member as $nm) {
                    if ((int)$nm['duration_months'] === 1) $nm_monthly_id = (int)$nm['id'];
                    elseif (stripos($nm['name'], '2nd Floor Only') !== false) $nm_2nd_id = (int)$nm['id'];
                    elseif (stripos($nm['name'], 'Ground') !== false) $nm_both_id = (int)$nm['id'];
                }

                if ($is_official) {
                    $sections = [
                        ['id' => 'sec-member', 'title' => '⚡ Member Discounted Passes', 'desc' => 'Eksklusibong presyo para sa iyong active Official Membership', 'items' => $sec_member, 'is_member_rate' => true],
                        ['id' => 'sec-fee', 'title' => '🏅 Annual Membership Renewal', 'desc' => 'Available kapag 30 araw o mas kaunti na lamang ang natitira', 'items' => $sec_fee, 'is_member_rate' => false],
                    ];
                } else {
                    $sections = [
                        ['id' => 'sec-fee', 'title' => '🏅 Official Member Package (Recommended)', 'desc' => 'Annual eligibility fee (₱1,000 / valid 1 year) — unlocks all member discounts below!', 'items' => $sec_fee, 'is_member_rate' => false],
                        ['id' => 'sec-non-member', 'title' => '⚡ Non-Member Passes (Standard Rates)', 'desc' => 'Tunay na presyo na walang annual membership fee na kailangan', 'items' => $sec_non_member, 'is_member_rate' => false],
                        ['id' => 'sec-member', 'title' => '🔒 Member Discounted Passes (Official Members Only)', 'desc' => 'Diskwentong presyo para sa may ₱1,000 Annual Fee', 'items' => $sec_member, 'is_member_rate' => true],
                    ];
                }
                if (!empty($sec_promo)) {
                    $sections[] = ['id' => 'sec-promo', 'title' => '🧪 Test Passes', 'desc' => 'Available for system testing and promotions', 'items' => $sec_promo, 'is_member_rate' => false];
                }
                ?>

                <?php foreach ($sections as $sec): if (empty($sec['items'])) continue; ?>
                <div>
                    <div style="margin-bottom:0.45rem; padding-bottom:3px; border-bottom:1px solid rgba(62,130,65,0.2); display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <div style="font-size:0.8rem; font-weight:800; color:var(--palmas-primary); text-transform:uppercase; letter-spacing:0.4px;">
                                <?php echo $sec['title']; ?>
                            </div>
                            <div style="font-size:0.7rem; color:var(--text-muted);">
                                <?php echo $sec['desc']; ?>
                            </div>
                        </div>
                        <?php if (!empty($sec['is_member_rate']) && !$is_official): ?>
                        <span style="font-size:0.62rem; background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; padding:1px 6px; border-radius:10px; font-weight:700;">
                            <i class="fas fa-lock"></i> Locked
                        </span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:0.5rem; margin-top:0.35rem;">
                        <?php foreach($sec['items'] as $plan): 
                            $is_daily = (intval($plan['duration_minutes'] ?? 0) === 1440);
                            $dur_text = $is_daily ? 'Same-Day Pass (Expires 11:59 PM today)' : ($plan['duration_months'] > 0 ? ($plan['duration_months'] . ' month' . ($plan['duration_months'] > 1 ? 's' : '') . ' Full Access') : ($plan['duration_minutes'] . ' mins'));
                            $is_locked = (!empty($plan['plan_category']) && $plan['plan_category'] === 'member_pass' && !$is_official);

                            // Pair equivalent non-member ID and savings label
                            $equiv_nm_id = 0;
                            $savings_note = '';
                            if ($plan['plan_category'] === 'member_pass') {
                                if (stripos($plan['name'], 'Monthly') !== false) {
                                    $equiv_nm_id = $nm_monthly_id;
                                    $savings_note = 'Tipid ₱100 kumpara sa ₱850';
                                } elseif (stripos($plan['name'], 'Yearly') !== false) {
                                    $savings_note = 'Tipid ₱1,500 kumpara sa 12x ₱750';
                                } elseif (stripos($plan['name'], '2nd Floor Only') !== false) {
                                    $equiv_nm_id = $nm_2nd_id;
                                    $savings_note = 'Tipid ₱10 kumpara sa ₱50';
                                } elseif (stripos($plan['name'], 'Ground') !== false) {
                                    $equiv_nm_id = $nm_both_id;
                                    $savings_note = 'Tipid ₱10 kumpara sa ₱60';
                                }
                            }
                        ?>
                        <label 
                            class="plan-option <?php echo $is_locked ? 'plan-locked' : ''; ?>" 
                            for="plan-<?php echo $plan['id']; ?>"
                            id="plan-label-<?php echo $plan['id']; ?>"
                            <?php if ($is_locked): ?>
                            onclick="handlePortalMemberPassClick(event, <?php echo (int)$plan['id']; ?>, '<?php echo addslashes($plan['name']); ?>', <?php echo floatval($plan['price']); ?>, <?php echo (int)$equiv_nm_id; ?>)"
                            <?php endif; ?>
                            style="<?php echo $is_locked ? 'border:1.5px dashed #86efac; background:#fafffd;' : ''; ?>"
                        >
                            <input type="radio" name="plan" id="plan-<?php echo $plan['id']; ?>"
                                   value="<?php echo $plan['id']; ?>"
                                   data-price="<?php echo $plan['price']; ?>"
                                   data-name="<?php echo htmlspecialchars($plan['name']); ?>"
                                   data-months="<?php echo $plan['duration_months']; ?>"
                                   data-minutes="<?php echo $plan['duration_minutes'] ?? 0; ?>"
                                   data-category="<?php echo htmlspecialchars($plan['plan_category'] ?? ''); ?>"
                                   data-equiv-id="<?php echo (int)$equiv_nm_id; ?>"
                                   <?php if ($is_locked) echo 'style="pointer-events:none;"'; ?>>
                            <div class="plan-card-inner">
                                <div style="flex:1;">
                                    <div class="plan-card-name" style="font-weight:700; font-size:0.92rem; color:var(--text-main); display:flex; align-items:center; gap:5px; flex-wrap:wrap;">
                                        <?php echo htmlspecialchars($plan['name']); ?>
                                        <?php if ($is_locked): ?>
                                            <span style="font-size:0.62rem; background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; padding:1px 5px; border-radius:6px; font-weight:800;">
                                                <i class="fas fa-lock"></i> Member Only
                                            </span>
                                        <?php elseif (($plan['plan_category'] ?? '') === 'member_pass'): ?>
                                            <span style="font-size:0.62rem; background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:1px 5px; border-radius:6px; font-weight:800;">
                                                <i class="fas fa-check"></i> Member Rate
                                            </span>
                                        <?php elseif (($plan['plan_category'] ?? '') === 'membership_fee'): ?>
                                            <span style="font-size:0.62rem; background:#fef08a; color:#854d0e; border:1px solid #facc15; padding:1px 5px; border-radius:6px; font-weight:800;">
                                                ⭐ Sulit Choice
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="plan-card-duration" style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;"><?php echo $dur_text; ?></div>
                                    <?php if (!empty($savings_note)): ?>
                                    <div style="font-size:0.7rem; color:#15803d; font-weight:700; margin-top:3px;">
                                        <i class="fas fa-tag" style="font-size:0.65rem; margin-right:3px;"></i><?php echo $savings_note; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if(!empty($plan['benefits'])): ?>
                                    <div class="plan-card-benefits" style="font-size:0.72rem; color:#52b788; margin-top:4px; line-height:1.35; padding-top:4px; border-top:1px dashed rgba(82,183,136,0.25);">
                                        <i class="fas fa-circle-info" style="font-size:0.68rem; margin-right:3px;"></i><?php echo htmlspecialchars($plan['benefits']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="plan-card-price" style="font-weight:800; font-size:1.1rem; color:var(--palmas-primary);">₱<?php echo number_format($plan['price'], 2); ?></div>
                            </div>
                            <span class="plan-check"><i class="fas fa-circle-check"></i></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <button class="renew-btn" onclick="goToPayment()">
                <i class="fas fa-arrow-right"></i> Continue to Payment
            </button>
        </div>
    </div>

    <!-- Step 2: Payment Method -->
    <div id="renew-step-2" style="display:none;">
        <div class="notif-drawer-header">
            <h3><i class="fas fa-credit-card" style="color:var(--palmas-primary);"></i> Payment Method</h3>
            <button class="notif-drawer-close" onclick="closeRenewModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <div style="padding:1rem; flex:1; overflow-y:auto;">

            <!-- Selected Plan Summary -->
            <div id="plan-summary-box" style="background:#f0fdf4; border:1px solid rgba(62,130,65,0.2); border-radius:14px; padding:0.9rem 1rem; margin-bottom:1.25rem; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <p style="font-size:0.68rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:2px; font-weight:700;">Selected Plan</p>
                    <p id="sum-plan-name" style="font-weight:700; color:var(--text-primary); font-size:0.95rem;"></p>
                    <p id="sum-plan-dur" style="font-size:0.75rem; color:var(--text-secondary);"></p>
                </div>
                <p id="sum-plan-price" style="font-family:'Outfit',sans-serif; font-size:1.3rem; font-weight:800; color:var(--palmas-primary);"></p>
            </div>

            <?php if (is_paymongo_test_mode()): ?>
            <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; padding:0.75rem 1rem; margin-bottom:1.2rem; display:flex; align-items:center; gap:0.65rem; font-size:0.8rem; color:#1e40af;">
                <i class="fas fa-circle-info" style="color:#2563eb; font-size:1.15rem; flex-shrink:0;"></i>
                <div>
                    <strong style="display:block; font-weight:700; color:#1d4ed8;">DEMO / PRACTICE MODE ACTIVE</strong>
                    Simulated payment environment • No real money will be charged.
                </div>
            </div>
            <?php endif; ?>

            <p style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:0.75rem;">Choose Payment Method</p>

            <!-- Payment Instructions Box -->
            <div id="payment-instructions" style="display:none;"></div>

            <!-- Payment Method Options -->
            <div style="display:flex; flex-direction:column; gap:0.65rem; margin-bottom:1.25rem;">
                <!-- 1. GCash Payment -->
                <label class="pay-option" for="pm-GCash">
                    <input type="radio" name="paymethod" id="pm-GCash" value="GCash" checked onchange="toggleRef('GCash')"> 
                    <div style="width:40px;height:40px;border-radius:10px;overflow:hidden;background:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,125,254,0.25);padding:2px;flex-shrink:0;">
                        <img src="../assets/images/gcash-logo.png" alt="GCash" style="width:100%;height:100%;object-fit:contain;">
                    </div>
                    <div style="flex:1;">
                        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                            <span style="font-weight:700; font-size:0.9rem; color:var(--text-primary);">GCash</span>
                            <span style="font-size:0.65rem; background:#dcfce7; color:#15803d; padding:2px 7px; border-radius:12px; font-weight:800; letter-spacing:0.4px;">⚡ INSTANT ACTIVATION</span>
                        </div>
                        <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px;">Direct GCash E-Wallet payment</div>
                    </div>
                    <span class="pay-check"><i class="fas fa-circle-check"></i></span>
                </label>

                <!-- 2. Maya Payment -->
                <label class="pay-option" for="pm-Maya">
                    <input type="radio" name="paymethod" id="pm-Maya" value="Maya" onchange="toggleRef('Maya')"> 
                    <div style="width:40px;height:40px;border-radius:10px;overflow:hidden;background:#000;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,214,100,0.2);padding:3px;flex-shrink:0;">
                        <img src="../assets/images/maya-logo.png" alt="Maya" style="width:100%;height:100%;object-fit:contain;">
                    </div>
                    <div style="flex:1;">
                        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                            <span style="font-weight:700; font-size:0.9rem; color:var(--text-primary);">Maya</span>
                            <span style="font-size:0.65rem; background:#dcfce7; color:#15803d; padding:2px 7px; border-radius:12px; font-weight:800; letter-spacing:0.4px;">⚡ INSTANT ACTIVATION</span>
                        </div>
                        <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px;">Direct Maya E-Wallet payment</div>
                    </div>
                    <span class="pay-check"><i class="fas fa-circle-check"></i></span>
                </label>

                <!-- 3. Front Desk Cash -->
                <label class="pay-option" for="pm-Cash">
                    <input type="radio" name="paymethod" id="pm-Cash" value="Cash" onchange="toggleRef('Cash')"> 
                    <div style="width:40px;height:40px;border-radius:10px;background:rgba(62,130,65,0.12);color:#2d6a4f;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div style="flex:1;">
                        <div style="font-weight:700; font-size:0.9rem; color:var(--text-primary);">Cash (Front Desk)</div>
                        <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px;">Pay over the counter at gym front desk</div>
                    </div>
                    <span class="pay-check"><i class="fas fa-circle-check"></i></span>
                </label>
            </div>

            <div style="display:flex; gap:0.65rem;">
                <button style="flex:1;padding:0.85rem;background:var(--surface-soft);border:1px solid var(--border);border-radius:12px;color:var(--text-secondary);font-weight:600;font-size:0.85rem;cursor:pointer;font-family:'Outfit',sans-serif;" onclick="backToPlan()">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <button class="renew-btn" style="flex:2;" id="confirm-renew-btn" onclick="submitRenewal()">
                    <i class="fas fa-bolt"></i> Continue to Payment
                </button>
            </div>
        </div>
    </div>

    <!-- Step 3: Success -->
    <div id="renew-step-3" style="display:none; flex:1; flex-direction:column; align-items:center; justify-content:center; padding:2rem; text-align:center; gap:1rem;">
        <div style="width:80px;height:80px;border-radius:50%;background:#f0fdf4;border:2px solid rgba(62,130,65,0.3);display:flex;align-items:center;justify-content:center;font-size:2rem;color:var(--palmas-primary);">
            <i class="fas fa-circle-check"></i>
        </div>
        <h3 style="font-family:'Outfit',sans-serif;font-size:1.25rem;font-weight:800;color:var(--text-primary);">Renewal Successful!</h3>
        <p id="renew-success-msg" style="font-size:0.84rem;color:var(--text-secondary);line-height:1.6;"></p>
        <button class="renew-btn" style="margin-top:0.5rem;" onclick="finishRenewal()">
            <i class="fas fa-house"></i> Back to Dashboard
        </button>
    </div>
</div>

<style>
.plan-option {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    background: #ffffff;
    border: 1.5px solid var(--border);
    border-radius: 14px;
    padding: 0.9rem 1rem;
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
    box-shadow: var(--shadow-xs);
}
.plan-option:hover { border-color: rgba(62,130,65,0.4); background: #fcfdfc; }
.plan-option input[type=radio] { position: absolute; opacity: 0; }
.plan-option:has(input:checked) {
    border-color: var(--palmas-primary);
    background: #f0fdf4;
    box-shadow: 0 0 0 1px var(--palmas-primary);
}
.plan-card-inner { display:flex; align-items:center; flex:1; gap:0.5rem; }
.plan-card-name { font-weight:700; font-size:0.92rem; color:var(--text-primary); }
.plan-card-duration { font-size:0.75rem; color:var(--text-secondary); margin-top:2px; }
.plan-card-benefits { font-size:0.72rem; color:var(--text-muted); margin-top:3px; }
.plan-card-price { font-family:'Outfit',sans-serif; font-size:1.15rem; font-weight:800; color:var(--palmas-primary); white-space:nowrap; }
.plan-check { color:transparent; font-size:1.1rem; transition:color 0.2s; flex-shrink:0; }
.plan-option:has(input:checked) .plan-check { color:var(--palmas-primary); }

.pay-option {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    background: #ffffff;
    border: 1.5px solid var(--border);
    border-radius: 14px;
    padding: 0.85rem 1rem;
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
    box-shadow: var(--shadow-xs);
}
.pay-option:hover { border-color: rgba(62,130,65,0.4); background: #fcfdfc; }
.pay-option input[type=radio] { position: absolute; opacity: 0; }
.pay-option:has(input:checked) {
    border-color: var(--palmas-primary);
    background: #f0fdf4;
    box-shadow: 0 0 0 1px var(--palmas-primary);
}
.pay-check { color:transparent; font-size:1rem; margin-left:auto; transition:color 0.2s; }
.pay-option:has(input:checked) .pay-check { color:var(--palmas-primary); }

.renew-btn {
    width: 100%;
    min-height: 48px;
    padding: 0.9rem;
    background: linear-gradient(135deg, var(--palmas-primary), var(--palmas-dark));
    color: #ffffff;
    border: none;
    border-radius: 12px;
    font-size: 0.92rem;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Outfit', sans-serif;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    box-shadow: 0 4px 14px rgba(62, 130, 65, 0.25);
}
.renew-btn:hover {
    background: linear-gradient(135deg, #48944b, #245840);
    box-shadow: 0 6px 18px rgba(62, 130, 65, 0.35);
    transform: translateY(-1px);
}
.renew-btn:disabled { opacity:0.5; cursor:not-allowed; transform:none; }
</style>

<script>
// ── Notification System ─────────────────────────────────────────
let allNotifications = [];
let toastShownIds    = new Set();

function openNotifDrawer() {
    document.getElementById('notif-overlay').classList.add('open');
    document.getElementById('notif-drawer').classList.add('open');
    const badge = document.getElementById('notif-badge');
    badge.style.display = 'none';
    badge.textContent = '0';
    renderDrawer();
}

function closeNotifDrawer() {
    document.getElementById('notif-overlay').classList.remove('open');
    document.getElementById('notif-drawer').classList.remove('open');
}

function renderDrawer() {
    const list = document.getElementById('notif-drawer-list');
    if (!allNotifications.length) {
        list.innerHTML = `<div class="notif-drawer-empty"><i class="fas fa-bell-slash"></i><p>All caught up! No notifications.</p></div>`;
        return;
    }
    list.innerHTML = allNotifications.map(n => {
        const isExpiry = (n.type === 'danger' && (n.id.includes('expiry') || n.id.includes('db_')) && canRenew);
        return `<div class="notif-item ${n.unread ? 'unread' : ''} ${n.type}" style="${isExpiry ? 'cursor:pointer;' : ''}" ${isExpiry ? 'onclick="openRenewFromNotif()"' : ''}>
            <div class="notif-item-icon"><i class="fas ${n.icon}"></i></div>
            <div style="flex:1;">
                <div class="notif-item-title">${n.title}</div>
                <div class="notif-item-msg">${n.message}</div>
                ${isExpiry ? '<div style="font-size:0.72rem;color:var(--palmas-primary, #3e8241);font-weight:700;margin-top:5px;"><i class="fas fa-rotate-right"></i> Tap to Renew Now</div>' : ''}
                <span class="notif-item-time"><i class="far fa-clock"></i> ${n.time}</span>
            </div>
            ${isExpiry ? '<i class="fas fa-chevron-right" style="color:#4d6b5e;font-size:0.8rem;flex-shrink:0;"></i>' : ''}
        </div>`;
    }).join('');
}

function showToast(n) {
    if (toastShownIds.has(n.id)) return;
    toastShownIds.add(n.id);
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${n.type}`;
    const isExpiry = (n.type === 'danger' && canRenew);
    toast.innerHTML = `
        <div class="toast-icon"><i class="fas ${n.icon}"></i></div>
        <div class="toast-body">
            <div class="toast-title">${n.title}</div>
            <div class="toast-msg">${n.message}</div>
            ${isExpiry ? '<div style="font-size:0.7rem;color:var(--palmas-primary, #3e8241);font-weight:700;margin-top:4px;cursor:pointer;" onclick="openRenewModal()"><i class="fas fa-rotate-right"></i> Renew Now</div>' : ''}
        </div>
        <button class="toast-close" onclick="dismissToast(this.parentElement)"><i class="fas fa-xmark"></i></button>`;
    container.appendChild(toast);
    setTimeout(() => dismissToast(toast), 8000);
}

function dismissToast(toast) {
    if (!toast || toast.classList.contains('hiding')) return;
    toast.classList.add('hiding');
    setTimeout(() => toast.remove(), 350);
}

async function fetchNotifications() {
    try {
        const res  = await fetch('get_notifications.php');
        const data = await res.json();
        allNotifications = data.notifications || [];
        const badge = document.getElementById('notif-badge');
        if (data.unread > 0) {
            badge.textContent = data.unread > 9 ? '9+' : data.unread;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
        allNotifications.slice(0, 2).forEach((n, i) => setTimeout(() => showToast(n), i * 800));
    } catch(e) { console.warn('Notification fetch failed:', e); }
}

fetchNotifications();
setInterval(fetchNotifications, 60000);

// ── Renewal Modal ────────────────────────────────────────────────
const hasPendingRenewal = <?php echo $pending_request ? 'true' : 'false'; ?>;
const canRenewPass = <?php echo $can_renew_pass ? 'true' : 'false'; ?>;
const cannotRenewPassReason = <?php echo json_encode($cannot_renew_pass_reason); ?>;
const isOfficialMember = <?php echo $is_official ? 'true' : 'false'; ?>;

function openRenewModal(mode = 'all') {
    closeNotifDrawer();
    const titleEl = document.getElementById('renew-modal-title');
    const descEl  = document.getElementById('renew-modal-desc');

    if (mode === 'membership') {
        if (titleEl) titleEl.innerHTML = '<i class="fas fa-medal" style="color:var(--palmas-primary);"></i> Annual Membership Fee';
        if (descEl) descEl.textContent = 'Pay or renew your annual membership to unlock discounted member rates.';
    } else if (mode === 'pass') {
        if (!canRenewPass) {
            alert(cannotRenewPassReason || "Aktibo pa ang iyong kasalukuyang gym pass.");
            return;
        }
        if (titleEl) titleEl.innerHTML = '<i class="fas fa-dumbbell" style="color:var(--palmas-primary);"></i> Get Gym Access Pass';
        if (descEl) descEl.textContent = 'Select a daily, monthly, or yearly workout pass for gym floor access.';
    } else {
        if (titleEl) titleEl.innerHTML = '<i class="fas fa-rotate-right" style="color:var(--palmas-primary);"></i> Membership & Passes';
        if (descEl) descEl.textContent = 'Select a workout pass or annual membership to activate your access.';
    }

    if (hasPendingRenewal) {
        alert("You already have a pending renewal request under review by the admin.");
        return;
    }

    // Reset to step 1
    document.getElementById('renew-step-1').style.display = 'flex';
    document.getElementById('renew-step-1').style.flexDirection = 'column';
    document.getElementById('renew-step-2').style.display = 'none';
    document.getElementById('renew-step-3').style.display = 'none';

    // Auto-select first allowed matching option
    const radioInputs = document.querySelectorAll('input[name=plan]');
    let selected = false;
    radioInputs.forEach(r => {
        r.checked = false;
        const isFee = (r.dataset.category === 'membership_fee' || r.dataset.name.includes('Annual'));
        const isMemberPass = (r.dataset.category === 'member_pass');
        const isAllowed = !isMemberPass || isOfficialMember;

        if (isAllowed && !selected) {
            if (mode === 'membership' && isFee) {
                r.checked = true;
                selected = true;
            } else if (mode === 'pass' && !isFee) {
                r.checked = true;
                selected = true;
            }
        }
    });
    if (!selected && radioInputs.length > 0) {
        for (const r of radioInputs) {
            if (r.dataset.category !== 'member_pass' || isOfficialMember) {
                r.checked = true;
                selected = true;
                break;
            }
        }
    }

    const defaultPm = document.getElementById('pm-GCash');
    if (defaultPm) defaultPm.checked = true;
    document.getElementById('renew-overlay').classList.add('open');
    document.getElementById('renew-drawer').classList.add('open');
}

function openRenewFromNotif() {
    openRenewModal('pass');
}

function closeRenewModal() {
    document.getElementById('renew-overlay').classList.remove('open');
    document.getElementById('renew-drawer').classList.remove('open');
}

function goToPayment() {
    const selected = document.querySelector('input[name=plan]:checked');
    if (!selected) {
        alert('Please select a membership plan.');
        return;
    }

    // Guard: Prevent non-members from submitting locked member passes
    if (selected.dataset.category === 'member_pass' && !isOfficialMember) {
        showMemberUpsellPrompt(selected.dataset.name, selected.dataset.price, selected.dataset.equivId);
        return;
    }

    // Populate summary
    document.getElementById('sum-plan-name').textContent  = selected.dataset.name;
    const isDaily = parseInt(selected.dataset.minutes || 0) === 1440;
    const durMonths = parseInt(selected.dataset.months || 0);
    const durMins = parseInt(selected.dataset.minutes || 0);
    let durText = isDaily ? 'Same-Day Pass (Until 11:59 PM)' : (durMonths > 0 ? (durMonths + ' month' + (durMonths > 1 ? 's' : '')) : (durMins + ' min(s)'));
    document.getElementById('sum-plan-dur').textContent   = durText;
    document.getElementById('sum-plan-price').textContent = '₱' + parseFloat(selected.dataset.price).toLocaleString('en-PH', {minimumFractionDigits:0});
    document.getElementById('renew-step-1').style.display = 'none';
    document.getElementById('renew-step-2').style.display = 'flex';
    document.getElementById('renew-step-2').style.flexDirection = 'column';
    const checkedMethod = document.querySelector('input[name=paymethod]:checked');
    toggleRef(checkedMethod ? checkedMethod.value : 'GCash');
}

function backToPlan() {
    document.getElementById('renew-step-2').style.display = 'none';
    document.getElementById('renew-step-1').style.display = 'flex';
    document.getElementById('renew-step-1').style.flexDirection = 'column';
}

function toggleRef(method) {
    const instructions = document.getElementById('payment-instructions');
    const confirmBtn = document.getElementById('confirm-renew-btn');
    if (!instructions) return;

    if (method === 'GCash') {
        if (confirmBtn) confirmBtn.innerHTML = '<i class="fas fa-bolt"></i> Continue to GCash Payment';
        instructions.innerHTML = `
            <div style="background:#eef6ff; border:1px solid #bfdbfe; border-radius:14px; padding:0.9rem 1rem; margin-bottom:1.1rem;">
                <div style="display:flex; align-items:center; gap:0.5rem; color:#1d4ed8; font-weight:700; font-size:0.86rem; margin-bottom:0.3rem;">
                    <i class="fas fa-bolt" style="color:#007dfe;"></i> Instant GCash Checkout
                </div>
                <p style="font-size:0.8rem; color:#1e40af; line-height:1.45; margin:0;">
                    Pay directly using your <strong>GCash</strong> E-Wallet account. Your gym pass will activate immediately after completing payment.
                </p>
            </div>`;
        instructions.style.display = 'block';
    } else if (method === 'Maya') {
        if (confirmBtn) confirmBtn.innerHTML = '<i class="fas fa-bolt"></i> Continue to Maya Payment';
        instructions.innerHTML = `
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:14px; padding:0.9rem 1rem; margin-bottom:1.1rem;">
                <div style="display:flex; align-items:center; gap:0.5rem; color:#15803d; font-weight:700; font-size:0.86rem; margin-bottom:0.3rem;">
                    <i class="fas fa-bolt" style="color:#00d664;"></i> Instant Maya Checkout
                </div>
                <p style="font-size:0.8rem; color:#166534; line-height:1.45; margin:0;">
                    Pay directly using your <strong>Maya</strong> E-Wallet account. Your gym pass will activate immediately after completing payment.
                </p>
            </div>`;
        instructions.style.display = 'block';
    } else {
        if (confirmBtn) confirmBtn.innerHTML = '<i class="fas fa-check-circle"></i> Submit Front Desk Request';
        instructions.innerHTML = `
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:14px; padding:0.9rem 1rem; margin-bottom:1.1rem;">
                <div style="display:flex; align-items:center; gap:0.5rem; color:#166534; font-weight:700; font-size:0.86rem; margin-bottom:0.3rem;">
                    <i class="fas fa-hand-holding-dollar"></i> Front Desk Cash Payment
                </div>
                <p style="font-size:0.8rem; color:#14532d; line-height:1.45; margin:0;">
                    Please settle your membership payment in <strong>Cash</strong> at the gym front desk upon your visit. Our staff will confirm your payment and activate your pass.
                </p>
            </div>`;
        instructions.style.display = 'block';
    }
}

async function submitRenewal() {
    const plan   = document.querySelector('input[name=plan]:checked');
    const method = document.querySelector('input[name=paymethod]:checked');

    if (!plan)   { alert('Please select a membership plan.'); return; }
    if (!method) { alert('Please choose a payment method.'); return; }

    // ── Online Checkout Flow (GCash / Maya) ──
    if (method.value === 'GCash' || method.value === 'Maya' || method.value === 'PayMongo') {
        const btn = document.getElementById('confirm-renew-btn');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Opening ' + method.value + ' Window...';
        btn.disabled  = true;

        try {
            const res = await fetch('../api/payments/create', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    plan_id: plan.value,
                    payment_method: method.value
                })
            });
            const data = await res.json();
            if (data.success && data.checkout_url) {
                window.location.href = data.checkout_url;
                return;
            } else {
                alert(data.message || 'Unable to open payment window. Please try again.');
                btn.innerHTML = '<i class="fas fa-bolt"></i> Continue to ' + method.value + ' Payment';
                btn.disabled  = false;
                return;
            }
        } catch(e) {
            alert('A connection error occurred. Please try again.');
            btn.innerHTML = '<i class="fas fa-bolt"></i> Continue to ' + method.value + ' Payment';
            btn.disabled  = false;
        }
    }

    // ── Front Desk Cash Request ──
    const btn = document.getElementById('confirm-renew-btn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
    btn.disabled  = true;

    const fd = new FormData();
    fd.append('plan_id',        plan.value);
    fd.append('payment_method', 'Cash');
    fd.append('reference_no',   '');
    fd.append('csrf_token',     document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

    try {
        const res  = await fetch('renew_request.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            document.getElementById('renew-step-2').style.display = 'none';
            document.getElementById('renew-step-3').style.display = 'flex';
            document.getElementById('renew-success-msg').textContent = data.message;
        } else {
            alert(data.message || 'Renewal request failed. Please try again.');
            btn.innerHTML = '<i class="fas fa-check-circle"></i> Submit Front Desk Request';
            btn.disabled  = false;
        }
    } catch(e) {
        alert('Network error. Please try again.');
        btn.innerHTML = '<i class="fas fa-check-circle"></i> Submit Front Desk Request';
        btn.disabled  = false;
    }
}

function finishRenewal() {
    closeRenewModal();
    location.reload(); // Refresh dashboard to show updated status
}

/* ── INTERACTIVE PORTAL UPSELL PROMPT LOGIC ── */
let portalPendingEquivId = null;

function chooseAnnualMembershipFee() {
    const feeRadio = document.querySelector('input[name=plan][data-category="membership_fee"]') || document.querySelector('input[name=plan][value="8"]');
    if (feeRadio) {
        feeRadio.checked = true;
        const parentLabel = feeRadio.closest('.plan-option');
        if (parentLabel) {
            parentLabel.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
}

function handlePortalMemberPassClick(e, planId, planName, planPrice, equivId) {
    e.preventDefault();
    e.stopPropagation();
    showMemberUpsellPrompt(planName, planPrice, equivId);
}

function showMemberUpsellPrompt(planName, planPrice, equivId) {
    portalPendingEquivId = equivId;
    const modal = document.getElementById('portal-upsell-modal');
    const desc = document.getElementById('portal-upsell-desc');
    const btnNonMem = document.getElementById('btn-portal-nonmem');

    if (desc) {
        desc.innerHTML = `Ang <strong>${escapeHtml(planName)}</strong> (₱${parseFloat(planPrice).toFixed(2)}) ay para lamang sa mga <strong>Official Members</strong>.<br><br>Gusto mo bang magpa-member ngayon sa halagang <strong>₱1,000 Annual Membership Fee</strong> (valid 1 year) para makuha ang presyong ito?`;
    }

    if (btnNonMem) {
        btnNonMem.style.display = (equivId && equivId > 0) ? 'flex' : 'none';
    }

    if (modal) modal.style.display = 'flex';
}

function confirmPortalAvailAnnualFee() {
    closePortalUpsellModal();
    chooseAnnualMembershipFee();
}

function switchToPortalNonMemberEquivalent() {
    closePortalUpsellModal();
    if (portalPendingEquivId) {
        const equivRadio = document.querySelector(`input[name=plan][value="${portalPendingEquivId}"]`);
        if (equivRadio) {
            equivRadio.checked = true;
            const parentLabel = equivRadio.closest('.plan-option');
            if (parentLabel) {
                parentLabel.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    }
}

function closePortalUpsellModal() {
    const modal = document.getElementById('portal-upsell-modal');
    if (modal) modal.style.display = 'none';
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

<!-- 🌟 INTERACTIVE PORTAL UPSELL PROMPT MODAL 🌟 -->
<div id="portal-upsell-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.68); z-index:99999; align-items:center; justify-content:center; padding:16px; backdrop-filter:blur(3px);">
  <div style="background:#fff; border-radius:20px; max-width:390px; width:100%; padding:24px 20px; box-shadow:0 24px 48px rgba(0,0,0,0.25); text-align:center;">
    <div style="width:58px; height:58px; border-radius:50%; background:#fef9c3; color:#ca8a04; display:flex; align-items:center; justify-content:center; font-size:1.6rem; margin:0 auto 12px; border:2px solid #facc15;">
      <i class="fas fa-crown"></i>
    </div>
    <h3 style="font-size:1.18rem; font-weight:800; color:#1e293b; margin:0 0 8px;">Gusto mo bang magpa-member?</h3>
    <p id="portal-upsell-desc" style="font-size:0.84rem; color:#475569; line-height:1.5; margin:0 0 16px;">
      Ang planong napili mo ay <strong>Exclusive Member Discount</strong>. Kailangan ng <strong>₱1,000 Annual Membership Fee</strong> para makuha ang discounted rate na ito!
    </p>
    <div style="display:flex; flex-direction:column; gap:8px;">
      <button type="button" onclick="confirmPortalAvailAnnualFee()" style="background:linear-gradient(135deg, #15803d, #166534); color:#fff; border:none; padding:11px 16px; border-radius:12px; font-weight:800; font-size:0.88rem; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; box-shadow:0 4px 12px rgba(22,101,52,0.25);">
        <i class="fas fa-sparkles" style="color:#fde047;"></i> Oo, I-avail ang Annual Fee (₱1,000)
      </button>
      <button type="button" id="btn-portal-nonmem" onclick="switchToPortalNonMemberEquivalent()" style="background:#f8fafc; color:#334155; border:1.5px solid #cbd5e1; padding:10px 16px; border-radius:12px; font-weight:700; font-size:0.84rem; cursor:pointer; display:none; align-items:center; justify-content:center; gap:6px;">
        <i class="fas fa-arrow-right-arrow-left"></i> Piliin ang Regular Non-Member Rate
      </button>
      <button type="button" onclick="closePortalUpsellModal()" style="background:transparent; color:#64748b; border:none; padding:7px; font-size:0.8rem; font-weight:600; cursor:pointer;">
        Bumalik sa Pagpili
      </button>
    </div>
  </div>
</div>

<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js');
    });
}
</script>
</body>
</html>
