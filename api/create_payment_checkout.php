<?php
/**
 * api/create_payment_checkout.php
 * Creates a PENDING payment transaction and initializes official payment gateway checkout session.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/cors.php'; // [R-02 FIX] Replaced wildcard CORS with origin-allowlist

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/paymongo.php';
require_once __DIR__ . '/../config/rate_limiter.php';
require_once __DIR__ . '/../config/member_helpers.php';
require_once __DIR__ . '/auth_middleware.php';

// Self-healing schema check to ensure PayMongo columns exist
PayMongoGateway::ensureSchema($pdo);

// Rate Limiting Guard: Max checkout requests per window per member
$rate_check = check_rate_limit($pdo, 'member_' . $auth_member_id, 'payment_checkout');
if (!$rate_check['allowed']) {
    http_response_code(429);
    echo json_encode([
        'success'      => false,
        'rate_limited' => true,
        'message'      => $rate_check['message'],
        'wait_seconds' => $rate_check['wait_seconds'] ?? 60
    ]);
    exit;
}

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
    $m_stmt = $pdo->prepare("SELECT id, full_name, email, contact_number, membership_id, account_status, status, annual_membership_expiry FROM members WHERE id = ?");
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
    $p_stmt = $pdo->prepare("SELECT id, name, duration_months, duration_minutes, price, is_test_promo, is_active, plan_category FROM membership_plans WHERE id = ?");
    $p_stmt->execute([$plan_id]);
    $plan = $p_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan || (int)($plan['is_active'] ?? 0) !== 1 || ($plan['plan_category'] ?? '') === 'legacy') {
        echo json_encode(['success' => false, 'message' => 'This plan is no longer available. Please select an active plan.']);
        exit;
    }

    $amount = floatval($plan['price']);
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid plan pricing configuration.']);
        exit;
    }

    // 2.2 Validate Plan Tier Eligibility (Official Member vs Non-Member, and Annual Membership Fee rules)
    $eligibility = validate_plan_tier_eligibility($plan, $member);
    if (!$eligibility['allowed']) {
        echo json_encode(['success' => false, 'message' => $eligibility['reason']]);
        exit;
    }

    // 2.5 Enforce renewal eligibility: only permitted when expired or expiring soon
    $is_membership_fee = (($plan['plan_category'] ?? '') === 'membership_fee');

    if ($is_membership_fee) {
        // For Annual Membership Fee: allow renewal only if expired or expiring within 30 days
        if (!empty($member['annual_membership_expiry']) && strtotime($member['annual_membership_expiry']) > time()) {
            $diff_sec = strtotime($member['annual_membership_expiry']) - time();
            $diff_days = ceil($diff_sec / 86400);
            if ($diff_days > 30) {
                echo json_encode([
                    'success' => false,
                    'cannot_renew' => true,
                    'message' => "Ang iyong Annual Membership ay aktibo pa ({$diff_days} araw natitira). Maaari lamang itong i-renew kapag 30 araw o mas kaunti na lamang ang natitira bago mag-expire."
                ]);
                exit;
            }
        }
    } else {
        // For Gym Access Pass: check active gym access subscriptions ONLY (exclude membership_fee)
        $cur_sub_stmt = $pdo->prepare("
            SELECT s.id, s.expiry_date, 
                   p.name as current_plan_name, p.duration_minutes, p.duration_months, p.is_test_promo
            FROM subscriptions s
            LEFT JOIN membership_plans p ON s.plan_id = p.id
            WHERE s.member_id = ? AND (p.plan_category IS NULL OR p.plan_category != 'membership_fee')
            ORDER BY (s.expiry_date >= NOW()) DESC, s.expiry_date DESC, s.id DESC
            LIMIT 1
        ");
        $cur_sub_stmt->execute([$member_id]);
        $active_sub = $cur_sub_stmt->fetch(PDO::FETCH_ASSOC);

        $sub_has_future_expiry = ($active_sub && !empty($active_sub['expiry_date']) && strtotime($active_sub['expiry_date']) > time());

        if ($sub_has_future_expiry) {
            $expiry_ts = strtotime($active_sub['expiry_date']);
            $diff_sec = $expiry_ts - time();
            $is_sub_minute_promo = (!empty($active_sub['duration_minutes']) && $active_sub['duration_minutes'] > 0)
                || preg_match('/(\d+)\s*(?:min|minute)/i', $active_sub['current_plan_name'] ?? '');

            // Threshold: 5 minutes (300s) for promos, 3 days (259,200s) for standard plans
            $threshold_sec = $is_sub_minute_promo ? 300 : (3 * 86400);

            if ($diff_sec > $threshold_sec) {
                $rem_text = '';
                if ($is_sub_minute_promo || $diff_sec < 86400) {
                    $rem_mins = ceil($diff_sec / 60);
                    $rem_text = "{$rem_mins} minute(s)";
                } else {
                    $rem_days = ceil($diff_sec / 86400);
                    $rem_text = "{$rem_days} day(s)";
                }
                $rule_text = $is_sub_minute_promo ? 'within 5 minutes of expiration' : 'within 3 days of expiration';

                echo json_encode([
                    'success' => false,
                    'cannot_renew' => true,
                    'message' => "Hindi pa maaaring mag-renew! Aktibo pa ang iyong kasalukuyang gym pass ({$rem_text} natitira). Maaari lamang mag-renew kapag expired na o {$rule_text}."
                ]);
                exit;
            }
        }
    }

    $is_minute_promo = (!empty($plan['duration_minutes']) && (int)$plan['duration_minutes'] > 0);
    $duration_label = $is_minute_promo ? ($plan['duration_minutes'] . ' Minute(s)') : ($plan['duration_months'] . ' Month(s)');
    $is_test_promo = ((int)($plan['is_test_promo'] ?? 0) === 1);
    $payment_mode = get_payment_mode();
    $is_test = ($is_test_promo || $payment_mode === 'demo' || $payment_mode === 'test') ? 1 : 0;

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
                'duration'           => $duration_label,
                'is_test_promo'      => $is_test_promo,
                'is_test'            => $is_test,
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
    if (strpos($upper_m, 'GCASH') !== false) {
        $std_method = 'GCASH';
    } elseif (strpos($upper_m, 'CASH') !== false) {
        $std_method = 'CASH';
    } elseif (strpos($upper_m, 'MAYA') !== false) {
        $std_method = 'MAYA';
    } elseif (strpos($upper_m, 'CARD') !== false || strpos($upper_m, 'CREDIT') !== false) {
        $std_method = 'CREDIT_CARD';
    } elseif (strpos($upper_m, 'GRAB') !== false) {
        $std_method = 'CREDIT_CARD';
    }

    // 5. Generate Unique Reference Number: PEG-YYYYMMDD-XXXXX
    $date_part = date('Ymd');
    $rand_part = strtoupper(bin2hex(random_bytes(3)));
    $ref_code  = "PEG-{$date_part}-{$rand_part}";

    // Base Application URL
    $app_url = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    if (empty($app_url) || (str_contains($app_url, 'localhost') && isset($_SERVER['HTTP_HOST']) && !str_contains($_SERVER['HTTP_HOST'], 'localhost'))) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https://' : 'http://';
        $app_url = rtrim($proto . $_SERVER['HTTP_HOST'] . (str_contains($_SERVER['REQUEST_URI'] ?? '', '/gggym/gym') ? '/gggym/gym' : ''), '/');
    }
    if (empty($app_url)) {
        $app_url = 'http://localhost/gggym/gym';
    }

    // Return & Webhook URLs
    $success_url = "{$app_url}/api/check_status.php?ref={$ref_code}&status=success";
    $cancel_url  = "{$app_url}/api/check_status.php?ref={$ref_code}&status=cancelled";

    $gateway_name = 'PayMongo';
    $gateway_tx_id = null;
    $checkout_url  = null;

    // 6. Check if Live/Sandbox PayMongo Gateway is Available
    if (($payment_mode === 'live' || $payment_mode === 'test') && PayMongoGateway::isConfigured()) {
        $gatewayResult = PayMongoGateway::createCheckoutSession([
            'amount'         => $amount,
            'currency'       => 'PHP',
            'plan_name'      => $plan['name'],
            'description'    => "Palma's Elite Gym - {$plan['name']} Membership Pass" . ($is_test ? ' [TEST]' : ''),
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
                'is_test'        => $is_test
            ]
        ]);

        if ($gatewayResult['success']) {
            $gateway_tx_id = $gatewayResult['session_id'];
            $checkout_url  = $gatewayResult['checkout_url'];
            $gateway_name  = ($payment_mode === 'test') ? 'PayMongo Sandbox' : 'PayMongo Live';
        } else {
            if ($payment_mode === 'live') {
                echo json_encode([
                    'success' => false,
                    'message' => 'Payment Gateway Error: ' . $gatewayResult['message']
                ]);
                exit;
            }
        }
    }

    // Fallback to Demo Simulator when in Demo mode or keys are not yet configured
    if (empty($checkout_url)) {
        $gateway_name  = ($payment_mode === 'test') ? 'PayMongo Sandbox' : 'Demo Simulator';
        $gateway_tx_id = 'cs_demo_' . bin2hex(random_bytes(8));
        $checkout_url  = "{$app_url}/api/demo_checkout.php?ref={$ref_code}";
    }

    // 7. Insert PENDING transaction into database with is_test tag
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
        $std_method,
        $amount,
        $is_test
    ]);

    echo json_encode([
        'success' => true,
        'checkout' => [
            'ref_code'           => $ref_code,
            'checkout_url'       => $checkout_url,
            'gateway'            => $gateway_name,
            'plan_id'            => (int)$plan['id'],
            'plan_name'          => $plan['name'],
            'duration'           => $duration_label,
            'is_test_promo'      => $is_test_promo,
            'is_test'            => $is_test,
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
    $err_detail = $e->getMessage();
    echo json_encode([
        'success' => false,
        'message' => 'Unable to initialize checkout: ' . $err_detail
    ]);
}
