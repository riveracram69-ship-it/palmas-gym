<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth_middleware.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) {
    $data = $_POST;
}

$member_id      = $auth_member_id;
$plan_id        = intval($data['plan_id'] ?? 0);
$payment_method = trim($data['payment_method'] ?? '');
$reference_no   = trim($data['reference_no'] ?? '');

$allowed_methods = ['Cash', 'GCash', 'Credit Card', 'Bank Transfer'];

if (!$plan_id || !in_array($payment_method, $allowed_methods)) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
    exit;
}

try {
    // 1. Verify Member exists
    $stmt = $pdo->prepare("SELECT id, full_name, email FROM members WHERE id = ?");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch();
    if (!$member) {
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }

    // 2. Verify Plan exists
    $plan_stmt = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ?");
    $plan_stmt->execute([$plan_id]);
    $plan = $plan_stmt->fetch();
    if (!$plan) {
        echo json_encode(['success' => false, 'message' => 'Selected plan not found.']);
        exit;
    }

    // 3. Check for existing pending request
    $pending_stmt = $pdo->prepare("SELECT COUNT(*) FROM renewal_requests WHERE member_id = ? AND status = 'Pending'");
    $pending_stmt->execute([$member_id]);
    if ($pending_stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'You already have a pending renewal request under review.']);
        exit;
    }

    // 4. Insert renewal request
    $insert_stmt = $pdo->prepare("
        INSERT INTO renewal_requests (member_id, plan_id, payment_method, reference_no, status, created_at) 
        VALUES (?, ?, ?, ?, 'Pending', NOW())
    ");
    $insert_stmt->execute([
        $member_id,
        $plan_id,
        $payment_method,
        $reference_no ?: null
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Your renewal request for ' . htmlspecialchars($plan['name']) . ' has been submitted! Staff will review and activate it shortly.'
    ]);

} catch (Exception $e) {
    error_log('API Error in member_renew.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An internal server error occurred.']);
}
