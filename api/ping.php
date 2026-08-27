<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

echo json_encode([
    'status' => 'online',
    'app' => 'palmas_elite_gym',
    'server_ip' => $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? '127.0.0.1',
    'timestamp' => time()
]);
