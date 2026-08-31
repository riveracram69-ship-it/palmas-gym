<?php
/**
 * api/google_auth.php
 * ─────────────────────────────────────────────────────────────────
 * Secure Google OAuth 2.0 Sign-In endpoint for Palma's Elite Gym.
 *
 * Flow:
 *  1. Mobile sends Google id_token (JWT) from the Google Sign-In SDK.
 *  2. Backend verifies the token with Google's tokeninfo API.
 *  3. Backend looks up member by google_id OR email.
 *  4. Returns:
 *     - Existing approved member  → { success: true, token, member }
 *     - Existing pending member   → { success: false, account_status: 'Pending' }
 *     - Existing rejected member  → { success: false, account_status: 'Rejected', reason }
 *     - Existing suspended member → { success: false, account_status: 'Suspended' }
 *     - Brand new user            → { success: false, new_user: true, prefill: { name, email, picture } }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/logger.php';

// ── 1. Parse Input ────────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

$id_token = trim($data['id_token'] ?? '');

if (empty($id_token)) {
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
try {
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

        // If member was found by email but has no google_id yet → link their Google account
        if (empty($member['google_id'])) {
            $pdo->prepare("UPDATE members SET google_id = ?, google_picture = ?, auth_provider = 'both' WHERE id = ?")
                ->execute([$google_id, $g_picture ?: $member['google_picture'], $member['id']]);
            $member['google_id']      = $google_id;
            $member['auth_provider'] = 'both';
        }

        // Refresh google picture if changed
        if (!empty($g_picture) && $g_picture !== ($member['google_picture'] ?? '')) {
            $pdo->prepare("UPDATE members SET google_picture = ? WHERE id = ?")
                ->execute([$g_picture, $member['id']]);
            $member['google_picture'] = $g_picture;
        }

        $acc_status = $member['account_status'] ?? 'Approved';

        // Block non-approved accounts
        if ($acc_status === 'Pending') {
            echo json_encode([
                'success'        => false,
                'account_status' => 'Pending',
                'full_name'      => $member['full_name'],
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
        $pdo->prepare("INSERT INTO auth_tokens (member_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))")
            ->execute([$member['id'], $token]);

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
                'photo'         => $member['google_picture'] ?: $member['photo'],
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

} catch (Exception $e) {
    error_log('Google Auth Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again.']);
}

// ── Helper: Verify Google ID Token ───────────────────────────────────────────
/**
 * Verifies a Google ID token by querying Google's tokeninfo endpoint.
 * Returns the decoded payload array on success, or null on failure.
 *
 * In production with google/apiclient installed:
 *   use Google\Client; $client->verifyIdToken($token);
 *
 * This implementation uses the tokeninfo HTTP endpoint (works without SDK).
 */
function verify_google_id_token(string $token, string $expected_client_id = ''): ?array {
    // Use Google's tokeninfo endpoint
    $url      = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($token);
    $ctx      = stream_context_create(['http' => ['timeout' => 10]]);
    $response = @file_get_contents($url, false, $ctx);

    if ($response === false) {
        // Fallback: try cURL
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
        }
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

    // Validate audience (aud) matches our Client ID if configured
    if (!empty($expected_client_id) && isset($payload['aud'])) {
        $aud_values = is_array($payload['aud']) ? $payload['aud'] : [$payload['aud']];
        if (!in_array($expected_client_id, $aud_values, true)) {
            error_log('Google tokeninfo: aud mismatch. Expected: ' . $expected_client_id . ' Got: ' . implode(',', $aud_values));
            return null;
        }
    }

    return $payload;
}
