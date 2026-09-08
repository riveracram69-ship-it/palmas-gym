<?php
/**
 * api/payment_detail.php
 * Authenticated Payment Details & Official Receipt API
 * 
 * Strict authorization: A member can NEVER view another member's transaction.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/cors.php'; // [R-02 FIX] Replaced wildcard CORS with origin-allowlist

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payment.php';
require_once __DIR__ . '/auth_middleware.php';

$identifier = trim($_GET['id'] ?? $_GET['ref'] ?? '');

if (empty($identifier)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing transaction identifier.']);
    exit;
}

try {
    $receipt = get_payment_receipt_details($pdo, $identifier, $auth_member_id);

    if (!$receipt) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Transaction not found or you do not have permission to view it.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'payment' => $receipt
    ]);

} catch (Throwable $e) {
    error_log('API Error in payment_detail.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to fetch transaction details.']);
}
