<?php
/**
 * api/get_notifications.php — Mobile API Endpoint for Notifications
 * 
 * Fetches all notifications from the relational `notifications` table.
 * NO synthetic/hardcoded alerts — all notifications are persisted in DB
 * so that mark-as-read operations correctly clear the unread badge.
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

    $member_id = $auth_member_id;
    $limit     = min((int)($_GET['limit'] ?? 30), 50);
    $offset    = max((int)($_GET['offset'] ?? 0), 0);

    // Fetch ALL notifications directly from the database — single source of truth.
    // The cron/daily_maintenance.php creates MEMBERSHIP_EXPIRING and MEMBERSHIP_EXPIRED
    // DB rows so that mark-all-read can clear them correctly.
    $n_stmt = $pdo->prepare("
        SELECT id, notification_type, type, title, message, read_status, sent_at, read_at, subscription_id, stage
        FROM notifications 
        WHERE member_id = ? 
        ORDER BY sent_at DESC 
        LIMIT ? OFFSET ?
    ");
    $n_stmt->bindValue(1, $member_id, PDO::PARAM_INT);
    $n_stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $n_stmt->bindValue(3, $offset, PDO::PARAM_INT);
    $n_stmt->execute();
    $db_notifs = $n_stmt->fetchAll(PDO::FETCH_ASSOC);

    $notifications = [];
    foreach ($db_notifs as $dn) {
        $notif_type = $dn['notification_type'] ?? $dn['type'] ?? 'SYSTEM';
        $icon  = 'fa-bell';
        $color = 'info';

        if (str_contains($notif_type, 'ACTIVAT') || str_contains($notif_type, 'RENEW') || str_contains($notif_type, 'PAYMENT')) {
            $icon = 'fa-circle-check'; $color = 'success';
        } elseif (str_contains($notif_type, 'EXPIR') || str_contains($notif_type, 'REJECT')) {
            $icon = 'fa-triangle-exclamation'; $color = 'danger';
        } elseif (str_contains($notif_type, 'APPROV') || str_contains($notif_type, 'Registration')) {
            $icon = 'fa-circle-check'; $color = 'success';
        }

        $is_unread = (($dn['read_status'] ?? 'Unread') === 'Unread');

        $notifications[] = [
            'id'      => 'notif_' . $dn['id'],
            'db_id'   => (int)$dn['id'],
            'type'    => $color,
            'icon'    => $icon,
            'title'   => $dn['title'] ?: ($dn['message'] ? substr($dn['message'], 0, 60) : 'Notification'),
            'message' => $dn['message'] ?: $dn['title'],
            'time'    => date('M d · h:i A', strtotime($dn['sent_at'])),
            'unread'  => $is_unread,
            'read_at' => $dn['read_at'] ?: null,
        ];
    }

    // Count unread from DB (accurate count, no synthetic inflation)
    $unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE member_id = ? AND read_status = 'Unread'");
    $unread_stmt->execute([$member_id]);
    $unread_count = (int)$unread_stmt->fetchColumn();

    echo json_encode([
        'success'       => true,
        'notifications' => $notifications,
        'unread'        => $unread_count,
        'total'         => count($notifications),
        'offset'        => $offset,
        'limit'         => $limit
    ]);

} catch (Throwable $e) {
    error_log('get_notifications error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'notifications' => [], 'unread' => 0]);
}
