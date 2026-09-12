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
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/paymongo.php';

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

    // Determine accurate base public URL
    $app_url = '';
    if (defined('APP_URL') && !empty(APP_URL) && stripos(APP_URL, 'localhost') === false) {
        $app_url = rtrim(APP_URL, '/');
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'palmas-gym.onrender.com';
        $app_url = "{$scheme}://{$host}";
    }

    $format_qr_url = function(?string $path) use ($app_url): ?string {
        if (empty($path)) return null;
        if (str_starts_with($path, 'data:')) return $path;

        // Strip localhost or relative prefixes if stored in settings
        $clean = preg_replace('#^https?://(localhost|127\.0\.0\.1|10\.0\.2\.2)(:[0-9]+)?(/gym)?/#i', '', $path);
        $clean = ltrim($clean, '/');

        if (str_starts_with($clean, 'http://') || str_starts_with($clean, 'https://')) {
            return $clean;
        }
        return $app_url ? "{$app_url}/{$clean}" : $clean;
    };

    echo json_encode([
        'success'      => true,
        'payment_mode' => function_exists('get_payment_mode') ? get_payment_mode() : 'test',
        'is_test_mode' => function_exists('is_paymongo_test_mode') ? is_paymongo_test_mode() : true,
        'plans'        => $plans,
        'payment_info' => [
            'gcash' => [
                'name'     => $settings['gcash_name'] ?? "Palma's Elite Gym",
                'number'   => $settings['gcash_number'] ?? "0917-888-4961",
                'qr_image' => $format_qr_url($settings['gcash_qr_image'] ?? null)
            ],
            'maya' => [
                'name'     => $settings['maya_name'] ?? "Palma's Elite Gym",
                'number'   => $settings['maya_number'] ?? "0917-888-4961",
                'qr_image' => $format_qr_url($settings['maya_qr_image'] ?? null)
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
