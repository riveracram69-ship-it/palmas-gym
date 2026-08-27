<?php
require_once __DIR__ . '/../config/db.php';

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $t) {
    echo "=== Table: $t ===\n";
    try {
        $q = $pdo->query("DESCRIBE $t");
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            echo "  {$row['Field']} ({$row['Type']}) Null: {$row['Null']} Key: {$row['Key']} Default: " . json_encode($row['Default']) . "\n";
        }
        
        // Let's also get foreign keys
        $fk_query = "
            SELECT 
                COLUMN_NAME, 
                CONSTRAINT_NAME, 
                REFERENCED_TABLE_NAME, 
                REFERENCED_COLUMN_NAME
            FROM
                INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE
                TABLE_SCHEMA = 'gym_management' AND
                TABLE_NAME = '$t' AND
                REFERENCED_TABLE_NAME IS NOT NULL
        ";
        $fks = $pdo->query($fk_query)->fetchAll();
        if (!empty($fks)) {
            echo "  Foreign Keys:\n";
            foreach ($fks as $fk) {
                echo "    {$fk['COLUMN_NAME']} -> {$fk['REFERENCED_TABLE_NAME']}({$fk['REFERENCED_COLUMN_NAME']}) [{$fk['CONSTRAINT_NAME']}]\n";
            }
        }
        
        // Let's also check row count
        $cnt = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        echo "  Row Count: $cnt\n";
        
    } catch (Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
