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

    // Send confirmation email to member
    if (!empty($member['email'])) {
        require_once __DIR__ . '/../config/email.php';
        $email_subject = "Renewal Request Received — Palma's Elite Gym";
        $email_title = "Renewal Request Submitted 📋";
        $email_body = "
            <p>Dear <strong>" . htmlspecialchars($member['full_name']) . "</strong>,</p>
            <p>We have received your membership renewal request for <strong>Palma's Elite Gym</strong>.</p>
            
            <div style=\"background-color:#F4F9F6; border:1px solid #D8E6DC; border-radius:10px; padding:18px; margin:20px 0;\">
                <p style=\"margin:0 0 10px; font-weight:bold; color:#1B4332; font-size:14px; text-transform:uppercase; letter-spacing:0.5px;\">Renewal Request Summary</p>
                <table style=\"width:100%; font-size:13px; color:#334155; border-collapse:collapse;\">
                    <tr><td style=\"padding:4px 0;\"><strong>Membership ID:</strong></td><td style=\"text-align:right; font-family:monospace; font-weight:bold; color:#1B4332;\">" . htmlspecialchars($member['membership_id'] ?? 'N/A') . "</td></tr>
                    <tr><td style=\"padding:4px 0;\"><strong>Selected Plan:</strong></td><td style=\"text-align:right; font-weight:bold;\">" . htmlspecialchars($plan['name']) . "</td></tr>
                    <tr><td style=\"padding:4px 0;\"><strong>Amount Due:</strong></td><td style=\"text-align:right; font-weight:bold; color:#2D6A4F;\">₱" . number_format($plan['price'], 2) . "</td></tr>
                    <tr><td style=\"padding:4px 0;\"><strong>Payment Method:</strong></td><td style=\"text-align:right;\">Cash (Front Desk)</td></tr>
                    <tr><td style=\"padding:4px 0;\"><strong>Status:</strong></td><td style=\"text-align:right; color:#d97706; font-weight:bold;\">Pending Front Desk Payment</td></tr>
                </table>
            </div>

            <p>Please visit the gym front desk upon your arrival to settle your cash payment and activate your subscription.</p>
            <p style=\"margin-top:16px;\">Thank you for staying committed to your fitness journey with Palma's Elite Gym! 💪</p>
        ";
        try {
            send_email_notification($member['email'], $email_subject, $email_title, $email_body);
        } catch (\Throwable $emEx) {
            error_log("Renewal request email error in renew_request.php: " . $emEx->getMessage());
        }
    }

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
