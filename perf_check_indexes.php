<?php
// perf_check_indexes.php — Temporary diagnostic script
// Run: php perf_check_indexes.php
require_once __DIR__ . '/config/db.php';

$start_total = microtime(true);

echo "=== GGGYM DATABASE PERFORMANCE DIAGNOSTIC ===\n\n";

// 1. Table row counts
$tables = ['members','auth_tokens','attendance','payments','subscriptions','notifications','membership_plans','rate_limits','activity_logs'];
echo "[1] TABLE ROW COUNTS\n";
foreach ($tables as $t) {
    try {
        $c = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "    $t: $c rows\n";
    } catch (Exception $e) {
        echo "    $t: TABLE NOT FOUND\n";
    }
}

// 2. Index check on critical tables
echo "\n[2] INDEXES ON CRITICAL TABLES\n";
$critical = ['members','auth_tokens','attendance','subscriptions'];
foreach ($critical as $t) {
    try {
        $stmt = $pdo->query("SHOW INDEX FROM `$t`");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "    -- $t --\n";
        foreach ($rows as $r) {
            $unique = $r['Non_unique'] == 0 ? 'UNIQUE' : '      ';
            echo "       [$unique] {$r['Key_name']} → {$r['Column_name']}  (cardinality: {$r['Cardinality']})\n";
        }
    } catch (Exception $e) {
        echo "    $t: ERROR - " . $e->getMessage() . "\n";
    }
}

// 3. Measure query times for dashboard queries
echo "\n[3] QUERY EXECUTION TIMES (simulated member_id = 1)\n";
$member_id = 1;

$queries = [
    'auth_token_lookup'     => "SELECT t.member_id, m.account_status FROM auth_tokens t JOIN members m ON m.id = t.member_id WHERE t.token = 'fake_token' AND t.expires_at > NOW() LIMIT 1",
    'member_detail'         => "SELECT id, membership_id, full_name, email, contact_number, photo, status FROM members WHERE id = $member_id",
    'active_subscription'   => "SELECT expiry_date FROM subscriptions WHERE member_id = $member_id AND expiry_date >= CURDATE() ORDER BY expiry_date DESC LIMIT 1",
    'attendance_15'         => "SELECT * FROM attendance WHERE member_id = $member_id ORDER BY date DESC LIMIT 15",
    'payments_15'           => "SELECT * FROM payments WHERE member_id = $member_id ORDER BY payment_date DESC LIMIT 15",
    'plans_all'             => "SELECT id, name, price, duration_months FROM membership_plans ORDER BY price ASC",
    'notifications_10'      => "SELECT * FROM notifications WHERE member_id = $member_id ORDER BY sent_at DESC LIMIT 10",
    'auth_token_scan_full'  => "SELECT COUNT(*) FROM auth_tokens WHERE expires_at > NOW()",
];

foreach ($queries as $label => $sql) {
    $t0 = microtime(true);
    try {
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ms = round((microtime(true) - $t0) * 1000, 2);
        $count = count($rows);
        $status = $ms < 50 ? '🟢' : ($ms < 200 ? '🟡' : ($ms < 500 ? '🟠' : '🔴'));
        echo "    $status $label: {$ms}ms ({$count} rows)\n";
    } catch (Exception $e) {
        $ms = round((microtime(true) - $t0) * 1000, 2);
        echo "    ❌ $label: ERROR {$ms}ms — " . $e->getMessage() . "\n";
    }
}

// 4. Missing indexes check
echo "\n[4] MISSING INDEX RECOMMENDATIONS\n";
$missing = [];
try {
    $res = $pdo->query("SHOW INDEX FROM auth_tokens");
    $indexes = array_column($res->fetchAll(PDO::FETCH_ASSOC), 'Column_name');
    if (!in_array('token', $indexes)) $missing[] = "auth_tokens.token — MISSING INDEX (critical for auth_middleware.php)";
    if (!in_array('expires_at', $indexes)) $missing[] = "auth_tokens.expires_at — MISSING INDEX";
} catch (Exception $e) {}

try {
    $res = $pdo->query("SHOW INDEX FROM members");
    $indexes = array_column($res->fetchAll(PDO::FETCH_ASSOC), 'Column_name');
    if (!in_array('email', $indexes)) $missing[] = "members.email — MISSING INDEX (login lookup)";
    if (!in_array('membership_id', $indexes)) $missing[] = "members.membership_id — MISSING INDEX (QR scan)";
} catch (Exception $e) {}

try {
    $res = $pdo->query("SHOW INDEX FROM attendance");
    $indexes = array_column($res->fetchAll(PDO::FETCH_ASSOC), 'Column_name');
    if (!in_array('member_id', $indexes)) $missing[] = "attendance.member_id — MISSING INDEX";
} catch (Exception $e) {}

if (empty($missing)) {
    echo "    ✅ All critical indexes are present\n";
} else {
    foreach ($missing as $m) echo "    ⚠️  $m\n";
}

// 5. Total DB connection time
$total_ms = round((microtime(true) - $start_total) * 1000, 2);
echo "\n[5] TOTAL DIAGNOSTIC TIME: {$total_ms}ms\n";
echo "\n=== DONE ===\n";
