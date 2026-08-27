<?php
// config/payment.php
// Central payment processing & instant auto-activation engine for Palma's Elite Gym

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/email.php';

/**
 * Core function to activate or extend a member's subscription instantly upon payment verification.
 */
function process_automated_subscription_activation($pdo, $member_id, $plan_id, $amount, $payment_method = 'GCash', $ref_no = '') {
    try {
        $pdo->beginTransaction();

        // 1. Fetch Member
        $stmt = $pdo->prepare("SELECT id, full_name, email, membership_id, status FROM members WHERE id = ? FOR UPDATE");
        $stmt->execute([$member_id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Member not found.'];
        }

        // 2. Fetch Plan
        $plan_stmt = $pdo->prepare("SELECT id, name, duration_months, price FROM membership_plans WHERE id = ?");
        $plan_stmt->execute([$plan_id]);
        $plan = $plan_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Membership plan not found.'];
        }

        $duration_months = intval($plan['duration_months'] ?? 1);
        if ($duration_months <= 0) $duration_months = 1;

        // 3. Calculate Subscription Dates (Extension or New)
        $sub_stmt = $pdo->prepare("
            SELECT id, expiry_date 
            FROM subscriptions 
            WHERE member_id = ? AND expiry_date >= CURDATE() 
            ORDER BY expiry_date DESC 
            LIMIT 1
        ");
        $sub_stmt->execute([$member_id]);
        $active_sub = $sub_stmt->fetch(PDO::FETCH_ASSOC);

        if ($active_sub && !empty($active_sub['expiry_date'])) {
            // Member is still active -> extend from existing expiration date
            $base_date = $active_sub['expiry_date'];
            $start_date = $active_sub['expiry_date'];
        } else {
            // Member is expired or new -> start from today
            $base_date = date('Y-m-d');
            $start_date = date('Y-m-d');
        }

        $new_expiry = date('Y-m-d', strtotime("{$base_date} + {$duration_months} months"));

        // Insert Subscription
        $sub_insert = $pdo->prepare("
            INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date) 
            VALUES (?, ?, ?, ?)
        ");
        $sub_insert->execute([$member_id, $plan_id, $start_date, $new_expiry]);
        $subscription_id = $pdo->lastInsertId();

        // 4. Normalize payment method for ENUM ('Cash','GCash','Bank Transfer','Credit Card')
        $db_method = 'GCash';
        if (stripos($payment_method, 'Cash') !== false && stripos($payment_method, 'GCash') === false) {
            $db_method = 'Cash';
        } elseif (stripos($payment_method, 'Bank') !== false) {
            $db_method = 'Bank Transfer';
        } elseif (stripos($payment_method, 'Credit') !== false || stripos($payment_method, 'Card') !== false) {
            $db_method = 'Credit Card';
        } else {
            $db_method = 'GCash'; // Default for Maya, QR Ph, GCash
        }

        // Insert Payment Record
        $pay_notes = "Instant Auto-Activation via {$payment_method}" . ($ref_no ? " (Ref: {$ref_no})" : "");
        $insert_pay = $pdo->prepare("
            INSERT INTO payments (member_id, subscription_id, amount, payment_date, payment_method, reference_number, notes) 
            VALUES (?, ?, ?, NOW(), ?, ?, ?)
        ");
        $insert_pay->execute([$member_id, $subscription_id, $amount, $db_method, $ref_no ?: null, $pay_notes]);
        $payment_id = $pdo->lastInsertId();

        // 5. Update Member Status to Active
        $update_mem = $pdo->prepare("UPDATE members SET status = 'Active' WHERE id = ?");
        $update_mem->execute([$member_id]);

        // 6. Close/Approve any Pending Renewal Requests
        $update_req = $pdo->prepare("
            UPDATE renewal_requests 
            SET status = 'Approved', notes = ?, updated_at = NOW() 
            WHERE member_id = ? AND status = 'Pending'
        ");
        $update_req->execute(["Auto-approved via instant {$payment_method} payment", $member_id]);

        $pdo->commit();

        // 7. Activity Log
        log_activity(
            $pdo,
            'Instant Payment Auto-Activated',
            "{$member['full_name']} ({$member['membership_id']}) auto-activated '{$plan['name']}' plan via {$payment_method} (₱" . number_format($amount, 2) . "). Valid until {$new_expiry}",
            'Payment',
            $member_id,
            $member['full_name']
        );

        // 8. Automated Email Receipt
        if (!empty($member['email'])) {
            $email_subject = "Payment Receipt & Membership Activated - Palma's Elite Gym";
            $email_title = "Official Payment Confirmation";
            $email_body = "
                Dear <strong>{$member['full_name']}</strong>,<br><br>
                Thank you for your payment! Your membership has been <strong>automatically activated</strong> in our system.<br><br>
                <strong>Transaction Details:</strong><br>
                • <strong>Plan:</strong> {$plan['name']}<br>
                • <strong>Amount Paid:</strong> ₱" . number_format($amount, 2) . "<br>
                • <strong>Payment Method:</strong> {$payment_method}<br>
                • <strong>Reference No:</strong> " . ($ref_no ?: 'INSTANT-AUTO-' . time()) . "<br>
                • <strong>New Expiry Date:</strong> " . date('F j, Y', strtotime($new_expiry)) . "<br><br>
                Your Digital QR Pass is now live and ready to use at the gym entrance kiosk. Have a great workout!
            ";
            @send_email_notification($member['email'], $email_subject, $email_title, $email_body);
        }

        return [
            'success' => true,
            'message' => "Payment successful! Your '{$plan['name']}' membership is now active until " . date('M d, Y', strtotime($new_expiry)) . ".",
            'plan_name' => $plan['name'],
            'amount' => $amount,
            'expiry_date' => $new_expiry,
            'member_status' => 'Active'
        ];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in process_automated_subscription_activation: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'An error occurred while activating your subscription: ' . $e->getMessage()
        ];
    }
}
