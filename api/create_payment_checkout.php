<?php
/**
 * api/create_payment_checkout.php
 * Creates a PENDING payment transaction and initializes official payment gateway checkout session.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/paymongo.php';
require_once __DIR__ . '/auth_middleware.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

$member_id      = $auth_member_id;
$plan_id        = intval($data['plan_id'] ?? 0);
$payment_method = trim($data['payment_method'] ?? 'GCash');

if ($plan_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Please select a valid membership plan.']);
    exit;
}

try {
    // 1. Fetch Member
    $m_stmt = $pdo->prepare("SELECT id, full_name, email, contact_number, membership_id, account_status, status FROM members WHERE id = ?");
    $m_stmt->execute([$member_id]);
    $member = $m_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        echo json_encode(['success' => false, 'message' => 'Member record not found.']);
        exit;
    }

    if (($member['account_status'] ?? '') === 'Suspended' || ($member['status'] ?? '') === 'Suspended') {
        echo json_encode(['success' => false, 'message' => 'Account is suspended. Please visit the front desk.']);
        exit;
    }

    // 2. Fetch Plan & Secure Server-Side Pricing
    $p_stmt = $pdo->prepare("SELECT id, name, duration_months, price FROM membership_plans WHERE id = ?");
    $p_stmt->execute([$plan_id]);
    $plan = $p_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        echo json_encode(['success' => false, 'message' => 'Selected membership plan was not found.']);
        exit;
    }

    $amount = floatval($plan['price']);
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid plan pricing configuration.']);
        exit;
    }

    // 3. Prevent rapid duplicate checkout requests (idempotency check within 45s)
    $dup_stmt = $pdo->prepare("
        SELECT id, reference_code, checkout_url, created_at 
        FROM payment_transactions 
        WHERE member_id = ? AND plan_id = ? AND status = 'PENDING' AND created_at >= DATE_SUB(NOW(), INTERVAL 45 SECOND)
        ORDER BY id DESC LIMIT 1
    ");
    $dup_stmt->execute([$member_id, $plan_id]);
    $recent_tx = $dup_stmt->fetch(PDO::FETCH_ASSOC);

    if ($recent_tx && !empty($recent_tx['checkout_url'])) {
        echo json_encode([
            'success' => true,
            'is_duplicate' => true,
            'checkout' => [
                'ref_code'           => $recent_tx['reference_code'],
                'checkout_url'       => $recent_tx['checkout_url'],
                'plan_id'            => (int)$plan['id'],
                'plan_name'          => $plan['name'],
                'duration'           => $plan['duration_months'] . ' Month(s)',
                'amount'             => $amount,
                'amount_formatted'   => '₱' . number_format($amount, 2),
                'payment_method'     => $payment_method,
                'member_name'        => $member['full_name'],
                'membership_id'      => $member['membership_id'],
                'expires_in_minutes' => 30
            ]
        ]);
        exit;
    }

    // 4. Standardize Payment Method
    $upper_m = strtoupper($payment_method);
    $std_method = 'GCASH';
    if (strpos($upper_m, 'CASH') !== false) {
        $std_method = 'CASH';
    } elseif (strpos($upper_m, 'MAYA') !== false) {
        $std_method = 'MAYA';
    } elseif (strpos($upper_m, 'CARD') !== false || strpos($upper_m, 'CREDIT') !== false) {
        $std_method = 'CREDIT_CARD';
    } elseif (strpos($upper_m, 'GRAB') !== false) {
        $std_method = 'GRAB_PAY';
    }

    // 5. Generate Unique Reference Number: PEG-YYYYMMDD-XXXXX
    $date_part = date('Ymd');
    $rand_part = strtoupper(bin2hex(random_bytes(3)));
    $ref_code  = "PEG-{$date_part}-{$rand_part}";

    // Base Application URL
    $app_url = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://palmas-gym-4oxn.onrender.com';

    // Return & Webhook URLs
    $success_url = "{$app_url}/api/check_status.php?ref={$ref_code}&status=success";
    $cancel_url  = "{$app_url}/api/check_status.php?ref={$ref_code}&status=cancelled";

    $gateway_name = 'PayMongo';
    $gateway_tx_id = null;
    $checkout_url  = null;

    $payment_mode = strtolower(defined('PAYMENT_MODE') ? PAYMENT_MODE : 'demo');

    // 6. Check if Live PayMongo Gateway is Available
    if ($payment_mode === 'live' || PayMongoGateway::isConfigured()) {
        $gatewayResult = PayMongoGateway::createCheckoutSession([
            'amount'         => $amount,
            'currency'       => 'PHP',
            'plan_name'      => $plan['name'],
            'description'    => "Palma's Elite Gym - {$plan['name']} Membership Pass",
            'reference_code' => $ref_code,
            'payment_method' => $payment_method,
            'member' => [
                'name'  => $member['full_name'],
                'email' => $member['email'],
                'phone' => $member['contact_number']
            ],
            'success_url'    => $success_url,
            'cancel_url'     => $cancel_url,
            'metadata' => [
                'member_id'      => $member_id,
                'plan_id'        => $plan_id,
                'membership_id'  => $member['membership_id'],
                'reference_code' => $ref_code
            ]
        ]);

        if ($gatewayResult['success']) {
            $gateway_tx_id = $gatewayResult['session_id'];
            $checkout_url  = $gatewayResult['checkout_url'];
        } else {
            // If live mode failed, report error
            if ($payment_mode === 'live') {
                echo json_encode([
                    'success' => false,
                    'message' => 'Payment Gateway Error: ' . $gatewayResult['message']
                ]);
                exit;
            }
        }
    }

    // Fallback to Demo Simulator when in Demo mode or keys are pending
    if (empty($checkout_url)) {
        $gateway_name  = 'PayMongo Sandbox';
        $gateway_tx_id = 'cs_demo_' . bin2hex(random_bytes(8));
        $checkout_url  = "{$app_url}/api/demo_checkout.php?ref={$ref_code}";
    }

    // 7. Insert PENDING transaction into database
    $tx_stmt = $pdo->prepare("
        INSERT INTO payment_transactions 
        (member_id, plan_id, reference_code, gateway_transaction_id, gateway, checkout_url, payment_method, amount, currency, status, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PHP', 'PENDING', DATE_ADD(NOW(), INTERVAL 30 MINUTE))
    ");
    $tx_stmt->execute([
        $member_id,
        $plan_id,
        $ref_code,
        $gateway_tx_id,
        $gateway_name,
        $checkout_url,
        $std_method,
        $amount
    ]);

    echo json_encode([
        'success' => true,
        'checkout' => [
            'ref_code'           => $ref_code,
            'checkout_url'       => $checkout_url,
            'gateway'            => $gateway_name,
            'plan_id'            => (int)$plan['id'],
            'plan_name'          => $plan['name'],
            'duration'           => $plan['duration_months'] . ' Month(s)',
            'amount'             => $amount,
            'amount_formatted'   => '₱' . number_format($amount, 2),
            'payment_method'     => $payment_method,
            'member_name'        => $member['full_name'],
            'membership_id'      => $member['membership_id'],
            'expires_in_minutes' => 30
        ]
    ]);

} catch (Throwable $e) {
    error_log('Error in create_payment_checkout.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Unable to initialize checkout. Please try again.'
    ]);
}
