<?php
/**
 * Database Migration: Account Status & Approval Workflow
 */
require_once __DIR__ . '/config/db.php';

echo "Running migration on database...\n";

try {
    // 1. Check existing columns in members table
    $stmt = $pdo->query("SHOW COLUMNS FROM members");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('account_status', $columns)) {
        echo "Adding account_status column to members table...\n";
        $pdo->exec("ALTER TABLE members ADD COLUMN account_status ENUM('Pending', 'Approved', 'Rejected', 'Suspended') NOT NULL DEFAULT 'Approved' AFTER membership_id");
    } else {
        echo "Column account_status already exists.\n";
    }

    if (!in_array('approved_by', $columns)) {
        echo "Adding approved_by column to members table...\n";
        $pdo->exec("ALTER TABLE members ADD COLUMN approved_by INT(11) NULL AFTER created_by, ADD CONSTRAINT fk_members_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL");
    } else {
        echo "Column approved_by already exists.\n";
    }

    if (!in_array('approved_at', $columns)) {
        echo "Adding approved_at column to members table...\n";
        $pdo->exec("ALTER TABLE members ADD COLUMN approved_at DATETIME NULL AFTER approved_by");
    } else {
        echo "Column approved_at already exists.\n";
    }

    if (!in_array('rejection_reason', $columns)) {
        echo "Adding rejection_reason column to members table...\n";
        $pdo->exec("ALTER TABLE members ADD COLUMN rejection_reason TEXT NULL AFTER approved_at");
    } else {
        echo "Column rejection_reason already exists.\n";
    }

    if (!in_array('selected_plan_id', $columns)) {
        echo "Adding selected_plan_id column to members table...\n";
        $pdo->exec("ALTER TABLE members ADD COLUMN selected_plan_id INT(11) NULL AFTER rejection_reason, ADD CONSTRAINT fk_members_selected_plan FOREIGN KEY (selected_plan_id) REFERENCES membership_plans(id) ON DELETE SET NULL");
    } else {
        echo "Column selected_plan_id already exists.\n";
    }

    // Set default account_status for existing members as Approved
    $pdo->exec("UPDATE members SET account_status = 'Approved' WHERE account_status IS NULL OR account_status = ''");

    echo "\n[SUCCESS] Migration completed successfully!\n";
} catch (Exception $e) {
    echo "\n[ERROR] Migration failed: " . $e->getMessage() . "\n";
}
