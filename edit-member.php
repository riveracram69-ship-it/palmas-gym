<?php
$page_title = 'Edit Member';
include 'includes/header.php';
include 'includes/sidebar.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) { echo "<script>window.location.href='members.php';</script>"; exit; }

$member  = null;
$message = '';
$msg_type = 'success';

try {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
    $stmt->execute([$id]);
    $member = $stmt->fetch();
    if (!$member) { echo "<script>window.location.href='members.php';</script>"; exit; }
} catch (Exception $e) {
    echo "<script>window.location.href='members.php';</script>"; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name  = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $extension   = trim($_POST['extension'] ?? '');
    $name        = trim($_POST['full_name'] ?? '');

    if (!empty($first_name) || !empty($last_name)) {
        $name = trim(implode(' ', array_filter([$first_name, $middle_name, $last_name, $extension])));
    }

    $email   = trim($_POST['email'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');
    $age     = intval($_POST['age'] ?? 0);
    $gender  = $_POST['gender'] ?? 'Male';
    // Server-side guard: Only Admin can alter member status
    $status  = is_admin() ? ($_POST['status'] ?? $member['status']) : $member['status'];

    $validation_errors = [];
    if (empty($first_name) && empty($name)) $validation_errors[] = "First name is required.";
    if (empty($last_name) && empty($name))  $validation_errors[] = "Last name is required.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $validation_errors[] = "Invalid email address format.";
    if (!empty($_POST['age']) && ($age <= 0 || $age > 120)) $validation_errors[] = "Age must be between 1 and 120.";
    if (!empty($contact) && !preg_match('/^09[0-9]{9}$/', $contact)) $validation_errors[] = "Contact number must be exactly 11 digits starting with 09.";

    // Handle Photo Upload
    $photo_path = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        require_once __DIR__ . '/config/uploader.php';
        $upload_result = secure_process_image_upload($_FILES['photo'], 'members', 1200, 1200);
        if ($upload_result['success']) {
            $photo_path = $upload_result['path'];
            if (!empty($member['photo']) && file_exists(__DIR__ . '/' . $member['photo'])) {
                @unlink(__DIR__ . '/' . $member['photo']);
            }
        } else {
            $validation_errors[] = $upload_result['error'];
        }
    }

    // Check for duplicate email excluding the current member
    if (empty($validation_errors)) {
        $check_stmt = $pdo->prepare("SELECT id FROM members WHERE email = ? AND id != ?");
        $check_stmt->execute([$email, $id]);
        if ($check_stmt->fetch()) {
            $validation_errors[] = "The email address '{$email}' is already registered to another member.";
        }
    }

    if (!empty($validation_errors)) {
        $message  = implode('<br>', $validation_errors);
        $msg_type = 'error';
    } else {
        try {
            if ($photo_path) {
                $stmt = $pdo->prepare(
                    "UPDATE members SET first_name=?, middle_name=?, last_name=?, extension=?, full_name=?, email=?, contact_number=?, age=?, gender=?, status=?, photo=?
                     WHERE id=?"
                );
                $stmt->execute([$first_name ?: null, $middle_name ?: null, $last_name ?: null, $extension ?: null, $name, $email, $contact, $age ?: null, $gender, $status, $photo_path, $id]);
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE members SET first_name=?, middle_name=?, last_name=?, extension=?, full_name=?, email=?, contact_number=?, age=?, gender=?, status=?
                     WHERE id=?"
                );
                $stmt->execute([$first_name ?: null, $middle_name ?: null, $last_name ?: null, $extension ?: null, $name, $email, $contact, $age ?: null, $gender, $status, $id]);
            }
            $message  = 'Member details updated successfully.';
            $msg_type = 'success';

            // Refresh member data
            $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$id]);
            $member = $stmt->fetch();
        } catch (Exception $e) {
            error_log('System Error in edit-member.php: ' . $e->getMessage());
            $message  = 'An internal system error occurred while updating the member.';
            $msg_type = 'error';
        }
    }
}
?>

<a href="view-member.php?id=<?php echo $id; ?>" class="back-link">
    <i class="fas fa-arrow-left"></i> Back to Profile
</a>

<div class="topbar" style="margin-bottom:1.5rem;">
    <div class="page-title">
        <h1>Edit Member</h1>
        <p>Editing: <strong><?php echo htmlspecialchars($member['full_name']); ?></strong>
           &nbsp;<code style="font-size:0.8rem;color:var(--accent);"><?php echo htmlspecialchars($member['membership_id']); ?></code></p>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo $msg_type; ?>" style="margin-bottom:1.5rem;">
    <i class="fas fa-<?php echo $msg_type === 'success' ? 'circle-check' : 'circle-exclamation'; ?>"></i>
    <?php if (!empty($validation_errors) && count($validation_errors) > 1): ?>
        <ul style="margin:0; padding-left:1.2rem; display:flex; flex-direction:column; gap:0.25rem;">
            <?php foreach ($validation_errors as $verr): ?>
                <li><?php echo htmlspecialchars($verr); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <span><?php echo htmlspecialchars(!empty($validation_errors) ? $validation_errors[0] : $message); ?></span>
    <?php endif; ?>
    <?php if ($msg_type === 'success'): ?>
    <a href="view-member.php?id=<?php echo $id; ?>" style="margin-left:auto;color:inherit;font-weight:600;">View Profile →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<form method="POST" action="" id="edit-form" class="needs-validation" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
    <div class="card" style="max-width:760px;">
        <p class="section-title">Personal Information</p>

        <!-- Profile Photo -->
        <div style="display:flex; align-items:center; gap:1.5rem; margin-bottom:1.5rem; padding-bottom:1.25rem; border-bottom:1px solid var(--border);">
            <div style="width:80px; height:80px; border-radius:50%; border:2px solid var(--border); overflow:hidden; display:flex; align-items:center; justify-content:center; background:var(--palmas-dark);">
                <?php if (!empty($member['photo'])): ?>
                    <img id="edit-photo-preview" src="<?php echo htmlspecialchars($member['photo']); ?>" alt="Current member photo preview" style="width:100%; height:100%; object-fit:cover;">
                <?php else: ?>
                    <i id="edit-photo-icon" class="fas fa-camera" style="font-size:1.8rem; color:var(--text-muted);"></i>
                    <img id="edit-photo-preview" src="" alt="New member photo preview" style="width:100%; height:100%; object-fit:cover; display:none;">
                <?php endif; ?>
            </div>
            <div>
                <label style="font-weight:600; display:block; margin-bottom:0.35rem;">Member Photo</label>
                <input type="file" name="photo" id="edit-photo-input" accept="image/*" style="display:none;" onchange="
                    if (this.files && this.files[0]) {
                        const r = new FileReader();
                        r.onload = e => {
                            const prev = document.getElementById('edit-photo-preview');
                            const icon = document.getElementById('edit-photo-icon');
                            prev.src = e.target.result;
                            prev.style.display = 'block';
                            if (icon) icon.style.display = 'none';
                        };
                        r.readAsDataURL(this.files[0]);
                    }
                ">
                <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('edit-photo-input').click()">
                    <i class="fas fa-upload"></i> Change Photo
                </button>
                <span class="cell-secondary" style="font-size:0.75rem; margin-left:0.5rem;">JPG, PNG, max 3MB</span>
            </div>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label for="first_name">First Name *</label>
                <input type="text" name="first_name" id="first_name" class="form-control" required
                       value="<?php echo htmlspecialchars($member['first_name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="middle_name">Middle Name <span style="font-size:0.75rem;color:var(--text-muted);">(Optional)</span></label>
                <input type="text" name="middle_name" id="middle_name" class="form-control"
                       value="<?php echo htmlspecialchars($member['middle_name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="last_name">Last Name *</label>
                <input type="text" name="last_name" id="last_name" class="form-control" required
                       value="<?php echo htmlspecialchars($member['last_name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="extension">Suffix / Extension</label>
                <select name="extension" id="extension" class="form-control">
                    <?php $cur_ext = $member['extension'] ?? ''; ?>
                    <option value="" <?php echo $cur_ext === '' ? 'selected' : ''; ?>>None</option>
                    <option value="Jr." <?php echo $cur_ext === 'Jr.' ? 'selected' : ''; ?>>Jr.</option>
                    <option value="Sr." <?php echo $cur_ext === 'Sr.' ? 'selected' : ''; ?>>Sr.</option>
                    <option value="II" <?php echo $cur_ext === 'II' ? 'selected' : ''; ?>>II</option>
                    <option value="III" <?php echo $cur_ext === 'III' ? 'selected' : ''; ?>>III</option>
                    <option value="IV" <?php echo $cur_ext === 'IV' ? 'selected' : ''; ?>>IV</option>
                    <option value="V" <?php echo $cur_ext === 'V' ? 'selected' : ''; ?>>V</option>
                </select>
            </div>
            <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" name="email" id="email" class="form-control" required
                       value="<?php echo htmlspecialchars($member['email']); ?>">
            </div>
            <div class="form-group">
                <label for="contact_number">Contact Number</label>
                <input type="text" name="contact_number" id="contact_number" class="form-control" placeholder="09XXXXXXXXX" maxlength="11" pattern="09[0-9]{9}" title="Must be exactly 11 digits starting with 09" required
                       value="<?php echo htmlspecialchars($member['contact_number'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="age">Age</label>
                <input type="number" name="age" id="age" class="form-control" min="1" max="120" required
                       value="<?php echo htmlspecialchars($member['age'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="gender">Gender</label>
                <select name="gender" id="gender" class="form-control">
                    <?php foreach (['Male','Female','Other'] as $g): ?>
                    <option value="<?php echo $g; ?>" <?php echo $member['gender'] === $g ? 'selected' : ''; ?>><?php echo $g; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="status">Membership Status</label>
                <?php if (is_admin()): ?>
                <select name="status" id="status" class="form-control">
                    <?php foreach (['Active','Inactive','Expired'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $member['status'] === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <div style="display:flex;align-items:center;height:42px;gap:0.5rem;padding:0 0.5rem;background:var(--bg-input, rgba(0,0,0,0.03));border-radius:var(--radius-sm);border:1px solid var(--border);">
                    <span class="badge <?php echo $member['status'] === 'Active' ? 'badge-success' : 'badge-danger'; ?>">
                        <i class="fas fa-circle" style="font-size:0.35rem;margin-right:4px;"></i>
                        <?php echo htmlspecialchars($member['status']); ?>
                    </span>
                    <span style="font-size:0.75rem;color:var(--text-muted);">(Admin managed)</span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div style="padding-top:1.25rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:0.75rem;">
            <a href="view-member.php?id=<?php echo $id; ?>" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary" id="save-btn">
                <i class="fas fa-floppy-disk"></i> Save Changes
            </button>
        </div>
    </div>
</form>

<script>
document.getElementById('edit-form').addEventListener('submit', function() {
    const btn = document.getElementById('save-btn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    btn.disabled = true;
});
</script>

<?php include 'includes/footer.php'; ?>
