<?php
require_once __DIR__ . '/env.php';

// Prevent database and query errors from exposing directory structure to users
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Note: QR_SECRET_KEY is now defined in env.php

$host = DB_HOST;
$port = defined('DB_PORT') ? DB_PORT : '3306';
$db   = DB_NAME;
$user = DB_USER;
$pass = DB_PASS;
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    // NOTE: PDO::ATTR_PERSISTENT is intentionally disabled.
    // Render uses Docker containers where persistent connections cause stale
    // connection handles after container restarts — leading to 500 errors.
    PDO::ATTR_ERRMODE               => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE    => PDO::FETCH_ASSOC,
    // CRITICAL: Must be TRUE for Aiven MySQL over SSL.
    // Native prepared statements (false) require an extra protocol round-trip
    // that fails with Aiven's SSL-wrapped MySQL, causing ALL $pdo->prepare()
    // calls to throw PDOException → 500 errors on every authenticated endpoint.
    // Emulated prepares handle binding in PHP → sends one complete query → works.
    PDO::ATTR_EMULATE_PREPARES      => true,
    PDO::ATTR_TIMEOUT               => 10,
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    // Single valid SET statement only
    PDO::MYSQL_ATTR_INIT_COMMAND    => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
];



$pdo = null;
try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // Graceful error handling for database connection failure
    http_response_code(503); // Service Unavailable
    echo "<!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>System Unavailable</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f8f9fa; color: #333; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .error-container { background: white; padding: 3rem; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); text-align: center; max-width: 500px; width: 90%; border-top: 4px solid #ef4444; }
            h1 { color: #ef4444; margin-bottom: 0.5rem; font-size: 1.8rem; }
            p { color: #6b7280; line-height: 1.6; margin-bottom: 1.5rem; font-size: 0.95rem; }
            .icon { font-size: 4rem; color: #fee2e2; margin-bottom: 1rem; }
        </style>
    </head>
    <body>
        <div class='error-container'>
            <div class='icon'>⚠️</div>
            <h1>System Unavailable</h1>
            <p>We are currently experiencing a technical issue and cannot connect to the database. Please contact your system administrator.</p>
            <p style='font-size: 0.8rem; color: #9ca3af;'>Error code: DB_CONN_FAIL</p>
        </div>
    </body>
    </html>";
    exit;
}
?>
