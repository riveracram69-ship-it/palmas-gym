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
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' | ' : ''; ?>Palma's Elite Gym</title>
    <meta name="description" content="Palma's Elite Gym — Membership, Attendance &amp; Payment Management System">
    <!-- Performance: preconnect for Google Fonts (Stage 6) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- Static versioned asset (avoids cache-busting on every request) -->
    <link rel="stylesheet" href="assets/css/main.css?v=2.6">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,700;1,700&display=swap" rel="stylesheet">
    <meta name="csrf-token" content="<?php echo get_csrf_token(); ?>">
    <script src="assets/js/main.js?v=2.6" defer></script>
</head>
<body>
<!-- Skip Navigation Landmark (WCAG 2.4.1 — Stage 6 Accessibility) -->
<a href="#main-content" class="skip-to-main" tabindex="1">Skip to main content</a>
<div class="app-container">
