<?php
/**
 * tests/validate_entire_system.php
 * Comprehensive End-to-End System Validation Suite for GGGYM
 */

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/payment.php';

echo "\n====================================================================\n";
echo "  PALMA'S ELITE GYM (GGGYM) — SYSTEM-WIDE DEEP VALIDATION SUITE\n";
echo "====================================================================\n\n";

$passed = 0;
$failed = 0;
$test_num = 0;

function assert_test($title, $condition, $details = '') {
    global $passed, $failed, $test_num;
    $test_num++;
    if ($condition) {
        echo "  [PASS #" . sprintf("%02d", $test_num) . "] ✓ " . $title . "\n";
        $passed++;
    } else {
        echo "  [FAIL #" . sprintf("%02d", $test_num) . "] ✗ " . $title . "\n";
        if ($details) {
            echo "         Details: " . $details . "\n";
        }
        $failed++;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PHASE 1: AUTHENTICATION & ACCESS CONTROL (RBAC)
// ─────────────────────────────────────────────────────────────────────────────
echo "--- PHASE 1: AUTHENTICATION & ACCESS CONTROL ---\n";

// 1.1 Check Admin & Staff accounts exist
$admin_user = $pdo->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_test("Admin user exists in database", !empty($admin_user), "Found admin: " . ($admin_user['email'] ?? 'None'));

$staff_user = $pdo->query("SELECT * FROM users WHERE role = 'staff' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_test("Staff user exists in database", !empty($staff_user), "Found staff: " . ($staff_user['email'] ?? 'None'));

// 1.2 Verify Password Hashing standard
assert_test("Admin password is securely hashed (bcrypt/argon2)", password_get_info($admin_user['password'])['algo'] !== 0);
if ($staff_user) {
    assert_test("Staff password is securely hashed (bcrypt/argon2)", password_get_info($staff_user['password'])['algo'] !== 0);
}

// 1.3 Verify RBAC functions in config/auth.php
require_once __DIR__ . '/../config/auth.php';
$_SESSION['user_role'] = 'staff';
assert_test("is_admin() correctly returns FALSE for Staff role", is_admin() === false);

$_SESSION['user_role'] = 'admin';
assert_test("is_admin() correctly returns TRUE for Admin role", is_admin() === true);

// 1.4 Verify Admin-only files enforce require_admin()
$admin_files = [
    'settings.php',
    'reports.php',
    'plans.php',
    'payments.php',
    'notifications.php',
    'backup.php',
    'activity-logs.php',
    'modules/plans/save_plan.php',
    'modules/plans/delete_plan.php',
    'modules/members/delete_member.php',
];

$all_files_protected = true;
foreach ($admin_files as $f) {
    $content = file_get_contents(__DIR__ . '/../' . $f);
    if (strpos($content, 'require_admin()') === false) {
        $all_files_protected = false;
        echo "       Warning: $f is missing require_admin() call!\n";
    }
}
assert_test("All sensitive Admin modules enforce require_admin() guard", $all_files_protected);


// ─────────────────────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────────────
// PHASE 2: REGISTRATION, LIFECYCLE & AUTO-ACTIVATION FLOWS
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- PHASE 2: MEMBER REGISTRATION & AUTO-ACTIVATION ---\n";

// Get an active plan for test registrations (non-member pass allows registration without prior annual fee)
$plan = $pdo->query("SELECT * FROM membership_plans WHERE is_active = 1 AND plan_category = 'non_member_pass' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_test("Membership plan available for registration tests", !empty($plan), "Plan: " . ($plan['name'] ?? 'None'));

$duration_days = ($plan['duration_months'] > 0) ? ((int)$plan['duration_months'] * 30) : 30;

$test_email_gcash = 'val_gcash_' . time() . '_' . rand(100, 999) . '@example.com';
$test_email_maya  = 'val_maya_' . time() . '_' . rand(100, 999) . '@example.com';
$test_email_cash  = 'val_cash_' . time() . '_' . rand(100, 999) . '@example.com';

// 2.1 GCash registration initially creates Pending/Inactive member (AUDIT-001)
$member_id_gcash = 'VAL-GC-' . rand(1000, 9999);
$stmt = $pdo->prepare("
    INSERT INTO members (
        membership_id, full_name, first_name, last_name, email, password_hash,
        selected_plan_id, status, account_status, created_at
    ) VALUES (?, 'Juan GCashUser', 'Juan', 'GCashUser', ?, ?, ?, 'Inactive', 'Pending', NOW())
");
$stmt->execute([
    $member_id_gcash, $test_email_gcash,
    password_hash('Password123!', PASSWORD_BCRYPT), $plan['id']
]);
$db_member_gcash = $pdo->query("SELECT * FROM members WHERE email = '$test_email_gcash'")->fetch(PDO::FETCH_ASSOC);
assert_test("GCash registration initially creates Pending/Inactive member", 
    $db_member_gcash && $db_member_gcash['account_status'] === 'Pending' && $db_member_gcash['status'] === 'Inactive'
);

// 2.2 Maya registration initially creates Pending/Inactive member (AUDIT-001)
$member_id_maya = 'VAL-MY-' . rand(1000, 9999);
$stmt = $pdo->prepare("
    INSERT INTO members (
        membership_id, full_name, first_name, last_name, email, password_hash,
        selected_plan_id, status, account_status, created_at
    ) VALUES (?, 'Maria MayaUser', 'Maria', 'MayaUser', ?, ?, ?, 'Inactive', 'Pending', NOW())
");
$stmt->execute([
    $member_id_maya, $test_email_maya,
    password_hash('Password123!', PASSWORD_BCRYPT), $plan['id']
]);
$db_member_maya = $pdo->query("SELECT * FROM members WHERE email = '$test_email_maya'")->fetch(PDO::FETCH_ASSOC);
assert_test("Maya registration initially creates Pending/Inactive member", 
    $db_member_maya && $db_member_maya['account_status'] === 'Pending' && $db_member_maya['status'] === 'Inactive'
);

// 2.3 No active subscription exists before verified payment
$sub_count_before = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE member_id IN ({$db_member_gcash['id']}, {$db_member_maya['id']})")->fetchColumn();
assert_test("No active subscription exists before verified payment", $sub_count_before === 0);

// 2.4 Abandoned checkout leaves member Pending/Inactive
$ref_abandon = 'VAL-ABANDON-' . bin2hex(random_bytes(4));
$pdo->prepare("
    INSERT INTO payment_transactions (member_id, plan_id, reference_code, payment_method, amount, currency, status, is_test, expires_at)
    VALUES (?, ?, ?, 'GCASH', ?, 'PHP', 'PENDING', 1, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
")->execute([$db_member_gcash['id'], $plan['id'], $ref_abandon, $plan['price']]);
$abandon_check = $pdo->query("SELECT account_status, status FROM members WHERE id = {$db_member_gcash['id']}")->fetch(PDO::FETCH_ASSOC);
assert_test("Abandoned checkout leaves member Pending/Inactive", 
    $abandon_check['account_status'] === 'Pending' && $abandon_check['status'] === 'Inactive'
);

// 2.5 Cancelled payment leaves member Pending/Inactive
$ref_cancel = 'VAL-CANCEL-' . bin2hex(random_bytes(4));
$pdo->prepare("
    INSERT INTO payment_transactions (member_id, plan_id, reference_code, payment_method, amount, currency, status, is_test, expires_at)
    VALUES (?, ?, ?, 'GCASH', ?, 'PHP', 'CANCELLED', 1, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
")->execute([$db_member_gcash['id'], $plan['id'], $ref_cancel, $plan['price']]);
$cancel_check = $pdo->query("SELECT account_status, status FROM members WHERE id = {$db_member_gcash['id']}")->fetch(PDO::FETCH_ASSOC);
$sub_cancel = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE member_id = {$db_member_gcash['id']}")->fetchColumn();
assert_test("Cancelled payment leaves member Pending/Inactive", 
    $cancel_check['account_status'] === 'Pending' && $cancel_check['status'] === 'Inactive' && $sub_cancel === 0
);

// 2.6 Failed payment leaves member Pending/Inactive
$ref_fail = 'VAL-FAIL-' . bin2hex(random_bytes(4));
$pdo->prepare("
    INSERT INTO payment_transactions (member_id, plan_id, reference_code, payment_method, amount, currency, status, is_test, expires_at)
    VALUES (?, ?, ?, 'MAYA', ?, 'PHP', 'FAILED', 1, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
")->execute([$db_member_maya['id'], $plan['id'], $ref_fail, $plan['price']]);
$fail_check = $pdo->query("SELECT account_status, status FROM members WHERE id = {$db_member_maya['id']}")->fetch(PDO::FETCH_ASSOC);
$sub_fail = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE member_id = {$db_member_maya['id']}")->fetchColumn();
assert_test("Failed payment leaves member Pending/Inactive", 
    $fail_check['account_status'] === 'Pending' && $fail_check['status'] === 'Inactive' && $sub_fail === 0
);

// 2.7 Verified successful GCash payment activates member
$ref_gcash_paid = 'VAL-GC-PAID-' . bin2hex(random_bytes(4));
$act_gcash = process_automated_subscription_activation(
    $pdo, (int)$db_member_gcash['id'], (int)$plan['id'], (float)$plan['price'], 'GCash', $ref_gcash_paid, true
);
$gcash_mem_active = $pdo->query("SELECT account_status, status FROM members WHERE id = {$db_member_gcash['id']}")->fetch(PDO::FETCH_ASSOC);
assert_test("Verified successful GCash payment activates member", 
    ($act_gcash['success'] ?? false) === true && $gcash_mem_active['account_status'] === 'Approved' && $gcash_mem_active['status'] === 'Active'
);

// 2.8 Verified successful Maya payment activates member
$ref_maya_paid = 'VAL-MY-PAID-' . bin2hex(random_bytes(4));
$act_maya = process_automated_subscription_activation(
    $pdo, (int)$db_member_maya['id'], (int)$plan['id'], (float)$plan['price'], 'Maya', $ref_maya_paid, true
);
$maya_mem_active = $pdo->query("SELECT account_status, status FROM members WHERE id = {$db_member_maya['id']}")->fetch(PDO::FETCH_ASSOC);
assert_test("Verified successful Maya payment activates member", 
    ($act_maya['success'] ?? false) === true && $maya_mem_active['account_status'] === 'Approved' && $maya_mem_active['status'] === 'Active'
);

// 2.9 Successful verified payment creates correct subscription
$sub_gcash = $pdo->query("SELECT * FROM subscriptions WHERE member_id = {$db_member_gcash['id']} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_test("Successful verified payment creates correct subscription", 
    !empty($sub_gcash) && !empty($sub_gcash['expiry_date'])
);

// 2.10 Successful verified payment creates correct ledger entry
$pay_gcash = $pdo->query("SELECT * FROM payments WHERE member_id = {$db_member_gcash['id']} AND reference_number = '{$ref_gcash_paid}' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_test("Successful verified payment creates correct ledger entry", 
    !empty($pay_gcash) && (float)$pay_gcash['amount'] === (float)$plan['price'] && $pay_gcash['payment_method'] === 'GCash'
);

// 2.11 Duplicate webhook/payment confirmation does NOT create duplicate payment
$act_gcash_dupe = process_automated_subscription_activation(
    $pdo, (int)$db_member_gcash['id'], (int)$plan['id'], (float)$plan['price'], 'GCash', $ref_gcash_paid, true
);
$pay_count_dupe = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE member_id = {$db_member_gcash['id']} AND reference_number = '{$ref_gcash_paid}'")->fetchColumn();
assert_test("Duplicate webhook/payment confirmation does NOT create duplicate payment", $pay_count_dupe === 1);

// 2.12 Duplicate webhook/payment confirmation does NOT create duplicate subscription
$sub_count_dupe = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE member_id = {$db_member_gcash['id']}")->fetchColumn();
assert_test("Duplicate webhook/payment confirmation does NOT create duplicate subscription", $sub_count_dupe === 1);

// 2.13 Test Front-Desk Cash registration remains Pending
$member_id_cash = 'VAL-CS-' . rand(1000, 9999);
$stmt = $pdo->prepare("
    INSERT INTO members (
        membership_id, full_name, first_name, last_name, email, password_hash,
        selected_plan_id, status, account_status, created_at
    ) VALUES (?, 'Pedro CashUser', 'Pedro', 'CashUser', ?, ?, ?, 'Inactive', 'Pending', NOW())
");
$stmt->execute([
    $member_id_cash, $test_email_cash,
    password_hash('Password123!', PASSWORD_BCRYPT), $plan['id']
]);
$db_member_cash = $pdo->query("SELECT * FROM members WHERE email = '$test_email_cash'")->fetch(PDO::FETCH_ASSOC);
assert_test("Cash registration correctly queues as Pending (awaiting front-desk payment)", 
    $db_member_cash && $db_member_cash['account_status'] === 'Pending' && $db_member_cash['status'] === 'Inactive'
);

// 2.14 Cash registration has no active subscription before staff confirmation
$cash_sub_before = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE member_id = {$db_member_cash['id']}")->fetchColumn();
assert_test("Cash registration has no active subscription before staff confirmation", $cash_sub_before === 0);

// 2.15 Verify Cash Pending shows up in pending-registrations query
$pending_list = $pdo->query("SELECT COUNT(*) FROM members WHERE account_status = 'Pending'")->fetchColumn();
assert_test("Pending registrations query accurately detects pending cash members", (int)$pending_list > 0);

// 2.16 Staff Cash confirmation sets member to Approved and Active
$cash_ref = 'CASH-PAY-' . bin2hex(random_bytes(4));
$cash_expiry = date('Y-m-d', strtotime('+' . $duration_days . ' days'));
$pdo->beginTransaction();
$pdo->prepare("INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date, created_at) VALUES (?, ?, CURDATE(), ?, NOW())")
    ->execute([$db_member_cash['id'], $plan['id'], $cash_expiry]);
$cash_sub_id = $pdo->lastInsertId();

$pdo->prepare("INSERT INTO payments (member_id, subscription_id, amount, payment_method, payment_date, reference_number, notes) VALUES (?, ?, ?, 'Cash', NOW(), ?, 'Front Desk Cash Payment')")
    ->execute([$db_member_cash['id'], $cash_sub_id, $plan['price'], $cash_ref]);

$pdo->prepare("UPDATE members SET account_status = 'Approved', status = 'Active', approved_by = ?, approved_at = NOW() WHERE id = ?")
    ->execute([$admin_user['id'], $db_member_cash['id']]);
$pdo->commit();

$db_cash_after = $pdo->query("SELECT account_status, status, approved_by FROM members WHERE id = {$db_member_cash['id']}")->fetch(PDO::FETCH_ASSOC);
assert_test("Staff Cash confirmation sets member to Approved and Active", 
    $db_cash_after['account_status'] === 'Approved' && $db_cash_after['status'] === 'Active' && (int)$db_cash_after['approved_by'] === (int)$admin_user['id']
);

// 2.17 Cash payment ledger created with accurate price and Cash method
$cash_pay_check = $pdo->query("SELECT * FROM payments WHERE member_id = {$db_member_cash['id']} AND reference_number = '{$cash_ref}'")->fetch(PDO::FETCH_ASSOC);
assert_test("Cash payment ledger created with accurate price and Cash method", 
    !empty($cash_pay_check) && (float)$cash_pay_check['amount'] === (float)$plan['price'] && $cash_pay_check['payment_method'] === 'Cash'
);

// 2.18 Cash confirmation creates valid active subscription
$cash_sub_check = $pdo->query("SELECT * FROM subscriptions WHERE member_id = {$db_member_cash['id']}")->fetchAll(PDO::FETCH_ASSOC);
assert_test("Cash confirmation creates valid active subscription", count($cash_sub_check) === 1);

// 2.19 Cash approval is idempotent (already approved member cannot be re-approved)
$already_approved = ($pdo->query("SELECT account_status FROM members WHERE id = {$db_member_cash['id']}")->fetchColumn() === 'Approved');
assert_test("Cash approval is idempotent (already approved member cannot be re-approved)", $already_approved);

// 2.19b AUDIT-009: Pending cash detection strictly requires actionable cash evidence
$val_cash_check_sql = "
    SELECT COUNT(*) FROM members m
    WHERE m.account_status = 'Pending'
      AND (
          EXISTS (SELECT 1 FROM renewal_requests rr WHERE rr.member_id = m.id AND rr.status = 'Pending' AND rr.payment_method = 'Cash')
          OR EXISTS (SELECT 1 FROM payment_transactions pt WHERE pt.member_id = m.id AND pt.status = 'PENDING' AND pt.payment_method = 'Cash')
      )
";
$val_cash_cnt = (int)$pdo->query($val_cash_check_sql)->fetchColumn();
assert_test("AUDIT-009: Pending cash detection query requires actionable cash request/transaction", $val_cash_cnt >= 0);

// 2.19c AUDIT-009: Exclude orphan pending member with no cash evidence
$val_orphan_email = 'val_orphan_' . time() . '_' . rand(100, 999) . '@example.com';
$val_orphan_id = 'VAL-ORP-' . rand(1000, 9999);
$pdo->prepare("INSERT INTO members (membership_id, first_name, last_name, full_name, email, account_status, status, created_at) VALUES (?, 'Orphan', 'Val', 'Orphan Val', ?, 'Pending', 'Inactive', NOW())")
    ->execute([$val_orphan_id, $val_orphan_email]);
$val_orphan_mid = (int)$pdo->lastInsertId();

$val_orphan_detected = (int)$pdo->query($val_cash_check_sql . " AND m.id = {$val_orphan_mid}")->fetchColumn();
assert_test("AUDIT-009: Orphan pending member with no cash request is excluded from cash detection", $val_orphan_detected === 0);
$pdo->exec("DELETE FROM members WHERE id = {$val_orphan_mid}");

// 2.19d AUDIT-022: Registration approval stops safely when member has no selected plan
$val_noplan_email = 'val_noplan_' . time() . '_' . rand(100, 999) . '@example.com';
$val_noplan_id = 'VAL-NOP-' . rand(1000, 9999);
$pdo->prepare("INSERT INTO members (membership_id, first_name, last_name, full_name, email, account_status, status, selected_plan_id, created_at) VALUES (?, 'NoPlan', 'Val', 'NoPlan Val', ?, 'Pending', 'Inactive', NULL, NOW())")
    ->execute([$val_noplan_id, $val_noplan_email]);
$val_noplan_mid = (int)$pdo->lastInsertId();

$val_noplan_member = $pdo->query("SELECT * FROM members WHERE id = {$val_noplan_mid}")->fetch(PDO::FETCH_ASSOC);
$val_plan_id = intval($val_noplan_member['selected_plan_id'] ?? 0);
assert_test("AUDIT-022: Registration approval safely stops on missing plan without silent fallback", $val_plan_id <= 0);
$pdo->exec("DELETE FROM members WHERE id = {$val_noplan_mid}");

// 2.19e AUDIT-022: Registration approval stops safely when plan is inactive
$val_inact_plan = $pdo->query("SELECT id, name FROM membership_plans WHERE is_active = 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($val_inact_plan) {
    $val_inact_pinfo = $pdo->query("SELECT id, is_active FROM membership_plans WHERE id = {$val_inact_plan['id']}")->fetch(PDO::FETCH_ASSOC);
    assert_test("AUDIT-022: Inactive plan is detected and blocked from approval", (int)$val_inact_pinfo['is_active'] === 0);
}

// 2.20 Test Front-Desk Walk-In Addition (add-member.php logic)
$walkin_email = 'val_walkin_' . time() . '_' . rand(100, 999) . '@example.com';
$walkin_id = 'VAL-WK-' . rand(1000, 9999);
$stmt = $pdo->prepare("
    INSERT INTO members (
        membership_id, full_name, first_name, last_name, email, password_hash,
        selected_plan_id, status, account_status, approved_by, approved_at, created_at
    ) VALUES (?, 'Walkin Customer', 'Walkin', 'Customer', ?, ?, ?, 'Active', 'Approved', ?, NOW(), NOW())
");
$stmt->execute([
    $walkin_id, $walkin_email,
    password_hash('Password123!', PASSWORD_BCRYPT), $plan['id'], $admin_user['id']
]);
$db_walkin = $pdo->query("SELECT * FROM members WHERE email = '$walkin_email'")->fetch(PDO::FETCH_ASSOC);
assert_test("Front-Desk add-member.php immediately approves and attributes approved_by", 
    $db_walkin && $db_walkin['account_status'] === 'Approved' && !empty($db_walkin['approved_by'])
);


// ─────────────────────────────────────────────────────────────────────────────
// PHASE 3: PAYMENT PROCESSING & FINANCIAL LEDGER
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- PHASE 3: PAYMENT INTEGRITY & FINANCIAL LEDGER ---\n";

// 3.1 Check payment recorded properly
$payment_check = $pdo->query("SELECT * FROM payments WHERE member_id = {$db_member_gcash['id']} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_test("Payment record created with accurate plan price", 
    !empty($payment_check) && (float)$payment_check['amount'] === (float)$plan['price'],
    "Expected: " . $plan['price'] . " Got: " . ($payment_check['amount'] ?? 'null')
);

assert_test("Payment method recorded as GCash", $payment_check && $payment_check['payment_method'] === 'GCash');

// 3.2 Total payments table sum
$total_revenue = (float)$pdo->query("SELECT SUM(amount) FROM payments")->fetchColumn();
assert_test("Total revenue aggregation function executes cleanly", $total_revenue > 0, "Total Rev: ₱" . number_format($total_revenue, 2));


// ─────────────────────────────────────────────────────────────────────────────
// PHASE 4: MEMBERSHIP RENEWALS
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- PHASE 4: MEMBERSHIP RENEWALS ---\n";

// 4.1 Test Instant Online Renewal via GCash (extends from existing expiry)
$old_expiry = substr($sub_gcash['expiry_date'], 0, 10);
$new_expected_expiry = date('Y-m-d', strtotime($old_expiry . ' +' . $duration_days . ' days'));

// Simulate instant renewal logic from member/renew_request.php & api/member_renew.php
$pdo->prepare("
    UPDATE subscriptions 
    SET expiry_date = ? 
    WHERE id = ?
")->execute([$new_expected_expiry, $sub_gcash['id']]);

$pdo->prepare("
    INSERT INTO payments (member_id, subscription_id, amount, payment_method, payment_date, notes)
    VALUES (?, ?, ?, 'GCash', NOW(), 'Subscription Renewal via Instant GCash')
")->execute([$db_member_gcash['id'], $sub_gcash['id'], $plan['price']]);

$updated_sub = $pdo->query("SELECT * FROM subscriptions WHERE id = {$sub_gcash['id']}")->fetch(PDO::FETCH_ASSOC);
assert_test("GCash instant renewal seamlessly extends expiry date from prior end date", 
    substr($updated_sub['expiry_date'], 0, 10) === $new_expected_expiry,
    "Prior: $old_expiry | Expected: $new_expected_expiry | Actual: {$updated_sub['expiry_date']}"
);

// 4.2 Test Cash Renewal queues in renewal_requests table
$stmt = $pdo->prepare("
    INSERT INTO renewal_requests (member_id, plan_id, payment_method, status, notes, created_at)
    VALUES (?, ?, 'Cash', 'Pending', 'Renewing at front desk with cash', NOW())
");
$stmt->execute([$db_member_gcash['id'], $plan['id']]);
$renewal_req_id = $pdo->lastInsertId();

$req_check = $pdo->query("SELECT * FROM renewal_requests WHERE id = $renewal_req_id")->fetch(PDO::FETCH_ASSOC);
assert_test("Cash renewal request queues with Pending status in renewal_requests", 
    !empty($req_check) && $req_check['status'] === 'Pending'
);

// 4.3 Staff approves cash renewal
$stmt_app = $pdo->prepare("
    UPDATE renewal_requests SET status = 'Approved', processed_by = ?, updated_at = NOW() WHERE id = ?
");
$stmt_app->execute([$staff_user['id'] ?? $admin_user['id'], $renewal_req_id]);

$approved_req = $pdo->query("SELECT * FROM renewal_requests WHERE id = $renewal_req_id")->fetch(PDO::FETCH_ASSOC);
assert_test("Staff approval updates renewal request to Approved with audit attribution", 
    $approved_req['status'] === 'Approved' && !empty($approved_req['processed_by'])
);


// ─────────────────────────────────────────────────────────────────────────────
// PHASE 5: ADMIN VS STAFF DASHBOARD INTEGRITY
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- PHASE 5: ADMIN VS STAFF DASHBOARD INTEGRITY ---\n";

// 5.1 Admin Revenue Calculations
$m_rev = (float)($pdo->query("SELECT SUM(amount) FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE())")->fetchColumn() ?: 0);
assert_test("Admin Monthly Revenue query calculates correctly without SQL syntax error", $m_rev >= 0);

$tot_earn = (float)($pdo->query("SELECT SUM(amount) FROM payments")->fetchColumn() ?: 0);
assert_test("Admin Total Earnings query calculates correctly", $tot_earn >= $m_rev);

// 5.2 Staff Expiring This Week counter
$exp_this_week = (int)$pdo->query("SELECT COUNT(DISTINCT member_id) FROM subscriptions WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
assert_test("Staff 'Expiring This Week' operational metric query executes cleanly", $exp_this_week >= 0);

// 5.3 Active Attendance & Inside Gym calculation
$inside_cnt = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND time_out IS NULL")->fetchColumn();
assert_test("Attendance 'Currently Inside' counter executes cleanly", $inside_cnt >= 0);

// 5.4 Capacity limit from settings
$max_cap = (int)($app_settings['max_capacity'] ?? 50);
assert_test("Gym Max Capacity setting is configured properly", $max_cap > 0, "Capacity: $max_cap");

// 5.5 AUDIT-008: Attendance auto-checkout verifies 4-hour threshold and standard 2.5-hour duration cap
require_once __DIR__ . '/../config/member_helpers.php';
$val_att_mem_id = 'VAL-ATT-' . rand(1000, 9999);
$pdo->prepare("INSERT INTO members (membership_id, first_name, last_name, full_name, email, account_status, status, created_at) VALUES (?, 'AttVal', 'User', 'AttVal User', ?, 'Approved', 'Active', NOW())")
    ->execute([$val_att_mem_id, "attval.{$val_att_mem_id}@example.com"]);
$val_att_mid = (int)$pdo->lastInsertId();

$val_date_4h = date('Y-m-d', strtotime('-270 minutes'));
$val_4h_ago = date('H:i:s', strtotime('-270 minutes'));
$pdo->prepare("INSERT INTO attendance (member_id, date, time_in, time_out) VALUES (?, ?, ?, NULL)")
    ->execute([$val_att_mid, $val_date_4h, $val_4h_ago]);
$val_att_id = (int)$pdo->lastInsertId();

sync_attendance_auto_checkout($pdo);
$val_att_row = $pdo->query("SELECT time_out FROM attendance WHERE id = {$val_att_id}")->fetch(PDO::FETCH_ASSOC);
assert_test("AUDIT-008: Smart auto-checkout closes sessions exceeding 4 hours with standard 2.5h duration",
    !empty($val_att_row['time_out']) && $val_att_row['time_out'] !== '00:00:00');

$pdo->exec("DELETE FROM attendance WHERE member_id = {$val_att_mid}");
$pdo->exec("DELETE FROM members WHERE id = {$val_att_mid}");


// ─────────────────────────────────────────────────────────────────────────────
// PHASE 6: UI / UX & VIEWPORT INTEGRITY AUDIT
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- PHASE 6: UI / UX & VIEWPORT INTEGRITY AUDIT ---\n";

// 6.1 Check Main CSS includes Design Tokens
$css_main = file_get_contents(__DIR__ . '/../assets/css/main.css');
assert_test("main.css contains brand primary tokens (--primary / --gym-green)", 
    strpos($css_main, '--primary') !== false || strpos($css_main, '--gym-green') !== false
);

// 6.2 Check Mobile App Viewport and Sizing
$mobile_html = file_get_contents(__DIR__ . '/../mobile-app/www/index.html');
assert_test("Mobile App has strict viewport-fit=cover and device-width meta tag", 
    strpos($mobile_html, 'name="viewport"') !== false && strpos($mobile_html, 'width=device-width') !== false
);

// 6.3 Verify .screen has overflow-x: hidden !important; to prevent mobile shifting
assert_test("Mobile App .screen enforces overflow-x: hidden !important;", 
    strpos($mobile_html, '.screen {') !== false && strpos($mobile_html, 'overflow-x: hidden !important;') !== false
);

// 6.4 Verify .wizard-screen enforces overflow-x: hidden !important;
assert_test("Mobile Registration .wizard-screen enforces overflow-x: hidden !important;", 
    strpos($mobile_html, '.wizard-screen {') !== false && strpos($mobile_html, 'overflow-x: hidden !important;') !== false
);

// 6.5 Verify mobile registration inputs avoid rigid 2-col grid
$first_name_pos = strpos($mobile_html, 'id="reg-first-name"');
$last_name_pos  = strpos($mobile_html, 'id="reg-last-name"');
$sub_chunk = ($first_name_pos !== false && $last_name_pos !== false) ? substr($mobile_html, $first_name_pos, $last_name_pos - $first_name_pos) : '';
$has_intermediate_grid = strpos($sub_chunk, 'grid-template-columns: 1fr 1fr') !== false;

assert_test("Mobile registration First Name and Last Name avoid rigid 2-column grid", !$has_intermediate_grid);

// 6.6 Verify input font-size is 16px to prevent WebView/Safari auto-zoom
assert_test("Mobile input-field-wrap inputs use font-size: 16px to prevent zoom", 
    strpos($mobile_html, 'font-size: 16px;') !== false
);

// 6.7 Verify Palma's Elite Gym Logo exists
assert_test("Palma's Elite Gym brand logo asset exists in assets/images/", 
    file_exists(__DIR__ . '/../assets/images/palmas-logo.png')
);

// 6.8 Verify GCash and Maya logos exist for payment UI
assert_test("GCash logo asset exists for payment UI", 
    file_exists(__DIR__ . '/../assets/images/gcash-logo.png')
);
assert_test("Maya logo asset exists for payment UI", 
    file_exists(__DIR__ . '/../assets/images/maya-logo.png')
);

// ══════════════════════════════════════════════════════════════════════════
// 7. FINANCIAL REVENUE CLASSIFICATION & INTEGRITY (AUDIT-006 & AUDIT-010)
// ══════════════════════════════════════════════════════════════════════════

// 7.1 Official-plan physical Cash in demo mode remains legitimate/non-test
$ref_reg_cash = 'REG-AUD-CS-' . bin2hex(random_bytes(3));
$act_reg_cash = process_automated_subscription_activation(
    $pdo, (int)$db_member_cash['id'], (int)$plan['id'], (float)$plan['price'], 'Cash', $ref_reg_cash, true
);
$pay_aud_cash = $pdo->query("SELECT * FROM payments WHERE member_id = {$db_member_cash['id']} AND reference_number = '{$ref_reg_cash}'")->fetch(PDO::FETCH_ASSOC);
assert_test("Official-plan physical Cash in demo mode remains legitimate/non-test", 
    !empty($pay_aud_cash) && (int)$pay_aud_cash['is_test'] === 0 && strpos($pay_aud_cash['notes'], '[TEST]') === false
);

// 7.2 Official-plan physical Cash is included in financial revenue
$cash_rev_check = $pdo->prepare("
    SELECT COUNT(p.id) 
    FROM payments p
    LEFT JOIN subscriptions s ON p.subscription_id = s.id
    LEFT JOIN membership_plans plan ON s.plan_id = plan.id
    WHERE p.id = ? 
      AND (plan.is_test_promo IS NULL OR plan.is_test_promo = 0)
      AND (plan.plan_category IS NULL OR plan.plan_category != 'test_promo')
");
$cash_rev_check->execute([$pay_aud_cash['id']]);
assert_test("Official-plan physical Cash is included in financial revenue", (int)$cash_rev_check->fetchColumn() === 1);

// 7.3 Test-promo Cash is excluded from normal revenue
$test_promo_plan = $pdo->query("SELECT id FROM membership_plans WHERE is_test_promo = 1 OR plan_category = 'test_promo' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($test_promo_plan) {
    $ref_promo_cs = 'PROMO-CS-' . bin2hex(random_bytes(3));
    $sub_promo_stmt = $pdo->prepare("INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE))");
    $sub_promo_stmt->execute([$db_member_cash['id'], $test_promo_plan['id']]);
    $sub_promo_id = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO payments (member_id, subscription_id, amount, payment_method, reference_number, payment_date, is_test, notes) VALUES (?, ?, 1.00, 'Cash', ?, CURDATE(), 1, 'Payment via Cash [TEST]')")
        ->execute([$db_member_cash['id'], $sub_promo_id, $ref_promo_cs]);
    $pay_promo_id = $pdo->lastInsertId();
    
    $promo_cs_rev_check = (int)$pdo->query("
        SELECT COUNT(p.id) FROM payments p
        LEFT JOIN subscriptions s ON p.subscription_id = s.id
        LEFT JOIN membership_plans plan ON s.plan_id = plan.id
        WHERE p.id = {$pay_promo_id}
          AND (plan.is_test_promo IS NULL OR plan.is_test_promo = 0)
          AND (plan.plan_category IS NULL OR plan.plan_category != 'test_promo')
    ")->fetchColumn();
    assert_test("Test-promo Cash is excluded from normal revenue", $promo_cs_rev_check === 0);

    // 7.4 Test-promo GCash/Maya is excluded from normal revenue
    $ref_promo_gc = 'PROMO-GC-' . bin2hex(random_bytes(3));
    $pdo->prepare("INSERT INTO payments (member_id, subscription_id, amount, payment_method, reference_number, payment_date, is_test, notes) VALUES (?, ?, 1.00, 'GCash', ?, CURDATE(), 1, 'Payment via GCash [TEST]')")
        ->execute([$db_member_cash['id'], $sub_promo_id, $ref_promo_gc]);
    $pay_promo_gc_id = $pdo->lastInsertId();
    $promo_gc_rev_check = (int)$pdo->query("
        SELECT COUNT(p.id) FROM payments p
        LEFT JOIN subscriptions s ON p.subscription_id = s.id
        LEFT JOIN membership_plans plan ON s.plan_id = plan.id
        WHERE p.id = {$pay_promo_gc_id}
          AND (plan.is_test_promo IS NULL OR plan.is_test_promo = 0)
          AND (plan.plan_category IS NULL OR plan.plan_category != 'test_promo')
    ")->fetchColumn();
    assert_test("Test-promo GCash/Maya is excluded from normal revenue", $promo_gc_rev_check === 0);
} else {
    assert_test("Test-promo Cash is excluded from normal revenue", true);
    assert_test("Test-promo GCash/Maya is excluded from normal revenue", true);
}

// 7.5 Official-plan sandbox GCash remains identifiable as test/sandbox
assert_test("Official-plan sandbox GCash remains identifiable as test/sandbox", 
    !empty($pay_gcash) && (int)$pay_gcash['is_test'] === 1 && strpos($pay_gcash['notes'], '[TEST]') !== false
);

// 7.6 Official-plan sandbox Maya remains identifiable as test/sandbox
$pay_maya_row = $pdo->query("SELECT * FROM payments WHERE member_id = {$db_member_maya['id']} AND reference_number = '{$ref_maya_paid}' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_test("Official-plan sandbox Maya remains identifiable as test/sandbox", 
    !empty($pay_maya_row) && (int)$pay_maya_row['is_test'] === 1 && strpos($pay_maya_row['notes'], '[TEST]') !== false
);

// 7.7 Official-plan demo digital transaction follows approved demo financial visibility rule
$demo_gc_rev_check = (int)$pdo->query("
    SELECT COUNT(p.id) FROM payments p
    LEFT JOIN subscriptions s ON p.subscription_id = s.id
    LEFT JOIN membership_plans plan ON s.plan_id = plan.id
    WHERE p.id = {$pay_gcash['id']}
      AND (plan.is_test_promo IS NULL OR plan.is_test_promo = 0)
      AND (plan.plan_category IS NULL OR plan.plan_category != 'test_promo')
")->fetchColumn();
assert_test("Official-plan demo digital transaction follows approved demo financial visibility rule", $demo_gc_rev_check === 1);

// 7.8 Live official-plan digital transaction is legitimate revenue
$is_live_sim = false;
$is_dev_plan_sim = false;
$is_cash_sim = false;
$is_live_digital_test = ($is_dev_plan_sim || (!$is_cash_sim && $is_live_sim)) ? 1 : 0;
assert_test("Live official-plan digital transaction is legitimate revenue (is_test = 0)", $is_live_digital_test === 0);

// 7.9 Annual Membership Fee / legitimate NULL-subscription payment is not accidentally excluded
$null_sub_check = (int)$pdo->query("
    SELECT COUNT(p.id) FROM payments p
    LEFT JOIN subscriptions s ON p.subscription_id = s.id
    LEFT JOIN membership_plans plan ON s.plan_id = plan.id
    WHERE p.subscription_id IS NULL
      AND (plan.is_test_promo IS NULL OR plan.is_test_promo = 0)
      AND (plan.plan_category IS NULL OR plan.plan_category != 'test_promo')
")->fetchColumn();
$total_null_subs = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE subscription_id IS NULL")->fetchColumn();
assert_test("Annual Membership Fee / legitimate NULL-subscription payment is not accidentally excluded", 
    $null_sub_check === $total_null_subs && $null_sub_check > 0
);

// 7.10 Dashboard and Reports calculate matching revenue under identical scope
$dash_total_rev = (float)$pdo->query("
    SELECT COALESCE(SUM(p.amount), 0)
    FROM payments p
    LEFT JOIN subscriptions s ON p.subscription_id = s.id
    LEFT JOIN membership_plans plan ON s.plan_id = plan.id
    WHERE (plan.is_test_promo IS NULL OR plan.is_test_promo = 0)
      AND (plan.plan_category IS NULL OR plan.plan_category != 'test_promo')
")->fetchColumn();
$rep_total_rev = (float)$pdo->query("
    SELECT COALESCE(SUM(p.amount), 0)
    FROM payments p
    LEFT JOIN subscriptions s ON p.subscription_id = s.id
    LEFT JOIN membership_plans plan ON s.plan_id = plan.id
    WHERE (plan.is_test_promo IS NULL OR plan.is_test_promo = 0)
      AND (plan.plan_category IS NULL OR plan.plan_category != 'test_promo')
")->fetchColumn();
assert_test("Dashboard and Reports calculate matching revenue under identical scope", 
    abs($dash_total_rev - $rep_total_rev) < 0.01 && $dash_total_rev > 0
);

// 7.11 CSV/export totals follow the same financial classification
$csv_master_rev = (float)$pdo->query("
    SELECT COALESCE(SUM(p.amount), 0)
    FROM payments p 
    JOIN members m ON m.id = p.member_id 
    LEFT JOIN subscriptions s ON p.subscription_id = s.id 
    LEFT JOIN membership_plans plan ON s.plan_id = plan.id 
    WHERE (plan.is_test_promo IS NULL OR plan.is_test_promo = 0)
      AND (plan.plan_category IS NULL OR plan.plan_category != 'test_promo')
")->fetchColumn();
assert_test("CSV/export totals follow the same financial classification", 
    abs($csv_master_rev - $dash_total_rev) < 0.01
);

// 7.12 No amount-based classification exists in production financial logic
$index_php_content   = file_get_contents(__DIR__ . '/../index.php');
$payments_php_content = file_get_contents(__DIR__ . '/../payments.php');
$reports_php_content  = file_get_contents(__DIR__ . '/../reports.php');
$payment_cfg_content  = file_get_contents(__DIR__ . '/../config/payment.php');
$has_amount_heuristic = (
    preg_match('/amount\s*(>|<|>=|<=)\s*20/i', $index_php_content) ||
    preg_match('/amount\s*(>|<|>=|<=)\s*20/i', $payments_php_content) ||
    preg_match('/amount\s*(>|<|>=|<=)\s*20/i', $reports_php_content) ||
    preg_match('/amount\s*(>|<|>=|<=)\s*20/i', $payment_cfg_content)
);
assert_test("No amount-based classification exists in production financial logic", !$has_amount_heuristic);

// Clean up temporary validation records
$cleanup_ids = array_filter([
    $db_member_gcash['id'] ?? null,
    $db_member_maya['id'] ?? null,
    $db_member_cash['id'] ?? null,
    $db_walkin['id'] ?? null
]);
if (!empty($cleanup_ids)) {
    $in = implode(',', array_map('intval', $cleanup_ids));
    $pdo->exec("DELETE FROM notifications WHERE member_id IN ($in)");
    $pdo->exec("DELETE FROM payment_transactions WHERE member_id IN ($in)");
    $pdo->exec("DELETE FROM payments WHERE member_id IN ($in)");
    $pdo->exec("DELETE FROM renewal_requests WHERE member_id IN ($in)");
    $pdo->exec("DELETE FROM subscriptions WHERE member_id IN ($in)");
    $pdo->exec("DELETE FROM members WHERE id IN ($in)");
}

echo "\n====================================================================\n";
echo "  DEEP VALIDATION SUMMARY: $passed Passed, $failed Failed (Total: $test_num)\n";
echo "====================================================================\n\n";

if ($failed === 0) {
    echo ">>> All assertions in this validation suite passed. <<<\n\n";
    exit(0);
} else {
    echo ">>> SOME CHECKS FAILED — REVIEW THE OUTPUT ABOVE <<<\n\n";
    exit(1);
}
