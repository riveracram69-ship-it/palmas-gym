<?php
/**
 * migrate_backfill_missing_payments.php
 * Palma's Elite Gym Management System
 *
 * Automatically checks and backfills:
 *  1. Any active/approved members with subscriptions who had 0 records in `payments`.
 *  2. Any pending registrations with a selected plan who did not have a corresponding
 *     entry in `renewal_requests` (so pending payments show up in payment history).
 */

require_once __DIR__ . '/config/db.php';

echo "=== PALMAS ELITE GYM: MIGRATING & BACKFILLING PAYMENT HISTORY ===\n";

try {
    // 1. Backfill approved members who have a subscription but no payment record in `payments`
    echo "[1/2] Checking approved subscriptions with missing payment records...\n";
    $missing_sub_stmt = $pdo->query("
        SELECT s.id as subscription_id, s.member_id, s.plan_id, s.start_date, s.created_by,
               p.price as plan_price, m.membership_id, m.full_name
        FROM subscriptions s
        JOIN members m ON s.member_id = m.id
        LEFT JOIN membership_plans p ON s.plan_id = p.id
        LEFT JOIN payments py ON py.subscription_id = s.id
        WHERE py.id IS NULL
    ");
    $missing_subs = $missing_sub_stmt->fetchAll(PDO::FETCH_ASSOC);

    $inserted_payments = 0;
    foreach ($missing_subs as $ms) {
        $price = floatval($ms['plan_price'] ?? 0);
        $ref_num = 'REG-' . $ms['membership_id'] . '-S' . $ms['subscription_id'];

        $insert_pay = $pdo->prepare("
            INSERT INTO payments (member_id, subscription_id, amount, payment_method, reference_number, payment_date, verified_by, notes, created_at)
            VALUES (?, ?, ?, 'Cash', ?, ?, ?, 'Registration Subscription Payment', NOW())
        ");
        $insert_pay->execute([
            $ms['member_id'],
            $ms['subscription_id'],
            $price,
            $ref_num,
            $ms['start_date'] ?: date('Y-m-d'),
            $ms['created_by']
        ]);
        $inserted_payments++;
    }
    echo "  ✓ Backfilled {$inserted_payments} missing payment records in `payments`.\n";

    // 2. Backfill pending registrations into `renewal_requests` so they show in Pending tab
    echo "[2/2] Checking pending member registrations with missing pending renewal requests...\n";
    $missing_req_stmt = $pdo->query("
        SELECT m.id, m.membership_id, m.selected_plan_id, m.created_at, p.name as plan_name
        FROM members m
        LEFT JOIN membership_plans p ON m.selected_plan_id = p.id
        WHERE m.account_status = 'Pending' 
          AND m.selected_plan_id IS NOT NULL 
          AND m.selected_plan_id > 0
          AND m.id NOT IN (SELECT member_id FROM renewal_requests WHERE status = 'Pending')
    ");
    $missing_reqs = $missing_req_stmt->fetchAll(PDO::FETCH_ASSOC);

    $inserted_reqs = 0;
    foreach ($missing_reqs as $mr) {
        $ref = 'REG-' . $mr['membership_id'];
        $insert_req = $pdo->prepare("
            INSERT INTO renewal_requests (member_id, plan_id, payment_method, reference_no, status, notes, created_at)
            VALUES (?, ?, 'Cash', ?, 'Pending', 'Initial Membership Registration Fee', ?)
        ");
        $insert_req->execute([
            $mr['id'],
            $mr['selected_plan_id'],
            $ref,
            $mr['created_at'] ?: date('Y-m-d H:i:s')
        ]);
        $inserted_reqs++;
    }
    echo "  ✓ Backfilled {$inserted_reqs} pending registration requests in `renewal_requests`.\n";

    echo "=== MIGRATION & BACKFILL COMPLETED SUCCESSFULLY ===\n";

} catch (Exception $e) {
    echo "ERROR during backfill migration: " . $e->getMessage() . "\n";
    exit(1);
}
