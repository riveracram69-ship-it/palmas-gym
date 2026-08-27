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
require_once __DIR__ . '/../config/rate_limiter.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    $data = $_POST;
}

$username = trim($data['username'] ?? '');
$password = trim($data['password'] ?? '');

if (empty($username) || empty($password)) {
    echo json_encode([
        'success' => false,
        'message' => 'Please enter your Member ID/Email and password.'
    ]);
    exit;
}

// ── 1. Check Rate Limit ──────────────────────────────────────────────────────
$rate_check = check_rate_limit($pdo, $username, 'api_member_login');
if (!$rate_check['allowed']) {
    http_response_code(429); // 429 Too Many Requests
    echo json_encode([
        'success'      => false,
        'rate_limited' => true,
        'wait_seconds' => $rate_check['wait_seconds'],
        'message'      => $rate_check['message']
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, membership_id, full_name, email, contact_number, photo, status, password_hash 
        FROM members 
        WHERE (membership_id = :u1 OR LOWER(email) = LOWER(:u2) OR contact_number = :u3) 
        LIMIT 1
    ");
    $stmt->execute([':u1' => $username, ':u2' => $username, ':u3' => $username]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($member) {
        if ($member['status'] === 'Archived') {
            echo json_encode(['success' => false, 'message' => 'Your account is archived. Please contact gym staff.']);
            exit;
        }

        // Case 1: Member registered at Admin front desk (No password set yet)
        if (empty($member['password_hash'])) {
            $is_verified = (
                strtolower($password) === strtolower($member['email']) ||
                $password === $member['contact_number'] ||
                $password === $member['membership_id']
            );

            if ($is_verified) {
                clear_rate_limit($pdo, $username, 'api_member_login');
                echo json_encode([
                    'success' => true,
                    'first_time_setup' => true,
                    'member_id' => $member['id'],
                    'full_name' => $member['full_name'],
                    'membership_id' => $member['membership_id'],
                    'email' => $member['email'],
                    'message' => 'First-time login detected. Please create your secure password.'
                ]);
                exit;
            } else {
                $failed = record_failed_login($pdo, $username, 'api_member_login');
                if ($failed['lockout']) {
                    http_response_code(429);
                }
                echo json_encode([
                    'success' => false,
                    'rate_limited' => $failed['lockout'],
                    'wait_seconds' => $failed['wait_seconds'],
                    'message' => $failed['lockout'] 
                        ? $failed['message'] 
                        : 'First-Time Setup: Enter your registered Email or Phone Number in the password field to verify your account.'
                ]);
                exit;
            }
        }

        // Case 2: Normal password login
        if (password_verify($password, $member['password_hash'])) {
            clear_rate_limit($pdo, $username, 'api_member_login');
            unset($member['password_hash']);
            $token = bin2hex(random_bytes(32));

            // Store token in database with 30 days expiration
            $insertToken = $pdo->prepare("INSERT INTO auth_tokens (member_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
            $insertToken->execute([$member['id'], $token]);

            echo json_encode([
                'success' => true,
                'message' => 'Login successful',
                'token' => $token,
                'member' => $member
            ]);
        } else {
            $failed = record_failed_login($pdo, $username, 'api_member_login');
            if ($failed['lockout']) {
                http_response_code(429);
            }
            echo json_encode([
                'success' => false,
                'rate_limited' => $failed['lockout'],
                'wait_seconds' => $failed['wait_seconds'],
                'message' => $failed['lockout'] ? $failed['message'] : 'Invalid Member ID, Email, or Password.'
            ]);
        }
    } else {
        $failed = record_failed_login($pdo, $username, 'api_member_login');
        if ($failed['lockout']) {
            http_response_code(429);
        }
        echo json_encode([
            'success' => false,
            'rate_limited' => $failed['lockout'],
            'wait_seconds' => $failed['wait_seconds'],
            'message' => $failed['lockout'] ? $failed['message'] : 'Invalid Member ID, Email, or Password.'
        ]);
    }
} catch (Exception $e) {
    error_log('API Error in member_login.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An internal server error occurred.'
    ]);
}

