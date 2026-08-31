<?php
// api/ping.php — Lightweight keep-alive & health check endpoint
// Used by UptimeRobot/Freshping to keep the Render server warm (prevents cold starts)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo json_encode(['status' => 'ok', 'server' => 'palmas-gym', 'ts' => time()]);
