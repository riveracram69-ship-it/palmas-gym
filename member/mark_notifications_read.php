<?php
/**
 * member/mark_notifications_read.php
 * Marks all (or a specific) notification as read for the logged-in member.
 */
require_once __DIR__ . '/auth.php';
require_member_login();
header('Content-Type: application/json');

$member = current_member($pdo);
if (!$member) {
    echo json_encode(['success' => false]);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];

$all       = !empty($data['all']);
$notif_id  = intval($data['notification_id'] ?? 0);

try {
    if ($all) {
        $pdo->prepare("
            UPDATE notifications 
            SET read_status = 'Read', read_at = NOW() 
            WHERE member_id = ? AND read_status = 'Unread'
        ")->execute([$member['id']]);
    } elseif ($notif_id > 0) {
        $pdo->prepare("
            UPDATE notifications 
            SET read_status = 'Read', read_at = NOW() 
            WHERE id = ? AND member_id = ?
        ")->execute([$notif_id, $member['id']]);
    }
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false]);
}
