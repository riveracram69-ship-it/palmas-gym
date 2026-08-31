<?php
/**
 * Notifications Engine & Relational Schema Manager
 * 
 * Provides unified helper functions to log and dispatch system notifications
 * tied relationally to member records with CASCADE constraints.
 */

require_once __DIR__ . '/db.php';

/**
 * Create and persist a notification linked to a member
 * 
 * @param PDO $pdo
 * @param int $member_id
 * @param string $notification_type ('ACCOUNT_APPROVED' | 'ACCOUNT_REJECTED' | 'PAYMENT_SUCCESS' | 'PAYMENT_FAILED' | 'MEMBERSHIP_ACTIVATED' | 'MEMBERSHIP_RENEWED' | 'MEMBERSHIP_EXPIRING' | 'MEMBERSHIP_EXPIRED' | 'SYSTEM')
 * @param string $title
 * @param string $message
 * @param string $delivery_status ('Sent' | 'Delivered' | 'Failed')
 * @return int|bool Inserted notification ID or false
 */
function create_notification(
    PDO $pdo, 
    int $member_id, 
    string $notification_type, 
    string $title, 
    string $message = '', 
    string $delivery_status = 'Sent'
) {
    if ($member_id <= 0) return false;

    // Map notification_type to legacy type for backwards compatibility
    $legacy_type = 'General';
    if (strpos($notification_type, 'ACCOUNT') !== false || strpos($notification_type, 'Registration') !== false) {
        $legacy_type = 'Registration';
    } elseif (strpos($notification_type, 'RENEW') !== false) {
        $legacy_type = 'Renewal';
    } elseif (strpos($notification_type, 'EXPIR') !== false) {
        $legacy_type = 'Expiration';
    } elseif (strpos($notification_type, 'PAYMENT') !== false || strpos($notification_type, 'ACTIVAT') !== false) {
        $legacy_type = 'Renewal';
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (member_id, type, notification_type, title, message, delivery_status, read_status, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, 'Unread', NOW())
        ");
        $stmt->execute([$member_id, $legacy_type, $notification_type, $title, $message, $delivery_status]);
        $notif_id = (int)$pdo->lastInsertId();

        // Trigger push notification if member has registered devices
        dispatch_device_push_notification($pdo, $member_id, $title, $message, ['type' => $notification_type]);

        return $notif_id;
    } catch (Exception $e) {
        error_log("create_notification Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Fetch recent notifications for a specific member
 */
function get_member_notifications(PDO $pdo, int $member_id, int $limit = 15): array {
    if ($member_id <= 0) return [];

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM notifications 
            WHERE member_id = ? 
            ORDER BY sent_at DESC 
            LIMIT ?
        ");
        $stmt->bindValue(1, $member_id, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("get_member_notifications Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Mark notification as read
 */
function mark_notification_read(PDO $pdo, int $notification_id, int $member_id): bool {
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET read_status = 'Read', read_at = NOW() 
            WHERE id = ? AND member_id = ?
        ");
        return $stmt->execute([$notification_id, $member_id]);
    } catch (Exception $e) {
        error_log("mark_notification_read Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark all member notifications as read
 */
function mark_all_notifications_read(PDO $pdo, int $member_id): bool {
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET read_status = 'Read', read_at = NOW() 
            WHERE member_id = ? AND read_status = 'Unread'
        ");
        return $stmt->execute([$member_id]);
    } catch (Exception $e) {
        error_log("mark_all_notifications_read Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Register a member device token for push notifications
 */
function register_member_device(PDO $pdo, int $member_id, string $device_token, string $device_type = 'android'): bool {
    if ($member_id <= 0 || empty($device_token)) return false;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO member_devices (member_id, device_token, device_type, last_used_at, created_at)
            VALUES (?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE last_used_at = NOW(), device_type = VALUES(device_type)
        ");
        return $stmt->execute([$member_id, $device_token, $device_type]);
    } catch (Exception $e) {
        error_log("register_member_device Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Dispatch Push Notification to all active devices of a member
 */
function dispatch_device_push_notification(PDO $pdo, int $member_id, string $title, string $body, array $data = []): void {
    try {
        $stmt = $pdo->prepare("SELECT device_token, device_type FROM member_devices WHERE member_id = ?");
        $stmt->execute([$member_id]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($devices)) return;

        // In production, send via Firebase Cloud Messaging (FCM) or APNs
        // Log push dispatch for audit trail
        foreach ($devices as $dev) {
            error_log("[PUSH DISPATCH] To Member #{$member_id} ({$dev['device_type']}): '{$title}' - {$body}");
        }
    } catch (Exception $e) {
        error_log("dispatch_device_push_notification Error: " . $e->getMessage());
    }
}
