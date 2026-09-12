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
    require_once __DIR__ . '/auth_middleware.php';

    $member_id = $auth_member_id;
    $t_start   = microtime(true);

    // ── QUERY 1: Member + Latest Subscription (CRITICAL — renders the card) ──
    $stmt = $pdo->prepare("
        SELECT 
            m.id, m.membership_id, m.first_name, m.middle_name, m.last_name, m.extension,
            m.full_name, m.email, m.contact_number,
            m.photo, m.google_picture, m.auth_provider, m.status,
            m.account_status,
            s.id    AS subscription_id,
            s.expiry_date,
            s.start_date,
            p.name  AS plan_name,
            p.id    AS plan_id,
            p.duration_months,
            p.duration_minutes,
            p.is_test_promo
        FROM members m
        LEFT JOIN subscriptions s 
            ON s.id = (
                SELECT s2.id FROM subscriptions s2 
                WHERE s2.member_id = m.id 
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

    $now_time = time();
    $exp_ts = (!empty($member['expiry_date'])) 
        ? ((strpos($member['expiry_date'], ':') !== false) ? strtotime($member['expiry_date']) : strtotime($member['expiry_date'] . ' 23:59:59'))
        : 0;
    $is_expired = (!empty($member['expiry_date']) && $exp_ts < $now_time);
    $member['is_expired'] = $is_expired;

    // Determine renewal eligibility (can only renew if expired or expiring soon)
    $can_renew = true;
    $cannot_renew_reason = null;
    if (!empty($member['expiry_date']) && !$is_expired) {
        $diff_sec = $exp_ts - $now_time;
        $is_minute_promo = (!empty($member['duration_minutes']) && $member['duration_minutes'] > 0)
            || preg_match('/(\d+)\s*(?:min|minute)/i', $member['plan_name'] ?? '');
        $threshold_sec = $is_minute_promo ? 300 : (3 * 86400); // 5 mins for promo, 3 days for regular

        if ($diff_sec > $threshold_sec) {
            $can_renew = false;
            $rem_text = ($is_minute_promo || $diff_sec < 86400) ? ceil($diff_sec / 60) . ' min(s)' : ceil($diff_sec / 86400) . ' day(s)';
            $rule_text = $is_minute_promo ? 'within 5 minutes of expiration' : 'within 3 days of expiration';
            $cannot_renew_reason = "Your plan is still active ({$rem_text} remaining). Renewal is available when expired or {$rule_text}.";
        }
    }
    $member['can_renew'] = $can_renew;
    $member['cannot_renew_reason'] = $cannot_renew_reason;

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

    // ── QUERY 4: Membership Plans (Safe Fallback) ──
    $plans = [];
    try {
        $plans_stmt = $pdo->query("
            SELECT id, name, price, duration_months, duration_minutes, is_test_promo, benefits 
            FROM membership_plans 
            WHERE is_active = 1 OR is_active IS NULL
            ORDER BY price ASC
        ");
        $plans = $plans_stmt->fetchAll(PDO::FETCH_ASSOC);
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
