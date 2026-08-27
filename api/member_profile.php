<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth_middleware.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) {
    $data = $_POST;
}

$member_id      = $auth_member_id;
$email          = trim($data['email'] ?? '');
$contact_number = trim($data['contact_number'] ?? '');
$old_password   = trim($data['old_password'] ?? '');
$new_password   = trim($data['new_password'] ?? '');

try {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }

    $updates = [];
    $params  = [];

    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // check unique email
        $chk = $pdo->prepare("SELECT id FROM members WHERE email = ? AND id != ?");
        $chk->execute([$email, $member_id]);
        if ($chk->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email is already in use by another member.']);
            exit;
        }
        $updates[] = "email = ?";
        $params[] = $email;
    }

    if (!empty($contact_number)) {
        $updates[] = "contact_number = ?";
        $params[] = $contact_number;
    }

    // Optional password change
    if (!empty($new_password)) {
        if (strlen($new_password) < 6) {
            echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters.']);
            exit;
        }
        if (!empty($member['password_hash']) && !password_verify($old_password, $member['password_hash'])) {
            echo json_encode(['success' => false, 'message' => 'Incorrect current password.']);
            exit;
        }
        $updates[] = "password_hash = ?";
        $params[] = password_hash($new_password, PASSWORD_DEFAULT);
    }

    if (!empty($updates)) {
        $params[] = $member_id;
        $sql = "UPDATE members SET " . implode(", ", $updates) . " WHERE id = ?";
        $upd_stmt = $pdo->prepare($sql);
        $upd_stmt->execute($params);
    }

    // Fetch updated member data
    $stmt = $pdo->prepare("SELECT id, membership_id, full_name, email, contact_number, photo, status FROM members WHERE id = ?");
    $stmt->execute([$member_id]);
    $updated_member = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully!',
        'member' => $updated_member
    ]);

} catch (Exception $e) {
    error_log('API Error in member_profile.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An internal server error occurred.']);
}
