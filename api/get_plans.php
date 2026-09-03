<?php
/**
 * api/get_plans.php — Public Endpoint for Active Membership Plans
 * Allows mobile app registration wizard and public views to fetch plans dynamically.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';

try {
    $stmt = $pdo->query("
        SELECT id, name, price, duration_months, benefits 
        FROM membership_plans 
        ORDER BY price ASC
    ");
    // Fetch payment settings (GCash & Maya details uploaded by Admin)
    $settings_stmt = $pdo->query("
        SELECT setting_key, setting_value 
        FROM system_settings 
        WHERE setting_key IN ('gcash_name', 'gcash_number', 'gcash_qr_image', 'maya_name', 'maya_number', 'maya_qr_image')
    ");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    echo json_encode([
        'success' => true,
        'plans' => $plans,
        'payment_info' => [
            'gcash' => [
                'name' => $settings['gcash_name'] ?? "Palma's Elite Gym",
                'number' => $settings['gcash_number'] ?? "0917-888-4961",
                'qr_image' => !empty($settings['gcash_qr_image']) ? $settings['gcash_qr_image'] : null
            ],
            'maya' => [
                'name' => $settings['maya_name'] ?? "Palma's Elite Gym",
                'number' => $settings['maya_number'] ?? "0917-888-4961",
                'qr_image' => !empty($settings['maya_qr_image']) ? $settings['maya_qr_image'] : null
            ]
        ]
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'plans' => [], 
        'payment_info' => [
            'gcash' => ['name' => "Palma's Elite Gym", 'number' => "0917-888-4961", 'qr_image' => null],
            'maya'  => ['name' => "Palma's Elite Gym", 'number' => "0917-888-4961", 'qr_image' => null]
        ],
        'message' => 'Unable to fetch plans.'
    ]);
}
