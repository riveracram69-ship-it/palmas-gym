<?php
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Complete Database Relational Foreign Key Migration</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 2.5rem; border-radius: 12px; max-width: 650px; width: 90%; border: 1px solid #334155; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { color: #52b788; font-size: 1.5rem; margin-top: 0; }
        .log-step { padding: 0.7rem 1rem; border-radius: 6px; margin-bottom: 0.6rem; font-size: 0.88rem; background: #0f172a; border-left: 4px solid #38bdf8; }
        .success { border-left-color: #52b788; color: #52b788; font-weight: bold; }
        .info { border-left-color: #eab308; color: #fde047; }
        .error { border-left-color: #ef4444; color: #ef4444; }
        .btn { display: inline-block; background: #2d6a4f; color: #fff; padding: 0.75rem 1.5rem; border-radius: 6px; text-decoration: none; margin-top: 1.25rem; font-weight: 600; }
    </style>
</head>
<body>
<div class="card">
    <h1>🔗 Complete Relational Foreign Key Migration</h1>
    <?php
    if (!$pdo) {
        echo "<div class='log-step error'>❌ Database connection failed. Please ensure MySQL is running in XAMPP.</div>";
    } else {
        function add_fk_if_missing($pdo, $table, $fk_name, $alter_sql, $cleanup_sql = '') {
            if ($cleanup_sql) {
                $pdo->exec($cleanup_sql);
            }
            $exists = $pdo->query("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.TABLE_CONSTRAINTS 
                WHERE TABLE_SCHEMA = DATABASE() 
                  AND TABLE_NAME = '{$table}' 
                  AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                  AND CONSTRAINT_NAME = '{$fk_name}'
            ")->fetchColumn();

            if ($exists) {
                echo "<div class='log-step info'>⚡ `{$table}`.{$fk_name} is already connected.</div>";
            } else {
                $pdo->exec($alter_sql);
                echo "<div class='log-step success'>🎉 Successfully created Foreign Key `{$fk_name}` on `{$table}`!</div>";
            }
        }

        try {
            // 1. auth_tokens -> members(id)
            add_fk_if_missing(
                $pdo,
                'auth_tokens',
                'fk_auth_tokens_member',
                "ALTER TABLE `auth_tokens` ADD CONSTRAINT `fk_auth_tokens_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;",
                "DELETE FROM `auth_tokens` WHERE `member_id` NOT IN (SELECT `id` FROM `members`);"
            );

            // 2. payments -> members(id)
            add_fk_if_missing(
                $pdo,
                'payments',
                'fk_payments_member',
                "ALTER TABLE `payments` ADD CONSTRAINT `fk_payments_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;",
                "DELETE FROM `payments` WHERE `member_id` NOT IN (SELECT `id` FROM `members`);"
            );

            // 3. activity_logs -> users(id)
            add_fk_if_missing(
                $pdo,
                'activity_logs',
                'fk_activity_logs_user',
                "ALTER TABLE `activity_logs` ADD CONSTRAINT `fk_activity_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;",
                "UPDATE `activity_logs` SET `user_id` = NULL WHERE `user_id` IS NOT NULL AND `user_id` NOT IN (SELECT `id` FROM `users`);"
            );

            // 4. notifications -> members(id)
            add_fk_if_missing(
                $pdo,
                'notifications',
                'fk_notifications_member',
                "ALTER TABLE `notifications` ADD CONSTRAINT `fk_notifications_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;",
                "DELETE FROM `notifications` WHERE `member_id` NOT IN (SELECT `id` FROM `members`);"
            );

            echo "<p style='color:#94a3b8; font-size:0.85rem; margin-top:1.5rem;'>🎉 Kumpleto na ang lahat ng relational links sa database! Maaari mo nang i-refresh ang phpMyAdmin Designer.</p>";
            echo "<a href='/gym/index.php' class='btn'>Return to Dashboard →</a>";

        } catch (Exception $e) {
            echo "<div class='log-step error'>❌ Migration Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    ?>
</div>
</body>
</html>
