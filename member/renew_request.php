<?php
/**
 * member/renew_request.php
 * Handles member renewals with support for Instant Auto-Activation (GCash/Maya/QR Ph)
 * and traditional Cash front-desk verification.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/payment.php';
require_member_login();

header('Content-Type: application/json');

$member = current_member($pdo);
if (!$member) {
    echo json_encode(['success' => false, 'message' => 'Session expired.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$plan_id        = intval($_POST['plan_id'] ?? 0);
$payment_method = trim($_POST['payment_method'] ?? '');
$reference_no   = trim($_POST['reference_no'] ?? '');
$allowed_methods = ['Cash', 'GCash', 'Maya', 'QR Ph', 'Credit Card', 'Bank Transfer'];

if (!$plan_id || !in_array($payment_method, $allowed_methods)) {
    echo json_encode(['success' => false, 'message' => 'Please select a valid membership plan and payment method.']);
    exit;
}

try {
    // Get the plan details
    $stmt = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ?");
    $stmt->execute([$plan_id]);
    $plan = $stmt->fetch();

    if (!$plan) {
        echo json_encode(['success' => false, 'message' => 'Selected plan not found.']);
        exit;
    }

    // Instant Auto-Activation for GCash and Maya: No staff approval needed!
    if (in_array($payment_method, ['GCash', 'Maya'])) {
        if (empty($reference_no)) {
            $reference_no = 'REN-' . strtoupper(substr($payment_method, 0, 2)) . '-' . strtoupper(bin2hex(random_bytes(3)));
        }
        $actRes = process_automated_subscription_activation($pdo, $member['id'], $plan_id, $plan['price'], $payment_method, $reference_no);
        if ($actRes && !empty($actRes['success'])) {
            echo json_encode([
                'success'   => true,
                'is_active' => true,
                'message'   => 'Renewal complete! Your ' . htmlspecialchars($plan['name']) . ' pass has been instantly activated via ' . $payment_method . '.'
            ]);
            exit;
        }
    }

    // Otherwise, Traditional Cash / Front Desk pending request
    $pending_stmt = $pdo->prepare("SELECT COUNT(*) FROM renewal_requests WHERE member_id = ? AND status = 'Pending'");
    $pending_stmt->execute([$member['id']]);
    if ($pending_stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'You already have a pending renewal request under review.']);
        exit;
    }

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO renewal_requests (member_id, plan_id, payment_method, reference_no, status, created_at) VALUES (?, ?, ?, ?, 'Pending', NOW())")
        ->execute([
            $member['id'],
            $plan_id,
            $payment_method,
            $reference_no ?: null
        ]);
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Your renewal request for ' . htmlspecialchars($plan['name']) . ' has been submitted! Please settle cash payment at the front desk.',
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Error in renew_request.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An internal server error occurred.']);
}
