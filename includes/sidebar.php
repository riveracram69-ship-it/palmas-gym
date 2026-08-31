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
        <?php
        $pending_regs_count = 0;
        try {
            if (isset($pdo)) {
                $pending_regs_count = $pdo->query("SELECT COUNT(*) FROM members WHERE account_status = 'Pending'")->fetchColumn();
            }
        } catch (Exception $e) {}
        ?>
        <li class="nav-item">
            <a href="pending-registrations.php" class="nav-link <?php echo nav_active('pending-registrations.php'); ?>">
                <i class="fas fa-user-clock"></i> Pending Approvals
                <?php if ($pending_regs_count > 0): ?>
                    <span style="background:#D97706; color:#fff; border-radius:10px; padding:0.15rem 0.55rem; font-size:0.75rem; font-weight:700; margin-left:auto; display:inline-block; line-height:1;"><?php echo $pending_regs_count; ?></span>
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
        <?php
        $pending_renewals_count = 0;
        try {
            if (isset($pdo)) {
                $pending_renewals_count = $pdo->query("SELECT COUNT(*) FROM renewal_requests WHERE status = 'Pending'")->fetchColumn();
            }
        } catch (Exception $e) {}
        ?>
        <li class="nav-item">
            <a href="renewal-requests.php" class="nav-link <?php echo nav_active('renewal-requests.php'); ?>">
                <i class="fas fa-file-invoice-dollar"></i> Renewal Requests
                <?php if ($pending_renewals_count > 0): ?>
                    <span style="background:var(--danger); color:#fff; border-radius:50%; padding:0.15rem 0.45rem; font-size:0.7rem; font-weight:700; margin-left:auto; display:inline-block; line-height:1;"><?php echo $pending_renewals_count; ?></span>
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
        <div style="display:flex;align-items:center;gap:0.75rem;padding:0.75rem 0.5rem;margin-bottom:0.5rem;">
            <div class="admin-avatar" style="width:36px;height:36px;font-size:0.85rem;border-radius:10px;display:flex;align-items:center;justify-content:center;"><?php echo strtoupper(substr($user['name'] ?? 'A', 0, 1)); ?></div>
            <div style="min-width:0;">
                <div style="font-size:0.875rem;font-weight:600;color:var(--text-main);"><?php echo htmlspecialchars($user['name'] ?? 'Admin'); ?></div>
                <div style="font-size:0.75rem;color:var(--text-muted);"><?php echo htmlspecialchars($user['role'] ?? 'Staff'); ?></div>
            </div>
        </div>
        <a href="logout.php" class="nav-link" style="color:var(--accent);margin-top:0.5rem;padding:0.5rem 0.75rem;">
            <i class="fas fa-arrow-right-from-bracket"></i> Logout
        </a>
    </div>
</aside>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<div class="mobile-header">
    <button class="sidebar-toggle" id="mobileSidebarToggle" aria-label="Toggle Navigation">
        <i class="fas fa-bars"></i>
    </button>
    <div class="mobile-brand-title">
        <?php echo htmlspecialchars($app_settings['gym_name'] ?? 'GYM PRO'); ?>
    </div>
</div>

<main class="main-content">
