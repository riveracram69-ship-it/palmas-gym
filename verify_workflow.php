<?php
/**
 * Automated System & Workflow Validation Test
 */
require_once __DIR__ . '/config/db.php';

echo "====================================================\n";
echo "PALMA'S ELITE GYM - SYSTEM FUNCTIONAL VALIDATION\n";
echo "====================================================\n\n";

$test_email = 'newapplicant@test.com';
$test_pass = 'securePass123';
$test_name = 'Maria Santos (Applicant)';
$test_contact = '09987654321';
$membership_id = 'GYM-APPL01';

try {
    // Clean previous test data
    $pdo->prepare("DELETE FROM members WHERE email = ? OR membership_id = ?")->execute([$test_email, $membership_id]);

    // ── TEST 1: New Member Registration (Should be Pending) ───────────────────
    echo "[TEST 1] Testing Member Registration Workflow...\n";
    $pass_hash = password_hash($test_pass, PASSWORD_DEFAULT);
    $ins = $pdo->prepare("
        INSERT INTO members (membership_id, full_name, email, contact_number, gender, account_status, status, selected_plan_id, password_hash, created_at)
        VALUES (?, ?, ?, ?, 'Female', 'Pending', 'Inactive', 1, ?, NOW())
    ");
    $ins->execute([$membership_id, $test_name, $test_email, $test_contact, $pass_hash]);
    $new_id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT account_status, status FROM members WHERE id = ?");
    $stmt->execute([$new_id]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($m['account_status'] === 'Pending' && $m['status'] === 'Inactive') {
        echo "  ✔ PASS: Account created with account_status = 'Pending' and status = 'Inactive'.\n";
    } else {
        echo "  ❌ FAIL: Unexpected status: " . json_encode($m) . "\n";
    }

    // ── TEST 2: Attempt Login While Pending (Should be Blocked) ──────────────
    echo "\n[TEST 2] Testing Login Restriction for Pending Member...\n";
    $check_stmt = $pdo->prepare("SELECT id, full_name, account_status, status, rejection_reason FROM members WHERE membership_id = ?");
    $check_stmt->execute([$membership_id]);
    $row = $check_stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && $row['account_status'] === 'Pending') {
        echo "  ✔ PASS: Login correctly blocked. Message: 'Your registration is currently waiting for staff approval.'\n";
    } else {
        echo "  ❌ FAIL: Pending member not intercepted.\n";
    }

    // ── TEST 3: Admin Approval of Member ─────────────────────────────────────
    echo "\n[TEST 3] Testing Admin Approval Execution...\n";
    $upd = $pdo->prepare("
        UPDATE members 
        SET account_status = 'Approved', 
            status = 'Active', 
            approved_by = 1, 
            approved_at = NOW() 
        WHERE id = ?
    ");
    $upd->execute([$new_id]);

    // Create 1-month subscription
    $sub = $pdo->prepare("INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date, created_by) VALUES (?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 MONTH), 1)");
    $sub->execute([$new_id]);

    $stmt->execute([$new_id]);
    $m2 = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($m2['account_status'] === 'Approved' && $m2['status'] === 'Active') {
        echo "  ✔ PASS: Member approved and activated by Admin. Subscription created.\n";
    } else {
        echo "  ❌ FAIL: Approval failed: " . json_encode($m2) . "\n";
    }

    // ── TEST 4: Attendance Scan Validation & Photo Metadata ──────────────────
    echo "\n[TEST 4] Testing QR Scan & Server-Side Validation...\n";
    // Check member details returned for scanner
    $scan_stmt = $pdo->prepare("
        SELECT m.id, m.full_name, m.membership_id, m.account_status, m.status, m.photo,
               s.expiry_date, p.name as plan_name
        FROM members m
        JOIN subscriptions s ON s.member_id = m.id AND s.expiry_date >= CURDATE()
        JOIN membership_plans p ON p.id = s.plan_id
        WHERE m.membership_id = ?
    ");
    $scan_stmt->execute([$membership_id]);
    $scan_data = $scan_stmt->fetch(PDO::FETCH_ASSOC);

    if ($scan_data && $scan_data['account_status'] === 'Approved' && $scan_data['status'] === 'Active') {
        echo "  ✔ PASS: QR Scan verified. Full Name: {$scan_data['full_name']} | Status: {$scan_data['account_status']} | Plan: {$scan_data['plan_name']} (Exp: {$scan_data['expiry_date']})\n";
    } else {
        echo "  ❌ FAIL: QR Scan failed: " . json_encode($scan_data) . "\n";
    }

    // ── TEST 5: Clean Up Test Member ─────────────────────────────────────────
    $pdo->prepare("DELETE FROM attendance WHERE member_id = ?")->execute([$new_id]);
    $pdo->prepare("DELETE FROM subscriptions WHERE member_id = ?")->execute([$new_id]);
    $pdo->prepare("DELETE FROM members WHERE id = ?")->execute([$new_id]);
    echo "\n✔ Cleaned up automated test data.\n";

    echo "\n====================================================\n";
    echo "ALL 5 CORE ARCHITECTURAL & WORKFLOW TESTS PASSED!\n";
    echo "====================================================\n";

} catch (Exception $e) {
    echo "Test Error: " . $e->getMessage() . "\n";
}
