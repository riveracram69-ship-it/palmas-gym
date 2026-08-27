<?php
require_once __DIR__ . '/auth.php';
require_member_login();

$member = current_member($pdo);

if (!$member) {
    header('Location: /gym/member/logout.php');
    exit;
}

// Subscription countdown
$days_left = null;
$expired   = true;
$progress  = 0;

if ($member['expiry_date']) {
    $expiry_ts  = strtotime($member['expiry_date']);
    $today_ts   = strtotime(date('Y-m-d'));
    $diff       = $expiry_ts - $today_ts;
    $days_left  = (int) round($diff / 86400);
    $expired    = ($days_left < 0);

    // estimate progress based on a 30-day plan window
    $plan_days  = 30;
    $elapsed    = $plan_days - max(0, $days_left);
    $progress   = max(0, min(100, round(($elapsed / $plan_days) * 100)));
}
$can_renew = $expired || ($days_left !== null && $days_left <= 7);

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

// Membership plans for renewal modal
$plans = [];
try {
    $plans = $pdo->query("SELECT id, name, price, duration_months, benefits FROM membership_plans ORDER BY price ASC")->fetchAll();
} catch (Exception $e) {}

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
    <link rel="stylesheet" href="/gym/assets/css/member.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <meta name="csrf-token" content="<?php echo get_csrf_token(); ?>">
    <link rel="manifest" href="/gym/member/manifest.json">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Palma's Elite">
    <link rel="apple-touch-icon" href="/gym/assets/images/palmas-logo.png">
</head>
<body>
<div class="mobile-container">

    <!-- Header -->
    <header class="app-header">
        <div class="app-brand">
            <img src="/gym/assets/images/palmas-logo.png" alt="Logo">
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
                    <?php if ($member['photo']): ?>
                        <img src="/gym/<?php echo htmlspecialchars($member['photo']); ?>" alt="Photo">
                    <?php else: ?>
                        <span class="avatar-initial"><?php echo strtoupper(substr($member['full_name'], 0, 1)); ?></span>
                    <?php endif; ?>
                </div>
                <div class="hero-info">
                    <p class="hero-greeting">Welcome back,</p>
                    <h2 class="hero-name"><?php echo htmlspecialchars(strtolower($member['full_name'])); ?></h2>
                    <p class="hero-id"><?php echo htmlspecialchars($member['membership_id']); ?></p>
                </div>
                <?php if ($member['status'] === 'Active' && !$expired): ?>
                    <span class="badge badge-active"><i class="fas fa-circle" style="font-size:0.45rem;"></i> Active</span>
                <?php else: ?>
                    <span class="badge badge-expired"><i class="fas fa-circle-xmark" style="font-size:0.7rem;"></i> Expired</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($pending_request): ?>
            <!-- Pending Renewal Request Banner -->
            <div class="card pending-renewal-banner fade-up" style="background: rgba(243, 156, 18, 0.08); border: 1.5px dashed rgba(243, 156, 18, 0.45); border-radius: 16px; padding: 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 1rem; backdrop-filter: blur(8px);">
                <div style="width: 42px; height: 42px; border-radius: 12px; background: rgba(243, 156, 18, 0.15); display: flex; align-items: center; justify-content: center; color: #f39c12; font-size: 1.2rem; flex-shrink: 0;">
                    <i class="fas fa-spinner fa-spin"></i>
                </div>
                <div style="flex: 1; min-width: 0;">
                    <p style="margin: 0; font-size: 0.85rem; font-weight: 700; color: #f1c40f; text-transform: uppercase; letter-spacing: 0.5px;">Renewal Under Review</p>
                    <p style="margin: 4px 0 0 0; font-size: 0.82rem; color: #e5e8e8; line-height: 1.4;">
                        Your request for <strong><?php echo htmlspecialchars($pending_request['plan_name']); ?></strong> (₱<?php echo number_format($pending_request['plan_price'], 2); ?>) is pending admin approval.
                        <?php if ($pending_request['reference_no']): ?>
                            <br><span style="font-size: 0.72rem; color: #bdc3c7;">Payment Method: <?php echo htmlspecialchars($pending_request['payment_method']); ?> | Ref: <code><?php echo htmlspecialchars($pending_request['reference_no']); ?></code></span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Stats Row -->
        <div class="stats-row fade-up fade-up-d1">
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-value"><?php echo $attendance_count; ?></div>
                <div class="stat-label">Visits (30d)</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon <?php echo $expired ? 'red' : 'gold'; ?>">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <div class="stat-value">
                    <?php if ($expired): ?>
                        <span style="color:#ff6b6b; font-size:1rem;">Expired</span>
                    <?php elseif ($days_left === 0): ?>
                        Today
                    <?php else: ?>
                        <?php echo $days_left; ?>d
                    <?php endif; ?>
                </div>
                <div class="stat-label">Days Left</div>
            </div>
        </div>

        <!-- Subscription Card -->
        <div class="subscription-card fade-up fade-up-d2">
            <div class="sub-header">
                <div>
                    <p class="section-title"><i class="fas fa-award"></i> Membership Plan</p>
                    <p class="plan-name"><?php echo htmlspecialchars($member['plan_name'] ?: 'No Active Plan'); ?></p>
                </div>
                <?php if ($member['status'] === 'Active' && !$expired): ?>
                    <span class="badge badge-active">Active</span>
                <?php else: ?>
                    <span class="badge badge-expired">Expired</span>
                <?php endif; ?>
            </div>

            <?php if (!$expired && $days_left !== null): ?>
            <div class="progress-wrapper">
                <div class="progress-bar-track">
                    <div class="progress-bar-fill" style="width:<?php echo $progress; ?>%"></div>
                </div>
                <div class="progress-labels">
                    <span><?php echo $progress; ?>% elapsed</span>
                    <span><?php echo max(0, $days_left); ?> days remaining</span>
                </div>
            </div>
            <?php endif; ?>

            <div class="sub-meta">
                <div class="sub-meta-item">
                    <p>Expiry Date</p>
                    <p><?php echo $member['expiry_date'] ? date('M d, Y', strtotime($member['expiry_date'])) : '—'; ?></p>
                </div>
                <div class="sub-meta-item" style="text-align:right;">
                    <p>Last Payment</p>
                    <p><?php echo $last_payment ? '₱' . number_format($last_payment['amount'], 2) : '—'; ?></p>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <p class="section-title fade-up fade-up-d3" style="padding:0 0.25rem;"><i class="fas fa-bolt"></i> Quick Access</p>
        <div class="quick-actions fade-up fade-up-d3">
            <a href="id-card.php" class="action-btn">
                <div class="action-btn-icon green"><i class="fas fa-id-card"></i></div>
                <span class="action-btn-label">My E-ID Card</span>
            </a>
            <a href="attendance.php" class="action-btn">
                <div class="action-btn-icon purple"><i class="fas fa-calendar-check"></i></div>
                <span class="action-btn-label">Attendance Log</span>
            </a>
            <a href="payments.php" class="action-btn">
                <div class="action-btn-icon gold"><i class="fas fa-receipt"></i></div>
                <span class="action-btn-label">Payment History</span>
            </a>
            <a href="profile.php" class="action-btn">
                <div class="action-btn-icon blue"><i class="fas fa-user-circle"></i></div>
                <span class="action-btn-label">My Profile</span>
            </a>
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
        <h3><i class="fas fa-bell" style="color:var(--accent-light);"></i> Notifications</h3>
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
            <h3><i class="fas fa-rotate-right" style="color:var(--accent-light);"></i> Renew Membership</h3>
            <button class="notif-drawer-close" onclick="closeRenewModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <div style="padding:1rem; flex:1; overflow-y:auto;">
            <p style="font-size:0.78rem; color:#8faaa0; margin-bottom:1rem; line-height:1.5;">
                Your membership has expired. Choose a plan to reactivate your access.
            </p>

            <!-- Plan Cards -->
            <div id="plan-list" style="display:flex; flex-direction:column; gap:0.65rem; margin-bottom:1.25rem;">
                <?php foreach($plans as $plan): ?>
                <label class="plan-option" for="plan-<?php echo $plan['id']; ?>">
                    <input type="radio" name="plan" id="plan-<?php echo $plan['id']; ?>"
                           value="<?php echo $plan['id']; ?>"
                           data-price="<?php echo $plan['price']; ?>"
                           data-name="<?php echo htmlspecialchars($plan['name']); ?>"
                           data-months="<?php echo $plan['duration_months']; ?>">
                    <div class="plan-card-inner">
                        <div style="flex:1;">
                            <div class="plan-card-name"><?php echo htmlspecialchars($plan['name']); ?></div>
                            <div class="plan-card-duration"><?php echo $plan['duration_months']; ?> month<?php echo $plan['duration_months'] > 1 ? 's' : ''; ?></div>
                            <?php if(!empty($plan['benefits'])): ?>
                            <div class="plan-card-benefits"><?php echo htmlspecialchars($plan['benefits']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="plan-card-price">₱<?php echo number_format($plan['price'], 0); ?></div>
                    </div>
                    <span class="plan-check"><i class="fas fa-circle-check"></i></span>
                </label>
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
            <h3><i class="fas fa-credit-card" style="color:var(--accent-light);"></i> Payment Method</h3>
            <button class="notif-drawer-close" onclick="closeRenewModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <div style="padding:1rem; flex:1; overflow-y:auto;">

            <!-- Selected Plan Summary -->
            <div id="plan-summary-box" style="background:rgba(82,183,136,0.06); border:1px solid rgba(82,183,136,0.15); border-radius:14px; padding:0.9rem 1rem; margin-bottom:1.25rem; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <p style="font-size:0.68rem; color:#8faaa0; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:2px;">Selected Plan</p>
                    <p id="sum-plan-name" style="font-weight:700; color:#f0f7f3; font-size:0.92rem;"></p>
                    <p id="sum-plan-dur" style="font-size:0.72rem; color:#8faaa0;"></p>
                </div>
                <p id="sum-plan-price" style="font-family:'Outfit',sans-serif; font-size:1.3rem; font-weight:800; color:#52b788;"></p>
            </div>

            <p style="font-size:0.72rem; font-weight:700; color:#8faaa0; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:0.75rem;">Choose Payment Method</p>

            <!-- Payment Method Options -->
            <div style="display:flex; flex-direction:column; gap:0.6rem; margin-bottom:1.25rem;">
                <?php
                $pmethods = [
                    ['Cash',          'fa-money-bill-wave', '#52b788', 'rgba(82,183,136,0.1)'],
                    ['GCash',         'fa-mobile-screen',  '#60a5fa', 'rgba(59,130,246,0.1)'],
                    ['Credit Card',   'fa-credit-card',    '#a78bfa', 'rgba(139,92,246,0.1)'],
                    ['Bank Transfer', 'fa-building-columns','#fbbf24','rgba(251,191,36,0.1)'],
                ];
                foreach($pmethods as [$pm, $icon, $clr, $bg]):
                ?>
                <label class="pay-option" for="pm-<?php echo str_replace(' ','-',$pm); ?>">
                    <input type="radio" name="paymethod" id="pm-<?php echo str_replace(' ','-',$pm); ?>" value="<?php echo $pm; ?>" onchange="toggleRef('<?php echo $pm; ?>')"> 
                    <div style="width:38px;height:38px;border-radius:10px;background:<?php echo $bg; ?>;color:<?php echo $clr; ?>;display:flex;align-items:center;justify-content:center;font-size:0.9rem;">
                        <i class="fas <?php echo $icon; ?>"></i>
                    </div>
                    <span style="font-weight:600; font-size:0.88rem; color:#f0f7f3;"><?php echo $pm; ?></span>
                    <span class="pay-check"><i class="fas fa-circle-check"></i></span>
                </label>
                <?php endforeach; ?>
            </div>

            <!-- Reference No (for GCash/Bank/Card) -->
            <div id="ref-group" style="display:none; margin-bottom:1.25rem;">
                <p style="font-size:0.7rem; font-weight:700; color:#8faaa0; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:0.4rem;">Reference / Transaction No.</p>
                <input type="text" id="reference-no" placeholder="e.g. 1234567890"
                    style="width:100%;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:0.8rem 1rem;color:#f0f7f3;font-size:0.88rem;font-family:'Inter',sans-serif;">
            </div>

            <div style="display:flex; gap:0.65rem;">
                <button style="flex:1;padding:0.85rem;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:12px;color:#8faaa0;font-weight:600;font-size:0.85rem;cursor:pointer;font-family:'Outfit',sans-serif;" onclick="backToPlan()">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <button class="renew-btn" style="flex:2;" id="confirm-renew-btn" onclick="submitRenewal()">
                    <i class="fas fa-check-circle"></i> Confirm Renewal
                </button>
            </div>
        </div>
    </div>

    <!-- Step 3: Success -->
    <div id="renew-step-3" style="display:none; flex:1; flex-direction:column; align-items:center; justify-content:center; padding:2rem; text-align:center; gap:1rem;">
        <div style="width:80px;height:80px;border-radius:50%;background:rgba(82,183,136,0.12);border:2px solid rgba(82,183,136,0.3);display:flex;align-items:center;justify-content:center;font-size:2rem;color:#52b788;">
            <i class="fas fa-circle-check"></i>
        </div>
        <h3 style="font-family:'Outfit',sans-serif;font-size:1.25rem;font-weight:800;color:#f0f7f3;">Renewal Successful!</h3>
        <p id="renew-success-msg" style="font-size:0.82rem;color:#8faaa0;line-height:1.6;"></p>
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
    background: rgba(255,255,255,0.02);
    border: 1.5px solid rgba(255,255,255,0.06);
    border-radius: 14px;
    padding: 0.9rem 1rem;
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
}
.plan-option:hover { background: rgba(255,255,255,0.04); }
.plan-option input[type=radio] { position: absolute; opacity: 0; }
.plan-option:has(input:checked) {
    border-color: rgba(82,183,136,0.45);
    background: rgba(82,183,136,0.06);
}
.plan-card-inner { display:flex; align-items:center; flex:1; gap:0.5rem; }
.plan-card-name { font-weight:700; font-size:0.9rem; color:#f0f7f3; }
.plan-card-duration { font-size:0.72rem; color:#8faaa0; margin-top:2px; }
.plan-card-benefits { font-size:0.7rem; color:#4d6b5e; margin-top:3px; }
.plan-card-price { font-family:'Outfit',sans-serif; font-size:1.15rem; font-weight:800; color:#52b788; white-space:nowrap; }
.plan-check { color:rgba(82,183,136,0); font-size:1.1rem; transition:color 0.2s; flex-shrink:0; }
.plan-option:has(input:checked) .plan-check { color:#52b788; }

.pay-option {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    background: rgba(255,255,255,0.02);
    border: 1.5px solid rgba(255,255,255,0.06);
    border-radius: 14px;
    padding: 0.85rem 1rem;
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
}
.pay-option:hover { background: rgba(255,255,255,0.04); }
.pay-option input[type=radio] { position: absolute; opacity: 0; }
.pay-option:has(input:checked) {
    border-color: rgba(82,183,136,0.4);
    background: rgba(82,183,136,0.05);
}
.pay-check { color:rgba(82,183,136,0); font-size:1rem; margin-left:auto; transition:color 0.2s; }
.pay-option:has(input:checked) .pay-check { color:#52b788; }

.renew-btn {
    width:100%;
    padding:0.9rem;
    background:linear-gradient(135deg,#40916c,#2d6a4f);
    color:#fff;
    border:none;
    border-radius:12px;
    font-size:0.9rem;
    font-weight:700;
    cursor:pointer;
    font-family:'Outfit',sans-serif;
    transition:all 0.2s;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:0.5rem;
}
.renew-btn:hover { background:linear-gradient(135deg,#52b788,#40916c); }
.renew-btn:disabled { opacity:0.5; cursor:not-allowed; }
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
                ${isExpiry ? '<div style="font-size:0.72rem;color:#52b788;font-weight:700;margin-top:5px;"><i class="fas fa-rotate-right"></i> Tap to Renew Now</div>' : ''}
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
            ${isExpiry ? '<div style="font-size:0.7rem;color:#52b788;font-weight:700;margin-top:4px;cursor:pointer;" onclick="openRenewModal()"><i class="fas fa-rotate-right"></i> Renew Now</div>' : ''}
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
        const res  = await fetch('/gym/member/get_notifications.php');
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
const canRenew = <?php echo $can_renew ? 'true' : 'false'; ?>;

function openRenewModal() {
    closeNotifDrawer();
    if (!canRenew) {
        alert("Your membership is active and does not require renewal at this time.");
        return;
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
    document.querySelectorAll('input[name=plan]').forEach(r => r.checked = false);
    document.querySelectorAll('input[name=paymethod]').forEach(r => r.checked = false);
    document.getElementById('reference-no').value = '';
    document.getElementById('ref-group').style.display = 'none';
    document.getElementById('renew-overlay').classList.add('open');
    document.getElementById('renew-drawer').classList.add('open');
}

function openRenewFromNotif() {
    openRenewModal();
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
    // Populate summary
    document.getElementById('sum-plan-name').textContent  = selected.dataset.name;
    document.getElementById('sum-plan-dur').textContent   = selected.dataset.months + ' month' + (selected.dataset.months > 1 ? 's' : '');
    document.getElementById('sum-plan-price').textContent = '₱' + parseFloat(selected.dataset.price).toLocaleString('en-PH', {minimumFractionDigits:0});
    document.getElementById('renew-step-1').style.display = 'none';
    document.getElementById('renew-step-2').style.display = 'flex';
    document.getElementById('renew-step-2').style.flexDirection = 'column';
}

function backToPlan() {
    document.getElementById('renew-step-2').style.display = 'none';
    document.getElementById('renew-step-1').style.display = 'flex';
    document.getElementById('renew-step-1').style.flexDirection = 'column';
}

function toggleRef(method) {
    const nonCash = ['GCash', 'Credit Card', 'Bank Transfer'];
    document.getElementById('ref-group').style.display = nonCash.includes(method) ? 'block' : 'none';
}

async function submitRenewal() {
    const plan   = document.querySelector('input[name=plan]:checked');
    const method = document.querySelector('input[name=paymethod]:checked');
    const ref    = document.getElementById('reference-no').value.trim();

    if (!plan)   { alert('Please select a plan.'); return; }
    if (!method) { alert('Please choose a payment method.'); return; }
    if (['GCash','Credit Card','Bank Transfer'].includes(method.value) && !ref) {
        alert('Please enter your reference/transaction number.'); return;
    }

    const btn = document.getElementById('confirm-renew-btn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    btn.disabled  = true;

    const fd = new FormData();
    fd.append('plan_id',        plan.value);
    fd.append('payment_method', method.value);
    fd.append('reference_no',   ref);
    fd.append('csrf_token',     document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

    try {
        const res  = await fetch('/gym/member/renew_request.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            document.getElementById('renew-step-2').style.display = 'none';
            document.getElementById('renew-step-3').style.display = 'flex';
            document.getElementById('renew-success-msg').textContent = data.message;
        } else {
            alert(data.message || 'Renewal failed. Please try again.');
            btn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Renewal';
            btn.disabled  = false;
        }
    } catch(e) {
        alert('Network error. Please try again.');
        btn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Renewal';
        btn.disabled  = false;
    }
}

function finishRenewal() {
    closeRenewModal();
    location.reload(); // Refresh dashboard to show updated status
}
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
