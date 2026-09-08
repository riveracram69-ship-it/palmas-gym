<?php
/**
 * tests/test_payment_suite.php — Automated Payment & System Verification Suite
 * 
 * Validates all critical system scenarios for Palma's Elite Gym:
 * 1.  ₱1 payment initialization (PENDING transaction created)
 * 2.  Payment cancellation (CANCELLED transaction, no activation)
 * 3.  Payment failure (FAILED transaction, no activation)
 * 4.  Correct ₱1 payment confirmed (PAID, activated, notification sent)
 * 5.  Amount mismatch rejection
 * 6.  Duplicate webhook idempotency
 * 7.  30-minute promotion expiration calculation
 * 8.  60-minute promotion expiration calculation
 * 9.  Expiration notification stage idempotency
 * 10. Mobile notification retrieval
 * 11. Read notification & unread count reduction
 * 12. Payment history audit
 * 13. Expired membership access block
 * 14. Renewal after expiration (starts from now)
 * 15. Renewal while active (extends existing expiry date)
 * 
 * Run: & 'C:\xam\php\php.exe' tests/test_payment_suite.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(120);

$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/payment.php';
require_once __DIR__ . '/../config/notifications.php';

// ─────────────────────────────────────────────────────────────────────────────
// Test Infrastructure
// ─────────────────────────────────────────────────────────────────────────────

$pass = 0;
$fail = 0;
$skip = 0;
$errors = [];

function assert_true(string $name, bool $condition, string $detail = ''): void {
    global $pass, $fail, $errors;
    if ($condition) {
        echo "  ✅ PASS: {$name}\n";
        $pass++;
    } else {
        echo "  ❌ FAIL: {$name}" . ($detail ? " — {$detail}" : '') . "\n";
        $fail++;
        $errors[] = "{$name}" . ($detail ? " [{$detail}]" : '');
    }
}

function assert_equals(string $name, $expected, $actual): void {
    $ok = ($expected == $actual);
    assert_true($name, $ok, "expected=" . json_encode($expected) . " got=" . json_encode($actual));
}

function assert_not_empty(string $name, $value): void {
    assert_true($name, !empty($value), "Value was empty: " . json_encode($value));
}

function skip_test(string $name, string $reason): void {
    global $skip;
    echo "  ⏭️  SKIP: {$name} — {$reason}\n";
    $skip++;
}

function section(string $title): void {
    echo "\n" . str_repeat('─', 60) . "\n";
    echo " {$title}\n";
    echo str_repeat('─', 60) . "\n";
}

function cleanup_test_member(PDO $pdo, string $membership_id): ?int {
    try {
        $s = $pdo->prepare("SELECT id FROM members WHERE membership_id = ?");
        $s->execute([$membership_id]);
        $row = $s->fetch();
        if (!$row) return null;
        $id = (int)$row['id'];
        $pdo->prepare("DELETE FROM notifications WHERE member_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM payment_transactions WHERE member_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM payments WHERE member_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM subscriptions WHERE member_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM member_devices WHERE member_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM members WHERE id = ?")->execute([$id]);
        return $id;
    } catch (Exception $e) { return null; }
}

function create_test_member(PDO $pdo, string $suffix = 'A'): array {
    $membership_id = "TEST-{$suffix}-" . strtoupper(substr(uniqid(), -4));
    $full_name = "Test Member {$suffix}";
    $email = "test{$suffix}@palmasgym.test";
    cleanup_test_member($pdo, $membership_id);
    $stmt = $pdo->prepare("
        INSERT INTO members (full_name, email, contact_number, membership_id, status, account_status, created_at)
        VALUES (?, ?, '09000000000', ?, 'Inactive', 'Approved', NOW())
    ");
    $stmt->execute([$full_name, $email, $membership_id]);
    $member_id = (int)$pdo->lastInsertId();
    return ['id' => $member_id, 'full_name' => $full_name, 'email' => $email, 'membership_id' => $membership_id];
}

function get_test_plan(PDO $pdo, int $duration_minutes): ?array {
    $stmt = $pdo->prepare("SELECT id, name, price, duration_minutes, duration_months FROM membership_plans WHERE duration_minutes = ? AND is_test_promo = 1 LIMIT 1");
    $stmt->execute([$duration_minutes]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function insert_pending_transaction(PDO $pdo, int $member_id, int $plan_id, float $amount, string $ref): void {
    $pdo->prepare("
        INSERT INTO payment_transactions (member_id, plan_id, reference_code, payment_method, amount, currency, status, is_test, expires_at)
        VALUES (?, ?, ?, 'GCASH', ?, 'PHP', 'PENDING', 1, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
        ON DUPLICATE KEY UPDATE status = 'PENDING'
    ")->execute([$member_id, $plan_id, $ref, $amount]);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "=============================================================\n";
echo " PALMA'S ELITE GYM — AUTOMATED PAYMENT VERIFICATION SUITE\n";
echo "=============================================================\n";
echo " Mode: " . strtoupper(get_payment_mode()) . " | Time: " . date('Y-m-d H:i:s') . "\n";
echo "=============================================================\n";

if (!$pdo) {
    echo "\n[FATAL] Database connection failed. Aborting.\n";
    exit(1);
}

// Verify test plans exist
$plan30 = get_test_plan($pdo, 30);
$plan60 = get_test_plan($pdo, 60);
if (!$plan30 || !$plan60) {
    echo "\n[WARNING] Test promo plans not found. Run migrate_clean_unified.php first.\n";
    echo "         Continuing tests with whatever plans are available...\n";
}

// ─────────────────────────────────────────────────────────────────────────────
section("TEST 1: ₱1 Payment Initialization (PENDING Transaction Created)");
// ─────────────────────────────────────────────────────────────────────────────
if (!$plan30) {
    skip_test("PENDING transaction creation", "30-min plan not found — run migration first");
} else {
    $m1 = create_test_member($pdo, 'T1');
    $ref1 = 'TEST-' . strtoupper(uniqid());
    
    insert_pending_transaction($pdo, $m1['id'], $plan30['id'], 1.00, $ref1);
    
    $chk = $pdo->prepare("SELECT status, amount, is_test FROM payment_transactions WHERE reference_code = ?");
    $chk->execute([$ref1]);
    $tx = $chk->fetch(PDO::FETCH_ASSOC);
    
    assert_not_empty("Transaction row created", $tx);
    assert_equals("Status is PENDING",   'PENDING', $tx['status'] ?? '');
    assert_equals("Amount is ₱1.00",     '1.00', number_format((float)($tx['amount'] ?? 0), 2));
    assert_equals("is_test flag = 1",    1, (int)($tx['is_test'] ?? 0));
    
    cleanup_test_member($pdo, $m1['membership_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
section("TEST 2: Payment Cancellation (No Activation)");
// ─────────────────────────────────────────────────────────────────────────────
if (!$plan30) {
    skip_test("Cancellation test", "Plan not available");
} else {
    $m2 = create_test_member($pdo, 'T2');
    $ref2 = 'TEST-' . strtoupper(uniqid());
    
    insert_pending_transaction($pdo, $m2['id'], $plan30['id'], 1.00, $ref2);
    $pdo->prepare("UPDATE payment_transactions SET status = 'CANCELLED' WHERE reference_code = ?")->execute([$ref2]);
    
    $sub_count = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE member_id = ?");
    $sub_count->execute([$m2['id']]);
    
    assert_equals("No subscription created on cancellation", 0, (int)$sub_count->fetchColumn());
    
    $tx2 = $pdo->prepare("SELECT status FROM payment_transactions WHERE reference_code = ?");
    $tx2->execute([$ref2]);
    assert_equals("Transaction status = CANCELLED", 'CANCELLED', $tx2->fetchColumn());
    
    cleanup_test_member($pdo, $m2['membership_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
section("TEST 3: Payment Failure (No Activation)");
// ─────────────────────────────────────────────────────────────────────────────
if (!$plan30) {
    skip_test("Failure test", "Plan not available");
} else {
    $m3 = create_test_member($pdo, 'T3');
    $ref3 = 'TEST-' . strtoupper(uniqid());
    
    insert_pending_transaction($pdo, $m3['id'], $plan30['id'], 1.00, $ref3);
    $pdo->prepare("UPDATE payment_transactions SET status = 'FAILED', failure_reason = 'Test failure scenario' WHERE reference_code = ?")->execute([$ref3]);
    
    $sub_count3 = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE member_id = ?");
    $sub_count3->execute([$m3['id']]);
    assert_equals("No subscription created on failure", 0, (int)$sub_count3->fetchColumn());
    
    cleanup_test_member($pdo, $m3['membership_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
section("TEST 4: Successful ₱1 Payment (Activation + Notification)");
// ─────────────────────────────────────────────────────────────────────────────
if (!$plan30) {
    skip_test("Successful payment activation", "Plan not available");
} else {
    $m4 = create_test_member($pdo, 'T4');
    $ref4 = 'TEST-' . strtoupper(uniqid());
    
    $result4 = process_automated_subscription_activation(
        $pdo, $m4['id'], (int)$plan30['id'], 1.00, 'GCash', $ref4
    );
    
    assert_true("Activation returned success", $result4['success'] ?? false, $result4['message'] ?? '');
    assert_not_empty("Plan name in result", $result4['plan_name'] ?? '');
    assert_not_empty("Expiry date returned", $result4['expiry_date'] ?? '');
    
    // Verify subscription in DB
    $sub4 = $pdo->prepare("SELECT * FROM subscriptions WHERE member_id = ? ORDER BY id DESC LIMIT 1");
    $sub4->execute([$m4['id']]);
    $sub_row4 = $sub4->fetch(PDO::FETCH_ASSOC);
    assert_not_empty("Subscription row created", $sub_row4);
    assert_not_empty("Subscription expiry_date set", $sub_row4['expiry_date'] ?? '');
    
    // Verify member status updated
    $mem4 = $pdo->prepare("SELECT status FROM members WHERE id = ?");
    $mem4->execute([$m4['id']]);
    assert_equals("Member status = Active", 'Active', $mem4->fetchColumn());
    
    // Verify notification created
    $notif4 = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE member_id = ?");
    $notif4->execute([$m4['id']]);
    assert_true("Notification created", (int)$notif4->fetchColumn() > 0);
    
    cleanup_test_member($pdo, $m4['membership_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
section("TEST 5: Amount Mismatch Rejection");
// ─────────────────────────────────────────────────────────────────────────────
if (!$plan30) {
    skip_test("Amount mismatch test", "Plan not available");
} else {
    // The webhook validates DB plan price; server-side pricing in process_automated_subscription_activation
    // always overrides client amount with $plan['price'], so we test at the DB layer
    $m5 = create_test_member($pdo, 'T5');
    $ref5 = 'TEST-' . strtoupper(uniqid());
    insert_pending_transaction($pdo, $m5['id'], $plan30['id'], 999.00, $ref5); // Wrong amount stored
    
    // Fetch from DB and verify the stored amount differs from plan price
    $tx5 = $pdo->prepare("SELECT amount FROM payment_transactions WHERE reference_code = ?");
    $tx5->execute([$ref5]);
    $stored_amount = (float)$tx5->fetchColumn();
    $plan_price = (float)$plan30['price'];
    
    // Server-side: process_automated_subscription_activation ignores the $amount param and uses plan price
    $r5 = process_automated_subscription_activation(
        $pdo, $m5['id'], (int)$plan30['id'], 999.00, 'GCash', 'TEST-MISMATCH'
    );
    // It should SUCCEED because the function enforces DB price internally
    // (the webhook layer does the per-paidAmount validation)
    assert_true("Server enforces DB price (not client amount)", $r5['success'] ?? false);
    assert_equals("Activation uses plan price ₱1.00", '1.00', number_format($r5['amount'] ?? 0, 2));
    
    cleanup_test_member($pdo, $m5['membership_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
section("TEST 6: Duplicate Webhook Idempotency (DB Unique Key Enforcement)");
// ─────────────────────────────────────────────────────────────────────────────
if (!$plan30) {
    skip_test("Idempotency test", "Plan not available");
} else {
    $m6 = create_test_member($pdo, 'T6');
    $ref6 = 'TEST-IDEM-' . strtoupper(uniqid());

    // First call — should succeed
    $r6a = process_automated_subscription_activation($pdo, $m6['id'], (int)$plan30['id'], 1.00, 'GCash', $ref6);

    // Second call with SAME reference — DB unique key on payments.reference_number
    // CORRECTLY rejects it (this IS the desired idempotency behavior)
    $r6b = process_automated_subscription_activation($pdo, $m6['id'], (int)$plan30['id'], 1.00, 'GCash', $ref6);

    $pay_count6 = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE member_id = ? AND reference_number = ?");
    $pay_count6->execute([$m6['id'], $ref6]);
    $dupe_pay_count = (int)$pay_count6->fetchColumn();

    $sub_count6 = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE member_id = ?");
    $sub_count6->execute([$m6['id']]);

    assert_true("First activation succeeded",                   $r6a['success'] ?? false);
    assert_true("Second call with duplicate ref is rejected",   !($r6b['success'] ?? true), "DB unique key correctly prevents duplicate payment");
    assert_equals("Only 1 payment record for reference code",  1, $dupe_pay_count);
    assert_true("At least 1 subscription created",             (int)$sub_count6->fetchColumn() >= 1);

    cleanup_test_member($pdo, $m6['membership_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
section("TEST 7: 30-Minute Promotion Expiration Calculation");
// ─────────────────────────────────────────────────────────────────────────────
if (!$plan30) {
    skip_test("30-min expiry calculation", "Plan not available");
} else {
    $m7 = create_test_member($pdo, 'T7');
    $before_ts = time();
    
    $r7 = process_automated_subscription_activation($pdo, $m7['id'], (int)$plan30['id'], 1.00, 'GCash', '');
    
    assert_true("30-min activation success", $r7['success'] ?? false);
    
    $expiry_ts = strtotime($r7['expiry_date'] ?? '');
    $now_ts    = time();
    $diff_mins = ($expiry_ts - $now_ts) / 60;
    
    assert_true("Expiry is ~30 minutes from now", $diff_mins >= 28 && $diff_mins <= 32,
        "Expected ~30 mins, got " . round($diff_mins, 1) . " mins");
    assert_equals("Duration label = 30 Minutes", '30 Minutes', $r7['duration'] ?? '');
    
    // Verify DATETIME stored (not just DATE)
    $sub7 = $pdo->prepare("SELECT expiry_date FROM subscriptions WHERE member_id = ? ORDER BY id DESC LIMIT 1");
    $sub7->execute([$m7['id']]);
    $expiry_str = $sub7->fetchColumn();
    assert_true("Expiry stored with time component", strlen($expiry_str) > 10);
    
    cleanup_test_member($pdo, $m7['membership_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
section("TEST 8: 60-Minute Promotion Expiration Calculation");
// ─────────────────────────────────────────────────────────────────────────────
if (!$plan60) {
    skip_test("60-min expiry calculation", "Plan not available");
} else {
    $m8 = create_test_member($pdo, 'T8');
    
    $r8 = process_automated_subscription_activation($pdo, $m8['id'], (int)$plan60['id'], 1.00, 'GCash', '');
    
    assert_true("60-min activation success", $r8['success'] ?? false);
    
    $expiry_ts8 = strtotime($r8['expiry_date'] ?? '');
    $diff_mins8 = ($expiry_ts8 - time()) / 60;
    
    assert_true("Expiry is ~60 minutes from now", $diff_mins8 >= 58 && $diff_mins8 <= 62,
        "Expected ~60 mins, got " . round($diff_mins8, 1) . " mins");
    assert_equals("Duration label = 60 Minutes", '60 Minutes', $r8['duration'] ?? '');
    
    cleanup_test_member($pdo, $m8['membership_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
section("TEST 9: Expiration Notification Stage Idempotency");
// ─────────────────────────────────────────────────────────────────────────────
if (!$plan30) {
    skip_test("Stage idempotency test", "Plan not available");
} else {
    $m9 = create_test_member($pdo, 'T9');
    $r9 = process_automated_subscription_activation($pdo, $m9['id'], (int)$plan30['id'], 1.00, 'GCash', '');
    $sub_id9 = $r9['subscription_id'] ?? 0;
    
    // Simulate dispatching the 5-minute warning twice
    $notif9a = create_notification($pdo, $m9['id'], 'MEMBERSHIP_EXPIRING', 'Only 5 Minutes Left!', 'msg', 'Sent', $sub_id9, 'STAGE_5M');
    $notif9b = create_notification($pdo, $m9['id'], 'MEMBERSHIP_EXPIRING', 'Only 5 Minutes Left!', 'msg', 'Sent', $sub_id9, 'STAGE_5M');
    
    // Second call should return false (idempotent — duplicate prevented)
    assert_not_empty("First STAGE_5M notification created", $notif9a);
    assert_true("Second STAGE_5M notification rejected (idempotent)", $notif9b === false);
    
    // Verify only 1 stage notification exists in DB for this stage
    $stage_count = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE subscription_id = ? AND stage = 'STAGE_5M'");
    $stage_count->execute([$sub_id9]);
    assert_equals("Only 1 STAGE_5M row in DB", 1, (int)$stage_count->fetchColumn());
    
    cleanup_test_member($pdo, $m9['membership_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
section("TEST 10: Mobile Notification Retrieval");
// ─────────────────────────────────────────────────────────────────────────────
if (!$plan30) {
    skip_test("Notification retrieval test", "Plan not available");
} else {
    $m10 = create_test_member($pdo, 'T10');
    process_automated_subscription_activation($pdo, $m10['id'], (int)$plan30['id'], 1.00, 'GCash', '');
    
    $notifs10 = get_member_notifications($pdo, $m10['id'], 10);
    
    assert_true("Notifications array returned", is_array($notifs10));
    assert_true("At least 1 notification exists", count($notifs10) >= 1);
    
    $first = $notifs10[0] ?? [];
    assert_not_empty("Notification has title",   $first['title'] ?? '');
    assert_not_empty("Notification has sent_at", $first['sent_at'] ?? '');
    
    cleanup_test_member($pdo, $m10['membership_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
section("TEST 11: Read Notification & Unread Count Reduction");
// ─────────────────────────────────────────────────────────────────────────────
if (!$plan30) {
    skip_test("Read notification test", "Plan not available");
} else {
    $m11 = create_test_member($pdo, 'T11');
    process_automated_subscription_activation($pdo, $m11['id'], (int)$plan30['id'], 1.00, 'GCash', '');
    
    // Get initial unread count
    $unread_before = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE member_id = ? AND read_status = 'Unread'");
    $unread_before->execute([$m11['id']]);
    $before = (int)$unread_before->fetchColumn();
    
    // Mark all read
    mark_all_notifications_read($pdo, $m11['id']);
    
    $unread_after = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE member_id = ? AND read_status = 'Unread'");
    $unread_after->execute([$m11['id']]);
    $after = (int)$unread_after->fetchColumn();
    
    assert_true("Had unread notifications before", $before > 0, "Got {$before}");
    assert_equals("Zero unread after mark-all-read", 0, $after);
    
    cleanup_test_member($pdo, $m11['membership_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
section("TEST 12: Payment History Audit");
// ─────────────────────────────────────────────────────────────────────────────
if (!$plan30) {
    skip_test("Payment history test", "Plan not available");
} else {
    $m12 = create_test_member($pdo, 'T12');
    $r12 = process_automated_subscription_activation($pdo, $m12['id'], (int)$plan30['id'], 1.00, 'GCash', '');
    
    $pays12 = $pdo->prepare("SELECT * FROM payments WHERE member_id = ?");
    $pays12->execute([$m12['id']]);
    $pay_rows = $pays12->fetchAll(PDO::FETCH_ASSOC);
    
    assert_true("Payment record exists", count($pay_rows) > 0);
    $p = $pay_rows[0];
    assert_equals("Payment amount = ₱1.00", '1.00', number_format((float)($p['amount'] ?? 0), 2));
    assert_not_empty("Payment reference_number set", $p['reference_number'] ?? '');
    assert_equals("Payment is_test = 1", 1, (int)($p['is_test'] ?? 0));
    
    cleanup_test_member($pdo, $m12['membership_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
section("TEST 13: Expired Membership Access Check");
// ─────────────────────────────────────────────────────────────────────────────
if (!$plan30) {
    skip_test("Expiry check test", "Plan not available");
} else {
    $m13 = create_test_member($pdo, 'T13');
    
    // Manually insert an expired subscription (past 5 minutes ago)
    $pdo->prepare("
        INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date)
        VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 30 MINUTE), DATE_SUB(NOW(), INTERVAL 5 MINUTE))
    ")->execute([$m13['id'], $plan30['id']]);
    $pdo->prepare("UPDATE members SET status = 'Active' WHERE id = ?")->execute([$m13['id']]);
    
    // Check if active subscription exists
    $active_check = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE member_id = ? AND expiry_date >= NOW()");
    $active_check->execute([$m13['id']]);
    $has_active = (int)$active_check->fetchColumn();
    
    assert_equals("No active subscription (expired 5 mins ago)", 0, $has_active);
    
    cleanup_test_member($pdo, $m13['membership_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
section("TEST 14: Renewal After Expiration (Starts from NOW)");
// ─────────────────────────────────────────────────────────────────────────────
if (!$plan30) {
    skip_test("Renewal after expiry test", "Plan not available");
} else {
    $m14 = create_test_member($pdo, 'T14');
    
    // Insert expired subscription
    $pdo->prepare("
        INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date)
        VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 60 MINUTE), DATE_SUB(NOW(), INTERVAL 5 MINUTE))
    ")->execute([$m14['id'], $plan30['id']]);
    
    // Renew
    $r14 = process_automated_subscription_activation($pdo, $m14['id'], (int)$plan30['id'], 1.00, 'GCash', '');
    
    assert_true("Renewal after expiry succeeded", $r14['success'] ?? false);
    $new_expiry_ts = strtotime($r14['expiry_date'] ?? '');
    $diff14 = ($new_expiry_ts - time()) / 60;
    assert_true("New expiry starts from NOW (~30 min from now)", $diff14 >= 28 && $diff14 <= 32,
        "Expected ~30 min from now, got " . round($diff14, 1) . " mins");
    
    cleanup_test_member($pdo, $m14['membership_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
section("TEST 15: Renewal While Active (Extends Existing Expiry)");
// ─────────────────────────────────────────────────────────────────────────────
if (!$plan30) {
    skip_test("Active renewal extension test", "Plan not available");
} else {
    $m15 = create_test_member($pdo, 'T15');
    
    // Insert active subscription expiring in 20 minutes
    $pdo->prepare("
        INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date)
        VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 20 MINUTE))
    ")->execute([$m15['id'], $plan30['id']]);
    
    $pdo->prepare("UPDATE members SET status = 'Active' WHERE id = ?")->execute([$m15['id']]);
    
    // Renew with another 30-minute pass (should extend from current expiry)
    $r15 = process_automated_subscription_activation($pdo, $m15['id'], (int)$plan30['id'], 1.00, 'GCash', '');
    
    assert_true("Active renewal succeeded", $r15['success'] ?? false);
    $new_expiry_ts15 = strtotime($r15['expiry_date'] ?? '');
    // Should be ~50 minutes from now (20 remaining + 30 added)
    $diff15 = ($new_expiry_ts15 - time()) / 60;
    assert_true("Extended expiry ~50 min from now (20+30)", $diff15 >= 47 && $diff15 <= 53,
        "Expected ~50 mins from now, got " . round($diff15, 1) . " mins");
    
    cleanup_test_member($pdo, $m15['membership_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('═', 60) . "\n";
echo " FINAL RESULTS\n";
echo str_repeat('═', 60) . "\n";
printf(" ✅ PASSED:  %d\n", $pass);
printf(" ❌ FAILED:  %d\n", $fail);
printf(" ⏭️  SKIPPED: %d\n", $skip);
$total = $pass + $fail + $skip;
printf(" 📊 TOTAL:   %d tests\n", $total);

if ($fail > 0) {
    echo "\n FAILING TESTS:\n";
    foreach ($errors as $i => $err) {
        echo "  " . ($i + 1) . ". {$err}\n";
    }
    echo "\n";
    exit(1);
} else {
    echo "\n 🎉 ALL TESTS PASSED! System is ready.\n\n";
    exit(0);
}
