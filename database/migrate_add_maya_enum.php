<?php
/**
 * database/migrate_add_maya_enum.php
 * Adds 'Maya' to payment_method ENUM in `payments` and `renewal_requests` tables.
 */

require_once __DIR__ . '/../config/db.php';

try {
    echo "Updating `payments` table ENUM to support 'Maya'...\n";
    $pdo->exec("ALTER TABLE payments MODIFY COLUMN payment_method ENUM('Cash','GCash','Maya','Bank Transfer','Credit Card') NOT NULL");
    echo "✓ `payments` updated successfully!\n";

    echo "Updating `renewal_requests` table ENUM to support 'Maya'...\n";
    $pdo->exec("ALTER TABLE renewal_requests MODIFY COLUMN payment_method ENUM('Cash','GCash','Maya','Bank Transfer','Credit Card') NOT NULL");
    echo "✓ `renewal_requests` updated successfully!\n";

    // Also update any previous empty values to 'Maya' if they had notes indicating Maya
    $pdo->exec("UPDATE payments SET payment_method = 'Maya' WHERE (payment_method = '' OR payment_method IS NULL) AND notes LIKE '%Maya%'");
    $pdo->exec("UPDATE renewal_requests SET payment_method = 'Maya' WHERE (payment_method = '' OR payment_method IS NULL) AND notes LIKE '%Maya%'");
    echo "✓ Cleaned up any legacy Maya records.\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
