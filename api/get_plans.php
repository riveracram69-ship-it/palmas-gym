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
    
    // By default: only return active plans (is_active = 1). Exclude legacy plans that are deactivated.
    $sql = "
        SELECT id, name, price, duration_months, duration_minutes, benefits,
               is_test_promo, promo_code, is_active, plan_category, floor_access
        FROM membership_plans
        WHERE is_active = 1
    ";
    if ($include_test === 0) {
        $sql .= " AND is_test_promo = 0 AND plan_category != 'test_promo' ";
    }
    // Order: membership_fee first, then member_pass, then non_member_pass, then test promos; within each by price
    $sql .= " ORDER BY 
        CASE plan_category 
            WHEN 'membership_fee' THEN 1 
            WHEN 'member_pass' THEN 2 
            WHEN 'non_member_pass' THEN 3 
            WHEN 'test_promo' THEN 4 
            ELSE 5 
        END, 
        price ASC";

    $stmt = $pdo->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    
    // Index non-member plans for pairing
    $nm_monthly_id = null;
    $nm_2nd_id     = null;
    $nm_both_id    = null;
    foreach ($rows as $r) {
        if (($r['plan_category'] ?? '') === 'non_member_pass') {
            if ((int)$r['duration_months'] === 1) $nm_monthly_id = (int)$r['id'];
            elseif (stripos($r['name'], '2nd Floor Only') !== false) $nm_2nd_id = (int)$r['id'];
            elseif (stripos($r['name'], 'Ground') !== false) $nm_both_id = (int)$r['id'];
        }
    }

    $plans = [];
    foreach ($rows as $r) {
        $dur_min = (int)($r['duration_minutes'] ?? 0);
        $dur_mo  = (int)$r['duration_months'];
        $is_daily = ($dur_min === 1440);

        if ($is_daily) {
            $duration_label = '1 Day';
        } elseif ($dur_min > 0) {
            $duration_label = $dur_min . ' Minute' . ($dur_min > 1 ? 's' : '');
        } else {
            $duration_label = $dur_mo . ' Month' . ($dur_mo > 1 ? 's' : '');
        }

        $is_member_discount = (($r['plan_category'] ?? '') === 'member_pass');
        $regular_price = (float)$r['price'];
        $savings_amount = 0.0;
        $savings_label = '';
        $paired_non_member_id = null;

        if ($is_member_discount) {
            if (stripos($r['name'], 'Monthly') !== false) {
                $regular_price = 850.00;
                $savings_amount = 100.00;
                $savings_label = 'Save ₱100/mo';
                $paired_non_member_id = $nm_monthly_id;
            } elseif (stripos($r['name'], 'Yearly') !== false) {
                $regular_price = 9000.00;
                $savings_amount = 1500.00;
                $savings_label = 'Save ₱1,500/yr';
            } elseif (stripos($r['name'], '2nd Floor Only') !== false) {
                $regular_price = 50.00;
                $savings_amount = 10.00;
                $savings_label = 'Save ₱10';
                $paired_non_member_id = $nm_2nd_id;
            } elseif (stripos($r['name'], 'Ground') !== false) {
                $regular_price = 60.00;
                $savings_amount = 10.00;
                $savings_label = 'Save ₱10';
                $paired_non_member_id = $nm_both_id;
            }
        }

        $plans[] = [
            'id'                   => (int)$r['id'],
            'name'                 => $r['name'],
            'price'                => (float)$r['price'],
            'price_formatted'      => '₱' . number_format((float)$r['price'], 2),
            'duration_months'      => $dur_mo,
            'duration_minutes'     => $dur_min,
            'duration_label'       => $duration_label,
            'benefits'             => $r['benefits'] ?? '',
            'is_test_promo'        => ((int)($r['is_test_promo'] ?? 0) === 1),
            'promo_code'           => $r['promo_code'] ?? null,
            // New fields (additive — backward compatible)
            'plan_category'        => $r['plan_category'] ?? 'member_pass',
            'floor_access'         => $r['floor_access'] ?? 'all',
            'is_member_discount'   => $is_member_discount,
            'regular_price'        => $regular_price,
            'savings_amount'       => $savings_amount,
            'savings_label'        => $savings_label,
            'requires_annual_fee'  => $is_member_discount,
            'paired_non_member_id' => $paired_non_member_id,
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
            'gateway'           => 'PayMongo',
            'supported_methods' => ['GCash', 'Maya', 'Card', 'QR Ph', 'Cash'],
            'online_enabled'    => true
        ]
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'plans' => [], 
        'payment_info' => [
            'gateway'           => 'PayMongo',
            'supported_methods' => ['GCash', 'Maya', 'Card', 'QR Ph', 'Cash'],
            'online_enabled'    => true
        ],
        'message' => 'Unable to fetch plans.'
    ]);
}
