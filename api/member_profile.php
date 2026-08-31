<?php
/**
 * api/member_profile.php — Member Profile Management API
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/auth_middleware.php';

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: $_POST;

    $member_id      = $auth_member_id;
    $full_name      = trim($data['full_name'] ?? '');
    $contact_number = trim($data['contact_number'] ?? '');
    $old_password   = trim($data['old_password'] ?? '');
    $new_password   = trim($data['new_password'] ?? '');

    $stmt = $pdo->prepare("SELECT id, membership_id, full_name, email, contact_number, photo, google_picture, auth_provider, status, account_status, password_hash FROM members WHERE id = ?");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }

    $updates = [];
    $params  = [];

    if (!empty($full_name)) {
        $updates[] = "full_name = ?";
        $params[] = $full_name;
    }

    if (!empty($contact_number)) {
        if (!preg_match('/^09[0-9]{9}$/', $contact_number)) {
            echo json_encode(['success' => false, 'message' => 'Contact number must be 11 digits starting with 09.']);
            exit;
        }
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
        if (($member['auth_provider'] ?? '') === 'google') {
            $updates[] = "auth_provider = 'both'";
        }
    }

    if (!empty($updates)) {
        $params[] = $member_id;
        $sql = "UPDATE members SET " . implode(", ", $updates) . " WHERE id = ?";
        $upd_stmt = $pdo->prepare($sql);
        $upd_stmt->execute($params);
    }

    // Fetch updated member data
    $stmt = $pdo->prepare("SELECT id, membership_id, full_name, email, contact_number, photo, google_picture, auth_provider, status, account_status FROM members WHERE id = ?");
    $stmt->execute([$member_id]);
    $updated_member = $stmt->fetch(PDO::FETCH_ASSOC);
    $updated_member['photo'] = $updated_member['google_picture'] ?: ($updated_member['photo'] ?? null);

    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully!',
        'member'  => $updated_member
    ]);

} catch (Throwable $e) {
    error_log('API Error in member_profile.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to update profile. Please try again.']);
}
