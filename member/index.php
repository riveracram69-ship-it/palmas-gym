<?php
require_once __DIR__ . '/auth.php';
require_member_login();

$member = current_member($pdo);

if (!$member) {
    header('Location: logout.php');
    exit;
}

// Subscription countdown
$days_left = null;
$expired   = true;
$progress  = 0;

if ($member['expiry_date']) {
    $expiry_ts  = strtotime($member['expiry_date']);
    $now_ts     = time();
    $diff       = $expiry_ts - $now_ts;
    $days_left  = (int) ceil($diff / 86400);
    $expired    = ($diff <= 0);

    // estimate progress based on a 30-day plan window
    $plan_days  = 30;
    $elapsed    = $plan_days - max(0, $days_left);
    $is_minute_promo = (!empty($member['duration_minutes']) && $member['duration_minutes'] > 0);
}

$can_renew = true;
$cannot_renew_reason = '';
if (!empty($member['expiry_date']) && !$expired) {
    $diff_sec = $expiry_ts - $now_ts;
    $threshold_sec = (!empty($is_minute_promo)) ? 300 : (3 * 86400);
    if ($diff_sec > $threshold_sec) {
        $can_renew = false;
        $rem_text = (!empty($is_minute_promo) || $diff_sec < 86400) ? ceil($diff_sec / 60) . ' minuto(s)' : ceil($diff_sec / 86400) . ' araw';
        $rule_text = (!empty($is_minute_promo)) ? '5 minuto bago mag-expire' : '3 araw bago mag-expire';
        $cannot_renew_reason = "Hindi pa maaaring mag-renew! Aktibo pa ang iyong kasalukuyang plano ({$rem_text} natitira). Maaari lamang mag-renew kapag expired na o {$rule_text}.";
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
                <?php if (!empty($member['is_active'])): ?>
                    <span class="badge badge-active"><i class="fas fa-circle" style="font-size:0.45rem;"></i> Active</span>
                <?php else: ?>
                    <span class="badge badge-expired"><i class="fas fa-circle-xmark" style="font-size:0.7rem;"></i> Expired</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($pending_request): ?>
            <!-- Pending Front Desk Renewal Banner -->
            <div class="card pending-renewal-banner fade-up" style="background: #fffbeb; border: 1.5px dashed #f59e0b; border-radius: 16px; padding: 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 1rem; box-shadow: var(--shadow-xs);">
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
                <?php if (!empty($member['is_active'])): ?>
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
                    <p><?php 
                        if ($member['expiry_date']) {
                            $has_time = (!empty($member['duration_minutes']) && $member['duration_minutes'] > 0) || (date('H:i:s', strtotime($member['expiry_date'])) !== '00:00:00');
                            echo date($has_time ? 'M d, Y h:i A' : 'M d, Y', strtotime($member['expiry_date']));
                        } else {
                            echo '—';
                        }
                    ?></p>
                </div>
                <div class="sub-meta-item" style="text-align:right;">
                    <p>Last Payment</p>
                    <p><?php echo $last_payment ? '₱' . number_format($last_payment['amount'], 2) : '—'; ?></p>
                </div>
            </div>
        </div>

        <!-- Digital Pass & Quick Actions -->
        <div class="card fade-up fade-up-d3" style="background:linear-gradient(135deg, rgba(45,106,79,0.08) 0%, rgba(82,183,136,0.04) 100%); border:1px solid rgba(82,183,136,0.25); border-radius:18px; padding:1.2rem; margin-bottom:1.25rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:38px; height:38px; border-radius:10px; background:#d8f3dc; color:#1b4332; display:flex; align-items:center; justify-content:center; font-size:1.1rem;">
                        <i class="fas fa-qrcode"></i>
                    </div>
                    <div>
                        <p style="margin:0; font-weight:700; font-size:0.95rem; color:var(--text-primary);">Entrance Pass</p>
                        <p style="margin:0; font-size:0.75rem; color:var(--text-secondary);">Ready for turnstile scanner</p>
                    </div>
                </div>
                <a href="id-card.php" class="btn" style="background:#2d6a4f; color:#fff; font-size:0.8rem; font-weight:700; padding:7px 15px; border-radius:10px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                    <i class="fas fa-id-card"></i> View Pass
                </a>
            </div>

            <?php if ($expired || ($days_left !== null && $days_left <= 7)): ?>
            <div style="padding-top:10px; border-top:1px dashed rgba(82,183,136,0.25); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <span style="font-size:0.82rem; font-weight:700; color:<?php echo $expired ? '#ef4444' : '#b45309'; ?>;">
                    <i class="fas <?php echo $expired ? 'fa-circle-xmark' : 'fa-clock'; ?>"></i>
                    <?php echo $expired ? 'Pass is currently expired' : "Expires in {$days_left} day" . ($days_left > 1 ? 's' : ''); ?>
                </span>
                <button type="button" class="renew-btn" style="padding:7px 16px; font-size:0.82rem; border-radius:10px;" onclick="openRenewModal()">
                    <i class="fas fa-arrows-rotate"></i> Renew Pass Now
                </button>
            </div>
            <?php else: ?>
            <div style="padding-top:8px; border-top:1px dashed rgba(82,183,136,0.25); display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:0.78rem; color:var(--text-muted);">
                    <i class="fas fa-circle-check" style="color:#2d6a4f;"></i> Subscription in good standing
                </span>
                <button type="button" style="background:transparent; border:none; color:var(--palmas-primary); font-weight:700; font-size:0.8rem; cursor:pointer; padding:4px;" onclick="openRenewModal()">
                    Extend Pass <i class="fas fa-chevron-right" style="font-size:0.7rem;"></i>
                </button>
            </div>
            <?php endif; ?>
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
            <h3><i class="fas fa-rotate-right" style="color:var(--palmas-primary);"></i> Renew Membership</h3>
            <button class="notif-drawer-close" onclick="closeRenewModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <div style="padding:1rem; flex:1; overflow-y:auto;">
            <p style="font-size:0.82rem; color:var(--text-secondary); margin-bottom:1rem; line-height:1.5;">
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
                <!-- Automated Online Payment -->
                <label class="pay-option" for="pm-PayMongo" style="border:1.5px solid #6366f1; background:rgba(99,102,241,0.03);">
                    <input type="radio" name="paymethod" id="pm-PayMongo" value="PayMongo" checked onchange="toggleRef('PayMongo')"> 
                    <div style="width:40px;height:40px;border-radius:10px;background:rgba(99,102,241,0.12);color:#4f46e5;display:flex;align-items:center;justify-content:center;font-size:1.1rem;">
                        <i class="fas fa-credit-card"></i>
                    </div>
                    <div style="flex:1;">
                        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                            <span style="font-weight:700; font-size:0.9rem; color:var(--text-primary);">Online Payment (GCash, Maya, Cards)</span>
                            <span style="font-size:0.65rem; background:#dcfce7; color:#15803d; padding:2px 7px; border-radius:12px; font-weight:800; letter-spacing:0.4px;">⚡ INSTANT ACTIVATION</span>
                        </div>
                        <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px;">Automated instant pass activation upon payment</div>
                    </div>
                    <span class="pay-check"><i class="fas fa-circle-check"></i></span>
                </label>

                <!-- Front Desk Cash -->
                <label class="pay-option" for="pm-Cash">
                    <input type="radio" name="paymethod" id="pm-Cash" value="Cash" onchange="toggleRef('Cash')"> 
                    <div style="width:40px;height:40px;border-radius:10px;background:rgba(62,130,65,0.12);color:#2d6a4f;display:flex;align-items:center;justify-content:center;font-size:1.1rem;">
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
const canRenew = <?php echo $can_renew ? 'true' : 'false'; ?>;
const cannotRenewReason = <?php echo json_encode($cannot_renew_reason); ?>;

function openRenewModal() {
    closeNotifDrawer();
    if (!canRenew) {
        alert(cannotRenewReason || "Hindi pa maaaring mag-renew dahil aktibo pa ang iyong plano.");
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
    const defaultPm = document.getElementById('pm-PayMongo');
    if (defaultPm) defaultPm.checked = true;
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
    toggleRef('PayMongo');
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

    if (method === 'PayMongo') {
        if (confirmBtn) confirmBtn.innerHTML = '<i class="fas fa-bolt"></i> Continue to Payment';
        instructions.innerHTML = `
            <div style="background:#eef2ff; border:1px solid #c7d2fe; border-radius:14px; padding:0.9rem 1rem; margin-bottom:1.1rem;">
                <div style="display:flex; align-items:center; gap:0.5rem; color:#3730a3; font-weight:700; font-size:0.86rem; margin-bottom:0.3rem;">
                    <i class="fas fa-shield-check" style="color:#4f46e5;"></i> Secure Online Checkout
                </div>
                <p style="font-size:0.8rem; color:#4338ca; line-height:1.45; margin:0;">
                    Pay directly using <strong>GCash, Maya, or Debit/Credit Card</strong>. Your gym pass will activate immediately after completing payment.
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

    // ── PayMongo Online Checkout Flow ──
    if (method.value === 'PayMongo') {
        const btn = document.getElementById('confirm-renew-btn');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Opening Payment Window...';
        btn.disabled  = true;

        try {
            const res = await fetch('../api/payments/create', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    plan_id: plan.value,
                    payment_method: 'all'
                })
            });
            const data = await res.json();
            if (data.success && data.checkout_url) {
                window.location.href = data.checkout_url;
                return;
            } else {
                alert(data.message || 'Unable to open payment window. Please try again.');
                btn.innerHTML = '<i class="fas fa-bolt"></i> Continue to Payment';
                btn.disabled  = false;
                return;
            }
        } catch(e) {
            alert('A connection error occurred. Please try again.');
            btn.innerHTML = '<i class="fas fa-bolt"></i> Continue to Payment';
            btn.disabled  = false;
            return;
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
</script>

<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js');
    });
}
</script>
</body>
</html>
