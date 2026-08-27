<?php
/**
 * Multi-Factor Progressive Login Rate Limiter
 * 
 * Provides defense against brute-force and credential stuffing attacks.
 * Tracks (Identifier + IP + Endpoint) with progressive exponential cooldowns.
 * Prevents permanent account lockouts to safeguard against denial-of-service abuse.
 */

// Define progressive thresholds and cooldowns in seconds
if (!defined('RATE_LIMIT_WINDOW_SECONDS')) define('RATE_LIMIT_WINDOW_SECONDS', 900); // 15 minutes window
if (!defined('RATE_LIMIT_MAX_ATTEMPTS')) define('RATE_LIMIT_MAX_ATTEMPTS', 5); // Start throttling after 5 attempts

/**
 * Ensure rate limit database table exists
 */
function ensure_rate_limit_table(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;

    $sql = "CREATE TABLE IF NOT EXISTS login_rate_limits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(191) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        endpoint VARCHAR(50) NOT NULL,
        failed_attempts INT NOT NULL DEFAULT 1,
        lockout_until DATETIME NULL,
        last_attempt_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_ident_endpoint (identifier, endpoint),
        INDEX idx_ip_endpoint (ip_address, endpoint),
        INDEX idx_lockout (lockout_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    try {
        $pdo->exec($sql);
        $checked = true;
    } catch (Exception $e) {
        error_log("RateLimiter Table Init Error: " . $e->getMessage());
    }
}

/**
 * Safely obtain client IP address
 */
function get_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    // Check forwarded headers if behind trusted reverse proxy
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
    }
    
    // Filter and sanitize IP format (IPv4 or IPv6)
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = '127.0.0.1';
    }
    
    return substr($ip, 0, 45);
}

/**
 * Check if a login attempt is allowed or currently locked out
 * 
 * @return array ['allowed' => bool, 'wait_seconds' => int, 'attempts' => int, 'message' => string]
 */
function check_rate_limit(PDO $pdo, string $identifier, string $endpoint = 'general'): array {
    ensure_rate_limit_table($pdo);
    
    $clean_identifier = strtolower(trim($identifier));
    $ip = get_client_ip();
    $now = date('Y-m-d H:i:s');
    
    try {
        // Opportunistic cleanup: 5% chance to prune entries older than 24h
        if (mt_rand(1, 100) <= 5) {
            $pdo->exec("DELETE FROM login_rate_limits WHERE last_attempt_at < DATE_SUB(NOW(), INTERVAL 24 HOUR) AND (lockout_until IS NULL OR lockout_until < NOW())");
        }

        // Check if there is an active lockout for this (identifier + endpoint) or (ip + endpoint)
        $stmt = $pdo->prepare("
            SELECT failed_attempts, lockout_until, last_attempt_at 
            FROM login_rate_limits 
            WHERE (identifier = ? OR ip_address = ?) 
              AND endpoint = ? 
              AND lockout_until > ? 
            ORDER BY lockout_until DESC 
            LIMIT 1
        ");
        $stmt->execute([$clean_identifier, $ip, $endpoint, $now]);
        $lockout_record = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($lockout_record && !empty($lockout_record['lockout_until'])) {
            $lockout_time = strtotime($lockout_record['lockout_until']);
            $current_time = time();
            $wait_seconds = max(1, $lockout_time - $current_time);
            $wait_minutes = ceil($wait_seconds / 60);

            return [
                'allowed'      => false,
                'wait_seconds' => $wait_seconds,
                'attempts'     => (int)$lockout_record['failed_attempts'],
                'message'      => "Too many failed attempts. Please try again in {$wait_minutes} minute" . ($wait_minutes > 1 ? 's' : '') . " ({$wait_seconds}s)."
            ];
        }

        // Get total recent failed attempts within the sliding window
        $stmt_attempts = $pdo->prepare("
            SELECT failed_attempts, last_attempt_at 
            FROM login_rate_limits 
            WHERE identifier = ? AND ip_address = ? AND endpoint = ? 
            LIMIT 1
        ");
        $stmt_attempts->execute([$clean_identifier, $ip, $endpoint]);
        $row = $stmt_attempts->fetch(PDO::FETCH_ASSOC);

        $attempts = 0;
        if ($row) {
            $last_attempt = strtotime($row['last_attempt_at']);
            if (time() - $last_attempt <= RATE_LIMIT_WINDOW_SECONDS) {
                $attempts = (int)$row['failed_attempts'];
            }
        }

        return [
            'allowed'      => true,
            'wait_seconds' => 0,
            'attempts'     => $attempts,
            'message'      => ''
        ];

    } catch (Exception $e) {
        error_log("RateLimiter Check Error: " . $e->getMessage());
        return ['allowed' => true, 'wait_seconds' => 0, 'attempts' => 0, 'message' => ''];
    }
}

/**
 * Record a failed login attempt and apply progressive lockout if threshold reached
 * 
 * Progressive Cooldown Schedule:
 * - 5 attempts: 60s (1 min)
 * - 7 attempts: 300s (5 mins)
 * - 10+ attempts: 900s (15 mins max cap)
 * 
 * @return array ['lockout' => bool, 'wait_seconds' => int, 'attempts' => int, 'message' => string]
 */
function record_failed_login(PDO $pdo, string $identifier, string $endpoint = 'general'): array {
    ensure_rate_limit_table($pdo);
    
    $clean_identifier = strtolower(trim($identifier));
    $ip = get_client_ip();
    $now = date('Y-m-d H:i:s');

    try {
        $stmt = $pdo->prepare("
            SELECT id, failed_attempts, last_attempt_at 
            FROM login_rate_limits 
            WHERE identifier = ? AND ip_address = ? AND endpoint = ? 
            LIMIT 1
        ");
        $stmt->execute([$clean_identifier, $ip, $endpoint]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $attempts = 1;
        if ($existing) {
            $last_attempt = strtotime($existing['last_attempt_at']);
            if (time() - $last_attempt <= RATE_LIMIT_WINDOW_SECONDS) {
                $attempts = (int)$existing['failed_attempts'] + 1;
            } else {
                $attempts = 1; // Reset window if previous attempt was long ago
            }
        }

        // Calculate progressive lockout duration
        $cooldown_seconds = 0;
        if ($attempts >= 10) {
            $cooldown_seconds = 900; // 15 minutes max cap
        } elseif ($attempts >= 7) {
            $cooldown_seconds = 300; // 5 minutes
        } elseif ($attempts >= RATE_LIMIT_MAX_ATTEMPTS) {
            $cooldown_seconds = 60;  // 1 minute
        }

        $lockout_until = null;
        if ($cooldown_seconds > 0) {
            $lockout_until = date('Y-m-d H:i:s', time() + $cooldown_seconds);
        }

        if ($existing) {
            $update = $pdo->prepare("
                UPDATE login_rate_limits 
                SET failed_attempts = ?, lockout_until = ?, last_attempt_at = ? 
                WHERE id = ?
            ");
            $update->execute([$attempts, $lockout_until, $now, $existing['id']]);
        } else {
            $insert = $pdo->prepare("
                INSERT INTO login_rate_limits (identifier, ip_address, endpoint, failed_attempts, lockout_until, last_attempt_at, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([$clean_identifier, $ip, $endpoint, $attempts, $lockout_until, $now, $now]);
        }

        $wait_minutes = ceil($cooldown_seconds / 60);
        $message = $cooldown_seconds > 0 
            ? "Too many failed attempts ({$attempts}). Please wait {$wait_minutes} minute" . ($wait_minutes > 1 ? 's' : '') . " ({$cooldown_seconds}s) before trying again."
            : "Invalid credentials. Attempt {$attempts} of " . RATE_LIMIT_MAX_ATTEMPTS . ".";

        return [
            'lockout'      => $cooldown_seconds > 0,
            'wait_seconds' => $cooldown_seconds,
            'attempts'     => $attempts,
            'message'      => $message
        ];

    } catch (Exception $e) {
        error_log("RateLimiter Record Error: " . $e->getMessage());
        return ['lockout' => false, 'wait_seconds' => 0, 'attempts' => 1, 'message' => 'Invalid credentials.'];
    }
}

/**
 * Clear rate limit records upon successful authentication
 */
function clear_rate_limit(PDO $pdo, string $identifier, string $endpoint = 'general'): void {
    ensure_rate_limit_table($pdo);
    
    $clean_identifier = strtolower(trim($identifier));
    $ip = get_client_ip();

    try {
        $stmt = $pdo->prepare("
            DELETE FROM login_rate_limits 
            WHERE (identifier = ? OR ip_address = ?) AND endpoint = ?
        ");
        $stmt->execute([$clean_identifier, $ip, $endpoint]);
    } catch (Exception $e) {
        error_log("RateLimiter Clear Error: " . $e->getMessage());
    }
}
