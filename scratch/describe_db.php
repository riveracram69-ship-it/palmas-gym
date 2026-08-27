<?php
require_once __DIR__ . '/../config/db.php';

foreach(['subscriptions', 'payments', 'members'] as $t) {
    echo "--- $t ---\n";
    try {
        $q = $pdo->query("DESCRIBE $t");
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            echo "  {$row['Field']} ({$row['Type']}) Null: {$row['Null']} Key: {$row['Key']} Default: " . json_encode($row['Default']) . "\n";
        }
    } catch (Exception $e) {
        echo "Error describing $t: " . $e->getMessage() . "\n";
    }
}
