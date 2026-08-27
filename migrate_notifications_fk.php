<?php
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Database Migration - Notifications Foreign Key</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 2.5rem; border-radius: 12px; max-width: 600px; width: 90%; border: 1px solid #334155; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { color: #52b788; font-size: 1.5rem; margin-top: 0; }
        .log-step { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 0.75rem; font-size: 0.9rem; background: #0f172a; border-left: 4px solid #38bdf8; }
        .success { border-left-color: #52b788; color: #52b788; font-weight: bold; }
        .error { border-left-color: #ef4444; color: #ef4444; }
        .btn { display: inline-block; background: #2d6a4f; color: #fff; padding: 0.75rem 1.5rem; border-radius: 6px; text-decoration: none; margin-top: 1rem; font-weight: 600; }
    </style>
</head>
<body>
<div class="card">
    <h1>🔗 Notifications Foreign Key Migration</h1>
    <?php
    if (!$pdo) {
        echo "<div class='log-step error'>❌ Database connection failed. Please ensure MySQL is running in XAMPP.</div>";
    } else {
        try {
            // Step 1: Ensure table structure
            $pdo->exec("CREATE TABLE IF NOT EXISTS `notifications` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `member_id` INT NOT NULL,
                `type` VARCHAR(50) NOT NULL DEFAULT 'General',
                `title` VARCHAR(255) NOT NULL,
                `message` TEXT NULL,
                `delivery_status` VARCHAR(50) NOT NULL DEFAULT 'Sent',
                `read_status` VARCHAR(50) NOT NULL DEFAULT 'Unread',
                `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            echo "<div class='log-step'>✅ Step 1: Verified `notifications` table structure.</div>";

            // Step 2: Clean orphaned rows
            $deleted = $pdo->exec("DELETE FROM `notifications` WHERE `member_id` NOT IN (SELECT `id` FROM `members`)");
            echo "<div class='log-step'>✅ Step 2: Cleaned orphaned rows (Removed: " . (int)$deleted . ").</div>";

            // Step 3: Check and add Foreign Key
            $fk_check = $pdo->query("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.TABLE_CONSTRAINTS 
                WHERE TABLE_SCHEMA = DATABASE() 
                  AND TABLE_NAME = 'notifications' 
                  AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                  AND CONSTRAINT_NAME = 'fk_notifications_member'
            ")->fetchColumn();

            if ($fk_check) {
                echo "<div class='log-step success'>🎉 Foreign Key `fk_notifications_member` is already active!</div>";
            } else {
                $pdo->exec("
                    ALTER TABLE `notifications`
                    ADD CONSTRAINT `fk_notifications_member`
                    FOREIGN KEY (`member_id`) REFERENCES `members`(`id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE;
                ");
                echo "<div class='log-step success'>🎉 Successfully created Foreign Key `fk_notifications_member` (ON DELETE CASCADE)!</div>";
            }

            echo "<p style='color:#94a3b8; font-size:0.85rem; margin-top:1.5rem;'>Maaari mo nang i-refresh ang phpMyAdmin Designer. Makikita mo na ang linyang nag-uugnay sa `notifications.member_id` papunta sa `members.id`.</p>";
            echo "<a href='/gym/notifications.php' class='btn'>Go to Notifications Panel →</a>";

        } catch (Exception $e) {
            echo "<div class='log-step error'>❌ Migration Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    ?>
</div>
</body>
</html>
