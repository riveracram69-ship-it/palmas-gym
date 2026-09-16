<?php
$page_title = 'Staff & Admin Accounts';
require_once 'config/auth.php';
require_once 'config/db.php';
require_once 'config/logger.php';
require_once 'config/email.php';
require_once 'includes/ui_components.php';

require_login();
require_admin();

include 'includes/header.php';
include 'includes/sidebar.php';

$message = '';
$error = '';
$current_admin_id = $_SESSION['user_id'] ?? 0;

// Handle Actions (Create, Update, Reset Password, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $role     = trim($_POST['role'] ?? 'staff');
        $password = $_POST['password'] ?? '';
        $send_email = !empty($_POST['send_welcome_email']);

        if (!in_array($role, ['admin', 'staff'], true)) {
            $role = 'staff';
        }

        if (empty($name) || empty($email) || empty($password)) {
            $error = 'Please fill in all required fields (Name, Email, and Password).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please provide a valid email address.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters long.';
        } else {
            try {
                // Check if email already exists
                $check = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $check->execute([$email]);
                if ($check->fetch()) {
                    $error = "A staff/admin account with the email '{$email}' already exists.";
                } else {
                    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                    $ins = $pdo->prepare("INSERT INTO users (name, email, password, role, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $ins->execute([$name, $email, $hashed_password, $role]);
                    $new_user_id = $pdo->lastInsertId();

                    log_activity($pdo, 'Created User Account', "Created new {$role} account for {$name} ({$email})", 'Auth', $new_user_id, $name);

                    // Send Welcome / Access Email if requested
                    if ($send_email) {
                        $role_label = ($role === 'admin') ? 'System Administrator' : 'Front-Desk Staff';
                        $email_subject = "Welcome to Palma's Elite Gym Staff Portal 🛡️";
                        $email_title = "Your Staff Portal Account is Ready!";
                        $email_body = "
                            <p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
                            <p>An official <strong>{$role_label}</strong> account has been created for you on the <strong>Palma's Elite Gym Management System</strong>.</p>
                            
                            <div style=\"background-color:#F4F9F6; border:1px solid #D8E6DC; border-radius:10px; padding:18px; margin:20px 0;\">
                                <p style=\"margin:0 0 10px; font-weight:bold; color:#1B4332; font-size:14px; text-transform:uppercase; letter-spacing:0.5px;\">Login Credentials</p>
                                <table style=\"width:100%; font-size:13px; color:#334155; border-collapse:collapse;\">
                                    <tr><td style=\"padding:4px 0;\"><strong>Email Address:</strong></td><td style=\"text-align:right; font-family:monospace; font-weight:bold; color:#1B4332;\">" . htmlspecialchars($email) . "</td></tr>
                                    <tr><td style=\"padding:4px 0;\"><strong>Temporary Password:</strong></td><td style=\"text-align:right; font-family:monospace; font-weight:bold; color:#2D6A4F;\">" . htmlspecialchars($password) . "</td></tr>
                                    <tr><td style=\"padding:4px 0;\"><strong>Assigned Role:</strong></td><td style=\"text-align:right; font-weight:bold;\">{$role_label}</td></tr>
                                </table>
                            </div>

                            <p>You may now sign in to the management dashboard to perform check-ins, record payments, and manage member operations.</p>
                            <p style=\"margin-top:16px;\"><em>Security Notice: Please change your password upon your first sign in.</em></p>
                        ";
                        try {
                            send_email_notification($email, $email_subject, $email_title, $email_body);
                        } catch (\Throwable $emErr) {
                            error_log("Staff welcome email error: " . $emErr->getMessage());
                        }
                    }

                    $message = "Account for <strong>" . htmlspecialchars($name) . "</strong> (" . ucfirst($role) . ") has been created successfully!";
                }
            } catch (Exception $e) {
                error_log("Create user error: " . $e->getMessage());
                $error = 'Database error creating account.';
            }
        }
    } elseif ($action === 'update_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $role    = trim($_POST['role'] ?? 'staff');

        if (!in_array($role, ['admin', 'staff'], true)) {
            $role = 'staff';
        }

        if ($user_id <= 0 || empty($name) || empty($email)) {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please provide a valid email address.';
        } else {
            try {
                // Check if email taken by another user
                $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                $check->execute([$email, $user_id]);
                if ($check->fetch()) {
                    $error = "The email '{$email}' is already in use by another account.";
                } else {
                    // Prevent demoting self from admin if you are the logged in admin
                    if ($user_id === (int)$current_admin_id && $role !== 'admin') {
                        $error = 'You cannot demote your own account from Administrator.';
                    } else {
                        $up = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?");
                        $up->execute([$name, $email, $role, $user_id]);
                        log_activity($pdo, 'Updated User Account', "Updated account details for {$name} (Role: {$role})", 'Auth', $user_id, $name);
                        $message = "Account details for <strong>" . htmlspecialchars($name) . "</strong> have been updated.";
                    }
                }
            } catch (Exception $e) {
                error_log("Update user error: " . $e->getMessage());
                $error = 'Database error updating account.';
            }
        }
    } elseif ($action === 'reset_password') {
        $user_id  = (int)($_POST['user_id'] ?? 0);
        $password = $_POST['new_password'] ?? '';
        $send_email = !empty($_POST['notify_user']);

        if ($user_id <= 0 || empty($password)) {
            $error = 'Please provide a new password.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters long.';
        } else {
            try {
                $u_stmt = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1");
                $u_stmt->execute([$user_id]);
                $target_user = $u_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$target_user) {
                    $error = 'User not found.';
                } else {
                    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                    $up = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $up->execute([$hashed_password, $user_id]);

                    log_activity($pdo, 'Reset User Password', "Reset password for {$target_user['name']} ({$target_user['email']})", 'Auth', $user_id, $target_user['name']);

                    if ($send_email && !empty($target_user['email'])) {
                        $email_subject = "Your Password Has Been Reset — Palma's Elite Gym Staff Portal";
                        $email_title = "Staff Account Password Reset 🔐";
                        $email_body = "
                            <p>Dear <strong>" . htmlspecialchars($target_user['name']) . "</strong>,</p>
                            <p>The password for your staff account on <strong>Palma's Elite Gym Management System</strong> has been reset by an administrator.</p>
                            
                            <div style=\"background-color:#F4F9F6; border:1px solid #D8E6DC; border-radius:10px; padding:18px; margin:20px 0;\">
                                <p style=\"margin:0 0 10px; font-weight:bold; color:#1B4332; font-size:14px; text-transform:uppercase; letter-spacing:0.5px;\">New Login Credentials</p>
                                <table style=\"width:100%; font-size:13px; color:#334155; border-collapse:collapse;\">
                                    <tr><td style=\"padding:4px 0;\"><strong>Email:</strong></td><td style=\"text-align:right; font-family:monospace; font-weight:bold; color:#1B4332;\">" . htmlspecialchars($target_user['email']) . "</td></tr>
                                    <tr><td style=\"padding:4px 0;\"><strong>New Password:</strong></td><td style=\"text-align:right; font-family:monospace; font-weight:bold; color:#2D6A4F;\">" . htmlspecialchars($password) . "</td></tr>
                                </table>
                            </div>

                            <p>Please log in using your new credentials and update your password if desired.</p>
                        ";
                        try {
                            send_email_notification($target_user['email'], $email_subject, $email_title, $email_body);
                        } catch (\Throwable $emErr) {
                            error_log("Staff reset password email error: " . $emErr->getMessage());
                        }
                    }

                    $message = "Password for <strong>" . htmlspecialchars($target_user['name']) . "</strong> has been reset successfully!";
                }
            } catch (Exception $e) {
                error_log("Reset password error: " . $e->getMessage());
                $error = 'Database error resetting password.';
            }
        }
    } elseif ($action === 'delete_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);

        if ($user_id <= 0) {
            $error = 'Invalid account selection.';
        } elseif ($user_id === (int)$current_admin_id) {
            $error = 'You cannot delete your own active administrator account.';
        } else {
            try {
                // Check if user is the last admin
                $u_stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ? LIMIT 1");
                $u_stmt->execute([$user_id]);
                $target_user = $u_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$target_user) {
                    $error = 'User not found.';
                } elseif ($target_user['role'] === 'admin') {
                    $admin_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                    if ($admin_count <= 1) {
                        $error = 'Cannot delete the only remaining Administrator account in the system.';
                    } else {
                        $del = $pdo->prepare("DELETE FROM users WHERE id = ?");
                        $del->execute([$user_id]);
                        log_activity($pdo, 'Deleted User Account', "Deleted account: {$target_user['name']} (Role: admin)", 'Auth', $user_id, $target_user['name']);
                        $message = "Administrator account for <strong>" . htmlspecialchars($target_user['name']) . "</strong> has been removed.";
                    }
                } else {
                    $del = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $del->execute([$user_id]);
                    log_activity($pdo, 'Deleted User Account', "Deleted account: {$target_user['name']} (Role: staff)", 'Auth', $user_id, $target_user['name']);
                    $message = "Staff account for <strong>" . htmlspecialchars($target_user['name']) . "</strong> has been removed.";
                }
            } catch (Exception $e) {
                error_log("Delete user error: " . $e->getMessage());
                $error = 'Database error deleting account.';
            }
        }
    }
}

// Fetch all users
$users = [];
$admin_count = 0;
$staff_count = 0;
try {
    $users = $pdo->query("SELECT id, name, email, role, created_at FROM users ORDER BY role ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $u) {
        if ($u['role'] === 'admin') $admin_count++;
        else $staff_count++;
    }
} catch (Exception $e) {
    error_log("Fetch users error: " . $e->getMessage());
}
$total_users = count($users);
?>

<div class="topbar">
    <div class="page-title">
        <div style="display:flex; align-items:center; gap:0.6rem;">
            <div style="width:40px; height:40px; border-radius:10px; background:linear-gradient(135deg, #1B4332 0%, #2D6A4F 100%); color:#52B788; display:flex; align-items:center; justify-content:center; font-size:1.1rem; box-shadow:0 4px 12px rgba(27,67,50,0.2);">
                <i class="fas fa-user-shield"></i>
            </div>
            <div>
                <h1 style="margin:0; font-size:1.6rem; font-family:'Outfit',sans-serif; font-weight:800; color:var(--text-main);">Staff &amp; Admin Accounts</h1>
                <p style="margin:2px 0 0; font-size:0.85rem; color:var(--text-muted);">Manage gym receptionists, staff members, and system administrators.</p>
            </div>
        </div>
    </div>
    <div style="display:flex; gap:0.6rem; align-items:center;">
        <a href="members.php" class="btn btn-outline" style="border-radius:10px; font-weight:600;">
            <i class="fas fa-users"></i> View Members
        </a>
        <button type="button" class="btn btn-primary" onclick="openCreateUserModal()" style="border-radius:10px; font-weight:700;">
            <i class="fas fa-user-plus"></i> Add Staff / Admin
        </button>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success" style="background:#ecfdf5; border-left:4px solid #10b981; color:#065f46; padding:0.9rem 1.25rem; border-radius:10px; margin-bottom:1.5rem; display:flex; align-items:center; gap:0.6rem;">
    <i class="fas fa-circle-check" style="font-size:1.1rem; color:#10b981;"></i>
    <div><?php echo $message; ?></div>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger" style="background:#fef2f2; border-left:4px solid #ef4444; color:#991b1b; padding:0.9rem 1.25rem; border-radius:10px; margin-bottom:1.5rem; display:flex; align-items:center; gap:0.6rem;">
    <i class="fas fa-circle-exclamation" style="font-size:1.1rem; color:#ef4444;"></i>
    <div><?php echo htmlspecialchars($error); ?></div>
</div>
<?php endif; ?>

<!-- ── STATS CARDS ──────────────────────────────────────────────────────────── -->
<div class="stats-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:1rem; margin-bottom:1.75rem;">
    <div class="card" style="padding:1.25rem; display:flex; align-items:center; gap:1rem; border-radius:14px;">
        <div style="width:48px; height:48px; border-radius:12px; background:rgba(45,106,79,0.12); color:#2D6A4F; display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
            <i class="fas fa-users-gear"></i>
        </div>
        <div>
            <div style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Total Accounts</div>
            <div style="font-size:1.6rem; font-weight:800; font-family:'Outfit',sans-serif; color:var(--text-main);"><?php echo $total_users; ?></div>
        </div>
    </div>

    <div class="card" style="padding:1.25rem; display:flex; align-items:center; gap:1rem; border-radius:14px;">
        <div style="width:48px; height:48px; border-radius:12px; background:rgba(217,119,6,0.12); color:#D97706; display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
            <i class="fas fa-crown"></i>
        </div>
        <div>
            <div style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Administrators</div>
            <div style="font-size:1.6rem; font-weight:800; font-family:'Outfit',sans-serif; color:var(--text-main);"><?php echo $admin_count; ?></div>
        </div>
    </div>

    <div class="card" style="padding:1.25rem; display:flex; align-items:center; gap:1rem; border-radius:14px;">
        <div style="width:48px; height:48px; border-radius:12px; background:rgba(59,130,246,0.12); color:#2563EB; display:flex; align-items:center; justify-content:center; font-size:1.3rem;">
            <i class="fas fa-id-badge"></i>
        </div>
        <div>
            <div style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Staff &amp; Reception</div>
            <div style="font-size:1.6rem; font-weight:800; font-family:'Outfit',sans-serif; color:var(--text-main);"><?php echo $staff_count; ?></div>
        </div>
    </div>
</div>

<!-- ── USERS TABLE ──────────────────────────────────────────────────────────── -->
<div class="card" style="border-radius:16px; overflow:hidden;">
    <div class="toolbar" style="padding:1.25rem 1.5rem; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
        <div class="search-bar" style="max-width:320px; width:100%;">
            <i class="fas fa-search"></i>
            <input type="text" id="user-search-input" class="form-control" placeholder="Search staff name or email..." oninput="filterUsersTable()">
        </div>
        <div style="display:flex; gap:0.5rem; align-items:center;">
            <select id="user-role-filter" class="form-control" style="width:auto; font-size:0.85rem;" onchange="filterUsersTable()">
                <option value="">All Roles</option>
                <option value="admin">Administrators</option>
                <option value="staff">Staff Only</option>
            </select>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table" id="users-table">
            <thead>
                <tr>
                    <th style="width:50px;">#</th>
                    <th>Staff / User</th>
                    <th>Email Address</th>
                    <th>Role &amp; Permissions</th>
                    <th>Date Added</th>
                    <th style="text-align:right; width:180px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:3rem 1rem;">
                            <i class="fas fa-user-shield" style="font-size:2.5rem; color:#cbd5e1; margin-bottom:0.75rem; display:block;"></i>
                            <h3 style="color:var(--text-main); font-size:1.1rem; margin:0 0 4px;">No User Accounts Found</h3>
                            <p style="color:var(--text-muted); font-size:0.88rem; margin:0 0 1rem;">Add a new staff or admin user to manage the gym system.</p>
                            <button class="btn btn-primary btn-sm" onclick="openCreateUserModal()"><i class="fas fa-plus"></i> Add Account</button>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $index => $u): 
                        $is_self = ($u['id'] == $current_admin_id);
                        $is_adm = ($u['role'] === 'admin');
                        $initials = strtoupper(substr($u['name'] ?? 'U', 0, 1));
                    ?>
                    <tr class="user-row" data-name="<?php echo strtolower(htmlspecialchars($u['name'])); ?>" data-email="<?php echo strtolower(htmlspecialchars($u['email'])); ?>" data-role="<?php echo strtolower($u['role']); ?>">
                        <td style="color:var(--text-muted); font-size:0.85rem; font-weight:600;"><?php echo $index + 1; ?></td>
                        <td>
                            <div style="display:flex; align-items:center; gap:0.75rem;">
                                <div style="width:38px; height:38px; border-radius:50%; background:<?php echo $is_adm ? 'linear-gradient(135deg, #1B4332 0%, #2D6A4F 100%)' : '#e2e8f0'; ?>; color:<?php echo $is_adm ? '#52B788' : '#475569'; ?>; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:0.95rem; flex-shrink:0;">
                                    <?php echo $initials; ?>
                                </div>
                                <div>
                                    <div style="font-weight:700; color:var(--text-main); font-size:0.95rem;">
                                        <?php echo htmlspecialchars($u['name']); ?>
                                        <?php if ($is_self): ?>
                                            <span style="font-size:0.7rem; background:#dcfce7; color:#166534; font-weight:800; padding:2px 8px; border-radius:12px; margin-left:4px;">YOU</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size:0.78rem; color:var(--text-muted); font-family:monospace;">ID: USR-<?php echo str_pad($u['id'], 4, '0', STR_PAD_LEFT); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <a href="mailto:<?php echo htmlspecialchars($u['email']); ?>" style="color:var(--text-main); text-decoration:none; font-size:0.9rem; font-weight:500;">
                                <i class="far fa-envelope" style="color:var(--text-muted); margin-right:4px;"></i> <?php echo htmlspecialchars($u['email']); ?>
                            </a>
                        </td>
                        <td>
                            <?php if ($is_adm): ?>
                                <span style="display:inline-flex; align-items:center; gap:5px; background:rgba(217,119,6,0.12); color:#D97706; font-size:0.78rem; font-weight:800; padding:4px 10px; border-radius:20px; border:1px solid rgba(217,119,6,0.25);">
                                    <i class="fas fa-crown" style="font-size:0.7rem;"></i> Administrator
                                </span>
                            <?php else: ?>
                                <span style="display:inline-flex; align-items:center; gap:5px; background:rgba(59,130,246,0.12); color:#2563EB; font-size:0.78rem; font-weight:700; padding:4px 10px; border-radius:20px; border:1px solid rgba(59,130,246,0.25);">
                                    <i class="fas fa-id-badge" style="font-size:0.7rem;"></i> Staff / Reception
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.85rem; color:var(--text-muted);">
                            <?php echo date('M d, Y', strtotime($u['created_at'])); ?>
                        </td>
                        <td style="text-align:right;">
                            <div style="display:inline-flex; gap:0.35rem;">
                                <button type="button" class="btn btn-outline btn-sm btn-icon" title="Edit Profile &amp; Role" 
                                    onclick="openEditUserModal(<?php echo htmlspecialchars(json_encode($u)); ?>)">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <button type="button" class="btn btn-outline btn-sm btn-icon" title="Reset Password" 
                                    onclick="openResetPasswordModal(<?php echo htmlspecialchars(json_encode($u)); ?>)">
                                    <i class="fas fa-key"></i>
                                </button>
                                <?php if (!$is_self): ?>
                                <button type="button" class="btn btn-outline btn-sm btn-icon" style="color:var(--danger); border-color:rgba(239,68,68,0.3);" title="Delete Account" 
                                    onclick="confirmDeleteUser(<?php echo htmlspecialchars(json_encode($u)); ?>)">
                                    <i class="fas fa-trash-can"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL 1: CREATE NEW STAFF / ADMIN                                           -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-create-user" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; backdrop-filter:blur(4px);">
    <div class="card" style="max-width:500px; width:92%; max-height:90vh; overflow-y:auto; border-radius:20px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.4); padding:2rem;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
            <div style="display:flex; align-items:center; gap:0.75rem;">
                <div style="width:40px; height:40px; border-radius:10px; background:#ecfdf5; color:#059669; display:flex; align-items:center; justify-content:center; font-size:1.1rem;">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div>
                    <h3 style="margin:0; font-size:1.25rem; font-family:'Outfit',sans-serif; font-weight:800; color:var(--text-main);">Create User Account</h3>
                    <p style="margin:0; font-size:0.8rem; color:var(--text-muted);">Add new staff receptionist or administrator.</p>
                </div>
            </div>
            <button type="button" onclick="closeModal('modal-create-user')" style="background:none; border:none; color:var(--text-muted); font-size:1.2rem; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
            <input type="hidden" name="action" value="create_user">

            <div class="form-group" style="margin-bottom:1.1rem;">
                <label style="display:block; font-size:0.78rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:0.4rem;">Full Name *</label>
                <input type="text" name="name" class="form-control" placeholder="e.g. Maria Santos" required>
            </div>

            <div class="form-group" style="margin-bottom:1.1rem;">
                <label style="display:block; font-size:0.78rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:0.4rem;">Email Address *</label>
                <input type="email" name="email" class="form-control" placeholder="staff@palmaselite.com" required>
            </div>

            <div class="form-group" style="margin-bottom:1.1rem;">
                <label style="display:block; font-size:0.78rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:0.4rem;">System Role *</label>
                <select name="role" class="form-control" style="font-weight:600;" required>
                    <option value="staff" selected>🛡️ Staff (Front-Desk / Receptionist) — Attendance, Renewals, Check-in</option>
                    <option value="admin">👑 Administrator — Full System Access, Reports, Plans, Settings</option>
                </select>
                <small style="color:var(--text-muted); font-size:0.75rem; margin-top:4px; display:block;">Staff accounts cannot modify system backups, core settings, or other admin profiles.</small>
            </div>

            <div class="form-group" style="margin-bottom:1.25rem;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.4rem;">
                    <label style="font-size:0.78rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin:0;">Temporary Password *</label>
                    <button type="button" onclick="generateRandomPassword('create_pw')" style="background:none; border:none; color:var(--primary); font-size:0.75rem; font-weight:700; cursor:pointer;">
                        <i class="fas fa-wand-magic-sparkles"></i> Generate Strong
                    </button>
                </div>
                <div style="position:relative;">
                    <input type="password" id="create_pw" name="password" class="form-control" placeholder="Minimum 6 characters" required style="padding-right:40px;">
                    <button type="button" onclick="togglePasswordVisibility('create_pw', this)" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); cursor:pointer;">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:0.9rem 1rem; margin-bottom:1.5rem;">
                <label style="display:flex; align-items:center; gap:0.6rem; cursor:pointer; font-size:0.85rem; font-weight:600; color:var(--text-main); margin:0;">
                    <input type="checkbox" name="send_welcome_email" value="1" checked style="width:16px; height:16px; accent-color:var(--primary);">
                    <span>Send welcome email with login credentials</span>
                </label>
            </div>

            <div style="display:flex; gap:0.75rem; justify-content:flex-end;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-create-user')" style="border-radius:10px;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="border-radius:10px; font-weight:700;">
                    <i class="fas fa-check"></i> Create Account
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL 2: EDIT STAFF / ADMIN DETAILS                                         -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-edit-user" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; backdrop-filter:blur(4px);">
    <div class="card" style="max-width:500px; width:92%; max-height:90vh; overflow-y:auto; border-radius:20px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.4); padding:2rem;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
            <div style="display:flex; align-items:center; gap:0.75rem;">
                <div style="width:40px; height:40px; border-radius:10px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:1.1rem;">
                    <i class="fas fa-user-pen"></i>
                </div>
                <div>
                    <h3 style="margin:0; font-size:1.25rem; font-family:'Outfit',sans-serif; font-weight:800; color:var(--text-main);">Edit User Account</h3>
                    <p style="margin:0; font-size:0.8rem; color:var(--text-muted);">Update profile details or change role.</p>
                </div>
            </div>
            <button type="button" onclick="closeModal('modal-edit-user')" style="background:none; border:none; color:var(--text-muted); font-size:1.2rem; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" id="edit_user_id">

            <div class="form-group" style="margin-bottom:1.1rem;">
                <label style="display:block; font-size:0.78rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:0.4rem;">Full Name *</label>
                <input type="text" name="name" id="edit_name" class="form-control" required>
            </div>

            <div class="form-group" style="margin-bottom:1.1rem;">
                <label style="display:block; font-size:0.78rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:0.4rem;">Email Address *</label>
                <input type="email" name="email" id="edit_email" class="form-control" required>
            </div>

            <div class="form-group" style="margin-bottom:1.5rem;">
                <label style="display:block; font-size:0.78rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:0.4rem;">System Role *</label>
                <select name="role" id="edit_role" class="form-control" style="font-weight:600;" required>
                    <option value="staff">🛡️ Staff (Front-Desk / Receptionist)</option>
                    <option value="admin">👑 Administrator (Full Privileges)</option>
                </select>
            </div>

            <div style="display:flex; gap:0.75rem; justify-content:flex-end;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-edit-user')" style="border-radius:10px;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="border-radius:10px; font-weight:700;">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL 3: RESET PASSWORD                                                     -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-reset-user" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; backdrop-filter:blur(4px);">
    <div class="card" style="max-width:460px; width:92%; max-height:90vh; overflow-y:auto; border-radius:20px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.4); padding:2rem;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
            <div style="display:flex; align-items:center; gap:0.75rem;">
                <div style="width:40px; height:40px; border-radius:10px; background:#fef3c7; color:#d97706; display:flex; align-items:center; justify-content:center; font-size:1.1rem;">
                    <i class="fas fa-key"></i>
                </div>
                <div>
                    <h3 style="margin:0; font-size:1.25rem; font-family:'Outfit',sans-serif; font-weight:800; color:var(--text-main);">Reset Password</h3>
                    <p style="margin:0; font-size:0.8rem; color:var(--text-muted);" id="reset_user_subtitle">Assign new password.</p>
                </div>
            </div>
            <button type="button" onclick="closeModal('modal-reset-user')" style="background:none; border:none; color:var(--text-muted); font-size:1.2rem; cursor:pointer;"><i class="fas fa-times"></i></button>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="reset_user_id">

            <div class="form-group" style="margin-bottom:1.25rem;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.4rem;">
                    <label style="font-size:0.78rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin:0;">New Password *</label>
                    <button type="button" onclick="generateRandomPassword('reset_pw')" style="background:none; border:none; color:var(--primary); font-size:0.75rem; font-weight:700; cursor:pointer;">
                        <i class="fas fa-wand-magic-sparkles"></i> Generate Strong
                    </button>
                </div>
                <div style="position:relative;">
                    <input type="password" id="reset_pw" name="new_password" class="form-control" placeholder="Minimum 6 characters" required style="padding-right:40px;">
                    <button type="button" onclick="togglePasswordVisibility('reset_pw', this)" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-muted); cursor:pointer;">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:0.9rem 1rem; margin-bottom:1.5rem;">
                <label style="display:flex; align-items:center; gap:0.6rem; cursor:pointer; font-size:0.85rem; font-weight:600; color:var(--text-main); margin:0;">
                    <input type="checkbox" name="notify_user" value="1" checked style="width:16px; height:16px; accent-color:var(--primary);">
                    <span>Notify user via email with their new password</span>
                </label>
            </div>

            <div style="display:flex; gap:0.75rem; justify-content:flex-end;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-reset-user')" style="border-radius:10px;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="border-radius:10px; font-weight:700; background:#d97706; border-color:#d97706;">
                    <i class="fas fa-key"></i> Update Password
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL 4: DELETE CONFIRMATION                                                -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-delete-user" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; backdrop-filter:blur(4px);">
    <div class="card" style="max-width:440px; width:92%; border-radius:20px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.4); padding:2rem; text-align:center;">
        <div style="width:54px; height:54px; border-radius:50%; background:#fee2e2; color:#ef4444; display:flex; align-items:center; justify-content:center; font-size:1.5rem; margin:0 auto 1.25rem;">
            <i class="fas fa-triangle-exclamation"></i>
        </div>
        <h3 style="font-size:1.3rem; font-family:'Outfit',sans-serif; font-weight:800; color:var(--text-main); margin-bottom:0.5rem;">Delete User Account?</h3>
        <p style="font-size:0.9rem; color:var(--text-muted); line-height:1.5; margin-bottom:1.5rem;" id="delete-user-msg">
            Are you sure you want to remove this staff account? This user will immediately lose access to the management portal.
        </p>
        <form method="POST" action="" style="display:flex; gap:0.75rem; justify-content:center;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(get_csrf_token()); ?>">
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="user_id" id="delete_user_id">
            <button type="button" class="btn btn-outline" onclick="closeModal('modal-delete-user')" style="border-radius:10px; min-width:110px;">Cancel</button>
            <button type="submit" class="btn btn-danger" style="border-radius:10px; min-width:120px; font-weight:700;">
                <i class="fas fa-trash-can"></i> Delete
            </button>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'flex';
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

function openCreateUserModal() {
    openModal('modal-create-user');
}

function openEditUserModal(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_name').value = user.name;
    document.getElementById('edit_email').value = user.email;
    document.getElementById('edit_role').value = user.role;
    openModal('modal-edit-user');
}

function openResetPasswordModal(user) {
    document.getElementById('reset_user_id').value = user.id;
    document.getElementById('reset_user_subtitle').textContent = 'Assign new password for ' + user.name;
    document.getElementById('reset_pw').value = '';
    openModal('modal-reset-user');
}

function confirmDeleteUser(user) {
    document.getElementById('delete_user_id').value = user.id;
    document.getElementById('delete-user-msg').innerHTML = 'Are you sure you want to permanently delete <strong>' + escapeHtml(user.name) + '</strong> (' + user.role.toUpperCase() + ')?';
    openModal('modal-delete-user');
}

function generateRandomPassword(targetId) {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*';
    let pass = '';
    for (let i = 0; i < 10; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const input = document.getElementById(targetId);
    if (input) {
        input.type = 'text';
        input.value = pass;
    }
}

function togglePasswordVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const isPw = input.type === 'password';
    input.type = isPw ? 'text' : 'password';
    const icon = btn.querySelector('i');
    if (icon) {
        icon.className = isPw ? 'fas fa-eye-slash' : 'fas fa-eye';
    }
}

function filterUsersTable() {
    const searchVal = document.getElementById('user-search-input').value.toLowerCase().trim();
    const roleVal = document.getElementById('user-role-filter').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.user-row');

    rows.forEach(row => {
        const name = row.getAttribute('data-name') || '';
        const email = row.getAttribute('data-email') || '';
        const role = row.getAttribute('data-role') || '';

        const matchSearch = (!searchVal || name.includes(searchVal) || email.includes(searchVal));
        const matchRole = (!roleVal || role === roleVal);

        row.style.display = (matchSearch && matchRole) ? '' : 'none';
    });
}

function escapeHtml(str) {
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

// Auto open create modal if query parameter ?create=1 is passed
if (window.location.search.includes('create=1')) {
    openCreateUserModal();
}

// Close modals on clicking backdrop
window.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-backdrop')) {
        e.target.style.display = 'none';
    }
});
</script>

<?php include 'includes/footer.php'; ?>
