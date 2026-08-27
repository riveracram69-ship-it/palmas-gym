<?php
// Quick diagnostic — test login directly
$ch = curl_init('http://localhost/gym/gym/api/member_login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'username' => 'GYM-757EAD',
    'password' => 'TestPass123!'
]));
$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);
curl_close($ch);

echo "HTTP Status: $status\n";
echo "cURL Error: $err\n";
echo "Response: $body\n";
