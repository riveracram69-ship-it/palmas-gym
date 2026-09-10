<?php
/**
 * api/get_plans.php — Public Endpoint for Active Membership Plans
 * Allows mobile app registration wizard and public views to fetch plans dynamically.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/cors.php'; // [R-02 FIX] Replaced wildcard CORS with origin-allowlist

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';

try {
    $include_test = isset($_GET['include_test']) ? (int)$_GET['include_test'] : 1;
    
    $sql = "
        SELECT id, name, price, duration_months, duration_minutes, benefits, is_test_promo, promo_code 
        FROM membership_plans 
    ";
    if ($include_test === 0) {
        $sql .= " WHERE is_test_promo = 0 ";
    }
    $sql .= " ORDER BY is_test_promo DESC, price ASC";

    $stmt = $pdo->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    
    $plans = [];
    foreach ($rows as $r) {
        $is_minute_promo = (!empty($r['duration_minutes']) && (int)$r['duration_minutes'] > 0);
        $duration_label  = $is_minute_promo 
            ? ((int)$r['duration_minutes'] . ' Minute' . ((int)$r['duration_minutes'] > 1 ? 's' : ''))
            : ((int)$r['duration_months'] . ' Month' . ((int)$r['duration_months'] > 1 ? 's' : ''));

        $plans[] = [
            'id'               => (int)$r['id'],
            'name'             => $r['name'],
            'price'            => (float)$r['price'],
            'price_formatted'  => '₱' . number_format((float)$r['price'], 2),
            'duration_months'  => (int)$r['duration_months'],
            'duration_minutes' => (int)($r['duration_minutes'] ?? 0),
            'duration_label'   => $duration_label,
            'benefits'         => $r['benefits'] ?? '',
            'is_test_promo'    => ((int)($r['is_test_promo'] ?? 0) === 1),
            'promo_code'       => $r['promo_code'] ?? null
        ];
    }

    // Fetch payment settings (GCash & Maya details uploaded by Admin)
    $settings_stmt = $pdo->query("
        SELECT setting_key, setting_value 
        FROM system_settings 
        WHERE setting_key IN ('gcash_name', 'gcash_number', 'gcash_qr_image', 'maya_name', 'maya_number', 'maya_qr_image')
    ");
    $settings = $settings_stmt ? $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];

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
