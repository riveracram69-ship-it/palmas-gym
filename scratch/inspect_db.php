<?php
require_once 'config/db.php';
try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        echo "Table: $table\n";
        $columns = $pdo->query("DESCRIBE $table")->fetchAll();
        foreach ($columns as $col) {
            echo "  - " . $col['Field'] . " (" . $col['Type'] . ")\n";
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
