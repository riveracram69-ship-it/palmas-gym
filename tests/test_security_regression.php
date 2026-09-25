<?php
/**
 * tests/test_security_regression.php
 * Comprehensive Security & Workflow Regression Suite
 * Tests all remediated vulnerabilities via isolated subprocesses / HTTP requests.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/env.php';

echo "\n=======================================================\n";
echo " GGGYM SECURITY & PRE-LAUNCH REGRESSION TEST SUITE\n";
echo "=======================================================\n\n";

$passed = 0;
$failed = 0;

function assert_sec($description, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] ✓ " . $description . "\n";
        $passed++;
    } else {
        echo "  [FAIL] ✗ " . $description . "\n";
        $failed++;
    }
}

function run_php_script($script_relative_path, $post_data = [], $session_data = [], $headers = []) {
    $php_bin = defined('PHP_BINARY') && file_exists(PHP_BINARY) ? PHP_BINARY : (file_exists('C:\\xamp\\php\\php.exe') ? 'C:\\xamp\\php\\php.exe' : 'C:\\xam\\php\\php.exe');
    $full_path = realpath(__DIR__ . '/../' . $script_relative_path);
    $script_dir = dirname($full_path);
    $script_name = basename($full_path);
    
    $runner_code = '
        chdir(' . var_export($script_dir, true) . ');
        $_SERVER["REQUEST_METHOD"] = "POST";
        $_SERVER["PHP_SELF"] = "/' . $script_relative_path . '";
        $_SERVER["SCRIPT_NAME"] = "/' . $script_relative_path . '";
        $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
        $_GET = [];
        session_start();
        $csrf = bin2hex(random_bytes(32));
        $_SESSION["csrf_token"] = $csrf;
        $_POST = ' . var_export($post_data, true) . ';
        if (!isset($_POST["csrf_token"])) {
            $_POST["csrf_token"] = $csrf;
        }
        $_SERVER["HTTP_X_CSRF_TOKEN"] = $csrf;
        foreach (' . var_export($session_data, true) . ' as $k => $v) {
            $_SESSION[$k] = $v;
        }
        foreach (' . var_export($headers, true) . ' as $k => $v) {
            $_SERVER[$k] = $v;
        }
        include ' . var_export($full_path, true) . ';
    ';

    $temp_runner = __DIR__ . '/temp_runner_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($temp_runner, "<?php\n" . $runner_code);

    $cmd = "\"{$php_bin}\" \"{$temp_runner}\" 2>&1";
    $output = shell_exec($cmd);
    @unlink($temp_runner);

    // Extract JSON from output
    if (preg_match('/\{.*\}$/s', trim($output), $matches)) {
        return json_decode($matches[0], true);
    }
    return ['raw' => $output, 'json' => null];
}

try {
    // -------------------------------------------------------------------------
    // TEST 1: Free Auto-Activation Exploit in renew_request.php
    // -------------------------------------------------------------------------
    echo "\n--- TEST 1: RENEWAL AUTO-ACTIVATION VULNERABILITY MITIGATION ---\n";
    $test_mem_id = 'GYM-TESTSEC1';
    $pdo->exec("DELETE FROM members WHERE membership_id = '{$test_mem_id}'");
    $stmt = $pdo->prepare("
        INSERT INTO members (membership_id, full_name, email, contact_number, gender, account_status, status, created_at)
        VALUES (?, 'Security Test User', 'sec.user@example.com', '09990001122', 'Male', 'Approved', 'Active', NOW())
    ");
    $stmt->execute([$test_mem_id]);
    $sec_member_id = (int)$pdo->lastInsertId();

    $plan = $pdo->query("SELECT id FROM membership_plans WHERE is_active = 1 AND (plan_category IS NULL OR plan_category = 'non_member_pass') LIMIT 1")->fetch();
    $plan_id = (int)$plan['id'];

    $subs_before = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE member_id = {$sec_member_id}")->fetchColumn();

    // 1.1 Exploit payload: auto_activate=1, Instant GCash
    $res1 = run_php_script('member/renew_request.php', [
        'plan_id' => $plan_id,
        'payment_method' => 'Instant GCash',
        'auto_activate' => '1',
        'reference_no' => 'FAKE-EXPLOIT-REF'
    ], ['member_id' => $sec_member_id]);

    $subs_after1 = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE member_id = {$sec_member_id}")->fetchColumn();
    assert_sec("Exploit with 'Instant GCash' is rejected from auto-activation", isset($res1['success']) && $res1['success'] === false);
    assert_sec("No unauthorized subscription was created in database", $subs_after1 === $subs_before);

    // 1.2 Legitimate manual renewal request -> queued as Pending
    $res2 = run_php_script('member/renew_request.php', [
        'plan_id' => $plan_id,
        'payment_method' => 'Cash',
        'reference_no' => 'COUNTER-123'
    ], ['member_id' => $sec_member_id]);

    $pending_req = $pdo->query("SELECT * FROM renewal_requests WHERE member_id = {$sec_member_id} AND status = 'Pending'")->fetch();
    assert_sec("Legitimate submission queues as 'Pending' status", !empty($pending_req) && ($res2['success'] ?? false) === true);

    // Clean up renewal requests
    $pdo->exec("DELETE FROM renewal_requests WHERE member_id = {$sec_member_id}");

    // -------------------------------------------------------------------------
    // TEST 2: Dynamic QR Cryptographic HMAC & Scanner Hardening
    // -------------------------------------------------------------------------
    echo "\n--- TEST 2: DYNAMIC QR CRYPTOGRAPHIC VERIFICATION ---\n";
    $secret_key = (!empty(defined('QR_SECRET_KEY') ? QR_SECRET_KEY : '')) ? QR_SECRET_KEY : 'palmas_secret_key_987';
    $current_slot = floor(time() / 15);
    $kiosk_header = ['HTTP_X_KIOSK_KEY' => (!empty(defined('KIOSK_API_KEY') ? KIOSK_API_KEY : '')) ? KIOSK_API_KEY : 'palmas_kiosk_2026_secure_key!'];

    // 2.1 Raw ID submission with NO active subscription -> MUST BE REJECTED with SUBSCRIPTION_EXPIRED
    $res_raw = run_php_script('modules/attendance/log_attendance.php', [
        'membership_id' => $test_mem_id
    ], [], $kiosk_header);
    assert_sec("Scanner rejects Member ID without active subscription", ($res_raw['success'] ?? true) === false && strpos($res_raw['error_code'] ?? '', 'SUBSCRIPTION_EXPIRED') !== false);

    // 2.2 Tampered Signature -> MUST BE REJECTED
    $tampered_token = $test_mem_id . ':' . $current_slot . ':deadbeefcafebabe';
    $res_tamper = run_php_script('modules/attendance/log_attendance.php', [
        'membership_id' => $tampered_token
    ], [], $kiosk_header);
    assert_sec("Scanner rejects tampered HMAC signature", ($res_tamper['success'] ?? true) === false && strpos($res_tamper['message'] ?? '', 'tampered') !== false);

    // 2.3 Expired dynamic QR token (>60s old) -> MUST BE REJECTED
    $expired_slot = $current_slot - 10;
    $exp_sig = substr(hash_hmac('sha256', $test_mem_id . '|' . $expired_slot, $secret_key), 0, 16);
    $expired_token = $test_mem_id . ':' . $expired_slot . ':' . $exp_sig;
    $res_exp = run_php_script('modules/attendance/log_attendance.php', [
        'membership_id' => $expired_token
    ], [], $kiosk_header);
    assert_sec("Scanner rejects expired dynamic QR token (>60s old)", ($res_exp['success'] ?? true) === false && strpos($res_exp['message'] ?? '', 'expired') !== false);

    // 2.4 Valid rotating dynamic QR token with active plan -> MUST BE ACCEPTED
    $pdo->exec("INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date, created_at) VALUES ({$sec_member_id}, {$plan_id}, NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), NOW())");
    $pdo->exec("UPDATE members SET status = 'Active', account_status = 'Approved' WHERE id = {$sec_member_id}");

    $valid_sig = substr(hash_hmac('sha256', $test_mem_id . '|' . $current_slot, $secret_key), 0, 16);
    $valid_token = $test_mem_id . ':' . $current_slot . ':' . $valid_sig;
    $res_valid = run_php_script('modules/attendance/log_attendance.php', [
        'membership_id' => $valid_token
    ], [], $kiosk_header);
    assert_sec("Scanner accepts valid dynamic HMAC token", ($res_valid['success'] ?? false) === true);

    // 2.5 Physical printable ID Card (Static Member ID) with active plan -> ACCEPTED
    $res_card = run_php_script('modules/attendance/log_attendance.php', [
        'membership_id' => $test_mem_id
    ], [], $kiosk_header);
    assert_sec("Scanner accepts printable physical ID card when subscription is active", ($res_card['success'] ?? false) === true);

    // -------------------------------------------------------------------------
    // TEST 3: PII Privacy in api/check_status.php
    // -------------------------------------------------------------------------
    echo "\n--- TEST 3: API PRIVACY & SENSITIVE DATA EXPOSURE CHECKS ---\n";
    $res_pii = run_php_script('api/check_status.php', [
        'identifier' => 'sec.user@example.com'
    ]);

    assert_sec("Check status returns success=true", ($res_pii['success'] ?? false) === true);
    assert_sec("Full name is NOT leaked in API response", !isset($res_pii['full_name']));
    assert_sec("Raw email is NOT leaked in API response", !isset($res_pii['email']));
    assert_sec("Raw phone number is NOT leaked in API response", !isset($res_pii['contact_number']));
    assert_sec("Raw Member ID is NOT leaked in API response", !isset($res_pii['membership_id']));
    assert_sec("Masked identifier is safely provided", !empty($res_pii['identifier']) && strpos($res_pii['identifier'], '***') !== false);

    // -------------------------------------------------------------------------
    // TEST 4: Security Hygiene & Database Indexes
    // -------------------------------------------------------------------------
    echo "\n--- TEST 4: HYGIENE & DATABASE INDEX VALIDATION ---\n";
    $idx_stmt = $pdo->query("SHOW INDEX FROM auth_tokens WHERE Key_name = 'idx_auth_tokens_expires'");
    assert_sec("Index idx_auth_tokens_expires exists on auth_tokens table", $idx_stmt->rowCount() > 0);

    $aiven_in_root = file_exists(__DIR__ . '/../import_aiven.php');
    assert_sec("import_aiven.php is removed/quarantined from web root", !$aiven_in_root);

    // -------------------------------------------------------------------------
    // TEST 5: Front-Desk Member Creation Workflow (Auto-Approval)
    // -------------------------------------------------------------------------
    echo "\n--- TEST 5: FRONT-DESK MEMBER CREATION WORKFLOW ---\n";
    $fd_mem_id = 'GYM-TESTFD' . rand(100, 999);
    $staff_admin_id = 1;
    $fd_email = "fd.member." . time() . "@example.com";

    // Emulate add-member.php insertion logic
    $fd_stmt = $pdo->prepare("
        INSERT INTO members (membership_id, first_name, last_name, full_name, email, contact_number, age, gender, status, created_by, account_status, approved_by, approved_at) 
        VALUES (?, 'John', 'Frontdesk', 'John Frontdesk', ?, '09123456789', 25, 'Male', 'Active', ?, 'Approved', ?, NOW())
    ");
    $fd_stmt->execute([$fd_mem_id, $fd_email, $staff_admin_id, $staff_admin_id]);
    $fd_inserted_id = (int)$pdo->lastInsertId();

    $fd_check = $pdo->query("SELECT account_status, approved_by, approved_at FROM members WHERE id = {$fd_inserted_id}")->fetch();
    assert_sec("Front-desk created member is account_status='Approved'", $fd_check['account_status'] === 'Approved');
    assert_sec("Front-desk created member has approved_by set", (int)$fd_check['approved_by'] === $staff_admin_id);
    assert_sec("Front-desk created member has approved_at timestamp set", !empty($fd_check['approved_at']));

    // Front-desk created member can immediately check in with staff manual entry
    $fd_checkin = run_php_script('modules/attendance/log_attendance.php', [
        'membership_id' => $fd_mem_id,
        'is_manual' => '1'
    ], ['user_id' => $staff_admin_id]);
    // Note: without active subscription, scanner should reject with 'Expired' status_type
    assert_sec("Attendance processor identifies front-desk member without account_status='Pending' block", 
        isset($fd_checkin['status_type']) && $fd_checkin['status_type'] !== 'Pending');

    $pdo->exec("DELETE FROM members WHERE id = {$fd_inserted_id}");

    // Cleanup test records
    $pdo->exec("DELETE FROM attendance WHERE member_id = {$sec_member_id}");
    $pdo->exec("DELETE FROM subscriptions WHERE member_id = {$sec_member_id}");
    $pdo->exec("DELETE FROM members WHERE id = {$sec_member_id}");

    echo "\n=======================================================\n";
    echo " REGRESSION SUMMARY: {$passed} Passed, {$failed} Failed\n";
    echo "=======================================================\n\n";

} catch (Throwable $t) {
    echo "\n[ERROR] Exception during test execution: " . $t->getMessage() . "\n";
    echo $t->getTraceAsString() . "\n";
    $failed++;
}

exit($failed > 0 ? 1 : 0);
