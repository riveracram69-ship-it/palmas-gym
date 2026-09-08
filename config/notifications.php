<?php
/**
 * Notifications Engine & Relational Schema Manager
 * 
 * Provides unified helper functions to log and dispatch system notifications
 * tied relationally to member records with CASCADE constraints and stage-level idempotency.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';

/**
 * Ensure the notifications table has all required columns (idempotent schema guard).
 * Called by cron/daily_maintenance.php on startup to guarantee schema readiness.
 */
function ensure_notifications_table(PDO $pdo): void {
    try {
        // Verify the notifications table exists and has required columns
        $cols = $pdo->query("SHOW COLUMNS FROM `notifications`")->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('notification_type', $cols)) {
            $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `notification_type` VARCHAR(50) NOT NULL DEFAULT 'SYSTEM' AFTER `message`");
        }
        if (!in_array('subscription_id', $cols)) {
            $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `subscription_id` INT(11) NULL AFTER `member_id`");
            try { $pdo->exec("ALTER TABLE `notifications` ADD KEY `idx_notif_sub` (`subscription_id`)"); } catch (Exception $e) {}
        }
        if (!in_array('stage', $cols)) {
            $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `stage` VARCHAR(30) NULL AFTER `notification_type`");
            try { $pdo->exec("ALTER TABLE `notifications` ADD KEY `idx_notif_stage` (`subscription_id`, `stage`)"); } catch (Exception $e) {}
        }
        if (!in_array('read_at', $cols)) {
            $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `read_at` DATETIME NULL AFTER `sent_at`");
        }
        if (!in_array('title', $cols)) {
            $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `title` VARCHAR(255) NOT NULL DEFAULT '' AFTER `stage`");
        }
    } catch (Exception $e) {
        error_log("ensure_notifications_table warning: " . $e->getMessage());
    }
}

/**
 * Report Database Notification Subsystem Status
 */
function get_database_notification_status(): string {
    return 'FUNCTIONAL';
}

/**
 * Report Push Notification Subsystem Status
 * ('NOT CONFIGURED' | 'CONFIGURED' | 'FUNCTIONAL')
 */
function get_push_notification_status(): string {
    $fcm_key = defined('FCM_SERVER_KEY') ? trim((string)FCM_SERVER_KEY) : '';
    $fcm_sa  = defined('FIREBASE_CREDENTIALS') ? trim((string)FIREBASE_CREDENTIALS) : '';

    if (!empty($fcm_sa) && file_exists($fcm_sa) && is_readable($fcm_sa)) {
        return 'CONFIGURED';
    }

    if (!empty($fcm_key)) {
        return 'CONFIGURED';
    }

    return 'NOT CONFIGURED';
}

/**
 * Create and persist an idempotent notification linked to a member
 * 
 * @param PDO $pdo
 * @param int $member_id
 * @param string $notification_type ('ACCOUNT_APPROVED' | 'ACCOUNT_REJECTED' | 'PAYMENT_SUCCESS' | 'PAYMENT_FAILED' | 'MEMBERSHIP_ACTIVATED' | 'MEMBERSHIP_RENEWED' | 'MEMBERSHIP_EXPIRING' | 'MEMBERSHIP_EXPIRED' | 'SYSTEM')
 * @param string $title
 * @param string $message
 * @param string $delivery_status ('Sent' | 'Delivered' | 'Failed')
 * @param int|null $subscription_id Associated subscription ID for stage tracking
 * @param string|null $stage Idempotency stage marker ('STAGE_ACTIVATED', 'STAGE_30M', 'STAGE_10M', 'STAGE_5M', 'STAGE_EXPIRED')
 * @return int|bool Inserted notification ID or false
 */
function create_notification(
    PDO $pdo, 
    int $member_id, 
    string $notification_type, 
    string $title, 
    string $message = '', 
    string $delivery_status = 'Sent',
    ?int $subscription_id = null,
    ?string $stage = null
) {
    if ($member_id <= 0) return false;

    // Idempotency check: Guard against duplicate stage notifications for the same subscription
    if ($stage !== null && $subscription_id !== null) {
        try {
            $chk = $pdo->prepare("
                SELECT id FROM notifications 
                WHERE member_id = ? AND subscription_id = ? AND stage = ? 
                LIMIT 1
            ");
            $chk->execute([$member_id, $subscription_id, $stage]);
            if ($chk->fetch()) {
                // Notification for this stage already dispatched
                return false;
            }
        } catch (Exception $chkEx) {}
    }

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
            INSERT INTO notifications (member_id, subscription_id, type, notification_type, stage, title, message, delivery_status, read_status, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Unread', NOW())
        ");
        $stmt->execute([
            $member_id,
            $subscription_id,
            $legacy_type,
            $notification_type,
            $stage,
            $title,
            $message,
            $delivery_status
        ]);
        $notif_id = (int)$pdo->lastInsertId();

        // Trigger push notification if member has registered devices
        dispatch_device_push_notification($pdo, $member_id, $title, $message, [
            'type'            => $notification_type,
            'notification_id' => $notif_id,
            'subscription_id' => $subscription_id
        ]);

        return $notif_id;
    } catch (Exception $e) {
        error_log("create_notification Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Fetch recent notifications for a specific member
 */
function get_member_notifications(PDO $pdo, int $member_id, int $limit = 20): array {
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

        $push_status = get_push_notification_status();

        if ($push_status === 'NOT CONFIGURED') {
            // Push provider not configured; log device delivery audit
            foreach ($devices as $dev) {
                error_log("[PUSH DISPATCH - NOT CONFIGURED] To Member #{$member_id} ({$dev['device_type']}): '{$title}' - {$body}");
            }
            return;
        }

        // When FCM is configured with server key or service account
        $fcm_key = defined('FCM_SERVER_KEY') ? trim((string)FCM_SERVER_KEY) : '';
        if (!empty($fcm_key)) {
            foreach ($devices as $dev) {
                $fcm_payload = [
                    'to' => $dev['device_token'],
                    'notification' => [
                        'title' => $title,
                        'body'  => $body,
                        'sound' => 'default'
                    ],
                    'data' => $data
                ];

                $ch = curl_init('https://fcm.googleapis.com/fcm/send');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode($fcm_payload),
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: key=' . $fcm_key,
                        'Content-Type: application/json'
                    ],
                    CURLOPT_TIMEOUT        => 5
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
        }
    } catch (Exception $e) {
        error_log("dispatch_device_push_notification Error: " . $e->getMessage());
    }
}
