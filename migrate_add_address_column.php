<?php
/**
 * migrate_add_address_column.php
 * Idempotent migration to add `address` column to `members` table.
 */
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=utf-8');

if (!isset($pdo) || !$pdo) {
    die("[FATAL] Database connection failed via config/db.php\n");
}

echo "=======================================================\n";
echo " MIGRATION: ADD ADDRESS COLUMN TO MEMBERS TABLE\n";
echo "=======================================================\n\n";

try {
    $cols = $pdo->query("SHOW COLUMNS FROM `members`")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('address', $cols)) {
        $pdo->exec("ALTER TABLE `members` ADD COLUMN `address` VARCHAR(255) NULL AFTER `contact_number`");
        echo "  [+] Successfully added `address` column to `members`.\n";
    } else {
        echo "  [*] `address` column already exists in `members` table.\n";
    }

    echo "\n=======================================================\n";
    echo " [SUCCESS] Migration completed successfully!\n";
    echo "=======================================================\n";
} catch (Exception $e) {
    echo "\n[ERROR] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
