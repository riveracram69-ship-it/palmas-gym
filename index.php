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
            $monthly_revenue = (float)($pdo->query("SELECT SUM(amount) FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())")->fetchColumn() ?: 0);
            $total_earnings  = (float)($pdo->query("SELECT SUM(amount) FROM payments")->fetchColumn() ?: 0);
        } else {
            $expiring_this_week_cnt = (int)$pdo->query("SELECT COUNT(DISTINCT member_id) FROM subscriptions WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
        }

        // ── 2. Today's Attendance Logs (Latest 8) ──────────────────────────────
        $stmt_att = $pdo->query("
            SELECT a.id, a.time_in, a.time_out, m.id as member_id, m.full_name, m.membership_id, m.photo
            FROM attendance a
            JOIN members m ON m.id = a.member_id
            WHERE a.date = CURDATE()
            ORDER BY a.id DESC
            LIMIT 8
        ");
        $today_attendance_list = $stmt_att ? $stmt_att->fetchAll(PDO::FETCH_ASSOC) : [];

        // ── 3. Overdue Renewals (Latest 5) ──────────────────────────────────────
        $stmt_od = $pdo->query("
            SELECT m.id, m.full_name, m.membership_id, m.contact_number, m.email, m.photo,
                   s.expiry_date, p.name as plan_name,
                   DATEDIFF(CURDATE(), s.expiry_date) as overdue_days
            FROM subscriptions s
            JOIN members m ON m.id = s.member_id
            JOIN membership_plans p ON p.id = s.plan_id
            WHERE s.expiry_date < CURDATE()
              AND s.member_id NOT IN (SELECT member_id FROM subscriptions WHERE expiry_date >= CURDATE())
            ORDER BY s.expiry_date DESC
            LIMIT 5
        ");
        $overdue_renewals = $stmt_od ? $stmt_od->fetchAll(PDO::FETCH_ASSOC) : [];

        // ── 4. Top Active Members (Top 5 Loyalty Champions) ────────────────────
        $stmt_top = $pdo->query("
            SELECT m.id, m.full_name, m.membership_id, m.photo,
                   COALESCE(MAX(p.name), 'Standard') as plan_name,
                   COUNT(a.id) as visit_count
            FROM members m
            JOIN attendance a ON a.member_id = m.id
            LEFT JOIN subscriptions s ON s.member_id = m.id AND s.expiry_date >= CURDATE()
            LEFT JOIN membership_plans p ON s.plan_id = p.id
            GROUP BY m.id, m.full_name, m.membership_id, m.photo
            HAVING visit_count > 0
            ORDER BY visit_count DESC
            LIMIT 5
        ");
        $top_active_members = $stmt_top ? $stmt_top->fetchAll(PDO::FETCH_ASSOC) : [];

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
            <form method="GET" action="" class="filter-preset-form">
                <select name="filter_preset" onchange="this.form.submit()" class="form-control filter-select">
                    <option value="today" <?php echo $filter_preset === 'today' ? 'selected' : ''; ?>>📅 Today</option>
                    <option value="week" <?php echo $filter_preset === 'week' ? 'selected' : ''; ?>>📊 This Week</option>
                    <option value="month" <?php echo $filter_preset === 'month' ? 'selected' : ''; ?>>📈 This Month</option>
                </select>
            </form>

            <button type="button" class="btn btn-primary btn-action" onclick="openAccountChoiceModal()">
                <i class="fas fa-user-plus"></i> Add Account
            </button>
        </div>
    </div>

    <?php if ($pending_registrations_cnt > 0): ?>
    <!-- ── PENDING REGISTRATIONS ALERT BANNER ─────────────────────────────── -->
    <div style="background:linear-gradient(135deg, rgba(217,119,6,0.12) 0%, rgba(245,158,11,0.06) 100%); border:1px solid rgba(245,158,11,0.3); border-left:5px solid #F59E0B; border-radius:14px; padding:1.1rem 1.4rem; margin-bottom:1.5rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
        <div style="display:flex; align-items:center; gap:0.85rem;">
            <div style="width:42px; height:42px; border-radius:12px; background:#FEF3C7; color:#D97706; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0;">
                <i class="fas fa-user-clock"></i>
            </div>
            <div>
                <h3 style="margin:0 0 2px 0; font-size:0.95rem; font-weight:700; color:var(--text-main);">
                    <?php echo $pending_registrations_cnt; ?> Member Registration<?php echo $pending_registrations_cnt > 1 ? 's' : ''; ?> Awaiting Review
                </h3>
                <p style="margin:0; font-size:0.82rem; color:var(--text-muted);">
                    New sign-ups require approval before digital passes and QR access are activated.
                </p>
            </div>
        </div>
        <a href="pending-registrations.php" class="btn" style="background:#D97706; color:#fff; font-weight:700; border-radius:10px; padding:0.55rem 1.15rem; font-size:0.84rem; text-decoration:none; display:inline-flex; align-items:center; gap:0.5rem;">
            Review Applications <i class="fas fa-arrow-right"></i>
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
                    <?php echo $currently_inside; ?> <small style="font-size:0.9rem; color:var(--text-muted); font-weight:500;">/ <?php echo $max_capacity; ?> max</small>
                </h2>
                <span class="kpi-trend-pill" style="background:rgba(0,0,0,0.05); color:<?php echo $live_occ_color; ?>; font-weight:700;">
                    <?php echo $live_occupancy_pct; ?>% capacity
                </span>
            </div>
            <div class="kpi-progress-bg">
                <div class="kpi-progress-bar" style="width: <?php echo $live_occupancy_pct; ?>%; background: <?php echo $live_occ_color; ?>;"></div>
            </div>
        </div>

        <!-- 4. Revenue / Expiring Card -->
        <div class="card kpi-card">
            <?php if ($is_admin): ?>
            <div class="kpi-card-head">
                <span class="kpi-label">Monthly Revenue</span>
                <div class="kpi-icon-box kpi-icon-yellow"><i class="fas fa-peso-sign"></i></div>
            </div>
            <div class="kpi-number-wrap">
                <h2 class="kpi-number" style="color:#eab308;">&#8369;<?php echo number_format($monthly_revenue, 2); ?></h2>
                <span class="kpi-trend-pill" style="background:rgba(234,179,8,0.12); color:#b45309;">
                    <?php echo date('M Y'); ?>
                </span>
            </div>
            <div class="kpi-footer-meta">
                <span><i class="fas fa-chart-simple"></i> <strong>&#8369;<?php echo number_format($total_earnings, 2); ?></strong> all-time revenue</span>
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

    <!-- ── FAST ACTION SHORTCUT BUTTONS ────────────────────────────────────── -->
    <div class="quick-actions-bar">
        <a href="add-member.php" class="quick-action-btn">
            <div class="quick-action-icon" style="background:#e8f5e9; color:#2e7d32;"><i class="fas fa-user-plus"></i></div>
            <div>
                <div>Register Member</div>
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:400;">New profile &amp; pass</div>
            </div>
        </a>
        <a href="members.php" class="quick-action-btn">
            <div class="quick-action-icon" style="background:#e0f2fe; color:#0284c7;"><i class="fas fa-users"></i></div>
            <div>
                <div>Members Directory</div>
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:400;">Search &amp; manage</div>
            </div>
        </a>
        <a href="attendance.php" class="quick-action-btn">
            <div class="quick-action-icon" style="background:#fef3c7; color:#d97706;"><i class="fas fa-qrcode"></i></div>
            <div>
                <div>QR Kiosk Scanner</div>
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:400;">Turnstile check-in</div>
            </div>
        </a>
        <a href="payments.php" class="quick-action-btn">
            <div class="quick-action-icon" style="background:#dcfce7; color:#16a34a;"><i class="fas fa-money-bill-wave"></i></div>
            <div>
                <div>Record Payment</div>
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:400;">Cash &amp; transactions</div>
            </div>
        </a>
        <a href="plans.php" class="quick-action-btn">
            <div class="quick-action-icon" style="background:#f3e8ff; color:#7e22ce;"><i class="fas fa-tags"></i></div>
            <div>
                <div>Gym Packages</div>
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:400;">Plans &amp; pricing</div>
            </div>
        </a>
        <a href="reports.php" class="quick-action-btn">
            <div class="quick-action-icon" style="background:#fce7f3; color:#be185d;"><i class="fas fa-chart-line"></i></div>
            <div>
                <div>Financial Reports</div>
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:400;">Ledger &amp; analytics</div>
            </div>
        </a>
    </div>

    <!-- ── STREAMLINED 2-COLUMN OPERATIONAL WORKSPACE ───────────────────────── -->
    <div class="dashboard-grid-2col" style="margin-top: 1.5rem;">
        
        <!-- LEFT COLUMN: TODAY'S ATTENDANCE & OVERDUE RENEWALS -->
        <div style="display:flex; flex-direction:column; gap:1.5rem;">
            
            <!-- Card 1: Today's Live Attendance Table -->
            <div class="card">
                <div class="card-header-flex">
                    <div>
                        <h3 class="section-title"><i class="fas fa-qrcode" style="color:var(--accent);"></i> Today's Live Attendance</h3>
                        <p class="section-subtitle">Real-time gym visitors recorded today (<?php echo date('M d, Y'); ?>)</p>
                    </div>
                    <a href="attendance.php" class="btn btn-outline btn-sm" style="font-size:0.75rem; padding:0.3rem 0.75rem;">
                        Open Scanner <i class="fas fa-arrow-up-right-from-square" style="font-size:0.7rem; margin-left:2px;"></i>
                    </a>
                </div>

                <div class="table-container" style="max-height: 320px; overflow-y: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th style="text-align:right;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($today_attendance_list)): ?>
                            <tr>
                                <td colspan="4" style="text-align:center; padding:2.5rem 1rem; color:var(--text-muted);">
                                    <i class="fas fa-qrcode" style="font-size:2rem; opacity:0.2; display:block; margin-bottom:0.5rem;"></i>
                                    No attendance check-ins recorded yet today.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($today_attendance_list as $att): ?>
                            <tr>
                                <td>
                                    <div class="member-cell">
                                        <div class="member-avatar" style="width:32px; height:32px; font-size:0.75rem;">
                                            <?php if (!empty($att['photo'])): ?>
                                                <img src="<?php echo htmlspecialchars($att['photo']); ?>" alt="Photo" style="width:100%; height:100%; object-fit:cover; border-radius:50%;">
                                            <?php else: ?>
                                                <?php echo strtoupper(substr($att['full_name'], 0, 1)); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <a href="view-member.php?id=<?php echo $att['member_id']; ?>" style="font-weight:600; color:var(--text-main); text-decoration:none;">
                                                <?php echo htmlspecialchars($att['full_name']); ?>
                                            </a>
                                            <div style="font-size:0.72rem; color:var(--text-muted); font-family:monospace;"><?php echo htmlspecialchars($att['membership_id']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="font-weight:600; color:var(--text-main); font-size:0.84rem;">
                                    <?php echo date('h:i A', strtotime($att['time_in'])); ?>
                                </td>
                                <td style="font-size:0.84rem; color:var(--text-muted);">
                                    <?php echo !empty($att['time_out']) ? date('h:i A', strtotime($att['time_out'])) : '&mdash;'; ?>
                                </td>
                                <td style="text-align:right;">
                                    <?php if (empty($att['time_out'])): ?>
                                        <span class="badge badge-success"><i class="fas fa-circle" style="font-size:0.35rem; margin-right:3px;"></i> Inside</span>
                                    <?php else: ?>
                                        <span class="badge badge-gray">Left</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Card 2: Overdue / Expired Renewals -->
            <div class="card">
                <div class="card-header-flex">
                    <div>
                        <h3 class="section-title"><i class="fas fa-triangle-exclamation" style="color:#ef4444;"></i> Expired Plans &amp; Follow-ups</h3>
                        <p class="section-subtitle">Members with expired plans needing renewal follow-up</p>
                    </div>
                    <span class="badge badge-danger" style="background:rgba(239,68,68,0.12); color:#ef4444;">
                        <?php echo count($overdue_renewals); ?> Overdue
                    </span>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Expired Date</th>
                                <th>Plan</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($overdue_renewals)): ?>
                            <tr>
                                <td colspan="4" style="text-align:center; padding:2rem; color:var(--text-muted);">
                                    <i class="fas fa-circle-check" style="font-size:1.5rem; color:#52b788; display:block; margin-bottom:0.4rem;"></i>
                                    All active member subscriptions are in good standing!
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($overdue_renewals as $od): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600; color:var(--text-main); font-size:0.85rem;"><?php echo htmlspecialchars($od['full_name']); ?></div>
                                    <div style="font-size:0.72rem; color:var(--text-muted); font-family:monospace;"><?php echo htmlspecialchars($od['membership_id']); ?></div>
                                </td>
                                <td>
                                    <span style="font-size:0.82rem; color:#ef4444; font-weight:600;">
                                        <?php echo date('M d, Y', strtotime($od['expiry_date'])); ?>
                                    </span>
                                </td>
                                <td><span class="badge badge-gold"><?php echo htmlspecialchars($od['plan_name']); ?></span></td>
                                <td style="text-align:right;">
                                    <a href="renew-member.php?id=<?php echo $od['id']; ?>" class="btn btn-primary btn-sm" style="padding:0.3rem 0.7rem; font-size:0.75rem;">
                                        <i class="fas fa-arrows-rotate"></i> Renew
                                    </a>
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
            <div class="card">
                <div class="card-header-flex">
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
                <div class="feed-filters-bar" style="margin-bottom:1rem;">
                    <button class="feed-filter-btn active" onclick="filterFeed('all')" data-cat="all">All</button>
                    <button class="feed-filter-btn" onclick="filterFeed('checkin')" data-cat="checkin">Check-ins</button>
                    <button class="feed-filter-btn" onclick="filterFeed('payment')" data-cat="payment">Payments</button>
                    <button class="feed-filter-btn" onclick="filterFeed('renewal')" data-cat="renewal">Renewals</button>
                </div>

                <!-- Feed Items Stream -->
                <div id="live-feed-stream" class="feed-items-container" style="max-height:360px; overflow-y:auto;">
                    <div style="text-align:center; padding:2rem; color:var(--text-muted); font-size:0.85rem;">
                        <i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i> Loading live activity stream...
                    </div>
                </div>
            </div>

            <!-- Top Loyalty Champions -->
            <div class="card">
                <div class="card-header-flex">
                    <div>
                        <h3 class="section-title"><i class="fas fa-trophy" style="color:#eab308;"></i> Member Loyalty Leaderboard</h3>
                        <p class="section-subtitle">Top members by workout visit frequency</p>
                    </div>
                    <span class="badge badge-gold">🏆 Champions</span>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:40px; text-align:center;">#</th>
                                <th>Member</th>
                                <th>Plan</th>
                                <th style="text-align:right;">Visits</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($top_active_members)): ?>
                            <tr>
                                <td colspan="4" style="text-align:center; padding:1.5rem; color:var(--text-muted);">No check-in history yet.</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($top_active_members as $idx => $tm): ?>
                            <tr>
                                <td style="text-align:center; font-weight:800; color:<?php echo $idx === 0 ? '#eab308' : ($idx === 1 ? '#94a3b8' : 'var(--text-muted)'); ?>;">
                                    <?php echo $idx === 0 ? '🥇' : ($idx === 1 ? '🥈' : ($idx === 2 ? '🥉' : '#' . ($idx + 1))); ?>
                                </td>
                                <td>
                                    <a href="view-member.php?id=<?php echo $tm['id']; ?>" style="font-weight:600; color:var(--text-main); text-decoration:none; font-size:0.84rem;">
                                        <?php echo htmlspecialchars($tm['full_name']); ?>
                                    </a>
                                </td>
                                <td><span class="badge badge-gold" style="font-size:0.7rem;"><?php echo htmlspecialchars($tm['plan_name']); ?></span></td>
                                <td style="text-align:right; font-weight:700; color:#38bdf8;">
                                    <i class="fas fa-dumbbell" style="font-size:0.7rem; margin-right:3px;"></i> <?php echo number_format($tm['visit_count']); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </div>

</div>

<!-- ── STYLES FOR STREAMLINED DASHBOARD ──────────────────────────────────── -->
<style>
.dashboard-topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 1rem;
}
.dashboard-title-area {
    display: flex;
    align-items: center;
    gap: 0.85rem;
}
.dashboard-brand-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: linear-gradient(135deg, #1b4332, #2d6a4f);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #52b788;
    font-size: 1.4rem;
    box-shadow: 0 6px 18px rgba(45,106,79,0.3);
    flex-shrink: 0;
}
.dashboard-main-title {
    margin: 0;
    font-size: 1.65rem;
    font-weight: 800;
    letter-spacing: -0.5px;
}
.dashboard-date-pill {
    font-size: 0.72rem;
    background: rgba(45,106,79,0.1);
    color: #2d6a4f;
    padding: 3px 10px;
    border-radius: 20px;
    font-weight: 700;
    border: 1px solid rgba(82,183,136,0.25);
}
.dashboard-subtitle {
    margin: 0.2rem 0 0 0;
    color: var(--text-muted);
    font-size: 0.84rem;
}
.dashboard-actions-area {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
}
.filter-select {
    margin: 0;
    padding: 0.5rem 0.85rem;
    font-size: 0.82rem;
    border-radius: 10px;
}

/* Master KPI Grid */
.kpi-master-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.kpi-card {
    padding: 1.25rem 1.4rem;
    position: relative;
    overflow: hidden;
}
.kpi-card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.6rem;
}
.kpi-label {
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: var(--text-muted);
}
.kpi-icon-box {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}
.kpi-icon-green { background: rgba(82,183,136,0.14); color: #52b788; }
.kpi-icon-blue  { background: rgba(56,189,248,0.14); color: #38bdf8; }
.kpi-icon-yellow{ background: rgba(234,179,8,0.14);  color: #eab308; }

.kpi-number-wrap {
    display: flex;
    align-items: baseline;
    gap: 0.6rem;
    margin-bottom: 0.5rem;
}
.kpi-number {
    font-size: 1.85rem;
    font-weight: 800;
    margin: 0;
    line-height: 1;
}
.kpi-trend-pill {
    font-size: 0.72rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 6px;
    background: rgba(82,183,136,0.14);
    color: #52b788;
}
.kpi-footer-meta {
    font-size: 0.76rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
.kpi-progress-bg {
    width: 100%;
    height: 5px;
    background: rgba(0,0,0,0.06);
    border-radius: 4px;
    margin-top: 0.6rem;
    overflow: hidden;
}
.kpi-progress-bar {
    height: 100%;
    border-radius: 4px;
    transition: width 0.3s ease;
}

/* Quick Actions Bar */
.quick-actions-bar {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 0.75rem;
    margin-bottom: 1.5rem;
}
.quick-action-btn {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 0.85rem 1rem;
    text-decoration: none;
    color: var(--text-main);
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-weight: 600;
    font-size: 0.84rem;
    transition: all 0.2s ease;
}
.quick-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.06);
    border-color: var(--accent);
}
.quick-action-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}

/* 2-Column Grid */
.dashboard-grid-2col {
    display: grid;
    grid-template-columns: 1.15fr 0.85fr;
    gap: 1.5rem;
}

/* Live Activity Stream */
.live-dot-pulse {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #52b788;
    box-shadow: 0 0 0 3px rgba(82,183,136,0.3);
    animation: pulseDot 2s infinite;
}
@keyframes pulseDot {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(82,183,136,0.6); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(82,183,136,0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(82,183,136,0); }
}

.feed-filters-bar {
    display: flex;
    gap: 0.35rem;
    flex-wrap: wrap;
}
.feed-filter-btn {
    padding: 0.25rem 0.65rem;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--card-bg);
    color: var(--text-muted);
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.feed-filter-btn.active, .feed-filter-btn:hover {
    background: #2d6a4f;
    color: #fff;
    border-color: #2d6a4f;
}

.feed-item {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 0.75rem;
    border-radius: 10px;
    background: rgba(0,0,0,0.015);
    border: 1px solid rgba(0,0,0,0.04);
    margin-bottom: 0.5rem;
    transition: background 0.2s;
}
.feed-item:hover {
    background: rgba(0,0,0,0.035);
}

@media (max-width: 1100px) {
    .kpi-master-grid { grid-template-columns: repeat(2, 1fr); }
    .quick-actions-bar { grid-template-columns: repeat(3, 1fr); }
    .dashboard-grid-2col { grid-template-columns: 1fr; }
}

@media (max-width: 640px) {
    .kpi-master-grid { grid-template-columns: 1fr; }
    .quick-actions-bar { grid-template-columns: repeat(2, 1fr); }
}
</style>

<!-- ── LIVE ACTIVITY FEED SCRIPT (FETCH API) ────────────────────────────── -->
<script>
let rawFeedData = [];
let currentCategory = 'all';

async function fetchLiveFeed() {
    const icon = document.getElementById('feed-refresh-icon');
    if (icon) icon.classList.add('fa-spin');

    try {
        const res = await fetch('api/admin_dashboard_ajax.php?ajax=live_feed');
        if (res.ok) {
            rawFeedData = await res.json();
            renderLiveFeed();
        }
    } catch (e) {
        console.error('Failed to fetch live feed:', e);
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

document.addEventListener('DOMContentLoaded', () => {
    fetchLiveFeed();
    setInterval(fetchLiveFeed, 15000);
});
</script>

<?php 
require_once __DIR__ . '/includes/ui_components.php';
render_create_account_modal();
include 'includes/footer.php'; 
?>
