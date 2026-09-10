<?php
// api/ping.php — Lightweight keep-alive, health check & auto-migration endpoint
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/cors.php'; // [R-02 FIX] Replaced wildcard CORS with origin-allowlist
header('Cache-Control: no-cache, no-store, must-revalidate');

// Fast-path: instant health ping response without information_schema table locks
if (!isset($_GET['migrate'])) {
    echo json_encode([
        'success' => true,
        'message' => 'Service healthy',
        'status'  => 'ok',
        'server'  => 'palmas-gym',
        'version' => 'v2.4-fast-ping',
        'ts'      => time()
    ]);
    exit;
}

// Ensure database schema is migrated (when ?migrate=1 requested)
$migrated = false;
$migration_log = [];
try {
    require_once __DIR__ . '/../config/db.php';
    if ($pdo) {
        $stmtPlans = $pdo->query("SHOW COLUMNS FROM membership_plans LIKE 'is_test_promo'");
        $hasTestPromo = ($stmtPlans && $stmtPlans->fetch());

        $stmtMembers = $pdo->query("SHOW COLUMNS FROM members LIKE 'account_status'");
        $hasAccountStatus = ($stmtMembers && $stmtMembers->fetch());

        if (!$hasTestPromo || !$hasAccountStatus || isset($_GET['force'])) {
            define('ALLOW_INTERNAL_MIGRATION', true);
            ob_start();
            require_once __DIR__ . '/../migrate_clean_unified.php';
            require_once __DIR__ . '/../migrate_google_auth.php';
            $logOutput = ob_get_clean();
            $migrated = true;
            $migration_log = array_filter(explode("\n", trim($logOutput)));
        }
    }
} catch (Throwable $me) {
    error_log("Ping auto-migration error: " . $me->getMessage());
}

echo json_encode([
    'success' => true,
    'message' => 'Service healthy',
    'status'  => 'ok',
    'server'  => 'palmas-gym',
    'version' => 'v2.4-fast-ping',
    'schema_migrated' => $migrated,
    'migration_log'   => $migration_log,
    'ts'      => time()
]);



