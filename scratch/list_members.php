<?php
require_once __DIR__ . '/../config/db.php';
$members = $pdo->query("SELECT m.id, m.full_name, m.membership_id, s.expiry_date 
                         FROM members m 
                         LEFT JOIN subscriptions s ON s.member_id = m.id AND s.status='Active'
                         LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach($members as $m) {
    echo "ID:{$m['id']} | {$m['full_name']} | {$m['membership_id']} | Expiry: {$m['expiry_date']}\n";
}
