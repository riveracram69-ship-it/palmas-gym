<?php
/**
 * modules/attendance/manual_checkout.php
 * Handles staff/admin manual check-out for members who forgot to scan out.
 */

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/logger.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure only authenticated staff/admin can execute manual checkout
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// CSRF validation
$headers = getallheaders();
$csrf_token = $_POST['csrf_token'] ?? $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrf_token) || !verify_csrf_token($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired security token. Please refresh.']);
    exit;
}

$attendance_id = intval($_POST['attendance_id'] ?? 0);
if ($attendance_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid attendance record ID.']);
    exit;
}

try {
    // Fetch record to verify existence and member name
    $stmt = $pdo->prepare("
        SELECT a.id, a.date, a.time_in, a.time_out, m.full_name, m.membership_id
        FROM attendance a
        JOIN members m ON m.id = a.member_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$attendance_id]);
    $rec = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rec) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Attendance record not found.']);
        exit;
    }

    if (!empty($rec['time_out']) && $rec['time_out'] !== '00:00:00') {
        $formatted_out = date('h:i A', strtotime($rec['time_out']));
        echo json_encode([
            'success' => true,
            'already_left' => true,
            'time_out_formatted' => $formatted_out,
            'message' => htmlspecialchars($rec['full_name']) . ' was already checked out at ' . $formatted_out . '.'
        ]);
        exit;
    }

    // Determine checkout time: If from previous day, close at 22:00:00 or +2.5 hours. If today, close NOW.
    $checkout_time = date('H:i:s');
    if ($rec['date'] < date('Y-m-d')) {
        $calc = strtotime($rec['time_in']) + 9000; // +2.5 hours
        $closing = strtotime('22:00:00');
        $checkout_time = date('H:i:s', min($calc, $closing));
    }

    $update_stmt = $pdo->prepare("UPDATE attendance SET time_out = ? WHERE id = ?");
    $update_stmt->execute([$checkout_time, $attendance_id]);

    $formatted_out = date('h:i A', strtotime($checkout_time));

    // Audit log
    $admin_user = current_user();
    $admin_name = $admin_user['name'] ?? 'Staff';
    if (function_exists('log_activity')) {
        log_activity(
            $pdo,
            'Manual Attendance Check-Out',
            "Staff {$admin_name} manually checked out member {$rec['full_name']} ({$rec['membership_id']}) at {$formatted_out}.",
            'Attendance'
        );
    }

    echo json_encode([
        'success' => true,
        'attendance_id' => $attendance_id,
        'member_name' => $rec['full_name'],
        'time_out_formatted' => $formatted_out,
        'message' => htmlspecialchars($rec['full_name']) . ' marked as Left at ' . $formatted_out . '.'
    ]);
} catch (Exception $e) {
    error_log("Manual checkout error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error while processing check-out.']);
}
