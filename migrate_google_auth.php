<?php
/**
 * migrate_google_auth.php
 * Adds Google OAuth fields to the members table.
 * Safe to run multiple times (uses IF NOT EXISTS checks).
 */
require_once __DIR__ . '/config/db.php';

echo "=== GGGYM — Google Auth Migration ===\n\n";

$migrations = [
    // 1. Add google_id (unique so one Google account = one member max)
    "google_id" => "ALTER TABLE members ADD COLUMN google_id VARCHAR(255) NULL UNIQUE AFTER reset_expires_at",

    // 2. Add google_picture (Google profile photo URL)
    "google_picture" => "ALTER TABLE members ADD COLUMN google_picture VARCHAR(500) NULL AFTER google_id",

    // 3. Add auth_provider (how the account was created)
    "auth_provider" => "ALTER TABLE members ADD COLUMN auth_provider ENUM('password','google','both') NOT NULL DEFAULT 'password' AFTER google_picture",

    // 4. Index on google_id for fast lookups
    "idx_google_id" => "ALTER TABLE members ADD INDEX idx_google_id (google_id)",
];

foreach ($migrations as $label => $sql) {
    try {
        $pdo->exec($sql);
        echo "  [OK]  Added: {$label}\n";
    } catch (PDOException $e) {
        // Already exists — safe to skip
        if (strpos($e->getMessage(), 'Duplicate column name') !== false ||
            strpos($e->getMessage(), "already exists") !== false ||
            strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "  [SKIP] Already exists: {$label}\n";
        } else {
            echo "  [ERR] {$label}: " . $e->getMessage() . "\n";
        }
    }
}

// 5. Update existing members (who signed up via password) to have auth_provider = 'password'
try {
    $pdo->exec("UPDATE members SET auth_provider = 'password' WHERE auth_provider = 'password' AND google_id IS NULL");
    echo "  [OK]  Set auth_provider='password' for existing members\n";
} catch (PDOException $e) {
    echo "  [ERR] Backfill: " . $e->getMessage() . "\n";
}

echo "\n=== Migration Complete ===\n";
