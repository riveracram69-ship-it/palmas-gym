<?php
/**
 * api/check_duplicate.php
 * ─────────────────────────────────────────────────────────────────
 * Lightweight endpoint to pre-validate email and contact number
 * uniqueness in real-time before proceeding to Step 2 of registration.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/cors.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../config/duplicate_validator.php';

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: $_POST;
    if (empty($data)) {
        $data = $_GET;
    }

    $email          = trim($data['email'] ?? '');
    $contact_number = trim($data['contact_number'] ?? $data['phone'] ?? '');
    $full_name      = trim($data['full_name'] ?? '');

    if (empty($email) && empty($contact_number)) {
        echo json_encode(['valid' => true, 'message' => 'No parameters to check']);
        exit;
    }

    $dup_result = validate_member_uniqueness($pdo, $full_name, $email, $contact_number);

    if (!$dup_result['valid']) {
        echo json_encode([
            'valid'   => false,
            'message' => implode(' ', $dup_result['errors']),
            'errors'  => $dup_result['errors'],
            'warning' => $dup_result['warning'] ?? null
        ]);
        exit;
    }

    echo json_encode([
        'valid'   => true,
        'message' => 'Email and contact number are available.',
        'warning' => $dup_result['warning'] ?? null
    ]);

} catch (Throwable $e) {
    error_log("api/check_duplicate.php error: " . $e->getMessage());
    echo json_encode([
        'valid'   => true, // Do not hard block user if duplicate check service has temporary issue
        'message' => 'Check skipped due to error',
        'warning' => null
    ]);
}
