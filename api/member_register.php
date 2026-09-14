<?php
/**
 * api/member_register.php
 * ─────────────────────────────────────────────────────────────────
 * Handles new member registration from the mobile app.
 *
 * Supports two registration modes:
 *  1. Google Auth (auth_provider = 'google') — no password required.
 *     Requires: google_id, full_name, email, contact_number, gender, plan_id
 *  2. Traditional (auth_provider = 'password') — password required.
 *     Requires: full_name, email, password, contact_number, gender
 *
 * After successful registration:
 *  - account_status = 'Pending'
 *  - status = 'Inactive'
 *  - No auth token issued (member must wait for staff approval)
 *  - Activity log created
 *  - Email notification sent (non-blocking)
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/cors.php'; // [R-02 FIX] Replaced wildcard CORS with origin-allowlist

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/logger.php';
require_once __DIR__ . '/../config/duplicate_validator.php';
require_once __DIR__ . '/../config/uploader.php';
require_once __DIR__ . '/../config/payment.php';

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

// ── Field Extraction ──────────────────────────────────────────────────────────
$first_name     = trim($data['first_name'] ?? '');
$middle_name    = trim($data['middle_name'] ?? '');
$last_name      = trim($data['last_name'] ?? '');
$extension      = trim($data['extension'] ?? '');
$full_name      = trim($data['full_name'] ?? '');

// Auto-derive full_name if first & last provided
if (!empty($first_name) || !empty($last_name)) {
    $name_parts = array_filter([$first_name, $middle_name, $last_name, $extension]);
    $full_name  = trim(implode(' ', $name_parts));
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

$email          = trim($data['email'] ?? '');
$password       = $data['password'] ?? '';
$contact_number = trim($data['contact_number'] ?? '');
$gender         = $data['gender'] ?? 'Male';
$plan_id        = intval($data['plan_id'] ?? 1);
$auth_provider  = $data['auth_provider'] ?? 'password';
$google_id      = trim($data['google_id'] ?? '');
$google_picture = trim($data['google_picture'] ?? '');

// ── Validation ────────────────────────────────────────────────────────────────
if (empty($full_name)) {
    echo json_encode(['success' => false, 'message' => 'Please enter your full name.']);
    exit;
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid email address.']);
    exit;
}

// Contact number validation (only validate if provided)
if (!empty($contact_number) && !preg_match('/^09[0-9]{9}$/', $contact_number)) {
    echo json_encode(['success' => false, 'message' => 'Contact number must be 11 digits starting with 09 (e.g. 09171234567).']);
    exit;
}

// Password required only for traditional registration
$password_hash = null;
if ($auth_provider !== 'google') {
    if (empty($password) || strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
        exit;
    }
    $password_hash = password_hash($password, PASSWORD_BCRYPT);
}

$valid_genders = ['Male', 'Female', 'Other'];
if (!in_array($gender, $valid_genders)) {
    $gender = 'Other';
}

// ── Profile Photo Handling ────────────────────────────────────────────────────
$photo_path = null;
$base64_photo = $data['photo_base64'] ?? $data['photo'] ?? '';
if (!empty($base64_photo) && is_string($base64_photo) && str_starts_with($base64_photo, 'data:image')) {
    $upRes = secure_process_base64_image_upload($base64_photo, 'members', 600, 600);
    if (!empty($upRes['success']) && !empty($upRes['path'])) {
        $photo_path = $upRes['path'];
    } elseif (preg_match('/^data:image\/(jpeg|png|webp|jpg);base64,/i', $base64_photo) && strlen($base64_photo) <= 2 * 1024 * 1024) {
        $photo_path = $base64_photo;
    } else {
        error_log("Photo upload warning during registration: " . ($upRes['error'] ?? 'Unknown error'));
    }
}
if (!$photo_path && isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    $upRes = secure_process_image_upload($_FILES['photo'], 'members', 600, 600);
    if (!empty($upRes['success']) && !empty($upRes['path'])) {
        $photo_path = $upRes['path'];
    }
}
if (!$photo_path && !empty($google_picture)) {
    $photo_path = $google_picture;
}

// ── Duplicate Detection ───────────────────────────────────────────────────────
if (!empty($google_id)) {
    $gid_check = $pdo->prepare("SELECT id FROM members WHERE google_id = ? LIMIT 1");
    $gid_check->execute([$google_id]);
    if ($gid_check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'This Google account is already registered. Please sign in instead.']);
        exit;
    }
}

$dup_check = validate_member_uniqueness($pdo, $full_name, $email, $contact_number);
if (!$dup_check['valid']) {
    echo json_encode(['success' => false, 'message' => implode(' ', $dup_check['errors'])]);
    exit;
}

// ── Create Member ─────────────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();

    // Generate unique Membership ID
    do {
        $membership_id = 'GYM-' . strtoupper(substr(uniqid(), -6));
        $id_exists = $pdo->prepare("SELECT id FROM members WHERE membership_id = ?");
        $id_exists->execute([$membership_id]);
    } while ($id_exists->fetch());

    require_once __DIR__ . '/../config/paymongo.php';
    PayMongoGateway::ensureSchema($pdo);

    // Initial account status is Pending until payment is confirmed
    $payment_method     = trim($data['payment_method'] ?? 'Cash');
    $is_online_payment  = in_array(strtolower($payment_method), ['gcash', 'maya', 'paymaya', 'online']);
    $initial_acc_status = 'Pending';
    $initial_status     = 'Inactive';

    $stmt = $pdo->prepare("
        INSERT INTO members 
            (membership_id, first_name, middle_name, last_name, extension, full_name, email, contact_number, gender, photo, google_id, google_picture,
             auth_provider, account_status, status, selected_plan_id, password_hash, approved_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NOW())
    ");
    $stmt->execute([
        $membership_id,
        $first_name ?: null,
        $middle_name ?: null,
        $last_name ?: null,
        $extension ?: null,
        $full_name,
        $email,
        $contact_number ?: null,
        $gender,
        $photo_path ?: null,
        $google_id ?: null,
        $google_picture ?: null,
        $auth_provider,
        $initial_acc_status,
        $initial_status,
        ($plan_id > 0) ? $plan_id : null,
        $password_hash
    ]);
    $member_id = (int)$pdo->lastInsertId();

    // Fetch plan details if selected
    $plan_name  = 'Standard';
    $plan_price = 0.00;
    if ($plan_id > 0) {
        $p_fetch = $pdo->prepare("SELECT name, price, duration_months, duration_minutes, is_test_promo FROM membership_plans WHERE id = ?");
        $p_fetch->execute([$plan_id]);
        $p_row = $p_fetch->fetch(PDO::FETCH_ASSOC);
        if ($p_row) {
            $plan_name  = $p_row['name'];
            $plan_price = floatval($p_row['price']);
        }
    }

    $auth_token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO auth_tokens (member_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))")
        ->execute([$member_id, $auth_token]);

    $checkout_url = null;
    $ref_code     = null;

    if ($is_online_payment && $plan_id > 0 && $plan_price > 0) {
        // ── CREATE ONLINE PAYMENT TRANSACTION & CHECKOUT SESSION ──────
        $date_part = date('Ymd');
        $rand_part = strtoupper(bin2hex(random_bytes(3)));
        $ref_code  = "PEG-{$date_part}-{$rand_part}";

        $app_url = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://palmas-gym-4oxn.onrender.com';
        $payment_mode = get_payment_mode();
        $is_test = ($payment_mode === 'demo' || $payment_mode === 'test' || !empty($p_row['is_test_promo'])) ? 1 : 0;

        $gateway_tx_id = null;
        $paymongo_checkout_id = null;

        if (($payment_mode === 'live' || $payment_mode === 'test') && PayMongoGateway::isConfigured()) {
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
                "New member {$full_name} ({$membership_id}) registered with {$plan_name} (₱" . number_format($plan_price, 2) . "). Checkout session generated ({$ref_code})."
            ]);
        } catch (Exception $nEx) {}

    } else {
        // ── CASH (FRONT DESK) PAYMENT ─────────────────────────────────
        if ($plan_id > 0) {
            try {
                $req_stmt = $pdo->prepare("
                    INSERT INTO renewal_requests 
                    (member_id, plan_id, payment_method, reference_no, status, notes, created_at)
                    VALUES (?, ?, ?, ?, 'Pending', ?, NOW())
                ");
                $req_stmt->execute([
                    $member_id,
                    $plan_id,
                    'Cash',
                    'REG-' . $membership_id,
                    "Initial Registration Fee — {$plan_name}"
                ]);
            } catch (Exception $payEx) {
                error_log("Failed to insert initial registration renewal request: " . $payEx->getMessage());
            }
        }

        try {
            $pdo->prepare("
                INSERT INTO notifications (member_id, type, title, message, delivery_status, read_status, sent_at)
                VALUES (?, 'Registration', 'New Member Registration Awaiting Review', ?, 'Sent', 'Unread', NOW())
            ")->execute([
                $member_id,
                "New member {$full_name} ({$membership_id}) registered with {$plan_name} (₱" . number_format($plan_price, 2) . ", Method: Cash). Please verify payment at front desk."
            ]);
        } catch (Exception $nEx) {}
    }

    $pdo->commit();

    // Prepare JSON response
    $response_data = [
        'success'          => true,
        'requires_payment' => ($is_online_payment && !empty($checkout_url)),
        'pending_approval' => !$is_online_payment,
        'is_active'        => false,
        'checkout_url'     => $checkout_url,
        'reference_code'   => $ref_code,
        'checkout'         => ($is_online_payment && !empty($checkout_url)) ? [
            'ref_code'         => $ref_code,
            'checkout_url'     => $checkout_url,
            'plan_id'          => $plan_id,
            'plan_name'        => $plan_name,
            'amount'           => $plan_price,
            'amount_formatted' => '₱' . number_format($plan_price, 2),
            'payment_method'   => $payment_method,
            'member_name'      => $full_name,
            'membership_id'    => $membership_id
        ] : null,
        'message'          => ($is_online_payment && !empty($checkout_url))
            ? "Account created! Redirecting to secure online payment..." 
            : 'Registration submitted! Please settle your cash payment at the gym front desk upon your visit.',
        'membership_id'    => $membership_id,
        'full_name'        => $full_name,
        'auth_provider'    => $auth_provider,
        'token'            => $auth_token,
        'member'           => [
            'id'             => $member_id,
            'membership_id'  => $membership_id,
            'full_name'      => $full_name,
            'email'          => $email,
            'account_status' => 'Pending',
            'status'         => 'Inactive'
        ]
    ];

    if (ob_get_length()) {
        ob_clean();
    }

    echo json_encode($response_data);

    // Fast-finish HTTP response if supported by server (Nginx/Apache/FPM)
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (ob_get_level()) {
        @ob_end_flush();
        @flush();
    }

    // Activity Log (Safely run in background)
    try {
        $provider_label = ($auth_provider === 'google') ? ' (Google Sign-In)' : '';
        $log_status = $is_online_instant ? 'Auto-activated instantly.' : 'Pending staff cash collection.';
        log_activity($pdo, 'Member Registration', "New member registered{$provider_label}: {$full_name} ({$membership_id}) via {$payment_method}. {$log_status}", 'Member');
    } catch (Throwable $logEx) {
        error_log("Registration log_activity warning: " . $logEx->getMessage());
    }

    // Welcome email (Safely run in background)
    try {
        require_once __DIR__ . '/../config/email.php';
        if ($is_online_instant) {
            @send_email_notification(
                $email,
                "Membership Activated! — Palma's Elite Gym",
                "Welcome, {$full_name}!",
                "Thank you for registering with Palma's Elite Gym!<br><br>Your payment via <strong>{$payment_method}</strong> has been processed, and your gym membership has been <strong>instantly activated</strong>!<br><br>Your Membership ID is: <strong>{$membership_id}</strong>.<br>You can now sign in to your mobile app to access your Digital QR Pass."
            );
        } else {
            $auth_text = ($auth_provider === 'google')
                ? "You can use <strong>Continue with Google</strong> in the Palma's Elite Gym Mobile App once your front-desk cash payment is verified."
                : "Your Membership Reference ID is: <strong>{$membership_id}</strong>.";

            @send_email_notification(
                $email,
                "Registration Received — Palma's Elite Gym",
                "Welcome, {$full_name}!",
                "Thank you for registering with Palma's Elite Gym!<br><br>Your account is currently <strong>Pending Review</strong>. Please settle your cash payment at the gym front desk upon your visit. {$auth_text}"
            );
        }
    } catch (Throwable $emErr) {
        error_log("Registration send_email_notification warning: " . $emErr->getMessage());
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Error in member_register.php: ' . $e->getMessage());
    if (ob_get_length()) ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred during registration. Please try again.']);
}
