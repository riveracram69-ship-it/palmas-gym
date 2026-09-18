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


    // Check authenticated member if Bearer token present
    $headers = function_exists('apache_request_headers') ? apache_request_headers() : (function_exists('getallheaders') ? getallheaders() : []);
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    $auth_member_id = null;
    $is_official_member = false;
    $member_tier = 'non_member';

    if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', trim($authHeader), $matches)) {
        try {
            $tokenStmt = $pdo->prepare("
                SELECT m.id, m.annual_membership_expiry 
                FROM auth_tokens t
                JOIN members m ON m.id = t.member_id
                WHERE t.token = ? AND t.expires_at > NOW()
                LIMIT 1
            ");
            $tokenStmt->execute([$matches[1]]);
            $mRow = $tokenStmt->fetch(PDO::FETCH_ASSOC);
            if ($mRow) {
                $auth_member_id = (int)$mRow['id'];
                $ann_exp = $mRow['annual_membership_expiry'] ?? null;
                if (!empty($ann_exp) && strtotime($ann_exp . ' 23:59:59') >= time()) {
                    $is_official_member = true;
                    $member_tier = 'member';
                }
            }
        } catch (Throwable $e) {}
    }

    // Override tier via GET param if explicitly requested
    if (isset($_GET['tier'])) {
        $paramTier = strtolower(trim($_GET['tier']));
        if ($paramTier === 'member') {
            $is_official_member = true;
            $member_tier = 'member';
        } elseif ($paramTier === 'non_member') {
            $is_official_member = false;
            $member_tier = 'non_member';
        }
    }

    // Group plans cleanly
    $member_plans = [];
    $non_member_plans = [];
    $membership_fee_plan = null;
    $test_promos = [];

    foreach ($plans as $p) {
        if ($p['plan_category'] === 'membership_fee') {
            $membership_fee_plan = $p;
        } elseif ($p['plan_category'] === 'member_pass') {
            $member_plans[] = $p;
        } elseif ($p['plan_category'] === 'non_member_pass') {
            $non_member_plans[] = $p;
        } elseif ($p['plan_category'] === 'test_promo' || $p['is_test_promo']) {
            $test_promos[] = $p;
        }
    }

    // Eligible plans depending on tier
    $eligible_plans = [];
    if ($is_official_member) {
        // Official Members ONLY see member rates (₱750, ₱7,500, ₱40, ₱50) + test promos
        $eligible_plans = array_merge($member_plans, $test_promos);
        // Include membership fee only for renewal
        if ($membership_fee_plan) {
            $eligible_plans[] = array_merge($membership_fee_plan, ['is_annual_renewal' => true]);
        }
    } else {
        // Non-Members see non-member rates (₱850, ₱50, ₱60), test promos, and the ₱1,000 fee to become a member
        $eligible_plans = array_merge($non_member_plans, $test_promos);
        if ($membership_fee_plan) {
            $eligible_plans[] = array_merge($membership_fee_plan, ['is_upgrade_to_member' => true]);
        }
    }

    echo json_encode([
        'success'             => true,
        'payment_mode'        => function_exists('get_payment_mode') ? get_payment_mode() : 'test',
        'is_test_mode'        => function_exists('is_paymongo_test_mode') ? is_paymongo_test_mode() : true,
        'is_official_member'  => $is_official_member,
        'member_tier'         => $member_tier,
        'plans'               => $plans,               // All active plans for backward compatibility
        'eligible_plans'      => $eligible_plans,      // Contextual plans based on member status
        'member_plans'        => $member_plans,        // Member discounted passes (₱750, ₱7500, ₱40, ₱50)
        'non_member_plans'    => $non_member_plans,    // Non-member passes (₱850, ₱50, ₱60)
        'membership_fee_plan' => $membership_fee_plan, // Annual Membership Fee (₱1,000)
        'payment_info'        => [
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
