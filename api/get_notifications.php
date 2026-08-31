<?php
/**
 * api/get_notifications.php — Mobile API Endpoint for Notifications
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/auth_middleware.php';

    $member_id = $auth_member_id;
    $notifications = [];
    $alerts = [];

    // 1. Fetch Member Expiry Alert
    $m_stmt = $pdo->prepare("SELECT s.expiry_date FROM members m LEFT JOIN subscriptions s ON s.member_id = m.id WHERE m.id = ? ORDER BY s.expiry_date DESC LIMIT 1");
    $m_stmt->execute([$member_id]);
    $m_sub = $m_stmt->fetch(PDO::FETCH_ASSOC);

    if ($m_sub && !empty($m_sub['expiry_date'])) {
        $days_left = (int) round((strtotime($m_sub['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
        if ($days_left < 0) {
            $alerts[] = [
                'id'      => 'expiry_now',
                'type'    => 'danger',
                'icon'    => 'fa-circle-xmark',
                'title'   => 'Membership Expired',
                'message' => 'Your membership has expired. Please renew to continue gym access.',
                'time'    => 'Just now',
                'unread'  => true,
            ];
        } elseif ($days_left <= 3) {
            $alerts[] = [
                'id'      => 'expiry_3d',
                'type'    => 'danger',
                'icon'    => 'fa-triangle-exclamation',
                'title'   => "Expiring in {$days_left} day" . ($days_left == 1 ? '' : 's') . "!",
                'message' => 'Your membership expires on ' . date('M d, Y', strtotime($m_sub['expiry_date'])),
                'time'    => 'Urgent',
                'unread'  => true,
            ];
        }
    }

    // 2. Fetch Notifications from Database
    try {
        $n_stmt = $pdo->prepare("SELECT id, type, title, message, read_status, sent_at FROM notifications WHERE member_id = ? ORDER BY sent_at DESC LIMIT 20");
        $n_stmt->execute([$member_id]);
        $db_notifs = $n_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($db_notifs as $dn) {
            $icon = 'fa-bell';
            $type = 'info';
            $t = $dn['type'] ?? '';

            if ($t === 'Registration' || str_contains($t, 'APPROV')) {
                $icon = 'fa-circle-check'; $type = 'success';
            } elseif ($t === 'Expiration' || str_contains($t, 'REJECT') || str_contains($t, 'EXPIRE')) {
                $icon = 'fa-circle-xmark'; $type = 'danger';
            } elseif ($t === 'Renewal' || str_contains($t, 'PAYMENT')) {
                $icon = 'fa-file-invoice-dollar'; $type = 'success';
            }

            $notifications[] = [
                'id'      => 'notif_' . $dn['id'],
                'type'    => $type,
                'icon'    => $icon,
                'title'   => $dn['title'],
                'message' => $dn['message'] ?: $dn['title'],
                'time'    => date('M d · h:i A', strtotime($dn['sent_at'])),
                'unread'  => (($dn['read_status'] ?? 'Unread') === 'Unread'),
            ];
        }
    } catch (Throwable $ne) {}

    $all = array_merge($alerts, $notifications);
    $unread = count(array_filter($all, fn($n) => $n['unread']));

    echo json_encode([
        'success'       => true,
        'notifications' => $all,
        'unread'        => $unread
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'notifications' => [], 'unread' => 0]);
}
