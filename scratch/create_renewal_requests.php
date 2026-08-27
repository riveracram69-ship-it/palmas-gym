<?php
/**
 * scratch/create_renewal_requests.php
 * Migration script to create the renewal_requests table.
 */
require_once __DIR__ . '/../config/db.php';

$sql = "CREATE TABLE IF NOT EXISTS renewal_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    plan_id INT NOT NULL,
    payment_method ENUM('Cash', 'GCash', 'Bank Transfer', 'Credit Card') NOT NULL,
    reference_no VARCHAR(100) DEFAULT NULL,
    status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    processed_by INT DEFAULT NULL,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES membership_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
)";

try {
    $pdo->exec($sql);
    echo "Success: Table 'renewal_requests' has been created.\n";
} catch (PDOException $e) {
    die("Error creating table: " . $e->getMessage() . "\n");
}
