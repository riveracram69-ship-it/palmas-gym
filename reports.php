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
        $headers = ['Membership ID', 'Full Name', 'Email', 'Contact Number', 'DOB', 'Age', 'Home Address', 'Status', 'Date Joined', 'Active Plan', 'Expiry Date'];
        $sql = "SELECT m.membership_id, m.full_name, m.email, m.contact_number,
                       COALESCE(m.dob, '—') as dob,
                       COALESCE(m.age, '—') as age,
                       COALESCE(m.address, '—') as address,
                       m.status, DATE(m.created_at) as joined,
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

    } elseif ($type === 'financial_summary') {
        $headers = ['Month / Year', 'Gross Revenue (PHP)', 'Total Expenses (PHP)', 'Gross/Net Income (PHP)', 'Net Profit Margin (%)', 'Status'];
        
        $months_map = [];
        $start_ts = !empty($startDate) ? strtotime($startDate) : strtotime('-11 months');
        $end_ts   = !empty($endDate) ? strtotime($endDate) : time();
        
        $curr = strtotime(date('Y-m-01', $start_ts));
        $last = strtotime(date('Y-m-01', $end_ts));
        
        while ($curr <= $last) {
            $key = date('Y-m', $curr);
            $months_map[$key] = [
                'label'    => date('M Y', $curr),
                'revenue'  => 0.0,
                'expenses' => 0.0
            ];
            $curr = strtotime('+1 month', $curr);
        }

        // Fetch monthly revenues
        $sql_rev = "SELECT DATE_FORMAT(payment_date, '%Y-%m') as ym, SUM(amount) as total FROM payments WHERE 1=1";
        $rev_params = [];
        if (!empty($startDate) && !empty($endDate)) {
            $sql_rev .= " AND payment_date BETWEEN :start_date AND :end_date";
            $rev_params['start_date'] = $startDate;
            $rev_params['end_date']   = $endDate;
        }
        $sql_rev .= " GROUP BY DATE_FORMAT(payment_date, '%Y-%m')";
        $stmt_r = $pdo->prepare($sql_rev);
        $stmt_r->execute($rev_params);
        foreach ($stmt_r->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($months_map[$r['ym']])) {
                $months_map[$r['ym']]['revenue'] = (float)$r['total'];
            }
        }

        // Fetch monthly expenses
        $sql_exp = "SELECT DATE_FORMAT(expense_date, '%Y-%m') as ym, SUM(amount) as total FROM expenses WHERE 1=1";
        $exp_params = [];
        if (!empty($startDate) && !empty($endDate)) {
            $sql_exp .= " AND expense_date BETWEEN :start_date AND :end_date";
            $exp_params['start_date'] = $startDate;
            $exp_params['end_date']   = $endDate;
        }
        $sql_exp .= " GROUP BY DATE_FORMAT(expense_date, '%Y-%m')";
        $stmt_e = $pdo->prepare($sql_exp);
        $stmt_e->execute($exp_params);
        foreach ($stmt_e->fetchAll(PDO::FETCH_ASSOC) as $e) {
            if (isset($months_map[$e['ym']])) {
                $months_map[$e['ym']]['expenses'] = (float)$e['total'];
            }
        }

        foreach ($months_map as $m) {
            $rev = $m['revenue'];
            $exp = $m['expenses'];
            $net = $rev - $exp;
            $margin = $rev > 0 ? round(($net / $rev) * 100, 1) : ($net < 0 ? -100.0 : 0.0);
            $status = $net > 0 ? 'Profitable' : ($net == 0 ? 'Break-Even' : 'Deficit');

            $rows[] = [
                $m['label'],
                number_format($rev, 2, '.', ''),
                number_format($exp, 2, '.', ''),
                number_format($net, 2, '.', ''),
                $margin . '%',
                $status
            ];
        }

    } elseif ($type === 'expenses') {
        $headers = ['Expense Date', 'Category', 'Particulars / Description', 'Payment Method', 'Reference No', 'Recorded By', 'Amount (PHP)', 'Notes'];
        $sql = "SELECT e.expense_date, e.category, e.title, e.payment_method, 
                       COALESCE(e.reference_number, 'N/A'), COALESCE(u.name, 'Admin') as recorded_by,
                       e.amount, COALESCE(e.notes, '')
                FROM expenses e
                LEFT JOIN users u ON e.recorded_by = u.id
                WHERE 1=1";
        if (!empty($startDate) && !empty($endDate)) {
            $sql .= " AND e.expense_date BETWEEN :start_date AND :end_date";
            $params['start_date'] = $startDate;
            $params['end_date']   = $endDate;
        }
        if ($payMethod !== 'all') {
            $sql .= " AND e.payment_method = :payment_method";
            $params['payment_method'] = $payMethod;
        }
        $sql .= " ORDER BY e.expense_date DESC, e.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);

    } else {
        exit("Invalid Export Type");
    }

    while (ob_get_level()) { ob_end_clean(); }
    $type_file_names = [
        'daily_revenue'     => 'Daily_Revenue',
        'weekly_revenue'    => 'Weekly_Revenue',
        'monthly_revenue'   => 'Monthly_Revenue',
        'financial_summary' => 'Financial_Profitability_Summary',
        'expenses'          => 'Operational_Expenses_Ledger',
        'retention'         => 'Membership_Retention',
        'conversion'        => 'Member_Conversion',
        'attendance_hour'   => 'Peak_Hours_Attendance',
        'attendance_day'    => 'Day_Of_Week_Attendance',
        'members'           => 'Membership',
        'attendance'        => 'Attendance',
        'revenue'           => 'Payment'
    ];
    $clean_type = $type_file_names[$type] ?? ucwords(str_replace(' ', '_', str_replace('_', ' ', $type)));
    $period_tag = (!empty($startDate) && !empty($endDate)) ? date('Y-m', strtotime($startDate)) : date('Y-m');
    $filename = 'Palmas_Elite_Gym_' . $clean_type . '_Report_' . $period_tag;


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

    } elseif ($format === 'pdf' || $format === 'print') {
        // ── EXECUTIVE PDF & PRINTABLE REPORT RENDERER ────────────────────────
        $type_titles = [
            'daily_revenue'     => 'Daily Revenue & Transaction Ledger',
            'weekly_revenue'    => 'Weekly Revenue Summary',
            'monthly_revenue'   => 'Monthly Revenue Performance',
            'financial_summary' => 'Financial Profitability & Net Income Summary',
            'expenses'          => 'Gym Operational Expenses & Cost Ledger',
            'retention'         => 'Membership Retention & Lifecycle Audit',
            'conversion'        => 'Member Registration & Conversion Report',
            'attendance_hour'   => 'Peak Hours Attendance Distribution',
            'attendance_day'    => 'Day-of-Week Attendance Analytics',
            'members'           => 'Registered Membership Directory',
            'attendance'        => 'Member Attendance Check-In Records',
            'revenue'           => 'Financial Payment Ledger'
        ];
        $report_title = $type_titles[$type] ?? ucwords(str_replace('_', ' ', $type)) . ' Report';

        // Date label formatting
        if (!empty($startDate) && !empty($endDate)) {
            $date_label = date('M j, Y', strtotime($startDate)) . ' — ' . date('M j, Y', strtotime($endDate));
        } else {
            $date_label = 'All Historical Records';
        }

        // Summary Statistics Calculation
        $total_records = count($rows);
        $amount_col_idx = -1;
        $total_amount = 0;
        foreach ($headers as $idx => $h) {
            if (stripos($h, 'amount') !== false || stripos($h, 'revenue') !== false || (stripos($h, 'total') !== false && stripos($h, 'check') === false && stripos($h, 'renew') === false)) {
                $amount_col_idx = $idx;
                break;
            }
        }

        if ($amount_col_idx !== -1) {
            foreach ($rows as $r) {
                $raw = (string)($r[$amount_col_idx] ?? 0);
                $val = floatval(preg_replace('/[^0-9.]/', '', $raw));
                $total_amount += $val;
            }
        }
        $avg_amount = ($total_records > 0 && $amount_col_idx !== -1) ? ($total_amount / $total_records) : 0;

        // Current Auditor/User
        $curr_user = current_user();
        $admin_name = htmlspecialchars($curr_user['name'] ?? 'Authorized Administrator');
        $admin_role = ucfirst($curr_user['role'] ?? 'Administrator');
        $doc_ref = 'PEG-RPT-' . date('Ymd') . '-' . strtoupper(substr(md5($type . ($startDate ?? '') . ($endDate ?? '')), 0, 6));
        $generated_at = date('F j, Y, g:i A');

        // Check if physical binary PDF download or direct file streaming is requested
        $is_binary_download = (isset($_GET['download']) && $_GET['download'] == '1') || 
                              (isset($_GET['binary']) && $_GET['binary'] == '1') || 
                              $format === 'download_pdf';

        if ($is_binary_download) {
            require_once __DIR__ . '/libs/fpdf/PalmasPDFReport.php';
            $orientation = (count($headers) > 6) ? 'L' : 'P';
            $pdf = new PalmasPDFReport($orientation, $report_title, $date_label, $doc_ref, $admin_name);
            $pdf->AddPage();

            $summary_cards = [
                ['label' => 'Total Records', 'value' => number_format($total_records)],
            ];
            if ($amount_col_idx !== -1) {
                $summary_cards[] = ['label' => 'Total Volume', 'value' => 'PHP ' . number_format($total_amount, 2)];
                $summary_cards[] = ['label' => 'Average / Trans', 'value' => 'PHP ' . number_format($avg_amount, 2)];
            } else {
                $summary_cards[] = ['label' => 'Auditor Status', 'value' => 'VERIFIED'];
            }
            $pdf->RenderSummaryCards($summary_cards);
            $pdf->RenderDataTable($headers, $rows);

            $pdf_filename = $filename . '.pdf';
            $dest = (isset($_GET['download']) && $_GET['download'] == '1') ? 'D' : 'I';
            $pdf->Output($dest, $pdf_filename);
            exit;
        }

        // Current query strings for alternative format links
        $q_csv = http_build_query(array_merge($_GET, ['format' => 'csv']));
        $q_xls = http_build_query(array_merge($_GET, ['format' => 'xls']));
        $q_pdf_dl = http_build_query(array_merge($_GET, ['format' => 'pdf', 'download' => '1']));
        $auto_print = (isset($_GET['auto_print']) && $_GET['auto_print'] == '1');
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Palma's Elite Gym — <?php echo htmlspecialchars($report_title); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1b4332;
            --primary-dark: #0d2e23;
            --accent: #52b788;
            --forest: #2d6a4f;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --bg-page: #f8faf9;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-page);
            color: var(--text-dark);
            line-height: 1.5;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* Screen Top Action Bar */
        .export-action-bar {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: #1b4332;
            color: #fff;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 16px rgba(0,0,0,0.18);
        }
        .action-bar-title {
            font-size: 0.95rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .action-bar-buttons {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn-act {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            font-size: 0.85rem;
            font-weight: 600;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-act-primary {
            background: #52b788;
            color: #0d2e23;
        }
        .btn-act-primary:hover {
            background: #74c69d;
            box-shadow: 0 2px 8px rgba(82,183,136,0.4);
        }
        .btn-act-outline {
            background: rgba(255,255,255,0.12);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.25);
        }
        .btn-act-outline:hover {
            background: rgba(255,255,255,0.2);
        }

        /* Report Canvas */
        .report-canvas {
            max-width: 1240px;
            margin: 28px auto 40px;
            background: #ffffff;
            padding: 40px 48px;
            border-radius: 14px;
            border: 1px solid var(--border-color);
            box-shadow: 0 8px 24px rgba(0,0,0,0.04);
        }

        /* Header Section */
        .report-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 24px;
            border-bottom: 2px solid #1b4332;
            margin-bottom: 22px;
        }
        .brand-section {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .brand-logo {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            border: 2px solid #52b788;
            object-fit: contain;
            background: #fff;
            padding: 2px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
        }
        .brand-text h1 {
            font-size: 1.45rem;
            font-weight: 800;
            color: #1b4332;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        .brand-text p {
            font-size: 0.76rem;
            font-weight: 700;
            color: #2d6a4f;
            text-transform: uppercase;
            letter-spacing: 1.2px;
        }
        .report-meta-box {
            text-align: right;
            font-size: 0.8rem;
        }
        .meta-pill {
            display: inline-block;
            background: #e8f5e9;
            color: #1b4332;
            font-weight: 700;
            font-size: 0.72rem;
            padding: 3px 12px;
            border-radius: 20px;
            margin-bottom: 6px;
            border: 1px solid #c8e6c9;
        }
        .meta-line {
            color: #64748b;
            margin-bottom: 2px;
        }
        .meta-line strong {
            color: #0f172a;
        }

        /* Subject Banner */
        .report-subject-banner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f1f7f4;
            padding: 16px 20px;
            border-radius: 8px;
            border-left: 5px solid #2d6a4f;
            margin-bottom: 24px;
        }
        .subject-title {
            font-size: 1.15rem;
            font-weight: 800;
            color: #1b4332;
        }
        .subject-scope {
            font-size: 0.82rem;
            color: #475569;
            font-weight: 600;
        }

        /* Metric Highlights Ribbon */
        .metrics-ribbon {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .metric-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 14px 18px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }
        .metric-label {
            font-size: 0.72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .metric-val {
            font-size: 1.35rem;
            font-weight: 800;
            color: #1b4332;
        }

        /* Table */
        .report-table-wrapper {
            width: 100%;
            overflow-x: auto;
            margin-bottom: 32px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        table.report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }
        table.report-table th {
            background: #1b4332;
            color: #ffffff;
            font-weight: 700;
            text-align: left;
            padding: 10px 14px;
            letter-spacing: 0.3px;
            border: none;
            white-space: nowrap;
        }
        table.report-table td {
            padding: 9px 14px;
            border-bottom: 1px solid #edf2f7;
            color: #334155;
            vertical-align: middle;
        }
        table.report-table tbody tr:nth-child(even) {
            background: #fbfdfc;
        }
        table.report-table tbody tr:hover {
            background: #f1f8f4;
        }
        table.report-table tfoot th {
            background: #2d6a4f;
            color: #ffffff;
            font-weight: 800;
            padding: 10px 14px;
        }

        /* Status Badges */
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.72rem;
            font-weight: 700;
            text-align: center;
        }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-inactive { background: #fee2e2; color: #991b1b; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-other { background: #f1f5f9; color: #475569; }

        /* Sign-off certification */
        .signoff-section {
            page-break-inside: avoid;
            margin-top: 36px;
            padding-top: 24px;
            border-top: 1px dashed #cbd5e1;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 48px;
        }
        .signature-box {
            background: #fafcfb;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
        }
        .sig-label {
            font-size: 0.72rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 28px;
        }
        .sig-line {
            border-bottom: 1.5px solid #0f172a;
            margin-bottom: 8px;
        }
        .sig-name {
            font-size: 0.9rem;
            font-weight: 700;
            color: #0f172a;
        }
        .sig-title {
            font-size: 0.75rem;
            color: #64748b;
        }

        /* Footer */
        .report-footer {
            margin-top: 24px;
            text-align: center;
            font-size: 0.72rem;
            color: #94a3b8;
            border-top: 1px solid #f1f5f9;
            padding-top: 12px;
        }

        /* Print Specific Media Query */
        @media print {
            @page {
                size: A4 landscape;
                margin: 10mm 12mm 12mm 12mm;
            }
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }
            .export-action-bar, .no-print {
                display: none !important;
            }
            .report-canvas {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
                box-shadow: none !important;
            }
            table.report-table th {
                background: #1b4332 !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            table.report-table tfoot th {
                background: #2d6a4f !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            table.report-table tbody tr:nth-child(even) {
                background: #f8faf9 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .badge-active { background: #dcfce7 !important; color: #166534 !important; }
            .badge-inactive { background: #fee2e2 !important; color: #991b1b !important; }
            .badge-pending { background: #fef3c7 !important; color: #92400e !important; }
            .signoff-section {
                break-inside: avoid !important;
            }
        }
    </style>
</head>
<body>
    <!-- Top Action Bar (hidden when printed) -->
    <div class="export-action-bar no-print">
        <div class="action-bar-title">
            <i class="fas fa-file-invoice" style="color:#52b788;"></i>
            <span>Executive Report Preview — <?php echo htmlspecialchars($report_title); ?></span>
        </div>
        <div class="action-bar-buttons">
            <a href="reports.php?<?php echo $q_xls; ?>" class="btn-act btn-act-outline" title="Download Excel Sheet">
                <i class="fas fa-file-excel" style="color:#52b788;"></i> Export Excel
            </a>
            <a href="reports.php?<?php echo $q_csv; ?>" class="btn-act btn-act-outline" title="Download CSV File">
                <i class="fas fa-file-csv" style="color:#a7f3d0;"></i> Export CSV
            </a>
            <a href="reports.php?<?php echo $q_pdf_dl; ?>" class="btn-act btn-act-outline" title="Download PDF Binary Document">
                <i class="fas fa-file-pdf" style="color:#f87171;"></i> Download PDF File
            </a>
            <button onclick="window.print()" class="btn-act btn-act-primary" title="Print or Save as PDF">
                <i class="fas fa-print"></i> Print / Save as PDF
            </button>
            <a href="reports.php" class="btn-act btn-act-outline" title="Close Preview">
                <i class="fas fa-arrow-left"></i> Return to Reports
            </a>
        </div>
    </div>

    <!-- Main Printable Canvas -->
    <div class="report-canvas">
        <!-- Header -->
        <header class="report-header">
            <div class="brand-section">
                <img src="assets/images/palmas-logo.png" alt="Palma's Elite Gym Logo" class="brand-logo" onerror="this.style.display='none'">
                <div class="brand-text">
                    <h1>PALMA'S ELITE GYM</h1>
                    <p>Official Executive Operations &amp; Intelligence Ledger</p>
                </div>
            </div>
            <div class="report-meta-box">
                <span class="meta-pill"><i class="fas fa-shield-alt"></i> Official Certified Record</span>
                <div class="meta-line">Document Ref: <strong><?php echo htmlspecialchars($doc_ref); ?></strong></div>
                <div class="meta-line">Generated: <strong><?php echo htmlspecialchars($generated_at); ?></strong></div>
                <div class="meta-line">Audited By: <strong><?php echo $admin_name . ' (' . $admin_role . ')'; ?></strong></div>
            </div>
        </header>

        <!-- Report Subject Banner -->
        <div class="report-subject-banner">
            <div>
                <div class="subject-title"><?php echo htmlspecialchars($report_title); ?></div>
                <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">Palma's Elite Gym &bull; System Intelligence Reporting Module</div>
            </div>
            <div style="text-align:right;">
                <div class="subject-scope"><i class="fas fa-calendar-alt" style="color:#2d6a4f;"></i> Period Coverage:</div>
                <div style="font-size:0.9rem; font-weight:700; color:#1b4332;"><?php echo htmlspecialchars($date_label); ?></div>
            </div>
        </div>

        <!-- Metric Highlights Ribbon -->
        <div class="metrics-ribbon">
            <div class="metric-card">
                <div class="metric-label">Total Records Listed</div>
                <div class="metric-val"><?php echo number_format($total_records); ?></div>
            </div>
            <?php if ($amount_col_idx !== -1): ?>
            <div class="metric-card">
                <div class="metric-label">Total Period Volume</div>
                <div class="metric-val" style="color:#2d6a4f;">&#8369;<?php echo number_format($total_amount, 2); ?></div>
            </div>
            <div class="metric-card">
                <div class="metric-label">Average per Entry</div>
                <div class="metric-val" style="color:#52b788;">&#8369;<?php echo number_format($avg_amount, 2); ?></div>
            </div>
            <?php endif; ?>
            <div class="metric-card">
                <div class="metric-label">Verification Status</div>
                <div class="metric-val" style="font-size:1.1rem; color:#166534;"><i class="fas fa-check-circle"></i> Authenticated</div>
            </div>
        </div>

        <!-- Data Table -->
        <div class="report-table-wrapper">
            <table class="report-table">
                <thead>
                    <tr>
                        <th style="width:40px; text-align:center;">#</th>
                        <?php foreach ($headers as $idx => $h): ?>
                            <th style="<?php echo ($idx === $amount_col_idx) ? 'text-align:right;' : ''; ?>">
                                <?php echo htmlspecialchars($h); ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="<?php echo count($headers) + 1; ?>" style="text-align:center; padding:32px; color:#64748b;">
                                <i class="fas fa-folder-open" style="font-size:1.8rem; color:#cbd5e1; margin-bottom:8px; display:block;"></i>
                                No matching records found for the selected timeframe.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r_idx => $r): ?>
                            <tr>
                                <td style="text-align:center; color:#94a3b8; font-size:0.75rem; font-weight:600;"><?php echo $r_idx + 1; ?></td>
                                <?php foreach ($r as $c_idx => $val): ?>
                                    <?php 
                                        $str_val = (string)$val;
                                        $is_amount = ($c_idx === $amount_col_idx);
                                        $is_status = stripos($headers[$c_idx], 'status') !== false;
                                        $lower_val = strtolower(trim($str_val));
                                    ?>
                                    <td style="<?php echo $is_amount ? 'text-align:right; font-weight:600; color:#1b4332;' : ''; ?>">
                                        <?php if ($is_amount && is_numeric($str_val)): ?>
                                            &#8369;<?php echo number_format((float)$str_val, 2); ?>
                                        <?php elseif ($is_status): ?>
                                            <?php 
                                                $badge_cls = 'badge-other';
                                                if (in_array($lower_val, ['active', 'completed', 'activated', 'paid'])) $badge_cls = 'badge-active';
                                                elseif (in_array($lower_val, ['inactive', 'expired', 'cancelled', 'rejected'])) $badge_cls = 'badge-inactive';
                                                elseif (in_array($lower_val, ['pending', 'unactivated'])) $badge_cls = 'badge-pending';
                                            ?>
                                            <span class="badge <?php echo $badge_cls; ?>">
                                                <?php echo htmlspecialchars($str_val); ?>
                                            </span>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($str_val); ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if ($amount_col_idx !== -1 && !empty($rows)): ?>
                <tfoot>
                    <tr>
                        <th colspan="<?php echo $amount_col_idx + 1; ?>" style="text-align:right;">TOTAL SUMMARY:</th>
                        <th style="text-align:right;">&#8369;<?php echo number_format($total_amount, 2); ?></th>
                        <?php if (count($headers) > ($amount_col_idx + 1)): ?>
                            <th colspan="<?php echo count($headers) - ($amount_col_idx + 1); ?>"></th>
                        <?php endif; ?>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <!-- Executive Sign-Off / Approval Block -->
        <div class="signoff-section">
            <div class="signature-box">
                <div class="sig-label">Prepared &amp; Verified By:</div>
                <div class="sig-line"></div>
                <div class="sig-name"><?php echo $admin_name; ?></div>
                <div class="sig-title"><?php echo $admin_role; ?> &bull; System Operations</div>
                <div style="font-size:0.72rem; color:#94a3b8; margin-top:4px;">Date: <?php echo date('F j, Y'); ?></div>
            </div>
            <div class="signature-box">
                <div class="sig-label">Reviewed &amp; Approved By:</div>
                <div class="sig-line"></div>
                <div class="sig-name">Gym Executive Directorate</div>
                <div class="sig-title">Palma's Elite Gym Management</div>
                <div style="font-size:0.72rem; color:#94a3b8; margin-top:4px;">Official Seal &amp; Authority Stamp</div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="report-footer">
            Palma's Elite Gym Management System &bull; Caloocan City, Philippines &bull; Confidential &amp; Proprietary Operational Document
        </footer>
    </div>

    <?php if ($auto_print): ?>
    <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 500);
        });
    </script>
    <?php endif; ?>
</body>
</html>
        <?php
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
    'period_expenses'   => 0,
    'period_net_income' => 0,
    'profit_margin'     => 0,
    'total_txns'        => 0,
    'total_expenses_cnt'=> 0,
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
$financial_report   = [
    'current_month_rev'    => 0,
    'current_month_exp'    => 0,
    'current_month_net'    => 0,
    'current_month_margin' => 0,
    'prev_month_rev'       => 0,
    'prev_month_exp'       => 0,
    'prev_month_net'       => 0,
    'mom_net_growth_pct'   => 0,
    'trend_labels'         => [],
    'trend_revenue'        => [],
    'trend_expenses'       => [],
    'trend_net'            => [],
    'monthly_ledger'       => [],
    'category_dist'        => [],
    'itemized_expenses'    => []
];
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

    // Operational expenses in period
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as exp, COUNT(id) as cnt FROM expenses WHERE expense_date BETWEEN ? AND ?");
    $stmt->execute([$start_date, $end_date]);
    $exp_res = $stmt->fetch();
    $kpis['period_expenses']    = (float)$exp_res['exp'];
    $kpis['total_expenses_cnt'] = (int)$exp_res['cnt'];
    $kpis['period_net_income']  = $kpis['period_revenue'] - $kpis['period_expenses'];
    $kpis['profit_margin']      = $kpis['period_revenue'] > 0 
        ? round(($kpis['period_net_income'] / $kpis['period_revenue']) * 100, 1) 
        : ($kpis['period_net_income'] < 0 ? -100.0 : 0.0);

    $stmt = $pdo->prepare("SELECT COUNT(*) as checkins, COUNT(DISTINCT member_id) as uniq FROM attendance WHERE date BETWEEN ? AND ?");
    $stmt->execute([$start_date, $end_date]);
    $res = $stmt->fetch();
    $kpis['period_checkins'] = (int)$res['checkins'];
    $kpis['unique_visitors'] = (int)$res['uniq'];

    // Accurate Approved population check: exclude Pending, Rejected, or Suspended members from active/expired membership KPIs
    $kpis['active_members']  = (int)$pdo->query(
        "SELECT COUNT(DISTINCT m.id) 
         FROM members m
         JOIN subscriptions s ON s.member_id = m.id 
         WHERE m.account_status = 'Approved' 
           AND m.status = 'Active' 
           AND s.expiry_date >= CURDATE()"
    )->fetchColumn();

    $kpis['expired_members'] = (int)$pdo->query(
        "SELECT COUNT(DISTINCT m.id) 
         FROM members m
         JOIN subscriptions s ON s.member_id = m.id 
         WHERE m.account_status = 'Approved' 
           AND m.status = 'Expired' 
           AND m.id NOT IN (
               SELECT member_id FROM subscriptions WHERE expiry_date >= CURDATE()
           )"
    )->fetchColumn();

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
    // FINANCIAL PROFITABILITY & NET INCOME INTELLIGENCE ENGINE
    // ═════════════════════════════════════════════════════════════════════════
    // 12-Month Trajectory
    $fin_months = [];
    for ($i = 11; $i >= 0; $i--) {
        $m_date = date('Y-m-01', strtotime("-{$i} months"));
        $m_key  = date('Y-m', strtotime($m_date));
        $m_lbl  = date('M Y', strtotime($m_date));
        $fin_months[$m_key] = [
            'label'       => $m_lbl,
            'start'       => $m_date,
            'end'         => date('Y-m-t', strtotime($m_date)),
            'revenue'     => 0.0,
            'expenses'    => 0.0,
            'net_income'  => 0.0,
            'margin_pct'  => 0.0,
            'status'      => 'Break-Even'
        ];
    }

    // Populate revenue for 12 months
    $stmt_fin_rev = $pdo->query("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') as ym, SUM(amount) as total
        FROM payments
        WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
        GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
    ");
    foreach ($stmt_fin_rev->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($fin_months[$r['ym']])) {
            $fin_months[$r['ym']]['revenue'] = (float)$r['total'];
        }
    }

    // Populate expenses for 12 months
    $stmt_fin_exp = $pdo->query("
        SELECT DATE_FORMAT(expense_date, '%Y-%m') as ym, SUM(amount) as total
        FROM expenses
        WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
        GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
    ");
    foreach ($stmt_fin_exp->fetchAll(PDO::FETCH_ASSOC) as $e) {
        if (isset($fin_months[$e['ym']])) {
            $fin_months[$e['ym']]['expenses'] = (float)$e['total'];
        }
    }

    foreach ($fin_months as $m_key => $fm) {
        $r = $fm['revenue'];
        $x = $fm['expenses'];
        $n = $r - $x;
        $m_pct = $r > 0 ? round(($n / $r) * 100, 1) : ($n < 0 ? -100.0 : 0.0);
        $st = $n > 0 ? 'Profitable' : ($n == 0 ? 'Break-Even' : 'Deficit');

        $fin_months[$m_key]['net_income'] = $n;
        $fin_months[$m_key]['margin_pct'] = $m_pct;
        $fin_months[$m_key]['status']     = $st;

        $financial_report['trend_labels'][]   = $fm['label'];
        $financial_report['trend_revenue'][]  = $r;
        $financial_report['trend_expenses'][] = $x;
        $financial_report['trend_net'][]      = $n;
    }

    $financial_report['monthly_ledger'] = array_reverse($fin_months); // Latest first for table

    // Current & previous month profitability
    $financial_report['current_month_rev'] = $monthly_report['current_month_rev'];
    
    $stmt_c_exp = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date BETWEEN ? AND ?");
    $stmt_c_exp->execute([$cur_m_start, $cur_m_end]);
    $financial_report['current_month_exp'] = (float)$stmt_c_exp->fetchColumn();
    $financial_report['current_month_net'] = $financial_report['current_month_rev'] - $financial_report['current_month_exp'];
    $financial_report['current_month_margin'] = $financial_report['current_month_rev'] > 0 
        ? round(($financial_report['current_month_net'] / $financial_report['current_month_rev']) * 100, 1) 
        : 0.0;

    $financial_report['prev_month_rev'] = $monthly_report['prev_month_rev'];
    $stmt_p_exp = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date BETWEEN ? AND ?");
    $stmt_p_exp->execute([$prev_m_start, $prev_m_end]);
    $financial_report['prev_month_exp'] = (float)$stmt_p_exp->fetchColumn();
    $financial_report['prev_month_net'] = $financial_report['prev_month_rev'] - $financial_report['prev_month_exp'];

    if ($financial_report['prev_month_net'] != 0) {
        $financial_report['mom_net_growth_pct'] = round((($financial_report['current_month_net'] - $financial_report['prev_month_net']) / abs($financial_report['prev_month_net'])) * 100, 1);
    } else {
        $financial_report['mom_net_growth_pct'] = $financial_report['current_month_net'] > 0 ? 100 : 0;
    }

    // Expense Category Breakdown in selected period
    $stmt_cat = $pdo->prepare("
        SELECT category as name, SUM(amount) as total, COUNT(id) as count
        FROM expenses
        WHERE expense_date BETWEEN ? AND ?
        GROUP BY category ORDER BY total DESC
    ");
    $stmt_cat->execute([$start_date, $end_date]);
    $financial_report['category_dist'] = $stmt_cat->fetchAll(PDO::FETCH_ASSOC);

    // Itemized Expenses for active period
    $stmt_item = $pdo->prepare("
        SELECT e.*, u.name as recorded_by_name
        FROM expenses e
        LEFT JOIN users u ON e.recorded_by = u.id
        WHERE e.expense_date BETWEEN ? AND ?
        ORDER BY e.expense_date DESC, e.id DESC
        LIMIT 50
    ");
    $stmt_item->execute([$start_date, $end_date]);
    $financial_report['itemized_expenses'] = $stmt_item->fetchAll(PDO::FETCH_ASSOC);

    // ═════════════════════════════════════════════════════════════════════════
    // 5. REPORT 4: MEMBERSHIP RETENTION REPORT
    // ═════════════════════════════════════════════════════════════════════════
    // Retention Rate = (Renewed Members / Total Eligible for Renewal) * 100
    $retention_report['active_cnt']  = $kpis['active_members'];
    $retention_report['expired_cnt'] = $kpis['expired_members'];

    // Eligible: Approved members whose subscription has reached or is within 30 days of expiry
    $eligible_query = $pdo->query(
        "SELECT COUNT(DISTINCT m.id) 
         FROM members m
         JOIN subscriptions s ON s.member_id = m.id
         WHERE m.account_status = 'Approved'
           AND s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
    )->fetchColumn();
    $retention_report['eligible_cnt'] = max((int)$eligible_query, 1);

    // Renewed: Approved members with 2 or more subscriptions (Correct subquery group count)
    $renewed_query = $pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT s.member_id 
            FROM subscriptions s
            JOIN members m ON m.id = s.member_id
            WHERE m.account_status = 'Approved'
            GROUP BY s.member_id 
            HAVING COUNT(s.id) > 1
        ) AS t"
    )->fetchColumn();
    $retention_report['renewed_cnt'] = (int)$renewed_query;

    $retention_report['rate_pct'] = round(($retention_report['renewed_cnt'] / $retention_report['eligible_cnt']) * 100, 1);
    $kpis['retention_rate'] = $retention_report['rate_pct'];

    // 6-Month Real Retention Trend (Authentic database calculations — no synthetic formulas)
    for ($m = 5; $m >= 0; $m--) {
        $mo_start = date('Y-m-01', strtotime("-{$m} months"));
        $mo_end   = date('Y-m-t', strtotime("-{$m} months"));
        $mo_label = date('M Y', strtotime($mo_start));

        $retention_report['trend_labels'][] = $mo_label;

        // Subscriptions that expired in this month
        $stmt_mo_exp = $pdo->prepare("
            SELECT COUNT(DISTINCT s.member_id) 
            FROM subscriptions s
            JOIN members m ON m.id = s.member_id
            WHERE m.account_status = 'Approved'
              AND s.expiry_date BETWEEN ? AND ?
        ");
        $stmt_mo_exp->execute([$mo_start, $mo_end]);
        $mo_eligible = (int)$stmt_mo_exp->fetchColumn();

        if ($mo_eligible > 0) {
            // Count members who had a subsequent subscription
            $stmt_mo_ren = $pdo->prepare("
                SELECT COUNT(DISTINCT s1.member_id) 
                FROM subscriptions s1
                JOIN subscriptions s2 ON s1.member_id = s2.member_id AND s2.id > s1.id
                JOIN members m ON m.id = s1.member_id
                WHERE m.account_status = 'Approved'
                  AND s1.expiry_date BETWEEN ? AND ?
            ");
            $stmt_mo_ren->execute([$mo_start, $mo_end]);
            $mo_renewed = (int)$stmt_mo_ren->fetchColumn();
            $mo_rate = min(100.0, round(($mo_renewed / $mo_eligible) * 100, 1));
        } else {
            // If no subscriptions expired, check if active members existed
            $stmt_mo_act = $pdo->prepare("
                SELECT COUNT(DISTINCT s.member_id) 
                FROM subscriptions s
                JOIN members m ON m.id = s.member_id
                WHERE m.account_status = 'Approved'
                  AND s.start_date <= ? AND s.expiry_date >= ?
            ");
            $stmt_mo_act->execute([$mo_end, $mo_start]);
            $mo_rate = ((int)$stmt_mo_act->fetchColumn() > 0) ? 100.0 : 0.0;
        }
        $retention_report['trend_data'][] = $mo_rate;
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
        "SELECT COUNT(*) FROM (
            SELECT member_id FROM subscriptions GROUP BY member_id HAVING COUNT(id) > 1
        ) AS t"
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
    $period_days = max(1, (int)round((strtotime($end_date) - strtotime($start_date)) / 86400) + 1);
    $day_report['avg_daily']     = round($total_day_checkins / $period_days, 1);
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
    <div class="stats-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.75rem;">
        <!-- KPI 1: Gross Revenue -->
        <div class="card stat-card" style="padding: 1.25rem;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.75rem;">
                <span class="stat-label" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">Period Revenue</span>
                <div class="stat-icon green" style="width:34px; height:34px; font-size:0.95rem; margin:0;"><i class="fas fa-peso-sign"></i></div>
            </div>
            <h2 class="stat-value" style="font-size:1.5rem; font-weight:800; color:#52b788; margin:0;">&#8369;<?php echo number_format($kpis['period_revenue'], 2); ?></h2>
            <p class="stat-meta" style="font-size:0.72rem; margin-top:0.35rem; color:var(--text-muted);"><i class="fas fa-receipt"></i> <?php echo number_format($kpis['total_txns']); ?> txns</p>
        </div>

        <!-- KPI 2: Operational Expenses -->
        <div class="card stat-card" style="padding: 1.25rem;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.75rem;">
                <span class="stat-label" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">Total Expenses</span>
                <div class="stat-icon red" style="width:34px; height:34px; font-size:0.95rem; margin:0; background:rgba(239,68,68,0.12); color:#f87171;"><i class="fas fa-file-invoice-dollar"></i></div>
            </div>
            <h2 class="stat-value" style="font-size:1.5rem; font-weight:800; color:#f87171; margin:0;">&#8369;<?php echo number_format($kpis['period_expenses'], 2); ?></h2>
            <p class="stat-meta" style="font-size:0.72rem; margin-top:0.35rem; color:var(--text-muted);"><i class="fas fa-receipt"></i> <?php echo number_format($kpis['total_expenses_cnt']); ?> expense items</p>
        </div>

        <!-- KPI 3: Net Income (Gross Profit) -->
        <?php 
            $net_is_pos = $kpis['period_net_income'] >= 0;
            $net_color = $net_is_pos ? '#52b788' : '#ef4444';
            $net_icon = $net_is_pos ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down';
        ?>
        <div class="card stat-card" style="padding: 1.25rem;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.75rem;">
                <span class="stat-label" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">Gross/Net Income</span>
                <div class="stat-icon" style="width:34px; height:34px; font-size:0.95rem; margin:0; background:<?php echo $net_is_pos ? 'rgba(82,183,136,0.12)' : 'rgba(239,68,68,0.12)'; ?>; color:<?php echo $net_color; ?>;"><i class="fas <?php echo $net_icon; ?>"></i></div>
            </div>
            <h2 class="stat-value" style="font-size:1.5rem; font-weight:800; color:<?php echo $net_color; ?>; margin:0;"><?php echo ($kpis['period_net_income'] < 0 ? '-' : '') . '&#8369;' . number_format(abs($kpis['period_net_income']), 2); ?></h2>
            <p class="stat-meta" style="font-size:0.72rem; margin-top:0.35rem; color:<?php echo $net_color; ?>; font-weight:700;">
                <?php echo $kpis['profit_margin']; ?>% Profit Margin
            </p>
        </div>

        <!-- KPI 4: Total Check-ins -->
        <div class="card stat-card" style="padding: 1.25rem;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.75rem;">
                <span class="stat-label" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">Gym Check-ins</span>
                <div class="stat-icon blue" style="width:34px; height:34px; font-size:0.95rem; margin:0;"><i class="fas fa-user-check"></i></div>
            </div>
            <h2 class="stat-value" style="font-size:1.5rem; font-weight:800; color:#38bdf8; margin:0;"><?php echo number_format($kpis['period_checkins']); ?></h2>
            <p class="stat-meta" style="font-size:0.72rem; margin-top:0.35rem; color:var(--text-muted);"><i class="fas fa-users"></i> <?php echo number_format($kpis['unique_visitors']); ?> visitors</p>
        </div>

        <!-- KPI 5: Active Members -->
        <div class="card stat-card" style="padding: 1.25rem;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.75rem;">
                <span class="stat-label" style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">Active Members</span>
                <div class="stat-icon green" style="width:34px; height:34px; font-size:0.95rem; margin:0;"><i class="fas fa-id-card"></i></div>
            </div>
            <h2 class="stat-value" style="font-size:1.5rem; font-weight:800; color:var(--text-main); margin:0;"><?php echo number_format($kpis['active_members']); ?></h2>
            <p class="stat-meta" style="font-size:0.72rem; margin-top:0.35rem; color:#ef4444;"><i class="fas fa-clock-rotate-left"></i> <?php echo number_format($kpis['expired_members']); ?> expired</p>
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

    <!-- ── 3-REPORT NAVIGATION TABS ───────────────────────────────────────── -->
    <div class="report-nav-container no-print" style="margin-bottom:1.5rem;">
        <div class="report-nav-tabs">
            <button class="nav-tab-btn active" data-tab="tab-daily" onclick="switchReportTab('tab-daily')">
                <i class="fas fa-calendar-day"></i> 1. Daily Revenue
            </button>
            <button class="nav-tab-btn" data-tab="tab-weekly" onclick="switchReportTab('tab-weekly')">
                <i class="fas fa-chart-column"></i> 2. Weekly Revenue
            </button>
            <button class="nav-tab-btn" data-tab="tab-financials" onclick="switchReportTab('tab-financials')">
                <i class="fas fa-scale-balanced"></i> 3. Financials &amp; Net Income
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
                                            <img src="<?php echo htmlspecialchars($tx['photo']); ?>" alt="<?php echo htmlspecialchars($tx['full_name']); ?> Avatar" style="width:100%; height:100%; object-fit:cover;">
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
         REPORT 3: FINANCIAL PROFITABILITY & NET INCOME REPORT
         ======================================================================= -->
    <div class="report-tab-pane" id="tab-financials">
        <!-- Top Metrics & Action Header -->
        <div class="card" style="margin-bottom:1.5rem; background:linear-gradient(135deg, rgba(27,67,50,0.4), rgba(45,106,79,0.15)); border:1px solid rgba(82,183,136,0.3);">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                <div>
                    <span class="badge badge-success" style="font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.4rem;">
                        <i class="fas fa-coins"></i> Executive Financial Audit
                    </span>
                    <h2 style="margin:0; font-size:1.45rem; color:var(--text-main); font-weight:800;">Monthly Financials &amp; Net Income</h2>
                    <p style="margin:0.25rem 0 0 0; color:var(--text-muted); font-size:0.82rem;">Complete profitability breakdown: Gross Revenue, Operating Expenses, Net Income, and Margins.</p>
                </div>
                <div style="display:flex; gap:0.6rem; align-items:center;">
                    <a href="expenses.php" class="btn btn-primary" style="font-size:0.82rem; padding:0.45rem 1rem;">
                        <i class="fas fa-plus"></i> Record Expense
                    </a>
                    <button class="btn btn-outline" onclick="openExportModal('financial_summary')" style="font-size:0.82rem; padding:0.45rem 1rem; border-color:var(--border);">
                        <i class="fas fa-download"></i> Export Summary
                    </button>
                </div>
            </div>

            <!-- Financial Mini Ribbon -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:1rem; margin-top:1.25rem; padding-top:1.25rem; border-top:1px solid rgba(255,255,255,0.08);">
                <div style="background:rgba(255,255,255,0.03); padding:0.85rem 1rem; border-radius:10px; border:1px solid var(--border);">
                    <span style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Period Gross Revenue</span>
                    <h3 style="font-size:1.4rem; font-weight:800; color:#52b788; margin:0.25rem 0 0 0;">&#8369;<?php echo number_format($kpis['period_revenue'], 2); ?></h3>
                    <span style="font-size:0.7rem; color:var(--text-muted);"><?php echo $kpis['total_txns']; ?> completed sales</span>
                </div>
                <div style="background:rgba(255,255,255,0.03); padding:0.85rem 1rem; border-radius:10px; border:1px solid var(--border);">
                    <span style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Period Operating Costs</span>
                    <h3 style="font-size:1.4rem; font-weight:800; color:#f87171; margin:0.25rem 0 0 0;">-&#8369;<?php echo number_format($kpis['period_expenses'], 2); ?></h3>
                    <span style="font-size:0.7rem; color:var(--text-muted);"><?php echo $kpis['total_expenses_cnt']; ?> recorded expenses</span>
                </div>
                <div style="background:rgba(255,255,255,0.03); padding:0.85rem 1rem; border-radius:10px; border:1px solid var(--border);">
                    <span style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Gross/Net Income (Malinis)</span>
                    <h3 style="font-size:1.4rem; font-weight:800; color:<?php echo $kpis['period_net_income'] >= 0 ? '#52b788' : '#ef4444'; ?>; margin:0.25rem 0 0 0;">
                        <?php echo ($kpis['period_net_income'] < 0 ? '-' : '') . '&#8369;' . number_format(abs($kpis['period_net_income']), 2); ?>
                    </h3>
                    <span style="font-size:0.7rem; color:<?php echo $kpis['period_net_income'] >= 0 ? '#52b788' : '#ef4444'; ?>; font-weight:700;">
                        <?php echo $kpis['period_net_income'] >= 0 ? '✓ Profitable Period' : '⚠ Deficit Period'; ?>
                    </span>
                </div>
                <div style="background:rgba(255,255,255,0.03); padding:0.85rem 1rem; border-radius:10px; border:1px solid var(--border);">
                    <span style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Net Profit Margin</span>
                    <h3 style="font-size:1.4rem; font-weight:800; color:#38bdf8; margin:0.25rem 0 0 0;"><?php echo $kpis['profit_margin']; ?>%</h3>
                    <span style="font-size:0.7rem; color:var(--text-muted);">Margin on sales</span>
                </div>
            </div>
        </div>

        <!-- 12-Month Financial Comparison Chart & Expense Breakdown -->
        <div style="display:grid; grid-template-columns: 2fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
            <!-- Trajectory Chart -->
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:0.5rem;">
                    <div>
                        <h3 class="section-title" style="margin:0;"><i class="fas fa-chart-line" style="color:var(--accent);"></i> 12-Month Revenue vs. Expenses vs. Net Income</h3>
                        <p style="margin:0.2rem 0 0 0; font-size:0.75rem; color:var(--text-muted);">Historical trajectory comparing sales volume, overhead costs, and net profit.</p>
                    </div>
                    <div style="display:flex; gap:0.75rem; font-size:0.75rem; font-weight:600;">
                        <span style="color:#52b788;"><i class="fas fa-square" style="font-size:0.65rem;"></i> Revenue</span>
                        <span style="color:#f87171;"><i class="fas fa-square" style="font-size:0.65rem;"></i> Expenses</span>
                        <span style="color:#38bdf8;"><i class="fas fa-circle" style="font-size:0.65rem;"></i> Net Income</span>
                    </div>
                </div>
                <div style="height:270px; position:relative;">
                    <canvas id="financialComparisonChart"></canvas>
                </div>
            </div>

            <!-- Expense Category Share Doughnut -->
            <div class="card">
                <div style="margin-bottom:1.25rem;">
                    <h3 class="section-title" style="margin:0;"><i class="fas fa-chart-pie" style="color:#f87171;"></i> Expense Distribution</h3>
                    <p style="margin:0.2rem 0 0 0; font-size:0.75rem; color:var(--text-muted);">Cost allocation across categories for active period.</p>
                </div>
                <div style="height:240px; position:relative;">
                    <?php if (empty($financial_report['category_dist'])): ?>
                        <div style="display:flex; height:100%; align-items:center; justify-content:center; flex-direction:column; color:var(--text-muted); font-size:0.8rem;">
                            <i class="fas fa-receipt" style="font-size:2rem; opacity:0.3; margin-bottom:0.5rem;"></i>
                            No expenses recorded in this period.
                        </div>
                    <?php else: ?>
                        <canvas id="expenseCategoryChart"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Monthly Profitability Ledger Table -->
        <div class="card" style="margin-bottom:1.5rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:0.75rem;">
                <div>
                    <h3 class="section-title" style="margin:0;"><i class="fas fa-table-list" style="color:var(--accent);"></i> Monthly Profitability &amp; Margin Ledger</h3>
                    <p style="margin:0.2rem 0 0 0; font-size:0.78rem; color:var(--text-muted);">Month-by-month accounting breakdown of total revenue, costs, net earnings, and margins.</p>
                </div>
                <div>
                    <button class="btn btn-outline" onclick="openExportModal('financial_summary')" style="font-size:0.8rem; padding:0.4rem 0.85rem;">
                        <i class="fas fa-download"></i> Export Financial Ledger
                    </button>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Month / Year</th>
                            <th style="text-align:right;">Gross Revenue</th>
                            <th style="text-align:right;">Operating Expenses</th>
                            <th style="text-align:right;">Gross / Net Income</th>
                            <th style="text-align:center;">Profit Margin</th>
                            <th style="text-align:center;">Operational Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($financial_report['monthly_ledger'] as $row): 
                            $isPos = $row['net_income'] > 0;
                            $isZero = $row['net_income'] == 0;
                            $statBadge = $isPos ? 'badge-active' : ($isZero ? 'badge-pending' : 'badge-inactive');
                            $statLabel = $isPos ? 'Profitable' : ($isZero ? 'Break-Even' : 'Deficit');
                        ?>
                        <tr>
                            <td style="font-weight:700; color:var(--text-main); font-size:0.85rem;">
                                <i class="fas fa-calendar-days" style="color:var(--accent); margin-right:6px;"></i>
                                <?php echo htmlspecialchars($row['label']); ?>
                            </td>
                            <td style="text-align:right; font-weight:700; color:#52b788; font-size:0.9rem;">
                                &#8369;<?php echo number_format($row['revenue'], 2); ?>
                            </td>
                            <td style="text-align:right; font-weight:700; color:#f87171; font-size:0.9rem;">
                                -&#8369;<?php echo number_format($row['expenses'], 2); ?>
                            </td>
                            <td style="text-align:right; font-weight:800; font-size:0.95rem; color:<?php echo $isPos ? '#52b788' : ($isZero ? 'var(--text-main)' : '#ef4444'); ?>;">
                                <?php echo ($row['net_income'] < 0 ? '-' : '') . '&#8369;' . number_format(abs($row['net_income']), 2); ?>
                            </td>
                            <td style="text-align:center; font-weight:700; color:#38bdf8;">
                                <?php echo $row['margin_pct']; ?>%
                            </td>
                            <td style="text-align:center;">
                                <span class="badge <?php echo $statBadge; ?>">
                                    <?php echo $statLabel; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Period Itemized Expenses Table -->
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:0.75rem;">
                <div>
                    <h3 class="section-title" style="margin:0;"><i class="fas fa-receipt" style="color:#f87171;"></i> Itemized Expense Records for Period</h3>
                    <p style="margin:0.2rem 0 0 0; font-size:0.78rem; color:var(--text-muted);">Detailed operational expense entries matching active date filter.</p>
                </div>
                <div style="display:flex; gap:0.5rem;">
                    <a href="expenses.php" class="btn btn-outline" style="font-size:0.8rem; padding:0.4rem 0.85rem;">
                        <i class="fas fa-arrow-up-right-from-square"></i> Open Full Expenses Manager
                    </a>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Payment Method</th>
                            <th>Ref / Voucher</th>
                            <th>Recorded By</th>
                            <th style="text-align:right;">Amount (PHP)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($financial_report['itemized_expenses'])): ?>
                        <tr><td colspan="7" style="text-align:center; padding:2rem; color:var(--text-muted);">No individual expense records found in this date range.</td></tr>
                        <?php else: ?>
                        <?php foreach ($financial_report['itemized_expenses'] as $item): ?>
                        <tr>
                            <td style="font-weight:600; font-size:0.82rem;"><?php echo date('M d, Y', strtotime($item['expense_date'])); ?></td>
                            <td><span class="badge" style="background:rgba(239,68,68,0.12); color:#f87171; font-weight:700;"><?php echo htmlspecialchars($item['category']); ?></span></td>
                            <td>
                                <span style="font-weight:600; color:var(--text-main);"><?php echo htmlspecialchars($item['title']); ?></span>
                                <?php if (!empty($item['notes'])): ?>
                                    <div style="font-size:0.72rem; color:var(--text-muted);"><?php echo htmlspecialchars($item['notes']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge" style="background:rgba(255,255,255,0.06);"><?php echo htmlspecialchars($item['payment_method']); ?></span></td>
                            <td><span style="font-family:monospace; color:var(--text-muted);"><?php echo htmlspecialchars($item['reference_number'] ?: '—'); ?></span></td>
                            <td style="font-size:0.8rem; color:var(--text-muted);"><?php echo htmlspecialchars($item['recorded_by_name'] ?: 'Admin'); ?></td>
                            <td style="text-align:right; font-weight:800; color:#f87171; font-size:0.92rem;">-&#8369;<?php echo number_format((float)$item['amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
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
                    <option value="financial_summary">3. Financial Profitability &amp; Net Income Summary</option>
                    <option value="expenses">4. Gym Operational Expenses Ledger</option>
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
                <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:0.5rem;">
                    <!-- CSV Option -->
                    <label style="margin:0; cursor:pointer; width:100%;">
                        <input type="radio" name="format" value="csv" checked style="display:none;" id="export-format-csv">
                        <div class="format-card active" onclick="setExportFormat('csv')" id="card-format-csv" style="border: 1px solid var(--accent); background:rgba(45,106,79,0.1); color:var(--text-main); border-radius:10px; padding:0.65rem 0.35rem; text-align:center; transition:all 0.25s;">
                            <div style="font-size:1rem; color:var(--accent); font-weight:bold;"><i class="fas fa-file-csv"></i> CSV</div>
                            <span style="font-size:0.65rem; color:var(--text-muted); display:block; margin-top:2px;">Spreadsheet</span>
                        </div>
                    </label>
                    
                    <!-- Excel Option -->
                    <label style="margin:0; cursor:pointer; width:100%;">
                        <input type="radio" name="format" value="xls" style="display:none;" id="export-format-xls">
                        <div class="format-card" onclick="setExportFormat('xls')" id="card-format-xls" style="border: 1px solid var(--border); background:transparent; color:var(--text-main); border-radius:10px; padding:0.65rem 0.35rem; text-align:center; transition:all 0.25s;">
                            <div style="font-size:1rem; color:#52b788; font-weight:bold;"><i class="fas fa-file-excel"></i> Excel</div>
                            <span style="font-size:0.65rem; color:var(--text-muted); display:block; margin-top:2px;">.XLS Sheet</span>
                        </div>
                    </label>

                    <!-- PDF Option -->
                    <label style="margin:0; cursor:pointer; width:100%;">
                        <input type="radio" name="format" value="pdf" style="display:none;" id="export-format-pdf">
                        <div class="format-card" onclick="setExportFormat('pdf')" id="card-format-pdf" style="border: 1px solid var(--border); background:transparent; color:var(--text-main); border-radius:10px; padding:0.65rem 0.35rem; text-align:center; transition:all 0.25s;">
                            <div style="font-size:1rem; color:#ef4444; font-weight:bold;"><i class="fas fa-file-pdf"></i> PDF</div>
                            <span style="font-size:0.65rem; color:var(--text-muted); display:block; margin-top:2px;">Executive Doc</span>
                        </div>
                    </label>
                    
                    <!-- JSON Option -->
                    <label style="margin:0; cursor:pointer; width:100%;">
                        <input type="radio" name="format" value="json" style="display:none;" id="export-format-json">
                        <div class="format-card" onclick="setExportFormat('json')" id="card-format-json" style="border: 1px solid var(--border); background:transparent; color:var(--text-main); border-radius:10px; padding:0.65rem 0.35rem; text-align:center; transition:all 0.25s;">
                            <div style="font-size:1rem; color:#eab308; font-weight:bold;"><i class="fas fa-file-code"></i> JSON</div>
                            <span style="font-size:0.65rem; color:var(--text-muted); display:block; margin-top:2px;">Raw Data</span>
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
    .sidebar, .topbar, .no-print, .report-nav-container, .modal { display: none !important; }
    .main-content { margin-left: 0 !important; width: 100% !important; padding: 0.5rem !important; }
    .card { break-inside: avoid; box-shadow: none !important; border: 1px solid #e2e8f0 !important; background: #fff !important; color: #0f172a !important; }
    .report-tab-pane.active { display: block !important; }
    .report-tab-pane:not(.active) { display: none !important; }
    body { background: #fff !important; color: #0f172a !important; }
    table { width: 100% !important; border-collapse: collapse !important; }
    th { background: #1b4332 !important; color: #ffffff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    td { color: #0f172a !important; border-bottom: 1px solid #e2e8f0 !important; }
}

/* PDF Generator Layout */
#analytics-master-container.pdf-render-mode .no-print { display: none !important; }
#analytics-master-container.pdf-render-mode .report-tab-pane.active { display: block !important; }
#analytics-master-container.pdf-render-mode .report-tab-pane:not(.active) { display: none !important; }
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

// 3. Report 3 Charts: Financial Profitability & Net Income
const finCanvas = document.getElementById('financialComparisonChart');
if (finCanvas) {
    new Chart(finCanvas, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($financial_report['trend_labels']); ?>,
            datasets: [
                {
                    label: 'Gross Revenue',
                    data: <?php echo json_encode($financial_report['trend_revenue']); ?>,
                    backgroundColor: themeGreen,
                    borderRadius: 5,
                    order: 2
                },
                {
                    label: 'Operational Expenses',
                    data: <?php echo json_encode($financial_report['trend_expenses']); ?>,
                    backgroundColor: themeRed,
                    borderRadius: 5,
                    order: 3
                },
                {
                    type: 'line',
                    label: 'Net Income (Profit/Loss)',
                    data: <?php echo json_encode($financial_report['trend_net']); ?>,
                    borderColor: themeBlue,
                    backgroundColor: 'rgba(56, 189, 248, 0.15)',
                    borderWidth: 2.5,
                    pointRadius: 4,
                    pointBackgroundColor: themeBlue,
                    tension: 0.35,
                    order: 1
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.dataset.label + ': ₱' + Number(ctx.raw).toLocaleString('en-US', { minimumFractionDigits: 2 })
                    }
                }
            },
            scales: {
                y: { grid: { color: gridColor }, ticks: { font: themeFont, callback: v => '₱' + Number(v).toLocaleString() } },
                x: { grid: { display: false }, ticks: { font: themeFont } }
            }
        }
    });
}

const expCatCanvas = document.getElementById('expenseCategoryChart');
if (expCatCanvas) {
    new Chart(expCatCanvas, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_column($financial_report['category_dist'], 'name')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_map('floatval', array_column($financial_report['category_dist'], 'total'))); ?>,
                backgroundColor: ['#38bdf8', '#c084fc', '#f87171', '#eab308', '#52b788', '#f97316', '#a855f7', '#64748b'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right', labels: { font: themeFont, color: '#94a3b8', boxWidth: 12 } },
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.label + ': ₱' + Number(ctx.raw).toLocaleString('en-US', { minimumFractionDigits: 2 })
                    }
                }
            }
        }
    });
}

</script>

<?php include 'includes/footer.php'; ?>
