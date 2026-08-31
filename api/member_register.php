<?php
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

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    $data = $_POST;
}

$full_name      = trim($data['full_name'] ?? '');
$email          = trim($data['email'] ?? '');
$contact_number = trim($data['contact_number'] ?? '');
$password       = trim($data['password'] ?? '');
$gender         = trim($data['gender'] ?? 'Other');
$plan_id        = intval($data['plan_id'] ?? 0);

if (empty($full_name)) {
    echo json_encode(['success' => false, 'message' => 'Full Name is required.']);
    exit;
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'A valid email address is required.']);
    exit;
}

if (empty($password) || strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters long.']);
    exit;
}

if (!empty($contact_number) && !preg_match('/^09[0-9]{9}$/', $contact_number)) {
    echo json_encode(['success' => false, 'message' => 'Contact number must be 11 digits starting with 09 (e.g. 09123456789).']);
    exit;
}

require_once __DIR__ . '/../config/duplicate_validator.php';

$dup_check = validate_member_uniqueness($pdo, $full_name, $email, $contact_number);
if (!$dup_check['valid']) {
    echo json_encode(['success' => false, 'message' => implode(' ', $dup_check['errors'])]);
    exit;
}

try {

    $pdo->beginTransaction();

    // Generate unique Membership ID
    $membership_id = 'GYM-' . strtoupper(substr(uniqid(), -6));
    $check_id = $pdo->prepare("SELECT id FROM members WHERE membership_id = ?");
    $check_id->execute([$membership_id]);
    while ($check_id->fetch()) {
        $membership_id = 'GYM-' . strtoupper(substr(uniqid(), -6));
        $check_id->execute([$membership_id]);
    }

    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO members (membership_id, full_name, email, contact_number, gender, photo, account_status, status, selected_plan_id, password_hash, created_at)
        VALUES (?, ?, ?, ?, ?, NULL, 'Pending', 'Inactive', ?, ?, NOW())
    ");
    $stmt->execute([$membership_id, $full_name, $email, $contact_number, $gender, ($plan_id > 0 ? $plan_id : null), $password_hash]);
    $member_id = (int)$pdo->lastInsertId();

    // Insert admin notification
    try {
        $notif_stmt = $pdo->prepare("
            INSERT INTO notifications (member_id, type, title, message, delivery_status, read_status, sent_at)
            VALUES (?, 'Registration', 'New Mobile App Registration Awaiting Review', ?, 'Sent', 'Unread', NOW())
        ");
        $notif_stmt->execute([$member_id, "New member registration submitted by {$full_name} ({$membership_id}) via Mobile App. Please review and approve."]);
    } catch (Exception $nEx) {}

    $pdo->commit();

    // Log Activity
    log_activity($pdo, 'Member Registration', "New member registered via API (Pending Review): {$full_name} ({$membership_id})", 'Member');

    // Email notification if configured
    try {
        require_once __DIR__ . '/../config/email.php';
        $email_subject = "Registration Received - Palma's Elite Gym";
        $email_title = "Welcome, {$full_name}!";
        $email_body = "Your registration has been submitted and is currently <strong>Pending Review</strong> by our staff. Your Membership ID is: <strong>{$membership_id}</strong>. You will receive an email once your account has been approved.";
        send_email_notification($email, $email_subject, $email_title, $email_body);
    } catch (Exception $emErr) {}

    echo json_encode([
        'success' => true,
        'pending_approval' => true,
        'message' => 'Registration submitted successfully! Your account is currently waiting for staff approval. Your Reference ID is: ' . $membership_id,
        'membership_id' => $membership_id,
        'full_name' => $full_name
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Error in member_register.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred during registration. Please try again.']);
}
