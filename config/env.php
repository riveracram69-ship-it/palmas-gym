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

    // Database Configuration
    if (!defined('DB_HOST')) define('DB_HOST', $get_conf('DB_HOST', 'localhost'));
    if (!defined('DB_PORT')) define('DB_PORT', $get_conf('DB_PORT', '3306'));
    if (!defined('DB_NAME')) define('DB_NAME', $get_conf('DB_NAME', 'gym_management'));
    if (!defined('DB_USER')) define('DB_USER', $get_conf('DB_USER', 'root'));
    if (!defined('DB_PASS')) define('DB_PASS', $get_conf('DB_PASS', ''));

    // Security Keys
    if (!defined('QR_SECRET_KEY')) {
        define('QR_SECRET_KEY', $get_conf('QR_SECRET_KEY', 'palmas_secret_key_987!_change_me_in_prod'));
    }
    if (!defined('KIOSK_API_KEY')) {
        define('KIOSK_API_KEY', $get_conf('KIOSK_API_KEY', 'kiosk_api_12345'));
    }
    if (!defined('CRON_SECRET_KEY')) {
        define('CRON_SECRET_KEY', $get_conf('CRON_SECRET_KEY', 'palmas_cron_secret_2026'));
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
})();
