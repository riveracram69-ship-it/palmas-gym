<?php
// Diagnostic: check kiosk endpoint responses
$KIOSK = 'kiosk_api_12345';

// Test 1: no key
$ch = curl_init('http://localhost/gym/gym/modules/attendance/log_attendance.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, ['membership_id' => 'GYM-757EAD']);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "No key: HTTP $code | $body\n\n";

// Test 2: with key, invalid format
$ch = curl_init('http://localhost/gym/gym/modules/attendance/log_attendance.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Kiosk-Key: $KIOSK"]);
curl_setopt($ch, CURLOPT_POSTFIELDS, ['membership_id' => 'INVALID:FORMAT']);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "Kiosk key + invalid format: HTTP $code | $body\n\n";

// Test 3: with key, tampered HMAC
$slot = floor(time() / 15);
$fake = "GYM-757EAD:$slot:FAKEHMAC1234567";
$ch = curl_init('http://localhost/gym/gym/modules/attendance/log_attendance.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Kiosk-Key: $KIOSK"]);
curl_setopt($ch, CURLOPT_POSTFIELDS, ['membership_id' => $fake]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "Kiosk key + tampered HMAC: HTTP $code | $body\n";
