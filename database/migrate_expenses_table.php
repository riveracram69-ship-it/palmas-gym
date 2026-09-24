<?php
/**
 * Migration: Create expenses table
 * Palma's Elite Gym Management System
 */
require_once __DIR__ . '/../config/db.php';

echo "=== Running Migration: Create Expenses Table ===\n";

try {
    if (!isset($pdo) || !$pdo) {
        throw new Exception("Database connection unavailable.");
    }

    $sql = "CREATE TABLE IF NOT EXISTS `expenses` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `expense_date` DATE NOT NULL,
        `category` VARCHAR(100) NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `amount` DECIMAL(10,2) NOT NULL,
        `payment_method` VARCHAR(50) DEFAULT 'Cash',
        `reference_number` VARCHAR(100) DEFAULT NULL,
        `receipt_photo` VARCHAR(255) DEFAULT NULL,
        `notes` TEXT DEFAULT NULL,
        `recorded_by` INT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_expense_date` (`expense_date`),
        KEY `idx_category` (`category`),
        KEY `idx_recorded_by` (`recorded_by`),
        CONSTRAINT `fk_expenses_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $pdo->exec($sql);
    echo "✓ Expenses table created or already exists.\n";

    // Create receipts upload directory
    $receiptsDir = __DIR__ . '/../uploads/receipts';
    if (!is_dir($receiptsDir)) {
        mkdir($receiptsDir, 0755, true);
        echo "✓ Created uploads/receipts/ directory.\n";
    }

    // Add .htaccess in uploads/receipts to prevent script execution
    $htaccessPath = $receiptsDir . '/.htaccess';
    if (!file_exists($htaccessPath)) {
        file_put_contents($htaccessPath, "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phps|cgi|pl|exe|sh|bat)$\">\n    Order Deny,Allow\n    Deny from all\n</FilesMatch>\n");
        echo "✓ Protected uploads/receipts/ with .htaccess script execution prevention.\n";
    }

    // Add index.html to prevent directory listing
    $indexHtmlPath = $receiptsDir . '/index.html';
    if (!file_exists($indexHtmlPath)) {
        file_put_contents($indexHtmlPath, "<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>Directory Access Forbidden</h1></body></html>");
        echo "✓ Added index.html placeholder to uploads/receipts/.\n";
    }

    echo "=== Migration Finished Successfully ===\n";
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
