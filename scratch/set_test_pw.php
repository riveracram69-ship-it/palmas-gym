<?php
require 'config/db.php';
$hash = password_hash('TestPass123!', PASSWORD_BCRYPT);
$stmt = $pdo->prepare("UPDATE members SET password_hash = ? WHERE membership_id = 'GYM-757EAD'");
$stmt->execute([$hash]);
$verify = password_verify('TestPass123!', $hash);
echo "Hash set: $hash\n";
echo "Verify test: " . ($verify ? "PASS" : "FAIL") . "\n";
