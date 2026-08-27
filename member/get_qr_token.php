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
$secret_key = QR_SECRET_KEY;
$signature = hash_hmac('sha256', $member['membership_id'] . '|' . $time_slot, $secret_key);
$token = $member['membership_id'] . ':' . $time_slot . ':' . substr($signature, 0, 16);

echo json_encode(['success' => true, 'token' => $token]);
