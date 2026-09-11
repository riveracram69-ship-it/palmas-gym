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

    // ── QUERY 1: Member + Active Subscription (CRITICAL — renders the card) ──
    $stmt = $pdo->prepare("
        SELECT 
            m.id, m.membership_id, m.first_name, m.middle_name, m.last_name, m.extension,
            m.full_name, m.email, m.contact_number,
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
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
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
            SELECT date, time_in, time_out, status 
            FROM attendance 
            WHERE member_id = ? 
            ORDER BY date DESC, time_in DESC 
            LIMIT 60
        ");
        $att_stmt->execute([$member_id]);
        $attendance = $att_stmt->fetchAll(PDO::FETCH_ASSOC);
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
            SELECT py.payment_date, COALESCE($noteCol, 'Membership Payment') as payment_type, 
                   py.payment_method, py.amount, COALESCE($refCol, CONCAT('PAY-', py.id)) as reference_no, 
                   'Paid' as status
            FROM payments py
            WHERE py.member_id = ? 
            ORDER BY py.payment_date DESC, py.id DESC 
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
            SELECT id, name, price, duration_months, benefits 
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
