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

    if (!$plan || (int)($plan['is_active'] ?? 0) !== 1) {
        echo json_encode(['success' => false, 'message' => 'Selected plan is no longer available.']);
        exit;
    }

    // Validate plan tier eligibility (Official Member vs Non-Member, Annual Membership Fee)
    require_once __DIR__ . '/../config/member_helpers.php';
    $eligibility = validate_plan_tier_eligibility($plan, $member);
    if (!$eligibility['allowed']) {
        echo json_encode(['success' => false, 'message' => $eligibility['reason']]);
        exit;
    }

    // Renewal window check: Gym Access Passes can only be renewed when expired or within 3 days (or 5 mins for promo)
    $is_membership_fee = (($plan['plan_category'] ?? '') === 'membership_fee');
    if ($is_membership_fee) {
        if (!empty($member['annual_membership_expiry']) && strtotime($member['annual_membership_expiry']) > time()) {
            $diff_sec = strtotime($member['annual_membership_expiry']) - time();
            $diff_days = ceil($diff_sec / 86400);
            if ($diff_days > 30) {
                echo json_encode([
                    'success' => false,
                    'message' => "Ang iyong Annual Membership ay aktibo pa ({$diff_days} araw natitira). Maaari lamang itong i-renew kapag 30 araw o mas kaunti na lamang ang natitira bago mag-expire."
                ]);
                exit;
            }
        }
    } else {
        $cur_sub_stmt = $pdo->prepare("
            SELECT s.id, s.expiry_date, p.name as current_plan_name, p.duration_minutes, p.duration_months
            FROM subscriptions s
            LEFT JOIN membership_plans p ON s.plan_id = p.id
            WHERE s.member_id = ? AND (p.plan_category IS NULL OR p.plan_category != 'membership_fee')
            ORDER BY (s.expiry_date >= NOW()) DESC, s.expiry_date DESC, s.id DESC
            LIMIT 1
        ");
        $cur_sub_stmt->execute([$member['id']]);
        $active_sub = $cur_sub_stmt->fetch(PDO::FETCH_ASSOC);

        if ($active_sub && !empty($active_sub['expiry_date']) && strtotime($active_sub['expiry_date']) > time()) {
            $expiry_ts = strtotime($active_sub['expiry_date']);
            $diff_sec = $expiry_ts - time();
            $is_minute = (!empty($active_sub['duration_minutes']) && $active_sub['duration_minutes'] > 0);
            $threshold = $is_minute ? 300 : (3 * 86400);

            if ($diff_sec > $threshold) {
                $rem = ($is_minute || $diff_sec < 86400) ? ceil($diff_sec / 60) . ' minute(s)' : ceil($diff_sec / 86400) . ' day(s)';
                $rule = $is_minute ? 'within 5 minutes of expiration' : 'within 3 days of expiration';
                echo json_encode([
                    'success' => false,
                    'message' => "Hindi pa maaaring mag-renew! Aktibo pa ang kasalukuyang gym pass ({$rem} natitira). Maaari lamang mag-renew kapag expired na o {$rule}."
                ]);
                exit;
            }
        }
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
        } else {
            echo json_encode([
                'success' => false,
                'message' => $actRes['message'] ?? 'Failed to activate subscription.'
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

    // Send confirmation email to member (skip dummy test domains)
    $is_test_email = preg_match('/@(example\.com|test\.local|test\.com)$/i', $member['email'] ?? '');
    if (!empty($member['email']) && !$is_test_email) {
        require_once __DIR__ . '/../config/email.php';
        $time_tag = date('M d, Y h:i A');
        $email_subject = "Renewal Request Received [{$time_tag}] — Palma's Elite Gym";
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
