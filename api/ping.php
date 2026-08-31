<?php
// api/ping.php — Lightweight keep-alive, health check & auto-migration endpoint
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Ensure database schema is migrated
$migrated = false;
try {
    require_once __DIR__ . '/../config/db.php';
    if ($pdo) {
        $stmt = $pdo->query("SHOW COLUMNS FROM members LIKE 'account_status'");
        if (!$stmt->fetch()) {
            // Self-heal: missing column detected, run migration
            require_once __DIR__ . '/../migrate_system_v2.php';
            require_once __DIR__ . '/../migrate_google_auth.php';
            $migrated = true;
        }
    }
} catch (Throwable $me) {
    error_log("Ping auto-migration error: " . $me->getMessage());
}

echo json_encode([
    'status' => 'ok',
    'server' => 'palmas-gym',
    'version' => 'v2.3-migrations-active',
    'schema_migrated' => $migrated,
    'ts' => time()
]);



