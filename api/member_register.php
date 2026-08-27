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

try {
    // Check if email is already taken
    $check_email = $pdo->prepare("SELECT id FROM members WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $check_email->execute([$email]);
    if ($check_email->fetch()) {
        echo json_encode(['success' => false, 'message' => 'The email address is already registered. Please sign in instead.']);
        exit;
    }

    // Check if contact number is already taken (if provided)
    if (!empty($contact_number)) {
        $check_contact = $pdo->prepare("SELECT id FROM members WHERE contact_number = ? LIMIT 1");
        $check_contact->execute([$contact_number]);
        if ($check_contact->fetch()) {
            echo json_encode(['success' => false, 'message' => 'This contact number is already registered.']);
            exit;
        }
    }

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
        INSERT INTO members (membership_id, full_name, email, contact_number, gender, photo, status, password_hash, created_at)
        VALUES (?, ?, ?, ?, ?, NULL, 'Active', ?, NOW())
    ");
    $stmt->execute([$membership_id, $full_name, $email, $contact_number, $gender, $password_hash]);
    $member_id = (int)$pdo->lastInsertId();

    // If plan_id is selected or default to 1st plan if exists
    if ($plan_id <= 0) {
        $first_plan = $pdo->query("SELECT id FROM membership_plans ORDER BY price ASC LIMIT 1")->fetch();
        if ($first_plan) {
            $plan_id = (int)$first_plan['id'];
        }
    }

    if ($plan_id > 0) {
        $plan_stmt = $pdo->prepare("SELECT duration_months FROM membership_plans WHERE id = ?");
        $plan_stmt->execute([$plan_id]);
        $plan = $plan_stmt->fetch();
        $duration = $plan ? (int)$plan['duration_months'] : 1;

        $start_date = date('Y-m-d');
        $expiry_date = date('Y-m-d', strtotime("+{$duration} months"));

        $sub_stmt = $pdo->prepare("
            INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date)
            VALUES (?, ?, ?, ?)
        ");
        $sub_stmt->execute([$member_id, $plan_id, $start_date, $expiry_date]);
    }

    // Generate auth token
    $token = bin2hex(random_bytes(32));
    $insertToken = $pdo->prepare("INSERT INTO auth_tokens (member_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
    $insertToken->execute([$member_id, $token]);

    $pdo->commit();

    // Log Activity
    log_activity($pdo, 'Member Registration', "New self-registered member: {$full_name} ({$membership_id})", 'Member');

    // Email notification if configured
    try {
        require_once __DIR__ . '/../config/email.php';
        $email_subject = "Welcome to Palma's Elite Gym!";
        $email_title = "Welcome, {$full_name}!";
        $email_body = "Your account has been created successfully! Your Membership ID is: <strong>{$membership_id}</strong>. You can use this ID or your email along with your password to log in to the Member Portal and Mobile App.";
        send_email_notification($email, $email_subject, $email_title, $email_body);
    } catch (Exception $emErr) {}

    $member_data = [
        'id' => $member_id,
        'membership_id' => $membership_id,
        'full_name' => $full_name,
        'email' => $email,
        'contact_number' => $contact_number,
        'photo' => null,
        'status' => 'Active'
    ];

    echo json_encode([
        'success' => true,
        'message' => 'Account created successfully! Your Membership ID is ' . $membership_id,
        'membership_id' => $membership_id,
        'token' => $token,
        'member' => $member_data
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Error in member_register.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred during registration. Please try again.']);
}
