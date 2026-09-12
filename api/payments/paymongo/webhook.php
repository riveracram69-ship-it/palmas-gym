<?php
/**
 * api/payments/paymongo/webhook.php
 * REST Endpoint: POST /api/payments/paymongo/webhook
 * 
 * Official Server-to-Server PayMongo Webhook Handler.
 * Supports Test Mode / Sandbox and Live Mode transactions.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../config/payment.php';
require_once __DIR__ . '/../../../config/paymongo.php';

// If visited in browser via GET, return friendly health status
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    http_response_code(200);
    echo json_encode([
        'status'  => 'active',
        'gateway' => 'PayMongo',
        'mode'    => function_exists('get_payment_mode') ? get_payment_mode() : 'test',
        'message' => 'PayMongo Webhook endpoint is active and listening for HTTP POST webhook events from PayMongo.',
        'timestamp' => date('c')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Only allow POST for event processing
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed', 'message' => 'Method Not Allowed']);
    exit;
}

$rawPayload = file_get_contents('php://input');
if (empty($rawPayload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Empty webhook payload']);
    exit;
}

// ── 1. SIGNATURE VERIFICATION ──────────────────────────────────────────────
$signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
$isDemoHeader    = $_SERVER['HTTP_X_DEMO_SIMULATION'] ?? '';
$paymentMode     = get_payment_mode();

$signatureValid = false;

// If a webhook secret is configured, enforce cryptographic signature check
if (!empty(defined('PAYMONGO_WEBHOOK_SECRET') ? PAYMONGO_WEBHOOK_SECRET : '')) {
    $signatureValid = PayMongoGateway::verifyWebhookSignature($rawPayload, $signatureHeader);
}

// Allow verified test/sandbox simulation header when in test or demo mode
if (!$signatureValid && in_array($paymentMode, ['test', 'demo'], true) && $isDemoHeader === 'palmas_demo_sandbox') {
    $signatureValid = true;
}

// If in live mode and signature is invalid, reject immediately
if (!$signatureValid && $paymentMode === 'live') {
    http_response_code(401);
    error_log("PayMongo Webhook: Unauthorized webhook request. Signature validation failed in LIVE mode.");
    echo json_encode(['success' => false, 'message' => 'Invalid webhook signature.']);
    exit;
}

// If in test mode with webhook secret set, reject if invalid
if (!$signatureValid && !empty(defined('PAYMONGO_WEBHOOK_SECRET') ? PAYMONGO_WEBHOOK_SECRET : '') && $isDemoHeader !== 'palmas_demo_sandbox') {
    http_response_code(401);
    error_log("PayMongo Webhook: Signature verification failed in TEST mode.");
    echo json_encode(['success' => false, 'message' => 'Webhook signature verification failed.']);
    exit;
}

// ── 2. PARSE EVENT PAYLOAD ─────────────────────────────────────────────────
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

    // Extract reference code and checkout ID
    $ref_code = $eventAttr['reference_number']
        ?? ($eventAttr['metadata']['reference_code'] ?? null)
        ?? ($payload['reference_code'] ?? null);

    $checkout_id = $eventData['attributes']['data']['id'] ?? ($eventData['id'] ?? null);

    // If not found in primary fields, inspect payments array
    if (!$ref_code && isset($eventAttr['payments'][0]['attributes']['reference_number'])) {
        $ref_code = $eventAttr['payments'][0]['attributes']['reference_number'];
    }

    if (empty($ref_code) && empty($checkout_id)) {
        error_log("PayMongo Webhook: Could not locate reference_code or checkout_id in payload: " . substr($rawPayload, 0, 300));
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Transaction identifier not found in webhook event.']);
        exit;
    }

    // ── 3. DATABASE ROW LOCKING (FOR UPDATE) ──────────────────────────────
    $pdo->beginTransaction();

    if (!empty($ref_code)) {
        $stmt = $pdo->prepare("
            SELECT id, member_id, plan_id, subscription_id, reference_code, amount, currency, status, payment_method, gateway_transaction_id
            FROM payment_transactions
            WHERE reference_code = ?
            FOR UPDATE
        ");
        $stmt->execute([$ref_code]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id, member_id, plan_id, subscription_id, reference_code, amount, currency, status, payment_method, gateway_transaction_id
            FROM payment_transactions
            WHERE gateway_transaction_id = ? OR paymongo_checkout_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$checkout_id, $checkout_id]);
    }

    $tx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tx) {
        $pdo->rollBack();
        error_log("PayMongo Webhook: Transaction not found in database: " . ($ref_code ?: $checkout_id));
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment transaction record not found.']);
        exit;
    }

    $ref_code = $tx['reference_code'];

    // ── 4. IDEMPOTENCY CHECK: ALREADY PAID? ───────────────────────────────
    if ($tx['status'] === 'PAID') {
        $pdo->commit();
        echo json_encode([
            'success'   => true,
            'message'   => 'Transaction is already marked as PAID. Duplicate event ignored safely.',
            'duplicate' => true
        ]);
        exit;
    }

    // ── 5. CHECK EVENT STATUS ─────────────────────────────────────────────
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

    // ── 6. PROCESS SUCCESSFUL PAYMENT ─────────────────────────────────────
    if ($isPaidEvent) {
        // Extract paid amount (Centavos to Pesos if coming from PayMongo)
        $paidAmount = isset($eventAttr['amount']) ? ((float)$eventAttr['amount'] / 100) : (float)$tx['amount'];
        $expectedAmount = (float)$tx['amount'];

        // Strict Server-Side Validation: Check against official database plan price
        $p_check = $pdo->prepare("SELECT price FROM membership_plans WHERE id = ?");
        $p_check->execute([$tx['plan_id']]);
        $db_plan_price = (float)$p_check->fetchColumn();

        if ((abs($paidAmount - $expectedAmount) > 0.05 || abs($paidAmount - $db_plan_price) > 0.05) && $paidAmount > 0) {
            error_log("PayMongo Webhook: Amount mismatch for ref {$ref_code}. Expected: {$db_plan_price}, Received: {$paidAmount}");
            $pdo->prepare("
                UPDATE payment_transactions 
                SET status = 'FAILED', failure_reason = ?, gateway_response = ? 
                WHERE id = ?
            ")->execute(["Amount mismatch: expected {$db_plan_price}, got {$paidAmount}", $rawPayload, $tx['id']]);
            $pdo->commit();

            log_payment_audit($pdo, [
                'event_type'      => 'PAYMENT_FAILED',
                'user_id'         => $tx['member_id'],
                'payment_id'      => $tx['id'],
                'reference_code'  => $ref_code,
                'previous_status' => $tx['status'],
                'new_status'      => 'FAILED',
                'amount'          => $paidAmount,
                'result'          => 'AMOUNT_MISMATCH'
            ]);

            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Payment amount mismatch against official database plan price.']);
            exit;
        }

        // Determine specific payment method and payment ID from webhook
        $methodLabel = $tx['payment_method'] ?? 'GCash';
        $paymongoPaymentId = null;

        if (!empty($eventAttr['payments'][0]['id'])) {
            $paymongoPaymentId = $eventAttr['payments'][0]['id'];
        }

        if (isset($eventAttr['payments'][0]['attributes']['source']['type'])) {
            $srcType = $eventAttr['payments'][0]['attributes']['source']['type'];
            if ($srcType === 'gcash') $methodLabel = 'GCash';
            elseif ($srcType === 'paymaya') $methodLabel = 'Maya';
            elseif ($srcType === 'card') $methodLabel = 'Credit Card';
            elseif ($srcType === 'grab_pay') $methodLabel = 'GrabPay';
        }

        // Execute Subscription Activation Engine within the caller-controlled transaction
        $activationResult = process_automated_subscription_activation(
            $pdo,
            (int)$tx['member_id'],
            (int)$tx['plan_id'],
            $expectedAmount,
            $methodLabel,
            $ref_code,
            true // Caller controls transaction
        );

        if (!$activationResult['success']) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("PayMongo Webhook: Subscription activation failure for ref {$ref_code}: " . ($activationResult['message'] ?? ''));
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Subscription activation failure.']);
            exit;
        }

        $subId = $activationResult['subscription_id'] ?? $tx['subscription_id'];

        // Update payment_transactions record to PAID
        $updateTx = $pdo->prepare("
            UPDATE payment_transactions 
            SET status = 'PAID', paid_at = NOW(), gateway_response = ?, subscription_id = ?, paymongo_payment_id = ?
            WHERE id = ?
        ");
        $updateTx->execute([$rawPayload, $subId, $paymongoPaymentId, $tx['id']]);

        // Commit the transaction
        $pdo->commit();

        // Audit Logging
        log_payment_audit($pdo, [
            'event_type'              => 'PAYMENT_SUCCEEDED',
            'user_id'                 => $tx['member_id'],
            'payment_id'              => $tx['id'],
            'reference_code'          => $ref_code,
            'paymongo_transaction_id' => $paymongoPaymentId ?: $tx['gateway_transaction_id'],
            'previous_status'         => $tx['status'],
            'new_status'              => 'PAID',
            'amount'                  => $expectedAmount,
            'result'                  => 'SUCCESS'
        ]);

        log_payment_audit($pdo, [
            'event_type'     => 'MEMBERSHIP_ACTIVATED',
            'user_id'        => $tx['member_id'],
            'payment_id'     => $tx['id'],
            'reference_code' => $ref_code,
            'result'         => 'ACTIVATED'
        ]);

        echo json_encode([
            'success'   => true,
            'received'  => true,
            'status'    => 'PAID',
            'reference' => $ref_code,
            'message'   => 'Payment successfully verified and membership activated.'
        ]);
        exit;

    // ── 7. PROCESS FAILED PAYMENT ─────────────────────────────────────────
    } elseif ($isFailedEvent) {
        $failReason = $eventAttr['failed_reason']
            ?? ($payload['failure_reason'] ?? 'Payment authorization failed at PayMongo gateway.');

        $pdo->prepare("
            UPDATE payment_transactions 
            SET status = 'FAILED', failure_reason = ?, gateway_response = ? 
            WHERE id = ?
        ")->execute([$failReason, $rawPayload, $tx['id']]);

        $pdo->commit();

        log_payment_audit($pdo, [
            'event_type'      => 'PAYMENT_FAILED',
            'user_id'         => $tx['member_id'],
            'payment_id'      => $tx['id'],
            'reference_code'  => $ref_code,
            'previous_status' => $tx['status'],
            'new_status'      => 'FAILED',
            'amount'          => (float)$tx['amount'],
            'result'          => $failReason
        ]);

        echo json_encode([
            'success'   => true,
            'received'  => true,
            'status'    => 'FAILED',
            'reference' => $ref_code,
            'message'   => 'Payment failure recorded.'
        ]);
        exit;

    } else {
        // Other non-completion events (e.g. source.chargeable, checkout_session.created)
        $pdo->commit();
        echo json_encode(['success' => true, 'received' => true, 'status' => 'UNHANDLED_EVENT']);
        exit;
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("PayMongo Webhook Exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error while processing webhook.']);
}
