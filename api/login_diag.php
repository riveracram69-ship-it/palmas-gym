<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$steps = [];
$steps[] = 'STEP-1: Request received';

try {
    require_once __DIR__ . '/../config/db.php';
    $steps[] = 'STEP-2: config/db.php loaded';
    
    if ($pdo) {
        $steps[] = 'STEP-3: PDO object created successfully';
        // Simple connectivity test
        $ping = $pdo->query("SELECT 1")->fetchColumn();
        $steps[] = "STEP-4: DB query OK — SELECT 1 = $ping";
    } else {
        $steps[] = 'STEP-3: PDO is NULL — connection failed silently';
    }
} catch (Throwable $e) {
    $steps[] = 'STEP-ERROR-DB: ' . get_class($e) . ' — ' . $e->getMessage() . ' (Line ' . $e->getLine() . ' in ' . basename($e->getFile()) . ')';
}

try {
    require_once __DIR__ . '/../config/rate_limiter.php';
    $steps[] = 'STEP-5: rate_limiter.php loaded';
    
    $rate = check_rate_limit($pdo, 'diag_test@test.com', 'api_member_login');
    $steps[] = 'STEP-6: rate_limit check OK — allowed=' . ($rate['allowed'] ? 'true' : 'false');
} catch (Throwable $e) {
    $steps[] = 'STEP-ERROR-RATE: ' . get_class($e) . ' — ' . $e->getMessage() . ' (Line ' . $e->getLine() . ' in ' . basename($e->getFile()) . ')';
}

try {
    $stmt = $pdo->prepare("
        (SELECT id, membership_id FROM members WHERE membership_id = ? LIMIT 1)
        UNION ALL
        (SELECT id, membership_id FROM members WHERE email = ? LIMIT 1)
        UNION ALL
        (SELECT id, membership_id FROM members WHERE contact_number = ? LIMIT 1)
        LIMIT 1
    ");
    $stmt->execute(['diag', 'diag@test.com', '09000000000']);
    $row = $stmt->fetch();
    $steps[] = 'STEP-7: UNION ALL member query OK — result=' . ($row ? json_encode($row) : 'no match');
} catch (Throwable $e) {
    $steps[] = 'STEP-ERROR-QUERY: ' . get_class($e) . ' — ' . $e->getMessage() . ' (Line ' . $e->getLine() . ')';
}

echo json_encode(['steps' => $steps, 'php_version' => PHP_VERSION, 'ts' => time()], JSON_PRETTY_PRINT);