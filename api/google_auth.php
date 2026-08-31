<?php
/**
 * api/google_auth.php
 * ─────────────────────────────────────────────────────────────────
 * Secure Google OAuth 2.0 Sign-In endpoint for Palma's Elite Gym.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../config/env.php';
    require_once __DIR__ . '/../config/logger.php';

    // ── 1. Parse Input ────────────────────────────────────────────────────────────
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: $_POST;

    $id_token = trim($data['id_token'] ?? '');

    if (empty($id_token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Google authentication token is required.']);
        exit;
    }

    // ── 2. Verify Token with Google ───────────────────────────────────────────────
    $google_client_id = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '';
    $google_info      = verify_google_id_token($id_token, $google_client_id);

    if (!$google_info || empty($google_info['sub'])) {
        echo json_encode(['success' => false, 'message' => 'Google authentication failed. Please try signing in again.']);
        exit;
    }

    $google_id  = $google_info['sub'];
    $g_email    = strtolower(trim($google_info['email'] ?? ''));
    $g_name     = trim($google_info['name'] ?? '');
    $g_picture  = trim($google_info['picture'] ?? '');
    $g_verified = ($google_info['email_verified'] ?? false) === true || ($google_info['email_verified'] ?? '') === 'true';

    if (!$g_verified || empty($g_email)) {
        echo json_encode(['success' => false, 'message' => 'Your Google account email is not verified. Please verify your Google account first.']);
        exit;
    }

    // ── 3. Lookup Member ─────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT id, membership_id, full_name, email, contact_number, photo, google_picture,
               account_status, status, rejection_reason, auth_provider, google_id
        FROM members
        WHERE google_id = ? OR LOWER(email) = LOWER(?)
        LIMIT 1
    ");
    $stmt->execute([$google_id, $g_email]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    // ── 4A. Existing Member ───────────────────────────────────────────────────
    if ($member) {

        // Link Google account if not linked yet
        if (empty($member['google_id'])) {
            try {
                $pdo->prepare("UPDATE members SET google_id = ?, google_picture = ?, auth_provider = 'both' WHERE id = ?")
                    ->execute([$google_id, $g_picture ?: ($member['google_picture'] ?? null), $member['id']]);
                $member['google_id']     = $google_id;
                $member['auth_provider'] = 'both';
            } catch (Throwable $ue) {}
        }

        // Refresh google picture if updated
        if (!empty($g_picture) && $g_picture !== ($member['google_picture'] ?? '')) {
            try {
                $pdo->prepare("UPDATE members SET google_picture = ? WHERE id = ?")
                    ->execute([$g_picture, $member['id']]);
                $member['google_picture'] = $g_picture;
            } catch (Throwable $ue) {}
        }

        $acc_status = $member['account_status'] ?? 'Approved';

        if ($acc_status === 'Pending') {
            echo json_encode([
                'success'        => false,
                'account_status' => 'Pending',
                'full_name'      => $member['full_name'],
                'membership_id'  => $member['membership_id'],
                'email'          => $member['email'],
                'message'        => 'Your account is currently pending staff review. You will receive an email and notification once approved.'
            ]);
            exit;
        }

        if ($acc_status === 'Rejected') {
            $reason = !empty($member['rejection_reason']) ? $member['rejection_reason'] : 'No reason provided.';
            echo json_encode([
                'success'          => false,
                'account_status'   => 'Rejected',
                'full_name'        => $member['full_name'],
                'rejection_reason' => $reason,
                'message'          => "Your registration was not approved. Reason: {$reason}"
            ]);
            exit;
        }

        if ($acc_status === 'Suspended') {
            echo json_encode([
                'success'        => false,
                'account_status' => 'Suspended',
                'full_name'      => $member['full_name'],
                'message'        => 'Your account is currently suspended. Please contact Palma\'s Elite Gym for assistance.'
            ]);
            exit;
        }

        // Approved → Issue auth token
        $token = bin2hex(random_bytes(32));
        try {
            $pdo->prepare("INSERT INTO auth_tokens (member_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))")
                ->execute([$member['id'], $token]);
        } catch (Throwable $te) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS auth_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_token (token),
                KEY idx_member (member_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->prepare("INSERT INTO auth_tokens (member_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))")
                ->execute([$member['id'], $token]);
        }

        log_activity($pdo, 'Member Login', "Member {$member['full_name']} ({$member['membership_id']}) signed in via Google.", 'Auth');

        unset($member['rejection_reason']);
        echo json_encode([
            'success' => true,
            'token'   => $token,
            'member'  => [
                'id'            => $member['id'],
                'membership_id' => $member['membership_id'],
                'full_name'     => $member['full_name'],
                'email'         => $member['email'],
                'contact_number'=> $member['contact_number'],
                'photo'         => $member['google_picture'] ?: ($member['photo'] ?? null),
                'account_status'=> $member['account_status'],
                'status'        => $member['status'],
                'auth_provider' => $member['auth_provider'],
            ]
        ]);
        exit;
    }

    // ── 4B. New Google User → Send prefill data for registration wizard ───────
    echo json_encode([
        'success'  => false,
        'new_user' => true,
        'prefill'  => [
            'google_id' => $google_id,
            'name'      => $g_name,
            'email'     => $g_email,
            'picture'   => $g_picture,
        ],
        'message' => 'Google account verified. Please complete your registration.'
    ]);

} catch (Throwable $e) {
    error_log('Google Auth Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to complete Google Sign-In. Please check your connection and try again.']);
}

// ── Helper: Verify Google ID Token ───────────────────────────────────────────
function verify_google_id_token(string $token, string $expected_client_id = ''): ?array {
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($token);
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $response = @file_get_contents($url, false, $ctx);

    if ($response === false && function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
    }

    if (!$response) {
        error_log('Google tokeninfo: Could not reach Google servers.');
        return null;
    }

    $payload = json_decode($response, true);

    if (empty($payload) || !empty($payload['error'])) {
        error_log('Google tokeninfo error: ' . ($payload['error_description'] ?? $payload['error'] ?? 'unknown'));
        return null;
    }

    if (!empty($expected_client_id) && isset($payload['aud'])) {
        $aud_values = is_array($payload['aud']) ? $payload['aud'] : [$payload['aud']];
        if (!in_array($expected_client_id, $aud_values, true)) {
            error_log('Google tokeninfo: aud mismatch. Expected: ' . $expected_client_id . ' Got: ' . implode(',', $aud_values));
            return null;
        }
    }

    return $payload;
}
