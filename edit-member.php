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
    $name    = trim($_POST['full_name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $contact = trim($_POST['contact_number'] ?? '');
    $age     = intval($_POST['age'] ?? 0);
    $gender  = $_POST['gender'] ?? 'Male';
    // Server-side guard: Only Admin can alter member status
    $status  = is_admin() ? ($_POST['status'] ?? $member['status']) : $member['status'];

    $validation_errors = [];
    if (empty($name)) $validation_errors[] = "Full name is required.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $validation_errors[] = "Invalid email address format.";
    if (!empty($_POST['age']) && ($age <= 0 || $age > 120)) $validation_errors[] = "Age must be between 1 and 120.";
    if (!empty($contact) && !preg_match('/^09[0-9]{9}$/', $contact)) $validation_errors[] = "Contact number must be exactly 11 digits starting with 09.";

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
            $stmt = $pdo->prepare(
                "UPDATE members SET full_name=?, email=?, contact_number=?, age=?, gender=?, status=?
                 WHERE id=?"
            );
            $stmt->execute([$name, $email, $contact, $age ?: null, $gender, $status, $id]);
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
    <?php echo htmlspecialchars($message); ?>
    <?php if ($msg_type === 'success'): ?>
    <a href="view-member.php?id=<?php echo $id; ?>" style="margin-left:auto;color:inherit;font-weight:600;">View Profile →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<form method="POST" action="" id="edit-form" class="needs-validation" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
    <div class="card" style="max-width:760px;">
        <p class="section-title">Personal Information</p>
        <div class="form-grid">
            <div class="form-group">
                <label for="full_name">Full Name *</label>
                <input type="text" name="full_name" id="full_name" class="form-control" required
                       value="<?php echo htmlspecialchars($member['full_name']); ?>">
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
