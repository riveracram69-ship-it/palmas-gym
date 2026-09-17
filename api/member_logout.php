<?php
/**
 * api/member_logout.php
 * ─────────────────────────────────────────────────────────────────
 * Handles secure member sign-out and server-side token revocation.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/cors.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/logger.php';

// Universal Authorization Header Extraction
$headers = function_exists('apache_request_headers')
    ? apache_request_headers()
    : (function_exists('getallheaders') ? getallheaders() : []);

$authHeader = $headers['Authorization']
    ?? $headers['authorization']
    ?? $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? null;

$token = null;
if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', trim($authHeader), $matches)) {
    $token = $matches[1];
}

if (!$token) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: $_POST;
    $token = trim($data['token'] ?? '');
}

if (!empty($token)) {
    try {
        // Find member before deleting token for activity logging
        $stmt = $pdo->prepare("
            SELECT t.member_id, m.full_name, m.membership_id 
            FROM auth_tokens t 
            LEFT JOIN members m ON m.id = t.member_id 
            WHERE t.token = ?
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Revoke token
        $del = $pdo->prepare("DELETE FROM auth_tokens WHERE token = ?");
        $del->execute([$token]);

        if ($row && !empty($row['member_id'])) {
            log_activity($pdo, 'Member Logout', "Member {$row['full_name']} ({$row['membership_id']}) signed out.", 'Auth', (int)$row['member_id'], $row['full_name']);
        }
    } catch (Throwable $e) {
        error_log('Error revoking token in member_logout.php: ' . $e->getMessage());
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Signed out successfully.'
]);
