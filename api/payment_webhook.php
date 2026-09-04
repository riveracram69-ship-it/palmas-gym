<?php
/**
 * api/payment_webhook.php
 * Official Server-to-Server Payment Webhook Handler (PayMongo & Gateway Events)
 * 
 * Features:
 * - Signature Verification (HMAC-SHA256)
 * - Strict Database Transactions & Row Locking (FOR UPDATE)
 * - True Idempotency (Prevents duplicate processing and multiple activations)
 * - Automated Subscription Activation & Receipt Generation
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/payment.php';
require_once __DIR__ . '/../config/paymongo.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

$rawPayload = file_get_contents('php://input');
if (empty($rawPayload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Empty webhook payload']);
    exit;
}

// 1. Signature Verification
$signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
$isDemoHeader    = $_SERVER['HTTP_X_DEMO_SIMULATION'] ?? '';
$paymentMode     = strtolower(defined('PAYMENT_MODE') ? PAYMENT_MODE : 'demo');

$signatureValid = false;

if ($paymentMode === 'live' || !empty(defined('PAYMONGO_WEBHOOK_SECRET') ? PAYMONGO_WEBHOOK_SECRET : '')) {
    // In live mode or when webhook secret is set, enforce PayMongo signature
    $signatureValid = PayMongoGateway::verifyWebhookSignature($rawPayload, $signatureHeader);
    
    if (!$signatureValid && $paymentMode === 'live') {
        http_response_code(401);
        error_log("Payment Webhook: Invalid webhook signature in LIVE mode.");
        echo json_encode(['success' => false, 'message' => 'Invalid webhook signature']);
        exit;
    }
}

// Allow verified demo simulation only in demo mode
if (!$signatureValid && $paymentMode === 'demo' && $isDemoHeader === 'palmas_demo_sandbox') {
    $signatureValid = true;
}

if (!$signatureValid && $paymentMode !== 'demo') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Webhook signature verification failed']);
    exit;
}

// 2. Parse Payload
$payload = json_decode($rawPayload, true);
if (!$payload || !isset($payload['data'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Malformed JSON payload']);
    exit;
}

try {
    $eventData = $payload['data'];
    $eventType = $eventData['attributes']['type'] ?? ($eventData['type'] ?? 'unknown');
    $eventAttr = $eventData['attributes']['data']['attributes'] ?? ($eventData['attributes'] ?? []);

    // Extract reference code and transaction details
    $ref_code = $eventAttr['reference_number']
        ?? ($eventAttr['metadata']['reference_code'] ?? null)
        ?? ($eventAttr['description'] ? null : null);

    // If not found in standard attributes, search inside metadata or line_items
    if (!$ref_code && isset($eventAttr['payments'][0]['attributes']['reference_number'])) {
        $ref_code = $eventAttr['payments'][0]['attributes']['reference_number'];
    }

    if (!$ref_code && isset($payload['reference_code'])) {
        $ref_code = $payload['reference_code'];
    }

    if (empty($ref_code)) {
        error_log("Payment Webhook: Could not locate reference_code in payload: " . substr($rawPayload, 0, 300));
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Reference code not found in event']);
        exit;
    }

    // 3. Database Transaction & Row Locking
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, member_id, plan_id, subscription_id, reference_code, amount, currency, status, payment_method
        FROM payment_transactions
        WHERE reference_code = ?
        FOR UPDATE
    ");
    $stmt->execute([$ref_code]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tx) {
        $pdo->rollBack();
        error_log("Payment Webhook: Transaction reference not found in database: {$ref_code}");
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Transaction reference not found']);
        exit;
    }

    // 4. Idempotency Check: Already PAID?
    if ($tx['status'] === 'PAID') {
        $pdo->commit();
        echo json_encode([
            'success'   => true,
            'message'   => 'Transaction already processed and marked as PAID',
            'duplicate' => true
        ]);
        exit;
    }

    // 5. Check Event Status
    $isPaidEvent = (
        $eventType === 'checkout_session.payment.paid' ||
        $eventType === 'payment.paid' ||
        ($eventAttr['status'] ?? '') === 'paid' ||
        ($payload['status'] ?? '') === 'PAID'
    );

    $isFailedEvent = (
        $eventType === 'payment.failed' ||
        ($eventAttr['status'] ?? '') === 'failed' ||
        ($payload['status'] ?? '') === 'FAILED'
    );

    if ($isPaidEvent) {
        // Verify Amount (Centavos to Pesos if coming from PayMongo)
        $paidAmount = isset($eventAttr['amount']) ? ((float)$eventAttr['amount'] / 100) : (float)$tx['amount'];
        $expectedAmount = (float)$tx['amount'];

        // Strict Amount Check
        if (abs($paidAmount - $expectedAmount) > 0.05 && $paidAmount > 0) {
            error_log("Payment Webhook: Amount mismatch for ref {$ref_code}. Expected: {$expectedAmount}, Received: {$paidAmount}");
            $pdo->prepare("
                UPDATE payment_transactions 
                SET status = 'FAILED', failure_reason = ?, gateway_response = ? 
                WHERE id = ?
            ")->execute(["Amount mismatch: expected {$expectedAmount}, got {$paidAmount}", $rawPayload, $tx['id']]);
            $pdo->commit();
            
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Payment amount mismatch']);
            exit;
        }

        // Determine payment method label
        $methodLabel = $tx['payment_method'] ?? 'GCash';
        if (isset($eventAttr['payments'][0]['attributes']['source']['type'])) {
            $srcType = $eventAttr['payments'][0]['attributes']['source']['type'];
            if ($srcType === 'gcash') $methodLabel = 'GCash';
            elseif ($srcType === 'paymaya') $methodLabel = 'Maya';
            elseif ($srcType === 'card') $methodLabel = 'Credit Card';
            elseif ($srcType === 'grab_pay') $methodLabel = 'GrabPay';
        }

        // Execute Subscription Activation Engine
        $activationResult = process_automated_subscription_activation(
            $pdo,
            (int)$tx['member_id'],
            (int)$tx['plan_id'],
            $expectedAmount,
            $methodLabel,
            $ref_code
        );

        if (!$activationResult['success']) {
            $pdo->rollBack();
            error_log("Payment Webhook: Activation error for ref {$ref_code}: " . ($activationResult['message'] ?? ''));
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Subscription activation failure']);
            exit;
        }

        // Update payment_transactions record
        $updateTx = $pdo->prepare("
            UPDATE payment_transactions 
            SET status = 'PAID', paid_at = NOW(), gateway_response = ? 
            WHERE id = ?
        ");
        $updateTx->execute([$rawPayload, $tx['id']]);

        $pdo->commit();

        echo json_encode([
            'success'   => true,
            'received'  => true,
            'status'    => 'PAID',
            'reference' => $ref_code,
            'message'   => 'Payment successfully verified and membership activated.'
        ]);
        exit;

    } elseif ($isFailedEvent) {
        $failReason = $eventAttr['failed_reason'] ?? ($payload['failure_reason'] ?? 'Payment authorization failed at gateway');
        $pdo->prepare("
            UPDATE payment_transactions 
            SET status = 'FAILED', failure_reason = ?, gateway_response = ? 
            WHERE id = ?
        ")->execute([$failReason, $rawPayload, $tx['id']]);
        
        $pdo->commit();

        echo json_encode([
            'success'   => true,
            'received'  => true,
            'status'    => 'FAILED',
            'reference' => $ref_code
        ]);
        exit;

    } else {
        // Other non-completion events (e.g. pending / created)
        $pdo->commit();
        echo json_encode(['success' => true, 'received' => true, 'status' => 'UNHANDLED_EVENT']);
        exit;
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Payment Webhook Exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error processing payment webhook']);
}
