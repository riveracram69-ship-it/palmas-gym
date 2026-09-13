<?php
/**
 * One-time database importer to Aiven MySQL 8.x using PHP 8.2 PDO (caching_sha2_password + SSL compatible)
 */

$host = 'mysql-1f1bdf2d-riveracram69-714e.c.aivencloud.com';
$port = '19776';
$db   = 'defaultdb';
$user = 'avnadmin';
$pass = $argv[1] ?? '';
if (empty($pass)) {
    echo "Usage: c:\\xam\\php\\php.exe import_aiven.php \"YOUR_AIVEN_PASSWORD\"\n";
    exit(1);
}

echo "Connecting to Aiven MySQL ($host:$port/$db)...\n";

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 10,
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    echo "[SUCCESS] Connected to Aiven MySQL database!\n";
} catch (PDOException $e) {
    echo "[ERROR] Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

$sqlFile = __DIR__ . '/gym_management.sql';
if (!file_exists($sqlFile)) {
    echo "[ERROR] SQL file not found at: $sqlFile\n";
    exit(1);
}

echo "Reading gym_management.sql...\n";
$sql = file_get_contents($sqlFile);

echo "Executing SQL statements...\n";
try {
    $pdo->exec($sql);
    echo "[SUCCESS] All gym management tables and initial data imported successfully!\n";
} catch (PDOException $e) {
    echo "[ERROR] Error during import: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nVerifying imported tables in defaultdb:\n";
$stmt = $pdo->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $i => $table) {
    echo "  " . ($i + 1) . ". $table\n";
}

echo "\n🎉 Database is ready for Render deployment!\n";
