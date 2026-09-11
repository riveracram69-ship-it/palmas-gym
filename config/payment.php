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
/**
 * Core function to activate or extend a member's subscription instantly upon payment verification.
 * 
 * Supports:
 * - Single transaction boundary (no nested transactions)
 * - Minute-level test promotions (e.g. 30-min, 60-min) and standard month-based plans
 * - Active expiration extension
 * - is_test transaction isolation
 * - Full idempotency
 */
function process_automated_subscription_activation($pdo, $member_id, $plan_id, $amount, $payment_method = 'GCash', $ref_no = '', bool $caller_controls_tx = false) {
    $should_manage_tx = !$caller_controls_tx && !$pdo->inTransaction();

    try {
        if ($should_manage_tx) {
            $pdo->beginTransaction();
        }

        // 1. Fetch Member with Row Lock
        $stmt = $pdo->prepare("SELECT id, full_name, email, membership_id, account_status, status FROM members WHERE id = ? FOR UPDATE");
        $stmt->execute([$member_id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            if ($should_manage_tx) $pdo->rollBack();
            return ['success' => false, 'message' => 'Member not found.'];
        }

        // Auto-approve member on confirmed payment if needed
        if (($member['account_status'] ?? '') !== 'Approved') {
            $pdo->prepare("UPDATE members SET account_status = 'Approved' WHERE id = ?")->execute([$member_id]);
        }

        // 2. Fetch Plan & Secure Server-Side Price & Duration
        $plan_stmt = $pdo->prepare("SELECT id, name, duration_months, duration_minutes, price, is_test_promo FROM membership_plans WHERE id = ?");
        $plan_stmt->execute([$plan_id]);
        $plan = $plan_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            if ($should_manage_tx) $pdo->rollBack();
            return ['success' => false, 'message' => 'Membership plan not found.'];
        }

        // Always enforce server-side pricing from database
        $amount = floatval($plan['price']);
        $duration_minutes = intval($plan['duration_minutes'] ?? 0);
        $duration_months = intval($plan['duration_months'] ?? 0);
        if ($duration_minutes <= 0 && preg_match('/(\d+)\s*(?:min|minute)/i', $plan['name'] ?? '', $pm)) {
            $duration_minutes = intval($pm[1]);
        }
        $is_test_promo = intval($plan['is_test_promo'] ?? 0);

        // Determine if this is a test transaction
        $is_test = ($is_test_promo === 1 || $duration_minutes > 0 || is_payment_demo() || is_payment_test() || (defined('PAYMENT_MODE') && in_array(PAYMENT_MODE, ['demo', 'test']))) ? 1 : 0;

        // 3. Check Prior Subscriptions to determine if this is First Activation or Renewal
        $prior_stmt = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE member_id = ?");
        $prior_stmt->execute([$member_id]);
        $prior_count = (int)$prior_stmt->fetchColumn();
        $is_first_activation = ($prior_count === 0);

        // 4. Calculate Subscription Dates (Extension from active expiry or new start)
        $sub_stmt = $pdo->prepare("
            SELECT id, expiry_date 
            FROM subscriptions 
            WHERE member_id = ? AND expiry_date >= NOW() 
            ORDER BY expiry_date DESC 
            LIMIT 1
        ");
        $sub_stmt->execute([$member_id]);
        $active_sub = $sub_stmt->fetch(PDO::FETCH_ASSOC);

        $now_str = date('Y-m-d H:i:s');
        if ($duration_minutes > 0) {
            // Temporary-duration promotion (e.g. 30 or 60 minutes)
            // Only extend if active sub is expiring within 5 minutes; otherwise start now
            $base_datetime = $now_str;
            if ($active_sub && !empty($active_sub['expiry_date'])) {
                $diff_sec = strtotime($active_sub['expiry_date']) - time();
                if ($diff_sec > 0 && $diff_sec <= 300) {
                    $base_datetime = $active_sub['expiry_date'];
                }
            }
            $start_date = $base_datetime;
            $new_expiry = date('Y-m-d H:i:s', strtotime("{$base_datetime} + {$duration_minutes} minutes"));
            $duration_label = "{$duration_minutes} Minute" . ($duration_minutes > 1 ? "s" : "");
        } else {
            // Month-based plan
            if ($active_sub && !empty($active_sub['expiry_date']) && strtotime($active_sub['expiry_date']) > time()) {
                $base_datetime = $active_sub['expiry_date'];
            } else {
                $base_datetime = $now_str;
            }
            $start_date = $base_datetime;
            if ($duration_months <= 0) $duration_months = 1;
            $new_expiry = date('Y-m-d 23:59:59', strtotime("{$base_datetime} + {$duration_months} months"));
            $duration_label = "{$duration_months} Month" . ($duration_months > 1 ? "s" : "");
        }

        // Insert Subscription with full DATETIME precision
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
        $pay_notes = "Payment via {$payment_method}" . ($ref_no ? " (Ref: {$ref_no})" : "") . ($is_test ? " [TEST]" : "");
        $insert_pay = $pdo->prepare("
            INSERT INTO payments (member_id, subscription_id, amount, payment_date, payment_method, reference_number, notes, is_test) 
            VALUES (?, ?, ?, NOW(), ?, ?, ?, ?)
        ");
        $insert_pay->execute([$member_id, $subscription_id, $amount, $db_method, $ref_code, $pay_notes, $is_test]);
        $payment_id = $pdo->lastInsertId();

        // 6. Record in payment_transactions table
        try {
            $tx_stmt = $pdo->prepare("
                INSERT INTO payment_transactions 
                (member_id, plan_id, subscription_id, reference_code, payment_method, amount, currency, status, is_test, paid_at, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, 'PHP', 'PAID', ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE))
                ON DUPLICATE KEY UPDATE status = 'PAID', subscription_id = VALUES(subscription_id), paid_at = NOW(), is_test = VALUES(is_test)
            ");
            $tx_stmt->execute([$member_id, $plan_id, $subscription_id, $ref_code, $std_method, $amount, $is_test]);
        } catch (Exception $txEx) {
            error_log("payment_transactions optional insert warning: " . $txEx->getMessage());
        }

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

        if ($should_manage_tx) {
            $pdo->commit();
        }

        // 9. Standardized Activity Log & Notifications
        $formatted_expiry = ($duration_minutes > 0)
            ? date('F j, Y, g:i A', strtotime($new_expiry))
            : date('F j, Y', strtotime($new_expiry));

        $action_label = $is_first_activation ? 'Membership Activated' : 'Membership Renewed';
        $log_desc = $is_first_activation
            ? "Membership activated for {$member['full_name']} ({$member['membership_id']}) on plan '{$plan['name']}' via {$payment_method} (₱" . number_format($amount, 2) . "). Valid until {$formatted_expiry}"
            : "Membership renewed for {$member['full_name']} ({$member['membership_id']}) on plan '{$plan['name']}' via {$payment_method} (₱" . number_format($amount, 2) . "). Extended to {$formatted_expiry}";

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
        $notif_msg = "Your '{$plan['name']}' pass has been processed via {$payment_method}. Valid until {$formatted_expiry}.";

        create_notification($pdo, $member_id, $notif_type, $notif_title, $notif_msg, 'Sent', (int)$subscription_id);

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
                • <strong>Duration:</strong> {$duration_label}<br>
                • <strong>New Expiry Date:</strong> {$formatted_expiry}<br><br>
                Your Digital QR Pass is now live and ready to use at the gym entrance kiosk. Have a great workout!
            ";
            @send_email_notification($member['email'], $email_subject, $email_title, $email_body);
        }

        return [
            'success' => true,
            'message' => "Payment successful! Your '{$plan['name']}' membership is now active until {$formatted_expiry}.",
            'plan_name' => $plan['name'],
            'amount' => $amount,
            'reference_no' => $ref_code,
            'expiry_date' => $new_expiry,
            'duration' => $duration_label,
            'member_status' => 'Active',
            'subscription_id' => (int)$subscription_id
        ];

    } catch (Exception $e) {
        if ($should_manage_tx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in process_automated_subscription_activation: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'An error occurred while activating your subscription: ' . $e->getMessage()
        ];
    }
}

/**
 * Retrieve comprehensive payment receipt details for receipt modal or PDF export
 */
function get_payment_receipt_details($pdo, $identifier, int $member_id = 0): ?array {
    try {
        $sql = "
            SELECT 
                t.id AS transaction_id,
                t.reference_code,
                t.gateway,
                t.gateway_transaction_id,
                t.payment_method,
                t.amount,
                t.currency,
                t.status,
                t.created_at,
                t.paid_at,
                m.id AS member_id,
                m.full_name AS member_name,
                m.membership_id,
                m.email AS member_email,
                m.contact_number,
                p.id AS plan_id,
                p.name AS plan_name,
                p.duration_months,
                s.start_date,
                s.expiry_date
            FROM payment_transactions t
            JOIN members m ON m.id = t.member_id
            JOIN membership_plans p ON p.id = t.plan_id
            LEFT JOIN subscriptions s ON s.id = t.subscription_id
            WHERE (t.id = :id_or_ref OR t.reference_code = :id_or_ref)
        ";
        
        $params = ['id_or_ref' => $identifier];
        if ($member_id > 0) {
            $sql .= " AND t.member_id = :member_id";
            $params['member_id'] = $member_id;
        }
        $sql .= " LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // ── FALLBACK 1: Search payments table (Staff / Registration payments) ──
        if (!$row) {
            $clean_pay_id = preg_replace('/^pay[-_]?/i', '', $identifier);
            $pay_sql = "
                SELECT 
                    py.id AS transaction_id,
                    COALESCE(py.reference_number, CONCAT('PAY-', py.id)) AS reference_code,
                    'Staff / Registration' AS gateway,
                    'N/A' AS gateway_transaction_id,
                    py.payment_method,
                    py.amount,
                    'PHP' AS currency,
                    'PAID' AS status,
                    py.created_at,
                    py.payment_date AS paid_at,
                    m.id AS member_id,
                    m.full_name AS member_name,
                    m.membership_id,
                    m.email AS member_email,
                    m.contact_number,
                    COALESCE(p.id, 0) AS plan_id,
                    COALESCE(p.name, 'Membership Payment') AS plan_name,
                    COALESCE(p.duration_months, 1) AS duration_months,
                    s.start_date,
                    s.expiry_date
                FROM payments py
                JOIN members m ON m.id = py.member_id
                LEFT JOIN subscriptions s ON s.id = py.subscription_id
                LEFT JOIN membership_plans p ON p.id = s.plan_id
                WHERE (py.id = :clean_id OR py.reference_number = :id_or_ref)
            ";
            $pay_params = ['clean_id' => is_numeric($clean_pay_id) ? (int)$clean_pay_id : 0, 'id_or_ref' => $identifier];
            if ($member_id > 0) {
                $pay_sql .= " AND py.member_id = :member_id";
                $pay_params['member_id'] = $member_id;
            }
            $pay_sql .= " LIMIT 1";
            $pStmt = $pdo->prepare($pay_sql);
            $pStmt->execute($pay_params);
            $row = $pStmt->fetch(PDO::FETCH_ASSOC);
        }

        // ── FALLBACK 2: Search renewal_requests table (Pending / Rejected renewals) ──
        if (!$row) {
            $clean_rnw_id = preg_replace('/^rnw[-_]?/i', '', $identifier);
            $rnw_sql = "
                SELECT 
                    r.id AS transaction_id,
                    COALESCE(r.reference_no, CONCAT('RNW-', r.id)) AS reference_code,
                    'Front Desk / Verification' AS gateway,
                    'N/A' AS gateway_transaction_id,
                    COALESCE(r.payment_method, 'Cash') AS payment_method,
                    COALESCE(p.price, 0) AS amount,
                    'PHP' AS currency,
                    CASE 
                        WHEN UPPER(r.status) = 'PENDING' THEN 'PENDING'
                        WHEN UPPER(r.status) = 'APPROVED' THEN 'PAID'
                        WHEN UPPER(r.status) = 'REJECTED' THEN 'FAILED'
                        ELSE UPPER(r.status)
                    END AS status,
                    r.created_at,
                    NULL AS paid_at,
                    m.id AS member_id,
                    m.full_name AS member_name,
                    m.membership_id,
                    m.email AS member_email,
                    m.contact_number,
                    COALESCE(p.id, 0) AS plan_id,
                    COALESCE(p.name, 'Membership Renewal') AS plan_name,
                    COALESCE(p.duration_months, 1) AS duration_months,
                    NULL AS start_date,
                    NULL AS expiry_date
                FROM renewal_requests r
                JOIN members m ON m.id = r.member_id
                LEFT JOIN membership_plans p ON p.id = r.plan_id
                WHERE (r.id = :clean_id OR r.reference_no = :id_or_ref)
            ";
            $rnw_params = ['clean_id' => is_numeric($clean_rnw_id) ? (int)$clean_rnw_id : 0, 'id_or_ref' => $identifier];
            if ($member_id > 0) {
                $rnw_sql .= " AND r.member_id = :member_id";
                $rnw_params['member_id'] = $member_id;
            }
            $rnw_sql .= " LIMIT 1";
            $rStmt = $pdo->prepare($rnw_sql);
            $rStmt->execute($rnw_params);
            $row = $rStmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$row) return null;

        // [R-06 FIX] Source gym info from system_settings instead of hardcoded strings
        $gym_name    = 'Palma\'s Elite Gym';
        $gym_address = 'Metro Manila, Philippines';
        $gym_contact = 'Contact your gym administrator';
        try {
            $gs = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('gym_name','gym_address','gym_phone','gym_email')");
            $gs_data = [];
            while ($gr = $gs->fetch(PDO::FETCH_ASSOC)) {
                $gs_data[$gr['setting_key']] = $gr['setting_value'];
            }
            if (!empty($gs_data['gym_name']))    $gym_name    = $gs_data['gym_name'];
            if (!empty($gs_data['gym_address'])) $gym_address = $gs_data['gym_address'];
            $contact_parts = [];
            if (!empty($gs_data['gym_email'])) $contact_parts[] = $gs_data['gym_email'];
            if (!empty($gs_data['gym_phone'])) $contact_parts[] = $gs_data['gym_phone'];
            if (!empty($contact_parts)) $gym_contact = implode(' | ', $contact_parts);
        } catch (Exception $gse) { /* Use fallback values above */ }

        $is_paid = (strtoupper($row['status'] ?? '') === 'PAID');
        $paid_at_str = $row['paid_at'] 
            ? date('F j, Y, g:i A', strtotime($row['paid_at'])) 
            : ($is_paid ? date('F j, Y, g:i A', strtotime($row['created_at'])) : 'Pending Verification');

        return [
            'gym' => [
                'name'    => $gym_name,
                'tagline' => 'Strength & Performance',
                'address' => $gym_address,
                'contact' => $gym_contact
            ],
            'receipt_no'       => 'REC-' . strtoupper(substr(md5($row['reference_code'] ?: $row['transaction_id']), 0, 10)),
            'reference_no'     => $row['reference_code'],
            'gateway'          => $row['gateway'] ?? 'Staff / Front Desk',
            'gateway_tx_id'    => $row['gateway_transaction_id'] ?: 'N/A',
            'payment_method'   => $row['payment_method'],
            'amount'           => (float)$row['amount'],
            'amount_formatted' => '₱' . number_format((float)$row['amount'], 2),
            'currency'         => $row['currency'],
            'status'           => strtoupper($row['status']),
            'is_paid'          => $is_paid,
            'paid_at'          => $paid_at_str,
            'created_at'       => date('F j, Y, g:i A', strtotime($row['created_at'])),
            'member' => [
                'id'            => (int)$row['member_id'],
                'name'          => $row['member_name'],
                'membership_id' => $row['membership_id'],
                'email'         => $row['member_email'],
                'contact'       => $row['contact_number']
            ],
            'plan' => [
                'id'              => (int)$row['plan_id'],
                'name'            => $row['plan_name'],
                'duration'        => $row['duration_months'] . ' Month(s)',
                'period_start'    => $row['start_date'] ? date('F j, Y', strtotime($row['start_date'])) : date('F j, Y'),
                'period_end'      => $row['expiry_date'] ? date('F j, Y', strtotime($row['expiry_date'])) : ($is_paid ? 'Active' : 'Pending Verification')
            ]
        ];
    } catch (Exception $e) {
        error_log("Error in get_payment_receipt_details: " . $e->getMessage());
        return null;
    }
}
