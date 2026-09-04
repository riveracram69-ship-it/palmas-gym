<?php
/**
 * api/check_status.php
 * Authenticated Real-Time Payment Status & Verification API
 * 
 * Securely verifies payment status against the database and on-demand payment gateway API.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/payment.php';
require_once __DIR__ . '/../config/paymongo.php';
require_once __DIR__ . '/auth_middleware.php';

$ref = trim($_GET['ref'] ?? $_GET['reference'] ?? '');

if (empty($ref)) {
    echo json_encode(['success' => false, 'message' => 'Missing transaction reference code.']);
    exit;
}

try {
    // 1. Fetch transaction and verify ownership
    $stmt = $pdo->prepare("
        SELECT 
            t.id, t.member_id, t.plan_id, t.subscription_id, t.reference_code,
            t.gateway_transaction_id, t.gateway, t.payment_method, t.amount,
            t.currency, t.status, t.created_at, t.paid_at, t.expires_at,
            p.name AS plan_name, p.duration_months,
            s.expiry_date AS subscription_expiry
        FROM payment_transactions t
        JOIN membership_plans p ON p.id = t.plan_id
        LEFT JOIN subscriptions s ON s.id = t.subscription_id
        WHERE t.reference_code = ? AND t.member_id = ?
        LIMIT 1
    ");
    $stmt->execute([$ref, $auth_member_id]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tx) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Transaction not found or unauthorized access.'
        ]);
        exit;
    }

    // 2. Check if expired
    if ($tx['status'] === 'PENDING' && !empty($tx['expires_at'])) {
        if (strtotime($tx['expires_at']) < time()) {
            $tx['status'] = 'EXPIRED';
            $pdo->prepare("UPDATE payment_transactions SET status = 'EXPIRED' WHERE id = ?")->execute([$tx['id']]);
        }
    }

    // 3. Live On-Demand Gateway Verification (for PENDING transactions)
    if ($tx['status'] === 'PENDING' && !empty($tx['gateway_transaction_id'])) {
        $paymentMode = strtolower(defined('PAYMENT_MODE') ? PAYMENT_MODE : 'demo');
        
        if ($paymentMode === 'live' || PayMongoGateway::isConfigured()) {
            $session = PayMongoGateway::getCheckoutSession($tx['gateway_transaction_id']);
            
            if ($session && isset($session['attributes']['status'])) {
                $sessionStatus = $session['attributes']['status'];
                
                // If PayMongo confirms payment is completed
                if ($sessionStatus === 'paid' || !empty($session['attributes']['payments'])) {
                    $hasPaidPayment = false;
                    foreach ($session['attributes']['payments'] ?? [] as $payItem) {
                        if (($payItem['attributes']['status'] ?? '') === 'paid') {
                            $hasPaidPayment = true;
                            break;
                        }
                    }

                    if ($hasPaidPayment || $sessionStatus === 'paid') {
                        // Trigger idempotent activation
                        $pdo->beginTransaction();
                        $lockStmt = $pdo->prepare("SELECT status FROM payment_transactions WHERE id = ? FOR UPDATE");
                        $lockStmt->execute([$tx['id']]);
                        $currentStatus = $lockStmt->fetchColumn();

                        if ($currentStatus !== 'PAID') {
                            $act = process_automated_subscription_activation(
                                $pdo,
                                (int)$tx['member_id'],
                                (int)$tx['plan_id'],
                                (float)$tx['amount'],
                                $tx['payment_method'],
                                $tx['reference_code']
                            );

                            if ($act['success']) {
                                $pdo->prepare("UPDATE payment_transactions SET status = 'PAID', paid_at = NOW() WHERE id = ?")
                                    ->execute([$tx['id']]);
                                $pdo->commit();
                                $tx['status']  = 'PAID';
                                $tx['paid_at'] = date('Y-m-d H:i:s');
                                $tx['subscription_expiry'] = $act['expiry_date'] ?? null;
                            } else {
                                $pdo->rollBack();
                            }
                        } else {
                            $pdo->commit();
                            $tx['status'] = 'PAID';
                        }
                    }
                } elseif ($sessionStatus === 'cancelled' || $sessionStatus === 'expired') {
                    $newStatus = strtoupper($sessionStatus);
                    $pdo->prepare("UPDATE payment_transactions SET status = ? WHERE id = ?")->execute([$newStatus, $tx['id']]);
                    $tx['status'] = $newStatus;
                }
            }
        }
    }

    // 4. Fetch latest subscription expiry if active
    $validUntil = $tx['subscription_expiry'];
    if (empty($validUntil)) {
        $subStmt = $pdo->prepare("SELECT expiry_date FROM subscriptions WHERE member_id = ? AND expiry_date >= CURDATE() ORDER BY expiry_date DESC LIMIT 1");
        $subStmt->execute([$auth_member_id]);
        $validUntil = $subStmt->fetchColumn() ?: null;
    }

    // 5. Response formatting
    echo json_encode([
        'success'          => true,
        'status'           => $tx['status'],
        'reference_code'   => $tx['reference_code'],
        'plan_name'        => $tx['plan_name'],
        'amount'           => (float)$tx['amount'],
        'amount_formatted' => '₱' . number_format((float)$tx['amount'], 2),
        'payment_method'   => $tx['payment_method'],
        'gateway'          => $tx['gateway'],
        'valid_until'      => $validUntil ? date('F j, Y', strtotime($validUntil)) : null,
        'created_at'       => date('F j, Y, g:i A', strtotime($tx['created_at'])),
        'paid_at'          => $tx['paid_at'] ? date('F j, Y, g:i A', strtotime($tx['paid_at'])) : null,
        'is_paid'          => ($tx['status'] === 'PAID')
    ]);

} catch (Throwable $e) {
    error_log('API Error in check_status.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to check transaction status.']);
}
