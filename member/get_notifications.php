<?php
/**
 * member/get_notifications.php
 * AJAX endpoint — returns unread/recent notifications for the logged-in member.
 */
require_once __DIR__ . '/auth.php';
require_member_login();

header('Content-Type: application/json');

$member = current_member($pdo);
if (!$member) { echo json_encode(['notifications' => [], 'unread' => 0]); exit; }

$notifications = [];
$alerts        = [];

// ── 1. Check membership expiry status ───────────────────────────
if ($member['expiry_date']) {
    $days_left = (int) round((strtotime($member['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);

    if ($days_left < 0) {
        $alerts[] = [
            'id'      => 'expiry_now',
            'type'    => 'danger',
            'icon'    => 'fa-circle-xmark',
            'title'   => 'Membership Expired',
            'message' => 'Your membership has expired. Please renew to continue accessing the gym.',
            'time'    => 'Just now',
            'unread'  => true,
        ];
    } elseif ($days_left <= 3) {
        $alerts[] = [
            'id'      => 'expiry_3d',
            'type'    => 'danger',
            'icon'    => 'fa-triangle-exclamation',
            'title'   => 'Expiring in ' . $days_left . ' day' . ($days_left == 1 ? '' : 's') . '!',
            'message' => 'Your membership expires on ' . date('M d, Y', strtotime($member['expiry_date'])) . '. Renew now to avoid losing access.',
            'time'    => 'Urgent',
            'unread'  => true,
        ];
    } elseif ($days_left <= 7) {
        $alerts[] = [
            'id'      => 'expiry_7d',
            'type'    => 'warning',
            'icon'    => 'fa-bell',
            'title'   => 'Membership Expiring Soon',
            'message' => $days_left . ' days left on your plan. Renew before ' . date('M d, Y', strtotime($member['expiry_date'])) . '.',
            'time'    => $days_left . 'd left',
            'unread'  => true,
        ];
    }
}

// ── 2. Fetch Dynamic Notifications from Renewal Requests ──────────
try {
    $stmt = $pdo->prepare("
        SELECT r.*, p.name as plan_name 
        FROM renewal_requests r
        JOIN membership_plans p ON r.plan_id = p.id
        WHERE r.member_id = ? 
        ORDER BY r.updated_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$member['id']]);
    $requests_logs = $stmt->fetchAll();

    foreach ($requests_logs as $r) {
        if ($r['status'] === 'Pending') {
            $notifications[] = [
                'id'      => 'req_' . $r['id'],
                'type'    => 'warning',
                'icon'    => 'fa-clock',
                'title'   => 'Renewal Under Review',
                'message' => "Your renewal request for {$r['plan_name']} is currently pending admin approval.",
                'time'    => date('M d · h:i A', strtotime($r['created_at'])),
                'unread'  => true,
            ];
        } elseif ($r['status'] === 'Approved') {
            // Only show approved notification if it was recently updated (within 7 days)
            $updated_ts = strtotime($r['updated_at']);
            if (time() - $updated_ts < 7 * 86400) {
                $notifications[] = [
                    'id'      => 'req_' . $r['id'],
                    'type'    => 'success',
                    'icon'    => 'fa-circle-check',
                    'title'   => 'Renewal Approved',
                    'message' => "Your renewal request for {$r['plan_name']} was approved! Your membership is active.",
                    'time'    => date('M d · h:i A', $updated_ts),
                    'unread'  => false,
                ];
            }
        } elseif ($r['status'] === 'Rejected') {
            $notifications[] = [
                'id'      => 'req_' . $r['id'],
                'type'    => 'danger',
                'icon'    => 'fa-circle-xmark',
                'title'   => 'Renewal Rejected',
                'message' => "Your request for {$r['plan_name']} was declined. Note: \"" . ($r['notes'] ?: 'No reason provided') . "\"",
                'time'    => date('M d · h:i A', strtotime($r['updated_at'])),
                'unread'  => true,
            ];
        }
    }
} catch (Exception $e) {}

// ── 3. Fetch Persisted Relational Notifications from notifications table ─────────
try {
    require_once __DIR__ . '/../config/notifications.php';
    $db_notifs = get_member_notifications($pdo, (int)$member['id'], 15);
    foreach ($db_notifs as $dn) {
        $icon = 'fa-bell';
        $type = 'info';
        $nt = $dn['notification_type'] ?? '';

        if ($nt === 'ACCOUNT_APPROVED' || $dn['type'] === 'Registration') { 
            $icon = 'fa-circle-check'; $type = 'success'; 
        } elseif ($nt === 'ACCOUNT_REJECTED' || $nt === 'MEMBERSHIP_EXPIRED' || $dn['type'] === 'Expiration') { 
            $icon = 'fa-circle-xmark'; $type = 'danger'; 
        } elseif ($nt === 'MEMBERSHIP_EXPIRING' || $dn['type'] === 'Inactivity') { 
            $icon = 'fa-triangle-exclamation'; $type = 'warning'; 
        } elseif ($nt === 'MEMBERSHIP_ACTIVATED' || $nt === 'MEMBERSHIP_RENEWED' || $nt === 'PAYMENT_SUCCESS' || $dn['type'] === 'Renewal') { 
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
} catch (Exception $e) {}

// Merge alerts (priority) + db notifications
$all = array_merge($alerts, $notifications);
$unread = count(array_filter($all, fn($n) => $n['unread']));

echo json_encode([
    'notifications' => $all,
    'unread'        => $unread,
]);
