<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../config/logger.php';

if (isset($_SESSION['member_id'])) {
    header('Location: index.php');
    exit;
}

// Fetch active plans for selection
$plans = [];
try {
    if (isset($pdo) && $pdo) {
        $stmt = $pdo->query("
            SELECT id, name, price, duration_months, duration_minutes, is_test_promo, benefits, plan_category 
            FROM membership_plans 
            WHERE is_active = 1 
            ORDER BY 
                CASE plan_category 
                    WHEN 'membership_fee' THEN 1 
                    WHEN 'member_pass' THEN 2 
                    WHEN 'non_member_pass' THEN 3 
                    WHEN 'test_promo' THEN 4 
                    ELSE 5 
                END, 
                price ASC
        ");
        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

$error   = '';
$success = '';
$new_membership_id = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name       = trim($_POST['first_name'] ?? '');
    $middle_name      = trim($_POST['middle_name'] ?? '');
    $last_name        = trim($_POST['last_name'] ?? '');
    $extension        = trim($_POST['extension'] ?? '');
    $full_name        = trim($_POST['full_name'] ?? '');

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

    $email            = trim($_POST['email'] ?? '');
    $contact_number   = trim($_POST['contact_number'] ?? '');
    
    // Structured Address components
    $house_street     = trim($_POST['house_street'] ?? '');
    $barangay         = trim($_POST['barangay'] ?? '');
    $municipality     = trim($_POST['municipality'] ?? '');
    $province         = trim($_POST['province'] ?? '');
    $zip_code         = trim($_POST['zip_code'] ?? '');
    $address          = compose_member_address_string($house_street, $barangay, $municipality, $province, $zip_code, trim($_POST['address'] ?? ''));

    // Date of Birth & Dynamic Age
    $dob              = trim($_POST['dob'] ?? '');
    $age              = compute_member_age($dob, !empty($_POST['age']) ? intval($_POST['age']) : null);
    $gender           = trim($_POST['gender'] ?? 'Male');
    $plan_id          = intval($_POST['plan_id'] ?? 0);
    $password         = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    $validation_errors = [];

    if (empty($first_name)) {
        $validation_errors[] = "First Name is required.";
    }
    if (empty($last_name)) {
        $validation_errors[] = "Last Name is required.";
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $validation_errors[] = "Please provide a valid email address.";
    }

    if (empty($contact_number)) {
        $validation_errors[] = "Contact number is required.";
    } elseif (!preg_match('/^09[0-9]{9}$/', $contact_number)) {
        $validation_errors[] = "Contact number must be 11 digits starting with 09 (e.g. 09123456789).";
    }

    if (empty($address) && empty($municipality)) {
        $validation_errors[] = "Home address details are required.";
    }

    if (!empty($dob)) {
        $dob_ts = strtotime($dob);
        if ($dob_ts === false || $dob_ts > time()) {
            $validation_errors[] = "Date of birth cannot be in the future.";
        }
    }
    if ($age !== null && ($age < 5 || $age > 120)) {
        $validation_errors[] = "Age must be between 5 and 120.";
    }

    if (empty($password) || strlen($password) < 6) {
        $validation_errors[] = "Password must be at least 6 characters long.";
    }

    if ($password !== $confirm_password) {
        $validation_errors[] = "Passwords do not match.";
    }

    if (empty($_POST['terms_consent'])) {
        $validation_errors[] = "You must agree to the Terms & Conditions and Privacy Policy to register.";
    }

    // Photo upload handling
    $photo_path = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        require_once __DIR__ . '/../config/uploader.php';
        $upload_result = secure_process_image_upload($_FILES['photo'], 'members', 600, 600);
        if ($upload_result['success']) {
            $photo_path = $upload_result['path'];
        } else {
            $validation_errors[] = $upload_result['error'];
        }
    }

    if (empty($validation_errors)) {
        require_once __DIR__ . '/../config/duplicate_validator.php';
        $dup_check = validate_member_uniqueness($pdo, $full_name, $email, $contact_number);
        if (!$dup_check['valid']) {
            $validation_errors = array_merge($validation_errors, $dup_check['errors']);
        }

        // Validate plan selection
        if ($plan_id <= 0) {
            $validation_errors[] = "Please select a membership plan.";
        } else {
            $p_check = $pdo->prepare("SELECT id, name, price, plan_category, is_active FROM membership_plans WHERE id = ?");
            $p_check->execute([$plan_id]);
            $plan_info = $p_check->fetch(PDO::FETCH_ASSOC);

            if (!$plan_info || (int)($plan_info['is_active'] ?? 0) !== 1) {
                $validation_errors[] = "The selected membership plan is no longer available.";
            } elseif (($plan_info['plan_category'] ?? '') === 'member_pass') {
                $validation_errors[] = "Discounted member rates are exclusively for Official Members. Please choose the Annual Membership Fee (₱1,000) to avail of member discounts or select a Non-Member pass.";
            }
        }
    }

    if (empty($validation_errors)) {
        try {
            $pdo->beginTransaction();

            $membership_id = 'GYM-' . strtoupper(substr(uniqid(), -6));
            $check_id = $pdo->prepare("SELECT id FROM members WHERE membership_id = ?");
            $check_id->execute([$membership_id]);
            while ($check_id->fetch()) {
                $membership_id = 'GYM-' . strtoupper(substr(uniqid(), -6));
                $check_id->execute([$membership_id]);
            }

            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $payment_method = trim($_POST['payment_method'] ?? 'GCash');
            $is_online_instant = in_array(strtolower($payment_method), ['gcash', 'maya', 'paymaya', 'online']);
            $initial_acc_status = $is_online_instant ? 'Approved' : 'Pending';
            $initial_status     = $is_online_instant ? 'Active' : 'Inactive';

            $stmt = $pdo->prepare("
                INSERT INTO members (
                    membership_id, first_name, middle_name, last_name, extension, full_name, email, contact_number,
                    house_street, barangay, municipality, province, zip_code, address,
                    dob, age, gender, photo, account_status, status, selected_plan_id, password_hash, approved_at, created_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($is_online_instant ? "NOW()" : "NULL") . ", NOW())
            ");
            $stmt->execute([
                $membership_id, $first_name, $middle_name ?: null, $last_name, $extension ?: null, $full_name, $email, $contact_number,
                $house_street ?: null, $barangay ?: null, $municipality ?: null, $province ?: null, $zip_code ?: null, $address ?: null,
                $dob ?: null, $age, $gender, $photo_path, $initial_acc_status, $initial_status, ($plan_id > 0 ? $plan_id : null), $password_hash
            ]);
            $member_id = (int)$pdo->lastInsertId();

            // Fetch plan price if plan selected
            $plan_price = 0.00;
            if ($plan_id > 0) {
                $p_stmt = $pdo->prepare("SELECT price FROM membership_plans WHERE id = ?");
                $p_stmt->execute([$plan_id]);
                $plan_price = floatval($p_stmt->fetchColumn() ?: 0);
            }

            $checkout_url = null;

            if ($is_online_instant && $plan_id > 0 && $plan_price > 0) {
                require_once __DIR__ . '/../config/paymongo.php';
                require_once __DIR__ . '/../config/env.php';
                PayMongoGateway::ensureSchema($pdo);

                $date_part = date('Ymd');
                $rand_part = strtoupper(bin2hex(random_bytes(3)));
                $ref_code  = "PEG-{$date_part}-{$rand_part}";

                $app_url = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://palmas-gym-4oxn.onrender.com';
                $payment_mode = get_payment_mode();
                $is_test = ($payment_mode === 'demo' || $payment_mode === 'test') ? 1 : 0;

                $gateway_tx_id = null;
                $paymongo_checkout_id = null;

                if (($payment_mode === 'live' || $payment_mode === 'test') && PayMongoGateway::isConfigured()) {
                    $p_stmt = $pdo->prepare("SELECT name FROM membership_plans WHERE id = ?");
                    $p_stmt->execute([$plan_id]);
                    $plan_name = $p_stmt->fetchColumn() ?: 'Membership Pass';

                    $gatewayResult = PayMongoGateway::createCheckoutSession([
                        'amount'         => $plan_price,
                        'currency'       => 'PHP',
                        'plan_name'      => $plan_name,
                        'description'    => "Palma's Elite Gym - {$plan_name} Registration",
                        'reference_code' => $ref_code,
                        'payment_method' => $payment_method,
                        'member' => [
                            'name'  => $full_name,
                            'email' => $email,
                            'phone' => $contact_number
                        ],
                        'success_url'    => "{$app_url}/api/check_status.php?ref={$ref_code}&status=success",
                        'cancel_url'     => "{$app_url}/api/check_status.php?ref={$ref_code}&status=cancelled"
                    ]);

                    if (!empty($gatewayResult['success']) && !empty($gatewayResult['checkout_url'])) {
                        $checkout_url         = $gatewayResult['checkout_url'];
                        $paymongo_checkout_id = $gatewayResult['session_id'] ?? null;
                    }
                }

                if (empty($checkout_url)) {
                    $checkout_url = "{$app_url}/api/demo_checkout.php?ref={$ref_code}";
                }

                // Insert pending payment transaction
                $tx_stmt = $pdo->prepare("
                    INSERT INTO payment_transactions 
                    (member_id, plan_id, reference_code, gateway_transaction_id, paymongo_checkout_id, gateway, checkout_url, payment_method, amount, currency, status, is_test, expires_at)
                    VALUES (?, ?, ?, ?, ?, 'PayMongo', ?, ?, ?, 'PHP', 'PENDING', ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
                ");
                $tx_stmt->execute([
                    $member_id,
                    $plan_id,
                    $ref_code,
                    $gateway_tx_id,
                    $paymongo_checkout_id,
                    $checkout_url,
                    $payment_method,
                    $plan_price,
                    $is_test
                ]);

                try {
                    $pdo->prepare("
                        INSERT INTO notifications (member_id, type, title, message, delivery_status, read_status, sent_at)
                        VALUES (?, 'Registration', 'New Registration Awaiting Payment', ?, 'Sent', 'Unread', NOW())
                    ")->execute([
                        $member_id,
                        "New member {$full_name} ({$membership_id}) registered with ₱" . number_format($plan_price, 2) . ". Checkout session generated ({$ref_code})."
                    ]);
                } catch (Exception $nEx) {}

            } else {
                // Cash Payment: Record initial registration payment request for front desk
                if ($plan_id > 0) {
                    try {
                        $pdo->prepare("
                            INSERT INTO renewal_requests (member_id, plan_id, payment_method, reference_no, status, notes, created_at)
                            VALUES (?, ?, 'Cash', ?, 'Pending', 'Initial Membership Registration Fee', NOW())
                        ")->execute([$member_id, $plan_id, 'REG-' . $membership_id]);
                    } catch (Exception $payEx) {}
                }

                try {
                    $pdo->prepare("
                        INSERT INTO notifications (member_id, type, title, message, delivery_status, read_status, sent_at)
                        VALUES (?, 'Registration', 'New Member Registration Awaiting Review', ?, 'Sent', 'Unread', NOW())
                    ")->execute([$member_id, "New member registration submitted by {$full_name} ({$membership_id}). Please verify cash payment at front desk."]);
                } catch (Exception $nEx) {}
            }

            $pdo->commit();

            if ($is_online_instant && !empty($checkout_url)) {
                // Redirect user to payment checkout immediately
                header('Location: ' . $checkout_url);
                exit;
            }

            $log_status = 'Pending front-desk cash collection.';
            log_activity($pdo, 'Member Registration', "New member registered: {$full_name} ({$membership_id}) via {$payment_method}. {$log_status}", 'Member');

            try {
                require_once __DIR__ . '/../config/email.php';
                $email_subject = "Registration Received - Palma's Elite Gym";
                $email_title   = "Hello, {$full_name}!";
                $email_body    = "Thank you for registering at Palma's Elite Gym! Your registration has been submitted and is currently <strong>Pending Review</strong>. Please settle your cash payment at the gym front desk upon your visit. Your Membership ID is: <strong>{$membership_id}</strong>.";
                send_email_notification($email, $email_subject, $email_title, $email_body);
            } catch (Exception $emErr) {}

            $new_membership_id  = $membership_id;
            $submitted_name     = $full_name;
            $is_auto_activated  = false;
            $used_method        = $payment_method;
            $success            = "Registration submitted successfully! Please settle cash at the gym front desk.";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Registration Error: ' . $e->getMessage());
            $error = "An error occurred while creating your account. Please try again.";
        }
    } else {
        $error = implode('<br>', $validation_errors);
    }
}

$selected_gender  = $_POST['gender'] ?? 'Male';
$fee_candidates   = array_filter($plans, fn($p) => ($p['plan_category'] ?? '') === 'membership_fee');
$default_plan_id  = !empty($fee_candidates) ? (int)reset($fee_candidates)['id'] : (int)($plans[0]['id'] ?? 0);
$selected_plan_id = intval($_POST['plan_id'] ?? $default_plan_id);
$gym_name         = htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Create Account | <?php echo $gym_name; ?></title>
<meta name="description" content="Register for a membership at <?php echo $gym_name; ?>">
<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── PALMAS DESIGN TOKENS ── */
:root{
  --c-bg:       #F4F7F5;
  --c-card:     #FFFFFF;
  --c-input:    #FAFDFA;
  --c-input-f:  #FFFFFF;
  --c-border:   #DCE5DD;
  --c-border-f: #3E8241;

  --c-p:        #3E8241;
  --c-p-mid:    #2D6A4F;
  --c-p-lt:     #52B788;
  --c-p-pale:   #EDF4EE;
  --c-p-glow:   rgba(62,130,65,.18);

  --c-gold:     #D4A942;
  --c-gold-p:   #FEF3E2;

  --c-h:        #121A14;
  --c-body:     #334337;
  --c-muted:    #617567;
  --c-faint:    #91A397;

  --c-err:      #DC2626;
  --c-err-p:    #FEE2E2;
  --c-err-b:    #FECACA;
  --c-ok:       #2E7D32;
  --c-ok-p:     #E8F5E9;

  --r-card:  22px;
  --r-input: 12px;
  --r-btn:   12px;
  --r-chip:  10px;

  --sh-card: 0 4px 24px rgba(18,26,20,.06), 0 1px 3px rgba(18,26,20,.03);
  --sh-btn:  0 4px 14px rgba(62,130,65,.28);
  --sh-fo:   0 0 0 3.5px rgba(62,130,65,.16);

  --tr: all .2s cubic-bezier(.4,0,.2,1);
}

*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{scroll-behavior:smooth}
body{
  font-family:'Inter',system-ui,sans-serif;
  background:var(--c-bg);
  color:var(--c-body);
  min-height:100vh;
  display:flex;
  flex-direction:column;
  align-items:center;
  padding:24px 16px 52px;
}
img{max-width:100%;display:block}

/* ── WRAPPER ── */
.wrap{width:100%;max-width:510px}

/* ── BRAND ── */
.brand{text-align:center;margin-bottom:18px}
.logo-ring{
  width:84px;height:84px;
  margin:0 auto 12px;
  border-radius:50%;
  background:#fff;
  border:2.5px solid #C8E6D4;
  box-shadow:0 4px 20px rgba(26,92,58,.14);
  display:flex;align-items:center;justify-content:center;
  overflow:hidden;padding:6px;
}
.logo-ring img{width:100%;height:100%;object-fit:contain}
.logo-fb{display:none;font-size:2rem;color:var(--c-p)}
.brand h1{
  font-family:'Outfit',sans-serif;
  font-size:1.65rem;font-weight:800;
  color:var(--c-h);letter-spacing:-.4px;line-height:1.2;
}
.brand .sub{font-size:.83rem;color:var(--c-muted);margin-top:3px}
.brand .tag{font-size:.79rem;color:var(--c-p-mid);margin-top:5px;font-style:italic;font-weight:500}

/* ── CARD ── */
.card{
  background:var(--c-card);
  border-radius:var(--r-card);
  padding:26px 22px;
  box-shadow:var(--sh-card);
  border:1px solid var(--c-border);
}

/* ── ALERTS ── */
.alert{
  display:flex;align-items:flex-start;gap:10px;
  padding:11px 14px;border-radius:10px;
  font-size:.875rem;line-height:1.5;margin-bottom:18px;
}
.alert i{margin-top:2px;flex-shrink:0}
.alert-err{background:var(--c-err-p);border:1px solid var(--c-err-b);color:#7F1D1D}
.alert-err i{color:var(--c-err)}
.alert-ok{background:var(--c-ok-p);border:1px solid #BBF7D0;color:#14532D}
.alert-ok i{color:var(--c-ok)}

/* ── FORM ── */
.fg{margin-bottom:16px}
.lbl{
  display:flex;align-items:center;justify-content:space-between;
  font-size:.81rem;font-weight:600;color:var(--c-h);
  margin-bottom:5px;letter-spacing:.05px;
}
.req{color:var(--c-err);margin-left:1px}
.iw{position:relative;display:flex;align-items:center}
.ii{
  position:absolute;left:12px;
  color:var(--c-faint);font-size:.88rem;
  pointer-events:none;transition:var(--tr);z-index:1;
}
.if{
  width:100%;height:50px;
  background:var(--c-input);
  border:1.5px solid var(--c-border);
  border-radius:var(--r-input);
  padding:0 14px 0 38px;
  color:var(--c-h);
  font-family:'Inter',sans-serif;font-size:.93rem;
  outline:none;transition:var(--tr);
  -webkit-appearance:none;appearance:none;
}
input[type="date"].if {
  -webkit-appearance: date-picker-field;
  appearance: auto;
}
.if::placeholder{color:var(--c-faint);font-size:.84rem}
.if:focus{
  border-color:var(--c-border-f);
  background:var(--c-input-f);
  box-shadow:var(--sh-fo);
}
.iw:focus-within .ii{color:var(--c-p-lt)}
select.if{
  padding-right:34px;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 11px center;cursor:pointer;
}
.pw-btn{
  position:absolute;right:12px;
  background:none;border:none;
  color:var(--c-faint);font-size:.88rem;
  cursor:pointer;padding:6px;border-radius:6px;
  display:flex;align-items:center;justify-content:center;
  min-width:32px;min-height:32px;transition:var(--tr);
}
.pw-btn:hover{color:var(--c-p-mid)}
.pw-btn:focus-visible{outline:2px solid var(--c-border-f);outline-offset:2px}
.if.has-pw{padding-right:44px}
.hint{font-size:.73rem;color:var(--c-faint);margin-top:4px;display:flex;align-items:center;gap:4px;min-height:18px}

/* ── 2-COL GRID (480px+) ── */
.g2{display:grid;grid-template-columns:1fr;gap:0}
@media(min-width:480px){.g2{grid-template-columns:1fr 1fr;gap:12px}}

/* ── GENDER CHIPS ── */
.chips{display:flex;gap:7px}
.chip{
  flex:1;display:flex;align-items:center;justify-content:center;gap:5px;
  height:48px;
  background:var(--c-input);border:1.5px solid var(--c-border);
  border-radius:var(--r-chip);
  color:var(--c-muted);font-size:.8rem;font-weight:600;
  cursor:pointer;transition:var(--tr);user-select:none;
}
.chip input[type=radio]{display:none}
.chip:hover{border-color:var(--c-p-lt);color:var(--c-p);background:var(--c-p-pale)}
.chip.on{border-color:var(--c-p-lt);background:var(--c-p-pale);color:var(--c-p);box-shadow:0 0 0 3px var(--c-p-glow)}
.chip i{font-size:.82rem}

/* ── PLAN CARDS ── */
.plans{display:flex;flex-direction:column;gap:7px}
.plan{
  display:flex;align-items:center;justify-content:space-between;
  padding:12px 14px;
  background:var(--c-input);border:1.5px solid var(--c-border);
  border-radius:var(--r-input);cursor:pointer;transition:var(--tr);
}
.plan input[type=radio]{display:none}
.plan:hover{border-color:var(--c-p-lt);background:var(--c-p-pale)}
.plan.on{border-color:var(--c-p-lt);background:var(--c-p-pale);box-shadow:0 0 0 3px var(--c-p-glow)}
.plan-l{display:flex;align-items:center;gap:10px}
.radio-dot{
  width:17px;height:17px;border-radius:50%;
  border:2px solid var(--c-border);
  background:#fff;flex-shrink:0;
  position:relative;transition:var(--tr);
}
.plan.on .radio-dot{border-color:var(--c-p-lt);background:var(--c-p-lt)}
.plan.on .radio-dot::after{
  content:'';position:absolute;
  width:5px;height:5px;background:#fff;
  border-radius:50%;top:50%;left:50%;transform:translate(-50%,-50%);
}
.plan-name{font-size:.86rem;font-weight:700;color:var(--c-h)}
.plan-dur{font-size:.73rem;color:var(--c-muted);margin-top:1px}
.plan-price{font-size:.98rem;font-weight:800;color:var(--c-p);font-family:'Poppins',sans-serif}

/* ── SECTION TAG ── */
.sec-tag{
  font-size:.68rem;font-weight:700;text-transform:uppercase;
  letter-spacing:.7px;color:var(--c-faint);
  display:flex;align-items:center;gap:8px;margin:4px 0 14px;
}
.sec-tag::after{content:'';flex:1;height:1px;background:var(--c-border)}

/* ── BUTTONS ── */
.btn-primary{
  width:100%;height:52px;
  background:linear-gradient(135deg,var(--c-p-lt) 0%,var(--c-p) 100%);
  border:none;border-radius:var(--r-btn);
  color:#fff;
  font-family:'Poppins',sans-serif;font-size:.96rem;font-weight:700;
  letter-spacing:.2px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:9px;
  margin-top:22px;
  box-shadow:var(--sh-btn);transition:var(--tr);
}
.btn-primary:hover:not(:disabled){
  background:linear-gradient(135deg,#46C47E 0%,var(--c-p-mid) 100%);
  transform:translateY(-1px);
  box-shadow:0 7px 22px rgba(26,92,58,.38);
}
.btn-primary:active:not(:disabled){transform:translateY(1px);box-shadow:var(--sh-btn)}
.btn-primary:disabled{opacity:.65;cursor:not-allowed;transform:none}

.btn-outline{
  width:100%;height:47px;
  background:transparent;
  border:1.5px solid var(--c-p-lt);border-radius:var(--r-btn);
  color:var(--c-p);
  font-family:'Inter',sans-serif;font-size:.875rem;font-weight:600;
  cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;
  text-decoration:none;transition:var(--tr);
}
.btn-outline:hover{background:var(--c-p-pale);border-color:var(--c-p)}

/* ── TRUST ── */
.trust{
  display:flex;align-items:center;justify-content:center;gap:6px;
  font-size:.71rem;color:var(--c-faint);margin-top:11px;
}
.trust i{color:var(--c-p-lt);font-size:.73rem}

/* ── DIVIDER ── */
.divider{
  display:flex;align-items:center;gap:11px;
  margin:20px 0;
  color:var(--c-faint);font-size:.73rem;font-weight:600;text-transform:uppercase;letter-spacing:.4px;
}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--c-border)}

/* ── SIGN-IN ROW ── */
.signin-row{text-align:center;font-size:.875rem;color:var(--c-muted)}
.signin-row a{color:var(--c-p);font-weight:700;text-decoration:none;margin-left:4px;transition:var(--tr)}
.signin-row a:hover{color:var(--c-p-mid);text-decoration:underline}

/* ── APK CARD ── */
.apk-card{
  margin-top:12px;
  background:#fff;
  border:1.5px solid var(--c-border);
  border-radius:18px;
  padding:13px 16px;
  display:flex;align-items:center;justify-content:space-between;
  text-decoration:none;
  box-shadow:0 2px 8px rgba(0,0,0,.04);
  transition:var(--tr);
}
.apk-card:hover{
  border-color:var(--c-p-lt);background:var(--c-p-pale);
  transform:translateY(-2px);
  box-shadow:0 6px 18px rgba(26,92,58,.11);
}
.apk-l{display:flex;align-items:center;gap:12px}
.apk-ico{
  width:44px;height:44px;border-radius:12px;
  background:var(--c-p-pale);border:1px solid #BBF7D0;
  display:flex;align-items:center;justify-content:center;
  color:var(--c-p);font-size:1.3rem;flex-shrink:0;
}
.apk-t{font-size:.875rem;font-weight:700;color:var(--c-h)}
.apk-s{font-size:.73rem;color:var(--c-muted);margin-top:2px}
.apk-badge{
  background:var(--c-p);color:#fff;
  font-size:.7rem;font-weight:700;
  padding:6px 12px;border-radius:20px;
  display:flex;align-items:center;gap:5px;flex-shrink:0;
}

/* ── SUCCESS ── */
.success-scr{text-align:center;padding:8px 0}
.success-ico{
  width:66px;height:66px;
  background:linear-gradient(135deg,var(--c-p-lt),var(--c-p));
  border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  margin:0 auto 16px;
  box-shadow:0 8px 24px rgba(26,92,58,.28);
  font-size:1.8rem;color:#fff;
}
.success-scr h2{font-family:'Poppins',sans-serif;font-size:1.35rem;font-weight:800;color:var(--c-h);margin-bottom:5px}
.success-scr p{font-size:.875rem;color:var(--c-muted)}
.mid-box{
  background:var(--c-p-pale);border:1.5px dashed var(--c-p-lt);
  border-radius:12px;padding:13px 18px;margin:18px 0;
}
.mid-lbl{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--c-p-mid);margin-bottom:3px}
.mid-val{font-family:'Courier New',monospace;font-size:1.45rem;font-weight:800;color:var(--c-p);letter-spacing:3px}
.mid-hint{font-size:.73rem;color:var(--c-muted);margin-top:4px}

/* ── STEP WIZARD STYLES ── */
.wizard-header {
  margin-bottom: 20px;
}
.wizard-badge {
  background: var(--c-p-pale);
  color: var(--c-p-mid);
  font-size: 0.72rem;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 20px;
  border: 1px solid rgba(62,130,65,0.2);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.wizard-steps-nav {
  display: flex;
  justify-content: space-between;
  gap: 6px;
  margin-bottom: 20px;
}
.step-pip {
  flex: 1;
  height: 38px;
  border-radius: 10px;
  background: var(--c-input);
  border: 1.5px solid var(--c-border);
  color: var(--c-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.85rem;
  transition: var(--tr);
  cursor: pointer;
  user-select: none;
}
.step-pip.active {
  background: var(--c-p-pale);
  border-color: var(--c-p-lt);
  color: var(--c-p);
  box-shadow: 0 0 0 3px var(--c-p-glow);
  font-weight: 700;
}
.step-pip.completed {
  background: #DCFCE7;
  border-color: #86EFAC;
  color: #15803D;
}
.step-intro {
  margin-bottom: 16px;
}
.step-intro h3 {
  font-size: 1.15rem;
  font-weight: 800;
  color: var(--c-h);
  margin-bottom: 3px;
}
.step-intro p {
  font-size: 0.82rem;
  color: var(--c-muted);
  line-height: 1.4;
}
.btn-row {
  display: flex;
  gap: 10px;
  margin-top: 22px;
}
.btn-row .btn-outline {
  flex: 1;
  margin-top: 0;
  height: 52px;
}
.btn-row .btn-primary {
  flex: 2;
  margin-top: 0;
}
.input-error {
  border-color: var(--c-err) !important;
  background: #FFF5F5 !important;
  box-shadow: 0 0 0 3.5px rgba(220,38,38,0.18) !important;
}
.field-err-msg {
  color: var(--c-err);
  font-size: 0.74rem;
  font-weight: 600;
  margin-top: 4px;
}
.summary-card {
  background: var(--c-p-pale);
  border: 1.5px solid #C8E6D4;
  border-radius: 14px;
  padding: 14px 16px;
  margin-bottom: 16px;
}
.summary-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 8px;
  font-size: 0.82rem;
  margin-bottom: 8px;
}
.summary-row:last-child {
  margin-bottom: 0;
}
.summary-label {
  color: var(--c-muted);
  font-weight: 500;
}
.summary-value {
  color: var(--c-h);
  font-weight: 700;
  text-align: right;
}

/* ── RESPONSIVE ── */
@media(max-width:359px){
  .card{padding:20px 14px}
  .brand h1{font-size:1.3rem}
  .chip{font-size:.74rem}
  .logo-ring{width:74px;height:74px}
}
@media(min-width:640px){
  body{padding-top:40px}
  .card{padding:34px 34px}
}
</style>
</head>
<body>
<div class="wrap">

  <!-- BRAND HEADER -->
  <header class="brand">
    <div class="logo-ring" id="logo-wrap">
      <img
        src="../assets/images/palmas-logo.png"
        alt="<?php echo $gym_name; ?> Logo"
        onerror="this.style.display='none';document.getElementById('logo-fb').style.display='flex'"
      >
      <span class="logo-fb" id="logo-fb" aria-hidden="true"><i class="fa-solid fa-dumbbell"></i></span>
    </div>
    <h1><?php echo $gym_name; ?></h1>
    <p class="sub">Membership &amp; Attendance Management</p>
    <p class="tag">&ldquo;Join us and manage your membership easily.&rdquo;</p>
  </header>

  <!-- CARD -->
  <main class="card" id="main-content">

    <?php if ($success): ?>
    <?php if (!empty($is_auto_activated)): ?>
    <!-- SUCCESS SCREEN: INSTANT ACTIVATION (GCASH / MAYA) -->
    <div class="success-scr">
      <div class="success-ico" style="background:#DCFCE7;color:#15803D;border:2px solid #86EFAC;"><i class="fa-solid fa-circle-check"></i></div>
      <h2>Membership Activated! 🎉</h2>
      <p style="color:var(--c-muted);font-size:0.95rem;margin-bottom:18px;">
        Congratulations, <strong><?php echo htmlspecialchars($submitted_name); ?></strong>! Your payment via <strong><?php echo htmlspecialchars($used_method); ?></strong> is confirmed and your gym membership is <strong>Active</strong>!
      </p>
      <div class="mid-box" style="background:#F0FDF4;border:1.5px solid #86EFAC;">
        <div class="mid-lbl" style="color:#166534;">Your Official Membership ID</div>
        <div class="mid-val" style="color:#15803D;"><?php echo htmlspecialchars($new_membership_id); ?></div>
        <div class="mid-hint" style="color:#14532D;">Use this ID with your password to sign in to your Web Portal and Mobile App.</div>
      </div>
      <div style="display:flex;flex-direction:column;gap:10px;margin-top:20px;">
        <a href="login.php" class="btn-primary" style="text-decoration:none;margin-top:0">
          <i class="fa-solid fa-bolt" aria-hidden="true"></i> Sign In to Access Your QR Pass Now
        </a>
      </div>
    </div>
    <?php else: ?>
    <!-- SUCCESS SCREEN: PENDING CASH AT FRONT DESK -->
    <div class="success-scr">
      <div class="success-ico" style="background:#FEF3C7;color:#D97706;border:2px solid #FDE68A;"><i class="fa-solid fa-clock"></i></div>
      <h2>Registration Received!</h2>
      <p style="color:var(--c-muted);font-size:0.95rem;margin-bottom:18px;">
        Thank you, <strong><?php echo htmlspecialchars($submitted_name); ?></strong>! Please settle your cash payment at the gym front desk upon your visit.
      </p>
      <div class="mid-box" style="background:#FFFBEB;border:1px solid #FDE68A;">
        <div class="mid-lbl" style="color:#92400E;">Your Membership Reference ID</div>
        <div class="mid-val" style="color:#B45309;"><?php echo htmlspecialchars($new_membership_id); ?></div>
        <div class="mid-hint" style="color:#78350F;">Save this ID &mdash; staff will confirm your cash renewal and activate your account.</div>
      </div>
      <div style="display:flex;flex-direction:column;gap:10px;margin-top:20px;">
        <a href="login.php" class="btn-primary" style="text-decoration:none;margin-top:0">
          <i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i> Back to Sign In
        </a>
      </div>
    </div>
    <?php endif; ?>

    <?php else: ?>

    <?php if ($error): ?>
    <div class="alert alert-err" role="alert" aria-live="assertive">
      <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
      <div><?php echo $error; ?></div>
    </div>
    <?php endif; ?>

    <form action="register.php" method="POST" id="reg-form" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">

      <!-- 🌟 PROGRESSIVE STEP WIZARD HEADER 🌟 -->
      <div class="wizard-header">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
          <div style="display:flex; align-items:center; gap:8px;">
            <span class="wizard-badge" id="wizard-step-tag">Step 1 of 6</span>
            <span id="wizard-title" style="font-size:0.95rem; font-weight:800; color:var(--c-h);">Basic Information</span>
          </div>
          <span id="wizard-percent" style="font-size:0.75rem; font-weight:800; color:var(--c-p-mid);">17%</span>
        </div>
        
        <!-- Progress track -->
        <div style="width:100%; height:6px; background:#e2ece5; border-radius:999px; overflow:hidden; margin-bottom:12px;">
          <div id="wizard-progress-bar" style="width:16.66%; height:100%; background:linear-gradient(90deg, #52b788, #2d6a4f); transition:width 0.3s cubic-bezier(.4,0,.2,1); border-radius:999px;"></div>
        </div>

        <!-- 6 Step pill indicators -->
        <div class="wizard-steps-nav">
          <div class="step-pip active" data-step="1" onclick="wizardGo(1)" title="Basic Info"><i class="fa-solid fa-user"></i></div>
          <div class="step-pip" data-step="2" onclick="wizardGo(2)" title="Account"><i class="fa-solid fa-lock"></i></div>
          <div class="step-pip" data-step="3" onclick="wizardGo(3)" title="Personal"><i class="fa-solid fa-id-card"></i></div>
          <div class="step-pip" data-step="4" onclick="wizardGo(4)" title="Address"><i class="fa-solid fa-map-pin"></i></div>
          <div class="step-pip" data-step="5" onclick="wizardGo(5)" title="Photo"><i class="fa-solid fa-camera"></i></div>
          <div class="step-pip" data-step="6" onclick="wizardGo(6)" title="Plan & Pay"><i class="fa-solid fa-crown"></i></div>
        </div>
      </div>

      <!-- ============================================== -->
      <!-- STEP 1: Basic Information                     -->
      <!-- ============================================== -->
      <div id="step-section-1" class="wizard-step-container">
        <div class="step-intro">
          <h3>Basic Information</h3>
          <p>Please enter your legal name as it appears on your valid government or student ID.</p>
        </div>

        <!-- First Name -->
        <div class="fg">
          <label class="lbl" for="first_name">
            First Name <span class="req" aria-hidden="true">*</span>
          </label>
          <div class="iw">
            <i class="fa-solid fa-user ii" aria-hidden="true"></i>
            <input type="text" name="first_name" id="first_name" class="if"
              placeholder="e.g. Juan"
              value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>"
              required autofocus autocomplete="given-name" aria-required="true">
          </div>
        </div>

        <!-- Last Name -->
        <div class="fg">
          <label class="lbl" for="last_name">
            Last Name <span class="req" aria-hidden="true">*</span>
          </label>
          <div class="iw">
            <i class="fa-solid fa-user ii" aria-hidden="true"></i>
            <input type="text" name="last_name" id="last_name" class="if"
              placeholder="e.g. Dela Cruz"
              value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>"
              required autocomplete="family-name" aria-required="true">
          </div>
        </div>

        <!-- Collapsible Middle Name & Suffix Toggle -->
        <?php 
          $has_middle = !empty($_POST['middle_name']) || !empty($_POST['extension']);
        ?>
        <div style="margin-top:6px; margin-bottom:14px;">
          <button type="button" id="toggle-middle-btn" onclick="toggleMiddleNameSection()" style="background:none; border:none; color:var(--c-p); font-size:0.82rem; font-weight:700; cursor:pointer; padding:4px 0; display:flex; align-items:center; gap:6px;">
            <i class="fa-solid <?php echo $has_middle ? 'fa-minus-circle' : 'fa-plus-circle'; ?>" id="toggle-middle-icon"></i>
            <span id="toggle-middle-text"><?php echo $has_middle ? 'Hide Middle Name & Suffix' : '+ Add Middle Name or Suffix (Optional)'; ?></span>
          </button>
        </div>

        <div id="middle-suffix-wrap" style="display:<?php echo $has_middle ? 'block' : 'none'; ?>; background:var(--c-p-pale); border:1px solid #C8E6D4; border-radius:12px; padding:14px; margin-bottom:16px;">
          <div class="fg" style="margin-bottom:12px;">
            <label class="lbl" for="middle_name">
              Middle Name <span style="font-size:0.75rem; color:#888;">(Optional)</span>
            </label>
            <div class="iw">
              <i class="fa-solid fa-user ii" aria-hidden="true"></i>
              <input type="text" name="middle_name" id="middle_name" class="if"
                placeholder="e.g. Santos"
                value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>"
                autocomplete="additional-name">
            </div>
          </div>

          <div class="fg" style="margin-bottom:0;">
            <label class="lbl" for="extension">
              Suffix / Extension <span style="font-size:0.75rem; color:#888;">(Optional)</span>
            </label>
            <div class="iw">
              <i class="fa-solid fa-award ii" aria-hidden="true"></i>
              <select name="extension" id="extension" class="if" style="cursor:pointer;">
                <?php $cur_ext = $_POST['extension'] ?? ''; ?>
                <option value="" <?php echo $cur_ext === '' ? 'selected' : ''; ?>>None</option>
                <option value="Jr." <?php echo $cur_ext === 'Jr.' ? 'selected' : ''; ?>>Jr.</option>
                <option value="Sr." <?php echo $cur_ext === 'Sr.' ? 'selected' : ''; ?>>Sr.</option>
                <option value="II" <?php echo $cur_ext === 'II' ? 'selected' : ''; ?>>II</option>
                <option value="III" <?php echo $cur_ext === 'III' ? 'selected' : ''; ?>>III</option>
                <option value="IV" <?php echo $cur_ext === 'IV' ? 'selected' : ''; ?>>IV</option>
                <option value="V" <?php echo $cur_ext === 'V' ? 'selected' : ''; ?>>V</option>
              </select>
            </div>
          </div>
        </div>

        <button type="button" class="btn-primary" onclick="wizardNext(1)" style="margin-top:20px;">
          Continue to Account <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
        </button>
      </div>

      <!-- ============================================== -->
      <!-- STEP 2: Account Information                   -->
      <!-- ============================================== -->
      <div id="step-section-2" class="wizard-step-container" style="display:none;">
        <div class="step-intro">
          <h3>Account Credentials</h3>
          <p>These credentials will be used to log in to your Member Mobile App and Web Portal.</p>
        </div>

        <!-- Email -->
        <div class="fg">
          <label class="lbl" for="email">
            Email Address <span class="req" aria-hidden="true">*</span>
          </label>
          <div class="iw">
            <i class="fa-solid fa-envelope ii" aria-hidden="true"></i>
            <input type="email" name="email" id="email" class="if"
              placeholder="e.g. juan@example.com"
              value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
              required autocomplete="email" aria-required="true">
          </div>
        </div>

        <!-- Contact Number -->
        <div class="fg">
          <label class="lbl" for="contact_number">
            Mobile Number <span class="req" aria-hidden="true">*</span>
          </label>
          <div class="iw">
            <i class="fa-solid fa-phone ii" aria-hidden="true"></i>
            <input type="tel" name="contact_number" id="contact_number" class="if"
              placeholder="e.g. 09123456789" maxlength="11"
              value="<?php echo htmlspecialchars($_POST['contact_number'] ?? ''); ?>"
              required autocomplete="tel" aria-required="true">
          </div>
          <div style="font-size:0.74rem; color:var(--c-muted); margin-top:4px;">11 digits starting with 09 (e.g. 09171234567)</div>
        </div>

        <!-- Passwords -->
        <div class="fg">
          <label class="lbl" for="password">
            Password <span class="req" aria-hidden="true">*</span>
          </label>
          <div class="iw">
            <i class="fa-solid fa-lock ii" aria-hidden="true"></i>
            <input type="password" name="password" id="password" class="if has-pw"
              placeholder="Minimum 6 characters"
              required minlength="6" autocomplete="new-password" aria-required="true">
            <button type="button" class="pw-btn" onclick="togglePw('password',this)" aria-label="Show or hide password">
              <i class="fa-regular fa-eye" aria-hidden="true"></i>
            </button>
          </div>
        </div>

        <div class="fg">
          <label class="lbl" for="confirm_password">
            Confirm Password <span class="req" aria-hidden="true">*</span>
          </label>
          <div class="iw">
            <i class="fa-solid fa-shield-halved ii" aria-hidden="true"></i>
            <input type="password" name="confirm_password" id="confirm_password" class="if has-pw"
              placeholder="Repeat your password"
              required minlength="6" autocomplete="new-password" aria-required="true">
            <button type="button" class="pw-btn" onclick="togglePw('confirm_password',this)" aria-label="Show or hide confirm password">
              <i class="fa-regular fa-eye" aria-hidden="true"></i>
            </button>
          </div>
          <div class="hint" id="pw-hint" aria-live="polite"></div>
        </div>

        <div class="btn-row">
          <button type="button" class="btn-outline" onclick="wizardBack(2)">
            <i class="fa-solid fa-arrow-left"></i> Back
          </button>
          <button type="button" class="btn-primary" onclick="wizardNext(2)">
            Continue to Personal <i class="fa-solid fa-arrow-right"></i>
          </button>
        </div>
      </div>

      <!-- ============================================== -->
      <!-- STEP 3: Personal Information                  -->
      <!-- ============================================== -->
      <div id="step-section-3" class="wizard-step-container" style="display:none;">
        <div class="step-intro">
          <h3>Personal Details</h3>
          <p>Used to verify eligibility, assign locker services, and tailor fitness safety standards.</p>
        </div>

        <!-- Date of Birth -->
        <div class="fg">
          <label class="lbl" for="dob">
            Date of Birth <span class="req" aria-hidden="true">*</span>
            <span id="computed-age-badge" style="font-size:0.75rem; color:var(--c-p, #3e8241); font-weight:700; margin-left:4px;"></span>
          </label>
          <div class="iw">
            <i class="fa-solid fa-cake-candles ii" aria-hidden="true"></i>
            <input type="date" name="dob" id="dob" class="if"
              max="<?php echo date('Y-m-d'); ?>"
              value="<?php echo htmlspecialchars($_POST['dob'] ?? ''); ?>"
              required oninput="updateComputedAge(this.value)">
          </div>
        </div>

        <!-- Gender Selection -->
        <div class="fg">
          <div class="lbl" id="gender-lbl">Gender <span class="req" aria-hidden="true">*</span></div>
          <div class="chips" role="radiogroup" aria-labelledby="gender-lbl">
            <label class="chip <?php echo ($selected_gender==='Male')?'on':''; ?>" aria-label="Male">
              <input type="radio" name="gender" value="Male" <?php echo ($selected_gender==='Male')?'checked':''; ?>>
              <i class="fa-solid fa-mars" aria-hidden="true"></i> Male
            </label>
            <label class="chip <?php echo ($selected_gender==='Female')?'on':''; ?>" aria-label="Female">
              <input type="radio" name="gender" value="Female" <?php echo ($selected_gender==='Female')?'checked':''; ?>>
              <i class="fa-solid fa-venus" aria-hidden="true"></i> Female
            </label>
            <label class="chip <?php echo ($selected_gender==='Other')?'on':''; ?>" aria-label="Other">
              <input type="radio" name="gender" value="Other" <?php echo ($selected_gender==='Other')?'checked':''; ?>>
              <i class="fa-solid fa-genderless" aria-hidden="true"></i> Other
            </label>
          </div>
        </div>

        <div class="btn-row">
          <button type="button" class="btn-outline" onclick="wizardBack(3)">
            <i class="fa-solid fa-arrow-left"></i> Back
          </button>
          <button type="button" class="btn-primary" onclick="wizardNext(3)">
            Continue to Address <i class="fa-solid fa-arrow-right"></i>
          </button>
        </div>
      </div>

      <!-- ============================================== -->
      <!-- STEP 4: Structured Address Details            -->
      <!-- ============================================== -->
      <div id="step-section-4" class="wizard-step-container" style="display:none;">
        <div class="step-intro">
          <h3>Home Address</h3>
          <p>Enter your primary residence for gym emergency contacts and membership records.</p>
        </div>

        <div class="fg">
          <label class="lbl" for="barangay">
            Barangay <span class="req" aria-hidden="true">*</span>
          </label>
          <div class="iw">
            <i class="fa-solid fa-map-pin ii" aria-hidden="true"></i>
            <input type="text" name="barangay" id="barangay" class="if"
              placeholder="e.g. Poblacion"
              value="<?php echo htmlspecialchars($_POST['barangay'] ?? ''); ?>" required>
          </div>
        </div>

        <div class="fg">
          <label class="lbl" for="municipality">
            Municipality / City <span class="req" aria-hidden="true">*</span>
          </label>
          <div class="iw">
            <i class="fa-solid fa-city ii" aria-hidden="true"></i>
            <input type="text" name="municipality" id="municipality" class="if"
              placeholder="e.g. Talavera"
              value="<?php echo htmlspecialchars($_POST['municipality'] ?? ''); ?>" required>
          </div>
        </div>

        <div class="fg">
          <label class="lbl" for="house_street">
            House No. / Street / Unit <span style="font-size:0.75rem; color:#888;">(Optional)</span>
          </label>
          <div class="iw">
            <i class="fa-solid fa-house ii" aria-hidden="true"></i>
            <input type="text" name="house_street" id="house_street" class="if"
              placeholder="e.g. 123 Rizal St."
              value="<?php echo htmlspecialchars($_POST['house_street'] ?? ''); ?>">
          </div>
        </div>

        <div class="g2">
          <div class="fg">
            <label class="lbl" for="province">Province</label>
            <div class="iw">
              <i class="fa-solid fa-map ii" aria-hidden="true"></i>
              <input type="text" name="province" id="province" class="if"
                placeholder="e.g. Nueva Ecija"
                value="<?php echo htmlspecialchars($_POST['province'] ?? 'Nueva Ecija'); ?>">
            </div>
          </div>
          <div class="fg">
            <label class="lbl" for="zip_code">ZIP Code <span style="font-size:0.75rem; color:#888;">(Optional)</span></label>
            <div class="iw">
              <i class="fa-solid fa-envelopes-bulk ii" aria-hidden="true"></i>
              <input type="text" name="zip_code" id="zip_code" class="if"
                placeholder="e.g. 3114" maxlength="10"
                value="<?php echo htmlspecialchars($_POST['zip_code'] ?? ''); ?>">
            </div>
          </div>
        </div>

        <div class="btn-row">
          <button type="button" class="btn-outline" onclick="wizardBack(4)">
            <i class="fa-solid fa-arrow-left"></i> Back
          </button>
          <button type="button" class="btn-primary" onclick="wizardNext(4)">
            Continue to Photo <i class="fa-solid fa-arrow-right"></i>
          </button>
        </div>
      </div>

      <!-- ============================================== -->
      <!-- STEP 5: Profile Photo                         -->
      <!-- ============================================== -->
      <div id="step-section-5" class="wizard-step-container" style="display:none;">
        <div class="step-intro" style="text-align:center;">
          <h3>Profile Photo</h3>
          <p>This photo appears on your digital QR membership pass for fast front-desk scanning.</p>
        </div>

        <div style="display:flex; flex-direction:column; align-items:center; gap:12px; margin:20px 0;">
          <!-- Preview Circle -->
          <div id="photo-preview-wrap" style="width:110px; height:110px; border-radius:50%; border:3px dashed var(--c-p-lt); display:flex; align-items:center; justify-content:center; overflow:hidden; background:var(--c-p-pale); cursor:pointer; box-shadow:0 4px 16px rgba(0,0,0,0.08);" onclick="openCamera()">
            <i class="fa-solid fa-camera" id="photo-placeholder-icon" style="font-size:2.4rem; color:var(--c-p-lt);"></i>
            <img id="photo-preview-img" src="" alt="Preview" style="display:none; width:100%; height:100%; object-fit:cover;" />
          </div>

          <!-- Hidden File Input -->
          <input type="file" name="photo" id="web-reg-photo" accept="image/*" style="display:none;" onchange="handlePhotoSelected(this)">

          <!-- Photo Action Buttons -->
          <div style="display:flex; gap:8px; width:100%; max-width:320px;">
            <button type="button" class="btn-primary" style="flex:1; margin-top:0; height:46px; font-size:0.86rem;" onclick="openCamera()">
              <i class="fa-solid fa-camera"></i> Take Selfie
            </button>
            <button type="button" class="btn-outline" style="flex:1; margin-top:0; height:46px; font-size:0.86rem;" onclick="openGallery()">
              <i class="fa-solid fa-image"></i> Gallery
            </button>
          </div>

          <button type="button" id="photo-remove-btn" onclick="clearPhoto()" style="display:none; background:none; border:none; color:#dc2626; font-size:0.8rem; font-weight:700; cursor:pointer; padding:4px 8px;">
            <i class="fa-solid fa-trash"></i> Remove Photo
          </button>
        </div>

        <!-- Security Note -->
        <div style="background:var(--c-p-pale); border:1px solid #C8E6D4; border-radius:12px; padding:12px 14px; font-size:0.78rem; color:var(--c-p-mid); line-height:1.45; margin-bottom:14px;">
          <i class="fa-solid fa-shield-halved" style="margin-right:4px;"></i>
          <strong>Entrance Security:</strong> Front desk staff verify your photo on scan to prevent account sharing and keep gym facilities secure.
        </div>

        <div class="btn-row">
          <button type="button" class="btn-outline" onclick="wizardBack(5)">
            <i class="fa-solid fa-arrow-left"></i> Back
          </button>
          <button type="button" class="btn-primary" onclick="wizardNext(5)">
            Continue to Plans <i class="fa-solid fa-arrow-right"></i>
          </button>
        </div>

        <div style="text-align:center; margin-top:14px;">
          <button type="button" onclick="wizardGo(6)" style="background:none; border:none; color:var(--c-muted); font-size:0.82rem; font-weight:600; text-decoration:underline; cursor:pointer;">
            Skip photo for now &rarr;
          </button>
        </div>
      </div>

      <!-- ============================================== -->
      <!-- STEP 6: Membership Plan & Payment             -->
      <!-- ============================================== -->
      <div id="step-section-6" class="wizard-step-container" style="display:none;">
        <div class="step-intro">
          <h3>Choose Your Plan &amp; Payment</h3>
          <p>Select your gym membership package and choose your preferred payment option.</p>
        </div>

        <?php if (!empty($plans)): ?>
        <div class="fg">
          <div class="lbl" id="plan-lbl">Membership Plan</div>

          <!-- 🌟 PROMOTIONAL UPSELL BANNER FOR NON-MEMBERS 🌟 -->
          <div class="member-upsell-banner" style="background:linear-gradient(135deg, #133e29 0%, #1f5e3e 100%); color:#fff; border-radius:14px; padding:14px 16px; margin-bottom:14px; border:1.5px solid #4ade80; box-shadow:0 4px 14px rgba(19,62,41,0.2);">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
              <div style="display:flex; align-items:center; gap:7px; font-weight:800; font-size:0.95rem; color:#fef08a;">
                <i class="fa-solid fa-crown" style="color:#facc15;"></i> Become an Official Member
              </div>
              <span style="background:rgba(250,204,21,0.2); color:#fef08a; border:1px solid #facc15; font-size:0.65rem; font-weight:800; padding:2px 8px; border-radius:12px; letter-spacing:0.5px;">
                DISCOUNTS UNLOCKED
              </span>
            </div>
            <p style="font-size:0.79rem; color:#e2e8f0; margin:6px 0 10px; line-height:1.45;">
              For just <strong>₱1,000 Annual Membership Fee</strong> (valid for 1 full year), unlock exclusive <strong>discounted rates</strong> on all passes:
            </p>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px; font-size:0.74rem; margin-bottom:12px;">
              <div style="background:rgba(255,255,255,0.08); border-radius:8px; padding:6px 8px; border:1px solid rgba(255,255,255,0.12);">
                <span style="color:#86efac; font-weight:700;">Monthly:</span> <strong>₱750</strong> <span style="text-decoration:line-through; opacity:0.6; font-size:0.68rem;">₱850</span> <span style="color:#fef08a; font-weight:800; font-size:0.68rem;">(Save ₱100/mo)</span>
              </div>
              <div style="background:rgba(255,255,255,0.08); border-radius:8px; padding:6px 8px; border:1px solid rgba(255,255,255,0.12);">
                <span style="color:#86efac; font-weight:700;">Yearly:</span> <strong>₱7,500</strong> <span style="color:#fef08a; font-weight:800; font-size:0.68rem;">(Save ₱1,500)</span>
              </div>
              <div style="background:rgba(255,255,255,0.08); border-radius:8px; padding:6px 8px; border:1px solid rgba(255,255,255,0.12);">
                <span style="color:#86efac; font-weight:700;">2nd Flr:</span> <strong>₱40</strong> <span style="text-decoration:line-through; opacity:0.6; font-size:0.68rem;">₱50</span> <span style="color:#fef08a; font-weight:800; font-size:0.68rem;">(Save ₱10)</span>
              </div>
              <div style="background:rgba(255,255,255,0.08); border-radius:8px; padding:6px 8px; border:1px solid rgba(255,255,255,0.12);">
                <span style="color:#86efac; font-weight:700;">Ground &amp; 2nd:</span> <strong>₱50</strong> <span style="text-decoration:line-through; opacity:0.6; font-size:0.68rem;">₱60</span> <span style="color:#fef08a; font-weight:800; font-size:0.68rem;">(Save ₱10)</span>
              </div>
            </div>
            <button type="button" onclick="selectAnnualMembershipPlan()" style="width:100%; background:#facc15; color:#14532d; font-weight:800; font-size:0.82rem; border:none; padding:9px 12px; border-radius:9px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px; box-shadow:0 2px 6px rgba(0,0,0,0.2);">
              <i class="fa-solid fa-sparkles"></i> Select Official Member Package (₱1,000)
            </button>
          </div>

          <div class="plans" role="radiogroup" aria-labelledby="plan-lbl" style="display:flex; flex-direction:column; gap:14px;">
            <?php 
            $sec_fee = array_filter($plans, fn($p) => ($p['plan_category'] ?? '') === 'membership_fee');
            $sec_non_member = array_filter($plans, fn($p) => ($p['plan_category'] ?? '') === 'non_member_pass');
            $sec_member = array_filter($plans, fn($p) => ($p['plan_category'] ?? '') === 'member_pass');
            $sec_promos = array_filter($plans, fn($p) => ($p['plan_category'] ?? '') === 'test_promo');

            $non_mem_monthly = null;
            $non_mem_2nd = null;
            $non_mem_both = null;
            foreach ($sec_non_member as $nm) {
                if ((int)$nm['duration_months'] === 1) $non_mem_monthly = (int)$nm['id'];
                elseif (stripos($nm['name'], '2nd Floor Only') !== false) $non_mem_2nd = (int)$nm['id'];
                elseif (stripos($nm['name'], 'Ground') !== false) $non_mem_both = (int)$nm['id'];
            }

            $reg_sections = [
                [
                    'id' => 'sec-fee', 
                    'title' => '🏅 Official Member Package (Recommended)', 
                    'desc' => 'Annual eligibility fee (₱1,000 / valid 1 year) — unlocks all member discounts below!', 
                    'items' => $sec_fee,
                    'is_member_rate' => false
                ],
                [
                    'id' => 'sec-non-member', 
                    'title' => '⚡ Non-Member Passes (Standard Rates)', 
                    'desc' => 'Standard rates for walk-ins and guests without an annual membership', 
                    'items' => $sec_non_member,
                    'is_member_rate' => false
                ],
                [
                    'id' => 'sec-member', 
                    'title' => '🔒 Member Discounted Passes (Official Members Only)', 
                    'desc' => 'Exclusive discounted prices for active ₱1,000 Annual Membership holders', 
                    'items' => $sec_member,
                    'is_member_rate' => true
                ],
            ];
            if (!empty($sec_promos)) {
                $reg_sections[] = [
                    'id' => 'sec-promos',
                    'title' => '🧪 Test & Promo Passes',
                    'desc' => 'Available for system testing and promotions',
                    'items' => $sec_promos,
                    'is_member_rate' => false
                ];
            }
            ?>
            <?php foreach ($reg_sections as $sec): if (empty($sec['items'])) continue; ?>
            <div id="<?php echo $sec['id']; ?>">
              <div style="font-size:0.8rem; font-weight:800; color:var(--c-p, #3e8241); text-transform:uppercase; letter-spacing:0.4px; margin-bottom:4px; padding-bottom:3px; border-bottom:1.5px solid #d9e6de; display:flex; justify-content:space-between; align-items:center;">
                <span><?php echo $sec['title']; ?></span>
                <?php if (!empty($sec['is_member_rate'])): ?>
                  <span style="font-size:0.65rem; background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; padding:1px 6px; border-radius:10px; text-transform:none; font-weight:700;">
                    <i class="fa-solid fa-lock"></i> Requires ₱1,000 Fee
                  </span>
                <?php endif; ?>
              </div>
              <div style="font-size:0.73rem; color:var(--c-muted); margin-bottom:6px;">
                <?php echo $sec['desc']; ?>
              </div>
              <div style="display:flex; flex-direction:column; gap:6px; margin-top:4px;">
                <?php foreach($sec['items'] as $p): 
                  $sel = ($selected_plan_id === (int)$p['id']); 
                  $is_member_pass = (($p['plan_category'] ?? '') === 'member_pass');

                  $equiv_id = 0;
                  $savings_tag = '';
                  if ($is_member_pass) {
                      if (stripos($p['name'], 'Monthly') !== false) {
                          $equiv_id = $non_mem_monthly;
                          $savings_tag = 'Save ₱100 vs standard ₱850';
                      } elseif (stripos($p['name'], 'Yearly') !== false) {
                          $savings_tag = 'Save ₱1,500 vs 12x ₱750';
                      } elseif (stripos($p['name'], '2nd Floor Only') !== false) {
                          $equiv_id = $non_mem_2nd;
                          $savings_tag = 'Save ₱10 vs standard ₱50';
                      } elseif (stripos($p['name'], 'Ground') !== false) {
                          $equiv_id = $non_mem_both;
                          $savings_tag = 'Save ₱10 vs standard ₱60';
                      }
                  }
                ?>
                <label 
                  class="plan <?php echo $sel?'on':''; ?> <?php echo $is_member_pass ? 'member-discount-card' : ''; ?>" 
                  id="plan-label-<?php echo $p['id']; ?>"
                  style="padding:12px 14px; border-radius:12px; cursor:pointer; <?php if($is_member_pass) echo 'border:1.5px dashed #86efac; background:#fafffd;'; ?>"
                  <?php if ($is_member_pass): ?>
                    onclick="handleMemberPassClick(event, <?php echo (int)$p['id']; ?>, '<?php echo addslashes($p['name']); ?>', <?php echo floatval($p['price']); ?>, <?php echo (int)$equiv_id; ?>)"
                  <?php else: ?>
                    onclick="updateSummaryReview()"
                  <?php endif; ?>
                >
                  <input type="radio" name="plan_id" value="<?php echo $p['id']; ?>" <?php echo $sel?'checked':''; ?> id="plan-radio-<?php echo $p['id']; ?>" style="<?php echo $is_member_pass ? 'pointer-events:none;' : ''; ?>">
                  <div class="plan-l">
                    <div class="radio-dot" aria-hidden="true"></div>
                    <div>
                      <div class="plan-name" style="font-weight:700; color:var(--c-h); font-size:0.92rem; display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                        <?php echo htmlspecialchars($p['name']); ?>
                        <?php if ($is_member_pass): ?>
                          <span style="font-size:0.65rem; background:#dcfce7; color:#15803d; border:1px solid #86efac; font-weight:800; padding:1px 6px; border-radius:10px;">
                            <i class="fa-solid fa-tag"></i> DISCOUNTED
                          </span>
                        <?php elseif (($p['plan_category'] ?? '') === 'membership_fee'): ?>
                          <span style="font-size:0.65rem; background:#fef08a; color:#854d0e; border:1px solid #facc15; font-weight:800; padding:1px 6px; border-radius:10px;">
                            ⭐ SULIT CHOICE
                          </span>
                        <?php endif; ?>
                      </div>
                      <div class="plan-dur" style="font-size:0.75rem; color:var(--c-muted); margin-top:2px;">
                        <i class="fa-regular fa-clock" aria-hidden="true"></i> <?php 
                          if (!empty($p['duration_minutes']) && (int)$p['duration_minutes'] === 1440) {
                              echo 'Same-Day Pass (Expires 11:59 PM today)';
                          } elseif (!empty($p['duration_minutes']) && (int)$p['duration_minutes'] > 0) {
                              echo (int)$p['duration_minutes'] . ' minute' . ((int)$p['duration_minutes'] != 1 ? 's' : '');
                          } else {
                              $d_m = max(1, (int)($p['duration_months'] ?? 1));
                              echo $d_m . ' month' . ($d_m != 1 ? 's' : '') . ' Full Access';
                          }
                        ?>
                      </div>
                      <?php if (!empty($savings_tag)): ?>
                        <div style="font-size:0.72rem; color:#15803d; font-weight:700; margin-top:3px;">
                          <i class="fa-solid fa-circle-check" style="font-size:0.68rem; margin-right:3px;"></i><?php echo $savings_tag; ?>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty($p['benefits'])): ?>
                      <div style="font-size:0.72rem; color:#406550; margin-top:4px; line-height:1.35; padding-top:3px; border-top:1px dashed #d5e4db;">
                        <i class="fa-solid fa-circle-info" style="color:var(--c-p, #3e8241); font-size:0.68rem; margin-right:3px;"></i><?php echo htmlspecialchars($p['benefits']); ?>
                      </div>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="plan-price" style="font-size:1.05rem; font-weight:800; color:var(--c-p, #1e5a3c);">&#8369;<?php echo number_format($p['price'],2); ?></div>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Payment Method Selection -->
        <div class="fg">
          <div class="lbl" id="paymethod-lbl">Payment Method</div>
          <div style="display:flex; flex-direction:column; gap:8px;">
            <!-- GCash -->
            <label class="plan on" id="reg-pm-gcash" style="cursor:pointer;" onclick="selectWebPayMethod('GCash')">
              <input type="radio" name="payment_method" value="GCash" checked style="display:none;">
              <div class="plan-l">
                <div style="width:36px;height:36px;border-radius:10px;overflow:hidden;background:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,125,254,0.3);padding:2px;flex-shrink:0;">
                  <img src="../assets/images/gcash-logo.png" alt="GCash" style="width:100%;height:100%;object-fit:contain;">
                </div>
                <div>
                  <div style="font-weight:700; font-size:0.88rem; color:var(--c-h); display:flex; align-items:center; gap:6px;">
                    GCash <span style="font-size:0.62rem; background:#dcfce7; color:#15803d; padding:1px 6px; border-radius:10px; font-weight:800;">⚡ INSTANT ACTIVATION</span>
                  </div>
                  <div style="font-size:0.72rem; color:var(--c-muted);">Direct E-Wallet Instant Activation</div>
                </div>
              </div>
              <div class="radio-dot" id="dot-gcash"></div>
            </label>

            <!-- Maya -->
            <label class="plan" id="reg-pm-maya" style="cursor:pointer;" onclick="selectWebPayMethod('Maya')">
              <input type="radio" name="payment_method" value="Maya" style="display:none;">
              <div class="plan-l">
                <div style="width:36px;height:36px;border-radius:10px;overflow:hidden;background:#000;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,214,100,0.2);padding:3px;flex-shrink:0;">
                  <img src="../assets/images/maya-logo.png" alt="Maya" style="width:100%;height:100%;object-fit:contain;">
                </div>
                <div>
                  <div style="font-weight:700; font-size:0.88rem; color:var(--c-h); display:flex; align-items:center; gap:6px;">
                    Maya <span style="font-size:0.62rem; background:#dcfce7; color:#15803d; padding:1px 6px; border-radius:10px; font-weight:800;">⚡ INSTANT ACTIVATION</span>
                  </div>
                  <div style="font-size:0.72rem; color:var(--c-muted);">Direct E-Wallet Instant Activation</div>
                </div>
              </div>
              <div class="radio-dot" id="dot-maya"></div>
            </label>

            <!-- Cash -->
            <label class="plan" id="reg-pm-cash" style="cursor:pointer;" onclick="selectWebPayMethod('Cash')">
              <input type="radio" name="payment_method" value="Cash" style="display:none;">
              <div class="plan-l">
                <div style="width:36px;height:36px;border-radius:10px;background:rgba(62,130,65,0.12);color:#2d6a4f;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;">
                  <i class="fa-solid fa-money-bill-wave"></i>
                </div>
                <div>
                  <div style="font-weight:700; font-size:0.88rem; color:var(--c-h);">Cash (Front Desk)</div>
                  <div style="font-size:0.72rem; color:var(--c-muted);">Pay over the counter upon your visit</div>
                </div>
              </div>
              <div class="radio-dot" id="dot-cash"></div>
            </label>
          </div>
        </div>

        <!-- 🌟 LIVE APPLICANT & PLAN REVIEW SUMMARY CARD 🌟 -->
        <div class="summary-card">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; padding-bottom:6px; border-bottom:1px solid #C8E6D4;">
            <span style="font-size:0.75rem; font-weight:800; text-transform:uppercase; color:var(--c-p-mid); letter-spacing:0.5px;">
              <i class="fa-solid fa-clipboard-check" style="margin-right:4px;"></i> Registration Summary
            </span>
            <span id="rv-plan-price" style="font-size:1.1rem; font-weight:900; color:var(--c-p);">₱0.00</span>
          </div>

          <div class="summary-row">
            <span class="summary-label">Full Name:</span>
            <span class="summary-value" id="rv-name">—</span>
          </div>
          <div class="summary-row">
            <span class="summary-label">Contact / Email:</span>
            <span class="summary-value" id="rv-contact">—</span>
          </div>
          <div class="summary-row">
            <span class="summary-label">DOB &amp; Gender:</span>
            <span class="summary-value" id="rv-dob">—</span>
          </div>
          <div class="summary-row">
            <span class="summary-label">Address:</span>
            <span class="summary-value" id="rv-address">—</span>
          </div>
          <div class="summary-row">
            <span class="summary-label">Selected Plan:</span>
            <span class="summary-value" id="rv-plan-name">—</span>
          </div>
          <div class="summary-row">
            <span class="summary-label">Payment Method:</span>
            <span class="summary-value" id="rv-pay-method" style="color:var(--c-p); font-weight:800;">GCash</span>
          </div>
        </div>

        <!-- Terms & Privacy Consent -->
        <div class="fg" style="margin-top:0.5rem; margin-bottom:1.25rem;">
          <label for="terms_consent" style="display:flex; align-items:flex-start; gap:0.65rem; font-size:0.84rem; color:var(--c-muted); cursor:pointer; line-height:1.45;">
            <input type="checkbox" name="terms_consent" id="terms_consent" value="1" required style="width:17px; height:17px; margin-top:2px; accent-color:var(--c-p, #3e8241); cursor:pointer; flex-shrink:0;" <?php echo !empty($_POST['terms_consent']) ? 'checked' : ''; ?>>
            <span>I agree to the <a href="terms.php" target="_blank" style="color:var(--c-p, #3e8241); font-weight:600; text-decoration:underline;">Terms &amp; Conditions</a> and consent to the collection and processing of my personal data pursuant to the <a href="privacy.php" target="_blank" style="color:var(--c-p, #3e8241); font-weight:600; text-decoration:underline;">Privacy Policy</a>.</span>
          </label>
        </div>

        <div class="btn-row">
          <button type="button" class="btn-outline" onclick="wizardBack(6)">
            <i class="fa-solid fa-arrow-left"></i> Back
          </button>
          <button type="submit" class="btn-primary" id="sub-btn">
            <i class="fa-solid fa-user-plus" aria-hidden="true"></i> Complete Registration
          </button>
        </div>

        <div class="trust">
          <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
          Your account information is securely protected.
        </div>
      </div>
    </form>

    <div class="divider">Already have an account?</div>
    <div class="signin-row">
      <a href="login.php">Sign In to Member Portal &rarr;</a>
    </div>

    <?php endif; ?>
  </main><!-- /.card -->

  <!-- APK DOWNLOAD -->
  <a href="../download.php" class="apk-card" aria-label="Download Android app">
    <div class="apk-l">
      <div class="apk-ico" aria-hidden="true"><i class="fa-brands fa-android"></i></div>
      <div>
        <div class="apk-t">Download Member App</div>
        <div class="apk-s">Android APK &bull; Fast QR check-in</div>
      </div>
    </div>
    <div class="apk-badge"><i class="fa-solid fa-download" aria-hidden="true"></i> Get APK</div>
  </a>

</div><!-- /.wrap -->

<script>
let currentStep = 1;
const totalSteps = 6;

const stepTitles = {
  1: "Basic Information",
  2: "Account Credentials",
  3: "Personal Details",
  4: "Home Address",
  5: "Profile Photo",
  6: "Plan & Payment"
};

function wizardGo(step) {
  if (step < 1 || step > totalSteps) return;
  
  // If moving forward beyond current step, validate current step first
  if (step > currentStep && !validateStep(currentStep)) {
    return;
  }
  
  clearStepErrors();

  // Hide all step sections
  for (let i = 1; i <= totalSteps; i++) {
    const el = document.getElementById('step-section-' + i);
    if (el) el.style.display = (i === step) ? 'block' : 'none';
  }
  
  currentStep = step;
  
  // Update header text
  const stepTag = document.getElementById('wizard-step-tag');
  if (stepTag) stepTag.textContent = `Step ${step} of ${totalSteps}`;
  
  const titleEl = document.getElementById('wizard-title');
  if (titleEl) titleEl.textContent = stepTitles[step] || '';
  
  const pctEl = document.getElementById('wizard-percent');
  const pct = Math.round((step / totalSteps) * 100);
  if (pctEl) pctEl.textContent = `${pct}%`;
  
  const bar = document.getElementById('wizard-progress-bar');
  if (bar) bar.style.width = `${(step / totalSteps) * 100}%`;
  
  // Update pip icons
  document.querySelectorAll('.step-pip').forEach(pip => {
    const pStep = parseInt(pip.getAttribute('data-step') || '0', 10);
    pip.classList.toggle('active', pStep === step);
    pip.classList.toggle('completed', pStep < step);
  });
  
  // If moving to step 6, update summary review card
  if (step === 6) {
    updateSummaryReview();
  }
  
  // Smoothly scroll card into view
  const card = document.getElementById('main-content');
  if (card) {
    const rect = card.getBoundingClientRect();
    if (rect.top < 0) {
      card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }
}

function wizardNext(fromStep) {
  if (!validateStep(fromStep)) return;
  wizardGo(fromStep + 1);
}

function wizardBack(fromStep) {
  wizardGo(fromStep - 1);
}

function showStepError(inputEl, message) {
  if (!inputEl) return;
  inputEl.classList.add('input-error');
  const parent = inputEl.closest('.fg') || inputEl.parentElement;
  if (parent) {
    let errEl = parent.querySelector('.field-err-msg');
    if (!errEl) {
      errEl = document.createElement('div');
      errEl.className = 'field-err-msg';
      parent.appendChild(errEl);
    }
    errEl.innerHTML = `<i class="fa-solid fa-circle-exclamation"></i> ${escapeHtml(message)}`;
  }
  inputEl.focus();
}

function clearStepErrors() {
  document.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));
  document.querySelectorAll('.field-err-msg').forEach(el => el.remove());
}

function validateStep(step) {
  clearStepErrors();
  
  if (step === 1) {
    const fn = document.getElementById('first_name');
    const ln = document.getElementById('last_name');
    if (!fn || !fn.value.trim()) {
      showStepError(fn, 'Please enter your first name.');
      return false;
    }
    if (!ln || !ln.value.trim()) {
      showStepError(ln, 'Please enter your last name.');
      return false;
    }
    return true;
  }
  
  if (step === 2) {
    const email = document.getElementById('email');
    const phone = document.getElementById('contact_number');
    const pw = document.getElementById('password');
    const cpw = document.getElementById('confirm_password');
    
    if (!email || !email.value.trim()) {
      showStepError(email, 'Please enter your email address.');
      return false;
    }
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email.value.trim())) {
      showStepError(email, 'Please enter a valid email address (e.g. name@example.com).');
      return false;
    }
    
    if (!phone || !phone.value.trim()) {
      showStepError(phone, 'Please enter your mobile contact number.');
      return false;
    }
    const phoneVal = phone.value.trim().replace(/\s+/g, '');
    if (!/^09[0-9]{9}$/.test(phoneVal)) {
      showStepError(phone, 'Contact number must be 11 digits starting with 09 (e.g. 09123456789).');
      return false;
    }
    
    if (!pw || pw.value.length < 6) {
      showStepError(pw, 'Password must be at least 6 characters long.');
      return false;
    }
    
    if (!cpw || cpw.value !== pw.value) {
      showStepError(cpw, 'Passwords do not match.');
      return false;
    }
    return true;
  }
  
  if (step === 3) {
    const dob = document.getElementById('dob');
    if (!dob || !dob.value) {
      showStepError(dob, 'Please select your date of birth.');
      return false;
    }
    const birthDate = new Date(dob.value);
    const today = new Date();
    if (birthDate > today) {
      showStepError(dob, 'Date of birth cannot be in the future.');
      return false;
    }
    return true;
  }
  
  if (step === 4) {
    const brgy = document.getElementById('barangay');
    const muni = document.getElementById('municipality');
    if (!brgy || !brgy.value.trim()) {
      showStepError(brgy, 'Please enter your Barangay.');
      return false;
    }
    if (!muni || !muni.value.trim()) {
      showStepError(muni, 'Please enter your Municipality / City.');
      return false;
    }
    return true;
  }
  
  if (step === 5) {
    return true; // Photo is optional
  }
  
  if (step === 6) {
    const selectedPlan = document.querySelector('input[name="plan_id"]:checked');
    if (!selectedPlan || !selectedPlan.value) {
      alert('Please select a membership plan to continue.');
      return false;
    }
    const consent = document.getElementById('terms_consent');
    if (!consent || !consent.checked) {
      alert('Please accept the Terms & Conditions and Privacy Policy to complete registration.');
      if (consent) consent.focus();
      return false;
    }
    return true;
  }
  
  return true;
}

function toggleMiddleNameSection() {
  const wrap = document.getElementById('middle-suffix-wrap');
  const icon = document.getElementById('toggle-middle-icon');
  const text = document.getElementById('toggle-middle-text');
  if (!wrap) return;
  const isHidden = (wrap.style.display === 'none' || wrap.style.display === '');
  wrap.style.display = isHidden ? 'block' : 'none';
  if (icon) {
    icon.classList.toggle('fa-plus-circle', !isHidden);
    icon.classList.toggle('fa-minus-circle', isHidden);
  }
  if (text) {
    text.textContent = isHidden ? 'Hide Middle Name & Suffix' : '+ Add Middle Name or Suffix (Optional)';
  }
}

function openCamera() {
  const fileInput = document.getElementById('web-reg-photo');
  if (fileInput) {
    fileInput.setAttribute('capture', 'user');
    fileInput.click();
  }
}

function openGallery() {
  const fileInput = document.getElementById('web-reg-photo');
  if (fileInput) {
    fileInput.removeAttribute('capture');
    fileInput.click();
  }
}

function handlePhotoSelected(input) {
  if (input && input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = function(e) {
      const preview = document.getElementById('photo-preview-img');
      const icon = document.getElementById('photo-placeholder-icon');
      const rmBtn = document.getElementById('photo-remove-btn');
      if (preview) {
        preview.src = e.target.result;
        preview.style.display = 'block';
      }
      if (icon) icon.style.display = 'none';
      if (rmBtn) rmBtn.style.display = 'inline-block';
    };
    reader.readAsDataURL(input.files[0]);
  }
}

function clearPhoto() {
  const fileInput = document.getElementById('web-reg-photo');
  if (fileInput) fileInput.value = '';
  const preview = document.getElementById('photo-preview-img');
  const icon = document.getElementById('photo-placeholder-icon');
  const rmBtn = document.getElementById('photo-remove-btn');
  if (preview) {
    preview.src = '';
    preview.style.display = 'none';
  }
  if (icon) icon.style.display = 'block';
  if (rmBtn) rmBtn.style.display = 'none';
}

function selectWebPayMethod(m) {
  ['gcash','maya','cash'].forEach(k => {
    const card = document.getElementById('reg-pm-' + k);
    const radio = card ? card.querySelector('input[type=radio]') : null;
    const isSel = (k.toLowerCase() === m.toLowerCase());
    if (card) card.classList.toggle('on', isSel);
    if (radio) radio.checked = isSel;
  });
  updateSummaryReview();
}

function updateSummaryReview() {
  const fn = (document.getElementById('first_name')?.value || '').trim();
  const mn = (document.getElementById('middle_name')?.value || '').trim();
  const ln = (document.getElementById('last_name')?.value || '').trim();
  const ext = (document.getElementById('extension')?.value || '').trim();
  
  let fullName = [fn, mn, ln, ext].filter(Boolean).join(' ');
  if (!fullName) fullName = '—';
  
  const email = (document.getElementById('email')?.value || '').trim();
  const phone = (document.getElementById('contact_number')?.value || '').trim();
  const dob = (document.getElementById('dob')?.value || '').trim();
  
  const genderEl = document.querySelector('input[name="gender"]:checked');
  const gender = genderEl ? genderEl.value : '—';
  
  const brgy = (document.getElementById('barangay')?.value || '').trim();
  const muni = (document.getElementById('municipality')?.value || '').trim();
  const prov = (document.getElementById('province')?.value || '').trim();
  const address = [brgy, muni, prov].filter(Boolean).join(', ') || '—';
  
  const selPlanRadio = document.querySelector('input[name="plan_id"]:checked');
  let planName = '—';
  let planPrice = '₱0.00';
  if (selPlanRadio) {
    const planCard = selPlanRadio.closest('.plan');
    if (planCard) {
      planName = planCard.querySelector('.plan-name')?.childNodes[0]?.textContent?.trim() || 'Selected Plan';
      planPrice = planCard.querySelector('.plan-price')?.textContent?.trim() || '₱0.00';
    }
  }
  
  const selPayRadio = document.querySelector('input[name="payment_method"]:checked');
  const payMethod = selPayRadio ? selPayRadio.value : 'GCash';
  
  // Populate Review Card Elements
  const rvName = document.getElementById('rv-name');
  if (rvName) rvName.textContent = fullName;
  
  const rvContact = document.getElementById('rv-contact');
  if (rvContact) rvContact.textContent = [phone, email].filter(Boolean).join(' • ') || '—';
  
  const rvDob = document.getElementById('rv-dob');
  if (rvDob) rvDob.textContent = dob ? `${dob} (${gender})` : gender;
  
  const rvAddr = document.getElementById('rv-address');
  if (rvAddr) rvAddr.textContent = address;
  
  const rvPlanName = document.getElementById('rv-plan-name');
  if (rvPlanName) rvPlanName.textContent = planName;
  
  const rvPlanPrice = document.getElementById('rv-plan-price');
  if (rvPlanPrice) rvPlanPrice.textContent = planPrice;
  
  const rvPayMethod = document.getElementById('rv-pay-method');
  if (rvPayMethod) rvPayMethod.textContent = payMethod;
  
  const subBtn = document.getElementById('sub-btn');
  if (subBtn) {
    if (payMethod.toLowerCase() === 'cash') {
      subBtn.innerHTML = '<i class="fa-solid fa-user-plus" aria-hidden="true"></i> Submit Cash Registration';
    } else {
      subBtn.innerHTML = '<i class="fa-solid fa-bolt" aria-hidden="true"></i> Proceed to ' + payMethod + ' Payment (' + planPrice + ')';
    }
  }
}

function togglePw(id, btn){
  const inp=document.getElementById(id);
  const ic=btn.querySelector('i');
  const hide=inp.type==='password';
  inp.type=hide?'text':'password';
  ic.classList.toggle('fa-eye',!hide);
  ic.classList.toggle('fa-eye-slash',hide);
  btn.setAttribute('aria-pressed',String(hide));
}

/* Gender chips */
document.querySelectorAll('.chip').forEach(c=>{
  c.addEventListener('change',()=>{
    document.querySelectorAll('.chip').forEach(x=>x.classList.remove('on'));
    c.classList.add('on');
    updateSummaryReview();
  });
});

/* Plan cards */
document.querySelectorAll('.plan').forEach(p=>{
  p.addEventListener('change',()=>{
    document.querySelectorAll('.plan').forEach(x=>x.classList.remove('on'));
    p.classList.add('on');
    updateSummaryReview();
  });
});

/* Live pw match */
const pw1=document.getElementById('password');
const pw2=document.getElementById('confirm_password');
const hint=document.getElementById('pw-hint');
function chkMatch(){
  if(!pw2||!pw2.value){if(hint)hint.innerHTML='';return;}
  if(pw1.value===pw2.value){
    hint.innerHTML='<i class="fa-solid fa-circle-check" style="color:#16A34A" aria-hidden="true"></i> <span style="color:#16A34A">Passwords match</span>';
  }else{
    hint.innerHTML='<i class="fa-solid fa-circle-xmark" style="color:#DC2626" aria-hidden="true"></i> <span style="color:#DC2626">Passwords do not match</span>';
  }
}
pw1&&pw1.addEventListener('input',chkMatch);
pw2&&pw2.addEventListener('input',chkMatch);

function updateComputedAge(dobVal) {
  const badge = document.getElementById('computed-age-badge');
  if (!badge) return;
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
  updateSummaryReview();
}

// Initialize on page load
const dobEl = document.getElementById('dob');
if (dobEl && dobEl.value) {
  updateComputedAge(dobEl.value);
}

/* Submit guard */
document.getElementById('reg-form')&&document.getElementById('reg-form').addEventListener('submit',function(e){
  if (!validateStep(6)) {
    e.preventDefault();
    return;
  }
  if(pw1 && pw2 && pw1.value!==pw2.value){
    e.preventDefault();
    wizardGo(2);
    pw2.focus();
    return;
  }
  const b=document.getElementById('sub-btn');
  if (b) {
    b.innerHTML='<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Processing Account\u2026';
    b.disabled=true;
  }
});

/* ── INTERACTIVE MEMBERSHIP UPSELL LOGIC ── */
let pendingEquivNonMemberId = null;

function selectAnnualMembershipPlan() {
  const feeRadio = document.querySelector('input[name="plan_id"][value="8"]') || document.querySelector('#sec-fee input[type="radio"]');
  if (feeRadio) {
    feeRadio.checked = true;
    document.querySelectorAll('.plan').forEach(x => x.classList.remove('on'));
    const parentLabel = feeRadio.closest('.plan');
    if (parentLabel) {
      parentLabel.classList.add('on');
    }
  }
  updateSummaryReview();
}

function handleMemberPassClick(e, planId, planName, planPrice, equivId) {
  e.preventDefault();
  e.stopPropagation();
  
  pendingEquivNonMemberId = equivId;
  const modal = document.getElementById('member-prompt-modal');
  const desc = document.getElementById('member-prompt-desc');
  const btnNonMember = document.getElementById('btn-switch-nonmember');
  
  if (desc) {
    desc.innerHTML = `<strong>${escapeHtml(planName)}</strong> (₱${parseFloat(planPrice).toFixed(2)}) is a discounted rate exclusively for <strong>Official Members</strong>.<br><br>Would you like to become an Official Member today for <strong>₱1,000 Annual Membership Fee</strong> (valid 1 year) to unlock these rates?`;
  }
  
  if (btnNonMember) {
    if (equivId && equivId > 0) {
      btnNonMember.style.display = 'flex';
    } else {
      btnNonMember.style.display = 'none';
    }
  }
  
  if (modal) modal.style.display = 'flex';
}

function confirmAvailAnnualFee() {
  closeMemberPromptModal();
  selectAnnualMembershipPlan();
}

function switchToNonMemberEquivalent() {
  closeMemberPromptModal();
  if (pendingEquivNonMemberId) {
    const equivRadio = document.querySelector(`input[name="plan_id"][value="${pendingEquivNonMemberId}"]`);
    if (equivRadio) {
      equivRadio.checked = true;
      document.querySelectorAll('.plan').forEach(x => x.classList.remove('on'));
      const parentLabel = equivRadio.closest('.plan');
      if (parentLabel) {
        parentLabel.classList.add('on');
      }
    }
  }
  updateSummaryReview();
}

function closeMemberPromptModal() {
  const modal = document.getElementById('member-prompt-modal');
  if (modal) modal.style.display = 'none';
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

<!-- 🌟 INTERACTIVE UPSELL PROMPT MODAL 🌟 -->
<div id="member-prompt-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:9999; align-items:center; justify-content:center; padding:16px; backdrop-filter:blur(3px);">
  <div style="background:#fff; border-radius:20px; max-width:420px; width:100%; padding:24px 22px; box-shadow:0 24px 48px rgba(0,0,0,0.25); text-align:center; animation:fadeIn 0.2s ease-out;">
    <div style="width:60px; height:60px; border-radius:50%; background:#fef9c3; color:#ca8a04; display:flex; align-items:center; justify-content:center; font-size:1.7rem; margin:0 auto 14px; border:2px solid #facc15;">
      <i class="fa-solid fa-crown"></i>
    </div>
    <h3 style="font-size:1.2rem; font-weight:800; color:#1e293b; margin:0 0 8px;">Become an Official Member</h3>
    <p id="member-prompt-desc" style="font-size:0.86rem; color:#475569; line-height:1.5; margin:0 0 18px;">
      The plan you selected has an <strong>Exclusive Member Discount</strong>. An active <strong>₱1,000 Annual Membership Fee</strong> (valid for 1 year) is required to unlock this rate!
    </p>
    <div style="display:flex; flex-direction:column; gap:9px;">
      <button type="button" onclick="confirmAvailAnnualFee()" style="background:linear-gradient(135deg, #15803d, #166534); color:#fff; border:none; padding:12px 18px; border-radius:12px; font-weight:800; font-size:0.9rem; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; box-shadow:0 4px 12px rgba(22,101,52,0.25);">
        <i class="fa-solid fa-sparkles" style="color:#fde047;"></i> Avail Annual Membership (₱1,000)
      </button>
      <button type="button" id="btn-switch-nonmember" onclick="switchToNonMemberEquivalent()" style="background:#f8fafc; color:#334155; border:1.5px solid #cbd5e1; padding:11px 18px; border-radius:12px; font-weight:700; font-size:0.86rem; cursor:pointer; display:none; align-items:center; justify-content:center; gap:6px;">
        <i class="fa-solid fa-arrow-right-arrow-left"></i> Select Standard Non-Member Rate
      </button>
      <button type="button" onclick="closeMemberPromptModal()" style="background:transparent; color:#64748b; border:none; padding:8px; font-size:0.82rem; font-weight:600; cursor:pointer;">
        Return to Selection
      </button>
    </div>
  </div>
</div>
</body>
</html>
