<?php
require 'config/db.php';

try {
    // Create table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    // Insert defaults
    $stmt = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    $stmt->execute(['gym_name', 'GYM PRO']);
    $stmt->execute(['max_capacity', '50']);
    $stmt->execute(['renewal_threshold_days', '7']);

    echo "Settings table created and populated successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
