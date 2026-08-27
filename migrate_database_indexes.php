<?php
/**
 * Query-Driven Database Performance Indexing Migration
 * 
 * Applies composite indexes optimized specifically for the exact SQL WHERE, JOIN,
 * and ORDER BY clauses executed across Dashboard 2.0, Reports, Kiosk, and Portals.
 */

require_once __DIR__ . '/config/db.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Database Performance Indexing Migration</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 2.5rem; border-radius: 12px; max-width: 700px; width: 90%; border: 1px solid #334155; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { color: #38bdf8; font-size: 1.5rem; margin-top: 0; }
        .log-step { padding: 0.65rem 1rem; border-radius: 6px; margin-bottom: 0.6rem; font-size: 0.88rem; background: #0f172a; border-left: 4px solid #38bdf8; }
        .success { border-left-color: #52b788; color: #52b788; font-weight: 600; }
        .info { border-left-color: #eab308; color: #fde047; }
        .error { border-left-color: #ef4444; color: #ef4444; }
        .btn { display: inline-block; background: #2d6a4f; color: #fff; padding: 0.75rem 1.5rem; border-radius: 6px; text-decoration: none; margin-top: 1rem; font-weight: 600; }
    </style>
</head>
<body>
<div class="card">
    <h1>⚡ Query-Driven Database Indexing</h1>
    <?php
    if (!$pdo) {
        echo "<div class='log-step error'>❌ Database connection failed. Ensure MySQL is running in XAMPP.</div>";
    } else {
        // List of Query-Driven Composite Indexes: [Table, IndexName, ColumnsSQL]
        $indexes_to_apply = [
            // 1. Attendance: live inside gym (date + time_out), live check-in feed (date + time_in), member history
            ['attendance', 'idx_att_date_timeout', '(`date`, `time_out`)'],
            ['attendance', 'idx_att_date_timein',  '(`date`, `time_in`)'],
            ['attendance', 'idx_att_member_date',  '(`member_id`, `date`, `time_in`)'],

            // 2. Subscriptions: active lookup, latest expiry per member, and global expiry scans
            ['subscriptions', 'idx_sub_member_expiry', '(`member_id`, `expiry_date`)'],
            ['subscriptions', 'idx_sub_expiry_date',   '(`expiry_date`)'],

            // 3. Payments: monthly/daily revenue computations, financial reports, member payment ledgers
            ['payments', 'idx_pay_date_created', '(`payment_date`, `created_at`)'],
            ['payments', 'idx_pay_member_date',  '(`member_id`, `payment_date`)'],

            // 4. Renewal Requests: pending queue badge count and review queries
            ['renewal_requests', 'idx_renew_status_created', '(`status`, `created_at`)'],
            ['renewal_requests', 'idx_renew_member_updated', '(`member_id`, `updated_at`)'],

            // 5. Members: status filtering and email lookups
            ['members', 'idx_members_status_created', '(`status`, `created_at`)']
        ];

        try {
            foreach ($indexes_to_apply as [$table, $index_name, $columns_sql]) {
                // Check if index exists
                $exists = $pdo->query("
                    SELECT COUNT(1) 
                    FROM information_schema.STATISTICS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                      AND TABLE_NAME = '{$table}' 
                      AND INDEX_NAME = '{$index_name}'
                ")->fetchColumn();

                if ($exists) {
                    echo "<div class='log-step info'>⚡ `{$table}`.{$index_name} already exists.</div>";
                } else {
                    $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index_name}` {$columns_sql};");
                    echo "<div class='log-step success'>✅ Created index `{$index_name}` on table `{$table}` {$columns_sql}</div>";
                }
            }

            echo "<p style='color:#94a3b8; font-size:0.85rem; margin-top:1.5rem;'>🎉 Ang lahat ng query-driven composite indexes ay matagumpay na nailapat! Mas mabilis na ngayong mag-load ang Dashboard, Reports, at Attendance Kiosk.</p>";
            echo "<a href='/gym/index.php' class='btn'>Return to Dashboard →</a>";

        } catch (Exception $e) {
            echo "<div class='log-step error'>❌ Indexing Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    ?>
</div>
</body>
</html>
