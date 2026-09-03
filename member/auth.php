<?php
if (session_status() === PHP_SESSION_NONE) {
    $is_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_start([
        'cookie_lifetime' => 86400,
        'cookie_secure'   => $is_https,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';

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

// Auto-validate all POST requests (excluding login.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_page = basename($_SERVER['PHP_SELF']);
    if ($current_page !== 'login.php') {
        $token = $_POST['csrf_token'] ?? '';
        if (empty($token)) {
            $headers = getallheaders();
            $token = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        }
        if (empty($token) || !verify_csrf_token($token)) {
            http_response_code(403);
            if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Access Denied: Invalid CSRF Token.']);
            } else {
                echo "Access Denied: Invalid CSRF Token.";
            }
            exit;
        }
    }
}

function require_member_login() {
    if (!isset($_SESSION['member_id'])) {
        header('Location: login.php');
        exit;
    }
}

function current_member($pdo) {
    if (!isset($_SESSION['member_id'])) {
        return null;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT m.*, 
                                      (SELECT s.expiry_date 
                                       FROM subscriptions s 
                                       WHERE s.member_id = m.id AND s.expiry_date >= CURDATE()
                                       ORDER BY s.expiry_date DESC, s.id DESC 
                                       LIMIT 1) as expiry_date,
                                      (SELECT p.name 
                                       FROM subscriptions s 
                                       LEFT JOIN membership_plans p ON p.id = s.plan_id 
                                       WHERE s.member_id = m.id AND s.expiry_date >= CURDATE()
                                       ORDER BY s.expiry_date DESC, s.id DESC 
                                       LIMIT 1) as plan_name 
                               FROM members m 
                               WHERE m.id = ?");
        $stmt->execute([$_SESSION['member_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}
?>
