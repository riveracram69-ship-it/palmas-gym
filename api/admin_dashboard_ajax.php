<?php
/**
 * Admin Dashboard AJAX Endpoints
 * Extracted from index.php — Priority 14 Code Quality
 *
 * Handles:
 *   GET ?ajax=live_feed        → JSON activity feed
 *   GET ?action=send_reminder  → Send email reminder to a member
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/logger.php';
require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../config/settings.php';

require_login();

header('Content-Type: application/json; charset=UTF-8');
// ── 0. Check Pending Cash Approvals Real-Time Monitoring ───────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_pending_approvals') {
    try {
        // 1. Pending Cash Registrations Count (AUDIT-009: Actionable Cash requests only)
        $reg_count_stmt = $pdo->query("
            SELECT COUNT(*) FROM members m
            WHERE m.account_status = 'Pending'
              AND (
                  EXISTS (
                      SELECT 1 FROM renewal_requests rr 
                      WHERE rr.member_id = m.id 
                        AND rr.status = 'Pending' 
                        AND rr.payment_method = 'Cash'
                  )
                  OR EXISTS (
                      SELECT 1 FROM payment_transactions pt 
                      WHERE pt.member_id = m.id 
                        AND pt.status = 'PENDING' 
                        AND pt.payment_method = 'Cash'
                  )
              )
        ");
        $pending_regs = (int)($reg_count_stmt ? $reg_count_stmt->fetchColumn() : 0);

        // 2. Pending Cash Renewals Count
        $renew_count_stmt = $pdo->query("
            SELECT COUNT(*) FROM renewal_requests r
            WHERE r.status = 'Pending'
              AND r.payment_method = 'Cash'
              AND (r.reference_no NOT LIKE 'REG-%' OR r.reference_no IS NULL)
        ");
        $pending_renews = (int)($renew_count_stmt ? $renew_count_stmt->fetchColumn() : 0);

        // 3. Latest Cash Registration Record (AUDIT-009: Actionable Cash requests only)
        $latest_reg_stmt = $pdo->query("
            SELECT m.id, m.full_name, m.membership_id, m.created_at,
                   COALESCE(p.name, 'Membership Plan') as plan_name,
                   COALESCE(p.price, 0) as plan_price
            FROM members m
            LEFT JOIN membership_plans p ON p.id = m.selected_plan_id
            WHERE m.account_status = 'Pending'
              AND (
                  EXISTS (
                      SELECT 1 FROM renewal_requests rr 
                      WHERE rr.member_id = m.id 
                        AND rr.status = 'Pending' 
                        AND rr.payment_method = 'Cash'
                  )
                  OR EXISTS (
                      SELECT 1 FROM payment_transactions pt 
                      WHERE pt.member_id = m.id 
                        AND pt.status = 'PENDING' 
                        AND pt.payment_method = 'Cash'
                  )
              )
            ORDER BY m.id DESC LIMIT 1
        ");
        $latest_reg = $latest_reg_stmt ? $latest_reg_stmt->fetch(PDO::FETCH_ASSOC) : null;
        $latest_reg_id = $latest_reg ? (int)$latest_reg['id'] : 0;

        // 4. Latest Cash Renewal Record
        $latest_renew_stmt = $pdo->query("
            SELECT r.id, r.created_at, r.payment_method, m.full_name, m.membership_id,
                   p.name as plan_name, p.price as plan_price
            FROM renewal_requests r
            JOIN members m ON r.member_id = m.id
            JOIN membership_plans p ON r.plan_id = p.id
            WHERE r.status = 'Pending'
              AND r.payment_method = 'Cash'
              AND (r.reference_no NOT LIKE 'REG-%' OR r.reference_no IS NULL)
            ORDER BY r.id DESC LIMIT 1
        ");
        $latest_renew = $latest_renew_stmt ? $latest_renew_stmt->fetch(PDO::FETCH_ASSOC) : null;
        $latest_renew_id = $latest_renew ? (int)$latest_renew['id'] : 0;

        // Determine which request is newer to feature in toast
        $latest_request = null;
        if ($latest_reg && $latest_renew) {
            $reg_ts = strtotime($latest_reg['created_at']);
            $renew_ts = strtotime($latest_renew['created_at']);
            if ($reg_ts >= $renew_ts) {
                $latest_request = [
                    'type'          => 'REGISTRATION',
                    'id'            => (int)$latest_reg['id'],
                    'member_name'   => $latest_reg['full_name'],
                    'membership_id' => $latest_reg['membership_id'],
                    'plan_name'     => $latest_reg['plan_name'],
                    'plan_price'    => (float)$latest_reg['plan_price'],
                    'created_at'    => $latest_reg['created_at']
                ];
            } else {
                $latest_request = [
                    'type'          => 'RENEWAL',
                    'id'            => (int)$latest_renew['id'],
                    'member_name'   => $latest_renew['full_name'],
                    'membership_id' => $latest_renew['membership_id'],
                    'plan_name'     => $latest_renew['plan_name'],
                    'plan_price'    => (float)$latest_renew['plan_price'],
                    'created_at'    => $latest_renew['created_at']
                ];
            }
        } elseif ($latest_reg) {
            $latest_request = [
                'type'          => 'REGISTRATION',
                'id'            => (int)$latest_reg['id'],
                'member_name'   => $latest_reg['full_name'],
                'membership_id' => $latest_reg['membership_id'],
                'plan_name'     => $latest_reg['plan_name'],
                'plan_price'    => (float)$latest_reg['plan_price'],
                'created_at'    => $latest_reg['created_at']
            ];
        } elseif ($latest_renew) {
            $latest_request = [
                'type'          => 'RENEWAL',
                'id'            => (int)$latest_renew['id'],
                'member_name'   => $latest_renew['full_name'],
                'membership_id' => $latest_renew['membership_id'],
                'plan_name'     => $latest_renew['plan_name'],
                'plan_price'    => (float)$latest_renew['plan_price'],
                'created_at'    => $latest_renew['created_at']
            ];
        }

        echo json_encode([
            'success'         => true,
            'total_pending'   => $pending_regs + $pending_renews,
            'pending_regs'    => $pending_regs,
            'pending_renews'  => $pending_renews,
            'latest_reg_id'   => $latest_reg_id,
            'latest_renew_id' => $latest_renew_id,
            'latest_request'  => $latest_request
        ]);
    } catch (Exception $e) {
        error_log("check_pending_approvals error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Internal server error while checking pending approvals.']);
    }
    exit;
}

// ── 0. Dynamic Leaderboard Endpoint (Filter by Any Month / Period & Metric) ─────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'leaderboard_data') {
    $period = trim($_GET['period'] ?? 'this_month');
    $metric = trim($_GET['metric'] ?? 'visits'); // 'visits' or 'plans'
    $where_att = "1=1";
    $where_sub = "1=1";
    $period_label = "This Month";

    if ($period === 'this_month') {
        $where_att = "YEAR(a.date) = YEAR(CURDATE()) AND MONTH(a.date) = MONTH(CURDATE())";
        $where_sub = "YEAR(s.start_date) = YEAR(CURDATE()) AND MONTH(s.start_date) = MONTH(CURDATE())";
        $period_label = date('F Y');
    } elseif ($period === 'prev_month' || $period === 'last_month') {
        $where_att = "YEAR(a.date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(a.date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
        $where_sub = "YEAR(s.start_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(s.start_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
        $period_label = date('F Y', strtotime('-1 month'));
    } elseif ($period === 'this_week') {
        $where_att = "YEARWEEK(a.date, 1) = YEARWEEK(CURDATE(), 1)";
        $where_sub = "YEARWEEK(s.start_date, 1) = YEARWEEK(CURDATE(), 1)";
        $period_label = "This Week";
    } elseif ($period === 'today') {
        $where_att = "a.date = CURDATE()";
        $where_sub = "DATE(s.start_date) = CURDATE()";
        $period_label = "Today (" . date('M d, Y') . ")";
    } elseif ($period === 'all_time') {
        $where_att = "1=1";
        $where_sub = "1=1";
        $period_label = "All-Time History";
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $period)) {
        $where_att = "a.date = " . $pdo->quote($period);
        $where_sub = "DATE(s.start_date) = " . $pdo->quote($period);
        $period_label = date('F d, Y', strtotime($period));
    } elseif (preg_match('/^\d{4}-\d{2}$/', $period)) {
        list($y, $m) = explode('-', $period);
        $where_att = "YEAR(a.date) = " . intval($y) . " AND MONTH(a.date) = " . intval($m);
        $where_sub = "YEAR(s.start_date) = " . intval($y) . " AND MONTH(s.start_date) = " . intval($m);
        $period_label = date('F Y', strtotime($period . '-01'));
    } elseif (preg_match('/^(\d{4}-\d{2}-\d{2})_to_(\d{4}-\d{2}-\d{2})$/', $period, $matches)) {
        $from = $matches[1];
        $to = $matches[2];
        $where_att = "a.date BETWEEN " . $pdo->quote($from) . " AND " . $pdo->quote($to);
        $where_sub = "s.start_date BETWEEN " . $pdo->quote($from) . " AND " . $pdo->quote($to);
        $period_label = date('M d, Y', strtotime($from)) . ' – ' . date('M d, Y', strtotime($to));
    }

    try {
        if ($metric === 'plans') {
            // Leaderboard by Plans Availed & Spending
            $leaderboard_sql = "
                SELECT m.id, m.full_name, m.membership_id, m.photo,
                       COALESCE(
                           (SELECT p2.name 
                            FROM subscriptions s2 
                            JOIN membership_plans p2 ON p2.id = s2.plan_id 
                            WHERE s2.member_id = m.id 
                            GROUP BY s2.plan_id, p2.name 
                            ORDER BY COUNT(*) DESC, MAX(s2.start_date) DESC 
                            LIMIT 1),
                           'Standard'
                       ) as plan_name,
                       COALESCE(sub_stats.plans_availed, 0) as metric_count,
                       COALESCE(pay_stats.total_spend, 0) as total_spend
                FROM members m
                JOIN (
                    SELECT member_id, COUNT(*) as plans_availed
                    FROM subscriptions s
                    WHERE {$where_sub}
                    GROUP BY member_id
                ) sub_stats ON sub_stats.member_id = m.id
                LEFT JOIN (
                    SELECT p.member_id, SUM(p.amount) as total_spend
                    FROM payments p
                    LEFT JOIN subscriptions s ON p.subscription_id = s.id
                    LEFT JOIN membership_plans plan ON s.plan_id = plan.id
                    WHERE (plan.is_test_promo IS NULL OR plan.is_test_promo = 0)
                      AND (plan.plan_category IS NULL OR plan.plan_category != 'test_promo')
                    GROUP BY p.member_id
                ) pay_stats ON pay_stats.member_id = m.id
                WHERE sub_stats.plans_availed > 0
                ORDER BY metric_count DESC, total_spend DESC, m.full_name ASC
                LIMIT 5
            ";
        } else {
            // Leaderboard by Gym Check-ins (Visits)
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
                       COUNT(a.id) as metric_count,
                       0 as total_spend
                FROM members m
                JOIN attendance a ON a.member_id = m.id
                WHERE {$where_att}
                GROUP BY m.id, m.full_name, m.membership_id, m.photo
                HAVING metric_count > 0
                ORDER BY metric_count DESC, m.full_name ASC
                LIMIT 5
            ";
        }

        $stmt = $pdo->query($leaderboard_sql);
        $members = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        echo json_encode([
            'success'      => true, 
            'metric'       => $metric,
            'period'       => $period, 
            'period_label' => $period_label, 
            'members'      => $members
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'members' => []]);
    }
    exit;
}

// ── 1. Live Activity Feed ──────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'live_feed') {
    $feed = [];

    try {
        // A. Check-ins & Check-outs (Last 12)
        $stmt = $pdo->query(
            "SELECT a.id, a.date, a.time_in, a.time_out, m.full_name, m.membership_id, m.photo
             FROM attendance a
             JOIN members m ON m.id = a.member_id
             ORDER BY a.date DESC, a.time_in DESC
             LIMIT 12"
        );
        foreach ($stmt->fetchAll() as $att) {
            $ts = strtotime($att['date'] . ' ' . $att['time_in']);
            $feed[] = [
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
                $feed[] = [
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

        // B. Payments (Recent 10 - Admin Only)
        if (is_admin()) {
            $stmt = $pdo->query(
                "SELECT p.id, p.payment_date, p.created_at, p.amount, p.payment_method, m.full_name, m.membership_id
                 FROM payments p
                 JOIN members m ON m.id = p.member_id
                 ORDER BY p.created_at DESC
                 LIMIT 10"
            );
            foreach ($stmt->fetchAll() as $pay) {
                $ts = strtotime($pay['created_at'] ?: $pay['payment_date']);
                $feed[] = [
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

        // C. Renewals & Subscriptions (Recent 8)
        $stmt = $pdo->query(
            "SELECT s.id, s.start_date, s.created_at, m.full_name, p.name as plan_name
             FROM subscriptions s
             JOIN members m ON m.id = s.member_id
             JOIN membership_plans p ON p.id = s.plan_id
             ORDER BY s.created_at DESC
             LIMIT 8"
        );
        foreach ($stmt->fetchAll() as $sub) {
            $ts = strtotime($sub['created_at'] ?: $sub['start_date']);
            $feed[] = [
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

        // D. New Registrations (Recent 8)
        $stmt = $pdo->query(
            "SELECT id, full_name, membership_id, created_at
             FROM members
             ORDER BY created_at DESC
             LIMIT 8"
        );
        foreach ($stmt->fetchAll() as $m_reg) {
            $ts = strtotime($m_reg['created_at']);
            $feed[] = [
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

        // Sort descending, cap at 20, add relative time
        usort($feed, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
        $feed = array_slice($feed, 0, 20);
        $now  = time();
        foreach ($feed as &$item) {
            $diff = $now - $item['timestamp'];
            if ($diff < 60)         $item['relative_time'] = 'Just now';
            elseif ($diff < 3600)   $item['relative_time'] = floor($diff / 60) . ' mins ago';
            elseif ($diff < 86400)  $item['relative_time'] = floor($diff / 3600) . ' hrs ago';
            else                    $item['relative_time'] = floor($diff / 86400) . ' days ago';
        }
    } catch (Exception $e) {
        $feed = [];
    }

    echo json_encode($feed);
    exit;
}

// ── 2. Send Reminder ───────────────────────────────────────────────────────────
if (isset($_GET['action']) && ($_GET['action'] === 'send_reminder' || $_GET['action'] === 'send_renewal_reminder')) {
    $member_id = intval($_GET['member_id'] ?? $_POST['member_id'] ?? 0);

    if ($member_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid member ID specified.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT m.id, m.full_name, m.email, m.membership_id, s.expiry_date, p.name as plan_name 
                               FROM members m 
                               LEFT JOIN (SELECT member_id, MAX(id) as max_id FROM subscriptions GROUP BY member_id) l ON l.member_id = m.id
                               LEFT JOIN subscriptions s ON s.id = l.max_id
                               LEFT JOIN membership_plans p ON p.id = s.plan_id
                               WHERE m.id = ?");
        $stmt->execute([$member_id]);
        $target = $stmt->fetch();

        if (!$target) {
            echo json_encode(['success' => false, 'message' => 'Member not found.']);
            exit;
        }

        if (empty($target['email'])) {
            echo json_encode(['success' => false, 'message' => 'Member has no registered email address.']);
            exit;
        }

        $gym_name = $app_settings['gym_name'] ?? "Palma's Elite Gym";
        $subject  = "Gym Pass Renewal Reminder — {$gym_name}";
        $title    = "Membership Renewal Reminder";
        $exp_date_str = !empty($target['expiry_date']) ? date('M d, Y', strtotime($target['expiry_date'])) : 'recently';
        $plan_str     = !empty($target['plan_name']) ? htmlspecialchars($target['plan_name']) : 'Gym Pass';

        $body = "Hi <strong>" . htmlspecialchars($target['full_name']) . "</strong>,<br><br>"
              . "Your <strong>{$plan_str}</strong> at {$gym_name} expired on <strong>{$exp_date_str}</strong>.<br><br>"
              . "We'd love to have you back on the workout floor! You can renew your pass seamlessly online via GCash/Maya by logging into your member portal, or simply visit the front desk on your next visit.<br><br>"
              . "<a href='" . (defined('APP_URL') ? APP_URL : '') . "/member/login.php' style='display:inline-block;background:#2d6a4f;color:#ffffff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700;'>Renew Membership Online →</a>";

        send_email_notification($target['email'], $subject, $title, $body);
        log_activity($pdo, 'Sent Renewal Reminder', "Sent renewal follow-up to {$target['full_name']} ({$target['membership_id']})", 'Member');

        echo json_encode(['success' => true, 'message' => 'Renewal reminder successfully sent to ' . htmlspecialchars($target['full_name']) . '!']);
    } catch (Exception $e) {
        error_log('API Error in admin_dashboard_ajax.php reminder: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to send reminder. ' . $e->getMessage()]);
    }
    exit;
}

// ── 3. Bulk & Selective Renewal Reminders ───────────────────────────────────────
if (isset($_GET['action']) && ($_GET['action'] === 'send_bulk_renewal_reminders' || $_GET['action'] === 'send_selected_renewal_reminders')) {
    try {
        $raw_input = file_get_contents('php://input');
        $json_data = json_decode($raw_input, true) ?: [];
        $selected_ids = $json_data['member_ids'] ?? $_POST['member_ids'] ?? $_GET['member_ids'] ?? null;

        $where_ids_clause = "";
        $params = [];

        if (!empty($selected_ids)) {
            if (is_string($selected_ids)) {
                $id_list = array_filter(array_map('intval', explode(',', $selected_ids)));
            } elseif (is_array($selected_ids)) {
                $id_list = array_filter(array_map('intval', $selected_ids));
            } else {
                $id_list = [];
            }

            if (!empty($id_list)) {
                $placeholders = implode(',', array_fill(0, count($id_list), '?'));
                $where_ids_clause = " AND s.member_id IN ($placeholders) ";
                $params = array_values($id_list);
            }
        }

        $query = "
            SELECT m.id, m.full_name, m.email, m.membership_id, s.expiry_date, p.name as plan_name
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
              AND m.email IS NOT NULL AND m.email != ''
              {$where_ids_clause}
        ";

        $stmt_all = $pdo->prepare($query);
        $stmt_all->execute($params);
        $expired_members = $stmt_all ? $stmt_all->fetchAll(PDO::FETCH_ASSOC) : [];
        $sent_count = 0;
        $gym_name = $app_settings['gym_name'] ?? "Palma's Elite Gym";

        foreach ($expired_members as $target) {
            $subject  = "Gym Pass Renewal Reminder — {$gym_name}";
            $title    = "Membership Renewal Reminder";
            $exp_date_str = !empty($target['expiry_date']) ? date('M d, Y', strtotime($target['expiry_date'])) : 'recently';
            $plan_str     = !empty($target['plan_name']) ? htmlspecialchars($target['plan_name']) : 'Gym Pass';

            $body = "Hi <strong>" . htmlspecialchars($target['full_name']) . "</strong>,<br><br>"
                  . "Your <strong>{$plan_str}</strong> at {$gym_name} expired on <strong>{$exp_date_str}</strong>.<br><br>"
                  . "We'd love to have you back on the workout floor! You can renew your pass online via GCash/Maya or visit the front desk.<br><br>"
                  . "<a href='" . (defined('APP_URL') ? APP_URL : '') . "/member/login.php' style='display:inline-block;background:#2d6a4f;color:#ffffff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700;'>Renew Membership Online →</a>";

            try {
                send_email_notification($target['email'], $subject, $title, $body);
                $sent_count++;
            } catch (Exception $e) {}
        }

        log_activity($pdo, 'Renewal Reminders', "Sent renewal reminders to {$sent_count} selected expired member(s).", 'Member');
        echo json_encode([
            'success'    => true, 
            'sent_count' => $sent_count, 
            'message'    => "Successfully dispatched renewal reminders to {$sent_count} selected member(s)!"
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to send renewal reminders: ' . $e->getMessage()]);
    }
    exit;
}

// ── 4. Quick Renew Action (Instant Front-Desk Renewal) ─────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'quick_renew') {
    $member_id      = intval($_POST['member_id'] ?? 0);
    $plan_id        = intval($_POST['plan_id'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? 'Cash');
    $notes          = trim($_POST['notes'] ?? 'Quick front-desk renewal');

    if ($member_id <= 0 || $plan_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Member ID and Plan ID are required.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $m_stmt = $pdo->prepare("SELECT * FROM members WHERE id = ? FOR UPDATE");
        $m_stmt->execute([$member_id]);
        $member = $m_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Member not found.']);
            exit;
        }

        $p_stmt = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ? AND is_active = 1");
        $p_stmt->execute([$plan_id]);
        $plan = $p_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Selected membership plan is invalid or inactive.']);
            exit;
        }

        $now_str          = date('Y-m-d H:i:s');
        $duration_minutes = intval($plan['duration_minutes'] ?? 0);
        $duration_months  = intval($plan['duration_months'] ?? 0);
        $plan_category    = $plan['plan_category'] ?? 'legacy';
        $price            = floatval($plan['price'] ?? 0);

        $is_daily_pass     = ($duration_minutes === 1440 || ($duration_months === 0 && stripos($plan['name'] ?? '', 'Daily') !== false));
        $is_minute_promo   = ($duration_minutes > 0 && !$is_daily_pass);
        $is_membership_fee = ($plan_category === 'membership_fee' || stripos($plan['name'] ?? '', 'Annual Membership Fee') !== false);

        if ($is_membership_fee) {
            $current_ann = $member['annual_membership_expiry'] ?? null;
            if ($current_ann && strtotime($current_ann) >= strtotime(date('Y-m-d'))) {
                $new_ann_expiry = date('Y-m-d', strtotime($current_ann . ' +1 year'));
            } else {
                $new_ann_expiry = date('Y-m-d', strtotime('+1 year'));
            }
            $pdo->prepare("UPDATE members SET status = 'Active', account_status = 'Approved', annual_membership_expiry = ? WHERE id = ?")
                ->execute([$new_ann_expiry, $member_id]);
            $start_date  = $now_str;
            $expiry_date = $new_ann_expiry . ' 23:59:59';
        } elseif ($is_minute_promo) {
            $start_date  = $now_str;
            $expiry_date = date('Y-m-d H:i:s', strtotime("+{$duration_minutes} minutes"));
            $pdo->prepare("UPDATE members SET status = 'Active', account_status = 'Approved' WHERE id = ?")->execute([$member_id]);
        } elseif ($is_daily_pass) {
            $start_date  = $now_str;
            $expiry_date = date('Y-m-d 23:59:59');
            $pdo->prepare("UPDATE members SET status = 'Active', account_status = 'Approved' WHERE id = ?")->execute([$member_id]);
        } else {
            $months = max(1, $duration_months);
            $start_date  = $now_str;
            $expiry_date = date('Y-m-d 23:59:59', strtotime("+{$months} months"));
            $pdo->prepare("UPDATE members SET status = 'Active', account_status = 'Approved' WHERE id = ?")->execute([$member_id]);
        }

        // Insert Subscription
        $ins_sub = $pdo->prepare("INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date, created_at) VALUES (?, ?, ?, ?, NOW())");
        $ins_sub->execute([$member_id, $plan_id, $start_date, $expiry_date]);

        // Insert Payment
        $ins_pay = $pdo->prepare("INSERT INTO payments (member_id, amount, payment_method, payment_date, created_at) VALUES (?, ?, ?, CURDATE(), NOW())");
        $ins_pay->execute([$member_id, $price, $payment_method]);

        $pdo->commit();

        log_activity($pdo, 'Quick Member Renewal', "Renewed {$member['full_name']} ({$member['membership_id']}) with {$plan['name']} (₱" . number_format($price, 2) . ") via {$payment_method}.", 'Attendance');

        echo json_encode([
            'success' => true,
            'member_id' => $member_id,
            'member_name' => $member['full_name'],
            'plan_name' => $plan['name'],
            'expiry_date' => date('M d, Y', strtotime($expiry_date)),
            'message' => "Successfully renewed {$member['full_name']} with {$plan['name']} (₱" . number_format($price, 2) . ")!"
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Renewal failed: ' . $e->getMessage()]);
    }
    exit;
}

// Unrecognised action
http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
