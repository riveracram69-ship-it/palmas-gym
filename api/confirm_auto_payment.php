<?php
/**
 * api/confirm_auto_payment.php
 * ─────────────────────────────────────────────────────────────────
 * Processes instant membership activation after payment.
 *
 * PAYMENT_MODE=demo  → Activates immediately (for testing/demo).
 * PAYMENT_MODE=live  → Requires payment_transactions.status = 'PAID'
 *                       set by a real payment gateway webhook before activating.
 *
 * IMPORTANT: In live mode, this endpoint does NOT activate the membership.
 * The actual activation happens in api/payment_webhook.php after the
 * gateway (e.g. PayMongo) sends a server-to-server payment confirmation.
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
require_once __DIR__ . '/../config/payment.php';
require_once __DIR__ . '/auth_middleware.php';

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

$member_id      = $auth_member_id;
$plan_id        = intval($data['plan_id'] ?? 0);
$payment_method = trim($data['payment_method'] ?? 'GCash');
$ref_code       = trim($data['ref_code'] ?? '');

if ($plan_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid plan selection.']);
    exit;
}

// Normalise payment method
$allowed_methods = ['CASH', 'GCASH', 'MAYA', 'QRPH', 'BANK_TRANSFER', 'CREDIT_CARD', 'Cash', 'GCash', 'Maya', 'QR Ph', 'Instant GCash', 'Instant Maya'];
if (!in_array($payment_method, $allowed_methods, true)) {
    $payment_method = 'GCash';
}

// Generate reference code if not supplied
if (empty($ref_code)) {
    $ref_code = 'PEG-' . strtoupper(substr($payment_method, 0, 2)) . '-' . strtoupper(bin2hex(random_bytes(4)));
}

// ── PAYMENT MODE CHECK ────────────────────────────────────────────────────────
$payment_mode = strtolower(defined('PAYMENT_MODE') ? PAYMENT_MODE : 'demo');

if ($payment_mode === 'live') {
    /**
     * LIVE MODE: The app requested instant activation, but in live mode
     * membership only activates after the gateway calls our webhook.
     *
     * Return a pending response — the mobile app should poll or wait
     * for a push notification confirming the payment was received.
     */
    echo json_encode([
        'success'      => false,
        'pending'      => true,
        'message'      => 'Payment is being processed. Your membership will activate automatically once payment is confirmed by the payment gateway.',
        'instructions' => 'Complete your payment in GCash/Maya. Your membership will activate within a few minutes after payment confirmation.'
    ]);
    exit;
}

// ── DEMO MODE: Instant Activation ─────────────────────────────────────────────
// Fetch official plan price from DB (never trust client-supplied amount)
$plan_stmt = $pdo->prepare("SELECT id, name, price FROM membership_plans WHERE id = ?");
$plan_stmt->execute([$plan_id]);
$plan = $plan_stmt->fetch(PDO::FETCH_ASSOC);

if (!$plan) {
    echo json_encode(['success' => false, 'message' => 'Plan not found.']);
    exit;
}

$amount = floatval($plan['price']);

// Process instant demo activation
$result = process_automated_subscription_activation($pdo, $member_id, $plan_id, $amount, $payment_method, $ref_code);

// Append demo mode notice to the success response
if ($result['success'] ?? false) {
    $result['demo_mode'] = true;
    $result['note'] = 'Demo mode: activation was instant. In production, set PAYMENT_MODE=live in .env for real gateway verification.';
}

echo json_encode($result);
