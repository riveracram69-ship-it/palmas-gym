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
$payment_method = trim($data['payment_method'] ?? 'GCash');
$reference_no   = trim($data['reference_no'] ?? '');

// Standardize to 3 primary payment methods
if (stripos($payment_method, 'gcash') !== false) {
    $payment_method = 'GCash';
} elseif (stripos($payment_method, 'maya') !== false) {
    $payment_method = 'Maya';
} else {
    $payment_method = 'Cash';
}

if (!$plan_id) {
    echo json_encode(['success' => false, 'message' => 'Please select a membership plan.']);
    exit;
}

// Enforce reference number for online payments
if (in_array($payment_method, ['GCash', 'Maya']) && empty($reference_no)) {
    echo json_encode(['success' => false, 'message' => "Please enter your {$payment_method} transaction Reference Number."]);
    exit;
}

try {
    // 1. Verify Member exists
    $stmt = $pdo->prepare("SELECT id, full_name, email, membership_id FROM members WHERE id = ?");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }

    // 2. Verify Plan exists & fetch official price
    $plan_stmt = $pdo->prepare("SELECT id, name, price, duration_months FROM membership_plans WHERE id = ?");
    $plan_stmt->execute([$plan_id]);
    $plan = $plan_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        echo json_encode(['success' => false, 'message' => 'Selected plan not found.']);
        exit;
    }

    // 3. Check for existing pending request (prevent duplicates)
    $pending_stmt = $pdo->prepare("SELECT id FROM renewal_requests WHERE member_id = ? AND status = 'Pending' LIMIT 1");
    $pending_stmt->execute([$member_id]);
    if ($pending_stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'You already have a pending renewal request under review by staff.']);
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
    $request_id = $pdo->lastInsertId();

    // 5. Send notification to staff
    try {
        $pdo->prepare("
            INSERT INTO notifications (member_id, type, title, message, delivery_status, read_status, sent_at)
            VALUES (?, 'Renewal', 'New Renewal Request Awaiting Staff Verification', ?, 'Sent', 'Unread', NOW())
        ")->execute([
            $member_id,
            "Member {$member['full_name']} ({$member['membership_id']}) requested renewal for {$plan['name']} (₱" . number_format($plan['price'], 2) . ") via {$payment_method}" . ($reference_no ? " | Ref: {$reference_no}" : "") . ". Pending staff approval."
        ]);
    } catch (Throwable $notifEx) {}

    echo json_encode([
        'success' => true,
        'message' => 'Renewal request for ' . htmlspecialchars($plan['name']) . ' submitted! Our staff will verify your ' . $payment_method . ' payment and activate your membership.'
    ]);

} catch (Exception $e) {
    error_log('API Error in member_renew.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An internal server error occurred.']);
}
