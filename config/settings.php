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
if (!isset($app_settings['gym_name'])) $app_settings['gym_name'] = "Palma's Elite Gym";
if (!isset($app_settings['max_capacity'])) $app_settings['max_capacity'] = 50;
if (!isset($app_settings['renewal_threshold_days'])) $app_settings['renewal_threshold_days'] = 7;

// E-Wallet & Payment Configuration Defaults
if (!isset($app_settings['gcash_number'])) $app_settings['gcash_number'] = '0917-000-0000';
if (!isset($app_settings['gcash_name'])) $app_settings['gcash_name'] = "Palma's Elite Gym";
if (!isset($app_settings['gcash_qr_image'])) $app_settings['gcash_qr_image'] = '';
if (!isset($app_settings['maya_number'])) $app_settings['maya_number'] = '0918-000-0000';
if (!isset($app_settings['maya_name'])) $app_settings['maya_name'] = "Palma's Elite Gym";
if (!isset($app_settings['maya_qr_image'])) $app_settings['maya_qr_image'] = '';
?>
