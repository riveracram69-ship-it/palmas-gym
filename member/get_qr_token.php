<?php
/**
 * member/get_qr_token.php
 * Generates signed, short-lived rotating token for QR code checks.
 */
require_once __DIR__ . '/auth.php';
require_member_login();

header('Content-Type: application/json');

$member = current_member($pdo);
if (!$member) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$time_slot = floor(time() / 15);
// AUDIT-003 FIX: QR secret must be configured. Fail closed if missing.
$secret_key = (defined('QR_SECRET_KEY') && is_string(QR_SECRET_KEY) && strlen(QR_SECRET_KEY) >= 20)
    ? QR_SECRET_KEY
    : null;
if ($secret_key === null) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'QR generation is temporarily unavailable. Server configuration error.']);
    exit;
}
$signature = hash_hmac('sha256', $member['membership_id'] . '|' . $time_slot, $secret_key);
$token = $member['membership_id'] . ':' . $time_slot . ':' . substr($signature, 0, 16);

echo json_encode(['success' => true, 'token' => $token]);
