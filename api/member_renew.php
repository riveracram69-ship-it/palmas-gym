<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/cors.php'; // [R-02 FIX] Replaced wildcard CORS with origin-allowlist

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
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

// Enforce reference number for online payments
if (in_array($payment_method, ['GCash', 'Maya']) && empty($reference_no)) {
    echo json_encode(['success' => false, 'message' => "Please enter your {$payment_method} transaction Reference Number."]);
    exit;
}

try {
    // 1. Verify Member exists
    $stmt = $pdo->prepare("SELECT id, full_name, email, membership_id FROM members WHERE id = ?");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }

    // 2. Verify Plan exists & fetch official price
    $plan_stmt = $pdo->prepare("SELECT id, name, price, duration_months, duration_minutes, is_test_promo FROM membership_plans WHERE id = ?");
    $plan_stmt->execute([$plan_id]);
    $plan = $plan_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        echo json_encode(['success' => false, 'message' => 'Selected plan not found.']);
        exit;
    }

    // 2.5 Enforce business rule: renewal is ONLY permitted when expired or expiring soon
    // Fetch member's latest subscription
    $cur_sub_stmt = $pdo->prepare("
        SELECT s.id, s.expiry_date, s.status as sub_status, 
               p.name as current_plan_name, p.duration_minutes, p.duration_months, p.is_test_promo
        FROM subscriptions s
        LEFT JOIN membership_plans p ON s.plan_id = p.id
        WHERE s.member_id = ?
        ORDER BY s.id DESC
        LIMIT 1
    ");
    $cur_sub_stmt->execute([$member_id]);
    $active_sub = $cur_sub_stmt->fetch(PDO::FETCH_ASSOC);

    // If member is marked expired or inactive, or latest subscription is expired/cancelled, allow renewal!
    $is_active_member = (strcasecmp($member['status'] ?? '', 'Active') === 0);
    $sub_is_active = ($active_sub && strcasecmp($active_sub['sub_status'] ?? 'Active', 'Active') === 0);
    $sub_has_future_expiry = ($active_sub && !empty($active_sub['expiry_date']) && strtotime($active_sub['expiry_date']) > time());

    if ($is_active_member && $sub_is_active && $sub_has_future_expiry) {
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
                'message' => "Hindi pa maaaring mag-renew! Aktibo pa ang iyong kasalukuyang plano ({$rem_text} natitira). Maaari lamang mag-renew kapag expired na o {$rule_text}."
            ]);
            exit;
        }
    }

    // 3. Check for existing pending request (update instead of blocking)
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
            'message' => 'Your pending renewal for ' . htmlspecialchars($plan['name']) . ' has been updated with your ' . $payment_method . ' reference number (' . htmlspecialchars($reference_no) . '). Gym staff will verify shortly!'
        ]);
        exit;
    }

    // 4. Insert renewal request
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

    // 5. Send notification to staff
    try {
        $pdo->prepare("
            INSERT INTO notifications (member_id, type, title, message, delivery_status, read_status, sent_at)
            VALUES (?, 'Renewal', 'New Renewal Request Awaiting Staff Verification', ?, 'Sent', 'Unread', NOW())
        ")->execute([
            $member_id,
            "Member {$member['full_name']} ({$member['membership_id']}) requested renewal for {$plan['name']} (₱" . number_format($plan['price'], 2) . ") via {$payment_method}" . ($reference_no ? " | Ref: {$reference_no}" : "") . ". Pending staff approval."
        ]);
    } catch (Throwable $notifEx) {}

    echo json_encode([
        'success' => true,
        'message' => 'Renewal request for ' . htmlspecialchars($plan['name']) . ' submitted! Our staff will verify your ' . $payment_method . ' payment and activate your membership.'
    ]);

} catch (Exception $e) {
    error_log('API Error in member_renew.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An internal server error occurred.']);
}
