<?php
$pending_regs_count = 0;
$pending_renewals_count = 0;
try {
    if (isset($pdo) && $pdo) {
        $counts = $pdo->query("
            SELECT COUNT(*) AS pending_regs FROM members WHERE account_status = 'Pending'
        ")->fetch(PDO::FETCH_ASSOC);
        $pending_regs_count = (int)($counts['pending_regs'] ?? 0);

        $renew_counts = $pdo->query("
            SELECT COUNT(*) AS pending_renews FROM renewal_requests WHERE status = 'Pending'
        ")->fetch(PDO::FETCH_ASSOC);
        $pending_renewals_count = (int)($renew_counts['pending_renews'] ?? 0);
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
            <h2><?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></h2>
            <p>MANAGEMENT SYSTEM</p>
        </div>
        <button class="sidebar-close-btn" id="sidebarCloseBtn" type="button" aria-label="Close Navigation">
            <i class="fas fa-times"></i>
        </button>
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
        <li class="nav-item">
            <a href="renewal-requests.php" class="nav-link <?php echo nav_active('renewal-requests.php'); ?>">
                <i class="fas fa-arrows-rotate"></i> Renewal Requests
                <?php if ($pending_renewals_count > 0): ?>
                    <span class="badge badge-warning" style="margin-left:auto; font-size:0.72rem; padding:0.15rem 0.55rem; border-radius:var(--radius-full);"><?php echo $pending_renewals_count; ?></span>
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
            <a href="expenses.php" class="nav-link <?php echo nav_active('expenses.php'); ?>">
                <i class="fas fa-file-invoice-dollar"></i> Expenses
            </a>
        </li>
        <li class="nav-item">
            <a href="users.php" class="nav-link <?php echo nav_active('users.php'); ?>">
                <i class="fas fa-user-shield"></i> Staff &amp; Users
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
                <div class="sidebar-user-role">
                    <?php 
                    $role_lower = strtolower($user['role'] ?? 'admin');
                    if ($role_lower === 'admin'): ?>
                        <span style="display:inline-flex;align-items:center;gap:3px;background:rgba(82,183,136,0.2);color:#52b788;padding:1px 7px;border-radius:4px;font-size:0.68rem;font-weight:700;letter-spacing:0.5px;"><i class="fas fa-shield-halved" style="font-size:0.6rem;"></i> ADMIN</span>
                    <?php else: ?>
                        <span style="display:inline-flex;align-items:center;gap:3px;background:rgba(96,165,250,0.2);color:#60a5fa;padding:1px 7px;border-radius:4px;font-size:0.68rem;font-weight:700;letter-spacing:0.5px;"><i class="fas fa-user" style="font-size:0.6rem;"></i> STAFF</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout-btn" title="Sign Out">
            <i class="fas fa-right-from-bracket"></i>
            <span>Log Out</span>
        </a>
    </div>
</aside>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<main class="main-content" id="main-content">
