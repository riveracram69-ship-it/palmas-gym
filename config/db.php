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
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 5,
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
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
