<?php
// api/ping.php — Lightweight keep-alive & health check endpoint
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo json_encode([
    'status' => 'ok',
    'server' => 'palmas-gym',
    'version' => 'v2.1-emulate-prepares-fixed',
    'commit' => '71cae61',
    'ts' => time()
]);

