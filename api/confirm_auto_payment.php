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
require_once __DIR__ . '/../config/payment.php';
require_once __DIR__ . '/auth_middleware.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) {
    $data = $_POST;
}

$member_id      = $auth_member_id;
$plan_id        = intval($data['plan_id'] ?? 0);
$payment_method = trim($data['payment_method'] ?? 'GCash');
$ref_code       = trim($data['ref_code'] ?? ('AUTO-' . strtoupper(bin2hex(random_bytes(4)))));

if ($plan_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid plan selection.']);
    exit;
}

// Fetch Plan Price
$plan_stmt = $pdo->prepare("SELECT price FROM membership_plans WHERE id = ?");
$plan_stmt->execute([$plan_id]);
$plan = $plan_stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    echo json_encode(['success' => false, 'message' => 'Plan not found.']);
    exit;
}

$amount = floatval($plan['price']);

// Process Automated Activation
$result = process_automated_subscription_activation($pdo, $member_id, $plan_id, $amount, $payment_method, $ref_code);

echo json_encode($result);
