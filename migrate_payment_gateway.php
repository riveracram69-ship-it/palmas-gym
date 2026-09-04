<?php
/**
 * migrate_payment_gateway.php
 * Enhances the payment_transactions table for official gateway integration & audit.
 */

require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    echo "=======================================================\n";
    echo " Palma's Elite Gym - Payment Gateway Migration\n";
    echo "=======================================================\n\n";

    // 1. Check if payment_transactions table exists, create if not
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `payment_transactions` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `member_id` INT(11) NOT NULL,
            `plan_id` INT(11) NOT NULL,
            `reference_code` VARCHAR(100) NOT NULL,
            `gateway_transaction_id` VARCHAR(100) NULL,
            `payment_method` ENUM('CASH', 'GCASH', 'MAYA', 'QRPH', 'BANK_TRANSFER', 'CREDIT_CARD', 'GRAB_PAY') NOT NULL DEFAULT 'GCASH',
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
    echo "[✓] Table `payment_transactions` verified.\n";

    // 2. Fetch existing columns
    $cols_stmt = $pdo->query("SHOW COLUMNS FROM `payment_transactions`");
    $existing_cols = $cols_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Add subscription_id if not present
    if (!in_array('subscription_id', $existing_cols)) {
        $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `subscription_id` INT(11) NULL AFTER `plan_id`");
        echo "[+] Added `subscription_id` column.\n";
    }

    // Add gateway if not present
    if (!in_array('gateway', $existing_cols)) {
        $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `gateway` VARCHAR(50) NOT NULL DEFAULT 'PayMongo' AFTER `gateway_transaction_id`");
        echo "[+] Added `gateway` column.\n";
    }

    // Add checkout_url if not present
    if (!in_array('checkout_url', $existing_cols)) {
        $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `checkout_url` TEXT NULL AFTER `gateway`");
        echo "[+] Added `checkout_url` column.\n";
    }

    // Add gateway_response if not present
    if (!in_array('gateway_response', $existing_cols)) {
        $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `gateway_response` LONGTEXT NULL AFTER `checkout_url`");
        echo "[+] Added `gateway_response` column.\n";
    }

    // Add failure_reason if not present
    if (!in_array('failure_reason', $existing_cols)) {
        $pdo->exec("ALTER TABLE `payment_transactions` ADD COLUMN `failure_reason` VARCHAR(255) NULL AFTER `gateway_response`");
        echo "[+] Added `failure_reason` column.\n";
    }

    // Update payment_method ENUM to include GRAB_PAY if necessary
    try {
        $pdo->exec("ALTER TABLE `payment_transactions` MODIFY COLUMN `payment_method` ENUM('CASH', 'GCASH', 'MAYA', 'QRPH', 'BANK_TRANSFER', 'CREDIT_CARD', 'GRAB_PAY') NOT NULL DEFAULT 'GCASH'");
        echo "[✓] Column `payment_method` ENUM verified.\n";
    } catch (Exception $e) {
        // ENUM modify safe notice
    }

    // 3. Ensure payments table has reference_number index
    try {
        $pdo->exec("ALTER TABLE `payments` ADD INDEX `idx_payments_ref` (`reference_number`)");
        echo "[+] Added index `idx_payments_ref` to `payments` table.\n";
    } catch (Exception $e) {
        // Index might already exist
    }

    echo "\n=======================================================\n";
    echo " [SUCCESS] Migration completed successfully!\n";
    echo "=======================================================\n";

} catch (Exception $e) {
    echo "\n[ERROR] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
