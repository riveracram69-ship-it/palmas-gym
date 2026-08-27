<?php
/**
 * scratch/refactor_database.php
 * Refactors and cleans up the database schema:
 * 1. Drops services, service_registrations, and notifications tables.
 * 2. Prunes members.qr_code_path and subscriptions.status.
 * 3. Migrates payments from polymorphic to strict FK referencing subscriptions.
 * 4. Adds necessary indexes.
 */
require_once __DIR__ . '/../config/db.php';

try {
    // 1. Drop unused tables
    echo "Dropping unused tables (service_registrations, services, notifications)...\n";
    $pdo->exec("DROP TABLE IF EXISTS service_registrations");
    $pdo->exec("DROP TABLE IF EXISTS services");
    $pdo->exec("DROP TABLE IF EXISTS notifications");

    // 2. Clean up members table
    echo "Pruning members table (dropping qr_code_path)...\n";
    // Check if column exists before dropping (to support re-running)
    $cols = $pdo->query("DESCRIBE members")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('qr_code_path', $cols)) {
        $pdo->exec("ALTER TABLE members DROP COLUMN qr_code_path");
    }
    // Add index on status if not already indexed
    try {
        $pdo->exec("ALTER TABLE members ADD INDEX idx_member_status (status)");
    } catch (Exception $e) {
        echo "Note: idx_member_status index already exists or error: " . $e->getMessage() . "\n";
    }

    // 3. Clean up subscriptions table
    echo "Pruning subscriptions table (dropping status column)...\n";
    $cols_sub = $pdo->query("DESCRIBE subscriptions")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('status', $cols_sub)) {
        $pdo->exec("ALTER TABLE subscriptions DROP COLUMN status");
    }
    // Add index on expiry_date
    try {
        $pdo->exec("ALTER TABLE subscriptions ADD INDEX idx_sub_expiry (expiry_date)");
    } catch (Exception $e) {
        echo "Note: idx_sub_expiry index already exists or error: " . $e->getMessage() . "\n";
    }

    // 4. Refactor payments table
    echo "Refactoring payments table (migrating polymorphic columns to subscription foreign key)...\n";
    
    // Clean up payments that aren't subscriptions or are orphans to prevent FK constraints failing
    try {
        $pdo->exec("DELETE FROM payments WHERE reference_type = 'Service'");
    } catch (Exception $e) {}
    
    // Delete payments whose subscription ID is not valid in subscriptions table
    try {
        $pdo->exec("DELETE FROM payments WHERE reference_id NOT IN (SELECT id FROM subscriptions)");
    } catch (Exception $e) {}

    $cols_pay = $pdo->query("DESCRIBE payments")->fetchAll(PDO::FETCH_COLUMN);
    
    if (in_array('reference_type', $cols_pay)) {
        $pdo->exec("ALTER TABLE payments DROP COLUMN reference_type");
    }
    
    if (in_array('reference_id', $cols_pay)) {
        $pdo->exec("ALTER TABLE payments CHANGE COLUMN reference_id subscription_id INT NOT NULL");
    }
    
    // Add foreign key constraint to subscriptions
    try {
        $pdo->exec("ALTER TABLE payments ADD CONSTRAINT fk_payments_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE");
    } catch (Exception $e) {
        echo "Note: fk_payments_subscription already exists or error: " . $e->getMessage() . "\n";
    }
    
    // Add index on payment_date
    try {
        $pdo->exec("ALTER TABLE payments ADD INDEX idx_payment_date (payment_date)");
    } catch (Exception $e) {
        echo "Note: idx_payment_date index already exists or error: " . $e->getMessage() . "\n";
    }

    // 5. Optimize attendance table
    echo "Adding indexes to attendance table...\n";
    try {
        $pdo->exec("ALTER TABLE attendance ADD INDEX idx_attendance_lookup (member_id, date, time_out)");
    } catch (Exception $e) {
        echo "Note: idx_attendance_lookup index already exists or error: " . $e->getMessage() . "\n";
    }

    echo "Success: Database refactoring completed.\n";

} catch (Exception $e) {
    echo "Error: Refactoring failed: " . $e->getMessage() . "\n";
}
