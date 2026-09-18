<?php
require_once 'config/auth.php';
require_once 'config/db.php';
require_once 'config/logger.php';

if (isset($_SESSION['user_id'])) {
    log_activity($pdo, 'User Logout', 'Logged out of the system.', 'Auth');
}
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
if (file_exists(__DIR__ . '/member/auth.php')) {
    require_once __DIR__ . '/member/auth.php';
    if (function_exists('clear_member_remember_cookie')) {
        clear_member_remember_cookie();
    }
}
setcookie('peg_member_remember', '', time() - 3600, '/');
session_destroy();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header('Location: login.php?logged_out=1');
exit;
