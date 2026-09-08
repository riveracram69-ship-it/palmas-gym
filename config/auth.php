<?php
if (session_status() === PHP_SESSION_NONE) {
    $is_https = (
        (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') ||
        (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"') !== false)
    );

    session_start([
        'cookie_lifetime' => 86400,
        'cookie_secure'   => $is_https,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict', // [R-03 FIX] Changed from Lax to Strict for stronger CSRF defense-in-depth
    ]);
}

// Generate CSRF token if not present
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function get_csrf_token() {
    return $_SESSION['csrf_token'] ?? '';
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Auto-validate all POST requests (excluding login.php and log_attendance.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_page = basename($_SERVER['PHP_SELF']);
    if ($current_page !== 'login.php' && $current_page !== 'log_attendance.php') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($token) || !verify_csrf_token($token)) {
            http_response_code(403);
            echo "Access Denied: Invalid CSRF Token.";
            exit;
        }
    }
}

// Session guard — call this at the top of every protected page
function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    // Periodic session ID regeneration to protect against hijacking
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

function current_user() {
    return [
        'id'   => $_SESSION['user_id']   ?? null,
        'name' => $_SESSION['user_name'] ?? 'Admin',
        'role' => $_SESSION['user_role'] ?? 'staff',
    ];
}

function is_admin() {
    return isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
}

function require_admin() {
    if (!is_admin()) {
        echo "<!DOCTYPE html><html lang='en'><head><title>Access Denied</title><link rel='stylesheet' href='assets/css/main.css'></head><body><div class='app-container'><main class='main-content' style='margin-left:0;'><div class='topbar'><div class='page-title'><h1>Access Denied</h1><p>Administrator privileges required to view this page.</p><a href='index.php' class='btn btn-primary' style='margin-top:1rem;'>Return to Dashboard</a></div></div></main></div></body></html>";
        exit;
    }
}
?>
