<?php
require_once '../../config/auth.php';
require_once '../../config/db.php';
require_once '../../config/logger.php';

header('Content-Type: application/json');

// Check for Kiosk API key OR logged in staff/admin
$kiosk_api_key = KIOSK_API_KEY; // Set this in header X-Kiosk-Key for the public kiosk device
$is_kiosk = (isset($_SERVER['HTTP_X_KIOSK_KEY']) && $_SERVER['HTTP_X_KIOSK_KEY'] === $kiosk_api_key);
$is_staff = isset($_SESSION['user_id']);

if (!$is_kiosk && !$is_staff) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $membership_id = $_POST['membership_id'] ?? '';

    if (empty($membership_id)) {
        echo json_encode(['success' => false, 'message' => 'Empty ID provided']);
        exit;
    }

    // Check if the barcode/QR scanned represents a dynamic token
    if (strpos($membership_id, ':') !== false) {
        $parts = explode(':', $membership_id);
        if (count($parts) === 3) {
            list($token_member_id, $time_slot, $signature) = $parts;
            
            // Validate the HMAC-SHA256 signature
            $expected_signature = hash_hmac('sha256', $token_member_id . '|' . $time_slot, QR_SECRET_KEY);
            $expected_signature_short = substr($expected_signature, 0, 16);
            
            if (!hash_equals($expected_signature_short, $signature)) {
                echo json_encode(['success' => false, 'message' => 'Invalid QR Code security signature.']);
                exit;
            }
            
            // Check time slot (permit up to 2 time slots drift = 30 seconds)
            $current_time_slot = floor(time() / 15);
            if (abs($current_time_slot - intval($time_slot)) > 2) {
                echo json_encode(['success' => false, 'message' => 'This QR Code has expired. Please refresh the app.']);
                exit;
            }
            
            // Override membership_id with decoded value
            $membership_id = $token_member_id;
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid QR Code format.']);
            exit;
        }
    }

    try {
        // 1. Get Member
        $stmt = $pdo->prepare("SELECT id, full_name, status FROM members WHERE membership_id = ?");
        $stmt->execute([$membership_id]);
        $member = $stmt->fetch();

        if (!$member) {
            echo json_encode(['success' => false, 'message' => 'Invalid Member ID']);
            exit;
        }

        // 2. Check Expiry from Subscriptions
        $sub_stmt = $pdo->prepare("SELECT expiry_date FROM subscriptions WHERE member_id = ? ORDER BY expiry_date DESC LIMIT 1");
        $sub_stmt->execute([$member['id']]);
        $sub = $sub_stmt->fetch();

        if (!$sub || strtotime($sub['expiry_date']) < strtotime(date('Y-m-d'))) {
            // Auto-update member status if expired
            $pdo->prepare("UPDATE members SET status = 'Expired' WHERE id = ?")->execute([$member['id']]);
            
            echo json_encode(['success' => false, 'message' => 'Membership Expired! Please renew at the desk.']);
            exit;
        }

        // 3. Check for active session (Check-out logic)
        $session = $pdo->prepare("SELECT id, time_in FROM attendance WHERE member_id = ? AND time_out IS NULL ORDER BY date DESC, time_in DESC LIMIT 1");
        $session->execute([$member['id']]);
        $active_session = $session->fetch();

        if ($active_session) {
            // Anti-Replay / Debounce: Prevent checkout if they just checked in within 60 seconds
            if (strtotime($active_session['time_in']) > time() - 60) {
                echo json_encode(['success' => false, 'message' => 'Please wait a minute before checking out.']);
                exit;
            }

            // Check-out
            $stmt = $pdo->prepare("UPDATE attendance SET time_out = NOW() WHERE id = ?");
            $stmt->execute([$active_session['id']]);

            echo json_encode([
                'success' => true,
                'message' => 'Goodbye, ' . $member['full_name'] . '. See you next time!',
                'member_name' => $member['full_name'],
                'action' => 'check-out'
            ]);
            log_activity($pdo, 'Member Check-out', "Member {$member['full_name']} ({$membership_id}) checked out.", 'Attendance');
            exit;
        }

        // Anti-Replay / Debounce: Prevent checkin if they just checked out within 60 seconds
        $last_checkout = $pdo->prepare("SELECT time_out FROM attendance WHERE member_id = ? AND time_out IS NOT NULL ORDER BY date DESC, time_out DESC LIMIT 1");
        $last_checkout->execute([$member['id']]);
        $last = $last_checkout->fetch();
        if ($last && strtotime($last['time_out']) > time() - 60) {
            echo json_encode(['success' => false, 'message' => 'Please wait a minute before checking in again.']);
            exit;
        }

        // 4. Log Check-in
        $stmt = $pdo->prepare("INSERT INTO attendance (member_id, date, time_in) VALUES (?, CURDATE(), NOW())");
        $stmt->execute([$member['id']]);

        echo json_encode([
            'success' => true,
            'message' => 'Welcome back, ' . $member['full_name'],
            'member_name' => $member['full_name'],
            'action' => 'check-in'
        ]);
        log_activity($pdo, 'Member Check-in', "Member {$member['full_name']} ({$membership_id}) checked in.", 'Attendance');

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'A database error occurred.']);
    }
}
