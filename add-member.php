<?php
$page_title = 'Add Member';
include 'includes/header.php';
include 'includes/sidebar.php';

// Fetch plans for selection
$plans = [];
try {
    if (isset($pdo) && $pdo) {
        $plans = $pdo->query("SELECT id, name, price, duration_months FROM membership_plans ORDER BY price ASC")->fetchAll();
    }
} catch (Exception $e) {}

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $contact   = trim($_POST['contact_number'] ?? '');
    $age       = intval($_POST['age'] ?? 0);
    $gender    = $_POST['gender'] ?? '';
    $plan_id   = intval($_POST['plan_id'] ?? 0);
    
    // Handle Photo Upload via Defense-in-Depth Uploader
    $photo_path = null;
    $validation_errors = [];
    
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        require_once __DIR__ . '/config/uploader.php';
        $upload_result = secure_process_image_upload($_FILES['photo'], 'members', 1200, 1200);
        if ($upload_result['success']) {
            $photo_path = $upload_result['path'];
        } else {
            $validation_errors[] = $upload_result['error'];
        }
    }

    // Validation Rules
    if (empty($full_name)) $validation_errors[] = "Full name is required.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $validation_errors[] = "Invalid email address format.";
    if (!empty($_POST['age']) && ($age <= 0 || $age > 120)) $validation_errors[] = "Age must be between 1 and 120.";
    if (!empty($contact) && !preg_match('/^09[0-9]{9}$/', $contact)) $validation_errors[] = "Contact number must be exactly 11 digits starting with 09.";
    if (empty($plan_id)) $validation_errors[] = "Please select a membership plan.";

    // Check for duplicate email
    if (empty($validation_errors)) {
        $check_stmt = $pdo->prepare("SELECT id FROM members WHERE email = ?");
        $check_stmt->execute([$email]);
        if ($check_stmt->fetch()) {
            $validation_errors[] = "The email address '{$email}' is already registered to another member.";
        }
    }

    if (empty($validation_errors)) {
        try {
            $pdo->beginTransaction();
            
            $created_by = $_SESSION['user_id'] ?? null;
            $membership_id = 'GYM-' . strtoupper(substr(uniqid(), -6));

            $stmt = $pdo->prepare("INSERT INTO members (membership_id, full_name, email, contact_number, age, gender, photo, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', ?)");
            $stmt->execute([$membership_id, $full_name, $email, $contact, $age, $gender, $photo_path, $created_by]);
            $member_id = $pdo->lastInsertId();

            $plan_stmt = $pdo->prepare("SELECT duration_months FROM membership_plans WHERE id = ?");
            $plan_stmt->execute([$plan_id]);
            $plan = $plan_stmt->fetch();
            
            $start_date = date('Y-m-d');
            $expiry_date = date('Y-m-d', strtotime("+" . ($plan['duration_months'] ?? 1) . " months"));

            $stmt = $pdo->prepare("INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$member_id, $plan_id, $start_date, $expiry_date, $created_by]);
            $subscription_id = $pdo->lastInsertId();

            // Record Payment
            $amount_paid    = $_POST['amount_paid'] ?? 0;
            $payment_method = $_POST['payment_method'] ?? 'Cash';
            $payment_date   = $_POST['payment_date'] ?? date('Y-m-d');
            $reference_num  = trim($_POST['reference_number'] ?? '');

            if (in_array($payment_method, ['GCash', 'Bank Transfer']) && empty($reference_num)) {
                throw new Exception("Reference number is required for GCash and Bank Transfer.");
            }

            $stmt = $pdo->prepare("INSERT INTO payments (member_id, subscription_id, amount, payment_method, reference_number, payment_date, verified_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$member_id, $subscription_id, $amount_paid, $payment_method, $reference_num ?: null, $payment_date, $created_by]);

            $pdo->commit();

            // Send Email Notification
            require_once 'config/email.php';
            $email_subject = "Welcome to Palma's Elite Gym!";
            $email_title = "Welcome, {$full_name}!";
            $email_body = "Your registration is successful. Your Membership ID is: <strong>{$membership_id}</strong>. You can use this ID to log in to the exclusive Member Portal.";
            send_email_notification($email, $email_subject, $email_title, $email_body);

            // Log the activity
            log_activity($pdo, 'Added Member', "Registered new member: {$full_name} (ID: {$membership_id})", 'Member');
            log_activity($pdo, 'Created Subscription', "Assigned plan to {$full_name}, expires {$expiry_date}", 'Subscription');
            if ($amount_paid > 0) {
                log_activity($pdo, 'Recorded Payment', "Payment of ₱{$amount_paid} via {$payment_method} for {$full_name}", 'Payment');
            }

            $message = "Member registered! ID: $membership_id. You can now generate their E-ID.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('System Error in add-member.php: ' . $e->getMessage());
            $error = "A system error occurred while adding the member.";
        }
    } else {
        $error = implode('<br>', $validation_errors);
    }
}
?>

<div class="topbar">
    <div class="page-title">
        <h1>Register New Member</h1>
        <p>Complete the profile and assign a membership plan.</p>
    </div>
    <a href="members.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to List</a>
</div>

<?php if ($message): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
<?php endif; ?>

<form method="POST" action="" enctype="multipart/form-data" class="needs-validation" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
    <div style="display:grid; grid-template-columns: 2fr 1fr; gap: 2rem; align-items: start;">
        
        <div style="display:flex; flex-direction:column; gap:2rem;">
            <!-- Main Info -->
            <div class="card">
                <h3 class="section-title"><i class="fas fa-user-pen" style="color:var(--accent);"></i> Personal Information</h3>
                
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" class="form-control" placeholder="John Doe" required>
                </div>

                <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="form-group">
                        <label>Email Address *</label>
                        <input type="email" name="email" class="form-control" placeholder="john@example.com" required>
                    </div>
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="text" name="contact_number" class="form-control" placeholder="09XXXXXXXXX" maxlength="11" pattern="09[0-9]{9}" title="Must be exactly 11 digits starting with 09" required>
                    </div>
                </div>

                <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="form-group">
                        <label>Age</label>
                        <input type="number" name="age" class="form-control" placeholder="20" min="1" max="120" required>
                    </div>
                    <div class="form-group">
                        <label>Gender</label>
                        <select name="gender" class="form-control">
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Plan Info -->
            <div class="card">
                <h3 class="section-title"><i class="fas fa-gem" style="color:var(--accent);"></i> Membership Details</h3>
                <div class="form-group">
                    <label>Select Membership Plan *</label>
                    <select name="plan_id" class="form-control" required>
                        <option value="" disabled selected>Choose a plan...</option>
                        <?php foreach ($plans as $p): ?>
                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?> — ₱<?php echo number_format($p['price'], 2); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="plan-info-note">
                    <i class="fas fa-info-circle" style="color:var(--accent);"></i>
                    <span>E-ID Card and QR Code will be automatically generated after registration.</span>
                </div>
            </div>

            <!-- Payment Info -->
            <div class="card">
                <h3 class="section-title"><i class="fas fa-money-bill-wave" style="color:var(--success);"></i> Initial Payment</h3>
                <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="form-group">
                        <label>Amount Paid (₱) *</label>
                        <input type="number" name="amount_paid" id="amount_paid" class="form-control" placeholder="0.00" min="0" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Payment Method *</label>
                        <select name="payment_method" id="payment-method-select" class="form-control" required>
                            <option value="Cash">Cash</option>
                            <option value="GCash">GCash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Credit Card">Credit Card</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="form-group">
                        <label>Payment Date</label>
                        <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group" id="ref-number-group" style="display:none;">
                        <label>Reference Number *</label>
                        <input type="text" name="reference_number" class="form-control" placeholder="e.g. 100239401923" title="Required for online transfers">
                    </div>
                </div>
            </div>
        </div>

        <div class="sidebar-col">
            <div class="card" style="text-align:center;">
                <h3 class="section-title">Member Photo</h3>
                <div id="photo-preview" class="photo-preview">
                    <i class="fas fa-camera" style="font-size:2rem; color:var(--border);"></i>
                </div>
                <input type="file" name="photo" id="photo-input" accept="image/*" style="display:none;">
                <button type="button" class="btn btn-outline w-100" onclick="document.getElementById('photo-input').click()">
                    <i class="fas fa-upload"></i> Upload Photo
                </button>
                <p class="cell-secondary" style="margin-top:0.75rem;">JPG or PNG, max 2MB. Square crop recommended.</p>
            </div>

            <button type="submit" class="btn btn-primary w-100" style="padding:1.2rem; font-size:1rem;">
                <i class="fas fa-check"></i> Register Member
            </button>
        </div>

    </div>
</form>

<script>
document.getElementById('photo-input').onchange = function(e) {
    const [file] = this.files;
    if (file) {
        const preview = document.getElementById('photo-preview');
        preview.innerHTML = `<img src="${URL.createObjectURL(file)}" style="width:100%; height:100%; object-fit:cover;">`;
    }
};

// Auto-fill amount based on plan
const plans = <?php echo json_encode($plans); ?>;
document.querySelector('select[name="plan_id"]').onchange = function() {
    const planId = this.value;
    const selectedPlan = plans.find(p => p.id == planId);
    if (selectedPlan) {
        document.getElementById('amount_paid').value = selectedPlan.price;
    }
};

// Toggle reference number field
document.getElementById('payment-method-select').addEventListener('change', function() {
    const val = this.value;
    const refGroup = document.getElementById('ref-number-group');
    const refInput = refGroup.querySelector('input');
    if (val === 'GCash' || val === 'Bank Transfer') {
        refGroup.style.display = 'block';
        refInput.required = true;
    } else {
        refGroup.style.display = 'none';
        refInput.required = false;
        refInput.value = '';
    }
});
</script>

<?php include 'includes/footer.php'; ?>
