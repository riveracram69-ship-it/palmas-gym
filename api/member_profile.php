<?php
/**
 * api/member_profile.php — Member Profile Management API
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/cors.php'; // [R-02 FIX] Replaced wildcard CORS with origin-allowlist

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
    $first_name     = trim($data['first_name'] ?? '');
    $middle_name    = trim($data['middle_name'] ?? '');
    $last_name      = trim($data['last_name'] ?? '');
    $extension      = trim($data['extension'] ?? '');
    $full_name      = trim($data['full_name'] ?? '');
    $contact_number = trim($data['contact_number'] ?? '');
    $old_password   = trim($data['old_password'] ?? '');
    $new_password   = trim($data['new_password'] ?? '');

    $stmt = $pdo->prepare("SELECT id, membership_id, first_name, middle_name, last_name, extension, full_name, email, contact_number, photo, google_picture, auth_provider, status, account_status, password_hash FROM members WHERE id = ?");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }

    $updates = [];
    $params  = [];

    if (!empty($first_name) || !empty($last_name)) {
        $full_name = trim(implode(' ', array_filter([$first_name, $middle_name, $last_name, $extension])));
        $updates[] = "first_name = ?";
        $params[]  = $first_name ?: null;
        $updates[] = "middle_name = ?";
        $params[]  = $middle_name ?: null;
        $updates[] = "last_name = ?";
        $params[]  = $last_name ?: null;
        $updates[] = "extension = ?";
        $params[]  = $extension ?: null;
        $updates[] = "full_name = ?";
        $params[]  = $full_name;
    } elseif (!empty($full_name)) {
        $updates[] = "full_name = ?";
        $params[]  = $full_name;
    }

    if (!empty($contact_number)) {
        if (!preg_match('/^09[0-9]{9}$/', $contact_number)) {
            echo json_encode(['success' => false, 'message' => 'Contact number must be 11 digits starting with 09.']);
            exit;
        }
        $updates[] = "contact_number = ?";
        $params[] = $contact_number;
    }

    // Handle photo upload (multipart file or base64)
    require_once __DIR__ . '/../config/uploader.php';
    $new_photo_path = null;
    $base64_photo = $data['photo_base64'] ?? $data['photo'] ?? '';
    if (!empty($base64_photo) && is_string($base64_photo) && str_starts_with($base64_photo, 'data:image')) {
        $upload_result = secure_process_base64_image_upload($base64_photo, 'members', 1200, 1200);
        if ($upload_result['success']) {
            $new_photo_path = $upload_result['path'];
        } else {
            echo json_encode(['success' => false, 'message' => $upload_result['error'] ?? 'Photo upload failed.']);
            exit;
        }
    } elseif (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_result = secure_process_image_upload($_FILES['photo'], 'members', 1200, 1200);
        if ($upload_result['success']) {
            $new_photo_path = $upload_result['path'];
        } else {
            echo json_encode(['success' => false, 'message' => $upload_result['error'] ?? 'Photo upload failed.']);
            exit;
        }
    }

    if ($new_photo_path) {
        if (!empty($member['photo']) && file_exists(__DIR__ . '/../' . $member['photo'])) {
            @unlink(__DIR__ . '/../' . $member['photo']);
        }
        $updates[] = "photo = ?";
        $params[]  = $new_photo_path;
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
    $stmt = $pdo->prepare("SELECT id, membership_id, first_name, middle_name, last_name, extension, full_name, email, contact_number, photo, google_picture, auth_provider, status, account_status FROM members WHERE id = ?");
    $stmt->execute([$member_id]);
    $updated_member = $stmt->fetch(PDO::FETCH_ASSOC);
    $updated_member['photo'] = $updated_member['photo'] ?: ($updated_member['google_picture'] ?? null);

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
