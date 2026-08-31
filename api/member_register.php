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
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/logger.php';
require_once __DIR__ . '/../config/duplicate_validator.php';

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

// ── Field Extraction ──────────────────────────────────────────────────────────
$full_name      = trim($data['full_name'] ?? '');
$email          = strtolower(trim($data['email'] ?? ''));
$contact_number = trim($data['contact_number'] ?? '');
$password       = trim($data['password'] ?? '');
$gender         = trim($data['gender'] ?? 'Other');
$plan_id        = intval($data['plan_id'] ?? 0);
$google_id      = trim($data['google_id'] ?? '');
$google_picture = trim($data['google_picture'] ?? '');
$auth_provider  = !empty($google_id) ? 'google' : 'password';

// ── Validation ────────────────────────────────────────────────────────────────
if (empty($full_name)) {
    echo json_encode(['success' => false, 'message' => 'Full Name is required.']);
    exit;
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'A valid email address is required.']);
    exit;
}

// Password required for traditional sign-up only
if ($auth_provider === 'password') {
    if (empty($password) || strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters long.']);
        exit;
    }
}

if (!empty($contact_number) && !preg_match('/^09[0-9]{9}$/', $contact_number)) {
    echo json_encode(['success' => false, 'message' => 'Contact number must be 11 digits starting with 09 (e.g. 09123456789).']);
    exit;
}

$valid_genders = ['Male', 'Female', 'Other'];
if (!in_array($gender, $valid_genders)) {
    $gender = 'Other';
}

// ── Duplicate Detection ───────────────────────────────────────────────────────
// Check for existing google_id first (most specific)
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

    $password_hash = ($auth_provider === 'password') ? password_hash($password, PASSWORD_DEFAULT) : null;

    $stmt = $pdo->prepare("
        INSERT INTO members 
            (membership_id, full_name, email, contact_number, gender, photo, google_id, google_picture,
             auth_provider, account_status, status, selected_plan_id, password_hash, created_at)
        VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, 'Pending', 'Inactive', ?, ?, NOW())
    ");
    $stmt->execute([
        $membership_id,
        $full_name,
        $email,
        $contact_number ?: null,
        $gender,
        $google_id ?: null,
        $google_picture ?: null,
        $auth_provider,
        ($plan_id > 0) ? $plan_id : null,
        $password_hash
    ]);
    $member_id = (int)$pdo->lastInsertId();

    // Admin notification
    try {
        $pdo->prepare("
            INSERT INTO notifications (member_id, type, title, message, delivery_status, read_status, sent_at)
            VALUES (?, 'Registration', 'New Member Registration Awaiting Review', ?, 'Sent', 'Unread', NOW())
        ")->execute([
            $member_id,
            "New member {$full_name} ({$membership_id}) registered via " . ($auth_provider === 'google' ? 'Google Sign-In' : 'Mobile App') . ". Please review and approve."
        ]);
    } catch (Exception $nEx) {}

    $pdo->commit();

    // Activity Log
    $provider_label = ($auth_provider === 'google') ? ' (Google Sign-In)' : '';
    log_activity($pdo, 'Member Registration', "New member registered{$provider_label}: {$full_name} ({$membership_id}). Pending staff review.", 'Member');

    // Welcome email (non-blocking)
    try {
        require_once __DIR__ . '/../config/email.php';
        $auth_text = ($auth_provider === 'google')
            ? "You can use <strong>Continue with Google</strong> in the Palma's Elite Gym Mobile App once your account is approved."
            : "Your Membership Reference ID is: <strong>{$membership_id}</strong>.";

        send_email_notification(
            $email,
            "Registration Received — Palma's Elite Gym",
            "Welcome, {$full_name}!",
            "Thank you for registering with Palma's Elite Gym!<br><br>Your account is currently <strong>Pending Review</strong> by our staff. {$auth_text}<br><br>You will receive an email and notification once your registration has been reviewed."
        );
    } catch (Exception $emErr) {}

    echo json_encode([
        'success'          => true,
        'pending_approval' => true,
        'message'          => 'Registration submitted! Your account is pending staff approval. You will be notified via email once reviewed.',
        'membership_id'    => $membership_id,
        'full_name'        => $full_name,
        'auth_provider'    => $auth_provider,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Error in member_register.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred during registration. Please try again.']);
}
