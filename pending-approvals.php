<?php
$page_title = 'Pending Approvals';
include 'includes/header.php';
include 'includes/sidebar.php';
require_login();

$message = '';
$error = '';

// Active tab selection from GET parameter (default to 'registrations', or 'renewals')
$active_tab = $_GET['tab'] ?? 'registrations';
if (!in_array($active_tab, ['registrations', 'renewals'])) {
    $active_tab = 'registrations';
}

$admin_id = $_SESSION['user_id'] ?? null;
$admin_name = $_SESSION['user_name'] ?? 'Admin';

// ─────────────────────────────────────────────────────────────────────────────
// 1. POST ACTION HANDLER (Registrations & Renewals)
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_type = $_POST['form_type'] ?? '';
    $action    = $_POST['action'] ?? '';

    // ─────────────────────────────────────────────────────────────────────────
    // A. NEW REGISTRATION ACTIONS (Approve / Reject)
    // ─────────────────────────────────────────────────────────────────────────
    if ($form_type === 'registration' || in_array($action, ['approve_reg', 'reject_reg', 'approve', 'reject']) && isset($_POST['member_id'])) {
        $active_tab = 'registrations';
        $member_id = intval($_POST['member_id'] ?? 0);
        $rejection_reason = trim($_POST['rejection_reason'] ?? '');
        $is_approve = ($action === 'approve' || $action === 'approve_reg');

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT m.*, p.name as plan_name, p.duration_months, p.price as plan_price
                FROM members m
                LEFT JOIN membership_plans p ON p.id = m.selected_plan_id
                WHERE m.id = ? AND m.account_status = 'Pending'
                FOR UPDATE
            ");
            $stmt->execute([$member_id]);
            $pending_member = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pending_member) {
                $pdo->rollBack();
                $error = 'Pending registration not found or already processed.';
            } else {
                if ($is_approve) {
                    $plan_id = intval($pending_member['selected_plan_id'] ?? 0);
                    $plan_info = null;
                    if ($plan_id <= 0) {
                        $first_plan = $pdo->query("SELECT id, name, duration_months, duration_minutes, price, is_test_promo, plan_category FROM membership_plans WHERE is_active = 1 ORDER BY price ASC LIMIT 1")->fetch();
                        if ($first_plan) {
                            $plan_id = (int)$first_plan['id'];
                            $plan_info = $first_plan;
                        }
                    } else {
                        $p_stmt = $pdo->prepare("SELECT id, name, duration_months, duration_minutes, price, is_test_promo, plan_category FROM membership_plans WHERE id = ?");
                        $p_stmt->execute([$plan_id]);
                        $plan_info = $p_stmt->fetch(PDO::FETCH_ASSOC);
                    }

                    $duration_minutes = intval($plan_info['duration_minutes'] ?? 0);
                    $duration_months  = intval($plan_info['duration_months'] ?? 0);
                    $plan_category    = $plan_info['plan_category'] ?? 'legacy';

                    $is_daily_pass     = ($duration_minutes === 1440 || ($duration_months === 0 && stripos($plan_info['name'] ?? '', 'Daily') !== false));
                    $is_minute_promo   = ($duration_minutes > 0 && !$is_daily_pass);
                    if ($is_minute_promo && $duration_minutes <= 0 && preg_match('/(\d+)\s*(?:min|minute)/i', $plan_info['name'] ?? '', $pm)) {
                        $duration_minutes = intval($pm[1]);
                    }
                    $is_membership_fee = ($plan_category === 'membership_fee' || stripos($plan_info['name'] ?? '', 'Annual Membership Fee') !== false);
                    $is_test_payment   = ((int)($plan_info['is_test_promo'] ?? 0) === 1 || $plan_category === 'test_promo') ? 1 : 0;

                    $start_date = date('Y-m-d H:i:s');
                    $ann_expiry = null;

                    if ($is_membership_fee) {
                        $ann_expiry = date('Y-m-d', strtotime('+1 year'));
                        $expiry_date = $ann_expiry . ' 23:59:59';
                    } elseif ($is_minute_promo) {
                        $expiry_date = date('Y-m-d H:i:s', strtotime("+{$duration_minutes} minutes"));
                    } elseif ($is_daily_pass) {
                        $expiry_date = date('Y-m-d 23:59:59');
                    } else {
                        if ($duration_months <= 0) $duration_months = 1;
                        $expiry_date = date('Y-m-d H:i:s', strtotime("+{$duration_months} months"));
                    }

                    // 1. Update Member status
                    if ($is_membership_fee) {
                        $upd = $pdo->prepare("
                            UPDATE members 
                            SET account_status = 'Approved', 
                                status = 'Active', 
                                annual_membership_expiry = ?,
                                approved_by = ?, 
                                approved_at = NOW(),
                                rejection_reason = NULL
                            WHERE id = ?
                        ");
                        $upd->execute([$ann_expiry, $admin_id, $member_id]);
                    } else {
                        $upd = $pdo->prepare("
                            UPDATE members 
                            SET account_status = 'Approved', 
                                status = 'Active', 
                                approved_by = ?, 
                                approved_at = NOW(),
                                rejection_reason = NULL
                            WHERE id = ?
                        ");
                        $upd->execute([$admin_id, $member_id]);
                    }

                    // 2. Manage Subscription & Payment
                    if ($plan_id > 0) {
                        $existing_sub_stmt = $pdo->prepare("
                            SELECT id, plan_id, start_date, expiry_date 
                            FROM subscriptions 
                            WHERE member_id = ? AND plan_id = ? 
                            ORDER BY id DESC LIMIT 1
                        ");
                        $existing_sub_stmt->execute([$member_id, $plan_id]);
                        $existing_sub = $existing_sub_stmt->fetch(PDO::FETCH_ASSOC);

                        if ($existing_sub) {
                            $subscription_id = (int)$existing_sub['id'];
                            $pdo->prepare("UPDATE subscriptions SET created_by = COALESCE(created_by, ?) WHERE id = ?")
                                ->execute([$admin_id, $subscription_id]);
                        } else {
                            $sub_stmt = $pdo->prepare("
                                INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date, created_by)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $sub_stmt->execute([$member_id, $plan_id, $start_date, $expiry_date, $admin_id]);
                            $subscription_id = (int)$pdo->lastInsertId();
                        }

                        $existing_pay_stmt = $pdo->prepare("
                            SELECT id, subscription_id, amount, payment_method, reference_number 
                            FROM payments 
                            WHERE member_id = ? 
                            ORDER BY id DESC LIMIT 1
                        ");
                        $existing_pay_stmt->execute([$member_id]);
                        $existing_pay = $existing_pay_stmt->fetch(PDO::FETCH_ASSOC);

                        if ($existing_pay) {
                            $pdo->prepare("
                                UPDATE payments 
                                SET verified_by = COALESCE(verified_by, ?), 
                                    subscription_id = COALESCE(subscription_id, ?) 
                                WHERE id = ?
                            ")->execute([$admin_id, $subscription_id, $existing_pay['id']]);

                            $pdo->prepare("
                                UPDATE payment_transactions 
                                SET status = 'PAID', subscription_id = COALESCE(subscription_id, ?), paid_at = COALESCE(paid_at, NOW()) 
                                WHERE member_id = ? AND (status = 'PENDING' OR subscription_id IS NULL)
                            ")->execute([$subscription_id, $member_id]);

                        } else {
                            $tx_stmt = $pdo->prepare("
                                SELECT id, reference_code, payment_method, amount, status, is_test 
                                FROM payment_transactions 
                                WHERE member_id = ? 
                                ORDER BY id DESC LIMIT 1
                            ");
                            $tx_stmt->execute([$member_id]);
                            $tx_row = $tx_stmt->fetch(PDO::FETCH_ASSOC);

                            $rr = $pdo->prepare("
                                SELECT payment_method, reference_no 
                                FROM renewal_requests 
                                WHERE member_id = ? 
                                ORDER BY id DESC LIMIT 1
                            ");
                            $rr->execute([$member_id]);
                            $rr_row = $rr->fetch(PDO::FETCH_ASSOC);

                            $pay_method = 'Cash';
                            $pay_ref    = 'REG-' . $pending_member['membership_id'];
                            $is_test_tx = $is_test_payment;

                            if ($tx_row) {
                                $tx_m = strtoupper(trim($tx_row['payment_method'] ?? ''));
                                if (strpos($tx_m, 'GCASH') !== false) {
                                    $pay_method = 'GCash';
                                } elseif (strpos($tx_m, 'MAYA') !== false) {
                                    $pay_method = 'Maya';
                                } elseif (strpos($tx_m, 'CASH') !== false) {
                                    $pay_method = 'Cash';
                                } elseif (strpos($tx_m, 'BANK') !== false) {
                                    $pay_method = 'Bank Transfer';
                                } else {
                                    $pay_method = 'GCash';
                                }
                                $pay_ref    = !empty($tx_row['reference_code']) ? $tx_row['reference_code'] : $pay_ref;
                                $is_test_tx = !empty($tx_row['is_test']) ? 1 : $is_test_payment;

                                $pdo->prepare("
                                    UPDATE payment_transactions 
                                    SET status = 'PAID', paid_at = COALESCE(paid_at, NOW()), subscription_id = ? 
                                    WHERE id = ?
                                ")->execute([$subscription_id, $tx_row['id']]);

                            } elseif ($rr_row && !empty($rr_row['payment_method'])) {
                                $rr_m = trim($rr_row['payment_method']);
                                $pay_method = (strcasecmp($rr_m, 'gcash') === 0) ? 'GCash' : ((strcasecmp($rr_m, 'maya') === 0) ? 'Maya' : $rr_m);
                                $pay_ref    = !empty($rr_row['reference_no']) ? $rr_row['reference_no'] : $pay_ref;
                            }

                            $plan_price = floatval($plan_info['price'] ?? 0);
                            if ($plan_price <= 0) {
                                $p_fetch = $pdo->prepare("SELECT price FROM membership_plans WHERE id = ?");
                                $p_fetch->execute([$plan_id]);
                                $plan_price = floatval($p_fetch->fetchColumn() ?: 0);
                            }

                            $pay_notes = $is_membership_fee 
                                ? ("Annual Membership Fee Approved (" . ($pay_method === 'Cash' ? 'Front Desk Cash' : $pay_method) . ")")
                                : ("Registration Fee Approved (" . ($pay_method === 'Cash' ? 'Front Desk Cash' : $pay_method) . ")");

                            $pay_stmt = $pdo->prepare("
                                INSERT INTO payments (member_id, subscription_id, amount, payment_method, reference_number, payment_date, verified_by, notes, is_test, created_at)
                                VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, NOW())
                            ");
                            $pay_stmt->execute([
                                $member_id,
                                $subscription_id,
                                $plan_price,
                                $pay_method,
                                $pay_ref,
                                $admin_id,
                                $pay_notes,
                                $is_test_tx
                            ]);
                        }

                        $pdo->prepare("UPDATE renewal_requests SET status = 'Approved', processed_by = ?, notes = 'Approved along with registration', updated_at = NOW() WHERE member_id = ? AND status = 'Pending'")
                            ->execute([$admin_id, $member_id]);
                    }

                    log_activity($pdo, 'Member Registration Approved', "Admin approved registration for {$pending_member['full_name']} ({$pending_member['membership_id']})", 'Member', $admin_id, $admin_name);

                    try {
                        require_once __DIR__ . '/config/notifications.php';
                        create_notification($pdo, $member_id, 'ACCOUNT_APPROVED', 'Account Approved 🎉', "Your Palma's Elite Gym account has been approved! You can now log in to the Member Portal and Mobile App.");

                        require_once __DIR__ . '/config/email.php';
                        $email_subject = "Your Palma's Elite Gym Account Has Been Approved";
                        $email_title   = "Hello, {$pending_member['full_name']}!";
                        $email_body    = "Good news!<br><br>Your registration for Palma's Elite Gym has been <strong>Approved</strong>.<br><br>Your Membership ID is: <strong>{$pending_member['membership_id']}</strong>.<br><br>You can now log in to the Palma's Elite Gym mobile application and member portal.";
                        send_email_notification($pending_member['email'], $email_subject, $email_title, $email_body);
                    } catch (Exception $emErr) {}

                    $pdo->commit();
                    $message = "Member <strong>" . htmlspecialchars($pending_member['full_name']) . "</strong> ({$pending_member['membership_id']}) has been successfully approved and activated!";

                } else {
                    // Reject Registration
                    if (empty($rejection_reason)) {
                        $pdo->rollBack();
                        $error = "Please provide a reason for rejecting the registration.";
                    } else {
                        $upd = $pdo->prepare("
                            UPDATE members 
                            SET account_status = 'Rejected', 
                                status = 'Inactive', 
                                rejection_reason = ?,
                                approved_by = ?, 
                                approved_at = NOW()
                            WHERE id = ?
                        ");
                        $upd->execute([$rejection_reason, $admin_id, $member_id]);

                        log_activity($pdo, 'Member Registration Rejected', "Registration rejected for {$pending_member['full_name']} ({$pending_member['membership_id']}). Reason: {$rejection_reason}", 'Member', $admin_id, $admin_name);

                        try {
                            require_once __DIR__ . '/config/notifications.php';
                            create_notification($pdo, $member_id, 'ACCOUNT_REJECTED', 'Registration Update', "Your registration was not approved. Reason: {$rejection_reason}");

                            require_once __DIR__ . '/config/email.php';
                            $email_subject = "Update Regarding Your Palma's Elite Gym Registration";
                            $email_title   = "Hello, {$pending_member['full_name']}";
                            $email_body    = "We reviewed your registration request for Palma's Elite Gym.<br><br>Status: <strong>Declined</strong><br>Reason: {$rejection_reason}<br><br>If you believe this is a mistake, please visit the front desk.";
                            send_email_notification($pending_member['email'], $email_subject, $email_title, $email_body);
                        } catch (Exception $emErr) {}

                        $pdo->commit();
                        $message = "Registration for <strong>" . htmlspecialchars($pending_member['full_name']) . "</strong> has been rejected.";
                    }
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Failed to process registration: ' . $e->getMessage();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B. MEMBERSHIP RENEWAL ACTIONS (Approve / Reject)
    // ─────────────────────────────────────────────────────────────────────────
    elseif ($form_type === 'renewal' || in_array($action, ['approve_renewal', 'reject_renewal']) || isset($_POST['request_id'])) {
        $active_tab = 'renewals';
        $request_id = intval($_POST['request_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $is_approve_renew = ($action === 'approve' || $action === 'approve_renewal');

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT r.*, m.full_name, m.email, m.membership_id, m.status as member_status, m.annual_membership_expiry,
                       p.name as plan_name, p.price as plan_price, p.duration_months, p.duration_minutes, p.is_test_promo,
                       p.plan_category, p.floor_access
                FROM renewal_requests r
                JOIN members m ON r.member_id = m.id
                JOIN membership_plans p ON r.plan_id = p.id
                WHERE r.id = ? AND r.status = 'Pending'
                FOR UPDATE
            ");
            $stmt->execute([$request_id]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req) {
                $pdo->rollBack();
                $error = 'Renewal request not found or has already been processed.';
            } else {
                if ($is_approve_renew) {
                    $cur_sub_stmt = $pdo->prepare("
                        SELECT expiry_date FROM subscriptions 
                        WHERE member_id = ? AND expiry_date > NOW() 
                        ORDER BY expiry_date DESC LIMIT 1
                    ");
                    $cur_sub_stmt->execute([$req['member_id']]);
                    $active_sub = $cur_sub_stmt->fetch(PDO::FETCH_ASSOC);

                    $plan_category    = $req['plan_category'] ?? 'legacy';
                    $duration_minutes = intval($req['duration_minutes'] ?? 0);
                    $duration_months  = intval($req['duration_months'] ?? 0);

                    $is_daily_pass   = ($duration_minutes === 1440);
                    $is_minute_promo = ($duration_minutes > 0 && !$is_daily_pass);
                    if ($is_minute_promo && $duration_minutes <= 0 && preg_match('/(\d+)\s*(?:min|minute)/i', $req['plan_name'] ?? '', $pm)) {
                        $duration_minutes = intval($pm[1]);
                    }

                    if ($plan_category === 'membership_fee') {
                        $current_ann_expiry = $req['annual_membership_expiry'] ?? null;
                        if ($current_ann_expiry && strtotime($current_ann_expiry) >= strtotime(date('Y-m-d'))) {
                            $new_ann_expiry = date('Y-m-d', strtotime($current_ann_expiry . ' +1 year'));
                        } else {
                            $new_ann_expiry = date('Y-m-d', strtotime('+1 year'));
                        }
                        $pdo->prepare("UPDATE members SET status = 'Active', account_status = 'Approved', annual_membership_expiry = ? WHERE id = ?")
                            ->execute([$new_ann_expiry, $req['member_id']]);

                        $start_date  = date('Y-m-d H:i:s');
                        $expiry_date = $new_ann_expiry . ' 23:59:59';
                        $sub_stmt = $pdo->prepare("
                            INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date, created_by)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $sub_stmt->execute([$req['member_id'], $req['plan_id'], $start_date, $expiry_date, $admin_id]);
                        $subscription_id = $pdo->lastInsertId();

                        $pay_stmt = $pdo->prepare("
                            INSERT INTO payments (member_id, subscription_id, amount, payment_method, reference_number, payment_date, verified_by, notes, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $payment_notes = 'Annual Membership Fee — ' . ($req['payment_method'] === 'Cash' ? 'Front Desk Cash' : ('Verified ' . $req['payment_method'] . ($req['reference_no'] ? ' | Ref: ' . $req['reference_no'] : '')));
                        $pay_stmt->execute([
                            $req['member_id'], $subscription_id, $req['plan_price'],
                            $req['payment_method'], $req['reference_no'] ?: null,
                            date('Y-m-d'), $admin_id, $payment_notes
                        ]);

                        $up_stmt = $pdo->prepare("UPDATE renewal_requests SET status = 'Approved', processed_by = ?, notes = ?, updated_at = NOW() WHERE id = ?");
                        $up_stmt->execute([$admin_id, 'Approved by ' . $admin_name, $request_id]);

                        $pdo->commit();

                        try {
                            require_once __DIR__ . '/config/notifications.php';
                            create_notification($pdo, (int)$req['member_id'], 'MEMBERSHIP_RENEWED', 'Membership Fee Approved! 🎉',
                                "Your ₱1,000 Annual Membership Fee has been approved. You are now an Official Member until " . date('F j, Y', strtotime($new_ann_expiry)) . ". Member rates now apply!");
                        } catch (Exception $nE) {}

                        if (!empty($req['email'])) {
                            require_once 'config/email.php';
                            $formatted_expiry = date('F j, Y', strtotime($new_ann_expiry));
                            $email_subject = "Annual Membership Fee Approved! — Palma's Elite Gym";
                            $email_title = "You Are Now an Official Member! 🏆";
                            $email_body = "<p>Dear <strong>" . htmlspecialchars($req['full_name']) . "</strong>,</p><p>Your <strong>₱1,000 Annual Membership Fee</strong> has been approved. Membership valid until: <strong>{$formatted_expiry}</strong>.</p>";
                            try { send_email_notification($req['email'], $email_subject, $email_title, $email_body); } catch (Throwable $emEx) {}
                        }
                        $message = 'Annual Membership Fee approved for <strong>' . htmlspecialchars($req['full_name']) . '</strong>.';

                    } else {
                        // Regular gym subscription
                        if ($is_minute_promo) {
                            $base_time = time();
                            if ($active_sub && !empty($active_sub['expiry_date'])) {
                                $active_ts = strtotime($active_sub['expiry_date']);
                                if (($active_ts - time()) > 0 && ($active_ts - time()) <= 300) $base_time = $active_ts;
                            }
                            $start_date  = date('Y-m-d H:i:s', $base_time);
                            $expiry_date = date('Y-m-d H:i:s', strtotime("+{$duration_minutes} minutes", $base_time));
                        } elseif ($is_daily_pass) {
                            $start_date  = date('Y-m-d H:i:s');
                            $expiry_date = date('Y-m-d 23:59:59');
                        } else {
                            if ($active_sub && !empty($active_sub['expiry_date']) && strtotime($active_sub['expiry_date']) > time()) {
                                $base_time  = strtotime($active_sub['expiry_date']);
                                $start_date = $active_sub['expiry_date'];
                            } else {
                                $base_time  = time();
                                $start_date = date('Y-m-d H:i:s', $base_time);
                            }
                            if ($duration_months <= 0) $duration_months = 1;
                            $expiry_date = date('Y-m-d H:i:s', strtotime("+{$duration_months} months", $base_time));
                        }

                        $sub_stmt = $pdo->prepare("
                            INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date, created_by)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $sub_stmt->execute([$req['member_id'], $req['plan_id'], $start_date, $expiry_date, $admin_id]);
                        $subscription_id = $pdo->lastInsertId();

                        $pay_stmt = $pdo->prepare("
                            INSERT INTO payments (member_id, subscription_id, amount, payment_method, reference_number, payment_date, verified_by, notes, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $payment_notes = 'Online Renewal — ' . $req['plan_name'] . ' (' . $req['payment_method'] . ($req['reference_no'] ? ' | Ref: ' . $req['reference_no'] : '') . ')';
                        $pay_stmt->execute([
                            $req['member_id'], $subscription_id, $req['plan_price'],
                            $req['payment_method'], $req['reference_no'] ?: null,
                            date('Y-m-d'), $admin_id, $payment_notes
                        ]);

                        $pdo->prepare("UPDATE members SET status = 'Active', account_status = 'Approved' WHERE id = ?")
                            ->execute([$req['member_id']]);

                        $up_stmt = $pdo->prepare("UPDATE renewal_requests SET status = 'Approved', processed_by = ?, notes = ?, updated_at = NOW() WHERE id = ?");
                        $up_stmt->execute([$admin_id, 'Approved by ' . $admin_name, $request_id]);

                        $pdo->commit();

                        try {
                            require_once __DIR__ . '/config/notifications.php';
                            create_notification($pdo, (int)$req['member_id'], 'MEMBERSHIP_RENEWED', 'Subscription Renewed! 🏋️',
                                "Your {$req['plan_name']} subscription has been approved and extended until " . date('F j, Y', strtotime($expiry_date)) . ".");
                        } catch (Exception $nE) {}

                        if (!empty($req['email'])) {
                            require_once 'config/email.php';
                            $formatted_expiry = date('F j, Y', strtotime($expiry_date));
                            $email_subject = "Subscription Renewal Approved — Palma's Elite Gym";
                            $email_title = "Your Renewal is Approved! 🎉";
                            $email_body = "<p>Dear <strong>" . htmlspecialchars($req['full_name']) . "</strong>,</p><p>Your renewal for <strong>" . htmlspecialchars($req['plan_name']) . "</strong> has been approved. New valid until date: <strong>{$formatted_expiry}</strong>.</p>";
                            try { send_email_notification($req['email'], $email_subject, $email_title, $email_body); } catch (Throwable $emEx) {}
                        }
                        $message = 'Renewal approved for <strong>' . htmlspecialchars($req['full_name']) . '</strong>. Active until ' . date('M d, Y', strtotime($expiry_date)) . '.';
                    }
                } else {
                    // Reject Renewal
                    $up_stmt = $pdo->prepare("UPDATE renewal_requests SET status = 'Rejected', processed_by = ?, notes = ?, updated_at = NOW() WHERE id = ?");
                    $up_stmt->execute([$admin_id, $notes ?: 'Declined by admin', $request_id]);

                    $pdo->commit();

                    try {
                        require_once __DIR__ . '/config/notifications.php';
                        create_notification($pdo, (int)$req['member_id'], 'PAYMENT_FAILED', 'Renewal Request Declined',
                            "Your renewal request was declined." . ($notes ? " Reason: {$notes}" : ""));
                    } catch (Exception $nE) {}

                    if (!empty($req['email'])) {
                        require_once 'config/email.php';
                        $email_subject = "Renewal Request Update — Palma's Elite Gym";
                        $email_title = "Renewal Request Update";
                        $email_body = "<p>Dear <strong>" . htmlspecialchars($req['full_name']) . "</strong>,</p><p>Your renewal request for <strong>" . htmlspecialchars($req['plan_name']) . "</strong> could not be processed at this time.</p>" . ($notes ? "<p><strong>Reason:</strong> " . htmlspecialchars($notes) . "</p>" : "");
                        try { send_email_notification($req['email'], $email_subject, $email_title, $email_body); } catch (Throwable $emEx) {}
                    }
                    $message = 'Renewal request for <strong>' . htmlspecialchars($req['full_name']) . '</strong> was rejected.';
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Failed to process renewal request: ' . $e->getMessage();
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. FETCH DATA FOR BOTH TABS
// ─────────────────────────────────────────────────────────────────────────────
$search_query = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'Pending';

// A. Pending Registrations
$reg_params = [];
$reg_sql = "
    SELECT m.*, 
           COALESCE(p.name, 'Default Plan') as selected_plan_name,
           COALESCE(p.price, 0) as selected_plan_price,
           pt.payment_method as online_pay_method,
           pt.reference_code as online_ref_code,
           pt.amount as online_amount,
           pt.status as online_status,
           rr.payment_method as rr_payment_method,
           rr.reference_no as rr_reference_no
    FROM members m
    LEFT JOIN membership_plans p ON p.id = m.selected_plan_id
    LEFT JOIN (
        SELECT pt1.* FROM payment_transactions pt1
        INNER JOIN (
            SELECT member_id, MAX(id) as max_id FROM payment_transactions GROUP BY member_id
        ) pt2 ON pt1.id = pt2.max_id
    ) pt ON pt.member_id = m.id
    LEFT JOIN (
        SELECT rr1.* FROM renewal_requests rr1
        INNER JOIN (
            SELECT member_id, MAX(id) as max_id FROM renewal_requests GROUP BY member_id
        ) rr2 ON rr1.id = rr2.max_id
    ) rr ON rr.member_id = m.id
    WHERE 1=1
";

if ($status_filter !== 'all') {
    $reg_sql .= " AND m.account_status = ?";
    $reg_params[] = $status_filter;
}
if (!empty($search_query)) {
    $reg_sql .= " AND (m.full_name LIKE ? OR m.email LIKE ? OR m.membership_id LIKE ? OR m.contact_number LIKE ?)";
    $like = "%{$search_query}%";
    $reg_params[] = $like;
    $reg_params[] = $like;
    $reg_params[] = $like;
    $reg_params[] = $like;
}
$reg_sql .= " ORDER BY m.created_at DESC";
$reg_stmt = $pdo->prepare($reg_sql);
$reg_stmt->execute($reg_params);
$registrations_list = $reg_stmt->fetchAll(PDO::FETCH_ASSOC);

// B. Renewal Requests
$ren_params = [];
$ren_sql = "
    SELECT r.*, 
           m.full_name, m.email, m.membership_id, m.contact_number, m.photo,
           p.name as plan_name, p.price as plan_price, p.duration_months, p.duration_minutes, p.plan_category,
           u.name as processor_name
    FROM renewal_requests r
    JOIN members m ON r.member_id = m.id
    JOIN membership_plans p ON r.plan_id = p.id
    LEFT JOIN users u ON r.processed_by = u.id
    WHERE 1=1
";
if ($status_filter !== 'all') {
    $ren_sql .= " AND r.status = ?";
    $ren_params[] = $status_filter;
}
if (!empty($search_query)) {
    $ren_sql .= " AND (m.full_name LIKE ? OR m.email LIKE ? OR m.membership_id LIKE ? OR r.reference_no LIKE ?)";
    $like = "%{$search_query}%";
    $ren_params[] = $like;
    $ren_params[] = $like;
    $ren_params[] = $like;
    $ren_params[] = $like;
}
$ren_sql .= " ORDER BY r.created_at DESC";
$ren_stmt = $pdo->prepare($ren_sql);
$ren_stmt->execute($ren_params);
$renewals_list = $ren_stmt->fetchAll(PDO::FETCH_ASSOC);

// Real-time Badge Counts
$pending_regs_cnt = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE account_status = 'Pending'")->fetchColumn();
$pending_renews_cnt = (int)$pdo->query("SELECT COUNT(*) FROM renewal_requests WHERE status = 'Pending'")->fetchColumn();
$total_pending_all = $pending_regs_cnt + $pending_renews_cnt;
?>

<div class="content-wrapper">
    <!-- Page Header & Summary Ribbon -->
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
        <div>
            <h1 class="page-title" style="display:flex; align-items:center; gap:0.65rem; margin:0;">
                <i class="fas fa-clipboard-check" style="color:var(--accent);"></i> Pending Approvals Hub
            </h1>
            <p style="color:var(--text-muted); margin:0.25rem 0 0 0; font-size:0.875rem;">
                Review and approve new member registrations and membership renewal requests in one place.
            </p>
        </div>
        
        <div style="display:flex; gap:0.75rem; align-items:center;">
            <a href="members.php" class="btn btn-outline" style="font-size:0.85rem; padding:0.5rem 1rem;">
                <i class="fas fa-users"></i> All Members
            </a>
            <a href="attendance.php" class="btn btn-primary" style="font-size:0.85rem; padding:0.5rem 1rem;">
                <i class="fas fa-qrcode"></i> QR Scanner
            </a>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-success" style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.25rem; border-radius:10px; padding:0.85rem 1.25rem;">
            <i class="fas fa-circle-check" style="font-size:1.2rem;"></i>
            <div><?php echo $message; ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger" style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.25rem; border-radius:10px; padding:0.85rem 1.25rem;">
            <i class="fas fa-triangle-exclamation" style="font-size:1.2rem;"></i>
            <div><?php echo htmlspecialchars($error); ?></div>
        </div>
    <?php endif; ?>

    <!-- Summary KPI Cards -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
        <!-- Card 1: Total Pending -->
        <div class="card" style="padding:1rem 1.25rem; border-left:4px solid #f59e0b;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <span style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Total Action Required</span>
                    <h3 style="font-size:1.75rem; font-weight:800; margin:0.2rem 0 0 0; color:<?php echo $total_pending_all > 0 ? '#d97706' : 'var(--text-main)'; ?>;">
                        <?php echo $total_pending_all; ?>
                    </h3>
                </div>
                <div style="width:42px; height:42px; border-radius:10px; background:rgba(245,158,11,0.12); color:#d97706; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
                    <i class="fas fa-bell"></i>
                </div>
            </div>
        </div>

        <!-- Card 2: New Registrations -->
        <div class="card" style="padding:1rem 1.25rem; border-left:4px solid #3b82f6; cursor:pointer;" onclick="switchTab('registrations')">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <span style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">New Registrations</span>
                    <h3 style="font-size:1.75rem; font-weight:800; margin:0.2rem 0 0 0; color:#2563eb;">
                        <?php echo $pending_regs_cnt; ?>
                    </h3>
                </div>
                <div style="width:42px; height:42px; border-radius:10px; background:rgba(59,130,246,0.12); color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
                    <i class="fas fa-user-plus"></i>
                </div>
            </div>
        </div>

        <!-- Card 3: Subscription Renewals -->
        <div class="card" style="padding:1rem 1.25rem; border-left:4px solid #10b981; cursor:pointer;" onclick="switchTab('renewals')">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <span style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Plan Renewals</span>
                    <h3 style="font-size:1.75rem; font-weight:800; margin:0.2rem 0 0 0; color:#059669;">
                        <?php echo $pending_renews_cnt; ?>
                    </h3>
                </div>
                <div style="width:42px; height:42px; border-radius:10px; background:rgba(16,185,129,0.12); color:#059669; display:flex; align-items:center; justify-content:center; font-size:1.2rem;">
                    <i class="fas fa-arrows-rotate"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Approvals Hub Container -->
    <div class="card" style="padding:0; overflow:hidden;">
        
        <!-- Tab Navigation Bar & Filters -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem; padding:1rem 1.25rem; border-bottom:1px solid var(--border); background:rgba(255,255,255,0.02);">
            
            <!-- Tab Buttons -->
            <div style="display:flex; gap:0.5rem; align-items:center;">
                <button type="button" class="btn <?php echo $active_tab === 'registrations' ? 'btn-primary' : 'btn-outline'; ?>" id="tab-btn-regs" onclick="switchTab('registrations')" style="display:inline-flex; align-items:center; gap:0.5rem; font-weight:700; font-size:0.875rem; border-radius:8px; padding:0.5rem 1rem;">
                    <i class="fas fa-user-plus"></i> New Registrations
                    <?php if ($pending_regs_cnt > 0): ?>
                        <span class="badge" style="background:#ef4444; color:#fff; font-size:0.72rem; padding:2px 7px; border-radius:12px;"><?php echo $pending_regs_cnt; ?></span>
                    <?php endif; ?>
                </button>

                <button type="button" class="btn <?php echo $active_tab === 'renewals' ? 'btn-primary' : 'btn-outline'; ?>" id="tab-btn-renews" onclick="switchTab('renewals')" style="display:inline-flex; align-items:center; gap:0.5rem; font-weight:700; font-size:0.875rem; border-radius:8px; padding:0.5rem 1rem;">
                    <i class="fas fa-arrows-rotate"></i> Membership Renewals
                    <?php if ($pending_renews_cnt > 0): ?>
                        <span class="badge" style="background:#ef4444; color:#fff; font-size:0.72rem; padding:2px 7px; border-radius:12px;"><?php echo $pending_renews_cnt; ?></span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- Search & Filter Controls -->
            <form method="GET" action="pending-approvals.php" id="approvalsFilterForm" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; margin:0;">
                <input type="hidden" name="tab" id="filter-tab-input" value="<?php echo htmlspecialchars($active_tab); ?>">
                
                <div style="position:relative; width:220px;">
                    <i class="fas fa-search" style="position:absolute; left:0.75rem; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:0.8rem;"></i>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search name, ID..." class="form-control" style="padding-left:2rem; font-size:0.82rem; height:36px; border-radius:8px;">
                </div>

                <select name="status" class="form-control" style="width:130px; font-size:0.82rem; height:36px; border-radius:8px;" onchange="this.form.submit()">
                    <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending Only</option>
                    <option value="Approved" <?php echo $status_filter === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="Rejected" <?php echo $status_filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                </select>

                <button type="submit" class="btn btn-secondary" style="height:36px; font-size:0.82rem; padding:0 0.85rem; border-radius:8px;">
                    <i class="fas fa-filter"></i>
                </button>

                <?php if (!empty($search_query) || $status_filter !== 'Pending'): ?>
                    <a href="pending-approvals.php?tab=<?php echo urlencode($active_tab); ?>" class="btn btn-outline" style="height:36px; font-size:0.82rem; padding:0 0.75rem; border-radius:8px;" title="Reset filters">
                        <i class="fas fa-rotate-left"></i>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- PANE 1: NEW MEMBER REGISTRATIONS -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div id="pane-registrations" style="display:<?php echo $active_tab === 'registrations' ? 'block' : 'none'; ?>;">
            <div class="table-container" style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; min-width:850px;">
                    <thead>
                        <tr>
                            <th style="padding:0.75rem 1rem; text-align:left;">Member</th>
                            <th style="padding:0.75rem 1rem; text-align:left;">Selected Plan</th>
                            <th style="padding:0.75rem 1rem; text-align:left;">Payment Info</th>
                            <th style="padding:0.75rem 1rem; text-align:left;">Proof / Receipt</th>
                            <th style="padding:0.75rem 1rem; text-align:left;">Submitted</th>
                            <th style="padding:0.75rem 1rem; text-align:left;">Status</th>
                            <th style="padding:0.75rem 1rem; text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($registrations_list)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding:3rem 1rem; color:var(--text-muted);">
                                    <i class="fas fa-user-check" style="font-size:2.5rem; opacity:0.3; display:block; margin-bottom:0.75rem;"></i>
                                    <strong>No <?php echo htmlspecialchars(strtolower($status_filter === 'all' ? '' : $status_filter)); ?> registrations found.</strong>
                                    <p style="margin:0.25rem 0 0 0; font-size:0.8rem;">All new member registrations are up to date.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($registrations_list as $reg): 
                                $status_badge = 'badge-warning';
                                if ($reg['account_status'] === 'Approved') $status_badge = 'badge-success';
                                elseif ($reg['account_status'] === 'Rejected') $status_badge = 'badge-danger';

                                // Find proof receipt if any
                                $proof_img = $reg['online_proof_image'] ?? $reg['rr_receipt_image'] ?? null;
                                $pay_method_label = $reg['online_pay_method'] ?? $reg['rr_payment_method'] ?? 'Online / Cash';
                                $ref_num_label = $reg['online_ref_code'] ?? $reg['rr_reference_no'] ?? '—';
                                $reg_amount = $reg['online_amount'] ?? $reg['selected_plan_price'] ?? 0;
                            ?>
                            <tr style="border-bottom:1px solid var(--border);">
                                <td style="padding:0.85rem 1rem;">
                                    <div style="display:flex; align-items:center; gap:0.75rem;">
                                        <div style="width:38px; height:38px; border-radius:50%; background:#2d6a4f; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.9rem; flex-shrink:0; overflow:hidden;">
                                            <?php if (!empty($reg['photo'])): ?>
                                                <img src="<?php echo htmlspecialchars($reg['photo']); ?>" alt="Photo" style="width:100%; height:100%; object-fit:cover;">
                                            <?php else: ?>
                                                <?php echo strtoupper(substr($reg['full_name'], 0, 1)); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <a href="view-member.php?id=<?php echo $reg['id']; ?>" style="font-weight:700; color:var(--text-main); text-decoration:none;">
                                                <?php echo htmlspecialchars($reg['full_name']); ?>
                                            </a>
                                            <div style="font-size:0.75rem; color:var(--text-muted); font-family:monospace;"><?php echo htmlspecialchars($reg['membership_id']); ?></div>
                                            <div style="font-size:0.72rem; color:var(--text-muted);"><?php echo htmlspecialchars($reg['email'] ?? $reg['contact_number'] ?? ''); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding:0.85rem 1rem;">
                                    <span class="badge badge-gold" style="font-weight:700; font-size:0.75rem;">
                                        <?php echo htmlspecialchars($reg['selected_plan_name']); ?>
                                    </span>
                                    <div style="font-size:0.75rem; color:#52b788; font-weight:700; margin-top:0.2rem;">
                                        &#8369;<?php echo number_format($reg['selected_plan_price'], 2); ?>
                                    </div>
                                </td>
                                <td style="padding:0.85rem 1rem;">
                                    <div style="font-weight:700; font-size:0.82rem; color:var(--text-main);">
                                        <?php echo htmlspecialchars($pay_method_label); ?>
                                    </div>
                                    <div style="font-size:0.72rem; color:var(--text-muted); font-family:monospace;">
                                        Ref: <?php echo htmlspecialchars($ref_num_label); ?>
                                    </div>
                                </td>
                                <td style="padding:0.85rem 1rem;">
                                    <?php if (!empty($proof_img)): ?>
                                        <button type="button" class="btn btn-outline btn-sm" onclick="viewReceiptModal('<?php echo htmlspecialchars($proof_img); ?>', '<?php echo htmlspecialchars($reg['full_name']); ?>', '<?php echo htmlspecialchars($ref_num_label); ?>', '<?php echo number_format($reg_amount, 2); ?>')" style="font-size:0.75rem; padding:0.25rem 0.6rem; border-radius:6px; display:inline-flex; align-items:center; gap:4px;">
                                            <i class="fas fa-image" style="color:var(--accent);"></i> View Receipt
                                        </button>
                                    <?php else: ?>
                                        <span style="font-size:0.75rem; color:var(--text-muted); font-style:italic;">No Image</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:0.85rem 1rem; font-size:0.8rem; color:var(--text-muted);">
                                    <?php echo date('M d, Y', strtotime($reg['created_at'])); ?><br>
                                    <small><?php echo date('h:i A', strtotime($reg['created_at'])); ?></small>
                                </td>
                                <td style="padding:0.85rem 1rem;">
                                    <span class="badge <?php echo $status_badge; ?>" style="font-weight:700; font-size:0.75rem;">
                                        <?php echo htmlspecialchars($reg['account_status']); ?>
                                    </span>
                                </td>
                                <td style="padding:0.85rem 1rem; text-align:right; white-space:nowrap;">
                                    <?php if ($reg['account_status'] === 'Pending'): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="confirmApproveReg(<?php echo $reg['id']; ?>, '<?php echo htmlspecialchars(addslashes($reg['full_name'])); ?>', '<?php echo htmlspecialchars(addslashes($reg['selected_plan_name'])); ?>', '<?php echo number_format($reg['selected_plan_price'], 2); ?>')" style="font-size:0.75rem; padding:0.35rem 0.75rem; font-weight:700;">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button type="button" class="btn btn-danger btn-sm" onclick="openRejectRegModal(<?php echo $reg['id']; ?>, '<?php echo htmlspecialchars(addslashes($reg['full_name'])); ?>')" style="font-size:0.75rem; padding:0.35rem 0.6rem;">
                                            <i class="fas fa-xmark"></i> Reject
                                        </button>
                                    <?php else: ?>
                                        <a href="view-member.php?id=<?php echo $reg['id']; ?>" class="btn btn-outline btn-sm" style="font-size:0.75rem; padding:0.3rem 0.6rem;">
                                            View Member
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- PANE 2: MEMBERSHIP RENEWALS -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div id="pane-renewals" style="display:<?php echo $active_tab === 'renewals' ? 'block' : 'none'; ?>;">
            <div class="table-container" style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; min-width:850px;">
                    <thead>
                        <tr>
                            <th style="padding:0.75rem 1rem; text-align:left;">Member</th>
                            <th style="padding:0.75rem 1rem; text-align:left;">Renewal Plan</th>
                            <th style="padding:0.75rem 1rem; text-align:left;">Payment Info</th>
                            <th style="padding:0.75rem 1rem; text-align:left;">Receipt / Proof</th>
                            <th style="padding:0.75rem 1rem; text-align:left;">Submitted</th>
                            <th style="padding:0.75rem 1rem; text-align:left;">Status</th>
                            <th style="padding:0.75rem 1rem; text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($renewals_list)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding:3rem 1rem; color:var(--text-muted);">
                                    <i class="fas fa-arrows-rotate" style="font-size:2.5rem; opacity:0.3; display:block; margin-bottom:0.75rem;"></i>
                                    <strong>No <?php echo htmlspecialchars(strtolower($status_filter === 'all' ? '' : $status_filter)); ?> renewal requests found.</strong>
                                    <p style="margin:0.25rem 0 0 0; font-size:0.8rem;">All membership renewal requests have been processed.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($renewals_list as $ren): 
                                $status_badge = 'badge-warning';
                                if ($ren['status'] === 'Approved') $status_badge = 'badge-success';
                                elseif ($ren['status'] === 'Rejected') $status_badge = 'badge-danger';
                            ?>
                            <tr style="border-bottom:1px solid var(--border);">
                                <td style="padding:0.85rem 1rem;">
                                    <div style="display:flex; align-items:center; gap:0.75rem;">
                                        <div style="width:38px; height:38px; border-radius:50%; background:#2d6a4f; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.9rem; flex-shrink:0; overflow:hidden;">
                                            <?php if (!empty($ren['photo'])): ?>
                                                <img src="<?php echo htmlspecialchars($ren['photo']); ?>" alt="Photo" style="width:100%; height:100%; object-fit:cover;">
                                            <?php else: ?>
                                                <?php echo strtoupper(substr($ren['full_name'], 0, 1)); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <a href="view-member.php?id=<?php echo $ren['member_id']; ?>" style="font-weight:700; color:var(--text-main); text-decoration:none;">
                                                <?php echo htmlspecialchars($ren['full_name']); ?>
                                            </a>
                                            <div style="font-size:0.75rem; color:var(--text-muted); font-family:monospace;"><?php echo htmlspecialchars($ren['membership_id']); ?></div>
                                            <div style="font-size:0.72rem; color:var(--text-muted);"><?php echo htmlspecialchars($ren['email'] ?? $ren['contact_number'] ?? ''); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding:0.85rem 1rem;">
                                    <span class="badge badge-gold" style="font-weight:700; font-size:0.75rem;">
                                        <?php echo htmlspecialchars($ren['plan_name']); ?>
                                    </span>
                                    <div style="font-size:0.75rem; color:#52b788; font-weight:700; margin-top:0.2rem;">
                                        &#8369;<?php echo number_format($ren['plan_price'], 2); ?>
                                    </div>
                                </td>
                                <td style="padding:0.85rem 1rem;">
                                    <div style="font-weight:700; font-size:0.82rem; color:var(--text-main);">
                                        <?php echo htmlspecialchars($ren['payment_method']); ?>
                                    </div>
                                    <div style="font-size:0.72rem; color:var(--text-muted); font-family:monospace;">
                                        Ref: <?php echo htmlspecialchars($ren['reference_no'] ?: '—'); ?>
                                    </div>
                                </td>
                                <td style="padding:0.85rem 1rem;">
                                    <?php if (!empty($ren['receipt_image'])): ?>
                                        <button type="button" class="btn btn-outline btn-sm" onclick="viewReceiptModal('<?php echo htmlspecialchars($ren['receipt_image']); ?>', '<?php echo htmlspecialchars($ren['full_name']); ?>', '<?php echo htmlspecialchars($ren['reference_no'] ?: 'N/A'); ?>', '<?php echo number_format($ren['plan_price'], 2); ?>')" style="font-size:0.75rem; padding:0.25rem 0.6rem; border-radius:6px; display:inline-flex; align-items:center; gap:4px;">
                                            <i class="fas fa-image" style="color:var(--accent);"></i> View Receipt
                                        </button>
                                    <?php else: ?>
                                        <span style="font-size:0.75rem; color:var(--text-muted); font-style:italic;">No Image</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:0.85rem 1rem; font-size:0.8rem; color:var(--text-muted);">
                                    <?php echo date('M d, Y', strtotime($ren['created_at'])); ?><br>
                                    <small><?php echo date('h:i A', strtotime($ren['created_at'])); ?></small>
                                </td>
                                <td style="padding:0.85rem 1rem;">
                                    <span class="badge <?php echo $status_badge; ?>" style="font-weight:700; font-size:0.75rem;">
                                        <?php echo htmlspecialchars($ren['status']); ?>
                                    </span>
                                </td>
                                <td style="padding:0.85rem 1rem; text-align:right; white-space:nowrap;">
                                    <?php if ($ren['status'] === 'Pending'): ?>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="confirmApproveRenew(<?php echo $ren['id']; ?>, '<?php echo htmlspecialchars(addslashes($ren['full_name'])); ?>', '<?php echo htmlspecialchars(addslashes($ren['plan_name'])); ?>', '<?php echo number_format($ren['plan_price'], 2); ?>')" style="font-size:0.75rem; padding:0.35rem 0.75rem; font-weight:700;">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button type="button" class="btn btn-danger btn-sm" onclick="openRejectRenewModal(<?php echo $ren['id']; ?>, '<?php echo htmlspecialchars(addslashes($ren['full_name'])); ?>')" style="font-size:0.75rem; padding:0.35rem 0.6rem;">
                                            <i class="fas fa-xmark"></i> Reject
                                        </button>
                                    <?php else: ?>
                                        <a href="view-member.php?id=<?php echo $ren['member_id']; ?>" class="btn btn-outline btn-sm" style="font-size:0.75rem; padding:0.3rem 0.6rem;">
                                            View Member
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- ───────────────────────────────────────────────────────────────────────── -->
<!-- MODALS -->
<!-- ───────────────────────────────────────────────────────────────────────── -->

<!-- 1. Receipt Lightbox Preview Modal -->
<div id="receiptModal" class="modal" style="display:none; position:fixed; z-index:1050; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
    <div style="background:var(--card-bg, #1a231e); border:1px solid var(--border); border-radius:12px; max-width:520px; width:90%; overflow:hidden; box-shadow:0 20px 40px rgba(0,0,0,0.5);">
        <div style="padding:1rem 1.25rem; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
            <h4 style="margin:0; font-size:1.05rem; display:flex; align-items:center; gap:0.5rem;">
                <i class="fas fa-receipt" style="color:var(--accent);"></i> Payment Proof Receipt
            </h4>
            <button type="button" onclick="closeReceiptModal()" style="background:none; border:none; color:var(--text-muted); font-size:1.2rem; cursor:pointer;">&times;</button>
        </div>
        <div style="padding:1.25rem; text-align:center;">
            <div style="margin-bottom:0.75rem; text-align:left; background:rgba(255,255,255,0.03); padding:0.75rem 1rem; border-radius:8px; font-size:0.85rem;">
                <div><strong>Member:</strong> <span id="modalReceiptMember">—</span></div>
                <div><strong>Reference No:</strong> <span id="modalReceiptRef" style="font-family:monospace; color:var(--accent);">—</span></div>
                <div><strong>Amount:</strong> &#8369;<span id="modalReceiptAmount">0.00</span></div>
            </div>
            <div style="max-height:380px; overflow-y:auto; border-radius:8px; border:1px solid var(--border); background:#000;">
                <img id="modalReceiptImg" src="" alt="Proof Receipt" style="width:100%; height:auto; display:block;">
            </div>
        </div>
        <div style="padding:0.75rem 1.25rem; border-top:1px solid var(--border); display:flex; justify-content:flex-end;">
            <button type="button" class="btn btn-secondary" onclick="closeReceiptModal()" style="font-size:0.85rem; padding:0.4rem 1rem;">Close</button>
        </div>
    </div>
</div>

<!-- 2. Approve Registration Modal -->
<div id="approveRegModal" class="modal" style="display:none; position:fixed; z-index:1050; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
    <div style="background:var(--card-bg, #1a231e); border:1px solid var(--border); border-radius:12px; max-width:460px; width:90%; overflow:hidden;">
        <form method="POST" action="pending-approvals.php" style="margin:0;">
            <input type="hidden" name="form_type" value="registration">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="member_id" id="approve_reg_member_id" value="0">
            
            <div style="padding:1.25rem; border-bottom:1px solid var(--border);">
                <h3 style="margin:0; font-size:1.15rem; color:#52b788; display:flex; align-items:center; gap:0.5rem;">
                    <i class="fas fa-check-circle"></i> Approve Registration
                </h3>
            </div>
            <div style="padding:1.25rem;">
                <p style="margin:0 0 1rem 0; font-size:0.9rem; color:var(--text-main);">
                    Are you sure you want to approve and activate the membership account for <strong id="approve_reg_name" style="color:var(--accent);"></strong>?
                </p>
                <div style="background:rgba(82,183,136,0.08); border:1px solid rgba(82,183,136,0.2); border-radius:8px; padding:0.85rem 1rem; font-size:0.85rem;">
                    <div><strong>Selected Plan:</strong> <span id="approve_reg_plan">—</span></div>
                    <div><strong>Amount to Record:</strong> &#8369;<span id="approve_reg_price">0.00</span></div>
                    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.35rem;">
                        ✓ This will activate member login access and send an approval notification.
                    </div>
                </div>
            </div>
            <div style="padding:0.85rem 1.25rem; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:0.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeApproveRegModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="font-weight:700;"><i class="fas fa-check"></i> Yes, Approve Account</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Reject Registration Modal -->
<div id="rejectRegModal" class="modal" style="display:none; position:fixed; z-index:1050; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
    <div style="background:var(--card-bg, #1a231e); border:1px solid var(--border); border-radius:12px; max-width:460px; width:90%; overflow:hidden;">
        <form method="POST" action="pending-approvals.php" style="margin:0;">
            <input type="hidden" name="form_type" value="registration">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="member_id" id="reject_reg_member_id" value="0">
            
            <div style="padding:1.25rem; border-bottom:1px solid var(--border);">
                <h3 style="margin:0; font-size:1.15rem; color:#ef4444; display:flex; align-items:center; gap:0.5rem;">
                    <i class="fas fa-circle-xmark"></i> Reject Registration
                </h3>
            </div>
            <div style="padding:1.25rem;">
                <p style="margin:0 0 1rem 0; font-size:0.9rem;">
                    Please enter the reason for rejecting registration of <strong id="reject_reg_name"></strong>:
                </p>
                <div class="form-group" style="margin-bottom:0;">
                    <textarea name="rejection_reason" class="form-control" rows="3" placeholder="e.g. Invalid payment reference number, unclear ID, etc." required style="font-size:0.85rem; width:100%;"></textarea>
                </div>
            </div>
            <div style="padding:0.85rem 1.25rem; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:0.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeRejectRegModal()">Cancel</button>
                <button type="submit" class="btn btn-danger" style="font-weight:700;"><i class="fas fa-xmark"></i> Reject Registration</button>
            </div>
        </form>
    </div>
</div>

<!-- 4. Approve Renewal Modal -->
<div id="approveRenewModal" class="modal" style="display:none; position:fixed; z-index:1050; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
    <div style="background:var(--card-bg, #1a231e); border:1px solid var(--border); border-radius:12px; max-width:460px; width:90%; overflow:hidden;">
        <form method="POST" action="pending-approvals.php" style="margin:0;">
            <input type="hidden" name="form_type" value="renewal">
            <input type="hidden" name="action" value="approve_renewal">
            <input type="hidden" name="request_id" id="approve_ren_id" value="0">
            
            <div style="padding:1.25rem; border-bottom:1px solid var(--border);">
                <h3 style="margin:0; font-size:1.15rem; color:#52b788; display:flex; align-items:center; gap:0.5rem;">
                    <i class="fas fa-check-circle"></i> Approve Subscription Renewal
                </h3>
            </div>
            <div style="padding:1.25rem;">
                <p style="margin:0 0 1rem 0; font-size:0.9rem; color:var(--text-main);">
                    Are you sure you want to approve the membership renewal for <strong id="approve_ren_name" style="color:var(--accent);"></strong>?
                </p>
                <div style="background:rgba(82,183,136,0.08); border:1px solid rgba(82,183,136,0.2); border-radius:8px; padding:0.85rem 1rem; font-size:0.85rem;">
                    <div><strong>Renewal Plan:</strong> <span id="approve_ren_plan">—</span></div>
                    <div><strong>Amount:</strong> &#8369;<span id="approve_ren_price">0.00</span></div>
                    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.35rem;">
                        ✓ This will automatically extend their subscription and record the verified payment.
                    </div>
                </div>
            </div>
            <div style="padding:0.85rem 1.25rem; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:0.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeApproveRenewModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="font-weight:700;"><i class="fas fa-check"></i> Yes, Approve Renewal</button>
            </div>
        </form>
    </div>
</div>

<!-- 5. Reject Renewal Modal -->
<div id="rejectRenewModal" class="modal" style="display:none; position:fixed; z-index:1050; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
    <div style="background:var(--card-bg, #1a231e); border:1px solid var(--border); border-radius:12px; max-width:460px; width:90%; overflow:hidden;">
        <form method="POST" action="pending-approvals.php" style="margin:0;">
            <input type="hidden" name="form_type" value="renewal">
            <input type="hidden" name="action" value="reject_renewal">
            <input type="hidden" name="request_id" id="reject_ren_id" value="0">
            
            <div style="padding:1.25rem; border-bottom:1px solid var(--border);">
                <h3 style="margin:0; font-size:1.15rem; color:#ef4444; display:flex; align-items:center; gap:0.5rem;">
                    <i class="fas fa-circle-xmark"></i> Reject Renewal Request
                </h3>
            </div>
            <div style="padding:1.25rem;">
                <p style="margin:0 0 1rem 0; font-size:0.9rem;">
                    Please enter notes or reason for rejecting renewal of <strong id="reject_ren_name"></strong>:
                </p>
                <div class="form-group" style="margin-bottom:0;">
                    <textarea name="notes" class="form-control" rows="3" placeholder="e.g. Unverified payment reference, invalid transaction screenshot." required style="font-size:0.85rem; width:100%;"></textarea>
                </div>
            </div>
            <div style="padding:0.85rem 1.25rem; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:0.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeRejectRenewModal()">Cancel</button>
                <button type="submit" class="btn btn-danger" style="font-weight:700;"><i class="fas fa-xmark"></i> Reject Request</button>
            </div>
        </form>
    </div>
</div>

<script>
function switchTab(tab) {
    document.getElementById('filter-tab-input').value = tab;
    
    // Update tab button styles
    if (tab === 'registrations') {
        document.getElementById('tab-btn-regs').classList.remove('btn-outline');
        document.getElementById('tab-btn-regs').classList.add('btn-primary');
        document.getElementById('tab-btn-renews').classList.remove('btn-primary');
        document.getElementById('tab-btn-renews').classList.add('btn-outline');
        
        document.getElementById('pane-registrations').style.display = 'block';
        document.getElementById('pane-renewals').style.display = 'none';
    } else {
        document.getElementById('tab-btn-renews').classList.remove('btn-outline');
        document.getElementById('tab-btn-renews').classList.add('btn-primary');
        document.getElementById('tab-btn-regs').classList.remove('btn-primary');
        document.getElementById('tab-btn-regs').classList.add('btn-outline');
        
        document.getElementById('pane-renewals').style.display = 'block';
        document.getElementById('pane-registrations').style.display = 'none';
    }

    // Update URL query string without reloading page
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url);
}

// Receipt Modal Handler
function viewReceiptModal(imgSrc, memberName, refNo, amount) {
    document.getElementById('modalReceiptImg').src = imgSrc;
    document.getElementById('modalReceiptMember').textContent = memberName;
    document.getElementById('modalReceiptRef').textContent = refNo;
    document.getElementById('modalReceiptAmount').textContent = amount;
    document.getElementById('receiptModal').style.display = 'flex';
}
function closeReceiptModal() {
    document.getElementById('receiptModal').style.display = 'none';
}

// Registration Modals
function confirmApproveReg(id, name, plan, price) {
    document.getElementById('approve_reg_member_id').value = id;
    document.getElementById('approve_reg_name').textContent = name;
    document.getElementById('approve_reg_plan').textContent = plan;
    document.getElementById('approve_reg_price').textContent = price;
    document.getElementById('approveRegModal').style.display = 'flex';
}
function closeApproveRegModal() {
    document.getElementById('approveRegModal').style.display = 'none';
}

function openRejectRegModal(id, name) {
    document.getElementById('reject_reg_member_id').value = id;
    document.getElementById('reject_reg_name').textContent = name;
    document.getElementById('rejectRegModal').style.display = 'flex';
}
function closeRejectRegModal() {
    document.getElementById('rejectRegModal').style.display = 'none';
}

// Renewal Modals
function confirmApproveRenew(id, name, plan, price) {
    document.getElementById('approve_ren_id').value = id;
    document.getElementById('approve_ren_name').textContent = name;
    document.getElementById('approve_ren_plan').textContent = plan;
    document.getElementById('approve_ren_price').textContent = price;
    document.getElementById('approveRenewModal').style.display = 'flex';
}
function closeApproveRenewModal() {
    document.getElementById('approveRenewModal').style.display = 'none';
}

function openRejectRenewModal(id, name) {
    document.getElementById('reject_ren_id').value = id;
    document.getElementById('reject_ren_name').textContent = name;
    document.getElementById('rejectRenewModal').style.display = 'flex';
}
function closeRejectRenewModal() {
    document.getElementById('rejectRenewModal').style.display = 'none';
}

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php include 'includes/footer.php'; ?>
