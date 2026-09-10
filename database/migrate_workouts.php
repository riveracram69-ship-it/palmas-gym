<?php
/**
 * database/migrate_workouts.php
 * Idempotent migration for Member Workouts and Fitness Goals tracking.
 */
require_once __DIR__ . '/../config/env.php';

$is_cli = (php_sapi_name() === 'cli');
$is_internal = (defined('ALLOW_INTERNAL_MIGRATION') && ALLOW_INTERNAL_MIGRATION === true);
$cron_key = (string)($_GET['key'] ?? '');
$expected_key = defined('CRON_SECRET_KEY') ? (string)CRON_SECRET_KEY : '';
$is_key_auth = ($expected_key !== '' && $cron_key !== '' && hash_equals($expected_key, $cron_key));

if (!$is_cli && !$is_internal && !$is_key_auth) {
    require_once __DIR__ . '/../config/auth.php';
    if (!function_exists('is_admin') || !is_admin()) {
        http_response_code(403);
        die("403 Forbidden: Maintenance and migration scripts may only be executed via CLI, authorized key, or by an authenticated administrator.");
    }
}

require_once __DIR__ . '/../config/db.php';

if (!isset($pdo) || !$pdo) {
    die("[FATAL] Database connection failed via config/db.php\n");
}

try {
    // 1. Create member_workouts table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `member_workouts` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `member_id` INT(11) NOT NULL,
            `workout_type` VARCHAR(50) NOT NULL DEFAULT 'General',
            `duration_minutes` INT(11) NOT NULL DEFAULT 30,
            `calories_burned` INT(11) NULL DEFAULT 0,
            `notes` TEXT NULL,
            `logged_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_member_workouts_member_id` (`member_id`),
            KEY `idx_member_workouts_logged_at` (`logged_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. Create member_fitness_goals table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `member_fitness_goals` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `member_id` INT(11) NOT NULL,
            `goal_type` VARCHAR(50) NOT NULL DEFAULT 'weekly_workouts',
            `target_value` DECIMAL(6,2) NOT NULL DEFAULT 4.00,
            `current_value` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            `unit` VARCHAR(20) NOT NULL DEFAULT 'sessions',
            `start_date` DATE NOT NULL,
            `target_date` DATE NULL,
            `status` ENUM('In Progress', 'Achieved', 'Abandoned') NOT NULL DEFAULT 'In Progress',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_member_fitness_goals_member_id` (`member_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    if (php_sapi_name() === 'cli') {
        echo "  [+] Member workouts & fitness goals tables created/verified successfully.\n";
    }
} catch (Throwable $e) {
    error_log("Workout migration error: " . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo "  [!] Error: " . $e->getMessage() . "\n";
    }
}
