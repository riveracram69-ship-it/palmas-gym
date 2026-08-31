<?php
/**
 * api/member_dashboard.php — Performance-Optimized v2
 *
 * Strategy: Split into CRITICAL (instant) + LAZY (background) data.
 *
 * CRITICAL (returned immediately):
 *   - Member name, membership_id, expiry, plan, status, QR token
 *   → 1 query, returns in ~200ms
 *
 * LAZY (returned in same response but queried last):
 *   - Attendance (15 rows)
 *   - Payments (15 rows)
 *   - Plans
 *   - Pending renewal
 *   → 4 queries, all indexed
 *
 * The mobile app can render the membership card immediately using
 * the critical data, while the tabs populate from lazy data.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth_middleware.php';

$member_id = $auth_member_id;
$t_start   = microtime(true);

try {
    // ── QUERY 1: Member + Active Subscription (CRITICAL — renders the card) ──
    // Single query with JOIN instead of 2 correlated subqueries
    $stmt = $pdo->prepare("
        SELECT 
            m.id, m.membership_id, m.full_name, m.email, m.contact_number,
            m.photo, m.google_picture, m.auth_provider, m.status,
            m.account_status,
            s.expiry_date,
            p.name  AS plan_name,
            p.id    AS plan_id
        FROM members m
        LEFT JOIN subscriptions s 
            ON s.member_id = m.id 
            AND s.expiry_date >= CURDATE()
        LEFT JOIN membership_plans p ON p.id = s.plan_id
        WHERE m.id = ?
        ORDER BY s.expiry_date DESC
        LIMIT 1
    ");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }

    // Use Google picture if available, fallback to uploaded photo
    $member['photo'] = $member['google_picture'] ?: $member['photo'];

    // ── DYNAMIC HMAC ROTATING QR TOKEN (15-second window) ──
    $time_slot  = floor(time() / 15);
    $secret_key = QR_SECRET_KEY;
    $signature  = hash_hmac('sha256', $member['membership_id'] . '|' . $time_slot, $secret_key);
    $qr_token   = $member['membership_id'] . ':' . $time_slot . ':' . substr($signature, 0, 16);

    // ── QUERY 2: Attendance (LAZY — last 15, indexed on member_id + date) ──
    $att_stmt = $pdo->prepare("
        SELECT date, time_in, time_out, status 
        FROM attendance 
        WHERE member_id = ? 
        ORDER BY date DESC, time_in DESC 
        LIMIT 15
    ");
    $att_stmt->execute([$member_id]);
    $attendance = $att_stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── QUERY 3: Payments (LAZY — last 15, indexed on member_id) ──
    $pay_stmt = $pdo->prepare("
        SELECT payment_date, payment_type, payment_method, amount, reference_no, status
        FROM payments 
        WHERE member_id = ? 
        ORDER BY payment_date DESC, id DESC 
        LIMIT 15
    ");
    $pay_stmt->execute([$member_id]);
    $payments = $pay_stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── QUERY 4: Membership Plans (LAZY — small table, cached well by MySQL) ──
    $plans_stmt = $pdo->query("
        SELECT id, name, price, duration_months, benefits 
        FROM membership_plans 
        WHERE is_active = 1 OR is_active IS NULL
        ORDER BY price ASC
    ");
    $plans = $plans_stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── QUERY 5: Pending Renewal (LAZY) ──
    $pending_stmt = $pdo->prepare("
        SELECT r.id, r.status, p.name AS plan_name, p.price AS plan_price 
        FROM renewal_requests r
        LEFT JOIN membership_plans p ON p.id = r.plan_id
        WHERE r.member_id = ? AND r.status = 'Pending'
        ORDER BY r.created_at DESC
        LIMIT 1
    ");
    $pending_stmt->execute([$member_id]);
    $pending_renewal = $pending_stmt->fetch(PDO::FETCH_ASSOC);

    // ── QUERY 6: Unread notification count (for bell badge) ──
    $notif_count_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM notifications 
        WHERE member_id = ? AND read_status = 'Unread'
    ");
    $notif_count_stmt->execute([$member_id]);
    $unread_notifications = (int)$notif_count_stmt->fetchColumn();

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
        '_perf_ms'             => $elapsed_ms, // diagnostic — remove in production
    ]);

} catch (Exception $e) {
    error_log('API Error in member_dashboard.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An internal server error occurred.']);
}
