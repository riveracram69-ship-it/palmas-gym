<?php
/**
 * api/member_dashboard.php — High-Resilience Member Dashboard API
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/cors.php'; // [R-02 FIX] Replaced wildcard CORS with origin-allowlist

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../config/member_helpers.php';
    require_once __DIR__ . '/auth_middleware.php';

    $member_id = $auth_member_id;
    $t_start   = microtime(true);

    // ── QUERY 1: Member + Latest Subscription (CRITICAL — renders the card) ──
    $stmt = $pdo->prepare("
        SELECT 
            m.id, m.membership_id, m.first_name, m.middle_name, m.last_name, m.extension,
            m.full_name, m.email, m.contact_number,
            m.house_street, m.barangay, m.municipality, m.province, m.zip_code, m.address,
            m.dob, m.age, m.gender,
            m.photo, m.google_picture, m.auth_provider, m.status,
            m.account_status,
            m.annual_membership_expiry,
            s.id    AS subscription_id,
            s.expiry_date,
            s.start_date,
            p.name  AS plan_name,
            p.id    AS plan_id,
            p.duration_months,
            p.duration_minutes,
            p.is_test_promo,
            p.plan_category,
            p.floor_access
        FROM members m
        LEFT JOIN subscriptions s 
            ON s.id = (
                SELECT s2.id FROM subscriptions s2 
                LEFT JOIN membership_plans p2 ON p2.id = s2.plan_id
                WHERE s2.member_id = m.id AND (p2.plan_category IS NULL OR p2.plan_category != 'membership_fee')
                ORDER BY (s2.expiry_date >= NOW()) DESC, s2.expiry_date DESC, s2.id DESC LIMIT 1
            )
        LEFT JOIN membership_plans p ON p.id = s.plan_id
        WHERE m.id = ?
        LIMIT 1
    ");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }

    $member['formatted_address'] = format_member_address($member);
    $member['formatted_dob']     = format_member_dob($member['dob'] ?? null, $member['age'] ?? null);
    $member['computed_age']      = compute_member_age($member['dob'] ?? null, $member['age'] ?? null);

    $now_time = time();
    $exp_ts = (!empty($member['expiry_date'])) 
        ? ((strpos($member['expiry_date'], ':') !== false) ? strtotime($member['expiry_date']) : strtotime($member['expiry_date'] . ' 23:59:59'))
        : 0;
    $is_expired = (!empty($member['expiry_date']) && $exp_ts < $now_time);
    $member['is_expired'] = $is_expired;

    // Membership Tier (Official Member vs Non-Member based on annual_membership_expiry)
    $annual_exp = $member['annual_membership_expiry'] ?? null;
    $is_official_member = false;
    $annual_status = 'Non-Member';
    if (!empty($annual_exp)) {
        $annual_ts = strtotime($annual_exp . ' 23:59:59');
        if ($annual_ts >= $now_time) {
            $is_official_member = true;
            $annual_status = 'Official Member';
        } else {
            $annual_status = 'Expired Member';
        }
    }
    $member['annual_membership_expiry'] = $annual_exp;
    $member['annual_membership_expiry_formatted'] = !empty($annual_exp) ? date('M d, Y', strtotime($annual_exp)) : null;
    $member['is_official_member'] = $is_official_member;
    $member['membership_tier'] = $annual_status;
    $member['plan_category'] = $member['plan_category'] ?? ($is_official_member ? 'member_pass' : 'non_member_pass');
    $member['floor_access'] = $member['floor_access'] ?? 'all';

    // Workout Pass Status
    $has_active_pass = (!empty($member['subscription_id']) && !$is_expired);
    $member['has_active_pass'] = $has_active_pass;
    $member['workout_pass_name'] = $has_active_pass 
        ? $member['plan_name'] 
        : ($is_official_member ? 'No Active Workout Pass' : 'No Active Guest Pass');

    $is_active = ($member['status'] === 'Active' && ($member['account_status'] ?? 'Approved') === 'Approved');
    $member['is_active'] = $is_active;
    $member['has_gym_access'] = $is_active && $has_active_pass;

    // ── SECTION A & B INDEPENDENT ELIGIBILITY CHECKS ──
    $ann_check = can_renew_annual_membership($member);
    $gym_check = can_renew_gym_access($member['expiry_date'] ?? null, (int)($member['duration_minutes'] ?? 0), $member['plan_name'] ?? null);

    $annual_membership_data = [
        'is_official'         => $is_official_member,
        'status'              => $annual_status,
        'expiry_date'         => $annual_exp,
        'expiry_formatted'    => $member['annual_membership_expiry_formatted'],
        'days_remaining'      => $ann_check['days_remaining'],
        'can_renew'           => $ann_check['can_renew'],
        'cannot_renew_reason' => $ann_check['reason'],
    ];

    $gym_access_data = [
        'has_active_pass'     => $has_active_pass,
        'plan_name'           => $has_active_pass ? $member['plan_name'] : null,
        'plan_id'             => $has_active_pass ? (int)$member['plan_id'] : null,
        'expiry_date'         => $member['expiry_date'] ?? null,
        'expiry_formatted'    => !empty($member['expiry_date']) ? date('M d, Y', strtotime($member['expiry_date'])) : null,
        'days_remaining'      => $gym_check['days_remaining'],
        'can_renew'           => $gym_check['can_renew'],
        'cannot_renew_reason' => $gym_check['reason'],
    ];

    $member['annual_membership']   = $annual_membership_data;
    $member['gym_access']          = $gym_access_data;
    $member['can_renew']           = $gym_check['can_renew'];
    $member['cannot_renew_reason'] = $gym_check['reason'];

    // Idempotently dispatch MEMBERSHIP_EXPIRED notification if subscription has elapsed
    if ($is_expired) {
        try {
            require_once __DIR__ . '/../config/notifications.php';
            ensure_notifications_table($pdo);
            create_notification(
                $pdo,
                (int)$member_id,
                'MEMBERSHIP_EXPIRED',
                'Membership Plan Expired',
                'Your ' . ($member['plan_name'] ? htmlspecialchars($member['plan_name']) : 'gym') . ' plan has expired. Renew your plan now to continue uninterrupted access.',
                'Sent',
                $member['subscription_id'] ? (int)$member['subscription_id'] : null,
                'STAGE_EXPIRED'
            );
        } catch (Throwable $notifEx) {
            error_log("Dashboard expired notification error: " . $notifEx->getMessage());
        }
    }

    // Prefer uploaded photo if available, fallback to Google picture
    $member['photo'] = $member['photo'] ?: ($member['google_picture'] ?? null);

    // ── DYNAMIC HMAC ROTATING QR TOKEN (15-second window) ──
    $time_slot  = floor(time() / 15);
    $secret_key = defined('QR_SECRET_KEY') ? QR_SECRET_KEY : 'palmas_secret_key_987';
    $signature  = hash_hmac('sha256', $member['membership_id'] . '|' . $time_slot, $secret_key);
    $qr_token   = $member['membership_id'] . ':' . $time_slot . ':' . substr($signature, 0, 16);

    // ── QUERY 2: Attendance (Safe Fallback) ──
    $attendance = [];
    try {
        $att_stmt = $pdo->prepare("
            SELECT id, date, time_in, time_out 
            FROM attendance 
            WHERE member_id = ? 
            ORDER BY date DESC, time_in DESC 
            LIMIT 60
        ");
        $att_stmt->execute([$member_id]);
        $rows = $att_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $ar) {
            $ar['status'] = (!empty($ar['time_out'])) ? 'Completed' : 'Present';
            $attendance[] = $ar;
        }
    } catch (Throwable $e) {
        error_log("Dashboard attendance query warning: " . $e->getMessage());
    }

    // ── QUERY 3: Payments (Safe Fallback) ──
    $payments = [];
    try {
        $payColsStmt = $pdo->query("SHOW COLUMNS FROM `payments`");
        $payCols     = array_column($payColsStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

        $refCol = in_array('reference_number', $payCols) ? 'py.reference_number' : (in_array('reference_no', $payCols) ? 'py.reference_no' : 'NULL');
        $noteCol = in_array('notes', $payCols) ? 'py.notes' : "'Membership Payment'";

        $pay_stmt = $pdo->prepare("
            SELECT COALESCE(py.created_at, py.payment_date) as payment_date, 
                   COALESCE(p.name, $noteCol, 'Membership Payment') as payment_type, 
                   COALESCE(p.name, 'Membership Plan') as membership_plan,
                   py.payment_method, py.amount, COALESCE($refCol, CONCAT('PAY-', py.id)) as reference_no, 
                   'Paid' as status,
                   py.created_at
            FROM payments py
            LEFT JOIN subscriptions s ON s.id = py.subscription_id
            LEFT JOIN membership_plans p ON p.id = s.plan_id
            WHERE py.member_id = ? 
            ORDER BY py.id DESC 
            LIMIT 15
        ");
        $pay_stmt->execute([$member_id]);
        $payments = $pay_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("Dashboard payments query warning: " . $e->getMessage());
    }

    // ── QUERY 4: Membership Plans (Active Only) ──
    $plans = [];
    try {
        $plans_stmt = $pdo->query("
            SELECT id, name, price, duration_months, duration_minutes, is_test_promo, benefits,
                   plan_category, floor_access
            FROM membership_plans 
            WHERE is_active = 1
            ORDER BY 
                CASE plan_category 
                    WHEN 'membership_fee' THEN 1 
                    WHEN 'member_pass' THEN 2 
                    WHEN 'non_member_pass' THEN 3 
                    WHEN 'test_promo' THEN 4 
                    ELSE 5 
                END, 
                price ASC
        ");
        $raw_plans = $plans_stmt ? $plans_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($raw_plans as $p) {
            $dur_min = (int)($p['duration_minutes'] ?? 0);
            $dur_mo  = (int)$p['duration_months'];
            $is_daily = ($dur_min === 1440);

            if ($is_daily) {
                $duration_label = '1 Day';
            } elseif ($dur_min > 0) {
                $duration_label = $dur_min . ' Minute' . ($dur_min > 1 ? 's' : '');
            } else {
                $duration_label = $dur_mo . ' Month' . ($dur_mo > 1 ? 's' : '');
            }

            $plans[] = [
                'id'               => (int)$p['id'],
                'name'             => $p['name'],
                'price'            => (float)$p['price'],
                'price_formatted'  => '₱' . number_format((float)$p['price'], 2),
                'duration_months'  => $dur_mo,
                'duration_minutes' => $dur_min,
                'duration_label'   => $duration_label,
                'benefits'         => $p['benefits'] ?? '',
                'is_test_promo'    => ((int)($p['is_test_promo'] ?? 0) === 1),
                'plan_category'    => $p['plan_category'] ?? 'member_pass',
                'floor_access'     => $p['floor_access'] ?? 'all',
            ];
        }
    } catch (Throwable $e) {
        error_log("Dashboard plans query warning: " . $e->getMessage());
    }

    // ── QUERY 5: Pending Renewal (Safe Fallback) ──
    $pending_renewal = null;
    try {
        $pending_stmt = $pdo->prepare("
            SELECT r.id, r.status, r.payment_method, r.reference_no, r.created_at,
                   COALESCE(p.name, 'Membership Renewal') AS plan_name, 
                   COALESCE(p.price, 0) AS plan_price 
            FROM renewal_requests r
            LEFT JOIN membership_plans p ON p.id = r.plan_id
            WHERE r.member_id = ? AND r.status = 'Pending'
            ORDER BY r.created_at DESC
            LIMIT 1
        ");
        $pending_stmt->execute([$member_id]);
        $pending_renewal = $pending_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("Dashboard pending renewal query warning: " . $e->getMessage());
    }

    // ── QUERY 6: Unread notification count (Safe Fallback) ──
    $unread_notifications = 0;
    try {
        $notif_count_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE member_id = ? AND read_status = 'Unread'
        ");
        $notif_count_stmt->execute([$member_id]);
        $unread_notifications = (int)$notif_count_stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log("Dashboard notification count warning: " . $e->getMessage());
    }

    $elapsed_ms = round((microtime(true) - $t_start) * 1000);

    echo json_encode([
        'success'              => true,
        'member'               => $member,
        'annual_membership'    => $annual_membership_data,
        'gym_access'           => $gym_access_data,
        'qr_token'             => $qr_token,
        'attendance'           => $attendance,
        'payments'             => $payments,
        'plans'                => $plans,
        'pending_renewal'      => $pending_renewal,
        'unread_notifications' => $unread_notifications,
        '_perf_ms'             => $elapsed_ms
    ]);

} catch (Throwable $e) {
    error_log('API Error in member_dashboard.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Dashboard temporary server issue.']);
}
