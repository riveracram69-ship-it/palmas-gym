<?php
require_once 'config/db.php';

$sql = "CREATE TABLE IF NOT EXISTS activity_logs (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT DEFAULT NULL,
    user_name    VARCHAR(255) DEFAULT 'System',
    action       VARCHAR(100) NOT NULL,
    description  TEXT,
    module       VARCHAR(50) DEFAULT 'General',
    ip_address   VARCHAR(45) DEFAULT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_module (module),
    INDEX idx_user   (user_id),
    INDEX idx_date   (created_at)
)";

try {
    $pdo->exec($sql);
    echo "Table 'activity_logs' created successfully.\n";
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
