<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/logger.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/ui_components.php';
require_login();
$user = current_user();

// Determine active page for sidebar highlight
$current_page = basename($_SERVER['PHP_SELF']);

function nav_active($page) {
    global $current_page;
    return ($current_page === $page) ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' | ' : ''; ?>Gym Management</title>
    <meta name="description" content="Gym Membership and Attendance Management System">
    <link rel="stylesheet" href="assets/css/main.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,700;1,700&display=swap" rel="stylesheet">
    <meta name="csrf-token" content="<?php echo get_csrf_token(); ?>">
    <script src="assets/js/main.js" defer></script>
</head>
<body>
<div class="app-container">
