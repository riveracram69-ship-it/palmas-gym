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

if ($plan_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Please select a membership plan.']);
    exit;
}

try {
    // 1. Fetch Member
    $m_stmt = $pdo->prepare("SELECT id, full_name, email, membership_id FROM members WHERE id = ?");
    $m_stmt->execute([$member_id]);
    $member = $m_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }

    // 2. Fetch Plan
    $p_stmt = $pdo->prepare("SELECT id, name, duration_months, price FROM membership_plans WHERE id = ?");
    $p_stmt->execute([$plan_id]);
    $plan = $p_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        echo json_encode(['success' => false, 'message' => 'Selected plan not found.']);
        exit;
    }

    $ref_code = 'PEG-' . strtoupper(substr($payment_method, 0, 2)) . '-' . strtoupper(bin2hex(random_bytes(3))) . '-' . rand(100, 999);
    $amount = floatval($plan['price']);

    // Standardize method
    $upper_m = strtoupper($payment_method);
    $std_method = 'GCASH';
    if (strpos($upper_m, 'CASH') !== false) $std_method = 'CASH';
    elseif (strpos($upper_m, 'MAYA') !== false) $std_method = 'MAYA';
    elseif (strpos($upper_m, 'QR') !== false) $std_method = 'QRPH';
    elseif (strpos($upper_m, 'BANK') !== false) $std_method = 'BANK_TRANSFER';
    elseif (strpos($upper_m, 'CREDIT') !== false || strpos($upper_m, 'CARD') !== false) $std_method = 'CREDIT_CARD';

    // Insert into payment_transactions
    try {
        $tx_stmt = $pdo->prepare("
            INSERT INTO payment_transactions 
            (member_id, plan_id, reference_code, payment_method, amount, currency, status, expires_at)
            VALUES (?, ?, ?, ?, ?, 'PHP', 'PENDING', DATE_ADD(NOW(), INTERVAL 30 MINUTE))
        ");
        $tx_stmt->execute([$member_id, $plan_id, $ref_code, $std_method, $amount]);
    } catch (Throwable $txE) {
        error_log("payment_transactions optional insert warning: " . $txE->getMessage());
    }

    $amt_str = number_format($amount, 2, '.', '');
    $qr_ph_payload = "00020101021226580010ph.ppmi.qr0111PALMASGYM0215PEG" . ($member['membership_id'] ?? 'MEMBER') . "520459995303608540" . str_pad((string)strlen($amt_str), 2, '0', STR_PAD_LEFT) . $amt_str . "5802PH5918PALMAS ELITE GYM6006MANILA62210717" . $ref_code . "6304";

    echo json_encode([
        'success' => true,
        'checkout' => [
            'ref_code' => $ref_code,
            'plan_id' => $plan['id'],
            'plan_name' => $plan['name'],
            'duration' => $plan['duration_months'] . ' Month(s)',
            'amount' => $amount,
            'amount_formatted' => '₱' . number_format($amount, 2),
            'payment_method' => $payment_method,
            'member_name' => $member['full_name'],
            'membership_id' => $member['membership_id'],
            'qr_data' => $qr_ph_payload,
            'expires_in_minutes' => 15
        ]
    ]);

} catch (Throwable $e) {
    error_log('Error in create_payment_checkout.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Checkout error: ' . $e->getMessage()]);
}
