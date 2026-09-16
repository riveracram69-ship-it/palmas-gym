<?php
/**
 * migrate_dob_and_address.php
 * Idempotent migration to add `dob`, `house_street`, `barangay`, `municipality`, `province`, `zip_code`
 * to the `members` table for Palma's Elite Gym.
 */
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=utf-8');

if (!isset($pdo) || !$pdo) {
    die("[FATAL] Database connection failed via config/db.php\n");
}

echo "=======================================================\n";
echo " MIGRATION: ADD DOB AND STRUCTURED ADDRESS COLUMNS\n";
echo "=======================================================\n\n";

try {
    $cols = $pdo->query("SHOW COLUMNS FROM `members`")->fetchAll(PDO::FETCH_COLUMN);

    $columns_to_add = [
        'dob' => "ALTER TABLE `members` ADD COLUMN `dob` DATE NULL AFTER `gender`",
        'house_street' => "ALTER TABLE `members` ADD COLUMN `house_street` VARCHAR(255) NULL AFTER `address`",
        'barangay' => "ALTER TABLE `members` ADD COLUMN `barangay` VARCHAR(150) NULL AFTER `house_street`",
        'municipality' => "ALTER TABLE `members` ADD COLUMN `municipality` VARCHAR(150) NULL AFTER `barangay`",
        'province' => "ALTER TABLE `members` ADD COLUMN `province` VARCHAR(150) NULL AFTER `municipality`",
        'zip_code' => "ALTER TABLE `members` ADD COLUMN `zip_code` VARCHAR(20) NULL AFTER `province`"
    ];

    foreach ($columns_to_add as $col_name => $alter_sql) {
        if (!in_array($col_name, $cols)) {
            $pdo->exec($alter_sql);
            echo "  [+] Added column `{$col_name}` to `members` table.\n";
        } else {
            echo "  [*] Column `{$col_name}` already exists in `members` table.\n";
        }
    }

    // Ensure legacy address column exists
    if (!in_array('address', $cols)) {
        $pdo->exec("ALTER TABLE `members` ADD COLUMN `address` VARCHAR(255) NULL AFTER `contact_number`");
        echo "  [+] Added `address` column to `members`.\n";
    }

    // Ensure legacy age column exists
    if (!in_array('age', $cols)) {
        $pdo->exec("ALTER TABLE `members` ADD COLUMN `age` INT NULL AFTER `dob`");
        echo "  [+] Added `age` column to `members`.\n";
    }

    echo "\n=======================================================\n";
    echo " [SUCCESS] Migration completed successfully!\n";
    echo "=======================================================\n";
} catch (Exception $e) {
    echo "\n[ERROR] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
