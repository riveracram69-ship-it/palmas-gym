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
session_destroy();
header('Location: login.php');
exit;
