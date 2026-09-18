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

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/member_helpers.php';

// Generate CSRF token if not present
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('get_csrf_token')) {
    function get_csrf_token() {
        return $_SESSION['csrf_token'] ?? '';
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
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

function set_member_remember_cookie($member_id, $pdo) {
    if (!$pdo) return;
    try {
        $stmt = $pdo->prepare("SELECT id, password_hash FROM members WHERE id = ?");
        $stmt->execute([$member_id]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$m) return;
        
        $expiry = time() + (30 * 86400); // 30 days persistent login
        $secret = defined('QR_SECRET_KEY') ? QR_SECRET_KEY : 'palmas_member_secret_auth_key_889';
        $sig = hash_hmac('sha256', $m['id'] . '|' . $m['password_hash'] . '|' . $expiry, $secret);
        $payload = base64_encode(json_encode([
            'id'  => (int)$m['id'],
            'exp' => $expiry,
            'sig' => $sig
        ]));
        
        $is_https = (
            (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
            (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"') !== false)
        );

        setcookie('peg_member_remember', $payload, [
            'expires'  => $expiry,
            'path'     => '/',
            'secure'   => $is_https,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } catch (\Throwable $e) {}
}

function clear_member_remember_cookie() {
    $is_https = (
        (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
        (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"') !== false)
    );
    setcookie('peg_member_remember', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

function restore_member_session_from_cookie($pdo) {
    if (!empty($_SESSION['member_id'])) {
        return $_SESSION['member_id'];
    }
    if (empty($_COOKIE['peg_member_remember']) || !$pdo) {
        return null;
    }
    try {
        $raw = base64_decode($_COOKIE['peg_member_remember'], true);
        if (!$raw) return null;
        $data = json_decode($raw, true);
        if (!isset($data['id'], $data['exp'], $data['sig'])) return null;
        if ($data['exp'] < time()) {
            clear_member_remember_cookie();
            return null;
        }
        
        $secret = defined('QR_SECRET_KEY') ? QR_SECRET_KEY : 'palmas_member_secret_auth_key_889';
        $stmt = $pdo->prepare("SELECT id, full_name, password_hash, account_status, status FROM members WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$data['id']]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$m || ($m['account_status'] ?? '') !== 'Approved' || ($m['status'] ?? '') === 'Suspended') {
            clear_member_remember_cookie();
            return null;
        }
        
        $expected_sig = hash_hmac('sha256', $m['id'] . '|' . $m['password_hash'] . '|' . $data['exp'], $secret);
        if (hash_equals($expected_sig, $data['sig'])) {
            $_SESSION['member_id']   = $m['id'];
            $_SESSION['member_name'] = $m['full_name'];
            return $m['id'];
        } else {
            clear_member_remember_cookie();
        }
    } catch (\Throwable $e) {
        return null;
    }
    return null;
}

function get_member_photo_url($photo) {
    if (empty($photo)) return null;
    $p = trim($photo);
    if (strpos($p, 'http://') === 0 || strpos($p, 'https://') === 0 || strpos($p, 'data:') === 0) {
        return $p;
    }
    $clean = ltrim($p, '/');
    $clean = preg_replace('/^gym\//i', '', $clean);
    return '../' . $clean;
}

// Prevent browser and proxy caching of authenticated member sessions
if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
}

function require_member_login() {
    global $pdo;
    if (empty($_SESSION['member_id']) && isset($pdo)) {
        restore_member_session_from_cookie($pdo);
    }
    if (!isset($_SESSION['member_id'])) {
        header('Location: login.php');
        exit;
    }
}

function current_member($pdo) {
    if (empty($_SESSION['member_id']) && $pdo) {
        restore_member_session_from_cookie($pdo);
    }
    if (!isset($_SESSION['member_id'])) {
        return null;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT m.*, 
                                      s.expiry_date,
                                      s.start_date,
                                      p.name as plan_name,
                                      p.duration_months,
                                      p.duration_minutes,
                                      p.is_test_promo,
                                      p.plan_category,
                                      p.floor_access
                               FROM members m 
                                LEFT JOIN subscriptions s ON s.id = (
                                    SELECT s2.id FROM subscriptions s2 
                                    LEFT JOIN membership_plans p2 ON p2.id = s2.plan_id
                                    WHERE s2.member_id = m.id AND (p2.plan_category IS NULL OR p2.plan_category != 'membership_fee')
                                    ORDER BY (s2.expiry_date >= NOW()) DESC, s2.expiry_date DESC, s2.id DESC LIMIT 1
                                )
                               LEFT JOIN membership_plans p ON p.id = s.plan_id
                               WHERE m.id = ?");
        $stmt->execute([$_SESSION['member_id']]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($member) {
            $member['photo'] = $member['photo'] ?: ($member['google_picture'] ?? null);
            
            // Gym Access Expiry
            $exp_ts = (!empty($member['expiry_date'])) 
                ? ((strpos($member['expiry_date'], ':') !== false) ? strtotime($member['expiry_date']) : strtotime($member['expiry_date'] . ' 23:59:59'))
                : 0;
            $has_gym_access = ($exp_ts > 0 && $exp_ts >= time());
            $is_expired     = (!$has_gym_access);
            
            $member['is_expired']           = $is_expired;
            $member['has_active_gym_pass']  = $has_gym_access;

            // Official Member Tier (Annual Membership)
            $ann_exp = $member['annual_membership_expiry'] ?? null;
            $is_official = is_official_member($ann_exp);
            $member['is_official_member']                  = $is_official;
            $member['membership_tier']                     = $is_official ? 'Official Member' : 'Non-Member';
            $member['annual_membership_expiry_formatted'] = (!empty($ann_exp) && $ann_exp !== '0000-00-00') ? date('M d, Y', strtotime($ann_exp)) : null;

            // Account is active if approved and either has valid gym access or official membership
            $is_approved = (($member['account_status'] ?? 'Approved') === 'Approved' && ($member['status'] ?? '') !== 'Suspended');
            $member['is_active'] = ($is_approved && ($has_gym_access || $is_official));
        }
        return $member;
    } catch (Exception $e) {
        return null;
    }
}
?>
