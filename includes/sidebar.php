<?php
$pending_regs_count = 0;
$pending_renewals_count = 0;
try {
    if (isset($pdo) && $pdo) {
        $counts = $pdo->query("
            SELECT 
                (SELECT COUNT(*) FROM members WHERE account_status = 'Pending') AS pending_regs,
                (SELECT COUNT(*) FROM renewal_requests WHERE status = 'Pending') AS pending_renewals
        ")->fetch(PDO::FETCH_ASSOC);
        $pending_regs_count     = (int)($counts['pending_regs'] ?? 0);
        $pending_renewals_count = (int)($counts['pending_renewals'] ?? 0);
    }
} catch (Exception $e) {
    error_log("Sidebar counts query error: " . $e->getMessage());
}
?>
<aside class="sidebar">
    <div class="brand">
        <div class="sidebar-brand-logo-container">
            <img src="assets/images/palmas-logo.png" alt="Palma's Elite Gym Logo" class="sidebar-brand-logo">
        </div>
        <div class="brand-text">
            <h2><?php echo htmlspecialchars($app_settings['gym_name'] ?? 'GYM PRO'); ?></h2>
            <p>MANAGEMENT SYSTEM</p>
        </div>
    </div>

    <p class="nav-section-label">Main</p>
    <ul class="nav-list">
        <li class="nav-item">
            <a href="index.php" class="nav-link <?php echo nav_active('index.php'); ?>">
                <i class="fas fa-house-chimney"></i> Dashboard
            </a>
        </li>
        <li class="nav-item">
            <a href="members.php" class="nav-link <?php echo nav_active('members.php'); ?>">
                <i class="fas fa-user-group"></i> Members
            </a>
        </li>
        <li class="nav-item">
            <a href="attendance.php" class="nav-link <?php echo nav_active('attendance.php'); ?>">
                <i class="fas fa-qrcode"></i> QR Attendance
            </a>
        </li>
        <li class="nav-item">
            <a href="pending-registrations.php" class="nav-link <?php echo nav_active('pending-registrations.php'); ?>">
                <i class="fas fa-user-clock"></i> Pending Approvals
                <?php if ($pending_regs_count > 0): ?>
                    <span class="badge badge-warning" style="margin-left:auto; font-size:0.72rem; padding:0.15rem 0.55rem; border-radius:var(--radius-full);"><?php echo $pending_regs_count; ?></span>
                <?php endif; ?>
            </a>
        </li>
    </ul>

    <?php if (is_admin()): ?>
    <p class="nav-section-label">Manage</p>
    <ul class="nav-list">
        <li class="nav-item">
            <a href="plans.php" class="nav-link <?php echo nav_active('plans.php'); ?>">
                <i class="fas fa-tags"></i> Membership Plans
            </a>
        </li>
        <li class="nav-item">
            <a href="reports.php" class="nav-link <?php echo nav_active('reports.php'); ?>">
                <i class="fas fa-chart-line"></i> Reports
            </a>
        </li>
        <li class="nav-item">
            <a href="payments.php" class="nav-link <?php echo nav_active('payments.php'); ?>">
                <i class="fas fa-money-bill-wave"></i> Payments
            </a>
        </li>
        <li class="nav-item">
            <a href="renewal-requests.php" class="nav-link <?php echo nav_active('renewal-requests.php'); ?>">
                <i class="fas fa-file-invoice-dollar"></i> Renewal Requests
                <?php if ($pending_renewals_count > 0): ?>
                    <span class="badge badge-danger" style="margin-left:auto; font-size:0.72rem; padding:0.15rem 0.55rem; border-radius:var(--radius-full);"><?php echo $pending_renewals_count; ?></span>
                <?php endif; ?>
            </a>
        </li>
    </ul>

    <p class="nav-section-label">System</p>
    <ul class="nav-list">
        <li class="nav-item">
            <a href="notifications.php" class="nav-link <?php echo nav_active('notifications.php'); ?>">
                <i class="fas fa-bell"></i> Notifications
            </a>
        </li>
        <li class="nav-item">
            <a href="activity-logs.php" class="nav-link <?php echo nav_active('activity-logs.php'); ?>">
                <i class="fas fa-clipboard-list"></i> Activity Logs
            </a>
        </li>
        <li class="nav-item">
            <a href="backup.php" class="nav-link <?php echo nav_active('backup.php'); ?>">
                <i class="fas fa-database"></i> Backup & Restore
            </a>
        </li>
        <li class="nav-item">
            <a href="settings.php" class="nav-link <?php echo nav_active('settings.php'); ?>">
                <i class="fas fa-sliders"></i> System Settings
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <div class="sidebar-footer">
        <div class="sidebar-user-card">
            <div class="admin-avatar"><?php echo strtoupper(substr($user['name'] ?? 'A', 0, 1)); ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($user['name'] ?? 'Admin'); ?></div>
                <div class="sidebar-user-role"><?php echo htmlspecialchars($user['role'] ?? 'Administrator'); ?></div>
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout-btn" title="Sign Out">
            <i class="fas fa-right-from-bracket"></i>
            <span>Log Out</span>
        </a>
    </div>
</aside>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<div class="mobile-header">
    <button class="sidebar-toggle" id="mobileSidebarToggle" aria-label="Toggle Navigation">
        <i class="fas fa-bars"></i>
    </button>
    <div class="mobile-brand-title" style="font-family:'Outfit',sans-serif; font-size:1.1rem; font-weight:800; color:var(--accent);">
        <?php echo htmlspecialchars($app_settings['gym_name'] ?? 'GYM PRO'); ?>
    </div>
</div>

<main class="main-content" id="main-content">
