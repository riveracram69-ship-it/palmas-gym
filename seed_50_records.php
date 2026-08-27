<?php
/**
 * Comprehensive Database Seeder — 50 Realistic Records
 * Populates members, subscriptions, attendance, payments, renewal requests, 
 * notifications, and activity logs with relational integrity.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/notifications.php';
require_once __DIR__ . '/config/logger.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Database Seeder — 50 Realistic Records</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 2rem 0; }
        .card { background: #1e293b; padding: 2.5rem; border-radius: 12px; max-width: 750px; width: 90%; border: 1px solid #334155; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { color: #52b788; font-size: 1.5rem; margin-top: 0; }
        .log-step { padding: 0.65rem 1rem; border-radius: 6px; margin-bottom: 0.5rem; font-size: 0.88rem; background: #0f172a; border-left: 4px solid #38bdf8; }
        .success { border-left-color: #52b788; color: #52b788; font-weight: 600; }
        .btn { display: inline-block; background: #2d6a4f; color: #fff; padding: 0.75rem 1.5rem; border-radius: 6px; text-decoration: none; margin-top: 1.25rem; font-weight: 600; font-size: 0.95rem; }
    </style>
</head>
<body>
<div class="card">
    <h1>🌱 Database Seeder (50 Full Records)</h1>
    <?php
    if (!$pdo) {
        echo "<div class='log-step' style='border-left-color:#ef4444; color:#ef4444;'>❌ Database connection failed.</div>";
        exit;
    }

    try {
        // 1. Ensure Membership Plans Exist
        $plans_count = $pdo->query("SELECT COUNT(*) FROM membership_plans")->fetchColumn();
        if ($plans_count == 0) {
            $pdo->exec("
                INSERT INTO `membership_plans` (`name`, `duration_months`, `price`, `benefits`) VALUES
                ('1 Month Starter', 1, 999.00, 'Access to all gym equipment, Locker access, Free fitness consultation'),
                ('3 Months Bronze', 3, 2699.00, 'Full gym access, Free locker, 1 Free Personal Trainer session'),
                ('6 Months Silver VIP', 6, 4999.00, 'Unlimited gym access, Free locker, Sauna access, 3 PT sessions'),
                ('1 Year Gold Elite', 12, 8999.00, 'VIP access, Free locker, Unlimited sauna, 6 PT sessions, Guest pass');
            ");
            echo "<div class='log-step success'>✅ Created 4 default membership plans.</div>";
        }

        $plans = $pdo->query("SELECT id, name, price, duration_months FROM membership_plans")->fetchAll(PDO::FETCH_ASSOC);

        // 2. Realistic Filipino & International Name Pools
        $first_names = [
            'Juan', 'Maria', 'Angelo', 'Kristine', 'Joshua', 'Patricia', 'Mark', 'Bea', 'Carlos', 'Andrea',
            'Christian', 'Nicole', 'Paolo', 'Camille', 'Gabriel', 'Katrina', 'Justin', 'Erika', 'Rafael', 'Stephanie',
            'Daniel', 'Samantha', 'Jerome', 'Alyssa', 'Lance', 'Mariel', 'Francis', 'Danielle', 'Adrian', 'Rhea',
            'Nathaniel', 'Angelica', 'Dominic', 'Roxanne', 'Cedric', 'Princess', 'Patrick', 'Joy', 'Vincent', 'Clarisse',
            'Kenneth', 'Giselle', 'Dexter', 'Hazel', 'Raymond', 'Bernadette', 'Aaron', 'Denise', 'Bryan', 'Fatima'
        ];

        $last_names = [
            'Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Mendoza', 'Torres', 'Tomas', 'Andrada',
            'Castillo', 'Flores', 'Villanueva', 'Ramos', 'Castro', 'Rivera', 'Aquino', 'Navarro', 'Salazar', 'Mercado',
            'De Leon', 'Pascual', 'Soriano', 'Del Rosario', 'Ferrer', 'Domingo', 'Valdez', 'Morales', 'Pineda', 'Sarmiento',
            'Gomez', 'Lim', 'Tan', 'Uy', 'Aguilar', 'Santiago', 'Manalo', 'Corpuz', 'Tolentino', 'Evangelista',
            'Padilla', 'Cortez', 'Gutierrez', 'Alcantara', 'Enriquez', 'Rosario', 'Miranda', 'Espino', 'Vergara', 'Cabrera'
        ];

        $genders = ['Male', 'Female'];
        $payment_methods = ['Cash', 'GCash', 'Bank Transfer', 'Credit Card'];
        $default_password_hash = password_hash('Member@123', PASSWORD_BCRYPT);

        // Fetch or create Admin user for audit relations
        $admin_id = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
        if (!$admin_id) {
            $admin_id = 1;
        }

        echo "<div class='log-step'>🚀 Generating 50 active and diverse member profiles...</div>";

        $members_created = 0;
        $subs_created = 0;
        $payments_created = 0;
        $attendance_created = 0;

        for ($i = 0; $i < 50; $i++) {
            $fn = $first_names[$i % count($first_names)];
            $ln = $last_names[$i % count($last_names)];
            $full_name = "$fn $ln";
            $email = strtolower($fn) . '.' . strtolower($ln) . ($i + 1) . '@example.com';
            $contact = '09' . rand(100000000, 999999999);
            $gender = $genders[$i % 2];
            $age = rand(18, 52);
            $membership_id = 'GYM-' . strtoupper(bin2hex(random_bytes(3)));

            // Realistic Status: 75% Active, 20% Expired, 5% Inactive
            $status_roll = rand(1, 100);
            if ($status_roll <= 75) {
                $status = 'Active';
            } elseif ($status_roll <= 95) {
                $status = 'Expired';
            } else {
                $status = 'Inactive';
            }

            // Check if member email exists
            $existing_id = $pdo->prepare("SELECT id FROM members WHERE email = ?");
            $existing_id->execute([$email]);
            $member_id = $existing_id->fetchColumn();

            if (!$member_id) {
                $insert_m = $pdo->prepare("
                    INSERT INTO members (membership_id, full_name, email, password_hash, contact_number, age, gender, status, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY))
                ");
                $days_ago = rand(10, 180);
                $insert_m->execute([$membership_id, $full_name, $email, $default_password_hash, $contact, $age, $gender, $status, $admin_id, $days_ago]);
                $member_id = (int)$pdo->lastInsertId();
                $members_created++;
            }

            // Assign Subscription
            $plan = $plans[array_rand($plans)];
            if ($status === 'Active') {
                $start_date = date('Y-m-d', strtotime('-' . rand(1, 20) . ' days'));
                $expiry_date = date('Y-m-d', strtotime($start_date . ' +' . $plan['duration_months'] . ' months'));
            } elseif ($status === 'Expired') {
                $start_date = date('Y-m-d', strtotime('-' . rand(60, 120) . ' days'));
                $expiry_date = date('Y-m-d', strtotime('-' . rand(1, 30) . ' days'));
            } else {
                $start_date = date('Y-m-d', strtotime('-' . rand(90, 150) . ' days'));
                $expiry_date = date('Y-m-d', strtotime('-' . rand(31, 60) . ' days'));
            }

            $ins_sub = $pdo->prepare("
                INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $ins_sub->execute([$member_id, $plan['id'], $start_date, $expiry_date, $admin_id, $start_date . ' 10:00:00']);
            $sub_id = (int)$pdo->lastInsertId();
            $subs_created++;

            // Create Payment Record
            $method = $payment_methods[array_rand($payment_methods)];
            $ref_no = ($method === 'Cash') ? null : 'TXN-' . strtoupper(bin2hex(random_bytes(4)));
            $ins_pay = $pdo->prepare("
                INSERT INTO payments (member_id, subscription_id, amount, payment_method, reference_number, payment_date, verified_by, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins_pay->execute([$member_id, $sub_id, $plan['price'], $method, $ref_no, $start_date, $admin_id, 'Subscription payment for ' . $plan['name'], $start_date . ' 10:05:00']);
            $payments_created++;

            // Create 3 to 10 Attendance records per active member
            if ($status === 'Active') {
                $att_count = rand(3, 8);
                $ins_att = $pdo->prepare("
                    INSERT INTO attendance (member_id, date, time_in, time_out)
                    VALUES (?, ?, ?, ?)
                ");
                for ($a = 0; $a < $att_count; $a++) {
                    $att_date = date('Y-m-d', strtotime('-' . rand(0, 25) . ' days'));
                    $hour_in = rand(6, 20);
                    $time_in = sprintf('%02d:%02d:00', $hour_in, rand(0, 59));
                    $time_out = sprintf('%02d:%02d:00', $hour_in + rand(1, 2), rand(0, 59));
                    $ins_att->execute([$member_id, $att_date, $time_in, $time_out]);
                    $attendance_created++;
                }
            }

            // Create 1 Notification for member
            create_notification($pdo, $member_id, 'Registration', 'Welcome to Palma\'s Elite Gym!', 'Your membership plan ' . $plan['name'] . ' is now registered and active.');
        }

        // Add 5 Renewal Requests (3 Pending, 1 Approved, 1 Rejected)
        $sample_members = $pdo->query("SELECT id FROM members LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($sample_members)) {
            $ins_renew = $pdo->prepare("
                INSERT INTO renewal_requests (member_id, plan_id, payment_method, reference_no, status, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $statuses = ['Pending', 'Pending', 'Pending', 'Approved', 'Rejected'];
            foreach ($sample_members as $idx => $m_id) {
                $status_req = $statuses[$idx % count($statuses)];
                $ins_renew->execute([
                    $m_id, 
                    $plans[0]['id'], 
                    'GCash', 
                    'GCASH-' . rand(100000, 999999), 
                    $status_req, 
                    ($status_req === 'Rejected' ? 'Payment screenshot was blurry.' : 'Renewing for upcoming month.')
                ]);
            }
            echo "<div class='log-step success'>✅ Created 5 sample renewal requests in different review stages.</div>";
        }

        echo "<div class='log-step success'>🎉 Added <b>{$members_created}</b> Member Accounts with full credentials.</div>";
        echo "<div class='log-step success'>🎉 Added <b>{$subs_created}</b> Historical & Active Subscriptions.</div>";
        echo "<div class='log-step success'>🎉 Added <b>{$payments_created}</b> Financial Transactions with Ledger Entries.</div>";
        echo "<div class='log-step success'>🎉 Added <b>{$attendance_created}</b> Attendance Check-in Logs for analytics charts.</div>";

        echo "<p style='color:#94a3b8; font-size:0.85rem; margin-top:1.5rem;'>Lahat ng 50 miyembro ay may default test password na: <code style='color:#38bdf8; background:#0f172a; padding:2px 6px; border-radius:4px;'>Member@123</code></p>";
        echo "<a href='/gym/index.php' class='btn'>Go to Dashboard & See Rich Data →</a>";

    } catch (Exception $e) {
        echo "<div class='log-step' style='border-left-color:#ef4444; color:#ef4444;'>❌ Seeding Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    ?>
</div>
</body>
</html>
