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
        SELECT member_id 
        FROM auth_tokens 
        WHERE token = ? AND expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $auth_member_id = $stmt->fetchColumn();

    if (!$auth_member_id) {
        unauthorized('Invalid or expired token');
    }
    
    // Validated! The calling script can now safely use $auth_member_id
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Auth Server Error']);
    exit;
}

