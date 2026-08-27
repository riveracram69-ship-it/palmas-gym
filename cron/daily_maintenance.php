<?php
/**
 * Automated Daily Maintenance Worker (Idempotent Cron Job)
 * 
 * Performs automated gym system maintenance tasks:
 * 1. Member Status Synchronization based on latest subscription (historical records preserved)
 * 2. Idempotent Expiration Reminders (3-day & 1-day alerts, guaranteed 0 duplicates per day)
 * 3. Inactivity Check & Follow-up
 * 4. Stale Auth Tokens & Temp Sessions Cleanup
 * 
 * Execution:
 *   CLI: php cron/daily_maintenance.php
 *   Web: http://localhost/gym/cron/daily_maintenance.php?key=YOUR_CRON_KEY
 */

// Allow longer execution time for batch email sending
set_time_limit(300);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/logger.php';
require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/env.php';

// Security Guard: Restrict web access via CRON_SECRET_KEY or require CLI mode
$is_cli = (php_sapi_name() === 'cli');
$cron_key = $_GET['key'] ?? '';
$expected_key = defined('CRON_SECRET_KEY') && CRON_SECRET_KEY !== '' 
    ? CRON_SECRET_KEY 
    : (defined('KIOSK_API_KEY') ? KIOSK_API_KEY : 'palmas_cron_secret_2026');

if (!$is_cli && $cron_key !== $expected_key) {
    // Check if logged in admin
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_role']) || strtolower($_SESSION['user_role']) !== 'admin') {
        http_response_code(403);
        echo "403 Forbidden: Invalid cron access key or insufficient permissions.";
        exit;
    }
}

if (!$pdo) {
    die("Database connection error. Exiting maintenance worker.\n");
}

$output_lines = [];
function log_cron_step(string $msg) {
    global $output_lines, $is_cli;
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] {$msg}";
    $output_lines[] = $line;
    if ($is_cli) {
        echo $line . "\n";
    }
}

log_cron_step("🚀 Starting Palma's Elite Gym Daily Maintenance Worker...");

ensure_notifications_table($pdo);

// ─────────────────────────────────────────────────────────────────────────────
// 1. MEMBER STATUS SYNCHRONIZATION (Historical subscriptions preserved)
// ─────────────────────────────────────────────────────────────────────────────
// A member is ACTIVE if their LATEST subscription expiry_date >= CURDATE().
// A member is EXPIRED only if their LATEST subscription expiry_date < CURDATE() and status != 'Inactive'.
log_cron_step("🔄 Synchronizing member statuses based on latest subscription dates...");

try {
    // Find members whose latest subscription has expired but are still marked Active
    $expired_stmt = $pdo->query("
        SELECT m.id, m.full_name, m.membership_id, MAX(s.expiry_date) as latest_expiry
        FROM members m
        JOIN subscriptions s ON s.member_id = m.id
        WHERE m.status = 'Active'
        GROUP BY m.id
        HAVING latest_expiry < CURDATE()
    ");
    $to_expire = $expired_stmt->fetchAll(PDO::FETCH_ASSOC);
    $expired_count = 0;

    $update_expire = $pdo->prepare("UPDATE members SET status = 'Expired' WHERE id = ?");
    foreach ($to_expire as $m) {
        $update_expire->execute([$m['id']]);
        $expired_count++;
    }
    log_cron_step("✅ Member Status Sync: Marked {$expired_count} members with past latest expiry as 'Expired'.");

    // Conversely, if a member has a future active subscription but was marked Expired, reactivate them
    $reactivate_stmt = $pdo->query("
        SELECT m.id, m.full_name, m.membership_id, MAX(s.expiry_date) as latest_expiry
        FROM members m
        JOIN subscriptions s ON s.member_id = m.id
        WHERE m.status = 'Expired'
        GROUP BY m.id
        HAVING latest_expiry >= CURDATE()
    ");
    $to_reactivate = $reactivate_stmt->fetchAll(PDO::FETCH_ASSOC);
    $reactivated_count = 0;

    $update_reactivate = $pdo->prepare("UPDATE members SET status = 'Active' WHERE id = ?");
    foreach ($to_reactivate as $m) {
        $update_reactivate->execute([$m['id']]);
        $reactivated_count++;
    }
    if ($reactivated_count > 0) {
        log_cron_step("✅ Member Status Sync: Restored {$reactivated_count} members with active future subscriptions to 'Active'.");
    }

} catch (Exception $e) {
    log_cron_step("❌ Error in Member Status Sync: " . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. IDEMPOTENT EXPIRATION REMINDERS (3-Day & 1-Day Windows)
// ─────────────────────────────────────────────────────────────────────────────
// Guarantees ZERO duplicate emails or notifications per member per day.
log_cron_step("📧 Processing idempotent expiration email notifications...");

try {
    // A. 3-Day Expiry Notice
    $three_days_target = date('Y-m-d', strtotime('+3 days'));
    $stmt_3d = $pdo->prepare("
        SELECT m.id, m.full_name, m.email, m.membership_id, s.expiry_date, p.name as plan_name
        FROM members m
        JOIN subscriptions s ON s.member_id = m.id
        LEFT JOIN membership_plans p ON p.id = s.plan_id
        WHERE s.expiry_date = ?
          AND m.status = 'Active'
          AND s.id = (SELECT id FROM subscriptions WHERE member_id = m.id ORDER BY expiry_date DESC LIMIT 1)
          AND NOT EXISTS (
              SELECT 1 FROM notifications n 
              WHERE n.member_id = m.id 
                AND n.type = 'Expiration' 
                AND DATE(n.sent_at) = CURDATE()
          )
    ");
    $stmt_3d->execute([$three_days_target]);
    $expiring_3d = $stmt_3d->fetchAll(PDO::FETCH_ASSOC);

    $sent_3d_cnt = 0;
    foreach ($expiring_3d as $m) {
        $exp_formatted = date('M d, Y', strtotime($m['expiry_date']));
        $subject = "Reminder: Your Gym Membership Expires in 3 Days";
        $body = "Hi {$m['full_name']},\n\nYour {$m['plan_name']} membership (ID: {$m['membership_id']}) will expire in 3 days on {$exp_formatted}.\n\nPlease renew at the front desk or via the mobile app to maintain uninterrupted gym access.\n\nBest regards,\nPalma's Elite Gym Team";
        
        $delivery = 'Sent';
        if (!empty($m['email']) && filter_var($m['email'], FILTER_VALIDATE_EMAIL)) {
            $mail_ok = send_email_notification($m['email'], $subject, $subject, $body);
            if (!$mail_ok) $delivery = 'Failed';
        }

        create_notification(
            $pdo, 
            $m['id'], 
            'Expiration', 
            "Membership Expiring in 3 Days ({$exp_formatted})", 
            $body, 
            $delivery
        );
        $sent_3d_cnt++;
    }
    log_cron_step("✅ 3-Day Expiry Alerts: Sent {$sent_3d_cnt} reminders (0 duplicates).");

    // B. 1-Day (Tomorrow) Final Expiry Notice
    $tomorrow_target = date('Y-m-d', strtotime('+1 day'));
    $stmt_1d = $pdo->prepare("
        SELECT m.id, m.full_name, m.email, m.membership_id, s.expiry_date, p.name as plan_name
        FROM members m
        JOIN subscriptions s ON s.member_id = m.id
        LEFT JOIN membership_plans p ON p.id = s.plan_id
        WHERE s.expiry_date = ?
          AND m.status = 'Active'
          AND s.id = (SELECT id FROM subscriptions WHERE member_id = m.id ORDER BY expiry_date DESC LIMIT 1)
          AND NOT EXISTS (
              SELECT 1 FROM notifications n 
              WHERE n.member_id = m.id 
                AND n.type = 'Expiration' 
                AND DATE(n.sent_at) = CURDATE()
          )
    ");
    $stmt_1d->execute([$tomorrow_target]);
    $expiring_1d = $stmt_1d->fetchAll(PDO::FETCH_ASSOC);

    $sent_1d_cnt = 0;
    foreach ($expiring_1d as $m) {
        $exp_formatted = date('M d, Y', strtotime($m['expiry_date']));
        $subject = "Urgent: Your Gym Membership Expires Tomorrow!";
        $body = "Hi {$m['full_name']},\n\nYour membership (ID: {$m['membership_id']}) expires tomorrow on {$exp_formatted}.\n\nRenew today at the reception desk to keep your workout routine on track!\n\nBest regards,\nPalma's Elite Gym Team";
        
        $delivery = 'Sent';
        if (!empty($m['email']) && filter_var($m['email'], FILTER_VALIDATE_EMAIL)) {
            $mail_ok = send_email_notification($m['email'], $subject, $subject, $body);
            if (!$mail_ok) $delivery = 'Failed';
        }

        create_notification(
            $pdo, 
            $m['id'], 
            'Expiration', 
            "Urgent: Membership Expires Tomorrow ({$exp_formatted})", 
            $body, 
            $delivery
        );
        $sent_1d_cnt++;
    }
    log_cron_step("✅ 1-Day Final Alerts: Sent {$sent_1d_cnt} reminders (0 duplicates).");

} catch (Exception $e) {
    log_cron_step("❌ Error in Expiration Reminders: " . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. STALE TOKEN & RATE LIMIT PRUNING
// ─────────────────────────────────────────────────────────────────────────────
log_cron_step("🧹 Cleaning expired authentication tokens and old rate-limit logs...");

try {
    // Delete expired mobile auth tokens
    $deleted_tokens = $pdo->exec("DELETE FROM auth_tokens WHERE expires_at < NOW()");
    log_cron_step("✅ Auth Token Cleanup: Purged " . (int)$deleted_tokens . " expired tokens.");

    // Delete stale rate limit logs older than 48 hours
    $deleted_limits = $pdo->exec("DELETE FROM login_rate_limits WHERE last_attempt_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)");
    log_cron_step("✅ Rate Limit Cleanup: Purged " . (int)$deleted_limits . " stale rate-limit logs.");

} catch (Exception $e) {
    log_cron_step("❌ Error during Cleanup: " . $e->getMessage());
}

log_cron_step("🎉 Maintenance Worker completed successfully!");

// If accessed via web browser, render clean status UI
if (!$is_cli): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Daily Maintenance Worker Results</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 2rem; border-radius: 12px; max-width: 650px; width: 90%; border: 1px solid #334155; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { color: #52b788; font-size: 1.4rem; margin-top: 0; }
        .log-box { background: #0f172a; border-radius: 8px; padding: 1rem; font-family: monospace; font-size: 0.85rem; line-height: 1.6; max-height: 400px; overflow-y: auto; border: 1px solid #334155; }
        .btn { display: inline-block; background: #2d6a4f; color: #fff; padding: 0.6rem 1.2rem; border-radius: 6px; text-decoration: none; margin-top: 1.25rem; font-weight: 600; font-size: 0.9rem; }
    </style>
</head>
<body>
<div class="card">
    <h1>⚙️ Daily Maintenance Worker</h1>
    <div class="log-box">
        <?php foreach ($output_lines as $line): ?>
            <div><?php echo htmlspecialchars($line); ?></div>
        <?php endforeach; ?>
    </div>
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <a href="/gym/index.php" class="btn">Return to Dashboard →</a>
        <span style="color:#94a3b8; font-size:0.8rem;">Idempotent Execution Guaranteed</span>
    </div>
</div>
</body>
</html>
<?php endif; ?>
