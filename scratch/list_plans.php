<?php
require_once __DIR__ . '/../config/db.php';
$plans = $pdo->query("SELECT id, name, price, duration_months, duration_minutes, is_active, plan_category FROM membership_plans ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach($plans as $p) {
    echo "ID:{$p['id']} | {$p['name']} | ₱{$p['price']} | is_active: " . var_export($p['is_active'], true) . " | plan_category: " . var_export($p['plan_category'], true) . PHP_EOL;
}

