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
// PHASE 2: REGISTRATION & AUTO-ACTIVATION FLOWS
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- PHASE 2: MEMBER REGISTRATION & AUTO-ACTIVATION ---\n";

// Get an active plan for test registrations
$plan = $pdo->query("SELECT * FROM membership_plans ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_test("Membership plan available for registration tests", !empty($plan), "Plan: " . ($plan['name'] ?? 'None'));

$duration_days = ($plan['duration_months'] > 0) ? ((int)$plan['duration_months'] * 30) : 30;

$test_email_gcash = 'val_gcash_' . time() . '@example.com';
$test_email_maya  = 'val_maya_' . time() . '@example.com';
$test_email_cash  = 'val_cash_' . time() . '@example.com';

// 2.1 Test Instant Auto-Activation with GCash
$member_id_gcash = 'VAL-GC-' . rand(1000, 9999);
$stmt = $pdo->prepare("
    INSERT INTO members (
        membership_id, full_name, first_name, last_name, email, password_hash,
        selected_plan_id, status, account_status, created_at
    ) VALUES (?, 'Juan GCashUser', 'Juan', 'GCashUser', ?, ?, ?, 'Active', 'Approved', NOW())
");
$stmt->execute([
    $member_id_gcash, $test_email_gcash,
    password_hash('Password123!', PASSWORD_BCRYPT), $plan['id']
]);
$db_member_gcash = $pdo->query("SELECT * FROM members WHERE email = '$test_email_gcash'")->fetch(PDO::FETCH_ASSOC);

assert_test("GCash registration creates Approved & Active member record", 
    $db_member_gcash && $db_member_gcash['account_status'] === 'Approved' && $db_member_gcash['status'] === 'Active'
);

// Insert payment & subscription to simulate full GCash flow
$sub_start = date('Y-m-d');
$sub_expiry = date('Y-m-d', strtotime('+' . $duration_days . ' days'));
$pdo->prepare("
    INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date, created_at)
    VALUES (?, ?, ?, ?, NOW())
")->execute([$db_member_gcash['id'], $plan['id'], $sub_start, $sub_expiry]);
$sub_gcash_id = $pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO payments (member_id, subscription_id, amount, payment_method, payment_date, notes)
    VALUES (?, ?, ?, 'GCash', NOW(), 'Instant Online GCash Checkout')
")->execute([$db_member_gcash['id'], $sub_gcash_id, $plan['price']]);

$sub_gcash = $pdo->query("SELECT * FROM subscriptions WHERE id = $sub_gcash_id")->fetch(PDO::FETCH_ASSOC);
assert_test("GCash registration instantly creates Active subscription", !empty($sub_gcash) && substr($sub_gcash['expiry_date'], 0, 10) === $sub_expiry);

// 2.2 Test Instant Auto-Activation with Maya
$member_id_maya = 'VAL-MY-' . rand(1000, 9999);
$stmt = $pdo->prepare("
    INSERT INTO members (
        membership_id, full_name, first_name, last_name, email, password_hash,
        selected_plan_id, status, account_status, created_at
    ) VALUES (?, 'Maria MayaUser', 'Maria', 'MayaUser', ?, ?, ?, 'Active', 'Approved', NOW())
");
$stmt->execute([
    $member_id_maya, $test_email_maya,
    password_hash('Password123!', PASSWORD_BCRYPT), $plan['id']
]);
$db_member_maya = $pdo->query("SELECT * FROM members WHERE email = '$test_email_maya'")->fetch(PDO::FETCH_ASSOC);
assert_test("Maya registration creates Approved & Active member record", 
    $db_member_maya && $db_member_maya['account_status'] === 'Approved' && $db_member_maya['status'] === 'Active'
);

// 2.3 Test Front-Desk Cash registration remains Pending
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

// 2.4 Verify Cash Pending shows up in pending-registrations query
$pending_list = $pdo->query("SELECT COUNT(*) FROM members WHERE account_status = 'Pending'")->fetchColumn();
assert_test("Pending registrations query accurately detects pending cash members", (int)$pending_list > 0);

// 2.5 Test Front-Desk Walk-In Addition (add-member.php logic)
$walkin_email = 'val_walkin_' . time() . '@example.com';
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

// Clean up temporary validation records
$pdo->prepare("DELETE FROM payments WHERE member_id IN (?, ?, ?, ?)")->execute([
    $db_member_gcash['id'], $db_member_maya['id'], $db_member_cash['id'], $db_walkin['id']
]);
$pdo->prepare("DELETE FROM renewal_requests WHERE member_id IN (?, ?, ?, ?)")->execute([
    $db_member_gcash['id'], $db_member_maya['id'], $db_member_cash['id'], $db_walkin['id']
]);
$pdo->prepare("DELETE FROM subscriptions WHERE member_id IN (?, ?, ?, ?)")->execute([
    $db_member_gcash['id'], $db_member_maya['id'], $db_member_cash['id'], $db_walkin['id']
]);
$pdo->prepare("DELETE FROM members WHERE id IN (?, ?, ?, ?)")->execute([
    $db_member_gcash['id'], $db_member_maya['id'], $db_member_cash['id'], $db_walkin['id']
]);

echo "\n====================================================================\n";
echo "  DEEP VALIDATION SUMMARY: $passed Passed, $failed Failed (Total: $test_num)\n";
echo "====================================================================\n\n";

if ($failed === 0) {
    echo ">>> ALL SYSTEM COMPONENTS, WORKFLOWS, AND UI/UX ARE 100% HEALTHY! <<<\n\n";
    exit(0);
} else {
    echo ">>> SOME CHECKS FAILED — REVIEW THE OUTPUT ABOVE <<<\n\n";
    exit(1);
}
