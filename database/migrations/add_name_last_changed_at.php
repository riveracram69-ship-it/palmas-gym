<?php
/**
 * Migration: Add `name_last_changed_at` column to `members` table
 */
require_once __DIR__ . '/../../config/db.php';

try {
    // Check if column already exists
    $stmt = $pdo->query("SHOW COLUMNS FROM members LIKE 'name_last_changed_at'");
    $exists = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$exists) {
        $pdo->exec("ALTER TABLE `members` ADD COLUMN `name_last_changed_at` DATETIME NULL DEFAULT NULL AFTER `extension`");
        echo "[SUCCESS] Column `name_last_changed_at` added to `members` table.\n";
    } else {
        echo "[INFO] Column `name_last_changed_at` already exists in `members` table.\n";
    }
} catch (Exception $e) {
    echo "[ERROR] Failed to run migration: " . $e->getMessage() . "\n";
    exit(1);
}
