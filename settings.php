<?php
$page_title = 'System Settings';
include 'includes/header.php';
include 'includes/sidebar.php';
require_admin(); // Ensure only admins can access

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update_settings';

    if ($action === 'change_password') {
        $current_pw = $_POST['current_password'] ?? '';
        $new_pw     = $_POST['new_password'] ?? '';
        $confirm_pw = $_POST['confirm_password'] ?? '';
        $user_id    = $_SESSION['user_id'] ?? 0;

        if (empty($current_pw) || empty($new_pw) || empty($confirm_pw)) {
            $error = "Please fill in all password fields.";
        } elseif ($new_pw !== $confirm_pw) {
            $error = "New passwords do not match.";
        } elseif (strlen($new_pw) < 6) {
            $error = "New password must be at least 6 characters long.";
        } else {
            try {
                $s = $pdo->prepare("SELECT id, password FROM users WHERE id = ?");
                $s->execute([$user_id]);
                $admin_user = $s->fetch(PDO::FETCH_ASSOC);

                if (!$admin_user || !password_verify($current_pw, $admin_user['password'])) {
                    $error = "Incorrect current password.";
                } else {
                    $new_hash = password_hash($new_pw, PASSWORD_DEFAULT);
                    $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $upd->execute([$new_hash, $user_id]);
                    log_activity($pdo, 'Admin Password Changed', "Administrator ({$user['name']}) changed their account password.", 'Security');
                    $message = "Your password has been changed successfully.";
                }
            } catch (Exception $e) {
                $error = "Failed to update password. Please try again.";
            }
        }
    } else {
        $gym_name               = trim($_POST['gym_name'] ?? '');
        $max_capacity           = intval($_POST['max_capacity'] ?? 0);
        $renewal_threshold_days = intval($_POST['renewal_threshold_days'] ?? 0);
        $gcash_number           = trim($_POST['gcash_number'] ?? '');
        $gcash_name             = trim($_POST['gcash_name'] ?? '');
        $maya_number            = trim($_POST['maya_number'] ?? '');
        $maya_name              = trim($_POST['maya_name'] ?? '');

        if (empty($gym_name) || $max_capacity <= 0 || $renewal_threshold_days < 0) {
            $error = "Please provide valid values for all required fields.";
        } else {
            try {
                $pdo->beginTransaction();

                $save_setting = function($key, $val) use ($pdo) {
                    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$key, $val, $val]);
                };

                $save_setting('gym_name', $gym_name);
                $save_setting('max_capacity', $max_capacity);
                $save_setting('renewal_threshold_days', $renewal_threshold_days);
                $save_setting('gcash_number', $gcash_number);
                $save_setting('gcash_name', $gcash_name);
                $save_setting('maya_number', $maya_number);
                $save_setting('maya_name', $maya_name);

                // Handle GCash QR Image Upload
                if (isset($_FILES['gcash_qr']) && $_FILES['gcash_qr']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['gcash_qr']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $target_dir = __DIR__ . '/uploads/qr';
                        if (!is_dir($target_dir)) mkdir($target_dir, 0775, true);
                        $filename = 'gcash_qr_' . time() . '.' . $ext;
                        $dest = $target_dir . '/' . $filename;
                        if (move_uploaded_file($_FILES['gcash_qr']['tmp_name'], $dest)) {
                            $save_setting('gcash_qr_image', 'uploads/qr/' . $filename);
                            $app_settings['gcash_qr_image'] = 'uploads/qr/' . $filename;
                        }
                    }
                }

                // Handle Maya QR Image Upload
                if (isset($_FILES['maya_qr']) && $_FILES['maya_qr']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['maya_qr']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $target_dir = __DIR__ . '/uploads/qr';
                        if (!is_dir($target_dir)) mkdir($target_dir, 0775, true);
                        $filename = 'maya_qr_' . time() . '.' . $ext;
                        $dest = $target_dir . '/' . $filename;
                        if (move_uploaded_file($_FILES['maya_qr']['tmp_name'], $dest)) {
                            $save_setting('maya_qr_image', 'uploads/qr/' . $filename);
                            $app_settings['maya_qr_image'] = 'uploads/qr/' . $filename;
                        }
                    }
                }

                $pdo->commit();
                log_activity($pdo, 'Updated Settings', 'System and Payment settings were updated by admin.', 'System');
                $message = "Settings and E-Wallet configuration saved successfully.";
                
                // Update global array for immediate view
                $app_settings['gym_name']               = $gym_name;
                $app_settings['max_capacity']           = $max_capacity;
                $app_settings['renewal_threshold_days'] = $renewal_threshold_days;
                $app_settings['gcash_number']           = $gcash_number;
                $app_settings['gcash_name']             = $gcash_name;
                $app_settings['maya_number']            = $maya_number;
                $app_settings['maya_name']              = $maya_name;

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('System Error in settings.php: ' . $e->getMessage());
                $error = "Failed to update settings due to an internal system error.";
            }
        }
    }
}
?>

<div class="topbar">
    <div class="page-title">
        <h1>System & Payment Settings</h1>
        <p>Configure gym branding, capacity, and E-Wallet payment details (GCash & Maya).</p>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div style="display:flex; flex-direction:column; gap:2rem; max-width:850px;">

    <!-- 1. General Branding & Operations -->
    <div class="card">
        <h3 class="section-title"><i class="fas fa-sliders" style="color:var(--accent);"></i> General Configuration</h3>
        
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
            <input type="hidden" name="action" value="update_settings">
            
            <div style="display:flex; flex-direction:column; gap:1.25rem; margin-top:1.25rem;">
                <div class="form-group">
                    <label for="gym_name">Gym Name (Branding) *</label>
                    <p class="cell-secondary" style="margin-bottom:0.4rem;">This name appears across the dashboard, member portal, invoices, and emails.</p>
                    <input type="text" id="gym_name" name="gym_name" class="form-control" value="<?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?>" required>
                </div>

                <div class="form-grid" style="grid-template-columns:1fr 1fr; gap:1rem;">
                    <div class="form-group">
                        <label for="max_capacity">Maximum Capacity Limit *</label>
                        <p class="cell-secondary" style="margin-bottom:0.4rem;">Simultaneous members allowed in gym.</p>
                        <input type="number" id="max_capacity" name="max_capacity" class="form-control" value="<?php echo htmlspecialchars($app_settings['max_capacity'] ?? 50); ?>" min="1" required>
                    </div>

                    <div class="form-group">
                        <label for="renewal_threshold_days">Renewal Alert Threshold (Days) *</label>
                        <p class="cell-secondary" style="margin-bottom:0.4rem;">Days before expiry to trigger alert badge.</p>
                        <input type="number" id="renewal_threshold_days" name="renewal_threshold_days" class="form-control" value="<?php echo htmlspecialchars($app_settings['renewal_threshold_days'] ?? 7); ?>" min="0" required>
                    </div>
                </div>

                <hr style="border:0; border-top:1px solid var(--border); margin:0.5rem 0;">

                <!-- 2. E-Wallet Payment Accounts -->
                <h3 class="section-title" style="margin-bottom:0.25rem;"><i class="fas fa-wallet" style="color:#2563eb;"></i> GCash & Maya Payment Details</h3>
                <p class="cell-secondary" style="margin-bottom:1rem;">These details will be shown to members when renewing their membership online.</p>

                <!-- GCash Settings -->
                <div style="background:rgba(59,130,246,0.04); border:1px solid rgba(59,130,246,0.15); border-radius:12px; padding:1.25rem;">
                    <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:1rem;">
                        <span class="badge" style="background:#2563eb; color:#fff;"><i class="fas fa-mobile-screen"></i> GCash</span>
                        <strong style="font-size:0.9rem; color:var(--text-main);">GCash Merchant / Personal Account</strong>
                    </div>

                    <div class="form-grid" style="grid-template-columns:1fr 1fr; gap:1rem;">
                        <div class="form-group">
                            <label for="gcash_number">GCash Mobile Number</label>
                            <input type="text" id="gcash_number" name="gcash_number" class="form-control" placeholder="e.g. 0917-123-4567" value="<?php echo htmlspecialchars($app_settings['gcash_number'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="gcash_name">GCash Account Name</label>
                            <input type="text" id="gcash_name" name="gcash_name" class="form-control" placeholder="e.g. Palma's Elite Gym / Juan D." value="<?php echo htmlspecialchars($app_settings['gcash_name'] ?? "Palma's Elite Gym"); ?>">
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:0.5rem; margin-bottom:0;">
                        <label for="gcash_qr">Upload GCash QR Code (Optional)</label>
                        <p class="cell-secondary" style="margin-bottom:0.4rem;">Members can scan this QR code directly using their GCash App.</p>
                        <input type="file" id="gcash_qr" name="gcash_qr" class="form-control" accept="image/*">
                        <?php if (!empty($app_settings['gcash_qr_image']) && file_exists(__DIR__ . '/' . $app_settings['gcash_qr_image'])): ?>
                            <div style="margin-top:0.75rem; display:flex; align-items:center; gap:0.75rem;">
                                <img src="<?php echo htmlspecialchars($app_settings['gcash_qr_image']); ?>" alt="GCash QR" style="width:70px; height:70px; object-fit:contain; border-radius:8px; border:1px solid var(--border); background:#fff; padding:3px;">
                                <span class="cell-secondary" style="font-size:0.78rem;"><i class="fas fa-check-circle" style="color:var(--success);"></i> Current GCash QR is active</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Maya Settings -->
                <div style="background:rgba(16,185,129,0.04); border:1px solid rgba(16,185,129,0.15); border-radius:12px; padding:1.25rem;">
                    <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:1rem;">
                        <span class="badge" style="background:#059669; color:#fff;"><i class="fas fa-wallet"></i> Maya</span>
                        <strong style="font-size:0.9rem; color:var(--text-main);">Maya Merchant / Account</strong>
                    </div>

                    <div class="form-grid" style="grid-template-columns:1fr 1fr; gap:1rem;">
                        <div class="form-group">
                            <label for="maya_number">Maya Mobile Number</label>
                            <input type="text" id="maya_number" name="maya_number" class="form-control" placeholder="e.g. 0918-987-6543" value="<?php echo htmlspecialchars($app_settings['maya_number'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label for="maya_name">Maya Account Name</label>
                            <input type="text" id="maya_name" name="maya_name" class="form-control" placeholder="e.g. Palma's Elite Gym" value="<?php echo htmlspecialchars($app_settings['maya_name'] ?? "Palma's Elite Gym"); ?>">
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:0.5rem; margin-bottom:0;">
                        <label for="maya_qr">Upload Maya QR Code (Optional)</label>
                        <p class="cell-secondary" style="margin-bottom:0.4rem;">Members can scan this QR code directly using their Maya App.</p>
                        <input type="file" id="maya_qr" name="maya_qr" class="form-control" accept="image/*">
                        <?php if (!empty($app_settings['maya_qr_image']) && file_exists(__DIR__ . '/' . $app_settings['maya_qr_image'])): ?>
                            <div style="margin-top:0.75rem; display:flex; align-items:center; gap:0.75rem;">
                                <img src="<?php echo htmlspecialchars($app_settings['maya_qr_image']); ?>" alt="Maya QR" style="width:70px; height:70px; object-fit:contain; border-radius:8px; border:1px solid var(--border); background:#fff; padding:3px;">
                                <span class="cell-secondary" style="font-size:0.78rem;"><i class="fas fa-check-circle" style="color:var(--success);"></i> Current Maya QR is active</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="margin-top:0.5rem;">
                    <button type="submit" class="btn btn-primary" style="padding:0.75rem 1.75rem;">
                        <i class="fas fa-floppy-disk"></i> Save Settings & E-Wallets
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- 3. Security & Password Management -->
    <div class="card">
        <h3 class="section-title"><i class="fas fa-shield-halved" style="color:var(--danger);"></i> Security & Password</h3>
        
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
            <input type="hidden" name="action" value="change_password">
            
            <div style="display:flex; flex-direction:column; gap:1.25rem; margin-top:1.25rem;">
                <div class="form-group">
                    <label for="current_password">Current Password *</label>
                    <input type="password" id="current_password" name="current_password" class="form-control" placeholder="••••••••" required>
                </div>

                <div class="form-grid" style="grid-template-columns:1fr 1fr; gap:1rem;">
                    <div class="form-group">
                        <label for="new_password">New Password *</label>
                        <input type="password" id="new_password" name="new_password" class="form-control" placeholder="Min. 6 characters" required>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Re-type new password" required>
                    </div>
                </div>

                <div style="margin-top:0.5rem;">
                    <button type="submit" class="btn btn-outline" style="border-color:var(--danger); color:var(--danger); padding:0.75rem 1.75rem;">
                        <i class="fas fa-key"></i> Update Password
                    </button>
                </div>
            </div>
        </form>
    </div>

</div>

<?php include 'includes/footer.php'; ?>
