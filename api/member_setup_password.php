<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/logger.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    $data = $_POST;
}

$member_id        = intval($data['member_id'] ?? 0);
$password         = trim($data['password'] ?? '');
$confirm_password = trim($data['confirm_password'] ?? '');

if ($member_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid member identifier.']);
    exit;
}

if (empty($password) || strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters long.']);
    exit;
}

if ($password !== $confirm_password) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

try {
    // Fetch member
    $stmt = $pdo->prepare("SELECT id, membership_id, full_name, email, contact_number, photo, status FROM members WHERE id = ? LIMIT 1");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }

    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $update = $pdo->prepare("UPDATE members SET password_hash = ? WHERE id = ?");
    $update->execute([$password_hash, $member_id]);

    // Create 30 days auth token
    $token = bin2hex(random_bytes(32));
    $insertToken = $pdo->prepare("INSERT INTO auth_tokens (member_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
    $insertToken->execute([$member_id, $token]);

    // Log Activity
    log_activity($pdo, 'Password Setup', "Member {$member['full_name']} ({$member['membership_id']}) completed first-time password setup", 'Auth', $member_id, $member['full_name']);

    echo json_encode([
        'success' => true,
        'message' => 'Password successfully set up! Logging you in...',
        'token' => $token,
        'member' => $member
    ]);
} catch (Exception $e) {
    error_log('Error in member_setup_password.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred while setting up your password.']);
}
