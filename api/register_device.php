<?php
/**
 * api/register_device.php — Mobile Push Notification Device Token Registration
 * 
 * Registers or updates a member's FCM/push device token in the `member_devices` table.
 * Called by the mobile app (Capacitor/Ionic) after obtaining a device push token.
 * 
 * Method: POST
 * Auth:   Bearer token (auth_middleware.php)
 * Body:   { "device_token": "...", "device_type": "android|ios|web" }
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/cors.php'; // [R-02 FIX] Replaced wildcard CORS with origin-allowlist

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use POST.']);
    exit;
}

try {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../config/notifications.php';
    require_once __DIR__ . '/auth_middleware.php';

    $member_id = $auth_member_id;

    // Parse JSON or form body
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $device_token = trim($input['device_token'] ?? '');
    $device_type  = strtolower(trim($input['device_type'] ?? 'android'));

    if (empty($device_token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'device_token is required.']);
        exit;
    }

    // Validate device_type
    $allowed_types = ['android', 'ios', 'web'];
    if (!in_array($device_type, $allowed_types, true)) {
        $device_type = 'android';
    }

    // Register or update via notifications helper
    $ok = register_member_device($pdo, $member_id, $device_token, $device_type);

    if ($ok) {
        echo json_encode([
            'success'     => true,
            'message'     => 'Device registered successfully. Push notifications are now enabled.',
            'device_type' => $device_type
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to register device. Please try again.'
        ]);
    }

} catch (Throwable $e) {
    error_log('register_device error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
}
