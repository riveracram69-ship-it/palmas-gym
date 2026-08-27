<?php
require_once __DIR__ . '/../config/db.php';
$q = $pdo->query("SHOW TABLES");
print_r($q->fetchAll(PDO::FETCH_COLUMN));
