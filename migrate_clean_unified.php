<?php
// Defense-in-Depth: Restrict execution to CLI or authenticated administrator
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/config/auth.php';
    if (!function_exists('is_admin') || !is_admin()) {
        http_response_code(403);
        die("403 Forbidden: Maintenance and migration scripts may only be executed via CLI or by an authenticated administrator.");
    }
}
/**
 * migrate_clean_unified.php
 * Unified, idempotent enterprise database migration for Palma's Elite Gym.
 * 
 * Safely aligns existing databases and fresh installations to the complete schema:
 * - Minute-level promo durations (duration_minutes, is_test_promo, promo_code)
 * - DATETIME precision for subscriptions (start_date, expiry_date)
 * - is_test flag on payments and payment_transactions
 * - Relational notifications with notification_type and read_at
 * - Device registry (member_devices) and rate limiting (login_rate_limits)
 * - Seeds ₱1 (30-Minute and 60-Minute) test promotional plans
 */

require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=utf-8');

if (!isset($pdo) || !$pdo) {
    die("[FATAL] Database connection failed via config/db.php\n");
}

echo "=======================================================\n";
echo " PALMA'S ELITE GYM — UNIFIED DATABASE MIGRATION ENGINE\n";
echo "=======================================================\n\n";

try {
    // -----------------------------------------------------------------
    // 1. MEMBERSHIP PLANS TABLE ENHANCEMENTS & TEST PROMOS
    // -----------------------------------------------------------------
    echo "[1/7] Updating `membership_plans` table...\n";
    $planCols = $pdo->query("SHOW COLUMNS FROM `membership_plans`")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('duration_minutes', $planCols)) {
        $pdo->exec("ALTER TABLE `membership_plans` ADD COLUMN `duration_minutes` INT(11) NOT NULL DEFAULT 0 AFTER `duration_months`");
        echo "  [+] Added `duration_minutes` column.\n";
    }

    if (!in_array('is_test_promo', $planCols)) {
        $pdo->exec("ALTER TABLE `membership_plans` ADD COLUMN `is_test_promo` TINYINT(1) NOT NULL DEFAULT 0 AFTER `benefits`");
        echo "  [+] Added `is_test_promo` column.\n";
    }

    if (!in_array('promo_code', $planCols)) {
        $pdo->exec("ALTER TABLE `membership_plans` ADD COLUMN `promo_code` VARCHAR(50) NULL AFTER `is_test_promo`");
        echo "  [+] Added `promo_code` column.\n";
    }

    if (!in_array('promo_enabled', $planCols)) {
        $pdo->exec("ALTER TABLE `membership_plans` ADD COLUMN `promo_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `promo_code`");
        echo "  [+] Added `promo_enabled` column.\n";
    }

    // Seed or update the dedicated ₱1 test promotional plans
    // Plan 1: 30-Minute Test Promo
    $stmtCheck30 = $pdo->prepare("SELECT id FROM `membership_plans` WHERE `promo_code` = 'TEST_30M' OR `name` LIKE '%30 MIN%' LIMIT 1");
    $stmtCheck30->execute();
    $plan30Id = $stmtCheck30->fetchColumn();

    if ($plan30Id) {
        $pdo->prepare("
            UPDATE `membership_plans` 
            SET `name` = 'PAYMENT TEST — ₱1 — 30 MINUTES',
                `duration_months` = 0,
                `duration_minutes` = 30,
                `price` = 1.00,
                `benefits` = 'Functional verification pass for payment & gateway testing (Valid for 30 minutes).',
                `is_test_promo` = 1,
                `promo_code` = 'TEST_30M',
                `promo_enabled` = 1
            WHERE `id` = ?
        ")->execute([$plan30Id]);
        echo "  [✓] Updated 30-Minute Test Promo Plan (ID: {$plan30Id}).\n";
    } else {
        $pdo->prepare("
            INSERT INTO `membership_plans` 
            (`name`, `duration_months`, `duration_minutes`, `price`, `benefits`, `is_test_promo`, `promo_code`, `promo_enabled`, `created_at`)
            VALUES ('PAYMENT TEST — ₱1 — 30 MINUTES', 0, 30, 1.00, 'Functional verification pass for payment & gateway testing (Valid for 30 minutes).', 1, 'TEST_30M', 1, NOW())
        ")->execute();
        echo "  [+] Created 30-Minute Test Promo Plan (₱1.00).\n";
    }

    // Plan 2: 60-Minute Test Promo
    $stmtCheck60 = $pdo->prepare("SELECT id FROM `membership_plans` WHERE `promo_code` = 'TEST_60M' OR `name` LIKE '%60 MIN%' LIMIT 1");
    $stmtCheck60->execute();
    $plan60Id = $stmtCheck60->fetchColumn();

    if ($plan60Id) {
        $pdo->prepare("
            UPDATE `membership_plans` 
            SET `name` = 'PAYMENT TEST — ₱1 — 60 MINUTES',
                `duration_months` = 0,
                `duration_minutes` = 60,
                `price` = 1.00,
                `benefits` = 'Functional verification pass for payment & gateway testing (Valid for 60 minutes).',
                `is_test_promo` = 1,
                `promo_code` = 'TEST_60M',
                `promo_enabled` = 1
            WHERE `id` = ?
        ")->execute([$plan60Id]);
        echo "  [✓] Updated 60-Minute Test Promo Plan (ID: {$plan60Id}).\n";
    } else {
        $pdo->prepare("
            INSERT INTO `membership_plans` 
            (`name`, `duration_months`, `duration_minutes`, `price`, `benefits`, `is_test_promo`, `promo_code`, `promo_enabled`, `created_at`)
            VALUES ('PAYMENT TEST — ₱1 — 60 MINUTES', 0, 60, 1.00, 'Functional verification pass for payment & gateway testing (Valid for 60 minutes).', 1, 'TEST_60M', 1, NOW())
        ")->execute();
        echo "  [+] Created 60-Minute Test Promo Plan (₱1.00).\n";
    }

    // -----------------------------------------------------------------
    // 2. SUBSCRIPTIONS TABLE (DATETIME PRECISION FOR MINUTES & MONTHS)
    // -----------------------------------------------------------------
    echo "\n[2/7] Upgrading `subscriptions` table for minute precision...\n";
    $subTypes = $pdo->query("
        SELECT COLUMN_NAME, DATA_TYPE 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME IN ('start_date', 'expiry_date')
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    if (($subTypes['start_date'] ?? '') !== 'datetime') {
        $pdo->exec("ALTER TABLE `subscriptions` MODIFY COLUMN `start_date` DATETIME NOT NULL");
        echo "  [+] Upgraded `subscriptions.start_date` to DATETIME.\n";
    }

    if (($subTypes['expiry_date'] ?? '') !== 'datetime') {
        $pdo->exec("ALTER TABLE `subscriptions` MODIFY COLUMN `expiry_date` DATETIME NOT NULL");
        echo "  [+] Upgraded `subscriptions.expiry_date` to DATETIME.\n";
    }

    // -----------------------------------------------------------------
    // 3. PAYMENT TRANSACTIONS TABLE
    // -----------------------------------------------------------------
    echo "\n[3/7] Verifying `payment_transactions` table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `payment_transactions` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `member_id` INT(11) NOT NULL,
            `plan_id` INT(11) NOT NULL,
            `subscription_id` INT(11) NULL,
            `reference_code` VARCHAR(100) NOT NULL,
            `gateway_transaction_id` VARCHAR(100) NULL,
            `gateway` VARCHAR(50) NOT NULL DEFAULT 'PayMongo',
            `checkout_url` TEXT NULL,
            `payment_method` ENUM('CASH', 'GCASH', 'MAYA', 'QRPH', 'BANK_TRANSFER', 'CREDIT_CARD', 'GRAB_PAY') NOT NULL DEFAULT 'GCASH',
            `amount` DECIMAL(10,2) NOT NULL,
            `currency` VARCHAR(10) NOT NULL DEFAULT 'PHP',
            `status` ENUM('PENDING', 'PROCESSING', 'PAID', 'FAILED', 'EXPIRED', 'CANCELLED', 'REFUNDED') NOT NULL DEFAULT 'PENDING',
            `is_test` TINYINT(1) NOT NULL DEFAULT 0,
            `gateway_response` LONGTEXT NULL,
            `failure_reason` VARCHAR(255) NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `paid_at` DATETIME NULL,
            `expires_at` DATETIME NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_pay_ref` (`reference_code`),
            KEY `idx_pay_member` (`member_id`),
            KEY `idx_pay_status` (`status`),
            KEY `idx_pay_test` (`is_test`),
            CONSTRAINT `fk_pay_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_pay_plan` FOREIGN KEY (`plan_id`) REFERENCES `membership_plans` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    $txCols = $pdo->query("SHOW COLUMNS FROM `payment_transactions`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('is_test', $txCols)) {
        $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `is_test` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`, ADD KEY `idx_pay_test` (`is_test`)");
        echo "  [+] Added `is_test` column to `payment_transactions`.\n";
    }
    if (!in_array('gateway', $txCols)) {
        $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `gateway` VARCHAR(50) NOT NULL DEFAULT 'PayMongo' AFTER `gateway_transaction_id`");
        echo "  [+] Added `gateway` column to `payment_transactions`.\n";
    }
    if (!in_array('checkout_url', $txCols)) {
        $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `checkout_url` TEXT NULL AFTER `gateway`");
        echo "  [+] Added `checkout_url` column to `payment_transactions`.\n";
    }
    if (!in_array('subscription_id', $txCols)) {
        $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `subscription_id` INT(11) NULL AFTER `plan_id`");
        echo "  [+] Added `subscription_id` column to `payment_transactions`.\n";
    }
    if (!in_array('gateway_response', $txCols)) {
        $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `gateway_response` LONGTEXT NULL AFTER `checkout_url`");
        echo "  [+] Added `gateway_response` column to `payment_transactions`.\n";
    }
    if (!in_array('failure_reason', $txCols)) {
        $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `failure_reason` VARCHAR(255) NULL AFTER `gateway_response`");
        echo "  [+] Added `failure_reason` column to `payment_transactions`.\n";
    }
    echo "  [✓] `payment_transactions` table verified.\n";

    // -----------------------------------------------------------------
    // 4. PAYMENTS TABLE AUDIT & is_test COLUMN
    // -----------------------------------------------------------------
    echo "\n[4/7] Updating `payments` table...\n";
    $payCols = $pdo->query("SHOW COLUMNS FROM `payments`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('is_test', $payCols)) {
        $pdo->exec("ALTER TABLE `payments` ADD COLUMN `is_test` TINYINT(1) NOT NULL DEFAULT 0 AFTER `notes`, ADD KEY `idx_payments_test` (`is_test`)");
        echo "  [+] Added `is_test` column to `payments`.\n";
    }
    echo "  [✓] `payments` table verified.\n";

    // -----------------------------------------------------------------
    // 5. NOTIFICATIONS TABLE (STAGE TRACKING & CASCASE)
    // -----------------------------------------------------------------
    echo "\n[5/7] Updating `notifications` table...\n";
    $notifCols = $pdo->query("SHOW COLUMNS FROM `notifications`")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('notification_type', $notifCols)) {
        $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `notification_type` VARCHAR(50) NOT NULL DEFAULT 'SYSTEM' AFTER `message`");
        echo "  [+] Added `notification_type` column to `notifications`.\n";
    }
    if (!in_array('subscription_id', $notifCols)) {
        $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `subscription_id` INT(11) NULL AFTER `member_id`, ADD KEY `idx_notif_sub` (`subscription_id`)");
        echo "  [+] Added `subscription_id` column to `notifications`.\n";
    }
    if (!in_array('stage', $notifCols)) {
        $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `stage` VARCHAR(30) NULL AFTER `notification_type`, ADD KEY `idx_notif_stage` (`subscription_id`, `stage`)");
        echo "  [+] Added `stage` column to `notifications`.\n";
    }
    if (!in_array('read_at', $notifCols)) {
        $pdo->exec("ALTER TABLE `notifications` ADD COLUMN `read_at` DATETIME NULL AFTER `sent_at`");
        echo "  [+] Added `read_at` column to `notifications`.\n";
    }

    // Ensure Foreign Key on notifications.member_id -> members.id
    try {
        $fkNotif = $pdo->query("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'notifications' 
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
              AND CONSTRAINT_NAME = 'fk_notifications_member'
        ")->fetchColumn();

        if (!$fkNotif) {
            // Clean orphaned rows first
            $pdo->exec("DELETE FROM `notifications` WHERE `member_id` IS NOT NULL AND `member_id` NOT IN (SELECT `id` FROM `members`)");
            $pdo->exec("
                ALTER TABLE `notifications` 
                ADD CONSTRAINT `fk_notifications_member` 
                FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) 
                ON DELETE CASCADE ON UPDATE CASCADE
            ");
            echo "  [+] Added foreign key `fk_notifications_member`.\n";
        }
    } catch (Exception $e) {
        error_log("Notifications FK check note: " . $e->getMessage());
    }
    echo "  [✓] `notifications` table verified.\n";

    // -----------------------------------------------------------------
    // 6. MEMBER DEVICES & LOGIN RATE LIMITS
    // -----------------------------------------------------------------
    echo "\n[6/7] Verifying `member_devices` and `login_rate_limits`...\n";
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `login_rate_limits` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `ip_address` VARCHAR(45) NOT NULL,
            `attempts` INT(11) NOT NULL DEFAULT 1,
            `last_attempt_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_rate_ip` (`ip_address`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    echo "  [✓] `member_devices` and `login_rate_limits` tables verified.\n";

    // -----------------------------------------------------------------
    // 7. MEMBERS TABLE INTEGRITY
    // -----------------------------------------------------------------
    echo "\n[7/7] Verifying `members` table status enums...\n";
    $memCols = $pdo->query("SHOW COLUMNS FROM `members`")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('account_status', $memCols)) {
        $pdo->exec("ALTER TABLE `members` ADD COLUMN `account_status` ENUM('Pending', 'Approved', 'Rejected', 'Suspended') NOT NULL DEFAULT 'Approved' AFTER `membership_id`");
    }
    if (!in_array('status', $memCols)) {
        $pdo->exec("ALTER TABLE `members` ADD COLUMN `status` ENUM('Active', 'Inactive', 'Expired', 'Suspended') NOT NULL DEFAULT 'Inactive' AFTER `account_status`");
    }
    echo "  [✓] `members` table verified.\n";

    echo "\n=======================================================\n";
    echo " [SUCCESS] UNIFIED DATABASE MIGRATION COMPLETED!\n";
    echo "=======================================================\n";

} catch (Exception $e) {
    echo "\n[ERROR] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
