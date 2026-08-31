<?php
/**
 * Database Migration v2: Comprehensive Enterprise System Update
 * Handles Tables, Columns, Constraints, Enums for Palma's Elite Gym Management System
 */
require_once __DIR__ . '/config/db.php';

echo "=== PALMA'S ELITE GYM — DATABASE MIGRATION V2 ===\n";

if (!isset($pdo) || !$pdo) {
    die("[FATAL] Could not connect to database via config/db.php\n");
}

try {
    // -------------------------------------------------------------
    // 1. Members Table Enhancements
    // -------------------------------------------------------------
    echo "\n[1/5] Checking `members` table columns & constraints...\n";
    $cols_stmt = $pdo->query("SHOW COLUMNS FROM members");
    $existing_cols = $cols_stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('account_status', $existing_cols)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN account_status ENUM('Pending', 'Approved', 'Rejected', 'Suspended') NOT NULL DEFAULT 'Approved' AFTER membership_id");
        echo "  + Added `account_status` column\n";
    } else {
        $pdo->exec("ALTER TABLE members MODIFY COLUMN account_status ENUM('Pending', 'Approved', 'Rejected', 'Suspended') NOT NULL DEFAULT 'Approved'");
        echo "  ✓ `account_status` column updated\n";
    }

    if (!in_array('status', $existing_cols)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN status ENUM('Active', 'Inactive', 'Expired', 'Suspended') NOT NULL DEFAULT 'Inactive' AFTER account_status");
        echo "  + Added `status` column\n";
    } else {
        $pdo->exec("ALTER TABLE members MODIFY COLUMN status ENUM('Active', 'Inactive', 'Expired', 'Suspended') NOT NULL DEFAULT 'Inactive'");
        echo "  ✓ `status` column updated\n";
    }

    if (!in_array('approved_by', $existing_cols)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN approved_by INT(11) NULL AFTER created_by, ADD CONSTRAINT fk_members_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL");
        echo "  + Added `approved_by` column\n";
    }

    if (!in_array('approved_at', $existing_cols)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN approved_at DATETIME NULL AFTER approved_by");
        echo "  + Added `approved_at` column\n";
    }

    if (!in_array('rejection_reason', $existing_cols)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN rejection_reason TEXT NULL AFTER approved_at");
        echo "  + Added `rejection_reason` column\n";
    }

    if (!in_array('selected_plan_id', $existing_cols)) {
        $pdo->exec("ALTER TABLE members ADD COLUMN selected_plan_id INT(11) NULL AFTER rejection_reason, ADD CONSTRAINT fk_members_selected_plan FOREIGN KEY (selected_plan_id) REFERENCES membership_plans(id) ON DELETE SET NULL");
        echo "  + Added `selected_plan_id` column\n";
    }

    // -------------------------------------------------------------
    // 2. Notifications Table Enhancements
    // -------------------------------------------------------------
    echo "\n[2/5] Checking `notifications` table columns...\n";
    $notif_cols_stmt = $pdo->query("SHOW COLUMNS FROM notifications");
    $existing_notif_cols = $notif_cols_stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('notification_type', $existing_notif_cols)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN notification_type VARCHAR(50) NOT NULL DEFAULT 'SYSTEM' AFTER message");
        echo "  + Added `notification_type` column\n";
    }

    if (!in_array('read_at', $existing_notif_cols)) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN read_at DATETIME NULL AFTER sent_at");
        echo "  + Added `read_at` column\n";
    }

    // -------------------------------------------------------------
    // 3. Payment Transactions Table
    // -------------------------------------------------------------
    echo "\n[3/5] Creating or upgrading `payment_transactions` table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `payment_transactions` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `member_id` INT(11) NOT NULL,
            `plan_id` INT(11) NOT NULL,
            `reference_code` VARCHAR(100) NOT NULL,
            `gateway_transaction_id` VARCHAR(100) NULL,
            `payment_method` ENUM('CASH', 'GCASH', 'MAYA', 'QRPH', 'BANK_TRANSFER', 'CREDIT_CARD') NOT NULL DEFAULT 'GCASH',
            `amount` DECIMAL(10,2) NOT NULL,
            `currency` VARCHAR(10) NOT NULL DEFAULT 'PHP',
            `status` ENUM('PENDING', 'PROCESSING', 'PAID', 'FAILED', 'EXPIRED', 'CANCELLED', 'REFUNDED') NOT NULL DEFAULT 'PENDING',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `paid_at` DATETIME NULL,
            `expires_at` DATETIME NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_pay_ref` (`reference_code`),
            KEY `idx_pay_member` (`member_id`),
            KEY `idx_pay_status` (`status`),
            CONSTRAINT `fk_pay_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_pay_plan` FOREIGN KEY (`plan_id`) REFERENCES `membership_plans` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    echo "  ✓ `payment_transactions` table is ready\n";

    // -------------------------------------------------------------
    // 4. Member Devices (Push Notification Registry) Table
    // -------------------------------------------------------------
    echo "\n[4/5] Creating or upgrading `member_devices` table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `member_devices` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `member_id` INT(11) NOT NULL,
            `device_token` VARCHAR(255) NOT NULL,
            `device_type` ENUM('android', 'ios', 'web') NOT NULL DEFAULT 'android',
            `last_used_at` DATETIME NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_member_token` (`member_id`, `device_token`),
            KEY `idx_dev_member` (`member_id`),
            CONSTRAINT `fk_device_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    echo "  ✓ `member_devices` table is ready\n";

    // -------------------------------------------------------------
    // 5. Renewal Requests Table & Auth Tokens Table
    // -------------------------------------------------------------
    echo "\n[5/6] Creating or upgrading `renewal_requests` & `auth_tokens` tables...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `renewal_requests` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `member_id` INT NOT NULL,
            `plan_id` INT NOT NULL,
            `payment_method` ENUM('Cash', 'GCash', 'Bank Transfer', 'Credit Card', 'Maya', 'QRPH') NOT NULL DEFAULT 'GCash',
            `reference_no` VARCHAR(100) DEFAULT NULL,
            `status` ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `processed_by` INT DEFAULT NULL,
            KEY `idx_renew_member` (`member_id`),
            KEY `idx_renew_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `auth_tokens` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `member_id` INT NOT NULL,
            `token` VARCHAR(64) NOT NULL UNIQUE,
            `expires_at` DATETIME NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_auth_token` (`token`),
            KEY `idx_auth_member` (`member_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "  ✓ `renewal_requests` and `auth_tokens` tables verified\n";

    // -------------------------------------------------------------
    // 6. Activity Logs Indexing & Foreign Keys Check
    // -------------------------------------------------------------
    echo "\n[6/6] Checking indexing on `activity_logs` & `attendance`...\n";

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_activity_action ON activity_logs (action)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_attendance_lookup_v2 ON attendance (member_id, date, time_out)");
    echo "  ✓ Indexes optimized\n";

    echo "\n=======================================================\n";
    echo "[SUCCESS] All Migration Steps Applied Successfully!\n";
    echo "=======================================================\n";

} catch (Exception $e) {
    echo "\n[ERROR] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
