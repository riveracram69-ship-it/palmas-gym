<?php
/**
 * migrate_fix_plan_durations.php
 * Automated database repair & self-healing migration for plan durations and subscription dates.
 * Fixes test promo plans (30m, 60m) that had duration_months = 1 instead of minutes,
 * and repairs existing subscriptions that were erroneously extended by 1 month.
 */

require_once __DIR__ . '/config/db.php';

echo "=== PALMA'S ELITE GYM: REPAIR PLAN DURATIONS & SUBSCRIPTIONS ===\n";

if (!isset($pdo) || !$pdo) {
    die("[FATAL] Could not connect to database.\n");
}

try {
    // 1. Ensure membership_plans has duration_minutes and is_test_promo columns
    $cols = $pdo->query("SHOW COLUMNS FROM membership_plans")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('duration_minutes', $cols)) {
        $pdo->exec("ALTER TABLE membership_plans ADD COLUMN duration_minutes INT(11) DEFAULT 0 AFTER duration_months");
        echo "  [+] Added duration_minutes column to membership_plans\n";
    }
    if (!in_array('is_test_promo', $cols)) {
        $pdo->exec("ALTER TABLE membership_plans ADD COLUMN is_test_promo TINYINT(1) DEFAULT 0 AFTER benefits");
        echo "  [+] Added is_test_promo column to membership_plans\n";
    }

    // 2. Fix test promo plans with explicit minute durations
    $pdo->exec("
        UPDATE membership_plans 
        SET duration_minutes = 30, duration_months = 0, is_test_promo = 1 
        WHERE name LIKE '%30 MINUTE%' OR name LIKE '%30M%' OR promo_code = 'TEST_30M'
    ");
    $pdo->exec("
        UPDATE membership_plans 
        SET duration_minutes = 60, duration_months = 0, is_test_promo = 1 
        WHERE name LIKE '%60 MINUTE%' OR name LIKE '%60M%' OR promo_code = 'TEST_60M'
    ");
    $pdo->exec("
        UPDATE membership_plans 
        SET duration_minutes = 15, duration_months = 0, is_test_promo = 1 
        WHERE name LIKE '%15 MINUTE%' OR name LIKE '%15M%'
    ");
    echo "  [✓] Updated standard minute-based plans in membership_plans.\n";

    // 3. Regex scan for any plans with minutes in their name
    $plans = $pdo->query("SELECT id, name, duration_minutes, duration_months FROM membership_plans")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($plans as $p) {
        if (preg_match('/(\d+)\s*(?:min|minute)/i', $p['name'], $m)) {
            $mins = intval($m[1]);
            if ($mins > 0 && ($p['duration_minutes'] != $mins || $p['duration_months'] != 0)) {
                $pdo->prepare("UPDATE membership_plans SET duration_minutes = ?, duration_months = 0, is_test_promo = 1 WHERE id = ?")
                    ->execute([$mins, $p['id']]);
                echo "  [✓] Repaired plan #{$p['id']} '{$p['name']}' -> duration_minutes = {$mins}, duration_months = 0\n";
            }
        }
    }

    // 4. Correct erroneously long subscriptions (e.g. 60-minute promo set to 1 month)
    $sub_stmt = $pdo->query("
        SELECT s.id, s.member_id, s.plan_id, s.start_date, s.expiry_date, s.created_at,
               p.name as plan_name, p.duration_minutes
        FROM subscriptions s
        JOIN membership_plans p ON s.plan_id = p.id
        WHERE (p.duration_minutes > 0 OR p.name LIKE '%MINUTE%')
          AND TIMESTAMPDIFF(HOUR, s.start_date, s.expiry_date) > 24
    ");
    $bad_subs = $sub_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($bad_subs as $bs) {
        $mins = intval($bs['duration_minutes']);
        if ($mins <= 0 && preg_match('/(\d+)\s*(?:min|minute)/i', $bs['plan_name'], $m)) {
            $mins = intval($m[1]);
        }
        if ($mins <= 0) $mins = 60; // fallback to 60 minutes

        $start_ts = !empty($bs['start_date']) ? strtotime($bs['start_date']) : (!empty($bs['created_at']) ? strtotime($bs['created_at']) : time());
        $corrected_expiry = date('Y-m-d H:i:s', strtotime("+{$mins} minutes", $start_ts));

        $pdo->prepare("UPDATE subscriptions SET expiry_date = ? WHERE id = ?")
            ->execute([$corrected_expiry, $bs['id']]);
        echo "  [✓] Fixed subscription #{$bs['id']} for member #{$bs['member_id']} ({$bs['plan_name']}): Expiry corrected to {$corrected_expiry}\n";
    }

    echo "=== REPAIR COMPLETE ===\n";

} catch (Exception $e) {
    echo "Migration warning: " . $e->getMessage() . "\n";
}
