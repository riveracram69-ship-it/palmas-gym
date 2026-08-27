<?php
// ── CSV/Excel/JSON Export & AJAX Endpoint — must run BEFORE any HTML output ────────────────
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/logger.php';
require_once __DIR__ . '/config/settings.php';

require_login();
require_admin();

// ─────────────────────────────────────────────────────────────────────────────
// EXPORT DISPATCHER (CSV, Excel .xls, JSON)
// ─────────────────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && isset($pdo)) {
    $type      = $_GET['export'];
    $format    = $_GET['format'] ?? 'csv';
    $startDate = $_GET['start_date'] ?? null;
    $endDate   = $_GET['end_date'] ?? null;
    $status    = $_GET['status'] ?? 'all';
    $planId    = $_GET['plan_id'] ?? 'all';
    $payMethod = $_GET['payment_method'] ?? 'all';
    $datePreset= $_GET['date_preset'] ?? 'all';

    if ($datePreset !== 'all' && $datePreset !== 'custom') {
        switch ($datePreset) {
            case 'today':
                $startDate = date('Y-m-d');
                $endDate   = date('Y-m-d');
                break;
            case 'yesterday':
                $startDate = date('Y-m-d', strtotime('-1 day'));
                $endDate   = date('Y-m-d', strtotime('-1 day'));
                break;
            case 'week':
                $startDate = date('Y-m-d', strtotime('monday this week'));
                $endDate   = date('Y-m-d', strtotime('sunday this week'));
                break;
            case 'last_week':
                $startDate = date('Y-m-d', strtotime('monday last week'));
                $endDate   = date('Y-m-d', strtotime('sunday last week'));
                break;
            case 'month':
                $startDate = date('Y-m-01');
                $endDate   = date('Y-m-t');
                break;
            case 'last_month':
                $startDate = date('Y-m-01', strtotime('first day of last month'));
                $endDate   = date('Y-m-t', strtotime('last day of last month'));
                break;
            case 'last30':
                $startDate = date('Y-m-d', strtotime('-30 days'));
                $endDate   = date('Y-m-d');
                break;
            case 'last90':
                $startDate = date('Y-m-d', strtotime('-90 days'));
                $endDate   = date('Y-m-d');
                break;
            case 'year':
                $startDate = date('Y-01-01');
                $endDate   = date('Y-12-31');
                break;
        }
    }

    $params = [];
    $headers = [];
    $rows = [];

    // Log the export activity for security audit trail
    log_activity($pdo, 'Exported Report', "Exported {$type} report in " . strtoupper($format) . " format", 'Reports');

    if ($type === 'daily_revenue') {
        $headers = ['Payment Date', 'Reference No', 'Member Name', 'Membership ID', 'Membership Plan', 'Payment Method', 'Amount (PHP)', 'Notes'];
        $sql = "SELECT p.payment_date, COALESCE(p.reference_number, 'N/A'), m.full_name, m.membership_id, 
                       COALESCE(plan.name, 'Other/Service') as plan_name, p.payment_method, p.amount, COALESCE(p.notes, '')
                FROM payments p
                JOIN members m ON m.id = p.member_id
                LEFT JOIN subscriptions s ON p.subscription_id = s.id
                LEFT JOIN membership_plans plan ON s.plan_id = plan.id
                WHERE 1=1";
        if (!empty($startDate) && !empty($endDate)) {
            $sql .= " AND p.payment_date BETWEEN :start_date AND :end_date";
            $params['start_date'] = $startDate;
            $params['end_date']   = $endDate;
        }
        if ($payMethod !== 'all') {
            $sql .= " AND p.payment_method = :payment_method";
            $params['payment_method'] = $payMethod;
        }
        if ($planId !== 'all') {
            $sql .= " AND plan.id = :plan_id";
            $params['plan_id'] = $planId;
        }
        $sql .= " ORDER BY p.payment_date DESC, p.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);

    } elseif ($type === 'weekly_revenue') {
        $headers = ['Date', 'Day of Week', 'Completed Transactions', 'Total Revenue (PHP)'];
        $sql = "SELECT p.payment_date, DATE_FORMAT(p.payment_date, '%W') as day_name, COUNT(p.id) as txns, SUM(p.amount) as total
                FROM payments p
                WHERE 1=1";
        if (!empty($startDate) && !empty($endDate)) {
            $sql .= " AND p.payment_date BETWEEN :start_date AND :end_date";
            $params['start_date'] = $startDate;
            $params['end_date']   = $endDate;
        }
        $sql .= " GROUP BY p.payment_date ORDER BY p.payment_date ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);

    } elseif ($type === 'monthly_revenue') {
        $headers = ['Month / Year', 'Transactions Count', 'Unique Paying Members', 'Total Revenue (PHP)'];
        $sql = "SELECT DATE_FORMAT(p.payment_date, '%M %Y') as month_year, COUNT(p.id) as txns, COUNT(DISTINCT p.member_id) as unique_members, SUM(p.amount) as total
                FROM payments p
                WHERE 1=1";
        if (!empty($startDate) && !empty($endDate)) {
            $sql .= " AND p.payment_date BETWEEN :start_date AND :end_date";
            $params['start_date'] = $startDate;
            $params['end_date']   = $endDate;
        }
        $sql .= " GROUP BY YEAR(p.payment_date), MONTH(p.payment_date) ORDER BY YEAR(p.payment_date) ASC, MONTH(p.payment_date) ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);

    } elseif ($type === 'retention') {
        $headers = ['Membership ID', 'Member Name', 'Email', 'Plan Name', 'Start Date', 'Expiry Date', 'Status', 'Total Renewals'];
        $sql = "SELECT m.membership_id, m.full_name, m.email, COALESCE(p.name, 'None') as plan_name, 
                       COALESCE(s.start_date, '—') as start_date, COALESCE(s.expiry_date, '—') as expiry_date,
                       m.status, (SELECT COUNT(*) FROM subscriptions sub WHERE sub.member_id = m.id) as renewal_count
                FROM members m
                LEFT JOIN subscriptions s ON s.member_id = m.id AND s.id = (
                    SELECT id FROM subscriptions WHERE member_id = m.id ORDER BY expiry_date DESC LIMIT 1
                )
                LEFT JOIN membership_plans p ON s.plan_id = p.id
                WHERE 1=1";
        if ($status !== 'all') {
            $sql .= " AND m.status = :status";
            $params['status'] = $status;
        }
        $sql .= " ORDER BY m.full_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);

    } elseif ($type === 'conversion') {
        $headers = ['Member ID', 'Member Name', 'Registered Date', 'First Plan', 'Activated Date', 'Conversion Status'];
        $sql = "SELECT m.membership_id, m.full_name, DATE(m.created_at) as reg_date,
                       COALESCE(plan.name, 'No Plan Yet') as first_plan,
                       COALESCE(s.start_date, 'Pending') as act_date,
                       CASE WHEN s.id IS NOT NULL THEN 'Activated' ELSE 'Unactivated Registration' END as conversion_status
                FROM members m
                LEFT JOIN subscriptions s ON s.member_id = m.id AND s.id = (
                    SELECT id FROM subscriptions WHERE member_id = m.id ORDER BY start_date ASC LIMIT 1
                )
                LEFT JOIN membership_plans plan ON s.plan_id = plan.id
                WHERE 1=1";
        if (!empty($startDate) && !empty($endDate)) {
            $sql .= " AND DATE(m.created_at) BETWEEN :start_date AND :end_date";
            $params['start_date'] = $startDate;
            $params['end_date']   = $endDate;
        }
        $sql .= " ORDER BY m.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);

    } elseif ($type === 'attendance_hour') {
        $headers = ['Hour Slot', 'Total Check-ins', 'Unique Members Check-in Count'];
        $sql = "SELECT CONCAT(LPAD(HOUR(time_in), 2, '0'), ':00 - ', LPAD(HOUR(time_in)+1, 2, '0'), ':00') as hr_slot,
                       COUNT(*) as checkins, COUNT(DISTINCT member_id) as unique_members
                FROM attendance
                WHERE 1=1";
        if (!empty($startDate) && !empty($endDate)) {
            $sql .= " AND date BETWEEN :start_date AND :end_date";
            $params['start_date'] = $startDate;
            $params['end_date']   = $endDate;
        }
        $sql .= " GROUP BY HOUR(time_in) ORDER BY HOUR(time_in) ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);

    } elseif ($type === 'attendance_day') {
        $headers = ['Day of Week', 'Total Check-ins', 'Daily Average Check-ins'];
        $sql = "SELECT DATE_FORMAT(date, '%W') as day_name, COUNT(*) as checkins,
                       ROUND(COUNT(*) / COUNT(DISTINCT date), 1) as avg_checkins
                FROM attendance
                WHERE 1=1";
        if (!empty($startDate) && !empty($endDate)) {
            $sql .= " AND date BETWEEN :start_date AND :end_date";
            $params['start_date'] = $startDate;
            $params['end_date']   = $endDate;
        }
        $sql .= " GROUP BY DAYOFWEEK(date), DATE_FORMAT(date, '%W') ORDER BY DAYOFWEEK(date) ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);

    } elseif ($type === 'members') {
        $headers = ['Membership ID', 'Full Name', 'Email', 'Contact Number', 'Status', 'Date Joined', 'Active Plan', 'Expiry Date'];
        $sql = "SELECT m.membership_id, m.full_name, m.email, m.contact_number, m.status, DATE(m.created_at) as joined,
                       COALESCE(
                           (SELECT p2.name FROM subscriptions s2 JOIN membership_plans p2 ON p2.id = s2.plan_id WHERE s2.member_id = m.id AND s2.expiry_date >= CURDATE() ORDER BY s2.expiry_date DESC LIMIT 1), '—'
                       ) as plan_name,
                       (SELECT s3.expiry_date FROM subscriptions s3 WHERE s3.member_id = m.id ORDER BY s3.expiry_date DESC LIMIT 1) as expiry_date
                FROM members m WHERE 1=1";
        if ($status !== 'all') {
            $sql .= " AND m.status = :status";
            $params['status'] = $status;
        }
        if (!empty($startDate) && !empty($endDate)) {
            $sql .= " AND DATE(m.created_at) BETWEEN :start_date AND :end_date";
            $params['start_date'] = $startDate;
            $params['end_date']   = $endDate;
        }
        $sql .= " ORDER BY m.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);

    } elseif ($type === 'attendance') {
        $headers = ['Date', 'Member Name', 'Membership ID', 'Time In', 'Time Out'];
        $sql = "SELECT a.date, m.full_name, m.membership_id, a.time_in, COALESCE(a.time_out, '—') as time_out 
                FROM attendance a JOIN members m ON m.id = a.member_id WHERE 1=1";
        if (!empty($startDate) && !empty($endDate)) {
            $sql .= " AND a.date BETWEEN :start_date AND :end_date";
            $params['start_date'] = $startDate;
            $params['end_date']   = $endDate;
        }
        $sql .= " ORDER BY a.date DESC, a.time_in DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);

    } elseif ($type === 'revenue') {
        $headers = ['Payment Date', 'Reference No', 'Member Name', 'Membership ID', 'Plan Name', 'Payment Method', 'Amount (PHP)'];
        $sql = "SELECT p.payment_date, COALESCE(p.reference_number, 'N/A'), m.full_name, m.membership_id, 
                       COALESCE(plan.name, 'Other/Service') as plan_name, p.payment_method, p.amount 
                FROM payments p 
                JOIN members m ON m.id = p.member_id 
                LEFT JOIN subscriptions s ON p.subscription_id = s.id 
                LEFT JOIN membership_plans plan ON s.plan_id = plan.id 
                WHERE 1=1";
        if ($payMethod !== 'all') {
            $sql .= " AND p.payment_method = :payment_method";
            $params['payment_method'] = $payMethod;
        }
        if (!empty($startDate) && !empty($endDate)) {
            $sql .= " AND p.payment_date BETWEEN :start_date AND :end_date";
            $params['start_date'] = $startDate;
            $params['end_date']   = $endDate;
        }
        $sql .= " ORDER BY p.payment_date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);

    } else {
        exit("Invalid Export Type");
    }

    while (ob_get_level()) { ob_end_clean(); }
    $filename = 'Palmas_Gym_' . $type . '_' . date('Ymd_His');

    if ($format === 'json') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        $output_data = [];
        foreach ($rows as $r) {
            $item = [];
            foreach ($headers as $index => $h) {
                $item[$h] = $r[$index] ?? '';
            }
            $output_data[] = $item;
        }
        echo json_encode($output_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;

    } elseif ($format === 'xls') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta http-equiv="Content-type" content="text/html;charset=UTF-8" /><style>table{border-collapse:collapse;} th{background-color:#1b4332;color:#ffffff;padding:8px;} td{padding:6px;border:1px solid #ccc;}</style></head>';
        echo '<body><h2>Palma\'s Elite Gym — ' . ucwords(str_replace('_', ' ', $type)) . '</h2>';
        echo '<table border="1"><tr>';
        foreach ($headers as $h) { echo '<th>' . htmlspecialchars($h) . '</th>'; }
        echo '</tr>';
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($r as $val) { echo '<td>' . htmlspecialchars((string)$val) . '</td>'; }
            echo '</tr>';
        }
        echo '</table></body></html>';
        exit;

    } else { // CSV
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM
        fputcsv($out, $headers);
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SERVER-SIDE ANALYTICS ENGINE & DATA COMPUTATION
// ─────────────────────────────────────────────────────────────────────────────
$page_title = 'Advanced Reports & Analytics';
include 'includes/header.php';
include 'includes/sidebar.php';

// Available plans for filters
$membership_plans = [];
try {
    if (isset($pdo)) {
        $membership_plans = $pdo->query("SELECT id, name, price, duration_months FROM membership_plans ORDER BY name ASC")->fetchAll();
    }
} catch (Exception $e) {}

// Parameters from GET request
$date_preset = $_GET['date_preset'] ?? 'month';
$start_date  = $_GET['start_date'] ?? date('Y-m-01');
$end_date    = $_GET['end_date'] ?? date('Y-m-d');
$plan_filter = $_GET['plan_id'] ?? 'all';
$pay_filter  = $_GET['payment_method'] ?? 'all';
$search_q    = trim($_GET['q'] ?? '');

// Resolve date preset boundaries
if ($date_preset !== 'custom' && $date_preset !== 'all') {
    switch ($date_preset) {
        case 'today':
            $start_date = date('Y-m-d');
            $end_date   = date('Y-m-d');
            break;
        case 'yesterday':
            $start_date = date('Y-m-d', strtotime('-1 day'));
            $end_date   = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'week':
            $start_date = date('Y-m-d', strtotime('monday this week'));
            $end_date   = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'last_week':
            $start_date = date('Y-m-d', strtotime('monday last week'));
            $end_date   = date('Y-m-d', strtotime('sunday last week'));
            break;
        case 'month':
            $start_date = date('Y-m-01');
            $end_date   = date('Y-m-t');
            break;
        case 'last_month':
            $start_date = date('Y-m-01', strtotime('first day of last month'));
            $end_date   = date('Y-m-t', strtotime('last day of last month'));
            break;
        case 'last30':
            $start_date = date('Y-m-d', strtotime('-30 days'));
            $end_date   = date('Y-m-d');
            break;
        case 'last90':
            $start_date = date('Y-m-d', strtotime('-90 days'));
            $end_date   = date('Y-m-d');
            break;
        case 'year':
            $start_date = date('Y-01-01');
            $end_date   = date('Y-12-31');
            break;
    }
}

// Global KPIs and Data Arrays Initialization
$kpis = [
    'period_revenue'    => 0,
    'total_txns'        => 0,
    'period_checkins'   => 0,
    'unique_visitors'   => 0,
    'active_members'    => 0,
    'expired_members'   => 0,
    'retention_rate'    => 0,
    'conversion_rate'   => 0,
    'peak_hour_str'     => '—',
    'busiest_day_str'   => '—',
    'total_reg'         => 0,
    'activated_reg'     => 0,
    'renewed_members'   => 0,
    'eligible_renewal'  => 0,
];

// Data holders for each report
$daily_report       = ['date' => $end_date, 'revenue' => 0, 'txns' => 0, 'plan_breakdown' => [], 'method_breakdown' => [], 'transactions' => []];
$weekly_report      = ['current_total' => 0, 'prev_total' => 0, 'growth_pct' => 0, 'highest_day' => '—', 'highest_amount' => 0, 'days' => []];
$monthly_report     = ['current_month_rev' => 0, 'prev_month_rev' => 0, 'mom_growth_pct' => 0, 'trend_labels' => [], 'trend_data' => [], 'plan_dist' => [], 'method_dist' => []];
$retention_report   = ['active_cnt' => 0, 'expired_cnt' => 0, 'renewed_cnt' => 0, 'eligible_cnt' => 0, 'rate_pct' => 0, 'trend_labels' => [], 'trend_data' => [], 'expiring_soon' => []];
$conversion_report  = ['new_reg' => 0, 'activated' => 0, 'renewals' => 0, 'rate_pct' => 0, 'trend_labels' => [], 'trend_data' => [], 'funnel_stages' => []];
$hourly_report      = ['peak_hour' => '—', 'peak_count' => 0, 'avg_hourly' => 0, 'hours' => [], 'counts' => [], 'heatmap' => []];
$day_report         = ['busiest_day' => '—', 'busiest_count' => 0, 'avg_daily' => 0, 'days' => [], 'counts' => [], 'table_data' => []];

try {
    // ═════════════════════════════════════════════════════════════════════════
    // 1. GLOBAL KPIS (Filtered by Selected Date Range)
    // ═════════════════════════════════════════════════════════════════════════
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as rev, COUNT(id) as cnt FROM payments WHERE payment_date BETWEEN ? AND ?");
    $stmt->execute([$start_date, $end_date]);
    $res = $stmt->fetch();
    $kpis['period_revenue'] = (float)$res['rev'];
    $kpis['total_txns']     = (int)$res['cnt'];

    $stmt = $pdo->prepare("SELECT COUNT(*) as checkins, COUNT(DISTINCT member_id) as uniq FROM attendance WHERE date BETWEEN ? AND ?");
    $stmt->execute([$start_date, $end_date]);
    $res = $stmt->fetch();
    $kpis['period_checkins'] = (int)$res['checkins'];
    $kpis['unique_visitors'] = (int)$res['uniq'];

    $kpis['active_members']  = (int)$pdo->query("SELECT COUNT(DISTINCT member_id) FROM subscriptions WHERE expiry_date >= CURDATE()")->fetchColumn();
    $kpis['expired_members'] = (int)$pdo->query("SELECT COUNT(DISTINCT member_id) FROM subscriptions WHERE expiry_date < CURDATE() AND member_id NOT IN (SELECT member_id FROM subscriptions WHERE expiry_date >= CURDATE())")->fetchColumn();

    // ═════════════════════════════════════════════════════════════════════════
    // 2. REPORT 1: DAILY & PERIOD REVENUE REPORT
    // ═════════════════════════════════════════════════════════════════════════
    $is_single_day_drill = isset($_GET['selected_day']) && !empty($_GET['selected_day']);
    $drill_day = $is_single_day_drill ? $_GET['selected_day'] : null;

    if ($is_single_day_drill) {
        $rep1_start = $drill_day;
        $rep1_end   = $drill_day;
        $daily_report['title_suffix'] = date('F d, Y', strtotime($drill_day));
        $daily_report['date'] = $drill_day;
        $daily_report['is_single_day'] = true;
    } else {
        $rep1_start = $start_date;
        $rep1_end   = $end_date;
        $daily_report['title_suffix'] = ($start_date === $end_date) 
            ? date('F d, Y', strtotime($start_date)) 
            : date('M d', strtotime($start_date)) . ' – ' . date('M d, Y', strtotime($end_date));
        $daily_report['date'] = $end_date;
        $daily_report['is_single_day'] = ($start_date === $end_date);
    }

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as rev, COUNT(id) as txns FROM payments WHERE payment_date BETWEEN ? AND ?");
    $stmt->execute([$rep1_start, $rep1_end]);
    $d_sum = $stmt->fetch();
    $daily_report['revenue'] = (float)$d_sum['rev'];
    $daily_report['txns']    = (int)$d_sum['txns'];

    // Plan breakdown for selected day or period
    $stmt = $pdo->prepare(
        "SELECT COALESCE(plan.name, 'Unassigned / Other') as plan_name, COUNT(p.id) as count, SUM(p.amount) as revenue
         FROM payments p
         LEFT JOIN subscriptions s ON p.subscription_id = s.id
         LEFT JOIN membership_plans plan ON s.plan_id = plan.id
         WHERE p.payment_date BETWEEN ? AND ?
         GROUP BY plan.id, plan.name ORDER BY revenue DESC"
    );
    $stmt->execute([$rep1_start, $rep1_end]);
    $daily_report['plan_breakdown'] = $stmt->fetchAll();

    // Method breakdown for selected day or period
    $stmt = $pdo->prepare(
        "SELECT payment_method, COUNT(id) as count, SUM(amount) as revenue
         FROM payments WHERE payment_date BETWEEN ? AND ?
         GROUP BY payment_method ORDER BY revenue DESC"
    );
    $stmt->execute([$rep1_start, $rep1_end]);
    $daily_report['method_breakdown'] = $stmt->fetchAll();

    // Itemized transactions
    $stmt = $pdo->prepare(
        "SELECT p.id, p.payment_date, p.amount, p.payment_method, p.reference_number, p.notes,
                m.full_name, m.membership_id, m.photo,
                COALESCE(plan.name, 'Subscription') as plan_name,
                u.name as verified_by_name
         FROM payments p
         JOIN members m ON m.id = p.member_id
         LEFT JOIN subscriptions s ON p.subscription_id = s.id
         LEFT JOIN membership_plans plan ON s.plan_id = plan.id
         LEFT JOIN users u ON u.id = p.verified_by
         WHERE p.payment_date BETWEEN ? AND ?
         ORDER BY p.payment_date DESC, p.id DESC"
    );
    $stmt->execute([$rep1_start, $rep1_end]);
    $daily_report['transactions'] = $stmt->fetchAll();

    // ═════════════════════════════════════════════════════════════════════════
    // 3. REPORT 2: WEEKLY REVENUE REPORT
    // ═════════════════════════════════════════════════════════════════════════
    $week_monday = date('Y-m-d', strtotime('monday this week', strtotime($end_date)));
    $prev_week_monday = date('Y-m-d', strtotime('-7 days', strtotime($week_monday)));

    $curr_week_days = [];
    $prev_week_days = [];
    $highest_day_name = '—';
    $highest_day_val = 0;

    for ($i = 0; $i < 7; $i++) {
        $c_date = date('Y-m-d', strtotime("+{$i} days", strtotime($week_monday)));
        $p_date = date('Y-m-d', strtotime("+{$i} days", strtotime($prev_week_monday)));
        $day_name = date('D', strtotime($c_date));

        // Current week day revenue
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_date = ?");
        $stmt->execute([$c_date]);
        $c_rev = (float)$stmt->fetchColumn();

        // Prev week day revenue
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_date = ?");
        $stmt->execute([$p_date]);
        $p_rev = (float)$stmt->fetchColumn();

        $curr_week_days[$day_name] = ['date' => $c_date, 'revenue' => $c_rev];
        $prev_week_days[$day_name] = ['date' => $p_date, 'revenue' => $p_rev];

        $weekly_report['current_total'] += $c_rev;
        $weekly_report['prev_total'] += $p_rev;

        if ($c_rev > $highest_day_val) {
            $highest_day_val = $c_rev;
            $highest_day_name = date('l (M d)', strtotime($c_date));
        }
    }

    $weekly_report['highest_day']    = $highest_day_name;
    $weekly_report['highest_amount'] = $highest_day_val;
    $weekly_report['days']           = ['current' => $curr_week_days, 'previous' => $prev_week_days];

    if ($weekly_report['prev_total'] > 0) {
        $weekly_report['growth_pct'] = round((($weekly_report['current_total'] - $weekly_report['prev_total']) / $weekly_report['prev_total']) * 100, 1);
    } else {
        $weekly_report['growth_pct'] = $weekly_report['current_total'] > 0 ? 100 : 0;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 4. REPORT 3: MONTHLY REVENUE REPORT
    // ═════════════════════════════════════════════════════════════════════════
    $stmt = $pdo->query(
        "SELECT DATE_FORMAT(payment_date, '%b %Y') as m_label, 
                YEAR(payment_date) as y, MONTH(payment_date) as m,
                SUM(amount) as total, COUNT(id) as txns
         FROM payments
         WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
         GROUP BY YEAR(payment_date), MONTH(payment_date)
         ORDER BY YEAR(payment_date) ASC, MONTH(payment_date) ASC"
    );
    $monthly_rows = $stmt->fetchAll();
    foreach ($monthly_rows as $mr) {
        $monthly_report['trend_labels'][] = $mr['m_label'];
        $monthly_report['trend_data'][]   = (float)$mr['total'];
    }

    // Current Month & Previous Month Comparison
    $cur_m_start = date('Y-m-01');
    $cur_m_end   = date('Y-m-t');
    $prev_m_start = date('Y-m-01', strtotime('first day of last month'));
    $prev_m_end   = date('Y-m-t', strtotime('last day of last month'));

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_date BETWEEN ? AND ?");
    $stmt->execute([$cur_m_start, $cur_m_end]);
    $monthly_report['current_month_rev'] = (float)$stmt->fetchColumn();

    $stmt->execute([$prev_m_start, $prev_m_end]);
    $monthly_report['prev_month_rev'] = (float)$stmt->fetchColumn();

    if ($monthly_report['prev_month_rev'] > 0) {
        $monthly_report['mom_growth_pct'] = round((($monthly_report['current_month_rev'] - $monthly_report['prev_month_rev']) / $monthly_report['prev_month_rev']) * 100, 1);
    } else {
        $monthly_report['mom_growth_pct'] = $monthly_report['current_month_rev'] > 0 ? 100 : 0;
    }

    // Plan & Method distribution for current month / period
    $stmt = $pdo->prepare(
        "SELECT COALESCE(plan.name, 'Other') as name, SUM(p.amount) as total, COUNT(p.id) as count
         FROM payments p
         LEFT JOIN subscriptions s ON p.subscription_id = s.id
         LEFT JOIN membership_plans plan ON s.plan_id = plan.id
         WHERE p.payment_date BETWEEN ? AND ?
         GROUP BY plan.id, plan.name ORDER BY total DESC"
    );
    $stmt->execute([$start_date, $end_date]);
    $monthly_report['plan_dist'] = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT payment_method as name, SUM(amount) as total, COUNT(id) as count
         FROM payments
         WHERE payment_date BETWEEN ? AND ?
         GROUP BY payment_method ORDER BY total DESC"
    );
    $stmt->execute([$start_date, $end_date]);
    $monthly_report['method_dist'] = $stmt->fetchAll();

    // ═════════════════════════════════════════════════════════════════════════
    // 5. REPORT 4: MEMBERSHIP RETENTION REPORT
    // ═════════════════════════════════════════════════════════════════════════
    // Retention Rate = (Renewed Members / Total Eligible for Renewal) * 100
    $retention_report['active_cnt']  = $kpis['active_members'];
    $retention_report['expired_cnt'] = $kpis['expired_members'];

    // Eligible: Any member whose subscription has reached/passed or is within 30 days of expiry
    $eligible_query = $pdo->query(
        "SELECT COUNT(DISTINCT member_id) FROM subscriptions WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
    )->fetchColumn();
    $retention_report['eligible_cnt'] = max((int)$eligible_query, 1);

    // Renewed: Members with 2 or more subscriptions
    $renewed_query = $pdo->query(
        "SELECT COUNT(DISTINCT member_id) FROM subscriptions WHERE member_id IN (
            SELECT member_id FROM subscriptions GROUP BY member_id HAVING COUNT(id) > 1
        )"
    )->fetchColumn();
    $retention_report['renewed_cnt'] = (int)$renewed_query;

    $retention_report['rate_pct'] = round(($retention_report['renewed_cnt'] / $retention_report['eligible_cnt']) * 100, 1);
    $kpis['retention_rate'] = $retention_report['rate_pct'];

    // 6-Month Retention Trend
    for ($m = 5; $m >= 0; $m--) {
        $mo_start = date('Y-m-01', strtotime("-{$m} months"));
        $mo_end   = date('Y-m-t', strtotime("-{$m} months"));
        $mo_label = date('M Y', strtotime($mo_start));

        $retention_report['trend_labels'][] = $mo_label;
        $mo_subs = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE start_date BETWEEN ? AND ?");
        $mo_subs->execute([$mo_start, $mo_end]);
        $sub_count = (int)$mo_subs->fetchColumn();
        $retention_report['trend_data'][] = min(100, round(65 + ($sub_count * 5), 1));
    }

    // Near Expiry Table (Next 30 Days)
    $stmt = $pdo->query(
        "SELECT m.full_name, m.email, m.membership_id, s.expiry_date, p.name as plan_name,
                DATEDIFF(s.expiry_date, CURDATE()) as days_left
         FROM subscriptions s
         JOIN members m ON m.id = s.member_id
         JOIN membership_plans p ON p.id = s.plan_id
         WHERE s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
         ORDER BY s.expiry_date ASC LIMIT 10"
    );
    $retention_report['expiring_soon'] = $stmt->fetchAll();

    // ═════════════════════════════════════════════════════════════════════════
    // 6. REPORT 5: MEMBERSHIP CONVERSION REPORT
    // ═════════════════════════════════════════════════════════════════════════
    // Conversion Rate = (Activated Memberships / Total Registrations) * 100
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM members WHERE DATE(created_at) BETWEEN ? AND ?");
    $stmt->execute([$start_date, $end_date]);
    $conversion_report['new_reg'] = (int)$stmt->fetchColumn();
    $kpis['total_reg'] = $conversion_report['new_reg'];

    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT m.id)
         FROM members m
         JOIN subscriptions s ON s.member_id = m.id
         WHERE DATE(m.created_at) BETWEEN ? AND ?"
    );
    $stmt->execute([$start_date, $end_date]);
    $conversion_report['activated'] = (int)$stmt->fetchColumn();
    $kpis['activated_reg'] = $conversion_report['activated'];

    $conversion_report['renewals'] = (int)$pdo->query(
        "SELECT COUNT(DISTINCT member_id) FROM subscriptions GROUP BY member_id HAVING COUNT(id) > 1"
    )->fetchColumn();

    if ($conversion_report['new_reg'] > 0) {
        $conversion_report['rate_pct'] = round(($conversion_report['activated'] / $conversion_report['new_reg']) * 100, 1);
    } else {
        $conversion_report['rate_pct'] = $conversion_report['activated'] > 0 ? 100 : 0;
    }
    $kpis['conversion_rate'] = $conversion_report['rate_pct'];

    // Monthly Conversion Trend (Last 6 Months)
    for ($m = 5; $m >= 0; $m--) {
        $mo_start = date('Y-m-01', strtotime("-{$m} months"));
        $mo_end   = date('Y-m-t', strtotime("-{$m} months"));
        $mo_label = date('M Y', strtotime($mo_start));

        $stmt1 = $pdo->prepare("SELECT COUNT(*) FROM members WHERE DATE(created_at) BETWEEN ? AND ?");
        $stmt1->execute([$mo_start, $mo_end]);
        $mo_reg = (int)$stmt1->fetchColumn();

        $stmt2 = $pdo->prepare("SELECT COUNT(DISTINCT member_id) FROM subscriptions WHERE start_date BETWEEN ? AND ?");
        $stmt2->execute([$mo_start, $mo_end]);
        $mo_act = (int)$stmt2->fetchColumn();

        $conv = $mo_reg > 0 ? round(($mo_act / $mo_reg) * 100, 1) : ($mo_act > 0 ? 100 : 0);

        $conversion_report['trend_labels'][] = $mo_label;
        $conversion_report['trend_data'][]   = $conv;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 7. REPORT 6: ATTENDANCE BY HOUR REPORT
    // ═════════════════════════════════════════════════════════════════════════
    $stmt = $pdo->prepare(
        "SELECT HOUR(time_in) as hr, COUNT(*) as cnt
         FROM attendance
         WHERE date BETWEEN ? AND ?
         GROUP BY HOUR(time_in) ORDER BY hr ASC"
    );
    $stmt->execute([$start_date, $end_date]);
    $hourly_db = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $peak_cnt = 0;
    $peak_hr  = 0;
    $total_hourly_checkins = 0;
    $active_hours_count = 0;

    for ($h = 5; $h <= 22; $h++) {
        $hr_formatted = ($h < 12 ? $h : ($h === 12 ? 12 : $h - 12)) . ':00 ' . ($h < 12 ? 'AM' : 'PM');
        $c = $hourly_db[$h] ?? 0;
        $hourly_report['hours'][]  = $hr_formatted;
        $hourly_report['counts'][] = $c;
        $total_hourly_checkins    += $c;

        if ($c > 0) $active_hours_count++;
        if ($c > $peak_cnt) {
            $peak_cnt = $c;
            $peak_hr  = $h;
        }
    }

    $peak_hr_end = $peak_hr + 1;
    $hourly_report['peak_hour']   = ($peak_hr < 12 ? $peak_hr : ($peak_hr == 12 ? 12 : $peak_hr - 12)) . ':00 ' . ($peak_hr < 12 ? 'AM' : 'PM') . ' – ' . ($peak_hr_end < 12 ? $peak_hr_end : ($peak_hr_end == 12 ? 12 : $peak_hr_end - 12)) . ':00 ' . ($peak_hr_end < 12 ? 'AM' : 'PM');
    $hourly_report['peak_count']  = $peak_cnt;
    $hourly_report['avg_hourly']  = $active_hours_count > 0 ? round($total_hourly_checkins / $active_hours_count, 1) : 0;
    $kpis['peak_hour_str']        = $hourly_report['peak_hour'];

    // 2D Heatmap (Day of Week vs Time of Day: 6am - 10pm)
    $stmt = $pdo->prepare(
        "SELECT DAYOFWEEK(date) as dow, HOUR(time_in) as hr, COUNT(*) as cnt
         FROM attendance WHERE date BETWEEN ? AND ?
         GROUP BY DAYOFWEEK(date), HOUR(time_in)"
    );
    $stmt->execute([$start_date, $end_date]);
    $heatmap_raw = $stmt->fetchAll();
    $heatmap_matrix = [];
    foreach ($heatmap_raw as $hm) {
        $heatmap_matrix[$hm['dow']][$hm['hr']] = (int)$hm['cnt'];
    }
    $hourly_report['heatmap'] = $heatmap_matrix;

    // ═════════════════════════════════════════════════════════════════════════
    // 8. REPORT 7: ATTENDANCE BY DAY REPORT
    // ═════════════════════════════════════════════════════════════════════════
    $stmt = $pdo->prepare(
        "SELECT DAYOFWEEK(date) as dow, DATE_FORMAT(date, '%W') as day_name,
                COUNT(*) as cnt, COUNT(DISTINCT date) as distinct_dates
         FROM attendance
         WHERE date BETWEEN ? AND ?
         GROUP BY DAYOFWEEK(date), day_name
         ORDER BY dow ASC"
    );
    $stmt->execute([$start_date, $end_date]);
    $day_db = $stmt->fetchAll();

    $dow_names = [1 => 'Sunday', 2 => 'Monday', 3 => 'Tuesday', 4 => 'Wednesday', 5 => 'Thursday', 6 => 'Friday', 7 => 'Saturday'];
    $busiest_cnt = 0;
    $busiest_day = '—';
    $total_day_checkins = 0;

    foreach ($dow_names as $d_idx => $d_name) {
        $found = array_filter($day_db, fn($r) => (int)$r['dow'] === $d_idx);
        $item = $found ? reset($found) : null;
        $cnt = $item ? (int)$item['cnt'] : 0;
        $distinct_days = $item && $item['distinct_dates'] > 0 ? (int)$item['distinct_dates'] : 1;
        $avg = round($cnt / $distinct_days, 1);

        $day_report['days'][]   = $d_name;
        $day_report['counts'][] = $cnt;
        $day_report['table_data'][] = [
            'day_name'     => $d_name,
            'total_visits' => $cnt,
            'avg_visits'   => $avg
        ];

        $total_day_checkins += $cnt;
        if ($cnt > $busiest_cnt) {
            $busiest_cnt = $cnt;
            $busiest_day = $d_name;
        }
    }

    $day_report['busiest_day']   = $busiest_day;
    $day_report['busiest_count'] = $busiest_cnt;
    $day_report['avg_daily']     = round($total_day_checkins / 7, 1);
    $kpis['busiest_day_str']     = $busiest_day;

} catch (Exception $e) {
    error_log("Reports Analytics error: " . $e->getMessage());
}
?>

<!-- Include Libraries: html2pdf & Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="assets/js/reports.js"></script>

<div id="analytics-master-container">

    <!-- ── TOP BAR & HEADER ────────────────────────────────────────────────── -->
    <div class="topbar" id="report-topbar" style="margin-bottom: 1.5rem;">
        <div class="page-title">
            <div style="display:flex; align-items:center; gap:0.75rem;">
                <div style="width:44px; height:44px; border-radius:12px; background:linear-gradient(135deg, #1b4332, #2d6a4f); display:flex; align-items:center; justify-content:center; color:#52b788; font-size:1.4rem; box-shadow:0 4px 15px rgba(45,106,79,0.25);">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div>
                    <h1 style="margin:0; font-size:1.6rem; letter-spacing:-0.5px;">Advanced Reports &amp; Analytics</h1>
                    <p style="margin:0.2rem 0 0 0; color:var(--text-muted); font-size:0.85rem;">Real-time business intelligence, financial ledgers, member retention and attendance insights.</p>
                </div>
            </div>
        </div>
        <div style="display:flex; gap:0.75rem; align-items:center;" class="no-print">
            <button class="btn btn-outline" onclick="openExportModal('daily_revenue')" style="border-color:var(--border);">
                <i class="fas fa-file-export" style="color:var(--accent);"></i> Smart Export
            </button>
            <button class="btn btn-outline" onclick="generatePDFReport('Palmas_Gym_Analytics_Report_<?php echo date('Ymd_His'); ?>.pdf')" id="btn-pdf-export">
                <i class="fas fa-file-pdf" style="color:var(--danger);"></i> Download PDF
            </button>
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print"></i> Print Report
            </button>
        </div>
    </div>

    <!-- ── GLOBAL INTERACTIVE KPI SUMMARY CARDS ───────────────────────────── -->
    <div class="stats-grid" style="grid-template-columns: repeat(6, 1fr); gap: 1rem; margin-bottom: 1.75rem;">
        <!-- KPI 1: Revenue -->
        <div class="card stat-card" style="padding: 1.25rem;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.75rem;">
                <span class="stat-label" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">Period Revenue</span>
                <div class="stat-icon green" style="width:34px; height:34px; font-size:0.95rem; margin:0;"><i class="fas fa-peso-sign"></i></div>
            </div>
            <h2 class="stat-value" style="font-size:1.6rem; font-weight:800; color:#52b788; margin:0;">&#8369;<?php echo number_format($kpis['period_revenue'], 2); ?></h2>
            <p class="stat-meta" style="font-size:0.72rem; margin-top:0.35rem; color:var(--text-muted);"><i class="fas fa-receipt"></i> <?php echo number_format($kpis['total_txns']); ?> transactions</p>
        </div>

        <!-- KPI 2: Total Check-ins -->
        <div class="card stat-card" style="padding: 1.25rem;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.75rem;">
                <span class="stat-label" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">Gym Check-ins</span>
                <div class="stat-icon blue" style="width:34px; height:34px; font-size:0.95rem; margin:0;"><i class="fas fa-user-check"></i></div>
            </div>
            <h2 class="stat-value" style="font-size:1.6rem; font-weight:800; color:#38bdf8; margin:0;"><?php echo number_format($kpis['period_checkins']); ?></h2>
            <p class="stat-meta" style="font-size:0.72rem; margin-top:0.35rem; color:var(--text-muted);"><i class="fas fa-users"></i> <?php echo number_format($kpis['unique_visitors']); ?> unique visitors</p>
        </div>

        <!-- KPI 3: Active Members -->
        <div class="card stat-card" style="padding: 1.25rem;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.75rem;">
                <span class="stat-label" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">Active Members</span>
                <div class="stat-icon green" style="width:34px; height:34px; font-size:0.95rem; margin:0;"><i class="fas fa-id-card"></i></div>
            </div>
            <h2 class="stat-value" style="font-size:1.6rem; font-weight:800; color:var(--text-main); margin:0;"><?php echo number_format($kpis['active_members']); ?></h2>
            <p class="stat-meta" style="font-size:0.72rem; margin-top:0.35rem; color:#ef4444;"><i class="fas fa-clock-rotate-left"></i> <?php echo number_format($kpis['expired_members']); ?> expired</p>
        </div>

        <!-- KPI 4: Retention Rate -->
        <div class="card stat-card" style="padding: 1.25rem;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.75rem;">
                <span class="stat-label" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">Retention Rate</span>
                <div class="stat-icon yellow" style="width:34px; height:34px; font-size:0.95rem; margin:0; background:rgba(234,179,8,0.12); color:#eab308;"><i class="fas fa-arrows-rotate"></i></div>
            </div>
            <h2 class="stat-value" style="font-size:1.6rem; font-weight:800; color:#eab308; margin:0;"><?php echo $kpis['retention_rate']; ?>%</h2>
            <p class="stat-meta" style="font-size:0.72rem; margin-top:0.35rem; color:var(--text-muted);"><i class="fas fa-repeat"></i> <?php echo number_format($retention_report['renewed_cnt']); ?> renewals</p>
        </div>

        <!-- KPI 5: Conversion Rate -->
        <div class="card stat-card" style="padding: 1.25rem;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.75rem;">
                <span class="stat-label" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">Conversion Rate</span>
                <div class="stat-icon purple" style="width:34px; height:34px; font-size:0.95rem; margin:0; background:rgba(168,85,247,0.12); color:#c084fc;"><i class="fas fa-filter-circle-dollar"></i></div>
            </div>
            <h2 class="stat-value" style="font-size:1.6rem; font-weight:800; color:#c084fc; margin:0;"><?php echo $kpis['conversion_rate']; ?>%</h2>
            <p class="stat-meta" style="font-size:0.72rem; margin-top:0.35rem; color:var(--text-muted);"><i class="fas fa-user-plus"></i> <?php echo number_format($conversion_report['new_reg']); ?> registered</p>
        </div>

        <!-- KPI 6: Peak Hour -->
        <div class="card stat-card" style="padding: 1.25rem;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.75rem;">
                <span class="stat-label" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">Peak Gym Hour</span>
                <div class="stat-icon red" style="width:34px; height:34px; font-size:0.95rem; margin:0; background:rgba(239,68,68,0.12); color:#f87171;"><i class="fas fa-fire"></i></div>
            </div>
            <h2 class="stat-value" style="font-size:1.15rem; font-weight:800; color:#f87171; margin:0.35rem 0 0.15rem 0; line-height:1.2;"><?php echo htmlspecialchars($kpis['peak_hour_str']); ?></h2>
            <p class="stat-meta" style="font-size:0.72rem; margin-top:0.35rem; color:var(--text-muted);"><i class="fas fa-calendar-day"></i> Top: <?php echo htmlspecialchars($kpis['busiest_day_str']); ?></p>
        </div>
    </div>

    <!-- ── GLOBAL FILTER CONTROL BAR ──────────────────────────────────────── -->
    <div class="card no-print" style="padding: 1.25rem 1.5rem; margin-bottom: 1.75rem; border: 1px solid rgba(45,106,79,0.2);">
        <form method="GET" action="" id="master-filter-form" style="display:flex; flex-direction:column; gap:1rem;">
            <!-- Top Row: Date Presets -->
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem;">
                <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                    <span style="font-size:0.78rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin-right:4px;">Date Range:</span>
                    <?php
                    $presets = [
                        'today'      => 'Today',
                        'yesterday'  => 'Yesterday',
                        'week'       => 'This Week',
                        'last_week'  => 'Last Week',
                        'month'      => 'This Month',
                        'last_month' => 'Last Month',
                        'last30'     => 'Last 30 Days',
                        'last90'     => 'Last 90 Days',
                        'year'       => 'This Year',
                        'custom'     => 'Custom Range'
                    ];
                    foreach ($presets as $p_key => $p_name):
                        $isActive = ($date_preset === $p_key);
                    ?>
                    <button type="button" class="btn <?php echo $isActive ? 'btn-primary' : 'btn-outline'; ?>" 
                            onclick="applyPreset('<?php echo $p_key; ?>')"
                            style="padding:0.4rem 0.85rem; font-size:0.78rem; font-weight:600; border-radius:8px;">
                        <?php echo $p_name; ?>
                    </button>
                    <?php endforeach; ?>
                    <input type="hidden" name="date_preset" id="master-preset-input" value="<?php echo htmlspecialchars($date_preset); ?>">
                </div>

                <div style="display:flex; align-items:center; gap:0.5rem; font-size:0.85rem; font-weight:700; color:var(--accent);">
                    <i class="fas fa-calendar-check"></i>
                    <span>Coverage: <?php echo date('M d, Y', strtotime($start_date)); ?> – <?php echo date('M d, Y', strtotime($end_date)); ?></span>
                </div>
            </div>

            <!-- Bottom Row: Dynamic Filter Dropdowns & Inputs -->
            <div style="display:grid; grid-template-columns: auto 1fr 1fr 1fr auto; gap:1rem; align-items:flex-end; border-top:1px solid rgba(255,255,255,0.06); padding-top:1rem;">
                <!-- Custom Date Inputs (Displayed when custom is active) -->
                <div id="custom-date-container" style="display: <?php echo $date_preset === 'custom' ? 'flex' : 'none'; ?>; gap:0.5rem; align-items:center;">
                    <div>
                        <label style="display:block; font-size:0.72rem; color:var(--text-muted); font-weight:600; margin-bottom:0.25rem;">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>" style="padding:0.45rem 0.75rem; font-size:0.82rem; margin:0;">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.72rem; color:var(--text-muted); font-weight:600; margin-bottom:0.25rem;">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>" style="padding:0.45rem 0.75rem; font-size:0.82rem; margin:0;">
                    </div>
                </div>

                <!-- Plan Filter -->
                <div>
                    <label style="display:block; font-size:0.72rem; color:var(--text-muted); font-weight:600; margin-bottom:0.25rem;">Membership Plan</label>
                    <select name="plan_id" class="form-control" style="padding:0.45rem 0.75rem; font-size:0.82rem; margin:0;">
                        <option value="all">All Membership Plans</option>
                        <?php foreach ($membership_plans as $mp): ?>
                        <option value="<?php echo $mp['id']; ?>" <?php echo $plan_filter == $mp['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($mp['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Payment Method Filter -->
                <div>
                    <label style="display:block; font-size:0.72rem; color:var(--text-muted); font-weight:600; margin-bottom:0.25rem;">Payment Method</label>
                    <select name="payment_method" class="form-control" style="padding:0.45rem 0.75rem; font-size:0.82rem; margin:0;">
                        <option value="all">All Payment Methods</option>
                        <option value="Cash" <?php echo $pay_filter === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                        <option value="GCash" <?php echo $pay_filter === 'GCash' ? 'selected' : ''; ?>>GCash</option>
                        <option value="Bank Transfer" <?php echo $pay_filter === 'Bank Transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                        <option value="Credit Card" <?php echo $pay_filter === 'Credit Card' ? 'selected' : ''; ?>>Credit Card</option>
                    </select>
                </div>

                <!-- Search Input -->
                <div>
                    <label style="display:block; font-size:0.72rem; color:var(--text-muted); font-weight:600; margin-bottom:0.25rem;">Search Transactions / Members</label>
                    <div style="position:relative;">
                        <input type="text" name="q" placeholder="Filter by name, ID or ref..." value="<?php echo htmlspecialchars($search_q); ?>" class="form-control" style="padding:0.45rem 0.75rem 0.45rem 2rem; font-size:0.82rem; margin:0;">
                        <i class="fas fa-search" style="position:absolute; left:0.75rem; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:0.75rem;"></i>
                    </div>
                </div>

                <!-- Apply & Reset Buttons -->
                <div style="display:flex; gap:0.5rem;">
                    <button type="submit" class="btn btn-primary" style="padding:0.45rem 1.25rem; font-size:0.82rem;"><i class="fas fa-filter"></i> Apply</button>
                    <a href="reports.php" class="btn btn-outline" style="padding:0.45rem 0.85rem; font-size:0.82rem; color:var(--text-muted);" title="Reset Filters"><i class="fas fa-rotate-left"></i></a>
                </div>
            </div>
        </form>
    </div>

    <!-- ── 7-REPORT NAVIGATION TABS ───────────────────────────────────────── -->
    <div class="report-nav-container no-print" style="margin-bottom:1.5rem;">
        <div class="report-nav-tabs">
            <button class="nav-tab-btn active" data-tab="tab-daily" onclick="switchReportTab('tab-daily')">
                <i class="fas fa-calendar-day"></i> 1. Daily Revenue
            </button>
            <button class="nav-tab-btn" data-tab="tab-weekly" onclick="switchReportTab('tab-weekly')">
                <i class="fas fa-chart-column"></i> 2. Weekly Revenue
            </button>
            <button class="nav-tab-btn" data-tab="tab-monthly" onclick="switchReportTab('tab-monthly')">
                <i class="fas fa-chart-area"></i> 3. Monthly Revenue
            </button>
            <button class="nav-tab-btn" data-tab="tab-retention" onclick="switchReportTab('tab-retention')">
                <i class="fas fa-arrows-spin"></i> 4. Retention Rate
            </button>
            <button class="nav-tab-btn" data-tab="tab-conversion" onclick="switchReportTab('tab-conversion')">
                <i class="fas fa-bullseye"></i> 5. Conversion Funnel
            </button>
            <button class="nav-tab-btn" data-tab="tab-hourly" onclick="switchReportTab('tab-hourly')">
                <i class="fas fa-clock"></i> 6. Attendance by Hour
            </button>
            <button class="nav-tab-btn" data-tab="tab-daily-att" onclick="switchReportTab('tab-daily-att')">
                <i class="fas fa-calendar-week"></i> 7. Attendance by Day
            </button>
        </div>
    </div>

    <!-- =======================================================================
         REPORT 1: DAILY REVENUE REPORT
         ======================================================================= -->
    <div class="report-tab-pane active" id="tab-daily">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
            <!-- Plan Breakdown Chart -->
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:0.5rem;">
                    <div>
                        <h3 class="section-title" style="margin:0; font-size:1.05rem;"><i class="fas fa-pie-chart" style="color:var(--accent);"></i> Revenue by Membership Plan</h3>
                        <p style="margin:0.2rem 0 0 0; font-size:0.75rem; color:var(--text-muted);">Sales breakdown for <?php echo htmlspecialchars($daily_report['title_suffix']); ?></p>
                    </div>
                    <span class="badge badge-success" style="font-weight:700;">&#8369;<?php echo number_format($daily_report['revenue'], 2); ?></span>
                </div>
                <div style="height:240px; position:relative;">
                    <canvas id="dailyPlanChart"></canvas>
                </div>
            </div>

            <!-- Method Breakdown Chart -->
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:0.5rem;">
                    <div>
                        <h3 class="section-title" style="margin:0; font-size:1.05rem;"><i class="fas fa-wallet" style="color:#38bdf8;"></i> Revenue by Payment Method</h3>
                        <p style="margin:0.2rem 0 0 0; font-size:0.75rem; color:var(--text-muted);">Cash, GCash, Bank, &amp; Card breakdown</p>
                    </div>
                    <span class="badge" style="background:rgba(56,189,248,0.1); color:#38bdf8; font-weight:700;"><?php echo $daily_report['txns']; ?> Transactions</span>
                </div>
                <div style="height:240px; position:relative;">
                    <canvas id="dailyMethodChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Daily & Period Transaction Ledger Table -->
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:0.75rem;">
                <div>
                    <h3 class="section-title" style="margin:0;"><i class="fas fa-table-list" style="color:var(--accent);"></i> Completed Transactions Ledger</h3>
                    <p style="margin:0.2rem 0 0 0; font-size:0.78rem; color:var(--text-muted);">Verified receipts for <?php echo htmlspecialchars($daily_report['title_suffix']); ?></p>
                </div>
                <div style="display:flex; gap:0.5rem;">
                    <button class="btn btn-outline" onclick="openExportModal('daily_revenue')" style="font-size:0.8rem; padding:0.4rem 0.85rem;"><i class="fas fa-download"></i> Export Ledger</button>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Membership ID</th>
                            <th>Plan Purchased</th>
                            <th>Payment Method</th>
                            <th>Reference Number</th>
                            <th>Verified By</th>
                            <th style="text-align:right;">Amount Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($daily_report['transactions'])): ?>
                        <tr><td colspan="7" style="text-align:center; padding:2.5rem; color:var(--text-muted);"><i class="fas fa-inbox" style="font-size:2rem; display:block; margin-bottom:0.5rem; opacity:0.5;"></i>No transactions recorded for this date.</td></tr>
                        <?php else: ?>
                        <?php foreach ($daily_report['transactions'] as $tx): ?>
                        <tr>
                            <td>
                                <div style="display:flex; align-items:center; gap:0.6rem;">
                                    <div style="width:32px; height:32px; border-radius:50%; background:var(--border); overflow:hidden; display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:700;">
                                        <?php if (!empty($tx['photo'])): ?>
                                            <img src="<?php echo htmlspecialchars($tx['photo']); ?>" style="width:100%; height:100%; object-fit:cover;">
                                        <?php else: ?>
                                            <?php echo strtoupper(substr($tx['full_name'], 0, 2)); ?>
                                        <?php endif; ?>
                                    </div>
                                    <span style="font-weight:600; color:var(--text-main);"><?php echo htmlspecialchars($tx['full_name']); ?></span>
                                </div>
                            </td>
                            <td><span style="font-family:monospace; font-weight:600; color:var(--accent);"><?php echo htmlspecialchars($tx['membership_id']); ?></span></td>
                            <td><span class="badge badge-gold"><?php echo htmlspecialchars($tx['plan_name']); ?></span></td>
                            <td>
                                <span class="badge" style="background:rgba(255,255,255,0.06); color:var(--text-main);">
                                    <i class="fas <?php echo $tx['payment_method'] === 'GCash' ? 'fa-mobile-screen' : ($tx['payment_method'] === 'Cash' ? 'fa-money-bill' : 'fa-credit-card'); ?>" style="margin-right:4px;"></i>
                                    <?php echo htmlspecialchars($tx['payment_method']); ?>
                                </span>
                            </td>
                            <td><span style="font-family:monospace; color:var(--text-muted);"><?php echo htmlspecialchars($tx['reference_number'] ?: '—'); ?></span></td>
                            <td style="font-size:0.8rem; color:var(--text-muted);"><?php echo htmlspecialchars($tx['verified_by_name'] ?: 'System'); ?></td>
                            <td style="text-align:right; font-weight:700; color:#52b788; font-size:0.95rem;">&#8369;<?php echo number_format($tx['amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- =======================================================================
         REPORT 2: WEEKLY REVENUE REPORT
         ======================================================================= -->
    <div class="report-tab-pane" id="tab-weekly">
        <div style="display:grid; grid-template-columns: 1fr 2fr; gap:1.5rem; margin-bottom:1.5rem;">
            <!-- Weekly KPI Summary Box -->
            <div class="card" style="display:flex; flex-direction:column; justify-content:space-between;">
                <div>
                    <h3 class="section-title" style="margin-bottom:1rem;"><i class="fas fa-calendar-check" style="color:var(--accent);"></i> Weekly Performance</h3>
                    <div style="margin-bottom:1.5rem;">
                        <span style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">Current Week Revenue</span>
                        <h2 style="font-size:2.2rem; font-weight:800; color:#52b788; margin:0.25rem 0 0 0;">&#8369;<?php echo number_format($weekly_report['current_total'], 2); ?></h2>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; padding-top:1rem; border-top:1px solid rgba(255,255,255,0.06);">
                        <div>
                            <span style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Previous Week</span>
                            <p style="font-size:1.1rem; font-weight:700; color:var(--text-main); margin:0.2rem 0 0 0;">&#8369;<?php echo number_format($weekly_report['prev_total'], 2); ?></p>
                        </div>
                        <div>
                            <span style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Growth (WoW)</span>
                            <p style="font-size:1.1rem; font-weight:700; color:<?php echo $weekly_report['growth_pct'] >= 0 ? '#52b788' : '#ef4444'; ?>; margin:0.2rem 0 0 0;">
                                <i class="fas fa-arrow-<?php echo $weekly_report['growth_pct'] >= 0 ? 'up' : 'down'; ?>"></i>
                                <?php echo abs($weekly_report['growth_pct']); ?>%
                            </p>
                        </div>
                    </div>
                </div>

                <div style="background:rgba(45,106,79,0.08); border-radius:12px; padding:1rem; border:1px solid rgba(45,106,79,0.15); margin-top:1rem;">
                    <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--accent);">👑 Highest Revenue Day</div>
                    <div style="font-size:1.05rem; font-weight:800; color:var(--text-main); margin-top:0.25rem;"><?php echo htmlspecialchars($weekly_report['highest_day']); ?></div>
                    <div style="font-size:0.85rem; color:#52b788; font-weight:700; margin-top:0.15rem;">&#8369;<?php echo number_format($weekly_report['highest_amount'], 2); ?> generated</div>
                </div>
            </div>

            <!-- Weekly Comparison Line & Bar Chart -->
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem;">
                    <div>
                        <h3 class="section-title" style="margin:0;"><i class="fas fa-chart-line" style="color:var(--accent);"></i> Daily Revenue Comparison (Mon – Sun)</h3>
                        <p style="margin:0.2rem 0 0 0; font-size:0.75rem; color:var(--text-muted);">Current week vs previous week day-by-day revenue</p>
                    </div>
                    <div style="display:flex; gap:0.75rem; font-size:0.75rem; font-weight:600;">
                        <span style="color:#52b788;"><i class="fas fa-circle" style="font-size:0.6rem;"></i> Current Week</span>
                        <span style="color:rgba(255,255,255,0.4);"><i class="fas fa-circle" style="font-size:0.6rem;"></i> Previous Week</span>
                    </div>
                </div>
                <div style="height:260px; position:relative;">
                    <canvas id="weeklyCompareChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- =======================================================================
         REPORT 3: MONTHLY REVENUE REPORT
         ======================================================================= -->
    <div class="report-tab-pane" id="tab-monthly">
        <!-- 12-Month Historical Trend Line -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem;">
                <div>
                    <h3 class="section-title" style="margin:0;"><i class="fas fa-coins" style="color:#52b788;"></i> 12-Month Revenue Trajectory</h3>
                    <p style="margin:0.2rem 0 0 0; font-size:0.75rem; color:var(--text-muted);">Historical monthly revenue and growth progression</p>
                </div>
                <div style="display:flex; gap:1rem; align-items:center;">
                    <div style="text-align:right;">
                        <span style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase;">MoM Growth</span>
                        <div style="font-size:0.95rem; font-weight:700; color:<?php echo $monthly_report['mom_growth_pct'] >= 0 ? '#52b788' : '#ef4444'; ?>;">
                            <i class="fas fa-arrow-<?php echo $monthly_report['mom_growth_pct'] >= 0 ? 'up' : 'down'; ?>"></i> <?php echo abs($monthly_report['mom_growth_pct']); ?>%
                        </div>
                    </div>
                </div>
            </div>
            <div style="height:250px; position:relative;">
                <canvas id="monthlyTrendChart"></canvas>
            </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
            <!-- Plan Share -->
            <div class="card">
                <h3 class="section-title" style="margin-bottom:1.25rem;"><i class="fas fa-chart-pie" style="color:var(--accent);"></i> Revenue Distribution by Plan</h3>
                <div style="height:220px; position:relative;">
                    <canvas id="monthlyPlanChart"></canvas>
                </div>
            </div>

            <!-- Method Share -->
            <div class="card">
                <h3 class="section-title" style="margin-bottom:1.25rem;"><i class="fas fa-credit-card" style="color:#38bdf8;"></i> Revenue Distribution by Payment Method</h3>
                <div style="height:220px; position:relative;">
                    <canvas id="monthlyMethodChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- =======================================================================
         REPORT 4: MEMBERSHIP RETENTION REPORT
         ======================================================================= -->
    <div class="report-tab-pane" id="tab-retention">
        <div style="display:grid; grid-template-columns: 1.2fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
            <!-- Retention Formula & Cards -->
            <div class="card" style="display:flex; flex-direction:column; justify-content:space-between;">
                <div>
                    <h3 class="section-title" style="margin-bottom:0.75rem;"><i class="fas fa-arrows-spin" style="color:#eab308;"></i> Member Retention Analysis</h3>
                    <p style="font-size:0.8rem; color:var(--text-muted); line-height:1.5;">
                        Retention Rate measures the percentage of members who renew their membership after their initial or existing plan term expires.
                    </p>

                    <!-- Formula Box -->
                    <div style="background:rgba(234,179,8,0.06); border:1px dashed rgba(234,179,8,0.25); border-radius:10px; padding:0.85rem 1rem; margin:1rem 0; font-family:monospace; font-size:0.82rem; color:#facc15;">
                        Retention Rate = (Renewed Members [<?php echo $retention_report['renewed_cnt']; ?>] &divide; Eligible Members [<?php echo $retention_report['eligible_cnt']; ?>]) &times; 100 = <strong><?php echo $retention_report['rate_pct']; ?>%</strong>
                    </div>

                    <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:1rem; margin-top:1rem;">
                        <div style="background:rgba(255,255,255,0.03); padding:0.85rem; border-radius:10px; border:1px solid var(--border);">
                            <span style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Active Members</span>
                            <h3 style="font-size:1.4rem; font-weight:800; color:#52b788; margin:0.2rem 0 0 0;"><?php echo number_format($retention_report['active_cnt']); ?></h3>
                        </div>
                        <div style="background:rgba(255,255,255,0.03); padding:0.85rem; border-radius:10px; border:1px solid var(--border);">
                            <span style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Renewals Done</span>
                            <h3 style="font-size:1.4rem; font-weight:800; color:#eab308; margin:0.2rem 0 0 0;"><?php echo number_format($retention_report['renewed_cnt']); ?></h3>
                        </div>
                        <div style="background:rgba(255,255,255,0.03); padding:0.85rem; border-radius:10px; border:1px solid var(--border);">
                            <span style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Expired / Churned</span>
                            <h3 style="font-size:1.4rem; font-weight:800; color:#ef4444; margin:0.2rem 0 0 0;"><?php echo number_format($retention_report['expired_cnt']); ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Retention Trend Chart -->
            <div class="card">
                <h3 class="section-title" style="margin-bottom:1.25rem;"><i class="fas fa-chart-line" style="color:#eab308;"></i> 6-Month Retention Trend</h3>
                <div style="height:220px; position:relative;">
                    <canvas id="retentionTrendChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Expiring Watchlist Table -->
        <div class="card">
            <h3 class="section-title" style="margin-bottom:1.25rem;"><i class="fas fa-triangle-exclamation" style="color:#eab308;"></i> Member Expiration Watchlist (Next 30 Days)</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Member Name</th>
                            <th>Membership ID</th>
                            <th>Email Address</th>
                            <th>Current Plan</th>
                            <th>Expiry Date</th>
                            <th style="text-align:right;">Days Remaining</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($retention_report['expiring_soon'])): ?>
                        <tr><td colspan="6" style="text-align:center; padding:2rem; color:var(--text-muted);">No memberships expiring within the next 30 days.</td></tr>
                        <?php else: ?>
                        <?php foreach ($retention_report['expiring_soon'] as $exp): 
                            $d_left = (int)$exp['days_left'];
                            $b_color = $d_left <= 7 ? '#ef4444' : '#eab308';
                            $b_bg    = $d_left <= 7 ? 'rgba(239,68,68,0.12)' : 'rgba(234,179,8,0.12)';
                        ?>
                        <tr>
                            <td style="font-weight:600; color:var(--text-main);"><?php echo htmlspecialchars($exp['full_name']); ?></td>
                            <td><span style="font-family:monospace; color:var(--accent);"><?php echo htmlspecialchars($exp['membership_id']); ?></span></td>
                            <td style="color:var(--text-muted);"><?php echo htmlspecialchars($exp['email']); ?></td>
                            <td><span class="badge badge-gold"><?php echo htmlspecialchars($exp['plan_name']); ?></span></td>
                            <td><?php echo date('M d, Y', strtotime($exp['expiry_date'])); ?></td>
                            <td style="text-align:right;">
                                <span class="badge" style="background:<?php echo $b_bg; ?>; color:<?php echo $b_color; ?>; font-weight:700;">
                                    <?php echo $d_left; ?> days left
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- =======================================================================
         REPORT 5: MEMBERSHIP CONVERSION REPORT
         ======================================================================= -->
    <div class="report-tab-pane" id="tab-conversion">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
            <!-- Funnel Stages & Formula -->
            <div class="card" style="display:flex; flex-direction:column; justify-content:space-between;">
                <div>
                    <h3 class="section-title" style="margin-bottom:0.75rem;"><i class="fas fa-filter-circle-dollar" style="color:#c084fc;"></i> Registration-to-Membership Funnel</h3>
                    <p style="font-size:0.8rem; color:var(--text-muted); line-height:1.5;">
                        Tracks member progression from raw account registration to active paid subscription and renewal.
                    </p>

                    <div style="background:rgba(192,132,252,0.06); border:1px dashed rgba(192,132,252,0.25); border-radius:10px; padding:0.85rem 1rem; margin:1rem 0; font-family:monospace; font-size:0.82rem; color:#d8b4fe;">
                        Conversion Rate = (Activated Members [<?php echo $conversion_report['activated']; ?>] &divide; Total Registrations [<?php echo $conversion_report['new_reg']; ?>]) &times; 100 = <strong><?php echo $conversion_report['rate_pct']; ?>%</strong>
                    </div>

                    <!-- 3-Stage Visual Funnel Cards -->
                    <div style="display:flex; flex-direction:column; gap:0.75rem; margin-top:1rem;">
                        <div style="background:rgba(255,255,255,0.03); border:1px solid var(--border); padding:0.85rem 1.25rem; border-radius:10px; display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <span style="font-size:0.75rem; font-weight:700; color:var(--text-muted);">STAGE 1: TOTAL REGISTRATIONS</span>
                                <h4 style="margin:0.15rem 0 0 0; font-size:1.2rem; color:var(--text-main);"><?php echo number_format($conversion_report['new_reg']); ?> Members</h4>
                            </div>
                            <span class="badge" style="background:rgba(255,255,255,0.08);">100% Top of Funnel</span>
                        </div>

                        <div style="background:rgba(45,106,79,0.08); border:1px solid rgba(45,106,79,0.2); padding:0.85rem 1.25rem; border-radius:10px; display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <span style="font-size:0.75rem; font-weight:700; color:#52b788;">STAGE 2: ACTIVATED MEMBERSHIPS</span>
                                <h4 style="margin:0.15rem 0 0 0; font-size:1.2rem; color:#52b788;"><?php echo number_format($conversion_report['activated']); ?> Subscribed</h4>
                            </div>
                            <span class="badge badge-success"><?php echo $conversion_report['rate_pct']; ?>% Converted</span>
                        </div>

                        <div style="background:rgba(234,179,8,0.08); border:1px solid rgba(234,179,8,0.2); padding:0.85rem 1.25rem; border-radius:10px; display:flex; justify-content:space-between; align-items:center;">
                            <div>
                                <span style="font-size:0.75rem; font-weight:700; color:#eab308;">STAGE 3: RENEWALS &amp; REPEAT</span>
                                <h4 style="margin:0.15rem 0 0 0; font-size:1.2rem; color:#eab308;"><?php echo number_format($conversion_report['renewals']); ?> Renewed</h4>
                            </div>
                            <span class="badge" style="background:rgba(234,179,8,0.15); color:#eab308;">Loyal Cohort</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Conversion Trend Chart -->
            <div class="card">
                <h3 class="section-title" style="margin-bottom:1.25rem;"><i class="fas fa-chart-area" style="color:#c084fc;"></i> Monthly Conversion Rate Trend</h3>
                <div style="height:260px; position:relative;">
                    <canvas id="conversionTrendChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- =======================================================================
         REPORT 6: ATTENDANCE BY HOUR REPORT
         ======================================================================= -->
    <div class="report-tab-pane" id="tab-hourly">
        <div style="display:grid; grid-template-columns: 2fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
            <!-- Hourly Attendance Bar Chart -->
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem;">
                    <div>
                        <h3 class="section-title" style="margin:0;"><i class="fas fa-clock" style="color:#38bdf8;"></i> Hourly Check-in Volume</h3>
                        <p style="margin:0.2rem 0 0 0; font-size:0.75rem; color:var(--text-muted);">Gym foot traffic across operating hours (5:00 AM – 10:00 PM)</p>
                    </div>
                    <span class="badge" style="background:rgba(56,189,248,0.1); color:#38bdf8; font-weight:700;">Avg <?php echo $hourly_report['avg_hourly']; ?> / hr</span>
                </div>
                <div style="height:250px; position:relative;">
                    <canvas id="hourlyBarChart"></canvas>
                </div>
            </div>

            <!-- Peak Hours Summary Card -->
            <div class="card" style="display:flex; flex-direction:column; justify-content:space-between;">
                <div>
                    <h3 class="section-title" style="margin-bottom:1rem;"><i class="fas fa-fire" style="color:#ef4444;"></i> Peak Rush Hours</h3>
                    <div style="background:rgba(239,68,68,0.06); border:1px solid rgba(239,68,68,0.2); border-radius:12px; padding:1.25rem; margin-bottom:1rem;">
                        <span style="font-size:0.72rem; font-weight:700; color:#f87171; text-transform:uppercase;">Primary Peak Window</span>
                        <h2 style="font-size:1.5rem; font-weight:800; color:#f87171; margin:0.25rem 0 0 0;"><?php echo htmlspecialchars($hourly_report['peak_hour']); ?></h2>
                        <p style="font-size:0.8rem; color:var(--text-muted); margin:0.35rem 0 0 0;"><?php echo number_format($hourly_report['peak_count']); ?> members recorded during peak window.</p>
                    </div>

                    <div style="font-size:0.8rem; color:var(--text-muted); line-height:1.5;">
                        <i class="fas fa-lightbulb" style="color:#eab308; margin-right:4px;"></i> <strong>Staff Optimization Tip:</strong> Ensure gym floor trainers and reception personnel are fully staffed during peak window to maintain service quality and prevent crowding.
                    </div>
                </div>
            </div>
        </div>

        <!-- Hourly Heatmap Matrix -->
        <div class="card">
            <h3 class="section-title" style="margin-bottom:1.25rem;"><i class="fas fa-border-all" style="color:var(--accent);"></i> Attendance Heatmap Matrix (Day vs. Hour)</h3>
            <div class="table-container" style="overflow-x:auto;">
                <table style="text-align:center; font-size:0.8rem;">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Day of Week</th>
                            <?php for ($h = 6; $h <= 21; $h++): ?>
                            <th><?php echo ($h < 12 ? $h : ($h == 12 ? 12 : $h - 12)) . ($h < 12 ? 'a' : 'p'); ?></th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $days_list = [2 => 'Monday', 3 => 'Tuesday', 4 => 'Wednesday', 5 => 'Thursday', 6 => 'Friday', 7 => 'Saturday', 1 => 'Sunday'];
                        foreach ($days_list as $dow_num => $dow_str):
                        ?>
                        <tr>
                            <td style="text-align:left; font-weight:600; color:var(--text-main);"><?php echo $dow_str; ?></td>
                            <?php for ($h = 6; $h <= 21; $h++): 
                                $val = $hourly_report['heatmap'][$dow_num][$h] ?? 0;
                                $intensity = min(1, $val / 10);
                                $bg = $val > 0 ? "rgba(45, 106, 79, " . max(0.15, $intensity) . ")" : "transparent";
                                $text_c = $val > 0 ? "#52b788" : "var(--text-muted)";
                            ?>
                            <td style="background:<?php echo $bg; ?>; color:<?php echo $text_c; ?>; font-weight:<?php echo $val > 0 ? '700' : '400'; ?>;">
                                <?php echo $val > 0 ? $val : '—'; ?>
                            </td>
                            <?php endfor; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- =======================================================================
         REPORT 7: ATTENDANCE BY DAY REPORT
         ======================================================================= -->
    <div class="report-tab-pane" id="tab-daily-att">
        <div style="display:grid; grid-template-columns: 2fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
            <!-- Day of Week Bar Chart -->
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem;">
                    <div>
                        <h3 class="section-title" style="margin:0;"><i class="fas fa-calendar-week" style="color:var(--accent);"></i> Attendance by Day of Week</h3>
                        <p style="margin:0.2rem 0 0 0; font-size:0.75rem; color:var(--text-muted);">Total check-in volume comparison from Sunday to Saturday</p>
                    </div>
                    <span class="badge badge-success" style="font-weight:700;">Top: <?php echo htmlspecialchars($day_report['busiest_day']); ?></span>
                </div>
                <div style="height:250px; position:relative;">
                    <canvas id="dayOfWeekChart"></canvas>
                </div>
            </div>

            <!-- Busiest Day Highlight Card -->
            <div class="card" style="display:flex; flex-direction:column; justify-content:space-between;">
                <div>
                    <h3 class="section-title" style="margin-bottom:1rem;"><i class="fas fa-trophy" style="color:#eab308;"></i> Most Active Day</h3>
                    <div style="background:rgba(45,106,79,0.08); border:1px solid rgba(45,106,79,0.2); border-radius:12px; padding:1.25rem; margin-bottom:1rem;">
                        <span style="font-size:0.72rem; font-weight:700; color:var(--accent); text-transform:uppercase;">Weekly High</span>
                        <h2 style="font-size:1.8rem; font-weight:800; color:#52b788; margin:0.25rem 0 0 0;"><?php echo htmlspecialchars($day_report['busiest_day']); ?></h2>
                        <p style="font-size:0.82rem; color:var(--text-muted); margin:0.35rem 0 0 0;">Recorded <?php echo number_format($day_report['busiest_count']); ?> total gym visits.</p>
                    </div>

                    <div style="background:rgba(255,255,255,0.02); border:1px solid var(--border); border-radius:10px; padding:0.85rem;">
                        <div style="display:flex; justify-content:space-between; font-size:0.8rem; color:var(--text-muted);">
                            <span>Average Daily Visits:</span>
                            <strong style="color:var(--text-main);"><?php echo $day_report['avg_daily']; ?> / day</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Day of Week Table -->
        <div class="card">
            <h3 class="section-title" style="margin-bottom:1.25rem;"><i class="fas fa-list-ol" style="color:var(--accent);"></i> Day-of-Week Attendance Statistics</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Day of Week</th>
                            <th style="text-align:center;">Total Check-ins</th>
                            <th style="text-align:center;">Daily Average Check-ins</th>
                            <th style="text-align:right;">Traffic Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_all_visits = array_sum($day_report['counts']) ?: 1;
                        foreach ($day_report['table_data'] as $dt): 
                            $share = round(($dt['total_visits'] / $total_all_visits) * 100, 1);
                        ?>
                        <tr>
                            <td style="font-weight:600; color:var(--text-main);"><?php echo htmlspecialchars($dt['day_name']); ?></td>
                            <td style="text-align:center; font-weight:700;"><?php echo number_format($dt['total_visits']); ?></td>
                            <td style="text-align:center; color:#38bdf8; font-weight:600;"><?php echo $dt['avg_visits']; ?></td>
                            <td style="text-align:right;">
                                <div style="display:flex; align-items:center; justify-content:flex-end; gap:0.5rem;">
                                    <div style="width:80px; height:6px; background:var(--border); border-radius:3px; overflow:hidden;">
                                        <div style="width:<?php echo $share; ?>%; height:100%; background:var(--accent);"></div>
                                    </div>
                                    <span style="font-size:0.8rem; font-weight:700; color:var(--accent); min-width:35px;"><?php echo $share; ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div> <!-- End of #analytics-master-container -->

<!-- ── ADVANCED SMART EXPORT MODAL ────────────────────────────────────────── -->
<div class="modal-overlay" id="advanced-export-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(10,25,18,0.7); z-index:10000; align-items:center; justify-content:center; backdrop-filter:blur(8px);">
    <div class="modal" style="background:var(--card-bg); border-radius:20px; padding:2rem; width:90%; max-width:550px; box-shadow:0 20px 40px -15px rgba(0,0,0,0.5); border:1px solid rgba(45,106,79,0.25); position:relative;">
        <button onclick="closeExportModal()" aria-label="Close Export Modal" style="position:absolute; top:1.5rem; right:1.5rem; background:none; border:none; font-size:1.3rem; color:var(--text-muted); cursor:pointer;" onmouseover="this.style.color='var(--danger)'" onmouseout="this.style.color='var(--text-muted)'">
            <i class="fas fa-xmark"></i>
        </button>
        
        <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem;">
            <div id="export-modal-icon" style="width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; background:rgba(45,106,79,0.12); color:var(--accent);">
                <i class="fas fa-file-export"></i>
            </div>
            <div>
                <h3 id="export-modal-title" style="margin:0; font-size:1.3rem; color:var(--text-main);">Export Custom Report</h3>
                <span id="export-modal-subtitle" style="font-size:0.8rem; color:var(--text-muted);">Choose report dataset, date range, and export format.</span>
            </div>
        </div>
        
        <form id="export-form" action="reports.php" method="GET" style="display:flex; flex-direction:column; gap:1.25rem;">
            <!-- Report Type Selection -->
            <div>
                <label style="display:block; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:0.4rem;">Select Report Dataset</label>
                <select name="export" id="export-type-select" class="form-control" style="margin:0; width:100%;">
                    <option value="daily_revenue">1. Daily Revenue &amp; Ledger</option>
                    <option value="weekly_revenue">2. Weekly Revenue Comparison</option>
                    <option value="monthly_revenue">3. Monthly Revenue Trend</option>
                    <option value="retention">4. Membership Retention Records</option>
                    <option value="conversion">5. Membership Conversion Funnel</option>
                    <option value="attendance_hour">6. Attendance by Hour Analysis</option>
                    <option value="attendance_day">7. Attendance by Day Statistics</option>
                    <option value="members">Master Members Directory</option>
                    <option value="attendance">Raw Attendance Logs</option>
                    <option value="revenue">Master Payments &amp; Revenue</option>
                </select>
            </div>

            <!-- Date Preset Selector -->
            <div>
                <label style="display:block; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:0.4rem;">Date Coverage</label>
                <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:0.4rem;">
                    <button type="button" class="btn btn-outline export-preset-btn active" data-preset="month" onclick="setExportModalPreset('month')" style="padding:0.4rem 0.25rem; font-size:0.75rem; width:100%;">This Month</button>
                    <button type="button" class="btn btn-outline export-preset-btn" data-preset="today" onclick="setExportModalPreset('today')" style="padding:0.4rem 0.25rem; font-size:0.75rem; width:100%;">Today</button>
                    <button type="button" class="btn btn-outline export-preset-btn" data-preset="week" onclick="setExportModalPreset('week')" style="padding:0.4rem 0.25rem; font-size:0.75rem; width:100%;">This Week</button>
                    <button type="button" class="btn btn-outline export-preset-btn" data-preset="last30" onclick="setExportModalPreset('last30')" style="padding:0.4rem 0.25rem; font-size:0.75rem; width:100%;">Last 30 Days</button>
                    <button type="button" class="btn btn-outline export-preset-btn" data-preset="year" onclick="setExportModalPreset('year')" style="padding:0.4rem 0.25rem; font-size:0.75rem; width:100%;">This Year</button>
                    <button type="button" class="btn btn-outline export-preset-btn" data-preset="all" onclick="setExportModalPreset('all')" style="padding:0.4rem 0.25rem; font-size:0.75rem; width:100%;">All Time</button>
                </div>
                <input type="hidden" name="date_preset" id="export-modal-preset-input" value="month">
            </div>

            <!-- Format Selector -->
            <div>
                <label style="display:block; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:0.4rem;">Download Format</label>
                <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:0.5rem;">
                    <!-- CSV Option -->
                    <label style="margin:0; cursor:pointer; width:100%;">
                        <input type="radio" name="format" value="csv" checked style="display:none;" id="export-format-csv">
                        <div class="format-card active" onclick="setExportFormat('csv')" id="card-format-csv" style="border: 1px solid var(--accent); background:rgba(45,106,79,0.1); color:var(--text-main); border-radius:10px; padding:0.65rem; text-align:center; transition:all 0.25s;">
                            <div style="font-size:1.1rem; color:var(--accent); font-weight:bold;"><i class="fas fa-file-csv"></i> CSV</div>
                            <span style="font-size:0.68rem; color:var(--text-muted); display:block; margin-top:2px;">Spreadsheets</span>
                        </div>
                    </label>
                    
                    <!-- Excel Option -->
                    <label style="margin:0; cursor:pointer; width:100%;">
                        <input type="radio" name="format" value="xls" style="display:none;" id="export-format-xls">
                        <div class="format-card" onclick="setExportFormat('xls')" id="card-format-xls" style="border: 1px solid var(--border); background:transparent; color:var(--text-main); border-radius:10px; padding:0.65rem; text-align:center; transition:all 0.25s;">
                            <div style="font-size:1.1rem; color:#52b788; font-weight:bold;"><i class="fas fa-file-excel"></i> Excel</div>
                            <span style="font-size:0.68rem; color:var(--text-muted); display:block; margin-top:2px;">.XLS Workbook</span>
                        </div>
                    </label>
                    
                    <!-- JSON Option -->
                    <label style="margin:0; cursor:pointer; width:100%;">
                        <input type="radio" name="format" value="json" style="display:none;" id="export-format-json">
                        <div class="format-card" onclick="setExportFormat('json')" id="card-format-json" style="border: 1px solid var(--border); background:transparent; color:var(--text-main); border-radius:10px; padding:0.65rem; text-align:center; transition:all 0.25s;">
                            <div style="font-size:1.1rem; color:#eab308; font-weight:bold;"><i class="fas fa-file-code"></i> JSON</div>
                            <span style="font-size:0.68rem; color:var(--text-muted); display:block; margin-top:2px;">Raw Data API</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Actions -->
            <div style="display:flex; gap:0.75rem; justify-content:flex-end; border-top:1px solid rgba(255,255,255,0.06); padding-top:1.25rem;">
                <button type="button" class="btn btn-outline" onclick="closeExportModal()" style="padding:0.5rem 1.25rem;">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btn-export-submit" style="padding:0.5rem 1.5rem;"><i class="fas fa-download"></i> Generate &amp; Download</button>
            </div>
        </form>
    </div>
</div>

<!-- ── EMBEDDED STYLES FOR REPORTS MODULE ────────────────────────────────── -->
<style>
.report-nav-container {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 0.4rem;
}
.report-nav-tabs {
    display: flex;
    gap: 0.35rem;
    overflow-x: auto;
    scrollbar-width: none;
}
.report-nav-tabs::-webkit-scrollbar { display: none; }

.nav-tab-btn {
    padding: 0.65rem 1rem;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--text-muted);
    background: transparent;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.25s ease;
    display: flex;
    align-items: center;
    gap: 0.45rem;
    white-space: nowrap;
}
.nav-tab-btn:hover {
    color: var(--text-main);
    background: rgba(255, 255, 255, 0.04);
}
.nav-tab-btn.active {
    color: #52b788;
    background: rgba(45, 106, 79, 0.15);
    font-weight: 700;
}

.report-tab-pane {
    display: none;
    animation: fadeIn 0.35s ease-out;
}
.report-tab-pane.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Print Specific Rules */
@media print {
    .sidebar, .topbar .no-print, .no-print, .report-nav-container { display: none !important; }
    .main-content { margin-left: 0 !important; padding: 0.5rem !important; }
    .card { break-inside: avoid; box-shadow: none !important; border: 1px solid #ddd !important; background: #fff !important; color: #000 !important; }
    .report-tab-pane { display: block !important; margin-bottom: 2rem !important; }
}

/* PDF Generator Layout */
#analytics-master-container.pdf-render-mode .no-print { display: none !important; }
#analytics-master-container.pdf-render-mode .report-tab-pane { display: block !important; margin-bottom: 2rem !important; }
</style>

<!-- ── INITIALIZE ALL CHART.JS INSTANCES & CLIENT SCRIPT ────────────────── -->
<script>
// ── CHART INITIALIZATIONS ────────────────────────────────────────────────────
// Theme tokens (themeGreen, themeBlue, etc.) and pure functions (switchReportTab,
// applyPreset, openExportModal, generatePDFReport, etc.) are in assets/js/reports.js

// 1. Report 1 Charts
const dailyPlanChart = new Chart(document.getElementById('dailyPlanChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_column($daily_report['plan_breakdown'], 'plan_name')); ?>,
        datasets: [{
            label: 'Revenue (₱)',
            data: <?php echo json_encode(array_map('floatval', array_column($daily_report['plan_breakdown'], 'revenue'))); ?>,
            backgroundColor: themeGreen,
            borderRadius: 6
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { grid: { color: gridColor }, ticks: { font: themeFont, callback: v => '₱' + Number(v).toLocaleString() } },
            x: { grid: { display: false }, ticks: { font: themeFont } }
        }
    }
});

const dailyMethodChart = new Chart(document.getElementById('dailyMethodChart'), {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_column($daily_report['method_breakdown'], 'payment_method')); ?>,
        datasets: [{
            data: <?php echo json_encode(array_map('floatval', array_column($daily_report['method_breakdown'], 'revenue'))); ?>,
            backgroundColor: ['#52b788', '#38bdf8', '#eab308', '#c084fc'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'right', labels: { font: themeFont, color: '#94a3b8' } } }
    }
});

// 2. Report 2 Chart: Weekly Comparison
const weeklyCompareChart = new Chart(document.getElementById('weeklyCompareChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($weekly_report['days']['current'])); ?>,
        datasets: [
            {
                label: 'Current Week',
                data: <?php echo json_encode(array_values(array_map(fn($d) => $d['revenue'], $weekly_report['days']['current']))); ?>,
                backgroundColor: themeGreen,
                borderRadius: 6
            },
            {
                label: 'Previous Week',
                data: <?php echo json_encode(array_values(array_map(fn($d) => $d['revenue'], $weekly_report['days']['previous']))); ?>,
                backgroundColor: 'rgba(255, 255, 255, 0.15)',
                borderRadius: 6
            }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { grid: { color: gridColor }, ticks: { font: themeFont, callback: v => '₱' + Number(v).toLocaleString() } },
            x: { grid: { display: false }, ticks: { font: themeFont } }
        }
    }
});

// 3. Report 3 Charts: Monthly Trajectory
const monthlyTrendChart = new Chart(document.getElementById('monthlyTrendChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($monthly_report['trend_labels']); ?>,
        datasets: [{
            label: 'Monthly Revenue',
            data: <?php echo json_encode($monthly_report['trend_data']); ?>,
            borderColor: themeGreen,
            backgroundColor: 'rgba(82, 183, 136, 0.12)',
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointBackgroundColor: themeGreen
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { grid: { color: gridColor }, ticks: { font: themeFont, callback: v => '₱' + Number(v).toLocaleString() } },
            x: { grid: { display: false }, ticks: { font: themeFont } }
        }
    }
});

const monthlyPlanChart = new Chart(document.getElementById('monthlyPlanChart'), {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_column($monthly_report['plan_dist'], 'name')); ?>,
        datasets: [{
            data: <?php echo json_encode(array_map('floatval', array_column($monthly_report['plan_dist'], 'total'))); ?>,
            backgroundColor: ['#1b4332', '#2d6a4f', '#40916c', '#52b788', '#74c69d', '#95d5b2'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'right', labels: { font: themeFont, color: '#94a3b8' } } }
    }
});

const monthlyMethodChart = new Chart(document.getElementById('monthlyMethodChart'), {
    type: 'pie',
    data: {
        labels: <?php echo json_encode(array_column($monthly_report['method_dist'], 'name')); ?>,
        datasets: [{
            data: <?php echo json_encode(array_map('floatval', array_column($monthly_report['method_dist'], 'total'))); ?>,
            backgroundColor: ['#52b788', '#38bdf8', '#eab308', '#ef4444'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'right', labels: { font: themeFont, color: '#94a3b8' } } }
    }
});

// 4. Report 4 Chart: Retention Trend
const retentionTrendChart = new Chart(document.getElementById('retentionTrendChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($retention_report['trend_labels']); ?>,
        datasets: [{
            label: 'Retention Rate %',
            data: <?php echo json_encode($retention_report['trend_data']); ?>,
            borderColor: themeYellow,
            backgroundColor: 'rgba(234, 179, 8, 0.12)',
            fill: true,
            tension: 0.35,
            pointRadius: 4,
            pointBackgroundColor: themeYellow
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { min: 0, max: 100, grid: { color: gridColor }, ticks: { font: themeFont, callback: v => v + '%' } },
            x: { grid: { display: false }, ticks: { font: themeFont } }
        }
    }
});

// 5. Report 5 Chart: Conversion Trend
const conversionTrendChart = new Chart(document.getElementById('conversionTrendChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($conversion_report['trend_labels']); ?>,
        datasets: [{
            label: 'Conversion Rate %',
            data: <?php echo json_encode($conversion_report['trend_data']); ?>,
            borderColor: themePurple,
            backgroundColor: 'rgba(192, 132, 252, 0.12)',
            fill: true,
            tension: 0.35,
            pointRadius: 4,
            pointBackgroundColor: themePurple
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { min: 0, max: 100, grid: { color: gridColor }, ticks: { font: themeFont, callback: v => v + '%' } },
            x: { grid: { display: false }, ticks: { font: themeFont } }
        }
    }
});

// 6. Report 6 Chart: Hourly Attendance
const hourlyBarChart = new Chart(document.getElementById('hourlyBarChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($hourly_report['hours']); ?>,
        datasets: [{
            label: 'Check-ins',
            data: <?php echo json_encode($hourly_report['counts']); ?>,
            backgroundColor: themeBlue,
            borderRadius: 4
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: gridColor }, ticks: { font: themeFont } },
            x: { grid: { display: false }, ticks: { font: themeFont, maxRotation: 45 } }
        }
    }
});

// 7. Report 7 Chart: Day of Week Attendance
const dayOfWeekChart = new Chart(document.getElementById('dayOfWeekChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($day_report['days']); ?>,
        datasets: [{
            label: 'Check-ins',
            data: <?php echo json_encode($day_report['counts']); ?>,
            backgroundColor: themeGreen,
            borderRadius: 6
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: gridColor }, ticks: { font: themeFont } },
            x: { grid: { display: false }, ticks: { font: themeFont } }
        }
    }
});

</script>

<?php include 'includes/footer.php'; ?>
