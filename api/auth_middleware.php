<?php
// api/auth_middleware.php

// Helper to return 401 Unauthorized
function unauthorized($message = 'Unauthorized') {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// Ensure the db connection is available
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
}

// Universal Authorization Header Extraction (Supports Apache, Nginx, LiteSpeed, FastCGI, IIS)
$headers = function_exists('apache_request_headers')
    ? apache_request_headers()
    : (function_exists('getallheaders') ? getallheaders() : []);

$authHeader = $headers['Authorization']
    ?? $headers['authorization']
    ?? $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? null;

if (empty($authHeader) || !preg_match('/Bearer\s+(\S+)/i', trim($authHeader), $matches)) {
    unauthorized('Missing or invalid Authorization header');
}

$token = $matches[1];

// Validate token in database
try {
    $stmt = $pdo->prepare("
        SELECT t.member_id, m.account_status, m.status 
        FROM auth_tokens t
        JOIN members m ON m.id = t.member_id
        WHERE t.token = ? AND t.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $auth_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$auth_data) {
        unauthorized('Invalid or expired session. Please log in again.');
    }

    if (($auth_data['account_status'] ?? 'Approved') !== 'Approved') {
        unauthorized('Account access restricted: ' . ($auth_data['account_status'] ?? 'Pending'));
    }

    if (($auth_data['status'] ?? '') === 'Archived' || ($auth_data['status'] ?? '') === 'Suspended') {
        unauthorized('Your account is currently inactive or suspended. Please contact the front desk.');
    }

    $auth_member_id = (int)$auth_data['member_id'];
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Auth Server Error']);
    exit;
}

