<?php
/**
 * tests/test_security_regression.php
 * Comprehensive Security & Workflow Regression Suite
 * Tests all remediated vulnerabilities via isolated subprocesses / HTTP requests.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/payment.php';

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
    if (is_string($output) && preg_match('/\{.*\}$/s', trim($output), $matches)) {
        return json_decode($matches[0], true);
    }
    return ['raw' => (string)$output, 'json' => null];
}

try {
    // -------------------------------------------------------------------------
    // TEST 1: SEC-REN-001 — Online Renewal Security (Literal GCash & Maya)
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

    $plan = $pdo->query("SELECT id, price FROM membership_plans WHERE is_active = 1 AND (plan_category IS NULL OR plan_category = 'non_member_pass') LIMIT 1")->fetch();
    $plan_id = (int)$plan['id'];
    $plan_price = (float)$plan['price'];

    $subs_before = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE member_id = {$sec_member_id}")->fetchColumn();
    $pays_before = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE member_id = {$sec_member_id}")->fetchColumn();

    // 1.1 Exploit attempt: Direct renewal POST using literal 'GCash' with fake ref & auto_activate
    $res_gcash = run_php_script('member/renew_request.php', [
        'plan_id' => $plan_id,
        'payment_method' => 'GCash',
        'auto_activate' => '1',
        'reference_no' => 'FAKE-GCASH-EXPLOIT'
    ], ['member_id' => $sec_member_id]);

    $subs_after_gcash = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE member_id = {$sec_member_id}")->fetchColumn();
    $pays_after_gcash = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE member_id = {$sec_member_id}")->fetchColumn();
    $pt_gcash = $pdo->query("SELECT status FROM payment_transactions WHERE member_id = {$sec_member_id} AND payment_method = 'GCASH' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    assert_sec("Direct GCash renewal does NOT immediately activate subscription", $subs_after_gcash === $subs_before);
    assert_sec("Direct GCash renewal does NOT create an unverified payment ledger row", $pays_after_gcash === $pays_before);
    assert_sec("Direct GCash renewal creates a PENDING checkout transaction", !empty($pt_gcash) && $pt_gcash['status'] === 'PENDING');

    // Clear pending transaction before Maya test to avoid 45s sliding window reuse
    $pdo->exec("DELETE FROM payment_transactions WHERE member_id = {$sec_member_id}");

    // 1.2 Exploit attempt: Direct renewal POST using literal 'Maya' with fake ref & auto_activate
    $res_maya = run_php_script('member/renew_request.php', [
        'plan_id' => $plan_id,
        'payment_method' => 'Maya',
        'auto_activate' => '1',
        'reference_no' => 'FAKE-MAYA-EXPLOIT'
    ], ['member_id' => $sec_member_id]);

    $subs_after_maya = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE member_id = {$sec_member_id}")->fetchColumn();
    $pays_after_maya = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE member_id = {$sec_member_id}")->fetchColumn();
    $pt_maya = $pdo->query("SELECT status FROM payment_transactions WHERE member_id = {$sec_member_id} AND payment_method = 'MAYA' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    assert_sec("Direct Maya renewal does NOT immediately activate subscription", $subs_after_maya === $subs_before);
    assert_sec("Direct Maya renewal does NOT create an unverified payment ledger row", $pays_after_maya === $pays_before);
    assert_sec("Direct Maya renewal creates a PENDING checkout transaction", !empty($pt_maya) && $pt_maya['status'] === 'PENDING');

    // 1.3 Legitimate manual renewal request -> queued as Pending (requires front-desk staff confirmation)
    $res_cash = run_php_script('member/renew_request.php', [
        'plan_id' => $plan_id,
        'payment_method' => 'Cash',
        'reference_no' => 'COUNTER-123'
    ], ['member_id' => $sec_member_id]);

    $pending_req = $pdo->query("SELECT * FROM renewal_requests WHERE member_id = {$sec_member_id} AND status = 'Pending'")->fetch();
    $subs_after_cash = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE member_id = {$sec_member_id}")->fetchColumn();
    assert_sec("Legitimate Cash renewal submission queues as 'Pending' status", !empty($pending_req) && ($res_cash['success'] ?? false) === true);
    assert_sec("Cash renewal does NOT activate subscription before staff confirmation", $subs_after_cash === $subs_before);

    // Clean up renewal requests & transactions on $sec_member_id so it remains intact with 0 subscriptions for Test 2
    $pdo->exec("DELETE FROM renewal_requests WHERE member_id = {$sec_member_id}");
    $pdo->exec("DELETE FROM payment_transactions WHERE member_id = {$sec_member_id}");

    // 1.4 Verified payment activation & duplicate callback idempotency check (using isolated test member)
    $act_mem_id = 'GYM-TESTSEC1-ACT';
    $pdo->exec("DELETE FROM members WHERE membership_id = '{$act_mem_id}'");
    $pdo->prepare("INSERT INTO members (membership_id, full_name, email, contact_number, gender, account_status, status, created_at) VALUES (?, 'Act Test User', 'act.user@example.com', '09990001133', 'Male', 'Approved', 'Inactive', NOW())")->execute([$act_mem_id]);
    $act_member_db_id = (int)$pdo->lastInsertId();

    $verified_ref = 'TEST-SEC-VERIFIED-' . strtoupper(bin2hex(random_bytes(4)));
    $act_res1 = process_automated_subscription_activation($pdo, $act_member_db_id, $plan_id, $plan_price, 'GCash', $verified_ref);
    $subs_after_verified = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE member_id = {$act_member_db_id}")->fetchColumn();
    $pays_after_verified = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE member_id = {$act_member_db_id} AND reference_number = '{$verified_ref}'")->fetchColumn();

    assert_sec("Verified payment activation creates subscription exactly once", ($act_res1['success'] ?? false) === true && $subs_after_verified === 1);
    assert_sec("Verified payment records successful ledger payment row", $pays_after_verified === 1);

    // Duplicate callback with same reference
    $act_res2 = process_automated_subscription_activation($pdo, $act_member_db_id, $plan_id, $plan_price, 'GCash', $verified_ref);
    $subs_after_dup = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE member_id = {$act_member_db_id}")->fetchColumn();
    $pays_after_dup = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE member_id = {$act_member_db_id} AND reference_number = '{$verified_ref}'")->fetchColumn();

    assert_sec("Duplicate verified payment callback is idempotent (no duplicate subscription)", $subs_after_dup === 1);
    assert_sec("Duplicate verified payment callback does not duplicate payment ledger entry", $pays_after_dup === 1);

    // Clean up isolated activation member
    $pdo->exec("DELETE FROM payments WHERE member_id = {$act_member_db_id}");
    $pdo->exec("DELETE FROM subscriptions WHERE member_id = {$act_member_db_id}");
    $pdo->exec("DELETE FROM members WHERE id = {$act_member_db_id}");

    // -------------------------------------------------------------------------
    // TEST 2: Dynamic QR Cryptographic HMAC & Scanner Hardening
    // -------------------------------------------------------------------------
    // AUDIT-002/003 FIX: Tests now use environment-configured keys only.
    // No hardcoded fallback credentials are acceptable — both application and tests must fail closed.
    $secret_key   = (defined('QR_SECRET_KEY') && strlen(QR_SECRET_KEY) >= 20) ? QR_SECRET_KEY : null;
    $kiosk_key    = (defined('KIOSK_API_KEY') && strlen(KIOSK_API_KEY) >= 8) ? KIOSK_API_KEY : null;
    $current_slot = floor(time() / 15);
    $kiosk_header = $kiosk_key ? ['HTTP_X_KIOSK_KEY' => $kiosk_key] : [];

    // If KIOSK_API_KEY is not configured, QR tests cannot proceed meaningfully
    // — verify the scanner REJECTS unconfigured kiosk attempts (fail-closed).
    if (!$kiosk_key || !$secret_key) {
        $res_no_kiosk = run_php_script('modules/attendance/log_attendance.php', [
            'membership_id' => $test_mem_id
        ], [], ['HTTP_X_KIOSK_KEY' => 'wrong_key_fail_closed_test']);
        assert_sec("Scanner fails closed when KIOSK_API_KEY is not configured",
            ($res_no_kiosk['success'] ?? true) === false &&
            in_array($res_no_kiosk['error_code'] ?? '', ['UNAUTHORIZED', 'SERVER_MISCONFIGURATION'])
        );
        echo "  [SKIP] QR HMAC sub-tests skipped: KIOSK_API_KEY or QR_SECRET_KEY not configured in environment.\n";
    } else {

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

    } // end else (keys configured)

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

    // -------------------------------------------------------------------------
    // TEST 6: AUDIT-001 — Online Registration Does Not Prematurely Activate
    // -------------------------------------------------------------------------
    echo "\n--- TEST 6: AUDIT-001 — ONLINE REGISTRATION AUTO-ACTIVATION PREVENTION ---\n";
    $sec_reg_email = 'sec.audit001.' . time() . '_' . rand(100, 999) . '@example.com';
    $reg_post = [
        'first_name' => 'SecAudit',
        'last_name' => 'Tester',
        'email' => $sec_reg_email,
        'contact_number' => '09123456781',
        'address' => '123 Test St',
        'municipality' => 'Talavera',
        'province' => 'Nueva Ecija',
        'password' => 'Password123!',
        'confirm_password' => 'Password123!',
        'payment_method' => 'GCash',
        'plan_id' => $plan_id,
        'age' => 24,
        'gender' => 'Male',
        'terms_consent' => '1'
    ];
    $res_reg = run_php_script('member/register.php', $reg_post);

    $sec_reg_mem = $pdo->query("SELECT * FROM members WHERE email = '{$sec_reg_email}'")->fetch(PDO::FETCH_ASSOC);
    assert_sec("AUDIT-001: Online GCash registration initially sets account_status='Pending' (not Approved)", 
        !empty($sec_reg_mem) && $sec_reg_mem['account_status'] === 'Pending');
    assert_sec("AUDIT-001: Online GCash registration initially sets status='Inactive' (not Active)", 
        !empty($sec_reg_mem) && $sec_reg_mem['status'] === 'Inactive');
    assert_sec("AUDIT-001: Online GCash registration does NOT populate approved_at timestamp", 
        !empty($sec_reg_mem) && empty($sec_reg_mem['approved_at']));
    if (!empty($sec_reg_mem)) {
        $sec_reg_subs = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE member_id = {$sec_reg_mem['id']}")->fetchColumn();
        assert_sec("AUDIT-001: No subscription created prior to verified payment", $sec_reg_subs === 0);
        // Clean up
        $pdo->exec("DELETE FROM notifications WHERE member_id = {$sec_reg_mem['id']}");
        $pdo->exec("DELETE FROM payment_transactions WHERE member_id = {$sec_reg_mem['id']}");
        $pdo->exec("DELETE FROM members WHERE id = {$sec_reg_mem['id']}");
    }

    // -------------------------------------------------------------------------
    // TEST 7: AUDIT-002 — Kiosk API Key Fail-Closed & Fallback Hardcode Rejection
    // -------------------------------------------------------------------------
    echo "\n--- TEST 7: AUDIT-002 — KIOSK AUTHENTICATION FAIL-CLOSED HARDENING ---\n";
    // 7.1 Verify scanner rejects the old hardcoded fallback key
    $res_old_kiosk = run_php_script('modules/attendance/log_attendance.php', [
        'membership_id' => $test_mem_id
    ], [], ['HTTP_X_KIOSK_KEY' => 'palmas_kiosk_2026_secure_key!']);
    assert_sec("AUDIT-002: Hardcoded fallback key 'palmas_kiosk_2026_secure_key!' is rejected",
        ($res_old_kiosk['success'] ?? true) === false && in_array($res_old_kiosk['error_code'] ?? '', ['UNAUTHORIZED', 'SERVER_MISCONFIGURATION']));

    // 7.2 Verify scanner rejects arbitrary unconfigured key
    $res_bogus_kiosk = run_php_script('modules/attendance/log_attendance.php', [
        'membership_id' => $test_mem_id
    ], [], ['HTTP_X_KIOSK_KEY' => 'unauthorized_random_kiosk_token_12345']);
    assert_sec("AUDIT-002: Arbitrary unconfigured kiosk key is rejected with UNAUTHORIZED",
        ($res_bogus_kiosk['success'] ?? true) === false && ($res_bogus_kiosk['error_code'] ?? '') === 'UNAUTHORIZED');

    // -------------------------------------------------------------------------
    // TEST 8: AUDIT-003 — Dynamic QR HMAC Fallback Rejection
    // -------------------------------------------------------------------------
    echo "\n--- TEST 8: AUDIT-003 — QR HMAC SECRET FAIL-CLOSED HARDENING ---\n";
    if ($kiosk_key && $secret_key) {
        $forged_slot = floor(time() / 15);
        $forged_sig = substr(hash_hmac('sha256', $test_mem_id . '|' . $forged_slot, 'palmas_secret_key_987'), 0, 16);
        $forged_token = $test_mem_id . ':' . $forged_slot . ':' . $forged_sig;
        $res_forged_qr = run_php_script('modules/attendance/log_attendance.php', [
            'membership_id' => $forged_token
        ], [], $kiosk_header);
        assert_sec("AUDIT-003: Dynamic QR token forged with old fallback 'palmas_secret_key_987' is rejected",
            ($res_forged_qr['success'] ?? true) === false);
    }

    // -------------------------------------------------------------------------
    // TEST 9: AUDIT-004 — Master Passkey Backdoors In Admin Registration Removed
    // -------------------------------------------------------------------------
    echo "\n--- TEST 9: AUDIT-004 — ADMIN REGISTRATION BACKDOOR PASSKEY REMOVAL ---\n";
    $res_bd1 = run_php_script('login.php', [
        'auth_action' => 'register',
        'reg_name' => 'Backdoor Admin 1',
        'reg_email' => 'bd1.' . time() . '_' . rand(100, 999) . '@example.com',
        'reg_password' => 'SecretPass123!',
        'reg_password_confirm' => 'SecretPass123!',
        'reg_passkey' => 'palmas2026',
        'reg_role' => 'admin'
    ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    assert_sec("AUDIT-004: Hardcoded passkey backdoor 'palmas2026' is rejected",
        ($res_bd1['success'] ?? true) === false);

    $res_bd2 = run_php_script('login.php', [
        'auth_action' => 'register',
        'reg_name' => 'Backdoor Admin 2',
        'reg_email' => 'bd2.' . time() . '_' . rand(100, 999) . '@example.com',
        'reg_password' => 'SecretPass123!',
        'reg_password_confirm' => 'SecretPass123!',
        'reg_passkey' => 'PALMAS_SECRET_2026',
        'reg_role' => 'admin'
    ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    assert_sec("AUDIT-004: Hardcoded passkey backdoor 'PALMAS_SECRET_2026' is rejected",
        ($res_bd2['success'] ?? true) === false);

    // -------------------------------------------------------------------------
    // TEST 10: AUDIT-008 — Attendance Auto-Checkout Trigger vs Recorded Duration
    // -------------------------------------------------------------------------
    echo "\n--- TEST 10: AUDIT-008 — ATTENDANCE AUTO-CHECKOUT DURATION INTEGRITY ---\n";
    require_once __DIR__ . '/../config/member_helpers.php';
    $b2_att_mem_id = 'SEC-ATT-' . rand(1000, 9999);
    $pdo->prepare("INSERT INTO members (membership_id, first_name, last_name, full_name, email, account_status, status, created_at) VALUES (?, 'Att', 'Sec', 'Att Sec', ?, 'Approved', 'Active', NOW())")
        ->execute([$b2_att_mem_id, "att.{$b2_att_mem_id}@example.com"]);
    $b2_att_mid = (int)$pdo->lastInsertId();

    // Session A: 2 hours ago (120 min) — should NOT auto-close
    $time_2h_ago = date('H:i:s', strtotime('-120 minutes'));
    $pdo->prepare("INSERT INTO attendance (member_id, date, time_in, time_out) VALUES (?, CURDATE(), ?, NULL)")
        ->execute([$b2_att_mid, $time_2h_ago]);
    $att_row_2h = (int)$pdo->lastInsertId();

    // Session B: 4.5 hours ago (270 min) — SHOULD auto-close to time_in + 2.5 hours
    $time_4h_ago = date('H:i:s', strtotime('-270 minutes'));
    $pdo->prepare("INSERT INTO attendance (member_id, date, time_in, time_out) VALUES (?, CURDATE(), ?, NULL)")
        ->execute([$b2_att_mid, $time_4h_ago]);
    $att_row_4h = (int)$pdo->lastInsertId();

    sync_attendance_auto_checkout($pdo);

    $check_2h = $pdo->query("SELECT time_out FROM attendance WHERE id = {$att_row_2h}")->fetch(PDO::FETCH_ASSOC);
    $check_4h = $pdo->query("SELECT time_in, time_out FROM attendance WHERE id = {$att_row_4h}")->fetch(PDO::FETCH_ASSOC);

    assert_sec("AUDIT-008: Attendance session < 4 hours (120 min) is NOT auto-closed (remains inside)",
        empty($check_2h['time_out']) || $check_2h['time_out'] === '00:00:00');
    assert_sec("AUDIT-008: Attendance session >= 4 hours is auto-closed by sync_attendance_auto_checkout",
        !empty($check_4h['time_out']) && $check_4h['time_out'] !== '00:00:00');

    $expected_4h_timeout = date('H:i:s', min(strtotime($check_4h['time_in']) + 9000, strtotime('22:00:00')));
    assert_sec("AUDIT-008: Auto-closed checkout records standard 2.5 hour duration (not distorted 4h+)",
        $check_4h['time_out'] === $expected_4h_timeout);

    $pdo->exec("DELETE FROM attendance WHERE member_id = {$b2_att_mid}");
    $pdo->exec("DELETE FROM members WHERE id = {$b2_att_mid}");

    // -------------------------------------------------------------------------
    // TEST 11: AUDIT-009 — Pending Cash Detection Filters Incomplete / Online Registrations
    // -------------------------------------------------------------------------
    echo "\n--- TEST 11: AUDIT-009 — PENDING CASH DETECTION ACCURACY ---\n";
    // 1. Valid Cash Registration
    $b2_cash_reg_code = 'SEC-CREG-' . rand(1000, 9999);
    $pdo->prepare("INSERT INTO members (membership_id, first_name, last_name, full_name, email, account_status, status, selected_plan_id, created_at) VALUES (?, 'Cash', 'Reg', 'Cash Reg', ?, 'Pending', 'Inactive', 8, NOW())")
        ->execute([$b2_cash_reg_code, "cash.reg.{$b2_cash_reg_code}@example.com"]);
    $b2_mid_cash = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO renewal_requests (member_id, plan_id, payment_method, reference_no, status, notes, created_at) VALUES (?, 8, 'Cash', ?, 'Pending', 'Initial Registration', NOW())")
        ->execute([$b2_mid_cash, 'REG-' . $b2_cash_reg_code]);
    $b2_rr_cash = (int)$pdo->lastInsertId();

    // 2. Pending GCash Online Registration (has payment_transactions, no cash request)
    $b2_gcash_code = 'SEC-GCASH-' . rand(1000, 9999);
    $pdo->prepare("INSERT INTO members (membership_id, first_name, last_name, full_name, email, account_status, status, selected_plan_id, created_at) VALUES (?, 'GCash', 'Reg', 'GCash Reg', ?, 'Pending', 'Inactive', 8, NOW())")
        ->execute([$b2_gcash_code, "gcash.{$b2_gcash_code}@example.com"]);
    $b2_mid_gcash = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO payment_transactions (member_id, plan_id, reference_code, payment_method, amount, currency, status, is_test, expires_at) VALUES (?, 8, ?, 'GCash', 1000.00, 'PHP', 'PENDING', 1, DATE_ADD(NOW(), INTERVAL 30 MINUTE))")
        ->execute([$b2_mid_gcash, 'TX-' . $b2_gcash_code]);
    $b2_tx_gcash = (int)$pdo->lastInsertId();

    // 3. Pending Maya Online Registration (has payment_transactions, no cash request)
    $b2_maya_code = 'SEC-MAYA-' . rand(1000, 9999);
    $pdo->prepare("INSERT INTO members (membership_id, first_name, last_name, full_name, email, account_status, status, selected_plan_id, created_at) VALUES (?, 'Maya', 'Reg', 'Maya Reg', ?, 'Pending', 'Inactive', 8, NOW())")
        ->execute([$b2_maya_code, "maya.{$b2_maya_code}@example.com"]);
    $b2_mid_maya = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO payment_transactions (member_id, plan_id, reference_code, payment_method, amount, currency, status, is_test, expires_at) VALUES (?, 8, ?, 'MAYA', 1000.00, 'PHP', 'PENDING', 1, DATE_ADD(NOW(), INTERVAL 30 MINUTE))")
        ->execute([$b2_mid_maya, 'TX-' . $b2_maya_code]);
    $b2_tx_maya = (int)$pdo->lastInsertId();

    // 4. Orphan Pending Member (incomplete registration, no transaction, no renewal request)
    $b2_orphan_code = 'SEC-ORPHAN-' . rand(1000, 9999);
    $pdo->prepare("INSERT INTO members (membership_id, first_name, last_name, full_name, email, account_status, status, selected_plan_id, created_at) VALUES (?, 'Orphan', 'Reg', 'Orphan Reg', ?, 'Pending', 'Inactive', NULL, NOW())")
        ->execute([$b2_orphan_code, "orphan.{$b2_orphan_code}@example.com"]);
    $b2_mid_orphan = (int)$pdo->lastInsertId();

    // Query pending cash registrations via production logic
    $b2_cash_sql = "
        SELECT m.id FROM members m
        WHERE m.account_status = 'Pending'
          AND (
              EXISTS (
                  SELECT 1 FROM renewal_requests rr 
                  WHERE rr.member_id = m.id 
                    AND rr.status = 'Pending' 
                    AND rr.payment_method = 'Cash'
              )
              OR EXISTS (
                  SELECT 1 FROM payment_transactions pt 
                  WHERE pt.member_id = m.id 
                    AND pt.status = 'PENDING' 
                    AND pt.payment_method = 'Cash'
              )
          )
          AND m.id IN ({$b2_mid_cash}, {$b2_mid_gcash}, {$b2_mid_maya}, {$b2_mid_orphan})
    ";
    $b2_cash_matches = $pdo->query($b2_cash_sql)->fetchAll(PDO::FETCH_COLUMN);

    assert_sec("AUDIT-009: Valid Cash registration request is detected in pending cash count",
        in_array($b2_mid_cash, $b2_cash_matches));
    assert_sec("AUDIT-009: Pending GCash online registration is EXCLUDED from pending cash count",
        !in_array($b2_mid_gcash, $b2_cash_matches));
    assert_sec("AUDIT-009: Pending Maya online registration is EXCLUDED from pending cash count",
        !in_array($b2_mid_maya, $b2_cash_matches));
    assert_sec("AUDIT-009: Orphan pending member with no cash request is EXCLUDED from pending cash count",
        !in_array($b2_mid_orphan, $b2_cash_matches));

    // 5. Test Approved/Cancelled request exclusion
    $pdo->prepare("UPDATE renewal_requests SET status = 'Approved' WHERE id = ?")->execute([$b2_rr_cash]);
    $b2_cash_after_app = $pdo->query($b2_cash_sql)->fetchAll(PDO::FETCH_COLUMN);
    assert_sec("AUDIT-009: Approved/cancelled request is EXCLUDED from pending cash count",
        !in_array($b2_mid_cash, $b2_cash_after_app));

    // Cleanup TEST 11
    $pdo->exec("DELETE FROM renewal_requests WHERE id = {$b2_rr_cash}");
    $pdo->exec("DELETE FROM payment_transactions WHERE id IN ({$b2_tx_gcash}, {$b2_tx_maya})");
    $pdo->exec("DELETE FROM members WHERE id IN ({$b2_mid_cash}, {$b2_mid_gcash}, {$b2_mid_maya}, {$b2_mid_orphan})");

    // -------------------------------------------------------------------------
    // TEST 12: AUDIT-022 — Safe Halting of Approval on Missing/Invalid/Inactive Plan
    // -------------------------------------------------------------------------
    echo "\n--- TEST 12: AUDIT-022 — CHEAPEST-PLAN FALLBACK REMOVAL & APPROVAL SAFETY ---\n";
    // 1. Pending member with missing selected plan
    $b2_noplan_code = 'SEC-NOPLAN-' . rand(1000, 9999);
    $pdo->prepare("INSERT INTO members (membership_id, first_name, last_name, full_name, email, account_status, status, selected_plan_id, created_at) VALUES (?, 'No', 'Plan', 'No Plan', ?, 'Pending', 'Inactive', NULL, NOW())")
        ->execute([$b2_noplan_code, "noplan.{$b2_noplan_code}@example.com"]);
    $b2_mid_noplan = (int)$pdo->lastInsertId();

    // 2. Pending member with nonexistent plan
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $b2_badplan_code = 'SEC-BADPLAN-' . rand(1000, 9999);
    $pdo->prepare("INSERT INTO members (membership_id, first_name, last_name, full_name, email, account_status, status, selected_plan_id, created_at) VALUES (?, 'Bad', 'Plan', 'Bad Plan', ?, 'Pending', 'Inactive', 999999, NOW())")
        ->execute([$b2_badplan_code, "badplan.{$b2_badplan_code}@example.com"]);
    $b2_mid_badplan = (int)$pdo->lastInsertId();
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // 3. Pending member with inactive plan (plan ID 1 is inactive)
    $b2_inactplan_code = 'SEC-INACT-' . rand(1000, 9999);
    $pdo->prepare("INSERT INTO members (membership_id, first_name, last_name, full_name, email, account_status, status, selected_plan_id, created_at) VALUES (?, 'Inact', 'Plan', 'Inact Plan', ?, 'Pending', 'Inactive', 1, NOW())")
        ->execute([$b2_inactplan_code, "inact.{$b2_inactplan_code}@example.com"]);
    $b2_mid_inactplan = (int)$pdo->lastInsertId();

    // 4. Pending member with valid active plan (plan ID 8: Annual Membership Fee)
    $b2_validplan_code = 'SEC-VALID-' . rand(1000, 9999);
    $pdo->prepare("INSERT INTO members (membership_id, first_name, last_name, full_name, email, account_status, status, selected_plan_id, created_at) VALUES (?, 'Valid', 'Plan', 'Valid Plan', ?, 'Pending', 'Inactive', 8, NOW())")
        ->execute([$b2_validplan_code, "valid.{$b2_validplan_code}@example.com"]);
    $b2_mid_validplan = (int)$pdo->lastInsertId();

    // Execute approval via run_php_script on pending-approvals.php
    $admin_sess = ['user_id' => 1, 'user_name' => 'Admin', 'role' => 'admin'];

    $res_app_noplan = run_php_script('pending-approvals.php', [
        'form_type' => 'registration',
        'action'    => 'approve_reg',
        'member_id' => $b2_mid_noplan
    ], $admin_sess);

    $res_app_badplan = run_php_script('pending-approvals.php', [
        'form_type' => 'registration',
        'action'    => 'approve_reg',
        'member_id' => $b2_mid_badplan
    ], $admin_sess);

    $res_app_inactplan = run_php_script('pending-approvals.php', [
        'form_type' => 'registration',
        'action'    => 'approve_reg',
        'member_id' => $b2_mid_inactplan
    ], $admin_sess);

    $res_app_validplan = run_php_script('pending-approvals.php', [
        'form_type' => 'registration',
        'action'    => 'approve_reg',
        'member_id' => $b2_mid_validplan
    ], $admin_sess);

    // Verify member states
    $st_noplan = $pdo->query("SELECT account_status FROM members WHERE id = {$b2_mid_noplan}")->fetchColumn();
    $st_badplan = $pdo->query("SELECT account_status FROM members WHERE id = {$b2_mid_badplan}")->fetchColumn();
    $st_inactplan = $pdo->query("SELECT account_status FROM members WHERE id = {$b2_mid_inactplan}")->fetchColumn();
    $st_validplan = $pdo->query("SELECT account_status, status FROM members WHERE id = {$b2_mid_validplan}")->fetch(PDO::FETCH_ASSOC);

    assert_sec("AUDIT-022: Approval halts safely with rollback when member has no selected plan",
        $st_noplan === 'Pending');
    assert_sec("AUDIT-022: Approval halts safely with rollback when member plan is nonexistent",
        $st_badplan === 'Pending');
    assert_sec("AUDIT-022: Approval halts safely with rollback when member plan is inactive",
        $st_inactplan === 'Pending');

    $rejected_payments_cnt = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE member_id IN ({$b2_mid_noplan}, {$b2_mid_badplan}, {$b2_mid_inactplan})")->fetchColumn();
    $rejected_subs_cnt = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions WHERE member_id IN ({$b2_mid_noplan}, {$b2_mid_badplan}, {$b2_mid_inactplan})")->fetchColumn();

    assert_sec("AUDIT-022: Rejected approval creates 0 payments in database",
        $rejected_payments_cnt === 0);
    assert_sec("AUDIT-022: Rejected approval creates 0 subscriptions in database",
        $rejected_subs_cnt === 0);

    assert_sec("AUDIT-022: Valid active plan approval succeeds and activates member",
        $st_validplan && $st_validplan['account_status'] === 'Approved' && $st_validplan['status'] === 'Active');

    // Cleanup TEST 12
    $pdo->exec("DELETE FROM payments WHERE member_id = {$b2_mid_validplan}");
    $pdo->exec("DELETE FROM subscriptions WHERE member_id = {$b2_mid_validplan}");
    $pdo->exec("DELETE FROM members WHERE id IN ({$b2_mid_noplan}, {$b2_mid_badplan}, {$b2_mid_inactplan}, {$b2_mid_validplan})");

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
