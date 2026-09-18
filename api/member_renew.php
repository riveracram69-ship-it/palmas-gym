<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/cors.php'; // [R-02 FIX] Replaced wildcard CORS with origin-allowlist

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/member_helpers.php';
require_once __DIR__ . '/auth_middleware.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) {
    $data = $_POST;
}

$member_id      = $auth_member_id;
$plan_id        = intval($data['plan_id'] ?? 0);
$payment_method = trim($data['payment_method'] ?? 'GCash');
$reference_no   = trim($data['reference_no'] ?? '');

// Standardize to primary payment methods
if (stripos($payment_method, 'maya') !== false) {
    $payment_method = 'Maya';
} elseif (stripos($payment_method, 'qr') !== false || stripos($payment_method, 'gcash') !== false) {
    $payment_method = 'GCash';
} elseif (stripos($payment_method, 'cash') !== false) {
    $payment_method = 'Cash';
} else {
    $payment_method = 'GCash';
}

if (!$plan_id) {
    echo json_encode(['success' => false, 'message' => 'Please select a membership plan.']);
    exit;
}

// Reference number for online payments
if (in_array($payment_method, ['GCash', 'Maya']) && empty($reference_no)) {
    $reference_no = 'REN-' . strtoupper(substr($payment_method, 0, 2)) . '-' . strtoupper(bin2hex(random_bytes(3)));
}

try {
    // 1. Verify Member exists
    $stmt = $pdo->prepare("SELECT id, full_name, email, membership_id, status, annual_membership_expiry FROM members WHERE id = ?");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }

    // 2. Verify Plan exists & fetch official price
    $plan_stmt = $pdo->prepare("SELECT id, name, price, duration_months, duration_minutes, is_test_promo, plan_category, floor_access, is_active FROM membership_plans WHERE id = ?");
    $plan_stmt->execute([$plan_id]);
    $plan = $plan_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan || (int)($plan['is_active'] ?? 0) !== 1 || ($plan['plan_category'] ?? '') === 'legacy') {
        echo json_encode(['success' => false, 'message' => 'This plan is no longer available. Please select an active plan.']);
        exit;
    }

    // 2.2 Validate Plan Tier Eligibility (Official Member vs Non-Member, and Annual Membership Fee rules)
    $eligibility = validate_plan_tier_eligibility($plan, $member);
    if (!$eligibility['allowed']) {
        echo json_encode(['success' => false, 'message' => $eligibility['reason']]);
        exit;
    }

    // 2.5 Enforce business rule: renewal is ONLY permitted when expired or expiring soon
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
        // Fetch member's latest GYM ACCESS subscription (exclude membership_fee)
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
            $is_minute_promo = (!empty($active_sub['duration_minutes']) && $active_sub['duration_minutes'] > 0)
                || preg_match('/(\d+)\s*(?:min|minute)/i', $active_sub['current_plan_name'] ?? '');

            // Threshold: 5 minutes (300s) for promos, 3 days (259,200s) for standard plans
            $threshold_sec = $is_minute_promo ? 300 : (3 * 86400);

            if ($diff_sec > $threshold_sec) {
                $rem_text = '';
                if ($is_minute_promo || $diff_sec < 86400) {
                    $rem_mins = ceil($diff_sec / 60);
                    $rem_text = "{$rem_mins} minuto(s)";
                } else {
                    $rem_days = ceil($diff_sec / 86400);
                    $rem_text = "{$rem_days} araw";
                }
                $rule_text = $is_minute_promo ? '5 minuto bago mag-expire' : '3 araw bago mag-expire';

                echo json_encode([
                    'success' => false,
                    'message' => "Hindi pa maaaring mag-renew! Aktibo pa ang iyong kasalukuyang gym pass ({$rem_text} natitira). Maaari lamang mag-renew kapag expired na o {$rule_text}."
                ]);
                exit;
            }
        }
    }

    // 3. For GCash and Maya: Instant auto-activation without waiting for staff approval!
    if (in_array($payment_method, ['GCash', 'Maya'])) {
        require_once __DIR__ . '/../config/payment.php';
        $actRes = process_automated_subscription_activation(
            $pdo,
            $member_id,
            $plan_id,
            $plan['price'],
            $payment_method,
            $reference_no
        );

        if ($actRes && !empty($actRes['success'])) {
            echo json_encode([
                'success'   => true,
                'is_active' => true,
                'message'   => 'Renewal complete! Your ' . htmlspecialchars($plan['name']) . ' pass has been instantly activated via ' . $payment_method . '.'
            ]);
            exit;
        }
    }

    // 4. For Cash (Front Desk): Check for existing pending request or insert new pending request
    $pending_stmt = $pdo->prepare("SELECT id FROM renewal_requests WHERE member_id = ? AND status = 'Pending' LIMIT 1");
    $pending_stmt->execute([$member_id]);
    $existing = $pending_stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $update_stmt = $pdo->prepare("
            UPDATE renewal_requests 
            SET plan_id = ?, payment_method = ?, reference_no = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $update_stmt->execute([$plan_id, $payment_method, $reference_no ?: null, $existing['id']]);

        echo json_encode([
            'success' => true,
            'message' => 'Your pending renewal for ' . htmlspecialchars($plan['name']) . ' has been updated. Please settle cash at the gym front desk.'
        ]);
        exit;
    }

    $insert_stmt = $pdo->prepare("
        INSERT INTO renewal_requests (member_id, plan_id, payment_method, reference_no, status, created_at) 
        VALUES (?, ?, ?, ?, 'Pending', NOW())
    ");
    $insert_stmt->execute([
        $member_id,
        $plan_id,
        $payment_method,
        $reference_no ?: null
    ]);
    $request_id = $pdo->lastInsertId();

    // Send notification to staff
    try {
        $pdo->prepare("
            INSERT INTO notifications (member_id, type, title, message, delivery_status, read_status, sent_at)
            VALUES (?, 'Renewal', 'New Renewal Request Awaiting Staff Verification', ?, 'Sent', 'Unread', NOW())
        ")->execute([
            $member_id,
            "Member {$member['full_name']} ({$member['membership_id']}) requested renewal for {$plan['name']} (₱" . number_format($plan['price'], 2) . ") via {$payment_method}. Pending front-desk cash collection."
        ]);
    } catch (Throwable $notifEx) {}

    // Send confirmation email to member
    if (!empty($member['email'])) {
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
            error_log("Renewal request email error in member_renew.php: " . $emEx->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Renewal request for ' . htmlspecialchars($plan['name']) . ' submitted! Please settle your cash payment at the gym front desk upon your visit.'
    ]);

} catch (Exception $e) {
    error_log('API Error in member_renew.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An internal server error occurred.']);
}
