<?php
require_once __DIR__ . '/auth.php';
require_member_login();

$member = current_member($pdo);
if (!$member) { header('Location: /gym/member/logout.php'); exit; }

$success = '';
$error   = '';

// Handle profile update (email / contact)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_contact') {
    $email   = trim($_POST['email'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');

    if (empty($email)) {
        $error = 'Email cannot be empty.';
    } else {
        try {
            $s = $pdo->prepare("UPDATE members SET email = ?, contact_number = ? WHERE id = ?");
            $s->execute([$email, $contact, $member['id']]);
            $success = 'Contact info updated successfully.';
            $member  = current_member($pdo); // refresh
        } catch (Exception $e) {
            $error = 'Could not update. Please try again.';
        }
    }
}

// Handle photo/selfie upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_photo') {
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        require_once __DIR__ . '/../config/uploader.php';
        $upload_result = secure_process_image_upload($_FILES['photo'], 'members', 1200, 1200);

        if ($upload_result['success']) {
            $db_path = $upload_result['path'];
            try {
                // Remove old photo safely if exists
                if (!empty($member['photo']) && file_exists(__DIR__ . '/../' . $member['photo'])) {
                    @unlink(__DIR__ . '/../' . $member['photo']);
                }
                
                $s = $pdo->prepare("UPDATE members SET photo = ? WHERE id = ?");
                $s->execute([$db_path, $member['id']]);
                $success = 'Profile picture updated successfully.';
                $member  = current_member($pdo); // refresh
                log_activity($pdo, 'Member Photo Uploaded', "Member {$member['full_name']} updated their profile picture.", 'Member');
            } catch (Exception $e) {
                $error = 'Could not update database with new photo.';
            }
        } else {
            $error = $upload_result['error'];
        }
    } else {
        $error = 'Please select a valid image file to upload.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>My Profile | <?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></title>
    <link rel="stylesheet" href="/gym/assets/css/member.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .profile-hero {
            background: linear-gradient(145deg, #1b4332 0%, #0a2218 65%, #060f0a 100%);
            border-radius: 22px;
            padding: 2rem 1.5rem 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
            border: 1px solid rgba(82,183,136,0.12);
            position: relative;
            overflow: hidden;
        }

        .profile-hero::before {
            content: '';
            position: absolute;
            top: -80px; left: 50%;
            transform: translateX(-50%);
            width: 250px; height: 250px;
            background: radial-gradient(circle, rgba(82,183,136,0.1) 0%, transparent 70%);
        }

        .profile-avatar {
            width: 90px; height: 90px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid rgba(255,255,255,0.2);
            box-shadow: 0 12px 30px rgba(0,0,0,0.35);
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, rgba(82,183,136,0.2), rgba(45,106,79,0.3));
            position: relative;
            z-index: 2;
        }

        .profile-avatar img {
            width: 100%; height: 100%; object-fit: cover;
        }

        .profile-avatar .avatar-initial {
            font-family: 'Outfit', sans-serif;
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--accent-light);
        }

        .profile-name {
            font-family: 'Outfit', sans-serif;
            font-size: 1.3rem;
            font-weight: 700;
            color: #fff;
            text-transform: capitalize;
            position: relative;
            z-index: 2;
        }

        .profile-id {
            font-size: 0.72rem;
            color: rgba(255,255,255,0.4);
            font-family: monospace;
            letter-spacing: 0.5px;
            position: relative;
            z-index: 2;
        }

        .info-section {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }

        .info-section-header {
            padding: 1rem 1.25rem 0.75rem;
            border-bottom: 1px solid var(--border);
        }

        .info-field {
            padding: 0.9rem 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
        }

        .info-field:last-child { border-bottom: none; }

        .info-field-label {
            font-size: 0.72rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.7px;
            font-weight: 700;
        }

        .info-field-value {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--text-primary);
            text-align: right;
            max-width: 60%;
            word-break: break-all;
        }

        .edit-section {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
        }

        .success-banner {
            background: var(--success-bg);
            color: var(--accent-light);
            border: 1px solid rgba(46,125,50,0.25);
            padding: 0.85rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.82rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logout-section {
            padding: 0 0 2rem;
        }
    </style>
    <link rel="manifest" href="/gym/member/manifest.json">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Palma's Elite">
    <link rel="apple-touch-icon" href="/gym/assets/images/palmas-logo.png">
</head>
<body>
<div class="mobile-container">

    <!-- Header -->
    <header class="app-header">
        <div class="app-brand">
            <img src="/gym/assets/images/palmas-logo.png" alt="Logo">
            <h1>My Profile</h1>
        </div>
        <div class="header-actions">
            <a href="logout.php" class="header-icon-btn danger" title="Sign Out">
                <i class="fas fa-right-from-bracket"></i>
            </a>
        </div>
    </header>

    <main class="app-content">

        <!-- Profile Hero -->
        <div class="profile-hero fade-up">
            <div class="profile-avatar">
                <?php if ($member['photo']): ?>
                    <img src="/gym/<?php echo htmlspecialchars($member['photo']); ?>" alt="Photo">
                <?php else: ?>
                    <span class="avatar-initial"><?php echo strtoupper(substr($member['full_name'], 0, 1)); ?></span>
                <?php endif; ?>
            </div>
            <h2 class="profile-name"><?php echo htmlspecialchars(strtolower($member['full_name'])); ?></h2>
            <p class="profile-id"><?php echo htmlspecialchars($member['membership_id']); ?></p>
            <?php if ($member['status'] === 'Active'): ?>
                <span class="badge badge-active"><i class="fas fa-circle" style="font-size:0.4rem;"></i> Active Member</span>
            <?php else: ?>
                <span class="badge badge-expired"><i class="fas fa-circle-xmark" style="font-size:0.7rem;"></i> Expired</span>
            <?php endif; ?>
        </div>

        <!-- Member Info -->
        <div class="info-section fade-up fade-up-d1">
            <div class="info-section-header">
                <p class="section-title"><i class="fas fa-user-circle"></i> Member Information</p>
            </div>
            <div class="info-field">
                <span class="info-field-label">Full Name</span>
                <span class="info-field-value"><?php echo htmlspecialchars($member['full_name']); ?></span>
            </div>
            <div class="info-field">
                <span class="info-field-label">Membership ID</span>
                <span class="info-field-value" style="font-family:monospace; color:var(--accent-light);"><?php echo htmlspecialchars($member['membership_id']); ?></span>
            </div>
            <div class="info-field">
                <span class="info-field-label">Current Plan</span>
                <span class="info-field-value" style="color:var(--accent-light);"><?php echo htmlspecialchars($member['plan_name'] ?: 'No Active Plan'); ?></span>
            </div>
            <div class="info-field">
                <span class="info-field-label">Expiry Date</span>
                <span class="info-field-value"><?php echo $member['expiry_date'] ? date('M d, Y', strtotime($member['expiry_date'])) : '—'; ?></span>
            </div>
            <div class="info-field">
                <span class="info-field-label">Status</span>
                <span class="info-field-value">
                    <?php if ($member['status'] === 'Active'): ?>
                        <span class="badge badge-active">Active</span>
                    <?php else: ?>
                        <span class="badge badge-expired">Expired</span>
                    <?php endif; ?>
                </span>
            </div>
            <?php if (!empty($member['gender'])): ?>
            <div class="info-field">
                <span class="info-field-label">Gender</span>
                <span class="info-field-value"><?php echo htmlspecialchars($member['gender']); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($member['dob'])): ?>
            <div class="info-field">
                <span class="info-field-label">Date of Birth</span>
                <span class="info-field-value"><?php echo date('M d, Y', strtotime($member['dob'])); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Update Profile Picture -->
        <div class="edit-section fade-up fade-up-d15" style="margin-bottom: 1.5rem;">
            <p class="section-title" style="margin-bottom:1rem;"><i class="fas fa-camera"></i> Update Profile Picture</p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
                <input type="hidden" name="action" value="upload_photo">
                <div class="form-group" style="margin-bottom: 1.25rem;">
                    <label for="photo"><i class="fas fa-image"></i> Select Selfie/Photo *</label>
                    <input type="file" name="photo" id="photo" class="form-control" accept="image/*" required style="padding: 0.5rem 0.75rem;">
                </div>
                <button type="submit" class="btn" style="background: var(--accent-gradient);">
                    <i class="fas fa-upload"></i> Upload Photo
                </button>
            </form>
        </div>

        <!-- Edit Contact Info -->
        <div class="edit-section fade-up fade-up-d2">
            <p class="section-title" style="margin-bottom:1rem;"><i class="fas fa-pen-to-square"></i> Update Contact Info</p>

            <?php if ($success): ?>
                <div class="success-banner" style="margin-bottom:1rem;">
                    <i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="error-banner" style="margin-bottom:1rem;">
                    <i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
                <input type="hidden" name="action" value="update_contact">
                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                    <input type="email" name="email" id="email" class="form-control"
                           value="<?php echo htmlspecialchars($member['email']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="contact_number"><i class="fas fa-phone"></i> Contact Number</label>
                    <input type="text" name="contact_number" id="contact_number" class="form-control"
                           value="<?php echo htmlspecialchars($member['contact_number'] ?? ''); ?>"
                           placeholder="09xxxxxxxxx">
                </div>
                <button type="submit" class="btn">
                    <i class="fas fa-floppy-disk"></i> Save Changes
                </button>
            </form>
        </div>

        <!-- Sign Out -->
        <div class="logout-section fade-up fade-up-d3">
            <a href="logout.php" class="btn btn-secondary" style="border-color:rgba(211,47,47,0.2); color:#ff6b6b;">
                <i class="fas fa-right-from-bracket"></i> Sign Out
            </a>
        </div>

    </main>

    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="index.php" class="nav-item">
            <i class="fas fa-house"></i><span>Home</span>
        </a>
        <a href="id-card.php" class="nav-item">
            <i class="fas fa-id-card"></i><span>E-ID</span>
        </a>
        <a href="attendance.php" class="nav-item">
            <i class="fas fa-calendar-check"></i><span>Visits</span>
        </a>
        <a href="payments.php" class="nav-item">
            <i class="fas fa-receipt"></i><span>Payments</span>
        </a>
        <a href="profile.php" class="nav-item active">
            <i class="fas fa-user"></i><span>Profile</span>
        </a>
    </nav>
</div>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/gym/member/sw.js');
    });
}
</script>
</body>
</html>
