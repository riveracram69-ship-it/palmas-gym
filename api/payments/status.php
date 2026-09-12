<?php
/**
 * api/payments/status.php
 * REST Endpoint: GET /api/payments/status?ref=... or GET /api/payments/status?id=...
 * 
 * Verifies real-time payment status with PayMongo API fallback verification.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../cors.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/payment.php';
require_once __DIR__ . '/../../config/paymongo.php';

$ref_code = trim($_GET['ref'] ?? $_GET['reference'] ?? '');
$tx_id    = intval($_GET['id'] ?? 0);

if (empty($ref_code) && $tx_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Missing transaction identifier (ref or id).',
        'message' => 'Missing transaction identifier (ref or id).'
    ]);
    exit;
}

try {
    if (!empty($ref_code)) {
        $stmt = $pdo->prepare("
            SELECT t.*, p.name as plan_name, p.duration_months, p.duration_minutes,
                   m.full_name as member_name, m.membership_id as member_code, m.email as member_email
            FROM payment_transactions t
            JOIN membership_plans p ON p.id = t.plan_id
            JOIN members m ON m.id = t.member_id
            WHERE t.reference_code = ?
            LIMIT 1
        ");
        $stmt->execute([$ref_code]);
    } else {
        $stmt = $pdo->prepare("
            SELECT t.*, p.name as plan_name, p.duration_months, p.duration_minutes,
                   m.full_name as member_name, m.membership_id as member_code, m.email as member_email
            FROM payment_transactions t
            JOIN membership_plans p ON p.id = t.plan_id
            JOIN members m ON m.id = t.member_id
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->execute([$tx_id]);
    }

    $tx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tx) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment transaction not found.']);
        exit;
    }

    // ── FALLBACK ON-DEMAND API VERIFICATION IF STILL PENDING ────────────────
    // If webhook was delayed or blocked, check PayMongo API directly
    if ($tx['status'] === 'PENDING' && !empty($tx['gateway_transaction_id']) && PayMongoGateway::isConfigured()) {
        $checkoutSession = PayMongoGateway::getCheckoutSession($tx['gateway_transaction_id']);
        
        if ($checkoutSession && isset($checkoutSession['attributes']['payments'][0])) {
            $pmPayment = $checkoutSession['attributes']['payments'][0];
            $pmStatus  = $pmPayment['attributes']['status'] ?? '';

            if ($pmStatus === 'paid') {
                $pdo->beginTransaction();

                // Row lock check
                $lockStmt = $pdo->prepare("SELECT status FROM payment_transactions WHERE id = ? FOR UPDATE");
                $lockStmt->execute([$tx['id']]);
                $currentStatus = $lockStmt->fetchColumn();

                if ($currentStatus !== 'PAID') {
                    $activation = process_automated_subscription_activation(
                        $pdo,
                        (int)$tx['member_id'],
                        (int)$tx['plan_id'],
                        (float)$tx['amount'],
                        $tx['payment_method'],
                        $tx['reference_code'],
                        true
                    );

                    if ($activation['success']) {
                        $paymongoPaymentId = $pmPayment['id'] ?? null;
                        $pdo->prepare("
                            UPDATE payment_transactions 
                            SET status = 'PAID', paid_at = NOW(), paymongo_payment_id = ?, subscription_id = ? 
                            WHERE id = ?
                        ")->execute([$paymongoPaymentId, $activation['subscription_id'], $tx['id']]);

                        $pdo->commit();

                        log_payment_audit($pdo, [
                            'event_type'     => 'PAYMENT_SUCCEEDED',
                            'user_id'        => $tx['member_id'],
                            'payment_id'     => $tx['id'],
                            'reference_code' => $tx['reference_code'],
                            'new_status'     => 'PAID',
                            'amount'         => (float)$tx['amount'],
                            'result'         => 'VERIFIED_VIA_FALLBACK_API'
                        ]);

                        $tx['status']  = 'PAID';
                        $tx['paid_at'] = date('Y-m-d H:i:s');
                    } else {
                        $pdo->rollBack();
                    }
                } else {
                    $pdo->commit();
                    $tx['status'] = 'PAID';
                }
            }
        }
    }

    echo json_encode([
        'success'        => true,
        'payment_id'     => (int)$tx['id'],
        'reference_code' => $tx['reference_code'],
        'status'         => strtolower($tx['status']),
        'amount'         => (float)$tx['amount'],
        'currency'       => $tx['currency'],
        'payment_method' => $tx['payment_method'],
        'paid_at'        => $tx['paid_at'],
        'created_at'     => $tx['created_at'],
        'is_test'        => (bool)$tx['is_test'],
        'failure_reason' => $tx['failure_reason'],
        'plan' => [
            'id'       => (int)$tx['plan_id'],
            'name'     => $tx['plan_name'],
            'duration' => (!empty($tx['duration_minutes'])) ? $tx['duration_minutes'] . ' mins' : $tx['duration_months'] . ' mos'
        ],
        'member' => [
            'id'            => (int)$tx['member_id'],
            'name'          => $tx['member_name'],
            'membership_id' => $tx['member_code'],
            'email'         => $tx['member_email']
        ]
    ]);

} catch (Throwable $e) {
    error_log("Error in /api/payments/status: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error checking payment status.']);
}
