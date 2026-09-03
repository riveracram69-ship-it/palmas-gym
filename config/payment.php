<?php
// config/payment.php
// Central payment processing & instant auto-activation engine for Palma's Elite Gym

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/email.php';

/**
 * Core function to activate or extend a member's subscription instantly upon payment verification.
 */
function process_automated_subscription_activation($pdo, $member_id, $plan_id, $amount, $payment_method = 'GCash', $ref_no = '') {
    try {
        $pdo->beginTransaction();

        // 1. Fetch Member
        $stmt = $pdo->prepare("SELECT id, full_name, email, membership_id, account_status, status FROM members WHERE id = ? FOR UPDATE");
        $stmt->execute([$member_id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Member not found.'];
        }

        // Auto-approve member on confirmed payment if needed
        if (($member['account_status'] ?? '') !== 'Approved') {
            $pdo->prepare("UPDATE members SET account_status = 'Approved' WHERE id = ?")->execute([$member_id]);
        }

        // 2. Fetch Plan & Secure Server-Side Price
        $plan_stmt = $pdo->prepare("SELECT id, name, duration_months, price FROM membership_plans WHERE id = ?");
        $plan_stmt->execute([$plan_id]);
        $plan = $plan_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Membership plan not found.'];
        }

        // Always enforce server-side pricing from database
        $amount = floatval($plan['price']);
        $duration_months = intval($plan['duration_months'] ?? 1);
        if ($duration_months <= 0) $duration_months = 1;

        // 3. Check Prior Subscriptions to determine if this is First Activation or Renewal
        $prior_stmt = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE member_id = ?");
        $prior_stmt->execute([$member_id]);
        $prior_count = (int)$prior_stmt->fetchColumn();
        $is_first_activation = ($prior_count === 0);

        // 4. Calculate Subscription Dates (Extension or New)
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

        // 5. Normalize payment method for ENUM ('Cash','GCash','Bank Transfer','Credit Card')
        $db_method = 'GCash';
        $std_method = 'GCASH';
        $upper_m = strtoupper($payment_method);

        if (strpos($upper_m, 'CASH') !== false && strpos($upper_m, 'GCASH') === false) {
            $db_method = 'Cash';
            $std_method = 'CASH';
        } elseif (strpos($upper_m, 'MAYA') !== false) {
            $db_method = 'GCash';
            $std_method = 'MAYA';
        } elseif (strpos($upper_m, 'QR') !== false) {
            $db_method = 'GCash';
            $std_method = 'QRPH';
        } elseif (strpos($upper_m, 'BANK') !== false) {
            $db_method = 'Bank Transfer';
            $std_method = 'BANK_TRANSFER';
        } elseif (strpos($upper_m, 'CREDIT') !== false || strpos($upper_m, 'CARD') !== false) {
            $db_method = 'Credit Card';
            $std_method = 'CREDIT_CARD';
        }

        $ref_code = $ref_no ?: ('PEG-' . strtoupper(substr(uniqid(), -8)));

        // Insert Payment Record
        $pay_notes = "Payment via {$payment_method}" . ($ref_no ? " (Ref: {$ref_no})" : "");
        $insert_pay = $pdo->prepare("
            INSERT INTO payments (member_id, subscription_id, amount, payment_date, payment_method, reference_number, notes) 
            VALUES (?, ?, ?, NOW(), ?, ?, ?)
        ");
        $insert_pay->execute([$member_id, $subscription_id, $amount, $db_method, $ref_code, $pay_notes]);
        $payment_id = $pdo->lastInsertId();

        // 6. Record in payment_transactions table
        try {
            $tx_stmt = $pdo->prepare("
                INSERT INTO payment_transactions 
                (member_id, plan_id, reference_code, payment_method, amount, currency, status, paid_at, expires_at)
                VALUES (?, ?, ?, ?, ?, 'PHP', 'PAID', NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE))
                ON DUPLICATE KEY UPDATE status = 'PAID', paid_at = NOW()
            ");
            $tx_stmt->execute([$member_id, $plan_id, $ref_code, $std_method, $amount]);
        } catch (Exception $txEx) {}

        // 7. Update Member Status to Active
        $update_mem = $pdo->prepare("UPDATE members SET status = 'Active', account_status = 'Approved' WHERE id = ?");
        $update_mem->execute([$member_id]);

        // 8. Close/Approve any Pending Renewal Requests
        $update_req = $pdo->prepare("
            UPDATE renewal_requests 
            SET status = 'Approved', notes = ?, updated_at = NOW() 
            WHERE member_id = ? AND status = 'Pending'
        ");
        $update_req->execute(["Auto-approved via verified {$payment_method} payment", $member_id]);

        $pdo->commit();

        // 9. Standardized Activity Log & Notifications
        $action_label = $is_first_activation ? 'Membership Activated' : 'Membership Renewed';
        $log_desc = $is_first_activation
            ? "Membership activated for {$member['full_name']} ({$member['membership_id']}) on plan '{$plan['name']}' via {$payment_method} (₱" . number_format($amount, 2) . "). Valid until {$new_expiry}"
            : "Membership renewed for {$member['full_name']} ({$member['membership_id']}) on plan '{$plan['name']}' via {$payment_method} (₱" . number_format($amount, 2) . "). Extended to {$new_expiry}";

        log_activity(
            $pdo,
            $action_label,
            $log_desc,
            'Payment',
            $member_id,
            $member['full_name']
        );

        $notif_type = $is_first_activation ? 'MEMBERSHIP_ACTIVATED' : 'MEMBERSHIP_RENEWED';
        $notif_title = $is_first_activation ? 'Membership Activated! 🎉' : 'Membership Renewed! 🔄';
        $notif_msg = "Your '{$plan['name']}' pass has been processed via {$payment_method}. Valid until " . date('F j, Y', strtotime($new_expiry)) . ".";

        create_notification($pdo, $member_id, $notif_type, $notif_title, $notif_msg);

        // 10. Automated Email Receipt
        if (!empty($member['email'])) {
            $email_subject = $is_first_activation 
                ? "Payment Receipt & Membership Activated - Palma's Elite Gym"
                : "Payment Receipt & Membership Renewed - Palma's Elite Gym";
            $email_title = "Official Payment Confirmation";
            $email_body = "
                Dear <strong>{$member['full_name']}</strong>,<br><br>
                Thank you for your payment! Your membership has been <strong>" . ($is_first_activation ? "activated" : "renewed") . "</strong> in our system.<br><br>
                <strong>Transaction Details:</strong><br>
                • <strong>Plan:</strong> {$plan['name']}<br>
                • <strong>Amount Paid:</strong> ₱" . number_format($amount, 2) . "<br>
                • <strong>Payment Method:</strong> {$payment_method}<br>
                • <strong>Reference No:</strong> {$ref_code}<br>
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
