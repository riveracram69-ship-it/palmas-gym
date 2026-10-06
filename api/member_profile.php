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
    $house_street   = trim($data['house_street'] ?? '');
    $barangay       = trim($data['barangay'] ?? '');
    $municipality   = trim($data['municipality'] ?? '');
    $province       = trim($data['province'] ?? '');
    $zip_code       = trim($data['zip_code'] ?? '');
    $address_input  = trim($data['address'] ?? '');
    $dob            = trim($data['dob'] ?? '');
    $old_password   = trim($data['old_password'] ?? '');
    $new_password   = trim($data['new_password'] ?? '');

    $stmt = $pdo->prepare("
        SELECT id, membership_id, first_name, middle_name, last_name, extension, full_name,
               name_last_changed_at,
               email, contact_number, house_street, barangay, municipality, province, zip_code, address,
               dob, age, gender, photo, google_picture, auth_provider, status, account_status, password_hash
        FROM members WHERE id = ?
    ");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Member not found.']);
        exit;
    }

    $updates = [];
    $params  = [];

    // Check if name is being changed
    $curr_fn  = trim($member['first_name'] ?? '');
    $curr_mn  = trim($member['middle_name'] ?? '');
    $curr_ln  = trim($member['last_name'] ?? '');
    $curr_ext = trim($member['extension'] ?? '');

    $has_name_input = (isset($data['first_name']) || isset($data['last_name']) || isset($data['middle_name']) || isset($data['extension']));
    
    if ($has_name_input) {
        $new_fn  = isset($data['first_name']) ? trim($data['first_name']) : $curr_fn;
        $new_mn  = isset($data['middle_name']) ? trim($data['middle_name']) : $curr_mn;
        $new_ln  = isset($data['last_name']) ? trim($data['last_name']) : $curr_ln;
        $new_ext = isset($data['extension']) ? trim($data['extension']) : $curr_ext;

        $name_changed = ($new_fn !== $curr_fn || $new_mn !== $curr_mn || $new_ln !== $curr_ln || $new_ext !== $curr_ext);

        if ($name_changed) {
            $cooldown = check_member_name_change_cooldown($member);
            if (!$cooldown['can_change']) {
                http_response_code(422);
                echo json_encode([
                    'success'       => false,
                    'message'       => $cooldown['reason'],
                    'name_cooldown' => $cooldown
                ]);
                exit;
            }

            if (empty($new_fn)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'First name cannot be empty.']);
                exit;
            }

            $computed_full_name = trim(implode(' ', array_filter([$new_fn, $new_mn, $new_ln, $new_ext])));
            if (empty($computed_full_name)) {
                $computed_full_name = $new_fn;
            }

            $updates[] = "first_name = ?";
            $params[]  = $new_fn ?: null;
            $updates[] = "middle_name = ?";
            $params[]  = $new_mn ?: null;
            $updates[] = "last_name = ?";
            $params[]  = $new_ln ?: null;
            $updates[] = "extension = ?";
            $params[]  = $new_ext ?: null;
            $updates[] = "full_name = ?";
            $params[]  = $computed_full_name;
            $updates[] = "name_last_changed_at = NOW()";
        }
    } elseif (!empty($full_name) && $full_name !== trim($member['full_name'] ?? '')) {
        $cooldown = check_member_name_change_cooldown($member);
        if (!$cooldown['can_change']) {
            http_response_code(422);
            echo json_encode([
                'success'       => false,
                'message'       => $cooldown['reason'],
                'name_cooldown' => $cooldown
            ]);
            exit;
        }
        $updates[] = "full_name = ?";
        $params[]  = $full_name;
        $updates[] = "name_last_changed_at = NOW()";
    }

    if (!empty($contact_number)) {
        if (!preg_match('/^09[0-9]{9}$/', $contact_number)) {
            echo json_encode(['success' => false, 'message' => 'Contact number must be 11 digits starting with 09.']);
            exit;
        }
        $updates[] = "contact_number = ?";
        $params[] = $contact_number;
    }

    if (isset($data['house_street']) || isset($data['barangay']) || isset($data['municipality']) || isset($data['province']) || isset($data['zip_code']) || isset($data['address'])) {
        $new_address = compose_member_address_string($house_street, $barangay, $municipality, $province, $zip_code, $address_input ?: ($member['address'] ?? ''));
        $updates[] = "house_street = ?";
        $params[]  = $house_street ?: null;
        $updates[] = "barangay = ?";
        $params[]  = $barangay ?: null;
        $updates[] = "municipality = ?";
        $params[]  = $municipality ?: null;
        $updates[] = "province = ?";
        $params[]  = $province ?: null;
        $updates[] = "zip_code = ?";
        $params[]  = $zip_code ?: null;
        $updates[] = "address = ?";
        $params[]  = $new_address ?: null;
    }

    if (!empty($dob)) {
        $age = compute_member_age($dob, !empty($member['age']) ? intval($member['age']) : null);
        $updates[] = "dob = ?";
        $params[]  = $dob;
        $updates[] = "age = ?";
        $params[]  = $age;
    }

    // Handle photo upload (multipart file or base64)
    require_once __DIR__ . '/../config/uploader.php';
    $new_photo_path = null;
    $base64_photo = $data['photo_base64'] ?? $data['photo'] ?? '';
    if (!empty($base64_photo) && is_string($base64_photo) && str_starts_with($base64_photo, 'data:image')) {
        $upload_result = secure_process_base64_image_upload($base64_photo, 'members', 600, 600);
        if (!empty($upload_result['success']) && !empty($upload_result['path'])) {
            $new_photo_path = $upload_result['path'];
        } elseif (preg_match('/^data:image\/(jpeg|png|webp|jpg);base64,/i', $base64_photo) && strlen($base64_photo) <= 2 * 1024 * 1024) {
            $new_photo_path = $base64_photo;
        } else {
            echo json_encode(['success' => false, 'message' => $upload_result['error'] ?? 'Photo upload failed.']);
            exit;
        }
    } elseif (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_result = secure_process_image_upload($_FILES['photo'], 'members', 600, 600);
        if (!empty($upload_result['success']) && !empty($upload_result['path'])) {
            $new_photo_path = $upload_result['path'];
        } else {
            echo json_encode(['success' => false, 'message' => $upload_result['error'] ?? 'Photo upload failed.']);
            exit;
        }
    }

    if ($new_photo_path) {
        if (!empty($member['photo']) && !str_starts_with($member['photo'], 'data:') && !str_starts_with($member['photo'], 'http') && file_exists(__DIR__ . '/../' . $member['photo'])) {
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
    $stmt = $pdo->prepare("
        SELECT id, membership_id, first_name, middle_name, last_name, extension, full_name,
               name_last_changed_at,
               email, contact_number, house_street, barangay, municipality, province, zip_code, address,
               dob, age, gender, photo, google_picture, auth_provider, status, account_status,
               annual_membership_expiry
        FROM members WHERE id = ?
    ");
    $stmt->execute([$member_id]);
    $updated_member = $stmt->fetch(PDO::FETCH_ASSOC);
    $updated_member['photo']             = $updated_member['photo'] ?: ($updated_member['google_picture'] ?? null);
    $updated_member['formatted_address'] = format_member_address($updated_member);
    $updated_member['formatted_dob']     = format_member_dob($updated_member['dob'] ?? null, $updated_member['age'] ?? null);
    $updated_member['computed_age']      = compute_member_age($updated_member['dob'] ?? null, $updated_member['age'] ?? null);
    $updated_member['name_cooldown']     = check_member_name_change_cooldown($updated_member);

    $ann_exp = $updated_member['annual_membership_expiry'] ?? null;
    $is_official = (!empty($ann_exp) && strtotime($ann_exp . ' 23:59:59') >= time());
    $updated_member['is_official_member'] = $is_official;
    $updated_member['membership_tier'] = $is_official ? 'Official Member' : (!empty($ann_exp) ? 'Expired Member' : 'Non-Member');
    $updated_member['annual_membership_expiry_formatted'] = !empty($ann_exp) ? date('M d, Y', strtotime($ann_exp)) : null;

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
