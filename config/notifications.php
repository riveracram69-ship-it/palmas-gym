<?php
/**
 * Notifications Engine & Relational Schema Manager
 * 
 * Provides unified helper functions to log and dispatch system notifications
 * tied relationally to member records with CASCADE constraints.
 */

/**
 * Ensure notifications table exists and has proper relational foreign keys
 */
function ensure_notifications_table(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) return;

    try {
        // 1. Create table if not exists
        $sql = "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            type ENUM('Registration', 'Inactivity', 'Expiration', 'Renewal', 'General') NOT NULL DEFAULT 'General',
            title VARCHAR(255) NOT NULL,
            message TEXT NULL,
            delivery_status ENUM('Sent', 'Delivered', 'Failed') NOT NULL DEFAULT 'Sent',
            read_status ENUM('Unread', 'Read') NOT NULL DEFAULT 'Unread',
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notif_member (member_id),
            INDEX idx_notif_sent (sent_at),
            INDEX idx_notif_read (read_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $pdo->exec($sql);

        // 2. Clean up any orphaned records before adding foreign key
        $pdo->exec("DELETE FROM notifications WHERE member_id NOT IN (SELECT id FROM members)");

        // 3. Add Foreign Key if not yet present
        $fk_check = $pdo->query("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'notifications' 
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
              AND CONSTRAINT_NAME = 'fk_notifications_member'
        ")->fetchColumn();

        if (!$fk_check) {
            $pdo->exec("
                ALTER TABLE notifications 
                ADD CONSTRAINT fk_notifications_member 
                FOREIGN KEY (member_id) REFERENCES members (id) 
                ON DELETE CASCADE 
                ON UPDATE CASCADE
            ");
        }

        $initialized = true;
    } catch (Exception $e) {
        error_log("Notifications Table Init/FK Error: " . $e->getMessage());
    }
}

/**
 * Create and persist a notification linked to a member
 * 
 * @param PDO $pdo
 * @param int $member_id
 * @param string $type ('Registration' | 'Inactivity' | 'Expiration' | 'Renewal' | 'General')
 * @param string $title
 * @param string $message
 * @param string $delivery_status ('Sent' | 'Delivered' | 'Failed')
 * @return int|bool Inserted notification ID or false
 */
function create_notification(
    PDO $pdo, 
    int $member_id, 
    string $type, 
    string $title, 
    string $message = '', 
    string $delivery_status = 'Sent'
) {
    if ($member_id <= 0) return false;
    ensure_notifications_table($pdo);

    $allowed_types = ['Registration', 'Inactivity', 'Expiration', 'Renewal', 'General'];
    if (!in_array($type, $allowed_types)) {
        $type = 'General';
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (member_id, type, title, message, delivery_status, read_status, sent_at)
            VALUES (?, ?, ?, ?, ?, 'Unread', NOW())
        ");
        $stmt->execute([$member_id, $type, $title, $message, $delivery_status]);
        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        error_log("create_notification Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Fetch recent notifications for a specific member
 */
function get_member_notifications(PDO $pdo, int $member_id, int $limit = 10): array {
    if ($member_id <= 0) return [];
    ensure_notifications_table($pdo);

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
