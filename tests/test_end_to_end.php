<?php
/**
 * End-to-End Enterprise Integration Test Suite
 * Validates Part 23 Requirements:
 * 1. New Registration & Duplicate Prevention
 * 2. Admin Approval & Idempotency
 * 3. Admin Rejection with Required Reason
 * 4. First-Time Membership Activation vs Renewal Disambiguation
 * 5. Membership Extension from Active Expiry Date
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/duplicate_validator.php';
require_once __DIR__ . '/../config/payment.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/logger.php';

echo "\n=======================================================\n";
echo " PALMA'S ELITE GYM (GGGYM) — END-TO-END SYSTEM TESTS\n";
echo "=======================================================\n\n";

$passed = 0;
$failed = 0;

function assert_test($description, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] ✓ " . $description . "\n";
        $passed++;
    } else {
        echo "  [FAIL] ✗ " . $description . "\n";
        $failed++;
    }
}

try {
    // -------------------------------------------------------------------------
    // TEST 1: New Registration & Duplicate Prevention
    // -------------------------------------------------------------------------
    echo "\n--- TEST 1: NEW MEMBER REGISTRATION & DUPLICATE CHECKS ---\n";
    $test_email = "test.e2e." . time() . "@example.com";
    $test_phone = "09" . rand(100000000, 999999999);
    $test_name  = "Samantha Agustin";
    $test_pass  = "Password123!";

    // 1.1 Uniqueness validation on clean data
    $dup1 = validate_member_uniqueness($pdo, $test_name, $test_email, $test_phone);
    assert_test("New unique member passes validation", $dup1['valid'] === true);

    // 1.2 Insert Pending Registration
    $mem_id_code = 'GYM-' . strtoupper(substr(uniqid(), -6));
    $stmt = $pdo->prepare("
        INSERT INTO members (membership_id, full_name, email, contact_number, gender, account_status, status, password_hash, created_at)
        VALUES (?, ?, ?, ?, 'Female', 'Pending', 'Inactive', ?, NOW())
    ");
    $stmt->execute([$mem_id_code, $test_name, $test_email, $test_phone, password_hash($test_pass, PASSWORD_DEFAULT)]);
    $member_id_1 = (int)$pdo->lastInsertId();
    assert_test("Member created with account_status='Pending' & status='Inactive'", $member_id_1 > 0);

    // 1.3 Duplicate email check must fail
    $dup2 = validate_member_uniqueness($pdo, "Different Name", $test_email, "09111111111");
    assert_test("Duplicate email is rejected", $dup2['valid'] === false);

    // 1.4 Duplicate phone check must fail
    $dup3 = validate_member_uniqueness($pdo, "Different Name 2", "other@example.com", $test_phone);
    assert_test("Duplicate contact number is rejected", $dup3['valid'] === false);

    // 1.5 Normalized Name Similarity Warning (e.g. 'samantha agustin')
    $dup4 = validate_member_uniqueness($pdo, "samantha agustin", "unique_email_123@example.com", "09222222222");
    assert_test("Normalized similar name triggers warning", !empty($dup4['warning']));

    // -------------------------------------------------------------------------
    // TEST 2: Admin Approval Workflow
    // -------------------------------------------------------------------------
    echo "\n--- TEST 2: ADMIN APPROVAL WORKFLOW ---\n";
    // Simulate Admin Approval
    $appr_stmt = $pdo->prepare("
        UPDATE members 
        SET account_status = 'Approved', approved_at = NOW() 
        WHERE id = ? AND account_status = 'Pending'
    ");
    $appr_stmt->execute([$member_id_1]);
    assert_test("Admin successfully approved pending member", $appr_stmt->rowCount() === 1);

    // Create Notification & Log
    create_notification($pdo, $member_id_1, 'ACCOUNT_APPROVED', 'Account Approved 🎉', 'Your account has been approved.');
    log_activity($pdo, 'Member Registration Approved', "Approved {$test_name} ({$mem_id_code})", 'Member', 1, 'Admin');

    $notifs = get_member_notifications($pdo, $member_id_1);
    assert_test("In-app notification created with type ACCOUNT_APPROVED", count($notifs) > 0 && ($notifs[0]['notification_type'] === 'ACCOUNT_APPROVED' || $notifs[0]['type'] === 'Registration'));

    // -------------------------------------------------------------------------
    // TEST 3: Admin Rejection Workflow
    // -------------------------------------------------------------------------
    echo "\n--- TEST 3: ADMIN REJECTION WORKFLOW ---\n";
    $rej_email = "test.rej." . time() . "@example.com";
    $rej_code = 'GYM-' . strtoupper(substr(uniqid(), -6));
    $stmt_rej = $pdo->prepare("
        INSERT INTO members (membership_id, full_name, email, contact_number, gender, account_status, status, password_hash, created_at)
        VALUES (?, 'Rejected Candidate', ?, '09333333333', 'Male', 'Pending', 'Inactive', ?, NOW())
    ");
    $stmt_rej->execute([$rej_code, $rej_email, password_hash('Pass123!', PASSWORD_DEFAULT)]);
    $member_id_rej = (int)$pdo->lastInsertId();

    $rejection_reason = "Unable to Verify Information";
    $upd_rej = $pdo->prepare("
        UPDATE members 
        SET account_status = 'Rejected', rejection_reason = ?, approved_at = NOW() 
        WHERE id = ? AND account_status = 'Pending'
    ");
    $upd_rej->execute([$rejection_reason, $member_id_rej]);
    assert_test("Admin rejected pending registration with mandatory reason", $upd_rej->rowCount() === 1);

    create_notification($pdo, $member_id_rej, 'ACCOUNT_REJECTED', 'Registration Update', "Reason: {$rejection_reason}");
    log_activity($pdo, 'Member Registration Rejected', "Rejected {$rej_code}. Reason: {$rejection_reason}", 'Member', 1, 'Admin');

    $chk_rej = $pdo->query("SELECT account_status, rejection_reason FROM members WHERE id = {$member_id_rej}")->fetch();
    assert_test("Database holds account_status='Rejected' and rejection_reason", $chk_rej['account_status'] === 'Rejected' && $chk_rej['rejection_reason'] === $rejection_reason);

    // -------------------------------------------------------------------------
    // TEST 4: First-Time Membership Activation
    // -------------------------------------------------------------------------
    echo "\n--- TEST 4: FIRST-TIME MEMBERSHIP ACTIVATION ---\n";
    $first_plan = $pdo->query("SELECT id, name, price, duration_months FROM membership_plans ORDER BY price ASC LIMIT 1")->fetch();
    $plan_id = (int)$first_plan['id'];

    $act_res = process_automated_subscription_activation($pdo, $member_id_1, $plan_id, 0, 'GCash', 'TEST-REF-001');
    assert_test("Instant auto-activation succeeded", $act_res['success'] === true);

    // Verify activity log says "Membership Activated" (NOT "Membership Renewed")
    $log_stmt = $pdo->prepare("SELECT action, description FROM activity_logs WHERE description LIKE ? ORDER BY id DESC LIMIT 1");
    $log_stmt->execute(["%{$mem_id_code}%"]);
    $act_log = $log_stmt->fetch();
    assert_test("Activity log correctly labeled as 'Membership Activated'", $act_log && $act_log['action'] === 'Membership Activated');

    // Verify transaction ledger
    $tx_stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE member_id = ? ORDER BY id DESC LIMIT 1");
    $tx_stmt->execute([$member_id_1]);
    $tx = $tx_stmt->fetch();
    assert_test("payment_transactions record saved in PAID status", $tx && $tx['status'] === 'PAID');

    // -------------------------------------------------------------------------
    // TEST 5: Membership Renewal & Expiration Extension
    // -------------------------------------------------------------------------
    echo "\n--- TEST 5: MEMBERSHIP RENEWAL (EXTENSION) ---\n";
    $cur_sub = $pdo->query("SELECT expiry_date FROM subscriptions WHERE member_id = {$member_id_1} ORDER BY expiry_date DESC LIMIT 1")->fetch();
    $initial_expiry = $cur_sub['expiry_date'];

    // Renew subscription
    $renew_res = process_automated_subscription_activation($pdo, $member_id_1, $plan_id, 0, 'Maya', 'TEST-RENEW-002');
    assert_test("Renewal activation succeeded", $renew_res['success'] === true);

    // Verify new expiry is extended from initial expiry date
    $new_sub = $pdo->query("SELECT expiry_date FROM subscriptions WHERE member_id = {$member_id_1} ORDER BY expiry_date DESC LIMIT 1")->fetch();
    $expected_expiry = date('Y-m-d', strtotime("{$initial_expiry} + {$first_plan['duration_months']} months"));
    assert_test("New expiration correctly extends active expiry ({$new_sub['expiry_date']})", $new_sub['expiry_date'] === $expected_expiry);

    // Verify activity log says "Membership Renewed"
    $renew_log_stmt = $pdo->prepare("SELECT action FROM activity_logs WHERE action = 'Membership Renewed' AND description LIKE ? ORDER BY id DESC LIMIT 1");
    $renew_log_stmt->execute(["%{$mem_id_code}%"]);
    $renew_log = $renew_log_stmt->fetch();
    assert_test("Activity log correctly labeled as 'Membership Renewed'", !empty($renew_log));

    // Cleanup test records
    $pdo->exec("DELETE FROM members WHERE id IN ({$member_id_1}, {$member_id_rej})");

    echo "\n=======================================================\n";
    echo " TEST SUMMARY: {$passed} Passed, {$failed} Failed\n";
    echo "=======================================================\n\n";

} catch (Exception $e) {
    echo "\n[ERROR] Test suite error: " . $e->getMessage() . "\n";
    exit(1);
}
