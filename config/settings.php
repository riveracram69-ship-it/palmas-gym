<?php
// Global application settings loader
$app_settings = [];

try {
    // Only fetch if database connection exists
    if (isset($pdo) && $pdo) {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $app_settings[$row['setting_key']] = $row['setting_value'];
        }
    }
} catch (Exception $e) {
    // Fail silently, use defaults below if table doesn't exist yet
}

// Fallback defaults in case database is empty or queries fail
if (!isset($app_settings['gym_name'])) $app_settings['gym_name'] = 'GYM PRO';
if (!isset($app_settings['max_capacity'])) $app_settings['max_capacity'] = 50;
if (!isset($app_settings['renewal_threshold_days'])) $app_settings['renewal_threshold_days'] = 7;
?>
