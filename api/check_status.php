<?php
/**
 * api/check_status.php
 * ─────────────────────────────────────────────────────────────────
 * Lightweight public endpoint for checking a member's registration status.
 * Used by the mobile app's "Registration Pending" screen.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

$identifier = trim($data['identifier'] ?? $data['email'] ?? $data['membership_id'] ?? '');

if (empty($identifier)) {
    echo json_encode([
        'success' => false,
        'message' => 'Email or Membership ID is required.'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, membership_id, full_name, email, account_status, status, rejection_reason
        FROM members
        WHERE email = ? OR membership_id = ? OR contact_number = ?
        LIMIT 1
    ");
    $stmt->execute([$identifier, $identifier, $identifier]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        echo json_encode([
            'success' => false,
            'message' => 'Member registration not found.'
        ]);
        exit;
    }

    $acc_status = $member['account_status'] ?? 'Approved';

    echo json_encode([
        'success'          => true,
        'account_status'   => $acc_status,
        'status'           => $member['status'],
        'full_name'        => $member['full_name'],
        'email'            => $member['email'],
        'membership_id'    => $member['membership_id'],
        'rejection_reason' => $member['rejection_reason'] ?? null
    ]);

} catch (Throwable $e) {
    error_log('check_status error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Unable to check status at this time.'
    ]);
}
