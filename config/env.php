<?php
/**
 * config/env.php
 * Universal Centralized Environment & Secrets Configuration Loader
 * 
 * Supports:
 * 1. Native server environment variables (Docker, Kubernetes, Apache SetEnv, Nginx fastcgi_param, Systemd)
 * 2. Dotenv (.env) file located outside public webroot or in the project root
 * 3. Safe fallback constants for local development
 */

(function () {
    // 1. Locate .env file in search hierarchy
    $env_candidates = [
        dirname(__DIR__, 2) . '/.env', // 1 level above project (outside webroot)
        dirname(__DIR__) . '/.env',    // Project root
        __DIR__ . '/.env'              // Config directory
    ];

    $loaded_env = [];
    foreach ($env_candidates as $path) {
        if (file_exists($path) && is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    // Skip comments and empty lines
                    if (empty($line) || $line[0] === '#') {
                        continue;
                    }
                    if (strpos($line, '=') !== false) {
                        list($key, $value) = explode('=', $line, 2);
                        $key = trim($key);
                        $value = trim($value);
                        // Strip enclosing quotes
                        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                            $value = substr($value, 1, -1);
                        }
                        $loaded_env[$key] = $value;
                    }
                }
            }
            break; // Found and loaded primary .env file
        }
    }

    /**
     * Helper to read config value from environment, loaded .env, or fallback
     */
    $get_conf = function (string $key, string $default = '') use ($loaded_env): string {
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return $val;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string)$_SERVER[$key];
        }
        if (isset($loaded_env[$key]) && $loaded_env[$key] !== '') {
            return (string)$loaded_env[$key];
        }
        return $default;
    };

    // 2. Define standard application configuration constants
    if (!defined('APP_ENV')) {
        define('APP_ENV', $get_conf('APP_ENV', 'development'));
    }

    if (!defined('APP_URL')) {
        define('APP_URL', rtrim($get_conf('APP_URL', 'http://localhost/gym'), '/'));
    }

    // Database Configuration (Supports DB_HOST or Host, DB_PASS or Password, etc.)
    $db_host_val = $get_conf('DB_HOST', $get_conf('Host', $get_conf('MYSQLHOST', $get_conf('MYSQL_HOST', 'localhost'))));
    $db_port_val = $get_conf('DB_PORT', $get_conf('Port', $get_conf('MYSQLPORT', $get_conf('MYSQL_PORT', '3306'))));
    $db_name_val = $get_conf('DB_NAME', $get_conf('Database', $get_conf('MYSQLDATABASE', $get_conf('MYSQL_DATABASE', 'gym_management'))));
    $db_user_val = $get_conf('DB_USER', $get_conf('User', $get_conf('Username', $get_conf('MYSQLUSER', $get_conf('MYSQL_USER', 'root')))));
    $db_pass_val = $get_conf('DB_PASS', $get_conf('Password', $get_conf('DB_PASSWORD', $get_conf('MYSQLPASSWORD', $get_conf('MYSQL_PASSWORD', '')))));

    if (!defined('DB_HOST')) define('DB_HOST', $db_host_val);
    if (!defined('DB_PORT')) define('DB_PORT', $db_port_val);
    if (!defined('DB_NAME')) define('DB_NAME', $db_name_val);
    if (!defined('DB_USER')) define('DB_USER', $db_user_val);
    if (!defined('DB_PASS')) define('DB_PASS', $db_pass_val);

    // Security Keys
    // IMPORTANT: These MUST be set via environment variables in production.
    // Defaults are intentionally blank. The production guard below will halt if they are missing.
    if (!defined('QR_SECRET_KEY')) {
        define('QR_SECRET_KEY', $get_conf('QR_SECRET_KEY', ''));
    }
    if (!defined('KIOSK_API_KEY')) {
        define('KIOSK_API_KEY', $get_conf('KIOSK_API_KEY', ''));
    }
    if (!defined('CRON_SECRET_KEY')) {
        define('CRON_SECRET_KEY', $get_conf('CRON_SECRET_KEY', ''));
    }

    // [R-01 FIX] Production Security Guard: Halt startup if required secrets are missing.
    // This prevents a misconfigured deployment from running silently with empty keys.
    if ($get_conf('APP_ENV', 'development') === 'production') {
        $missing_secrets = [];
        if (empty(QR_SECRET_KEY) || strlen(QR_SECRET_KEY) < 20)    $missing_secrets[] = 'QR_SECRET_KEY';
        if (empty(KIOSK_API_KEY) || strlen(KIOSK_API_KEY) < 10)    $missing_secrets[] = 'KIOSK_API_KEY';
        if (empty(CRON_SECRET_KEY) || strlen(CRON_SECRET_KEY) < 10) $missing_secrets[] = 'CRON_SECRET_KEY';
        if (!empty($missing_secrets)) {
            $msg = '[CRITICAL] Production deployment is missing required secret keys: ' . implode(', ', $missing_secrets) . '. Set them in your environment variables or .env file.';
            error_log($msg);
            // Halt the request gracefully — do NOT expose the key names to the browser
            http_response_code(503);
            die('<html><body style="font-family:sans-serif;text-align:center;padding:4rem;"><h1>Service Unavailable</h1><p>A required server configuration is missing. Contact the administrator.</p></body></html>');
        }
    }

    // SMTP Email Configuration
    if (!defined('SMTP_HOST')) define('SMTP_HOST', $get_conf('SMTP_HOST', 'smtp.gmail.com'));
    if (!defined('SMTP_PORT')) define('SMTP_PORT', (int)$get_conf('SMTP_PORT', '587'));
    if (!defined('SMTP_USER')) define('SMTP_USER', $get_conf('SMTP_USER', 'palmaselitegym.system@gmail.com'));
    if (!defined('SMTP_PASS')) define('SMTP_PASS', $get_conf('SMTP_PASS', ''));
    if (!defined('SMTP_FROM')) define('SMTP_FROM', $get_conf('SMTP_FROM', 'palmaselitegym.system@gmail.com'));
    if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', $get_conf('SMTP_FROM_NAME', "Palma's Elite Gym"));

    // System Binary Paths (optional overrides)
    if (!defined('MYSQLDUMP_PATH')) define('MYSQLDUMP_PATH', $get_conf('MYSQLDUMP_PATH', ''));
    if (!defined('MYSQL_PATH')) define('MYSQL_PATH', $get_conf('MYSQL_PATH', ''));

    // Mobile Application APK URL (Single source of truth for direct download & QR code)
    if (!defined('ANDROID_APK_URL')) {
        $default_apk_url = rtrim(defined('APP_URL') ? APP_URL : 'https://palmas-gym.onrender.com', '/') . '/downloads/palmas-elite-gym.apk';
        define('ANDROID_APK_URL', $get_conf('ANDROID_APK_URL', $default_apk_url));
    }

    // Google OAuth 2.0 Configuration
    // Get your Client ID from: https://console.cloud.google.com
    if (!defined('GOOGLE_CLIENT_ID')) {
        define('GOOGLE_CLIENT_ID', $get_conf('GOOGLE_CLIENT_ID', ''));
    }

    // Payment Mode: 'test' (sandbox/test promo), 'demo', or 'live' (production)
    // Supports PAYMONGO_MODE (primary) and PAYMENT_MODE (backward-compatible)
    if (!defined('PAYMONGO_MODE')) {
        $raw_mode = $get_conf('PAYMONGO_MODE', $get_conf('PAYMENT_MODE', 'test'));
        $mode = strtolower(trim($raw_mode));
        if (!in_array($mode, ['demo', 'test', 'live'], true)) {
            $mode = 'test';
        }
        define('PAYMONGO_MODE', $mode);
    }
    if (!defined('PAYMENT_MODE')) {
        define('PAYMENT_MODE', PAYMONGO_MODE);
    }

    // PayMongo Payment Gateway Configuration (GCash, Maya, Card, GrabPay)
    // Production keys are set in Render Environment Variables or .env
    if (!defined('PAYMONGO_PUBLIC_KEY')) {
        define('PAYMONGO_PUBLIC_KEY', $get_conf('PAYMONGO_PUBLIC_KEY', ''));
    }
    if (!defined('PAYMONGO_SECRET_KEY')) {
        define('PAYMONGO_SECRET_KEY', $get_conf('PAYMONGO_SECRET_KEY', ''));
    }
    if (!defined('PAYMONGO_WEBHOOK_SECRET')) {
        define('PAYMONGO_WEBHOOK_SECRET', $get_conf('PAYMONGO_WEBHOOK_SECRET', ''));
    }
})();

// Ensure system-wide timezone consistency
date_default_timezone_set('Asia/Manila');

if (!function_exists('get_payment_mode')) {
    function get_payment_mode(): string {
        return defined('PAYMENT_MODE') ? strtolower(PAYMENT_MODE) : 'demo';
    }
}

if (!function_exists('is_payment_demo')) {
    function is_payment_demo(): bool {
        return get_payment_mode() === 'demo';
    }
}

if (!function_exists('is_payment_test')) {
    function is_payment_test(): bool {
        return get_payment_mode() === 'test';
    }
}

if (!function_exists('is_payment_live')) {
    function is_payment_live(): bool {
        return get_payment_mode() === 'live';
    }
}

if (!function_exists('is_paymongo_test_mode')) {
    function is_paymongo_test_mode(): bool {
        return get_payment_mode() === 'test';
    }
}

