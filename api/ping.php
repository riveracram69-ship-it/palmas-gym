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

        $stmtName = $pdo->query("SHOW COLUMNS FROM members LIKE 'first_name'");
        if (!$stmtName->fetch()) {
            require_once __DIR__ . '/../migrate_split_name_photo.php';
            $migrated = true;
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
    'ts'      => time()
]);



