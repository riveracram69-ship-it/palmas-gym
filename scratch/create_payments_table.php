<?php
require_once 'config/db.php';

$sql = "CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    reference_id INT NOT NULL, 
    reference_type ENUM('Subscription', 'Service') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('Cash', 'GCash', 'Bank Transfer', 'Credit Card') NOT NULL,
    payment_date DATE NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
)";

try {
    $pdo->exec($sql);
    echo "Table 'payments' created successfully.\n";
} catch (PDOException $e) {
    die("Error creating table: " . $e->getMessage());
}
