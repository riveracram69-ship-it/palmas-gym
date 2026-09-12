<?php
/**
 * database/migrations/paymongo_integration_migration.php
 * Idempotent migration for PayMongo Test Mode & Live Gateway schema
 */

require_once __DIR__ . '/../../config/db.php';

echo "=== PAYMONGO PAYMENT GATEWAY MIGRATION ===\n";

if (!isset($pdo) || !$pdo) {
    echo "[!] Database connection unavailable. Skipping database migration.\n";
    exit(0);
}

try {
    // 1. Ensure payment_transactions table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `payment_transactions` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `member_id` INT(11) NOT NULL,
            `plan_id` INT(11) NOT NULL,
            `subscription_id` INT(11) NULL,
            `reference_code` VARCHAR(100) NOT NULL,
            `gateway_transaction_id` VARCHAR(100) NULL,
            `paymongo_checkout_id` VARCHAR(100) NULL,
            `paymongo_payment_id` VARCHAR(100) NULL,
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
            KEY `idx_pay_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    echo "  [✓] `payment_transactions` table verified.\n";

    // 2. Fetch existing columns in payment_transactions
    $stmt = $pdo->query("SHOW COLUMNS FROM `payment_transactions`");
    $existingCols = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('paymongo_checkout_id', $existingCols)) {
        $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `paymongo_checkout_id` VARCHAR(100) NULL AFTER `gateway_transaction_id`");
        echo "  [+] Added `paymongo_checkout_id` column to `payment_transactions`.\n";
    }

    if (!in_array('paymongo_payment_id', $existingCols)) {
        $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `paymongo_payment_id` VARCHAR(100) NULL AFTER `paymongo_checkout_id`");
        echo "  [+] Added `paymongo_payment_id` column to `payment_transactions`.\n";
    }

    if (!in_array('failure_reason', $existingCols)) {
        $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `failure_reason` VARCHAR(255) NULL AFTER `gateway_response`");
        echo "  [+] Added `failure_reason` column to `payment_transactions`.\n";
    }

    if (!in_array('is_test', $existingCols)) {
        $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `is_test` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`");
        echo "  [+] Added `is_test` column to `payment_transactions`.\n";
    }

    // 3. Ensure payments table has paymongo columns
    $p_stmt = $pdo->query("SHOW COLUMNS FROM `payments`");
    $pCols = $p_stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('paymongo_checkout_id', $pCols)) {
        $pdo->exec("ALTER TABLE `payments` ADD COLUMN `paymongo_checkout_id` VARCHAR(100) NULL AFTER `reference_number`");
        echo "  [+] Added `paymongo_checkout_id` column to `payments`.\n";
    }

    if (!in_array('paymongo_payment_id', $pCols)) {
        $pdo->exec("ALTER TABLE `payments` ADD COLUMN `paymongo_payment_id` VARCHAR(100) NULL AFTER `paymongo_checkout_id`");
        echo "  [+] Added `paymongo_payment_id` column to `payments`.\n";
    }

    if (!in_array('is_test', $pCols)) {
        $pdo->exec("ALTER TABLE `payments` ADD COLUMN `is_test` TINYINT(1) NOT NULL DEFAULT 0 AFTER `notes`");
        echo "  [+] Added `is_test` column to `payments`.\n";
    }

    // 4. Ensure index on gateway IDs
    try {
        $pdo->exec("ALTER TABLE `payment_transactions` ADD INDEX `idx_paymongo_checkout` (`paymongo_checkout_id`)");
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE `payment_transactions` ADD INDEX `idx_gateway_tx` (`gateway_transaction_id`)");
    } catch (Exception $e) {}

    echo "=== MIGRATION COMPLETED SUCCESSFULLY ===\n";

} catch (Exception $e) {
    echo "[!] Migration error: " . $e->getMessage() . "\n";
}
