<?php
/**
 * Activity Logger Helper
 * Call log_activity() from any PHP page to record an audit trail entry.
 * Requires $pdo to already be available via config/db.php.
 */

/**
 * @param PDO    $pdo         Active PDO connection
 * @param string $action      Short action name e.g. "Added Member"
 * @param string $description Full detail e.g. "Registered John Doe (GYM-ABC123)"
 * @param string $module      Module name: Member | Attendance | Payment | Plan | Service | Auth
 * @param int|null $user_id   ID of the user performing the action
 * @param string $user_name   Display name of the user
 */
function log_activity(PDO $pdo, string $action, string $description = '', string $module = 'General', ?int $user_id = null, string $user_name = 'System'): void
{
    try {
        // Try to get session user if not explicitly passed
        if ($user_id === null && isset($_SESSION['user_id'])) {
            $user_id   = (int) $_SESSION['user_id'];
            $user_name = $_SESSION['user_name'] ?? 'Unknown';
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $stmt = $pdo->prepare(
            "INSERT INTO activity_logs (user_id, user_name, action, description, module, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$user_id, $user_name, $action, $description, $module, $ip]);
    } catch (Exception $e) {
        // Never let logging crash the main app
        error_log('ActivityLogger error: ' . $e->getMessage());
    }
}
