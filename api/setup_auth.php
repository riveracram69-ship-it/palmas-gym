<?php
// api/setup_auth.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// Security Guard: Restrict to CLI execution or logged-in administrator
if (php_sapi_name() !== 'cli' && !is_admin()) {
    http_response_code(403);
    echo "403 Forbidden: Administrator privileges required.";
    exit;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS auth_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "auth_tokens table created successfully!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
