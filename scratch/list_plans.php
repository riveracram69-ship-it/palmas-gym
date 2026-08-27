<?php
require_once __DIR__ . '/../config/db.php';
$plans = $pdo->query("SELECT * FROM membership_plans ORDER BY price ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach($plans as $p) {
    echo "ID:{$p['id']} | {$p['name']} | ₱{$p['price']} | {$p['duration_days']}days\n";
}
