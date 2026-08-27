<?php
$page_title = 'System Settings';
include 'includes/header.php';
include 'includes/sidebar.php';
require_admin(); // Ensure only admins can access

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gym_name = trim($_POST['gym_name'] ?? '');
    $max_capacity = intval($_POST['max_capacity'] ?? 0);
    $renewal_threshold_days = intval($_POST['renewal_threshold_days'] ?? 0);

    if (empty($gym_name) || $max_capacity <= 0 || $renewal_threshold_days < 0) {
        $error = "Please provide valid values for all fields.";
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->execute([$gym_name, 'gym_name']);
            $stmt->execute([$max_capacity, 'max_capacity']);
            $stmt->execute([$renewal_threshold_days, 'renewal_threshold_days']);

            $pdo->commit();
            log_activity($pdo, 'Updated Settings', 'System settings were updated by admin.', 'System');
            $message = "Settings saved successfully.";
            
            // Update the global array for the current page load
            $app_settings['gym_name'] = $gym_name;
            $app_settings['max_capacity'] = $max_capacity;
            $app_settings['renewal_threshold_days'] = $renewal_threshold_days;

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('System Error in settings.php: ' . $e->getMessage());
            $error = "Failed to update settings due to an internal system error.";
        }
    }
}
?>

<div class="topbar">
    <div class="page-title">
        <h1>System Settings</h1>
        <p>Configure global parameters for the Gym Pro Management System.</p>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card" style="max-width: 800px;">
    <h3 class="section-title"><i class="fas fa-sliders" style="color:var(--accent);"></i> Global Configuration</h3>
    
    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
        <div style="display:flex; flex-direction:column; gap:1.5rem; margin-top:1.5rem;">
            
        <div class="form-group">
            <label for="gym_name">Gym Name (Branding)</label>
            <p class="cell-secondary" style="margin-bottom:0.5rem;">This name will appear in the sidebar, login page, and overall branding.</p>
            <input type="text" id="gym_name" name="gym_name" class="form-control" value="<?php echo htmlspecialchars($app_settings['gym_name']); ?>" required>
        </div>

        <hr style="border:0; border-top:1px solid var(--border); margin:0.5rem 0;">

        <div class="form-group">
            <label for="max_capacity">Maximum Capacity Limit</label>
            <p class="cell-secondary" style="margin-bottom:0.5rem;">The maximum number of members allowed inside the gym simultaneously. Used for the dashboard progress bar.</p>
            <input type="number" id="max_capacity" name="max_capacity" class="form-control" value="<?php echo htmlspecialchars($app_settings['max_capacity']); ?>" min="1" required>
        </div>

        <hr style="border:0; border-top:1px solid var(--border); margin:0.5rem 0;">

        <div class="form-group">
            <label for="renewal_threshold_days">Renewal Notice Threshold (Days)</label>
            <p class="cell-secondary" style="margin-bottom:0.5rem;">Number of days before a membership expires for it to show up in the "Pending Renewals" dashboard alert.</p>
            <input type="number" id="renewal_threshold_days" name="renewal_threshold_days" class="form-control" value="<?php echo htmlspecialchars($app_settings['renewal_threshold_days']); ?>" min="0" required>
        </div>

        <div style="margin-top:1rem;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
        </div>
            
        </div>
    </form>
</div>

<div class="card info-card" style="max-width:800px; margin-top:2rem;">
    <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.5rem;">
        <i class="fas fa-info-circle" style="color:var(--info); font-size:1.2rem;"></i>
        <h4 style="margin:0; color:#1e40af;">Manage Pricing</h4>
    </div>
    <p style="margin:0; font-size:0.9rem; color:#1e3a8a; line-height:1.5;">
        To update membership pricing, durations, and manage the specific packages offered by the gym, please visit the <a href="plans.php" style="color:var(--accent); font-weight:600; text-decoration:none;">Membership Plans</a> module.
    </p>
</div>

<?php include 'includes/footer.php'; ?>
