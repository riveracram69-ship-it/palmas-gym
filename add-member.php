<?php
$page_title = 'Add Member';
include 'includes/header.php';
include 'includes/sidebar.php';

// Fetch plans for selection (Active plans only)
$plans = [];
try {
    if (isset($pdo) && $pdo) {
        $plans = $pdo->query("SELECT id, name, price, duration_months, duration_minutes, is_test_promo, plan_category FROM membership_plans WHERE is_active = 1 ORDER BY (plan_category = 'membership_fee') DESC, (plan_category = 'member_pass') DESC, price ASC")->fetchAll();
    }
} catch (Exception $e) {}

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name  = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $extension   = trim($_POST['extension'] ?? '');
    $full_name   = trim($_POST['full_name'] ?? '');

    if (!empty($first_name) || !empty($last_name)) {
        $full_name = trim(implode(' ', array_filter([$first_name, $middle_name, $last_name, $extension])));
    } elseif (!empty($full_name)) {
        $tokens = preg_split('/\s+/', $full_name);
        if (count($tokens) > 1) {
            $last_token = strtoupper(rtrim(end($tokens), '.'));
            if (in_array($last_token, ['JR', 'SR', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'])) {
                $extension = array_pop($tokens);
            }
        }
        $nt = count($tokens);
        if ($nt === 1) {
            $first_name = $tokens[0];
            $last_name  = $tokens[0];
        } elseif ($nt === 2) {
            $first_name = $tokens[0];
            $last_name  = $tokens[1];
        } elseif ($nt === 3) {
            $first_name  = $tokens[0];
            $middle_name = $tokens[1];
            $last_name   = $tokens[2];
        } else {
            $last_name   = array_pop($tokens);
            $middle_name = array_pop($tokens);
            $first_name  = implode(' ', $tokens);
        }
    }

    $email        = trim($_POST['email'] ?? '');
    $contact      = trim($_POST['contact_number'] ?? '');
    
    // Structured Address components
    $house_street = trim($_POST['house_street'] ?? '');
    $barangay     = trim($_POST['barangay'] ?? '');
    $municipality = trim($_POST['municipality'] ?? '');
    $province     = trim($_POST['province'] ?? '');
    $zip_code     = trim($_POST['zip_code'] ?? '');
    $address      = compose_member_address_string($house_street, $barangay, $municipality, $province, $zip_code, trim($_POST['address'] ?? ''));

    // Date of Birth & Dynamic Age
    $dob          = trim($_POST['dob'] ?? '');
    $age          = compute_member_age($dob, !empty($_POST['age']) ? intval($_POST['age']) : null);
    $gender       = $_POST['gender'] ?? 'Male';
    $plan_id      = intval($_POST['plan_id'] ?? 0);
    
    // Handle Photo Upload via Defense-in-Depth Uploader
    $photo_path = null;
    $validation_errors = [];
    
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        require_once __DIR__ . '/config/uploader.php';
        $upload_result = secure_process_image_upload($_FILES['photo'], 'members', 600, 600);
        if ($upload_result['success']) {
            $photo_path = $upload_result['path'];
        } else {
            $validation_errors[] = $upload_result['error'];
        }
    }

    // Validation Rules
    if (empty($first_name)) $validation_errors[] = "First name is required.";
    if (empty($last_name)) $validation_errors[] = "Last name is required.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $validation_errors[] = "Invalid email address format.";
    if (empty($contact)) {
        $validation_errors[] = "Contact number is required.";
    } elseif (!preg_match('/^09[0-9]{9}$/', $contact)) {
        $validation_errors[] = "Contact number must be exactly 11 digits starting with 09.";
    }
    if (empty($address) && empty($municipality)) $validation_errors[] = "Home address details are required.";
    if (empty($dob)) {
        $validation_errors[] = "Date of birth is required.";
    } elseif (!empty($dob)) {
        $dob_ts = strtotime($dob);
        if ($dob_ts === false || $dob_ts > time()) {
            $validation_errors[] = "Date of birth cannot be in the future.";
        }
    }
    if ($age === null || $age < 15 || $age > 120) {
        $validation_errors[] = "Age must be between 15 and 120. Members must be at least 15 years old.";
    }
    if (empty($plan_id)) {
        $validation_errors[] = "Please select a membership plan.";
    }

    // Fetch and validate selected plan
    $plan = null;
    if ($plan_id > 0) {
        $plan_stmt = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ?");
        $plan_stmt->execute([$plan_id]);
        $plan = $plan_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan || (int)($plan['is_active'] ?? 0) !== 1) {
            $validation_errors[] = "The selected membership plan is invalid or no longer active.";
        } elseif (($plan['plan_category'] ?? '') === 'member_pass') {
            $validation_errors[] = "Member discounted rates require an active Annual Membership. Please register with the Annual Membership Fee or a Non-Member pass.";
        }
    }

    // Check for duplicate email
    if (empty($validation_errors)) {
        $check_stmt = $pdo->prepare("SELECT id FROM members WHERE email = ?");
        $check_stmt->execute([$email]);
        if ($check_stmt->fetch()) {
            $validation_errors[] = "The email address '{$email}' is already registered to another member.";
        }
    }

    if (empty($validation_errors) && $plan) {
        try {
            $pdo->beginTransaction();
            
            $created_by = $_SESSION['user_id'] ?? null;
            $membership_id = 'GYM-' . strtoupper(substr(uniqid(), -6));

            $duration_minutes = intval($plan['duration_minutes'] ?? 0);
            $duration_months  = intval($plan['duration_months'] ?? 0);
            $plan_category    = $plan['plan_category'] ?? 'legacy';

            $is_daily_pass     = ($duration_minutes === 1440 || ($duration_months === 0 && stripos($plan['name'] ?? '', 'Daily') !== false));
            $is_minute_promo   = ($duration_minutes > 0 && !$is_daily_pass);
            if ($is_minute_promo && $duration_minutes <= 0 && preg_match('/(\d+)\s*(?:min|minute)/i', $plan['name'] ?? '', $pm)) {
                $duration_minutes = intval($pm[1]);
            }
            $is_membership_fee = ($plan_category === 'membership_fee' || stripos($plan['name'] ?? '', 'Annual Membership Fee') !== false);
            $is_test_payment   = ((int)($plan['is_test_promo'] ?? 0) === 1 || $plan_category === 'test_promo') ? 1 : 0;

            $start_date = date('Y-m-d H:i:s');
            $ann_expiry = null;

            if ($is_membership_fee) {
                $ann_expiry = date('Y-m-d', strtotime('+1 year'));
                $expiry_date = $ann_expiry . ' 23:59:59';
            } elseif ($is_minute_promo) {
                $expiry_date = date('Y-m-d H:i:s', strtotime("+{$duration_minutes} minutes"));
            } elseif ($is_daily_pass) {
                $expiry_date = date('Y-m-d 23:59:59'); // Daily Pass strictly expires at end of today
            } else {
                if ($duration_months <= 0) $duration_months = 1;
                $expiry_date = date('Y-m-d H:i:s', strtotime("+{$duration_months} months"));
            }

            $stmt = $pdo->prepare("
                INSERT INTO members (
                    membership_id, first_name, middle_name, last_name, extension, full_name,
                    email, contact_number, house_street, barangay, municipality, province, zip_code, address,
                    dob, age, gender, photo, status, created_by, account_status, approved_by, approved_at,
                    annual_membership_expiry
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, 'Approved', ?, NOW(), ?)
            ");
            $stmt->execute([
                $membership_id, $first_name, $middle_name ?: null, $last_name, $extension ?: null, $full_name,
                $email, $contact, $house_street ?: null, $barangay ?: null, $municipality ?: null, $province ?: null, $zip_code ?: null, $address ?: null,
                $dob ?: null, $age, $gender, $photo_path, $created_by, $created_by, $ann_expiry
            ]);
            $member_id = $pdo->lastInsertId();

            $subscription_id = null;
            if (!$is_membership_fee) {
                $stmt = $pdo->prepare("INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date, created_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$member_id, $plan_id, $start_date, $expiry_date, $created_by]);
                $subscription_id = $pdo->lastInsertId();
            }

            // Authoritative server-side price from database
            $amount_paid    = floatval($plan['price']);
            $payment_method = $_POST['payment_method'] ?? 'Cash';
            $payment_date   = $_POST['payment_date'] ?? date('Y-m-d');
            $reference_num  = trim($_POST['reference_number'] ?? '');
            $payment_notes  = $is_membership_fee ? 'Annual Membership Fee Registration' : 'Registration Payment';

            if (in_array($payment_method, ['GCash', 'Bank Transfer']) && empty($reference_num)) {
                throw new Exception("Reference number is required for GCash and Bank Transfer.");
            }

            $stmt = $pdo->prepare("
                INSERT INTO payments (member_id, subscription_id, amount, payment_method, reference_number, payment_date, verified_by, notes, is_test, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$member_id, $subscription_id, $amount_paid, $payment_method, $reference_num ?: null, $payment_date, $created_by, $payment_notes, $is_test_payment]);

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
        <p>Complete the client profile and assign a membership plan.</p>
    </div>
    <div style="display:flex; gap:0.6rem; align-items:center;">
        <?php if (is_admin()): ?>
        <a href="users.php?create=1" class="btn btn-outline" style="border-radius:10px; font-weight:600;">
            <i class="fas fa-user-shield"></i> Create Staff User Instead
        </a>
        <?php endif; ?>
        <a href="members.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to List</a>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if (!empty($validation_errors)): ?>
<div class="alert alert-error" style="margin-bottom:1.5rem;">
    <i class="fas fa-exclamation-triangle"></i>
    <div style="flex:1;">
        <strong>Please correct the following errors:</strong>
        <ul style="margin:0.5rem 0 0 1.2rem; padding:0; display:flex; flex-direction:column; gap:0.25rem;">
            <?php foreach ($validation_errors as $vErr): ?>
                <li><?php echo htmlspecialchars($vErr); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php elseif ($error): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="POST" action="" enctype="multipart/form-data" class="needs-validation" onsubmit="const btn=this.querySelector('button[type=submit]'); if(btn && !btn.disabled){ btn.disabled=true; btn.innerHTML='<i class=\'fas fa-spinner fa-spin\'></i> Registering...'; return true; } return false;" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
    <div style="display:grid; grid-template-columns: 2fr 1fr; gap: 1.75rem; align-items: start;">
        
        <div style="display:flex; flex-direction:column; gap:1.25rem;">
            <!-- Fast Entry Core Details -->
            <div class="card" style="padding:1.5rem;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1.25rem; border-bottom:1px solid var(--border); padding-bottom:0.75rem;">
                    <h3 class="section-title" style="margin:0;"><i class="fas fa-bolt" style="color:var(--accent);"></i> Quick Member Registration</h3>
                    <span style="font-size:0.75rem; color:var(--text-muted);"><i class="fas fa-check-circle" style="color:var(--palmas-primary, #10b981);"></i> Required fields marked with *</span>
                </div>
                
                <div class="form-grid" style="grid-template-columns: 1fr 1fr; margin-bottom:1rem;">
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="first_name" class="form-control" placeholder="e.g. Juan" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required autofocus>
                    </div>
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="last_name" class="form-control" placeholder="e.g. Dela Cruz" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="form-grid" style="grid-template-columns: 1fr 1fr; margin-bottom:1rem;">
                    <div class="form-group">
                        <label>Email Address *</label>
                        <input type="email" name="email" class="form-control" placeholder="juan@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Contact Number *</label>
                        <input type="text" name="contact_number" class="form-control" placeholder="09XXXXXXXXX" maxlength="11" pattern="09[0-9]{9}" title="11 digits starting with 09" value="<?php echo htmlspecialchars($_POST['contact_number'] ?? ''); ?>" required>
                    </div>
                </div>

                <!-- Structured Address Fields -->
                <div style="background:rgba(255,255,255,0.02); border:1px solid var(--border); border-radius:12px; padding:1rem; margin-bottom:1rem;">
                    <label style="display:block; font-weight:700; font-size:0.85rem; color:var(--text-main); margin-bottom:0.75rem; text-transform:uppercase; letter-spacing:0.5px;">
                        <i class="fas fa-location-dot" style="color:var(--palmas-primary, #10b981); margin-right:6px;"></i> Home Address Details
                    </label>
                    <div class="form-group" style="margin-bottom:0.75rem;">
                        <label style="font-size:0.8rem;">House No. / Street / Building</label>
                        <input type="text" name="house_street" class="form-control" placeholder="e.g. 123 Rizal St. or Unit 4B Sunshine Bldg." value="<?php echo htmlspecialchars($_POST['house_street'] ?? ''); ?>">
                    </div>
                    <div class="form-grid" style="grid-template-columns: 1fr 1fr; margin-bottom:0.75rem;">
                        <div class="form-group">
                            <label style="font-size:0.8rem;">Barangay *</label>
                            <input type="text" name="barangay" class="form-control" placeholder="e.g. Poblacion or San Jose" value="<?php echo htmlspecialchars($_POST['barangay'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label style="font-size:0.8rem;">Municipality / City *</label>
                            <input type="text" name="municipality" class="form-control" placeholder="e.g. Talavera or Quezon City" value="<?php echo htmlspecialchars($_POST['municipality'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
                        <div class="form-group">
                            <label style="font-size:0.8rem;">Province</label>
                            <input type="text" name="province" class="form-control" placeholder="e.g. Nueva Ecija" value="<?php echo htmlspecialchars($_POST['province'] ?? 'Nueva Ecija'); ?>">
                        </div>
                        <div class="form-group">
                            <label style="font-size:0.8rem;">ZIP Code</label>
                            <input type="text" name="zip_code" class="form-control" placeholder="e.g. 3114" maxlength="10" value="<?php echo htmlspecialchars($_POST['zip_code'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div class="form-grid" style="grid-template-columns: 1fr 1fr; margin-bottom:1rem;">
                    <div class="form-group">
                        <label>Membership Plan *</label>
                        <select name="plan_id" class="form-control" required>
                            <option value="" disabled <?php echo empty($_POST['plan_id']) ? 'selected' : ''; ?>>Choose a plan...</option>
                            <?php 
                            $sec_fee = array_filter($plans, fn($p) => ($p['plan_category'] ?? '') === 'membership_fee' || stripos($p['name'], 'Annual Membership') !== false);
                            $sec_daily = array_filter($plans, fn($p) => (intval($p['duration_minutes'] ?? 0) === 1440 || (intval($p['duration_months'] ?? 0) === 0 && (intval($p['duration_minutes'] ?? 0) > 0 || stripos($p['name'], 'Daily') !== false))) && ($p['plan_category'] ?? '') !== 'membership_fee');
                            $sec_monthly = array_filter($plans, fn($p) => intval($p['duration_months'] ?? 0) > 0 && ($p['plan_category'] ?? '') !== 'membership_fee' && stripos($p['name'], 'Annual Membership') === false);
                            ?>
                            <?php if (!empty($sec_fee)): ?>
                            <optgroup label="🏅 Membership Fee">
                                <?php foreach ($sec_fee as $p): ?>
                                <?php $is_sel = ((int)($_POST['plan_id'] ?? 0) === (int)$p['id']); ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo $is_sel ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['name']); ?> — ₱<?php echo number_format($p['price'], 2); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>

                            <?php if (!empty($sec_daily)): ?>
                            <optgroup label="⚡ Daily Access Passes">
                                <?php foreach ($sec_daily as $p): ?>
                                <?php $is_sel = ((int)($_POST['plan_id'] ?? 0) === (int)$p['id']); ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo $is_sel ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['name']); ?> — ₱<?php echo number_format($p['price'], 2); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>

                            <?php if (!empty($sec_monthly)): ?>
                            <optgroup label="📅 Monthly / Yearly Registrations">
                                <?php foreach ($sec_monthly as $p): ?>
                                <?php $is_sel = ((int)($_POST['plan_id'] ?? 0) === (int)$p['id']); ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo $is_sel ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['name']); ?> — ₱<?php echo number_format($p['price'], 2); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Payment Method *</label>
                        <?php $selected_pm = $_POST['payment_method'] ?? 'Cash'; ?>
                        <select name="payment_method" id="payment-method-select" class="form-control" required>
                            <option value="Cash" <?php echo $selected_pm === 'Cash' ? 'selected' : ''; ?>>Cash (Front Desk)</option>
                            <option value="GCash" <?php echo $selected_pm === 'GCash' ? 'selected' : ''; ?>>GCash E-Wallet</option>
                            <option value="Maya" <?php echo $selected_pm === 'Maya' ? 'selected' : ''; ?>>Maya E-Wallet</option>
                        </select>
                    </div>
                </div>

                <input type="hidden" name="amount_paid" id="amount_paid" value="<?php echo htmlspecialchars($_POST['amount_paid'] ?? ''); ?>">
                <input type="hidden" name="payment_date" value="<?php echo htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d')); ?>">

                <!-- Date of Birth & Personal Demographics -->
                <div style="margin-top:1rem; border-top:1px dashed var(--border); padding-top:0.75rem;">
                    <button type="button" onclick="document.getElementById('extra-fields').style.display = (document.getElementById('extra-fields').style.display === 'none' ? 'block' : 'none')" class="btn btn-outline" style="font-size:0.8rem; padding:6px 14px; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
                        <i class="fas fa-sliders"></i> Date of Birth &amp; Additional Details <i class="fas fa-chevron-down" style="font-size:0.7rem;"></i>
                    </button>

                    <div id="extra-fields" style="display:block; margin-top:1rem; padding:1rem; background:rgba(255,255,255,0.02); border:1px solid var(--border); border-radius:10px;">
                        <div class="form-grid" style="grid-template-columns: 1fr 1fr; margin-bottom:0.75rem;">
                            <div class="form-group">
                                <label>Date of Birth <span style="color:red;">*</span> <span id="computed-age-badge" style="font-size:0.75rem; color:var(--palmas-primary, #10b981); font-weight:700; margin-left:6px;"></span></label>
                                <input type="date" name="dob" id="dob-input" class="form-control" max="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($_POST['dob'] ?? ''); ?>" oninput="updateComputedAge(this.value)" required>
                            </div>
                            <div class="form-group">
                                <label>Gender</label>
                                <select name="gender" class="form-control">
                                    <?php $selected_gender = $_POST['gender'] ?? 'Male'; ?>
                                    <option value="Male" <?php echo $selected_gender === 'Male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo $selected_gender === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other" <?php echo $selected_gender === 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
                            <div class="form-group">
                                <label>Middle Name <span style="font-size:0.72rem; color:var(--text-muted);">(Optional)</span></label>
                                <input type="text" name="middle_name" class="form-control" placeholder="Santos" value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Suffix <span style="font-size:0.72rem; color:var(--text-muted);">(Optional)</span></label>
                                <select name="extension" class="form-control">
                                    <?php $cur_ext = $_POST['extension'] ?? ''; ?>
                                    <option value="" <?php echo $cur_ext === '' ? 'selected' : ''; ?>>None</option>
                                    <option value="Jr." <?php echo $cur_ext === 'Jr.' ? 'selected' : ''; ?>>Jr.</option>
                                    <option value="Sr." <?php echo $cur_ext === 'Sr.' ? 'selected' : ''; ?>>Sr.</option>
                                    <option value="II" <?php echo $cur_ext === 'II' ? 'selected' : ''; ?>>II</option>
                                    <option value="III" <?php echo $cur_ext === 'III' ? 'selected' : ''; ?>>III</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group" id="ref-number-group" style="margin-top:0.75rem; display:none;">
                            <label>Online Reference Number</label>
                            <input type="text" name="reference_number" class="form-control" placeholder="Optional transaction reference" value="<?php echo htmlspecialchars($_POST['reference_number'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="sidebar-col">
            <div class="card" style="text-align:center; padding:1.25rem;">
                <h3 class="section-title" style="margin-bottom:0.75rem;">Member Photo</h3>
                <div id="photo-preview" class="photo-preview" style="width:96px; height:96px; border-radius:50%; margin:0 auto 10px; border:2px dashed var(--border); display:flex; align-items:center; justify-content:center; overflow:hidden; background:rgba(0,0,0,0.1); cursor:pointer;" onclick="document.getElementById('photo-input').click()">
                    <i class="fas fa-camera" style="font-size:1.8rem; color:var(--border);"></i>
                </div>
                <input type="file" name="photo" id="photo-input" accept="image/*" style="display:none;">
                <button type="button" class="btn btn-outline w-100" style="padding:6px 12px; font-size:0.8rem;" onclick="document.getElementById('photo-input').click()">
                    <i class="fas fa-upload"></i> Upload / Camera
                </button>
                <p class="cell-secondary" style="margin-top:0.5rem; font-size:0.72rem;">Optional photo or selfie</p>
            </div>

            <button type="submit" class="btn btn-primary w-100" style="padding:1rem; font-size:1rem; font-weight:700; margin-top:0.75rem; border-radius:12px; box-shadow: 0 4px 14px rgba(16,185,129,0.3);">
                <i class="fas fa-bolt"></i> Register &amp; Activate
            </button>
        </div>

    </div>
</form>

<script>
function updateComputedAge(dobVal) {
    const badge = document.getElementById('computed-age-badge');
    if (!dobVal) {
        badge.textContent = '';
        return;
    }
    const birthDate = new Date(dobVal);
    const today = new Date();
    if (isNaN(birthDate.getTime())) {
        badge.textContent = '';
        return;
    }
    let age = today.getFullYear() - birthDate.getFullYear();
    const m = today.getMonth() - birthDate.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
        age--;
    }
    if (age >= 0 && age <= 120) {
        badge.textContent = `(${age} yrs old)`;
    } else {
        badge.textContent = '';
    }
}

// Initialize on page load if DOB prefilled
const dobEl = document.getElementById('dob-input');
if (dobEl && dobEl.value) {
    updateComputedAge(dobEl.value);
}

document.getElementById('photo-input').onchange = function(e) {
    const [file] = this.files;
    if (file) {
        const preview = document.getElementById('photo-preview');
        preview.innerHTML = `<img src="${URL.createObjectURL(file)}" style="width:100%; height:100%; object-fit:cover;">`;
    }
};

// Auto-fill amount based on plan
const plans = <?php echo json_encode($plans); ?>;
const planSelect = document.querySelector('select[name="plan_id"]');
function syncPlanPrice() {
    const planId = planSelect.value;
    const selectedPlan = plans.find(p => p.id == planId);
    if (selectedPlan) {
        document.getElementById('amount_paid').value = selectedPlan.price;
    }
}
if (planSelect) {
    planSelect.onchange = syncPlanPrice;
    syncPlanPrice(); // run on load
}

// Toggle reference number field (optional)
document.getElementById('payment-method-select').addEventListener('change', function() {
    const val = this.value;
    const refGroup = document.getElementById('ref-number-group');
    if (refGroup) {
        if (val === 'GCash' || val === 'Maya') {
            refGroup.style.display = 'block';
        } else {
            refGroup.style.display = 'none';
        }
    }
});
</script>

<?php include 'includes/footer.php'; ?>
