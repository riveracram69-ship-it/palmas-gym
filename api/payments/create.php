<?php
/**
 * api/payments/create.php
 * REST Endpoint: POST /api/payments/create
 * Creates a pending payment transaction and initializes a PayMongo checkout session.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../cors.php';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($requestMethod !== 'POST' && $requestMethod !== 'CLI') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed', 'message' => 'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/payment.php';
require_once __DIR__ . '/../../config/paymongo.php';
require_once __DIR__ . '/../../config/rate_limiter.php';

// ── 1. AUTHENTICATE USER (Session or Bearer Token) ──────────────────────────
$member_id = null;
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['member_id'])) {
    $member_id = (int)$_SESSION['member_id'];
} else {
    // Check Authorization Bearer Header
    $headers = function_exists('apache_request_headers')
        ? apache_request_headers()
        : (function_exists('getallheaders') ? getallheaders() : []);

    $authHeader = $headers['Authorization']
        ?? $headers['authorization']
        ?? $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? null;

    if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', trim($authHeader), $matches)) {
        try {
            $tokenStmt = $pdo->prepare("SELECT member_id FROM auth_tokens WHERE token = ? AND expires_at > NOW() LIMIT 1");
            $tokenStmt->execute([$matches[1]]);
            $member_id = (int)$tokenStmt->fetchColumn();
        } catch (Exception $e) {}
    }
}

if (!$member_id) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Unauthorized. Please sign in to proceed with payment.',
        'message' => 'Unauthorized. Please sign in to proceed with payment.'
    ]);
    exit;
}

// ── 2. RATE LIMITING ───────────────────────────────────────────────────────
$rate_check = check_rate_limit($pdo, 'member_' . $member_id, 'payment_create');
if (!$rate_check['allowed']) {
    http_response_code(429);
    echo json_encode([
        'success'      => false,
        'rate_limited' => true,
        'message'      => $rate_check['message']
    ]);
    exit;
}

// ── 3. EXTRACT & VALIDATE REQUEST PAYLOAD ──────────────────────────────────
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

$plan_id        = intval($data['plan_id'] ?? 0);
$payment_method = trim($data['payment_method'] ?? 'all'); // 'all', 'GCash', 'Maya', 'Card'

if ($plan_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please select a valid membership plan.']);
    exit;
}

try {
    // Fetch Member Details
    $m_stmt = $pdo->prepare("SELECT id, full_name, email, contact_number, membership_id, account_status, status FROM members WHERE id = ?");
    $m_stmt->execute([$member_id]);
    $member = $m_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Member record not found.']);
        exit;
    }

    if (($member['account_status'] ?? '') === 'Suspended' || ($member['status'] ?? '') === 'Suspended') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Account is suspended. Please visit the gym front desk.']);
        exit;
    }

    // ── 4. FETCH OFFICIAL PRICE FROM DATABASE (NEVER TRUST CLIENT AMOUNT) ──
    $p_stmt = $pdo->prepare("SELECT id, name, duration_months, duration_minutes, price, is_test_promo FROM membership_plans WHERE id = ?");
    $p_stmt->execute([$plan_id]);
    $plan = $p_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Selected membership plan was not found.']);
        exit;
    }

    $amount = floatval($plan['price']);
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid plan pricing in system configuration.']);
        exit;
    }

    $payment_mode = get_payment_mode();
    $is_test = ($payment_mode === 'test' || $payment_mode === 'demo' || (int)($plan['is_test_promo'] ?? 0) === 1) ? 1 : 0;

    // ── 5. IDEMPOTENCY / RECENT PENDING TRANSACTION CHECK (45s window) ──────
    $dup_stmt = $pdo->prepare("
        SELECT id, reference_code, checkout_url, gateway_transaction_id, paymongo_checkout_id, created_at 
        FROM payment_transactions 
        WHERE member_id = ? AND plan_id = ? AND status = 'PENDING' AND created_at >= DATE_SUB(NOW(), INTERVAL 45 SECOND)
        ORDER BY id DESC LIMIT 1
    ");
    $dup_stmt->execute([$member_id, $plan_id]);
    $recent_tx = $dup_stmt->fetch(PDO::FETCH_ASSOC);

    if ($recent_tx && !empty($recent_tx['checkout_url'])) {
        echo json_encode([
            'success'        => true,
            'is_duplicate'   => true,
            'payment_id'     => (int)$recent_tx['id'],
            'reference_code' => $recent_tx['reference_code'],
            'checkout_url'   => $recent_tx['checkout_url'],
            'status'         => 'pending',
            'mode'           => $payment_mode,
            'plan_name'      => $plan['name'],
            'amount'         => $amount
        ]);
        exit;
    }

    // ── 6. GENERATE UNIQUE REFERENCE CODE ──────────────────────────────────
    $date_part = date('Ymd');
    $rand_part = strtoupper(bin2hex(random_bytes(3)));
    $ref_code  = "PEG-{$date_part}-{$rand_part}";

    // Base Application URL
    $app_url = defined('APP_URL') ? rtrim(APP_URL, '/') : 'http://localhost/gggym/gym';
    $success_url = "{$app_url}/api/check_status.php?ref={$ref_code}&status=success";
    $cancel_url  = "{$app_url}/api/check_status.php?ref={$ref_code}&status=cancelled";

    $gateway_name  = 'PayMongo';
    $gateway_tx_id = null;
    $checkout_url  = null;

    // ── 7. CREATE PAYMONGO CHECKOUT SESSION (TEST OR LIVE) ─────────────────
    if (PayMongoGateway::isConfigured()) {
        $desc = "Palma's Elite Gym - {$plan['name']}" . ($is_test ? ' [TEST MODE]' : '');
        $gatewayResult = PayMongoGateway::createCheckoutSession([
            'amount'         => $amount,
            'currency'       => 'PHP',
            'plan_name'      => $plan['name'],
            'description'    => $desc,
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
                'reference_code' => $ref_code,
                'is_test'        => $is_test,
                'payment_mode'   => $payment_mode
            ]
        ]);

        if ($gatewayResult['success']) {
            $gateway_tx_id = $gatewayResult['session_id'];
            $checkout_url  = $gatewayResult['checkout_url'];
            $gateway_name  = ($payment_mode === 'test') ? 'PayMongo Sandbox' : 'PayMongo Live';
        } else {
            if ($payment_mode === 'live') {
                http_response_code(502);
                echo json_encode([
                    'success' => false,
                    'message' => 'PayMongo Gateway Error: ' . ($gatewayResult['message'] ?? 'Unable to initialize checkout session.')
                ]);
                exit;
            }
            // In test/demo mode, if PayMongo network fails or sandbox key is placeholder, use test simulator
            error_log("PayMongo Sandbox notice: " . ($gatewayResult['message'] ?? 'Using local test simulator fallback'));
        }
    }

    // Sandbox / Test Simulator fallback
    if (empty($checkout_url)) {
        $gateway_name  = ($payment_mode === 'test') ? 'PayMongo Sandbox' : 'Demo Simulator';
        $gateway_tx_id = 'cs_test_' . bin2hex(random_bytes(8));
        $checkout_url  = "{$app_url}/api/demo_checkout.php?ref={$ref_code}";
    }

    // ── 8. INSERT PENDING TRANSACTION RECORD ──────────────────────────────
    $tx_stmt = $pdo->prepare("
        INSERT INTO payment_transactions 
        (member_id, plan_id, reference_code, gateway_transaction_id, paymongo_checkout_id, gateway, checkout_url, payment_method, amount, currency, status, is_test, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PHP', 'PENDING', ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
    ");
    $tx_stmt->execute([
        $member_id,
        $plan_id,
        $ref_code,
        $gateway_tx_id,
        $gateway_tx_id,
        $gateway_name,
        $checkout_url,
        strtoupper($payment_method === 'all' ? 'GCASH' : $payment_method),
        $amount,
        $is_test
    ]);
    $payment_tx_id = (int)$pdo->lastInsertId();

    // ── 9. AUDIT LOGGING ──────────────────────────────────────────────────
    log_payment_audit($pdo, [
        'event_type'             => 'PAYMENT_CREATED',
        'user_id'                => $member_id,
        'payment_id'             => $payment_tx_id,
        'reference_code'         => $ref_code,
        'paymongo_transaction_id'=> $gateway_tx_id,
        'previous_status'        => 'NONE',
        'new_status'             => 'PENDING',
        'amount'                 => $amount,
        'result'                 => 'SUCCESS'
    ]);

    // ── 10. RETURN FRONTEND RESPONSE ──────────────────────────────────────
    http_response_code(201);
    echo json_encode([
        'success'        => true,
        'payment_id'     => $payment_tx_id,
        'reference_code' => $ref_code,
        'checkout_url'   => $checkout_url,
        'status'         => 'pending',
        'mode'           => $payment_mode,
        'is_test'        => (bool)$is_test,
        'plan' => [
            'id'    => (int)$plan['id'],
            'name'  => $plan['name'],
            'price' => $amount
        ]
    ]);

} catch (Throwable $e) {
    error_log("Error in /api/payments/create: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error while initializing payment.']);
}
