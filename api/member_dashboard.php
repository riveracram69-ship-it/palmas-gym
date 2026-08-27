<?php
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

try {
    // 1. Fetch Member details & active plan status
    $stmt = $pdo->prepare("
        SELECT m.id, m.membership_id, m.full_name, m.email, m.contact_number, m.photo, m.status,
               (SELECT s.expiry_date 
                FROM subscriptions s 
                WHERE s.member_id = m.id AND s.expiry_date >= CURDATE()
                ORDER BY s.expiry_date DESC, s.id DESC 
                LIMIT 1) as expiry_date,
               (SELECT p.name 
                FROM subscriptions s 
                LEFT JOIN membership_plans p ON p.id = s.plan_id 
                WHERE s.member_id = m.id AND s.expiry_date >= CURDATE()
                ORDER BY s.expiry_date DESC, s.id DESC 
                LIMIT 1) as plan_name
        FROM members m 
        WHERE m.id = ?
    ");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }

    // Dynamic HMAC Rotating QR Token (Same algorithm as member/get_qr_token.php)
    $time_slot = floor(time() / 15);
    $secret_key = QR_SECRET_KEY;
    $signature = hash_hmac('sha256', $member['membership_id'] . '|' . $time_slot, $secret_key);
    $qr_token = $member['membership_id'] . ':' . $time_slot . ':' . substr($signature, 0, 16);

    // 2. Attendance history (Recent 15)
    $att_stmt = $pdo->prepare("SELECT * FROM attendance WHERE member_id = ? ORDER BY date DESC, time_in DESC LIMIT 15");
    $att_stmt->execute([$member_id]);
    $attendance = $att_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Payment history (Recent 15)
    $pay_stmt = $pdo->prepare("SELECT * FROM payments WHERE member_id = ? ORDER BY payment_date DESC, id DESC LIMIT 15");
    $pay_stmt->execute([$member_id]);
    $payments = $pay_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Available Membership Plans
    $plans_stmt = $pdo->query("SELECT id, name, price, duration_months, benefits FROM membership_plans ORDER BY price ASC");
    $plans = $plans_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Check if there is a pending renewal request
    $pending_stmt = $pdo->prepare("
        SELECT r.*, p.name as plan_name, p.price as plan_price 
        FROM renewal_requests r
        LEFT JOIN membership_plans p ON p.id = r.plan_id
        WHERE r.member_id = ? AND r.status = 'Pending'
        ORDER BY r.created_at DESC
        LIMIT 1
    ");
    $pending_stmt->execute([$member_id]);
    $pending_renewal = $pending_stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'member' => $member,
        'qr_token' => $qr_token,
        'attendance' => $attendance,
        'payments' => $payments,
        'plans' => $plans,
        'pending_renewal' => $pending_renewal
    ]);

} catch (Exception $e) {
    error_log('API Error in member_dashboard.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An internal server error occurred.']);
}
