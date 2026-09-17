<?php
/**
 * database/migrate_master_v3.php
 * Master Enterprise Idempotent Schema Migration & Data Alignment Engine
 * 
 * Ensures total parity across all environments:
 * - Members table schema (annual_membership_expiry, dob, addresses, emergency contacts)
 * - Membership plans schema (is_active, plan_category, floor_access, duration_minutes, is_test_promo)
 * - Seeds and aligns the 8 Official Tarpaulin Plans + 2 Test Promos
 * - Indexes on members, subscriptions, attendance, payments, rate limits, notifications
 * - Payment method ENUMs (Maya, GCash, Cash, Bank Transfer, Credit Card)
 */

if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/../config/auth.php';
    if (!function_exists('is_admin') || !is_admin()) {
        http_response_code(403);
        die("403 Forbidden: Migration scripts restricted to CLI or administrator.");
    }
}

require_once __DIR__ . '/../config/db.php';

function run_master_v3_migration(PDO $pdo): array {
    $results = [];

    // 1. MEMBERS TABLE
    $member_cols = $pdo->query("SHOW COLUMNS FROM members")->fetchAll(PDO::FETCH_COLUMN);

    $members_additions = [
        'first_name'               => "VARCHAR(100) NULL AFTER full_name",
        'middle_name'              => "VARCHAR(100) NULL AFTER first_name",
        'last_name'                => "VARCHAR(100) NULL AFTER middle_name",
        'extension'                => "VARCHAR(20) NULL AFTER last_name",
        'dob'                      => "DATE NULL AFTER gender",
        'age'                      => "INT NULL AFTER dob",
        'contact_number'           => "VARCHAR(50) NULL AFTER email",
        'address'                  => "TEXT NULL",
        'house_street'             => "VARCHAR(255) NULL",
        'barangay'                 => "VARCHAR(150) NULL",
        'municipality'             => "VARCHAR(150) NULL",
        'province'                 => "VARCHAR(150) NULL",
        'zip_code'                 => "VARCHAR(20) NULL",
        'emergency_contact'        => "VARCHAR(255) NULL",
        'emergency_phone'          => "VARCHAR(50) NULL",
        'annual_membership_expiry' => "DATE NULL",
        'account_status'           => "ENUM('Pending','Approved','Rejected','Suspended') NOT NULL DEFAULT 'Approved'",
        'status'                   => "ENUM('Active','Inactive','Expired','Suspended') NOT NULL DEFAULT 'Inactive'",
        'selected_plan_id'         => "INT(11) NULL",
        'approved_by'              => "INT(11) NULL",
        'approved_at'              => "DATETIME NULL",
        'rejection_reason'         => "TEXT NULL",
        'google_id'                => "VARCHAR(255) NULL",
        'google_picture'           => "VARCHAR(500) NULL",
        'auth_provider'            => "ENUM('password','google','both') NOT NULL DEFAULT 'password'"
    ];

    foreach ($members_additions as $col => $definition) {
        if (!in_array($col, $member_cols)) {
            try {
                $pdo->exec("ALTER TABLE `members` ADD COLUMN `{$col}` {$definition}");
                $results[] = "members: added column `{$col}`";
            } catch (Exception $e) {
                $results[] = "members: warning adding `{$col}`: " . $e->getMessage();
            }
        }
    }

    // 2. MEMBERSHIP_PLANS TABLE
    $plan_cols = $pdo->query("SHOW COLUMNS FROM membership_plans")->fetchAll(PDO::FETCH_COLUMN);

    $plan_additions = [
        'duration_minutes' => "INT(11) NOT NULL DEFAULT 0 AFTER duration_months",
        'is_test_promo'    => "TINYINT(1) NOT NULL DEFAULT 0 AFTER benefits",
        'promo_code'       => "VARCHAR(50) NULL AFTER is_test_promo",
        'promo_enabled'    => "TINYINT(1) NOT NULL DEFAULT 1 AFTER promo_code",
        'plan_category'    => "VARCHAR(50) NOT NULL DEFAULT 'member_pass'",
        'floor_access'     => "VARCHAR(50) NOT NULL DEFAULT 'all'",
        'is_active'        => "TINYINT(1) NOT NULL DEFAULT 1"
    ];

    foreach ($plan_additions as $col => $definition) {
        if (!in_array($col, $plan_cols)) {
            try {
                $pdo->exec("ALTER TABLE `membership_plans` ADD COLUMN `{$col}` {$definition}");
                $results[] = "membership_plans: added column `{$col}`";
            } catch (Exception $e) {
                $results[] = "membership_plans: warning adding `{$col}`: " . $e->getMessage();
            }
        }
    }

    // Deactivate legacy plans
    $pdo->exec("UPDATE `membership_plans` SET `is_active` = 0, `plan_category` = 'legacy' WHERE `id` IN (1, 2, 3, 4)");

    // Seed / align the 8 Official Plans
    $official_plans = [
        8 => [
            'name'     => 'Annual Membership Fee',
            'price'    => 1000.00, 'months' => 12, 'minutes' => 0,
            'category' => 'membership_fee', 'floor' => 'all',
            'benefits' => 'Official 1-Year Membership qualification. Grants discounted Member Daily and Monthly pass rates.'
        ],
        9 => [
            'name'     => 'Member — Monthly Registration',
            'price'    => 750.00, 'months' => 1, 'minutes' => 0,
            'category' => 'member_pass', 'floor' => 'all',
            'benefits' => '1 month unlimited workout access across all gym floors for Official Members.'
        ],
        10 => [
            'name'     => 'Member — Yearly Registration',
            'price'    => 7500.00, 'months' => 12, 'minutes' => 0,
            'category' => 'member_pass', 'floor' => 'all',
            'benefits' => '12 months full facility access with 2 months free rate for Official Members.'
        ],
        11 => [
            'name'     => 'Member — Daily (2nd Floor Only)',
            'price'    => 40.00, 'months' => 0, 'minutes' => 1440,
            'category' => 'member_pass', 'floor' => 'second_floor_only',
            'benefits' => 'Single day 2nd floor cardio & machines pass for active Official Members.'
        ],
        12 => [
            'name'     => 'Member — Daily (Ground + 2nd Floor)',
            'price'    => 50.00, 'months' => 0, 'minutes' => 1440,
            'category' => 'member_pass', 'floor' => 'ground_and_second',
            'benefits' => 'Single day complete access pass (Free weights + Cardio) for active Official Members.'
        ],
        13 => [
            'name'     => 'Non-Member — Monthly Registration',
            'price'    => 850.00, 'months' => 1, 'minutes' => 0,
            'category' => 'non_member_pass', 'floor' => 'all',
            'benefits' => '1 month standard facility access without annual membership requirement.'
        ],
        14 => [
            'name'     => 'Non-Member — Daily (2nd Floor Only)',
            'price'    => 50.00, 'months' => 0, 'minutes' => 1440,
            'category' => 'non_member_pass', 'floor' => 'second_floor_only',
            'benefits' => 'Single day 2nd floor pass for guests and non-members.'
        ],
        15 => [
            'name'     => 'Non-Member — Daily (Ground + 2nd Floor)',
            'price'    => 60.00, 'months' => 0, 'minutes' => 1440,
            'category' => 'non_member_pass', 'floor' => 'ground_and_second',
            'benefits' => 'Single day full facility pass for guests and non-members.'
        ]
    ];

    foreach ($official_plans as $id => $p) {
        $chk = $pdo->prepare("SELECT id FROM `membership_plans` WHERE `id` = ?");
        $chk->execute([$id]);
        if ($chk->fetch()) {
            $upd = $pdo->prepare("
                UPDATE `membership_plans` 
                SET `name` = ?, `price` = ?, `duration_months` = ?, `duration_minutes` = ?, 
                    `plan_category` = ?, `floor_access` = ?, `benefits` = ?, `is_active` = 1, `is_test_promo` = 0 
                WHERE `id` = ?
            ");
            $upd->execute([$p['name'], $p['price'], $p['months'], $p['minutes'], $p['category'], $p['floor'], $p['benefits'], $id]);
            $results[] = "membership_plans: updated official plan #{$id} ({$p['name']})";
        } else {
            $ins = $pdo->prepare("
                INSERT INTO `membership_plans` 
                (`id`, `name`, `price`, `duration_months`, `duration_minutes`, `plan_category`, `floor_access`, `benefits`, `is_active`, `is_test_promo`) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0)
            ");
            $ins->execute([$id, $p['name'], $p['price'], $p['months'], $p['minutes'], $p['category'], $p['floor'], $p['benefits']]);
            $results[] = "membership_plans: created official plan #{$id} ({$p['name']})";
        }
    }

    // Seed test promo plans (30m & 60m)
    $chk30 = $pdo->prepare("SELECT id FROM `membership_plans` WHERE `promo_code` = 'TEST_30M' OR `name` LIKE '%30 MINUTE%' LIMIT 1");
    $chk30->execute();
    $id30 = $chk30->fetchColumn();
    if ($id30) {
        $pdo->prepare("UPDATE `membership_plans` SET `name` = 'PAYMENT TEST — ₱1 — 30 MINUTES', `price` = 1.00, `duration_months` = 0, `duration_minutes` = 30, `is_test_promo` = 1, `promo_code` = 'TEST_30M', `promo_enabled` = 1, `plan_category` = 'test_promo', `floor_access` = 'all', `is_active` = 1 WHERE `id` = ?")->execute([$id30]);
    } else {
        $pdo->prepare("INSERT INTO `membership_plans` (`name`, `price`, `duration_months`, `duration_minutes`, `is_test_promo`, `promo_code`, `promo_enabled`, `plan_category`, `floor_access`, `is_active`) VALUES ('PAYMENT TEST — ₱1 — 30 MINUTES', 1.00, 0, 30, 1, 'TEST_30M', 1, 'test_promo', 'all', 1)")->execute();
    }

    $chk60 = $pdo->prepare("SELECT id FROM `membership_plans` WHERE `promo_code` = 'TEST_60M' OR `name` LIKE '%60 MINUTE%' LIMIT 1");
    $chk60->execute();
    $id60 = $chk60->fetchColumn();
    if ($id60) {
        $pdo->prepare("UPDATE `membership_plans` SET `name` = 'PAYMENT TEST — ₱1 — 60 MINUTES', `price` = 1.00, `duration_months` = 0, `duration_minutes` = 60, `is_test_promo` = 1, `promo_code` = 'TEST_60M', `promo_enabled` = 1, `plan_category` = 'test_promo', `floor_access` = 'all', `is_active` = 1 WHERE `id` = ?")->execute([$id60]);
    } else {
        $pdo->prepare("INSERT INTO `membership_plans` (`name`, `price`, `duration_months`, `duration_minutes`, `is_test_promo`, `promo_code`, `promo_enabled`, `plan_category`, `floor_access`, `is_active`) VALUES ('PAYMENT TEST — ₱1 — 60 MINUTES', 1.00, 0, 60, 1, 'TEST_60M', 1, 'test_promo', 'all', 1)")->execute();
    }

    // 3. PAYMENT ENUM ALIGNMENT (Maya, GCash, Cash, Bank Transfer, Credit Card)
    try {
        $pdo->exec("ALTER TABLE `payments` MODIFY COLUMN `payment_method` ENUM('Cash','GCash','Maya','Bank Transfer','Credit Card') NOT NULL");
        $results[] = "payments: updated payment_method enum";
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE `renewal_requests` MODIFY COLUMN `payment_method` ENUM('Cash','GCash','Maya','Bank Transfer','Credit Card') NOT NULL");
        $results[] = "renewal_requests: updated payment_method enum";
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE `payment_transactions` MODIFY COLUMN `payment_method` ENUM('CASH','GCASH','MAYA','QRPH','BANK_TRANSFER','CREDIT_CARD','GRAB_PAY') NOT NULL DEFAULT 'GCASH'");
        $results[] = "payment_transactions: updated payment_method enum";
    } catch (Exception $e) {}

    // 4. RATE LIMITS INDEXES
    $rate_indexes = $pdo->query("SHOW INDEX FROM login_rate_limits")->fetchAll(PDO::FETCH_ASSOC);
    $rate_idx_names = array_column($rate_indexes, 'Key_name');

    if (!in_array('idx_ident_endpoint', $rate_idx_names)) {
        try {
            $pdo->exec("CREATE INDEX idx_ident_endpoint ON login_rate_limits (identifier, endpoint)");
            $results[] = "login_rate_limits: added idx_ident_endpoint";
        } catch (Exception $e) {}
    }
    if (!in_array('idx_ip_endpoint', $rate_idx_names)) {
        try {
            $pdo->exec("CREATE INDEX idx_ip_endpoint ON login_rate_limits (ip_address, endpoint)");
            $results[] = "login_rate_limits: added idx_ip_endpoint";
        } catch (Exception $e) {}
    }
    if (!in_array('idx_lockout', $rate_idx_names)) {
        try {
            $pdo->exec("CREATE INDEX idx_lockout ON login_rate_limits (lockout_until)");
            $results[] = "login_rate_limits: added idx_lockout";
        } catch (Exception $e) {}
    }

    // 5. SUBSCRIPTIONS DATETIME PRECISION & INDEXES
    try {
        $pdo->exec("ALTER TABLE `subscriptions` MODIFY COLUMN `start_date` DATETIME NOT NULL");
        $pdo->exec("ALTER TABLE `subscriptions` MODIFY COLUMN `expiry_date` DATETIME NOT NULL");
        $results[] = "subscriptions: verified DATETIME precision for start_date & expiry_date";
    } catch (Exception $e) {}

    $sub_indexes = $pdo->query("SHOW INDEX FROM subscriptions")->fetchAll(PDO::FETCH_ASSOC);
    $sub_idx_names = array_column($sub_indexes, 'Key_name');
    if (!in_array('idx_sub_member_expiry', $sub_idx_names)) {
        try {
            $pdo->exec("CREATE INDEX idx_sub_member_expiry ON subscriptions (member_id, expiry_date)");
            $results[] = "subscriptions: added composite index idx_sub_member_expiry";
        } catch (Exception $e) {}
    }

    // 6. ATTENDANCE LOOKUP INDEX
    $att_indexes = $pdo->query("SHOW INDEX FROM attendance")->fetchAll(PDO::FETCH_ASSOC);
    $att_idx_names = array_column($att_indexes, 'Key_name');
    if (!in_array('idx_attendance_date', $att_idx_names)) {
        try {
            $pdo->exec("CREATE INDEX idx_attendance_date ON attendance (date)");
            $results[] = "attendance: added index idx_attendance_date";
        } catch (Exception $e) {}
    }

    // 7. PAYMENTS INDEX
    $pay_indexes = $pdo->query("SHOW INDEX FROM payments")->fetchAll(PDO::FETCH_ASSOC);
    $pay_idx_names = array_column($pay_indexes, 'Key_name');
    if (!in_array('idx_payments_member_date', $pay_idx_names)) {
        try {
            $pdo->exec("CREATE INDEX idx_payments_member_date ON payments (member_id, payment_date)");
            $results[] = "payments: added composite index idx_payments_member_date";
        } catch (Exception $e) {}
    }

    // 8. ANTI-REPLAY USED QR TOKENS TABLE
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `used_qr_tokens` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `token_sig` VARCHAR(64) NOT NULL UNIQUE,
                `membership_id` VARCHAR(50) NOT NULL,
                `member_id` INT NOT NULL,
                `time_slot` INT NOT NULL,
                `action` VARCHAR(20) NOT NULL DEFAULT 'check-in',
                `used_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_used_qr_sig` (`token_sig`),
                INDEX `idx_used_qr_time` (`used_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $results[] = "used_qr_tokens: verified anti-replay table exists";
    } catch (Exception $e) {
        $results[] = "used_qr_tokens: warning: " . $e->getMessage();
    }

    return $results;
}

// If run from CLI directly
if (php_sapi_name() === 'cli') {
    echo "=== RUNNING MASTER V3 DATABASE MIGRATION ===\n";
    $log = run_master_v3_migration($pdo);
    foreach ($log as $line) {
        echo "  - $line\n";
    }
    echo "=== MASTER V3 MIGRATION COMPLETE ===\n";
}
