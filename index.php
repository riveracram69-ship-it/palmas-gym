<?php
/**
 * Streamlined Executive Dashboard
 * Palma's Elite Gym Management Portal
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/logger.php';
require_once __DIR__ . '/config/email.php';
require_once __DIR__ . '/config/settings.php';

require_login();

$page_title = 'Admin Dashboard';
include 'includes/header.php';
include 'includes/sidebar.php';

$user = current_user();
$is_admin = is_admin();
$max_capacity = intval($app_settings['max_capacity'] ?? 50);

// Global filter presets: today | week | month
$filter_preset = $_GET['filter_preset'] ?? 'month';

// Metrics initialization
$total_members          = 0;
$active_members         = 0;
$inactive_members       = 0;
$expired_members        = 0;
$daily_attendance       = 0;
$monthly_attendance     = 0;
$currently_inside       = 0;
$monthly_revenue        = 0;
$total_earnings         = 0;
$pending_registrations_cnt = 0;
$expiring_this_week_cnt = 0;

$today_attendance_list  = [];
$overdue_renewals       = [];
$top_active_members     = [];

try {
    if (isset($pdo) && $pdo) {
        // Auto-close stale unclosed attendance sessions so "Currently Inside" is always accurate
        sync_attendance_auto_checkout($pdo);

        // ── 1. Master KPI Calculations ──────────────────────────────────────────
        $total_members    = (int)$pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
        $pending_registrations_cnt = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE account_status = 'Pending'")->fetchColumn();
        $active_members   = (int)$pdo->query("SELECT COUNT(DISTINCT member_id) FROM subscriptions WHERE expiry_date >= CURDATE()")->fetchColumn();
        $expired_members  = (int)$pdo->query("SELECT COUNT(DISTINCT member_id) FROM subscriptions WHERE expiry_date < CURDATE() AND member_id NOT IN (SELECT member_id FROM subscriptions WHERE expiry_date >= CURDATE())")->fetchColumn();
        $inactive_members = max(0, $total_members - ($active_members + $expired_members));

        // Attendance counts (distinct unique visitors)
        $daily_attendance   = (int)$pdo->query("SELECT COUNT(DISTINCT member_id) FROM attendance WHERE date = CURDATE()")->fetchColumn();
        $monthly_attendance = (int)$pdo->query("SELECT COUNT(DISTINCT CONCAT(member_id, '_', date)) FROM attendance WHERE MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())")->fetchColumn();
        $currently_inside   = (int)$pdo->query("SELECT COUNT(DISTINCT member_id) FROM attendance WHERE date = CURDATE() AND time_out IS NULL")->fetchColumn();

        if ($is_admin) {
            $monthly_revenue    = (float)($pdo->query("SELECT SUM(amount) FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())")->fetchColumn() ?: 0);
            $monthly_expenses   = (float)($pdo->query("SELECT SUM(amount) FROM expenses WHERE MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())")->fetchColumn() ?: 0);
            $monthly_net_income = $monthly_revenue - $monthly_expenses;
            $total_earnings     = (float)($pdo->query("SELECT SUM(amount) FROM payments")->fetchColumn() ?: 0);
        } else {
            $expiring_this_week_cnt = (int)$pdo->query("SELECT COUNT(DISTINCT member_id) FROM subscriptions WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
        }

        // ── 2. Today's Attendance Logs ─────────────────────────────────────────
        $stmt_att = $pdo->query("
            SELECT a.id, a.date, a.time_in, a.time_out, m.id AS member_id, m.full_name, m.membership_id, m.photo,
                   m.annual_membership_expiry,
                   COALESCE(sub.plan_name, 'No Plan') AS plan_name,
                   COALESCE(sub.floor_access, 'all') AS floor_access,
                   sub.plan_category
            FROM attendance a
            JOIN members m ON m.id = a.member_id
            LEFT JOIN (
                SELECT s.member_id, p.name AS plan_name, p.floor_access, p.plan_category
                FROM subscriptions s
                JOIN (
                    SELECT member_id, MAX(id) AS latest_sub_id
                    FROM subscriptions
                    GROUP BY member_id
                ) latest ON s.id = latest.latest_sub_id
                LEFT JOIN membership_plans p ON p.id = s.plan_id
            ) sub ON sub.member_id = m.id
            WHERE a.date = CURDATE()
            ORDER BY a.time_in DESC
        ");
        $today_attendance_list = $stmt_att ? $stmt_att->fetchAll(PDO::FETCH_ASSOC) : [];

        // ── 3. Overdue Renewals (Recurring Members Only, Excludes 1-Day Walk-ins) ─
        $stmt_od = $pdo->query("
            SELECT m.id, m.full_name, m.membership_id, m.contact_number, m.email, m.photo,
                   m.annual_membership_expiry,
                   s.expiry_date, p.id as plan_id, p.name as plan_name, p.price as plan_price,
                   p.duration_months, p.duration_minutes, p.plan_category,
                   DATEDIFF(CURDATE(), s.expiry_date) as overdue_days
            FROM subscriptions s
            JOIN (
                SELECT member_id, MAX(id) AS latest_sub_id
                FROM subscriptions
                GROUP BY member_id
            ) latest ON s.id = latest.latest_sub_id
            JOIN members m ON m.id = s.member_id
            JOIN membership_plans p ON p.id = s.plan_id
            WHERE s.expiry_date < CURDATE()
              AND (p.duration_months > 0 OR p.duration_minutes > 1440)
              AND p.plan_category != 'membership_fee'
              AND s.member_id NOT IN (SELECT member_id FROM subscriptions WHERE expiry_date >= CURDATE())
            ORDER BY s.expiry_date DESC
            LIMIT 50
        ");
        $overdue_renewals = $stmt_od ? $stmt_od->fetchAll(PDO::FETCH_ASSOC) : [];

        // Active plans for quick-renew modal
        $active_plans_stmt = $pdo->query("SELECT id, name, price, duration_months, duration_minutes, plan_category FROM membership_plans WHERE is_active = 1 ORDER BY (plan_category = 'membership_fee') DESC, (plan_category = 'member_pass') DESC, price ASC");
        $quick_renew_plans = $active_plans_stmt ? $active_plans_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        // ── 4. Top Active Members (Loyalty Champions — Active Cycle) ─────────
        // Only the current cycle (Month) is needed for initial dashboard render.
        // Other timeframes and metrics are loaded on-demand via admin_dashboard_ajax.php.
        $leaderboard_sql = "
            SELECT m.id, m.full_name, m.membership_id, m.photo,
                   COALESCE(
                       (SELECT p2.name 
                        FROM subscriptions s2 
                        JOIN membership_plans p2 ON p2.id = s2.plan_id 
                        WHERE s2.member_id = m.id 
                          AND p2.plan_category != 'membership_fee' 
                        ORDER BY (s2.expiry_date >= CURDATE()) DESC, s2.id DESC 
                        LIMIT 1),
                       'Standard'
                   ) as plan_name,
                   COUNT(a.id) as visit_count
            FROM members m
            JOIN attendance a ON a.member_id = m.id
            WHERE YEAR(a.date) = YEAR(CURDATE()) AND MONTH(a.date) = MONTH(CURDATE())
            GROUP BY m.id, m.full_name, m.membership_id, m.photo
            HAVING visit_count > 0
            ORDER BY visit_count DESC, m.full_name ASC
            LIMIT 5
        ";
        $stmt_top_month = $pdo->query($leaderboard_sql);
        $top_active_month = $stmt_top_month ? $stmt_top_month->fetchAll(PDO::FETCH_ASSOC) : [];

        // ── 5. Server-side Pre-rendered Live Activity Feed ──────────────────────
        $server_live_feed = [];
        try {
            // A. Check-ins & Check-outs (Last 12)
            $stmt_feed_att = $pdo->query("
                SELECT a.id, a.date, a.time_in, a.time_out, m.full_name, m.membership_id, m.photo
                FROM attendance a
                JOIN members m ON m.id = a.member_id
                ORDER BY a.date DESC, a.time_in DESC
                LIMIT 12
            ");
            if ($stmt_feed_att) {
                foreach ($stmt_feed_att->fetchAll(PDO::FETCH_ASSOC) as $att) {
                    $ts = strtotime($att['date'] . ' ' . $att['time_in']);
                    $server_live_feed[] = [
                        'type'          => 'checkin',
                        'timestamp'     => $ts,
                        'time_formatted'=> date('h:i A', $ts),
                        'date_formatted'=> date('M d', $ts),
                        'title'         => htmlspecialchars($att['full_name']) . ' checked in',
                        'description'   => 'Scanned ID ' . htmlspecialchars($att['membership_id']) . ' at reception.',
                        'icon'          => 'fa-qrcode',
                        'color'         => '#38bdf8',
                        'bg'            => 'rgba(56, 189, 248, 0.12)',
                        'badge'         => 'Check-in',
                    ];
                    if (!empty($att['time_out'])) {
                        $out = strtotime($att['date'] . ' ' . $att['time_out']);
                        $server_live_feed[] = [
                            'type'          => 'checkout',
                            'timestamp'     => $out,
                            'time_formatted'=> date('h:i A', $out),
                            'date_formatted'=> date('M d', $out),
                            'title'         => htmlspecialchars($att['full_name']) . ' checked out',
                            'description'   => 'Completed gym session.',
                            'icon'          => 'fa-door-open',
                            'color'         => '#94a3b8',
                            'bg'            => 'rgba(148, 163, 184, 0.12)',
                            'badge'         => 'Check-out',
                        ];
                    }
                }
            }

            // B. Payments (Recent 10)
            if (is_admin()) {
                $stmt_feed_pay = $pdo->query("
                    SELECT p.id, p.payment_date, p.created_at, p.amount, p.payment_method, m.full_name, m.membership_id
                    FROM payments p
                    JOIN members m ON m.id = p.member_id
                    ORDER BY p.created_at DESC
                    LIMIT 10
                ");
                if ($stmt_feed_pay) {
                    foreach ($stmt_feed_pay->fetchAll(PDO::FETCH_ASSOC) as $pay) {
                        $ts = strtotime($pay['created_at'] ?: $pay['payment_date']);
                        $server_live_feed[] = [
                            'type'          => 'payment',
                            'timestamp'     => $ts,
                            'time_formatted'=> date('h:i A', $ts),
                            'date_formatted'=> date('M d', $ts),
                            'title'         => htmlspecialchars($pay['full_name']) . ' made a payment',
                            'description'   => 'Paid ₱' . number_format($pay['amount'], 2) . ' via ' . htmlspecialchars($pay['payment_method']) . '.',
                            'icon'          => 'fa-money-bill-wave',
                            'color'         => '#52b788',
                            'bg'            => 'rgba(82, 183, 136, 0.12)',
                            'badge'         => 'Payment',
                        ];
                    }
                }
            }

            // C. Renewals & Subscriptions (Recent 8)
            $stmt_feed_sub = $pdo->query("
                SELECT s.id, s.start_date, s.created_at, m.full_name, p.name as plan_name
                FROM subscriptions s
                JOIN members m ON m.id = s.member_id
                JOIN membership_plans p ON p.id = s.plan_id
                ORDER BY s.created_at DESC
                LIMIT 8
            ");
            if ($stmt_feed_sub) {
                foreach ($stmt_feed_sub->fetchAll(PDO::FETCH_ASSOC) as $sub) {
                    $ts = strtotime($sub['created_at'] ?: $sub['start_date']);
                    $server_live_feed[] = [
                        'type'          => 'renewal',
                        'timestamp'     => $ts,
                        'time_formatted'=> date('h:i A', $ts),
                        'date_formatted'=> date('M d', $ts),
                        'title'         => htmlspecialchars($sub['full_name']) . ' renewed membership',
                        'description'   => 'Activated ' . htmlspecialchars($sub['plan_name']) . ' plan.',
                        'icon'          => 'fa-arrows-rotate',
                        'color'         => '#eab308',
                        'bg'            => 'rgba(234, 179, 8, 0.12)',
                        'badge'         => 'Renewal',
                    ];
                }
            }

            // D. Registrations (Recent 8)
            $stmt_feed_reg = $pdo->query("
                SELECT id, full_name, membership_id, created_at
                FROM members
                ORDER BY created_at DESC
                LIMIT 8
            ");
            if ($stmt_feed_reg) {
                foreach ($stmt_feed_reg->fetchAll(PDO::FETCH_ASSOC) as $m_reg) {
                    $ts = strtotime($m_reg['created_at']);
                    $server_live_feed[] = [
                        'type'          => 'registration',
                        'timestamp'     => $ts,
                        'time_formatted'=> date('h:i A', $ts),
                        'date_formatted'=> date('M d', $ts),
                        'title'         => htmlspecialchars($m_reg['full_name']) . ' registered as a new member',
                        'description'   => 'Assigned Membership ID: ' . htmlspecialchars($m_reg['membership_id']) . '.',
                        'icon'          => 'fa-user-plus',
                        'color'         => '#c084fc',
                        'bg'            => 'rgba(192, 132, 252, 0.12)',
                        'badge'         => 'Registration',
                    ];
                }
            }

            usort($server_live_feed, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
            $server_live_feed = array_slice($server_live_feed, 0, 20);
            $now = time();
            foreach ($server_live_feed as &$item) {
                $diff = $now - $item['timestamp'];
                if ($diff < 60)         $item['relative_time'] = 'Just now';
                elseif ($diff < 3600)   $item['relative_time'] = floor($diff / 60) . ' mins ago';
                elseif ($diff < 86400)  $item['relative_time'] = floor($diff / 3600) . ' hrs ago';
                else                    $item['relative_time'] = floor($diff / 86400) . ' days ago';
            }
        } catch (Exception $e) {
            $server_live_feed = [];
        }

    }
} catch (Exception $e) {
    error_log("Dashboard Query Error: " . $e->getMessage());
}
?>

<div class="dashboard-2-container">

    <!-- ── TOP BAR & EXECUTIVE HEADER ──────────────────────────────────────── -->
    <div class="dashboard-topbar">
        <div class="dashboard-title-area">
            <div class="dashboard-brand-icon">
                <i class="fas fa-gauge-high"></i>
            </div>
            <div>
                <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
                    <h1 class="dashboard-main-title"><?php echo $is_admin ? 'Admin Dashboard' : 'Staff Dashboard'; ?></h1>
                    <span class="dashboard-date-pill">
                        <i class="far fa-calendar"></i> <?php echo date('l, F j, Y'); ?>
                    </span>
                </div>
                <p class="dashboard-subtitle">
                    Real-time gym operations, attendance &amp; business metrics for <strong><?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></strong>.
                </p>
            </div>
        </div>

        <div class="dashboard-actions-area">
            <form method="GET" action="" class="filter-preset-form" id="dashboard-filter-form">
                <select name="filter_preset" id="filter-preset-select" class="form-control filter-select" aria-label="Filter dashboard by time period">
                    <option value="today" <?php echo $filter_preset === 'today' ? 'selected' : ''; ?>>📅 Today</option>
                    <option value="week" <?php echo $filter_preset === 'week' ? 'selected' : ''; ?>>📊 This Week</option>
                    <option value="month" <?php echo $filter_preset === 'month' ? 'selected' : ''; ?>>📈 This Month</option>
                </select>
            </form>

            <?php if ($pending_registrations_cnt > 0 || $pending_renewals_count > 0): ?>
            <a href="notifications.php" class="topbar-notification-btn" title="View Notifications" aria-label="Notifications">
                <i class="fas fa-bell"></i>
                <span class="topbar-notification-dot"></span>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($pending_registrations_cnt > 0): ?>
    <!-- ── PENDING REGISTRATIONS ALERT BANNER ─────────────────────────────── -->
    <div class="alert-pending-banner" role="alert">
        <div class="alert-pending-banner-content">
            <div class="alert-pending-icon" aria-hidden="true">
                <i class="fas fa-user-clock"></i>
            </div>
            <div class="alert-pending-text">
                <h3><?php echo $pending_registrations_cnt; ?> Member Registration<?php echo $pending_registrations_cnt > 1 ? 's' : ''; ?> Awaiting Review</h3>
                <p>New sign-ups require approval before digital passes and QR access are activated.</p>
            </div>
        </div>
        <a href="pending-approvals.php?tab=registrations" class="alert-pending-btn">
            <i class="fas fa-clipboard-check"></i> Review Applications
        </a>
    </div>
    <?php endif; ?>

    <!-- ── 4 EXECUTIVE MASTER KPI CARDS ────────────────────────────────────── -->
    <div class="kpi-master-grid">
        <!-- 1. Active Members -->
        <div class="card kpi-card">
            <div class="kpi-card-head">
                <span class="kpi-label">Active Members</span>
                <div class="kpi-icon-box kpi-icon-green"><i class="fas fa-users"></i></div>
            </div>
            <div class="kpi-number-wrap">
                <h2 class="kpi-number"><?php echo number_format($active_members); ?></h2>
                <span class="kpi-trend-pill positive">
                    <?php echo $total_members > 0 ? round(($active_members / $total_members) * 100) : 0; ?>% Active Rate
                </span>
            </div>
            <div class="kpi-footer-meta">
                <span><strong><?php echo number_format($total_members); ?></strong> Total Registered</span>
                <span>&bull;</span>
                <span style="color:#ef4444;"><strong><?php echo number_format($expired_members); ?></strong> Expired</span>
            </div>
        </div>

        <!-- 2. Today's Attendance -->
        <div class="card kpi-card">
            <div class="kpi-card-head">
                <span class="kpi-label">Today's Check-ins</span>
                <div class="kpi-icon-box kpi-icon-blue"><i class="fas fa-qrcode"></i></div>
            </div>
            <div class="kpi-number-wrap">
                <h2 class="kpi-number" style="color:#38bdf8;"><?php echo number_format($daily_attendance); ?></h2>
                <span class="kpi-trend-pill" style="background:rgba(56,189,248,0.12); color:#38bdf8;">
                    Live Today
                </span>
            </div>
            <div class="kpi-footer-meta">
                <span><i class="fas fa-calendar-alt"></i> <strong><?php echo number_format($monthly_attendance); ?></strong> total this month</span>
            </div>
        </div>

        <!-- 3. Live Gym Occupancy -->
        <div class="card kpi-card">
            <?php 
                $live_occupancy_pct = min(100, round(($currently_inside / max($max_capacity, 1)) * 100));
                $live_occ_color = $live_occupancy_pct > 80 ? '#ef4444' : ($live_occupancy_pct > 50 ? '#eab308' : '#52b788');
            ?>
            <div class="kpi-card-head">
                <span class="kpi-label">Live Inside Gym</span>
                <div class="kpi-icon-box" style="background:rgba(82,183,136,0.12); color:<?php echo $live_occ_color; ?>;"><i class="fas fa-door-open"></i></div>
            </div>
            <div class="kpi-number-wrap">
                <h2 class="kpi-number" style="color:<?php echo $live_occ_color; ?>;">
                    <span id="kpi-live-occupancy-num"><?php echo $currently_inside; ?></span> <small style="font-size:0.9rem; color:var(--text-muted); font-weight:500;">/ <?php echo $max_capacity; ?> max</small>
                </h2>
                <span class="kpi-trend-pill" id="kpi-live-occupancy-pill" style="background:rgba(0,0,0,0.05); color:<?php echo $live_occ_color; ?>; font-weight:700;">
                    <?php echo $live_occupancy_pct; ?>% capacity
                </span>
            </div>
            <div class="kpi-progress-bg">
                <div class="kpi-progress-bar" id="kpi-live-occupancy-bar" style="width: <?php echo $live_occupancy_pct; ?>%; background: <?php echo $live_occ_color; ?>;"></div>
            </div>
        </div>

        <!-- 4. Revenue / Expiring Card -->
        <div class="card kpi-card">
            <?php if ($is_admin): ?>
            <div class="kpi-card-head">
                <span class="kpi-label">Monthly Revenue &amp; Net</span>
                <div class="kpi-icon-box kpi-icon-yellow"><i class="fas fa-peso-sign"></i></div>
            </div>
            <div class="kpi-number-wrap">
                <h2 class="kpi-number" style="color:#52b788;">&#8369;<?php echo number_format($monthly_revenue, 2); ?></h2>
                <span class="kpi-trend-pill" style="background:<?php echo $monthly_net_income >= 0 ? 'rgba(82,183,136,0.12)' : 'rgba(239,68,68,0.12)'; ?>; color:<?php echo $monthly_net_income >= 0 ? '#52b788' : '#ef4444'; ?>; font-weight:700;">
                    Net: <?php echo ($monthly_net_income < 0 ? '-' : '') . '&#8369;' . number_format(abs($monthly_net_income), 2); ?>
                </span>
            </div>
            <div class="kpi-footer-meta">
                <span>Costs: <strong style="color:#f87171;">&#8369;<?php echo number_format($monthly_expenses, 2); ?></strong></span>
                <span>&bull;</span>
                <a href="reports.php" style="color:var(--accent); text-decoration:none; font-weight:600; font-size:0.75rem;">View Financials &rarr;</a>
            </div>
            <?php else: ?>
            <div class="kpi-card-head">
                <span class="kpi-label">Expiring This Week</span>
                <div class="kpi-icon-box" style="background:rgba(239,68,68,0.12); color:#ef4444;"><i class="fas fa-clock-rotate-left"></i></div>
            </div>
            <div class="kpi-number-wrap">
                <h2 class="kpi-number" style="color:#ef4444;"><?php echo number_format($expiring_this_week_cnt); ?></h2>
                <span class="kpi-trend-pill" style="background:rgba(239,68,68,0.12); color:#ef4444;">
                    Action Needed
                </span>
            </div>
            <div class="kpi-footer-meta">
                <a href="members.php" style="color:var(--accent); text-decoration:none; font-weight:600; font-size:0.8rem;">View Subscriptions &rarr;</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── STREAMLINED 2-COLUMN OPERATIONAL WORKSPACE ───────────────────────── -->
    <div class="dashboard-grid-2col" style="margin-top: 1.5rem;">
        
        <!-- LEFT COLUMN: TODAY'S ATTENDANCE & OVERDUE RENEWALS -->
        <div style="display:flex; flex-direction:column; gap:1.5rem;">
            
            <!-- Card 1: Today's Live Attendance Table -->
            <div class="card dash-equal-card" style="height:580px; min-height:580px; max-height:580px; display:flex; flex-direction:column; overflow:hidden;">
                <div class="card-header-flex" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem; margin-bottom:0.75rem; flex-shrink:0;">
                    <div>
                        <h3 class="section-title" style="margin:0;"><i class="fas fa-qrcode" style="color:var(--accent);"></i> Today's Live Attendance</h3>
                        <p class="section-subtitle" style="margin:0.2rem 0 0 0;">Real-time gym visitors recorded today (<?php echo date('M d, Y'); ?>)</p>
                    </div>
                    <div style="display:flex; align-items:center; gap:0.6rem;">
                        <span class="badge badge-gold" id="dash-log-count"><?php echo count($today_attendance_list); ?> active entr<?php echo count($today_attendance_list) === 1 ? 'y' : 'ies'; ?></span>
                        <a href="attendance.php" class="btn btn-outline btn-sm" style="font-size:0.75rem; padding:0.3rem 0.75rem;">
                            Open Scanner <i class="fas fa-arrow-up-right-from-square" style="font-size:0.7rem; margin-left:2px;"></i>
                        </a>
                    </div>
                </div>

                <div class="table-container dash-scroll-body" style="flex:1 1 0; min-height:0; overflow-y:auto; overflow-x:auto;">
                    <table style="min-width: 650px;">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Member Tier &amp; Plan</th>
                                <th>Floor Access</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="dash-logs-body">
                            <?php if (empty($today_attendance_list)): ?>
                            <tr id="no-logs">
                                <td colspan="6" style="text-align:center; padding:2.5rem 1rem; color:var(--text-muted);">
                                    <i class="fas fa-qrcode" style="font-size:2rem; opacity:0.2; display:block; margin-bottom:0.5rem;"></i>
                                    No attendance check-ins recorded yet today.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($today_attendance_list as $att): 
                                $has_timed_out = (!empty($att['time_out']) && $att['time_out'] !== '00:00:00');
                                $ann_exp = $att['annual_membership_expiry'] ?? null;
                                $is_official = (!empty($ann_exp) && strtotime($ann_exp) >= strtotime(date('Y-m-d')));
                                $fa = $att['floor_access'] ?? 'all';
                            ?>
                            <tr id="att-row-<?php echo $att['id']; ?>">
                                <td>
                                    <div class="member-cell">
                                        <div class="member-avatar" style="width:36px; height:36px; border-radius:50%; overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                            <?php if (!empty($att['photo'])): ?>
                                                <img src="<?php echo htmlspecialchars($att['photo']); ?>" alt="Photo" style="width:100%; height:100%; object-fit:cover;">
                                            <?php else: ?>
                                                <?php echo strtoupper(substr($att['full_name'], 0, 1)); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <a href="view-member.php?id=<?php echo $att['member_id']; ?>" class="cell-primary" style="font-weight:700; color:var(--text-main); text-decoration:none;">
                                                <?php echo htmlspecialchars($att['full_name']); ?>
                                            </a>
                                            <div style="font-size:0.75rem; color:var(--text-muted); font-family:monospace;"><?php echo htmlspecialchars($att['membership_id']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex; flex-direction:column; gap:3px;">
                                        <div>
                                            <?php if ($is_official): ?>
                                                <span class="badge" style="background:rgba(16,185,129,0.15); color:#059669; border:1px solid rgba(16,185,129,0.3); font-size:0.68rem; font-weight:700;">
                                                    <i class="fas fa-id-card"></i> Official Member
                                                </span>
                                            <?php else: ?>
                                                <span class="badge" style="background:rgba(100,116,139,0.12); color:#64748b; border:1px solid rgba(100,116,139,0.25); font-size:0.68rem; font-weight:600;">
                                                    <i class="fas fa-user"></i> Non-Member
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <span style="font-size:0.75rem; color:var(--text-main); font-weight:600;">
                                            <?php echo htmlspecialchars($att['plan_name']); ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($fa === 'second_floor_only'): ?>
                                        <span class="badge" style="background:rgba(14,165,233,0.15); color:#0284c7; border:1px solid rgba(14,165,233,0.3); font-weight:700; font-size:0.72rem; padding:3px 8px;">
                                            <i class="fas fa-stairs"></i> 2nd Floor Only
                                        </span>
                                    <?php else: ?>
                                        <span class="badge" style="background:rgba(34,197,94,0.15); color:#16a34a; border:1px solid rgba(34,197,94,0.3); font-weight:700; font-size:0.72rem; padding:3px 8px;">
                                            <i class="fas fa-building"></i> Ground + 2nd Flr
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell-primary" style="font-weight:600;"><?php echo date('h:i A', strtotime($att['time_in'])); ?></td>
                                <td class="cell-secondary" id="timeout-<?php echo $att['id']; ?>"><?php echo $has_timed_out ? date('h:i A', strtotime($att['time_out'])) : '&mdash;'; ?></td>
                                <td id="status-cell-<?php echo $att['id']; ?>" style="white-space:nowrap;">
                                    <?php if ($has_timed_out): ?>
                                        <span class="badge badge-gray">Left</span>
                                    <?php else: ?>
                                        <div style="display:inline-flex; align-items:center; gap:8px;">
                                            <span class="badge badge-success"><i class="fas fa-circle" style="font-size:0.35rem; margin-right:4px;"></i> Inside</span>
                                            <button type="button"
                                                    class="btn btn-outline manual-checkout-btn"
                                                    onclick="manualCheckout(<?php echo $att['id']; ?>, '<?php echo htmlspecialchars(addslashes($att['full_name'])); ?>')"
                                                    aria-label="Check out <?php echo htmlspecialchars($att['full_name']); ?>">
                                                <i class="fas fa-arrow-right-from-bracket"></i> Check Out
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Card 2: Overdue / Expired Renewals (Follow-up Center) -->
            <div class="card dash-equal-card" id="card-expired-followups" style="height:580px; min-height:580px; max-height:580px; display:flex; flex-direction:column; overflow:hidden;">
                <div class="card-header-flex" style="flex-wrap:wrap; gap:10px; margin-bottom:0.75rem; flex-shrink:0;">
                    <div>
                        <h3 class="section-title"><i class="fas fa-triangle-exclamation" style="color:#ef4444;"></i> Expired Plans &amp; Follow-ups</h3>
                        <p class="section-subtitle">Recurring members with expired plans needing renewal</p>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <?php if (!empty($overdue_renewals)): ?>
                        <button type="button" class="btn btn-outline btn-sm" id="btn-bulk-notify" onclick="sendBulkRenewalReminders()" style="font-size:0.75rem; padding:0.35rem 0.75rem; border-color:#fca5a5; color:#dc2626; background:#fff5f5; display:inline-flex; align-items:center; gap:5px; font-weight:700; border-radius:8px;" title="Send renewal reminder email to all expired members with email addresses">
                            <i class="fas fa-bullhorn"></i> <span id="bulk-notify-label">Notify All Overdue (<?php echo count($overdue_renewals); ?>)</span>
                        </button>
                        <?php endif; ?>
                        <span class="badge badge-danger" id="overdue-total-badge" style="background:rgba(239,68,68,0.12); color:#ef4444; font-weight:700;">
                            <?php echo count($overdue_renewals); ?> Overdue
                        </span>
                    </div>
                </div>

                <div class="table-container dash-scroll-body" style="flex:1 1 0; min-height:0; overflow-y:auto; overflow-x:auto;">
                    <table id="overdue-table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Expired Date</th>
                                <th>Plan</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="overdue-tbody">
                            <?php if (empty($overdue_renewals)): ?>
                            <tr id="row-no-overdue">
                                <td colspan="4" style="text-align:center; padding:2.5rem 1rem; color:var(--text-muted);">
                                    <i class="fas fa-circle-check" style="font-size:1.8rem; color:#52b788; display:block; margin-bottom:0.5rem;"></i>
                                    <strong>All recurring member subscriptions are in good standing!</strong>
                                    <p style="margin:4px 0 0 0; font-size:0.78rem;">No expired memberships requiring immediate follow-up.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($overdue_renewals as $od): 
                                $days = max(1, intval($od['overdue_days'] ?? 1));
                                
                                // Color-coded days overdue
                                if ($days <= 3) {
                                    $od_badge_style = 'background:#fef3c7; color:#b45309; border:1px solid #fde68a;';
                                    $od_label = "🟡 {$days}d ago (Fresh)";
                                } elseif ($days <= 14) {
                                    $od_badge_style = 'background:#ffedd5; color:#c2410c; border:1px solid #fed7aa;';
                                    $od_label = "🟠 {$days}d ago";
                                } else {
                                    $od_badge_style = 'background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5;';
                                    $od_label = "🔴 {$days}d ago (Lapsed)";
                                }
                            ?>
                            <tr class="od-row" id="od-row-<?php echo $od['id']; ?>">
                                <td>
                                    <div class="member-cell">
                                        <div class="member-avatar" style="width:34px; height:34px; border-radius:50%; overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0; background:#f1f5f9; font-weight:700; color:#2d6a4f; font-size:0.85rem;">
                                            <?php if (!empty($od['photo'])): ?>
                                                <img src="<?php echo htmlspecialchars($od['photo']); ?>" alt="Photo" style="width:100%; height:100%; object-fit:cover;">
                                            <?php else: ?>
                                                <?php echo strtoupper(substr($od['full_name'], 0, 1)); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <a href="view-member.php?id=<?php echo $od['id']; ?>" class="cell-primary" style="font-weight:700; color:var(--text-main); text-decoration:none; font-size:0.85rem;">
                                                <?php echo htmlspecialchars($od['full_name']); ?>
                                            </a>
                                            <div style="font-size:0.72rem; color:var(--text-muted); font-family:monospace;">
                                                <?php echo htmlspecialchars($od['membership_id']); ?>
                                                <?php if (!empty($od['contact_number'])): ?>
                                                    • <?php echo htmlspecialchars($od['contact_number']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex; flex-direction:column; gap:2px;">
                                        <span style="font-size:0.82rem; color:#ef4444; font-weight:700;">
                                            <?php echo date('M d, Y', strtotime($od['expiry_date'])); ?>
                                        </span>
                                        <span class="badge" style="<?php echo $od_badge_style; ?> font-size:0.65rem; padding:2px 6px; border-radius:6px; font-weight:700; width:fit-content;">
                                            <?php echo $od_label; ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex; flex-direction:column; gap:2px;">
                                        <span class="badge badge-gold" style="font-size:0.75rem; font-weight:700;"><?php echo htmlspecialchars($od['plan_name']); ?></span>
                                        <span style="font-size:0.68rem; color:var(--text-muted);">
                                            ₱<?php echo number_format((float)($od['plan_price'] ?? 0), 2); ?> • Recurring Pass
                                        </span>
                                    </div>
                                </td>
                                <td style="text-align:right;">
                                    <div style="display:inline-flex; align-items:center; gap:6px; flex-wrap:wrap; justify-content:flex-end;">
                                        <!-- Email Reminder Button -->
                                        <?php if (!empty($od['email'])): ?>
                                        <button type="button" class="btn btn-outline btn-sm" 
                                                onclick="sendRenewalReminder(<?php echo $od['id']; ?>, '<?php echo htmlspecialchars(addslashes($od['full_name'])); ?>', this)" 
                                                style="padding:0.32rem 0.65rem; font-size:0.75rem; color:#0284c7; border-color:#bae6fd; background:#f0f9ff; border-radius:7px; font-weight:600; display:inline-flex; align-items:center; gap:4px;" 
                                                title="Send Renewal Reminder Email">
                                            <i class="fas fa-envelope"></i> Email Notif
                                        </button>
                                        <?php endif; ?>

                                        <!-- Quick Renew Button -->
                                        <button type="button" class="btn btn-primary btn-sm" 
                                                onclick="openQuickRenewModal(<?php echo $od['id']; ?>, '<?php echo htmlspecialchars(addslashes($od['full_name'])); ?>', '<?php echo htmlspecialchars(addslashes($od['membership_id'])); ?>', <?php echo (int)($od['plan_id'] ?? 0); ?>, <?php echo floatval($od['plan_price'] ?? 0); ?>)" 
                                                style="padding:0.32rem 0.75rem; font-size:0.75rem; font-weight:700; display:inline-flex; align-items:center; gap:4px; border-radius:7px;" 
                                                title="Quick Renewal">
                                            <i class="fas fa-arrows-rotate"></i> Renew
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- RIGHT COLUMN: LIVE ACTIVITY FEED & TOP MEMBERS -->
        <div style="display:flex; flex-direction:column; gap:1.5rem;">
            
            <!-- Real-Time Activity Feed -->
            <div class="card dash-equal-card" style="height:580px; min-height:580px; max-height:580px; display:flex; flex-direction:column; overflow:hidden;">
                <div class="card-header-flex" style="margin-bottom:0.5rem; flex-shrink:0;">
                    <div style="display:flex; align-items:center; gap:0.6rem;">
                        <div class="live-dot-pulse"></div>
                        <div>
                            <h3 class="section-title">Live Activity Stream</h3>
                            <p class="section-subtitle">Real-time check-ins, payments &amp; renewals</p>
                        </div>
                    </div>
                    <button onclick="fetchLiveFeed()" class="btn btn-outline btn-sm" style="padding:0.25rem 0.6rem; font-size:0.75rem;" title="Refresh stream">
                        <i class="fas fa-arrows-rotate" id="feed-refresh-icon"></i>
                    </button>
                </div>

                <!-- Feed Category Filter Pills -->
                <div class="feed-filters-bar" style="margin-bottom:0.65rem; flex-shrink:0;">
                    <button class="feed-filter-btn active" onclick="filterFeed('all')" data-cat="all">All</button>
                    <button class="feed-filter-btn" onclick="filterFeed('checkin')" data-cat="checkin">Check-ins</button>
                    <button class="feed-filter-btn" onclick="filterFeed('payment')" data-cat="payment">Payments</button>
                    <button class="feed-filter-btn" onclick="filterFeed('renewal')" data-cat="renewal">Renewals</button>
                </div>

                <!-- Feed Items Stream -->
                <div id="live-feed-stream" class="feed-items-container dash-scroll-body" style="flex:1 1 0; min-height:0; overflow-y:auto; overflow-x:hidden;">
                    <?php if (empty($server_live_feed)): ?>
                        <div style="text-align:center; padding:2rem; color:var(--text-muted); font-size:0.85rem;">
                            <i class="fas fa-inbox" style="font-size:1.6rem; display:block; margin-bottom:0.4rem; color:#cbd5e1;"></i>
                            No recent activities recorded yet.
                        </div>
                    <?php else: ?>
                        <?php foreach ($server_live_feed as $item): ?>
                            <div class="feed-item" style="display:flex; align-items:flex-start; gap:0.75rem; padding:0.65rem 0.5rem; border-bottom:1px solid var(--border);">
                                <div style="width:32px; height:32px; border-radius:8px; background:<?php echo $item['bg']; ?>; color:<?php echo $item['color']; ?>; display:flex; align-items:center; justify-content:center; font-size:0.85rem; flex-shrink:0;">
                                    <i class="fas <?php echo $item['icon']; ?>"></i>
                                </div>
                                <div style="flex:1; min-width:0;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; gap:0.4rem;">
                                        <span style="font-size:0.82rem; font-weight:700; color:var(--text-main); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo $item['title']; ?></span>
                                        <span style="font-size:0.7rem; color:var(--text-muted); white-space:nowrap;"><?php echo $item['relative_time']; ?></span>
                                    </div>
                                    <p style="font-size:0.75rem; color:var(--text-muted); margin:0.15rem 0 0 0; line-height:1.3;"><?php echo $item['description']; ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top Loyalty Champions (Gamified Leaderboard with Timeframe Filters) -->
            <?php
            if (!function_exists('render_lb_rows')) {
                function render_lb_rows($list, $period_label = 'this month') {
                    if (empty($list)) {
                        return '<tr><td colspan="4" style="text-align:center; padding:2rem 1rem; color:var(--text-muted);"><i class="fas fa-trophy" style="font-size:1.6rem; color:#cbd5e1; display:block; margin-bottom:0.4rem;"></i><strong>No check-ins recorded ' . htmlspecialchars($period_label) . ' yet.</strong><p style="margin:4px 0 0 0; font-size:0.75rem;">Visits will appear here once members scan their ID at the kiosk.</p></td></tr>';
                    }
                    $html = '';
                    foreach ($list as $idx => $tm) {
                        $rank_badge = '';
                        $row_bg = '';
                        if ($idx === 0) {
                            $rank_badge = '<span class="badge" style="background:#fef9c3; color:#a16207; border:1px solid #fde047; font-weight:800; font-size:0.75rem; padding:3px 8px; border-radius:10px;">🥇 1st</span>';
                            $row_bg = 'background:rgba(254,249,195,0.12);';
                        } elseif ($idx === 1) {
                            $rank_badge = '<span class="badge" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-weight:800; font-size:0.75rem; padding:3px 8px; border-radius:10px;">🥈 2nd</span>';
                        } elseif ($idx === 2) {
                            $rank_badge = '<span class="badge" style="background:#ffedd5; color:#c2410c; border:1px solid #fed7aa; font-weight:800; font-size:0.75rem; padding:3px 8px; border-radius:10px;">🥉 3rd</span>';
                        } else {
                            $rank_badge = '<span style="font-weight:700; color:var(--text-muted); font-size:0.8rem;">#' . ($idx + 1) . '</span>';
                        }

                        $initial = strtoupper(substr($tm['full_name'] ?? 'M', 0, 1));
                        $avatar_content = !empty($tm['photo']) 
                            ? '<img src="' . htmlspecialchars($tm['photo']) . '" alt="" onerror="this.onerror=null; this.parentElement.textContent=\'' . htmlspecialchars($initial, ENT_QUOTES) . '\';" style="width:100%; height:100%; object-fit:cover;">'
                            : htmlspecialchars($initial);

                        $html .= '<tr style="' . $row_bg . '">
                            <td style="text-align:center; width:60px;">' . $rank_badge . '</td>
                            <td>
                                <div class="member-cell">
                                    <div class="member-avatar" style="width:34px; height:34px; border-radius:50%; overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0; background:#f1f5f9; font-weight:700; color:#2d6a4f; font-size:0.85rem;">
                                        ' . $avatar_content . '
                                    </div>
                                    <div>
                                        <a href="view-member.php?id=' . $tm['id'] . '" class="cell-primary" style="font-weight:700; color:var(--text-main); text-decoration:none; font-size:0.84rem;">
                                            ' . htmlspecialchars($tm['full_name']) . '
                                        </a>
                                        <div style="font-size:0.7rem; color:var(--text-muted); font-family:monospace;">
                                            ' . htmlspecialchars($tm['membership_id']) . '
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-gold" style="font-size:0.7rem; font-weight:700;">' . htmlspecialchars($tm['plan_name']) . '</span>
                            </td>
                            <td style="text-align:right; white-space:nowrap;">
                                <span class="badge" style="background:rgba(56,189,248,0.12); color:#0284c7; border:1px solid rgba(56,189,248,0.25); font-weight:800; font-size:0.78rem; padding:4px 9px; border-radius:8px; display:inline-flex; align-items:center; gap:4px;">
                                    <i class="fas fa-dumbbell"></i> ' . number_format($tm['visit_count']) . '
                                </span>
                            </td>
                        </tr>';
                    }
                    return $html;
                }
            }
            ?>
            <div class="card dash-equal-card" id="card-loyalty-leaderboard" style="height:580px; min-height:580px; max-height:580px; display:flex; flex-direction:column; overflow:hidden;">
                <div class="card-header-flex" style="flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:0.75rem; flex-shrink:0;">
                    <div>
                        <h3 class="section-title"><i class="fas fa-trophy" style="color:#eab308;"></i> Member Loyalty Leaderboard</h3>
                        <p class="section-subtitle"><span id="lb-subtitle-mode">Top champions by workout visits</span> (<strong id="lb-period-title" style="color:var(--accent); font-weight:700;"><?php echo date('F Y'); ?></strong>)</p>
                    </div>

                    <!-- Metric & Calendar Filter Controls -->
                    <div style="display:flex; align-items:center; gap:0.4rem; flex-wrap:wrap;">
                        <!-- Metric Toggle: Gym Visits vs Plans Availed -->
                        <div style="display:inline-flex; align-items:center; background:var(--bg-main, #f8fafc); padding:2px; border-radius:10px; border:1px solid var(--border);">
                            <button type="button" id="lb-metric-visits" class="btn btn-sm" onclick="setLeaderboardMetric('visits')" style="padding:4px 9px; font-size:0.75rem; border-radius:8px; background:var(--primary, #2d6a4f); color:#fff; border:none; font-weight:700; cursor:pointer;" title="View Top Active Members by Gym Check-ins">
                                <i class="fas fa-dumbbell"></i> Visits
                            </button>
                            <button type="button" id="lb-metric-plans" class="btn btn-sm" onclick="setLeaderboardMetric('plans')" style="padding:4px 9px; font-size:0.75rem; border-radius:8px; background:transparent; color:var(--text-muted); border:none; font-weight:700; cursor:pointer;" title="View Top Subscribers by Plan Availments">
                                <i class="fas fa-layer-group"></i> Plans Availed
                            </button>
                        </div>

                        <!-- Filter Scope: Month View / Day View / All-Time -->
                        <div style="display:inline-flex; align-items:center; background:var(--bg-main, #f8fafc); padding:2px; border-radius:10px; border:1px solid var(--border);">
                            <button type="button" id="lb-btn-month" class="btn btn-sm" onclick="setLeaderboardScope('month')" style="padding:4px 9px; font-size:0.75rem; border-radius:8px; background:var(--primary, #2d6a4f); color:#fff; border:none; font-weight:700; cursor:pointer;" title="View for Selected Month">
                                <i class="fas fa-calendar-alt"></i> Month
                            </button>
                            <button type="button" id="lb-btn-date" class="btn btn-sm" onclick="setLeaderboardScope('date')" style="padding:4px 9px; font-size:0.75rem; border-radius:8px; background:transparent; color:var(--text-muted); border:none; font-weight:700; cursor:pointer;" title="View for Exact Day">
                                <i class="fas fa-calendar-day"></i> Day
                            </button>
                            <button type="button" id="lb-btn-all" class="btn btn-sm" onclick="setLeaderboardScope('all_time')" style="padding:4px 9px; font-size:0.75rem; border-radius:8px; background:transparent; color:var(--text-muted); border:none; font-weight:700; cursor:pointer;" title="View All-Time">
                                <i class="fas fa-crown"></i> All-Time
                            </button>
                        </div>

                        <!-- Full Calendar Date Picker -->
                        <div id="lb-calendar-container" style="display:inline-flex; align-items:center; gap:6px; background:var(--bg-main, #f8fafc); padding:3px 10px; border-radius:10px; border:1px solid var(--border);">
                            <i class="fas fa-calendar-days" style="color:#2d6a4f; font-size:0.85rem;"></i>
                            <input type="date" 
                                   id="lb-full-calendar" 
                                   value="<?php echo date('Y-m-d'); ?>" 
                                   onchange="onLeaderboardDateChange(this.value)" 
                                   style="border:none; background:transparent; font-size:0.8rem; font-weight:700; color:var(--text-main); cursor:pointer; outline:none; font-family:inherit; padding:2px 0;">
                        </div>

                        <button type="button" class="btn btn-outline btn-sm" onclick="refreshCurrentLeaderboard()" style="padding:0.25rem 0.5rem; font-size:0.75rem; border-radius:8px;" title="Refresh Leaderboard">
                            <i class="fas fa-arrows-rotate" id="lb-refresh-icon"></i>
                        </button>
                    </div>
                </div>

                <div class="table-container dash-scroll-body" style="flex:1 1 0; min-height:0; overflow-y:auto; overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:60px; text-align:center;">Rank</th>
                                <th>Member</th>
                                <th>Plan</th>
                                <th style="text-align:right; white-space:nowrap;" id="lb-th-metric">Visits</th>
                            </tr>
                        </thead>
                        <tbody id="lb-tbody">
                            <?php echo render_lb_rows($top_active_month, 'this month'); ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </div>

</div>

<!-- ── Dashboard styles are in assets/css/main.css (Sections 17-25) ──────── -->
<!-- All dashboard component styles are centralized in assets/css/main.css -->

<script>
// Filter dropdown loading state feedback
document.addEventListener('DOMContentLoaded', function() {
    var filterSel = document.getElementById('filter-preset-select');
    if (filterSel) {
        filterSel.addEventListener('change', function() {
            filterSel.classList.add('is-loading');
            // Brief delay so user sees the loading state before page reloads
            setTimeout(function() {
                document.getElementById('dashboard-filter-form').submit();
            }, 80);
        });
    }
});
</script>

<script>
let rawFeedData = <?php echo json_encode($server_live_feed, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || [];
let currentCategory = 'all';

async function fetchLiveFeed() {
    const icon = document.getElementById('feed-refresh-icon');
    if (icon) icon.classList.add('fa-spin');

    try {
        const res = await fetch('api/admin_dashboard_ajax.php?ajax=live_feed');
        if (res.ok) {
            const data = await res.json();
            rawFeedData = Array.isArray(data) ? data : [];
            renderLiveFeed();
        } else {
            console.warn('Live feed fetch returned status:', res.status);
            if (!rawFeedData || rawFeedData.length === 0) {
                const container = document.getElementById('live-feed-stream');
                if (container) container.innerHTML = '<div style="text-align:center; padding:2rem; color:var(--text-muted); font-size:0.85rem;">No recent activities found.</div>';
            }
        }
    } catch (e) {
        console.error('Failed to fetch live feed:', e);
        if (!rawFeedData || rawFeedData.length === 0) {
            const container = document.getElementById('live-feed-stream');
            if (container) container.innerHTML = '<div style="text-align:center; padding:2rem; color:var(--text-muted); font-size:0.85rem;">No recent activities found.</div>';
        }
    } finally {
        if (icon) icon.classList.remove('fa-spin');
    }
}

function renderLiveFeed() {
    const container = document.getElementById('live-feed-stream');
    if (!container) return;

    const filtered = currentCategory === 'all' 
        ? rawFeedData 
        : rawFeedData.filter(item => item.type === currentCategory || (currentCategory === 'checkin' && (item.type === 'checkin' || item.type === 'checkout')));

    if (!filtered || filtered.length === 0) {
        container.innerHTML = '<div style="text-align:center; padding:2rem; color:var(--text-muted); font-size:0.85rem;">No recent activities found.</div>';
        return;
    }

    let html = '';
    filtered.forEach(item => {
        html += `
            <div class="feed-item">
                <div style="width:32px; height:32px; border-radius:8px; background:${item.bg}; color:${item.color}; display:flex; align-items:center; justify-content:center; font-size:0.85rem; flex-shrink:0;">
                    <i class="fas ${item.icon}"></i>
                </div>
                <div style="flex:1; min-width:0;">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:0.4rem;">
                        <span style="font-size:0.82rem; font-weight:700; color:var(--text-main); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${item.title}</span>
                        <span style="font-size:0.7rem; color:var(--text-muted); white-space:nowrap;">${item.relative_time}</span>
                    </div>
                    <p style="font-size:0.75rem; color:var(--text-muted); margin:0.15rem 0 0 0; line-height:1.3;">${item.description}</p>
                </div>
            </div>
        `;
    });
    container.innerHTML = html;
}

function filterFeed(cat) {
    currentCategory = cat;
    document.querySelectorAll('.feed-filter-btn').forEach(btn => {
        if (btn.getAttribute('data-cat') === cat) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
    renderLiveFeed();
}

function manualCheckout(attendanceId, memberName) {
    const confirmMsg = 'Check out ' + (memberName || 'this member') + ' now?';
    const executeCheckout = () => {
        const btn = document.querySelector(`#att-row-${attendanceId} .manual-checkout-btn`);
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        fetch('modules/attendance/manual_checkout.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'attendance_id=' + encodeURIComponent(attendanceId) + '&csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const timeoutEl = document.getElementById('timeout-' + attendanceId);
                const statusEl = document.getElementById('status-cell-' + attendanceId);
                if (timeoutEl) timeoutEl.textContent = data.time_out_formatted || 'Just now';
                if (statusEl) {
                    statusEl.innerHTML = '<span class="badge badge-gray">Left</span>';
                }

                // Dynamically update live occupancy KPI count if present
                const numEl = document.getElementById('kpi-live-occupancy-num');
                const pillEl = document.getElementById('kpi-live-occupancy-pill');
                const barEl = document.getElementById('kpi-live-occupancy-bar');
                if (numEl) {
                    const maxCap = <?php echo (int)($max_capacity ?? 50); ?> || 50;
                    const curVal = Math.max(0, parseInt(numEl.textContent.trim() || '0', 10) - 1);
                    numEl.textContent = curVal;
                    const pct = Math.min(100, Math.round((curVal / maxCap) * 100));
                    const color = pct > 80 ? '#ef4444' : (pct > 50 ? '#eab308' : '#52b788');
                    if (pillEl) {
                        pillEl.textContent = pct + '% capacity';
                        pillEl.style.color = color;
                    }
                    if (barEl) {
                        barEl.style.width = pct + '%';
                        barEl.style.background = color;
                    }
                }

                if (typeof palmasToast === 'function') {
                    palmasToast(data.message, 'success');
                }
                if (typeof fetchLiveFeed === 'function') {
                    fetchLiveFeed();
                }
            } else {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-arrow-right-from-bracket"></i> Check Out';
                }
                if (typeof palmasToast === 'function') {
                    palmasToast(data.message || 'Could not check out member.', 'error');
                } else {
                    alert(data.message || 'Could not check out member.');
                }
            }
        })
        .catch(err => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-arrow-right-from-bracket"></i> Check Out';
            }
            if (typeof palmasToast === 'function') {
                palmasToast('An error occurred. Please try again.', 'error');
            } else {
                alert('An error occurred. Please try again.');
            }
        });
    };

    if (typeof palmasConfirm === 'function') {
        palmasConfirm('Check-out Confirmation', confirmMsg, 'Check Out', '#059669', executeCheckout);
    } else {
        if (confirm(confirmMsg)) {
            executeCheckout();
        }
    }
}

// ── Loyalty Leaderboard Dynamic Calendar & Metric Selector ─────────────────
let currentLbScope = 'month';
let currentLbMetric = 'visits'; // 'visits' or 'plans'

function setLeaderboardMetric(metric) {
    currentLbMetric = metric;
    const btnVisits = document.getElementById('lb-metric-visits');
    const btnPlans  = document.getElementById('lb-metric-plans');
    const subtitleMode = document.getElementById('lb-subtitle-mode');
    const thMetric = document.getElementById('lb-th-metric');

    if (metric === 'plans') {
        if (btnPlans) {
            btnPlans.style.background = 'var(--primary, #2d6a4f)';
            btnPlans.style.color = '#fff';
        }
        if (btnVisits) {
            btnVisits.style.background = 'transparent';
            btnVisits.style.color = 'var(--text-muted)';
        }
        if (subtitleMode) subtitleMode.textContent = 'Top subscribers by plan availments';
        if (thMetric) thMetric.textContent = 'Plans / Spend';
    } else {
        if (btnVisits) {
            btnVisits.style.background = 'var(--primary, #2d6a4f)';
            btnVisits.style.color = '#fff';
        }
        if (btnPlans) {
            btnPlans.style.background = 'transparent';
            btnPlans.style.color = 'var(--text-muted)';
        }
        if (subtitleMode) subtitleMode.textContent = 'Top champions by workout visits';
        if (thMetric) thMetric.textContent = 'Visits';
    }

    refreshCurrentLeaderboard();
}

function setLeaderboardScope(scope) {
    currentLbScope = scope;
    const btnMonth = document.getElementById('lb-btn-month');
    const btnDate  = document.getElementById('lb-btn-date');
    const btnAll   = document.getElementById('lb-btn-all');
    const calContainer = document.getElementById('lb-calendar-container');

    [btnMonth, btnDate, btnAll].forEach(btn => {
        if (btn) {
            btn.style.background = 'transparent';
            btn.style.color = 'var(--text-muted)';
        }
    });

    if (scope === 'month') {
        if (btnMonth) {
            btnMonth.style.background = 'var(--primary, #2d6a4f)';
            btnMonth.style.color = '#fff';
        }
        if (calContainer) calContainer.style.display = 'inline-flex';
        const dateVal = document.getElementById('lb-full-calendar')?.value || '<?php echo date('Y-m-d'); ?>';
        const ym = dateVal.substring(0, 7); // e.g. 2026-07
        changeLeaderboardPeriod(ym);
    } else if (scope === 'date') {
        if (btnDate) {
            btnDate.style.background = 'var(--primary, #2d6a4f)';
            btnDate.style.color = '#fff';
        }
        if (calContainer) calContainer.style.display = 'inline-flex';
        const dateVal = document.getElementById('lb-full-calendar')?.value || '<?php echo date('Y-m-d'); ?>';
        changeLeaderboardPeriod(dateVal);
    } else if (scope === 'all_time') {
        if (btnAll) {
            btnAll.style.background = 'var(--primary, #2d6a4f)';
            btnAll.style.color = '#fff';
        }
        if (calContainer) calContainer.style.display = 'none';
        changeLeaderboardPeriod('all_time');
    }
}

function onLeaderboardDateChange(selectedDate) {
    if (!selectedDate) return;
    if (currentLbScope === 'month') {
        const ym = selectedDate.substring(0, 7); // e.g. 2026-07
        changeLeaderboardPeriod(ym);
    } else if (currentLbScope === 'date') {
        changeLeaderboardPeriod(selectedDate);
    }
}

function refreshCurrentLeaderboard() {
    const dateVal = document.getElementById('lb-full-calendar')?.value || '<?php echo date('Y-m-d'); ?>';
    if (currentLbScope === 'month') {
        changeLeaderboardPeriod(dateVal.substring(0, 7));
    } else if (currentLbScope === 'date') {
        changeLeaderboardPeriod(dateVal);
    } else {
        changeLeaderboardPeriod('all_time');
    }
}

async function changeLeaderboardPeriod(period) {
    const tbody = document.getElementById('lb-tbody');
    const refreshIcon = document.getElementById('lb-refresh-icon');
    const titleEl = document.getElementById('lb-period-title');

    if (refreshIcon) refreshIcon.classList.add('fa-spin');
    if (tbody) {
        tbody.style.opacity = '0.4';
    }

    try {
        const url = `api/admin_dashboard_ajax.php?ajax=leaderboard_data&period=${encodeURIComponent(period)}&metric=${encodeURIComponent(currentLbMetric)}`;
        const res = await fetch(url);
        if (res.ok) {
            const data = await res.json();
            if (data.success) {
                if (titleEl && data.period_label) {
                    titleEl.textContent = data.period_label;
                }
                renderLeaderboardTable(data.members || [], data.period_label || 'this period', data.metric || currentLbMetric);
            }
        }
    } catch (e) {
        console.error('Failed to update leaderboard:', e);
    } finally {
        if (refreshIcon) refreshIcon.classList.remove('fa-spin');
        if (tbody) tbody.style.opacity = '1';
    }
}

function renderLeaderboardTable(members, periodLabel, metric = 'visits') {
    const tbody = document.getElementById('lb-tbody');
    if (!tbody) return;

    if (!members || members.length === 0) {
        const emptyMsg = (metric === 'plans')
            ? `<strong>No plan availments recorded for ${periodLabel} yet.</strong><p style="margin:4px 0 0 0; font-size:0.75rem;">Subscriptions &amp; renewals will appear here once availed.</p>`
            : `<strong>No check-ins recorded for ${periodLabel} yet.</strong><p style="margin:4px 0 0 0; font-size:0.75rem;">Visits will appear here once members scan their ID at the kiosk.</p>`;
        tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:2.2rem 1rem; color:var(--text-muted);"><i class="fas fa-trophy" style="font-size:1.6rem; color:#cbd5e1; display:block; margin-bottom:0.4rem;"></i>${emptyMsg}</td></tr>`;
        return;
    }

    let html = '';
    members.forEach((tm, idx) => {
        let rankBadge = '';
        let rowBg = '';
        if (idx === 0) {
            rankBadge = '<span class="badge" style="background:#fef9c3; color:#a16207; border:1px solid #fde047; font-weight:800; font-size:0.75rem; padding:3px 8px; border-radius:10px;">🥇 1st</span>';
            rowBg = 'background:rgba(254,249,195,0.12);';
        } else if (idx === 1) {
            rankBadge = '<span class="badge" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-weight:800; font-size:0.75rem; padding:3px 8px; border-radius:10px;">🥈 2nd</span>';
        } else if (idx === 2) {
            rankBadge = '<span class="badge" style="background:#ffedd5; color:#c2410c; border:1px solid #fed7aa; font-weight:800; font-size:0.75rem; padding:3px 8px; border-radius:10px;">🥉 3rd</span>';
        } else {
            rankBadge = `<span style="font-weight:700; color:var(--text-muted); font-size:0.8rem;">#${idx + 1}</span>`;
        }

        const initial = tm.full_name ? tm.full_name.charAt(0).toUpperCase() : 'M';
        const avatarContent = tm.photo 
            ? `<img src="${escapeHtml(tm.photo)}" alt="" onerror="this.onerror=null; this.parentElement.textContent='${escapeHtml(initial)}';" style="width:100%; height:100%; object-fit:cover;">`
            : escapeHtml(initial);

        let metricDisplay = '';
        if (metric === 'plans') {
            const count = Number(tm.metric_count || 0);
            const spend = Number(tm.total_spend || 0);
            metricDisplay = `
                <div style="display:inline-flex; flex-direction:column; align-items:flex-end; gap:2px;">
                    <span class="badge" style="background:rgba(234,179,8,0.12); color:#b45309; border:1px solid rgba(234,179,8,0.3); font-weight:800; font-size:0.76rem; padding:3px 8px; border-radius:8px; display:inline-flex; align-items:center; gap:4px;">
                        <i class="fas fa-layer-group"></i> ${count} ${count === 1 ? 'plan' : 'plans'}
                    </span>
                    ${spend > 0 ? `<span style="font-size:0.68rem; font-weight:700; color:#52b788;">₱${spend.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})}</span>` : ''}
                </div>
            `;
        } else {
            const visits = Number(tm.metric_count || tm.visit_count || 0);
            metricDisplay = `
                <span class="badge" style="background:rgba(56,189,248,0.12); color:#0284c7; border:1px solid rgba(56,189,248,0.25); font-weight:800; font-size:0.78rem; padding:4px 9px; border-radius:8px; display:inline-flex; align-items:center; gap:4px;">
                    <i class="fas fa-dumbbell"></i> ${visits.toLocaleString()}
                </span>
            `;
        }

        html += `
            <tr style="${rowBg}">
                <td style="text-align:center; width:65px;">${rankBadge}</td>
                <td>
                    <div class="member-cell">
                        <div class="member-avatar" style="width:34px; height:34px; border-radius:50%; overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0; background:#f1f5f9; font-weight:700; color:#2d6a4f; font-size:0.85rem;">
                            ${avatarContent}
                        </div>
                        <div>
                            <a href="view-member.php?id=${tm.id}" class="cell-primary" style="font-weight:700; color:var(--text-main); text-decoration:none; font-size:0.84rem;">
                                ${escapeHtml(tm.full_name)}
                            </a>
                            <div style="font-size:0.7rem; color:var(--text-muted); font-family:monospace;">
                                ${escapeHtml(tm.membership_id)}
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <span class="badge badge-gold" style="font-size:0.7rem; font-weight:700;">${escapeHtml(tm.plan_name || 'Standard')}</span>
                </td>
                <td style="text-align:right;">
                    ${metricDisplay}
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// ── Send 1-Click Single Renewal Reminder ──────────────────────────────────
function sendRenewalReminder(memberId, memberName, btn) {
    if (!memberId) return;
    const originalHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    }

    fetch('api/admin_dashboard_ajax.php?action=send_renewal_reminder&member_id=' + encodeURIComponent(memberId))
        .then(r => r.json())
        .then(data => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
            if (data.success) {
                if (typeof palmasToast === 'function') {
                    palmasToast(data.message, 'success');
                } else {
                    alert(data.message);
                }
            } else {
                if (typeof palmasToast === 'function') {
                    palmasToast(data.message || 'Failed to send reminder.', 'error');
                } else {
                    alert(data.message || 'Failed to send reminder.');
                }
            }
        })
        .catch(err => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
            if (typeof palmasToast === 'function') {
                palmasToast('Network error while sending reminder.', 'error');
            } else {
                alert('Network error while sending reminder.');
            }
        });
}

// ── Send Bulk Renewal Reminders to All Overdue ───────────────────────────
function sendBulkRenewalReminders() {
    const btn = document.getElementById('btn-bulk-notify');
    const label = document.getElementById('bulk-notify-label');

    const executeBulk = () => {
        if (btn) btn.disabled = true;
        if (label) label.textContent = 'Sending Reminders...';

        fetch('api/admin_dashboard_ajax.php?action=send_bulk_renewal_reminders')
            .then(r => r.json())
            .then(data => {
                if (btn) btn.disabled = false;
                if (label) label.textContent = 'Notify All Overdue';

                if (data.success) {
                    if (typeof palmasToast === 'function') {
                        palmasToast(data.message, 'success');
                    } else {
                        alert(data.message);
                    }
                } else {
                    if (typeof palmasToast === 'function') {
                        palmasToast(data.message || 'Failed to send bulk reminders.', 'error');
                    } else {
                        alert(data.message || 'Failed to send bulk reminders.');
                    }
                }
            })
            .catch(err => {
                if (btn) btn.disabled = false;
                if (label) label.textContent = 'Notify All Overdue';
                if (typeof palmasToast === 'function') {
                    palmasToast('Network error while sending bulk reminders.', 'error');
                } else {
                    alert('Network error while sending bulk reminders.');
                }
            });
    };

    const confirmMsg = 'Send automated renewal reminder emails to all expired members with active email addresses?';
    if (typeof palmasConfirm === 'function') {
        palmasConfirm('Bulk Renewal Reminders', confirmMsg, 'Send Reminders', '#0284c7', executeBulk);
    } else {
        if (confirm(confirmMsg)) executeBulk();
    }
}

// ── Quick Renew Modal Handlers ───────────────────────────────────────────
function openQuickRenewModal(memberId, memberName, membershipId, planId, planPrice) {
    document.getElementById('qr-member-id').value = memberId;
    document.getElementById('qr-member-name').textContent = memberName;
    document.getElementById('qr-member-code').textContent = membershipId;

    const planSelect = document.getElementById('qr-plan-select');
    if (planSelect) {
        if (planId && planSelect.querySelector(`option[value="${planId}"]`)) {
            planSelect.value = planId;
        } else if (planSelect.options.length > 0) {
            planSelect.selectedIndex = 0;
        }
        updateQuickRenewPrice();
    }

    const modal = document.getElementById('quick-renew-modal-overlay');
    if (modal) modal.style.display = 'flex';
}

function closeQuickRenewModal() {
    const modal = document.getElementById('quick-renew-modal-overlay');
    if (modal) modal.style.display = 'none';
}

function updateQuickRenewPrice() {
    const select = document.getElementById('qr-plan-select');
    const priceDisplay = document.getElementById('qr-price-display');
    if (select && priceDisplay) {
        const opt = select.options[select.selectedIndex];
        const price = opt ? opt.getAttribute('data-price') : 0;
        priceDisplay.textContent = '₱' + parseFloat(price || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
}

function submitQuickRenewForm(e) {
    e.preventDefault();
    const btn = document.getElementById('qr-submit-btn');
    const form = document.getElementById('quick-renew-form');
    const memberId = document.getElementById('qr-member-id').value;

    if (!memberId) return;

    const formData = new FormData(form);
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing Renewal...';
    }

    fetch('api/admin_dashboard_ajax.php?action=quick_renew', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Complete Renewal';
        }
        if (data.success) {
            closeQuickRenewModal();
            if (typeof palmasToast === 'function') {
                palmasToast(data.message, 'success');
            }

            // Animate and remove the renewed row from overdue table
            const row = document.getElementById('od-row-' + memberId);
            if (row) {
                row.style.transition = 'all 0.5s ease';
                row.style.background = '#dcfce7';
                row.style.opacity = '0';
                setTimeout(() => {
                    const rowType = row.getAttribute('data-type');
                    row.remove();

                    // Update badge counters
                    const regCountEl = document.getElementById('count-tab-regular');
                    const dailyCountEl = document.getElementById('count-tab-daily');
                    const totalBadge = document.getElementById('overdue-total-badge');
                    const bulkLabel = document.getElementById('bulk-notify-label');

                    if (rowType === 'regular' && regCountEl) {
                        regCountEl.textContent = Math.max(0, parseInt(regCountEl.textContent || '1') - 1);
                    } else if (rowType === 'daily' && dailyCountEl) {
                        dailyCountEl.textContent = Math.max(0, parseInt(dailyCountEl.textContent || '1') - 1);
                    }

                    const remainingRows = document.querySelectorAll('.od-row').length;
                    if (totalBadge) totalBadge.textContent = remainingRows + ' Overdue';
                    if (bulkLabel) bulkLabel.textContent = `Notify All Overdue (${remainingRows})`;

                    if (remainingRows === 0) {
                        const tbody = document.getElementById('overdue-tbody');
                        if (tbody) {
                            tbody.innerHTML = '<tr id="row-no-overdue"><td colspan="4" style="text-align:center; padding:2.5rem 1rem; color:var(--text-muted);"><i class="fas fa-circle-check" style="font-size:1.8rem; color:#52b788; display:block; margin-bottom:0.5rem;"></i><strong>All active member subscriptions are in good standing!</strong></td></tr>';
                        }
                    }
                }, 500);
            }

            if (typeof fetchLiveFeed === 'function') {
                fetchLiveFeed();
            }
        } else {
            if (typeof palmasToast === 'function') {
                palmasToast(data.message || 'Renewal failed.', 'error');
            } else {
                alert(data.message || 'Renewal failed.');
            }
        }
    })
    .catch(err => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Complete Renewal';
        }
        if (typeof palmasToast === 'function') {
            palmasToast('Network error during renewal. Please try again.', 'error');
        } else {
            alert('Network error during renewal. Please try again.');
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    fetchLiveFeed();
    setInterval(fetchLiveFeed, 15000);
});
</script>

<!-- Quick Renewal Modal (No Page Reload) -->
<div id="quick-renew-modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:9999; align-items:center; justify-content:center; padding:1rem; backdrop-filter:blur(3px);">
    <div style="background:#ffffff; border-radius:20px; width:100%; max-width:440px; box-shadow:0 25px 60px rgba(0,0,0,0.3); border:1px solid #e2e8f0; overflow:hidden; animation:modalPop 0.25s ease-out;">
        <div style="background:linear-gradient(135deg, #1b4332 0%, #0a2218 100%); padding:1.25rem 1.5rem; color:#ffffff; display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:36px; height:36px; border-radius:10px; background:rgba(82,183,136,0.2); color:#52b788; display:flex; align-items:center; justify-content:center; font-size:1.1rem;">
                    <i class="fas fa-arrows-rotate"></i>
                </div>
                <div>
                    <h3 style="margin:0; font-size:1.05rem; font-weight:700; color:#ffffff;">Quick Member Renewal</h3>
                    <p style="margin:2px 0 0 0; font-size:0.75rem; color:rgba(255,255,255,0.7);">Instant front-desk subscription extension</p>
                </div>
            </div>
            <button type="button" onclick="closeQuickRenewModal()" style="background:none; border:none; color:rgba(255,255,255,0.7); font-size:1.2rem; cursor:pointer; padding:4px;" title="Close">
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        <form id="quick-renew-form" onsubmit="submitQuickRenewForm(event)" style="padding:1.25rem 1.5rem;">
            <input type="hidden" name="member_id" id="qr-member-id">

            <!-- Member Summary Pill -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:0.75rem 1rem; margin-bottom:1.1rem; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <span style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:700; letter-spacing:0.5px; display:block;">Member</span>
                    <strong id="qr-member-name" style="font-size:0.95rem; color:#0c2219;"></strong>
                </div>
                <code id="qr-member-code" style="font-size:0.8rem; font-weight:700; color:var(--accent); background:#dcfce7; padding:3px 8px; border-radius:6px;"></code>
            </div>

            <!-- Plan Selection -->
            <div style="margin-bottom:1rem;">
                <label style="display:block; font-size:0.75rem; font-weight:700; color:var(--text-main); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.35rem;">Select Renewal Plan *</label>
                <select name="plan_id" id="qr-plan-select" class="form-control" onchange="updateQuickRenewPrice()" required style="padding:0.6rem 0.8rem; font-size:0.9rem; font-weight:600;">
                    <?php foreach ($quick_renew_plans as $qp): 
                        $is_fee = (($qp['plan_category'] ?? '') === 'membership_fee');
                    ?>
                    <option value="<?php echo $qp['id']; ?>" data-price="<?php echo $qp['price']; ?>">
                        <?php echo htmlspecialchars($qp['name']); ?> — ₱<?php echo number_format($qp['price'], 2); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Amount & Payment Method -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:1.1rem;">
                <div>
                    <label style="display:block; font-size:0.72rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:0.35rem;">Amount Due</label>
                    <div id="qr-price-display" style="font-size:1.15rem; font-weight:800; color:#2d6a4f; padding:0.5rem 0.75rem; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px;">
                        ₱0.00
                    </div>
                </div>
                <div>
                    <label style="display:block; font-size:0.72rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:0.35rem;">Payment Method</label>
                    <select name="payment_method" class="form-control" style="padding:0.55rem 0.75rem; font-size:0.85rem; font-weight:600;">
                        <option value="Cash" selected>💵 Cash (Counter)</option>
                        <option value="GCash">📱 GCash</option>
                        <option value="Maya">💳 Maya</option>
                        <option value="Bank Transfer">🏦 Bank Transfer</option>
                    </select>
                </div>
            </div>

            <!-- Actions -->
            <div style="display:flex; gap:0.75rem; margin-top:1.25rem;">
                <button type="button" class="btn btn-outline" onclick="closeQuickRenewModal()" style="flex:1;">Cancel</button>
                <button type="submit" class="btn btn-primary" id="qr-submit-btn" style="flex:2; font-weight:700;">
                    <i class="fas fa-check"></i> Complete Renewal
                </button>
            </div>
        </form>
    </div>
</div>

<style>
@keyframes modalPop {
    from { opacity: 0; transform: scale(0.92); }
    to { opacity: 1; transform: scale(1); }
}
</style>

<?php 
require_once __DIR__ . '/includes/ui_components.php';
render_create_account_modal();
include 'includes/footer.php'; 
?>
