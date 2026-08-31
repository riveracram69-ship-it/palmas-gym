<?php
/**
 * api/mark_notifications_read.php — Mobile API Endpoint
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/auth_middleware.php';

    $member_id = $auth_member_id;

    $stmt = $pdo->prepare("UPDATE notifications SET read_status = 'Read' WHERE member_id = ? AND read_status = 'Unread'");
    $stmt->execute([$member_id]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false]);
}
