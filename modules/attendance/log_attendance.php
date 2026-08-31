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
    $raw_input = trim($_POST['membership_id'] ?? '');

    if (empty($raw_input)) {
        echo json_encode(['success' => false, 'message' => 'Please scan or provide a Member ID.']);
        exit;
    }

    $membership_id = $raw_input;

    // Handle token format (e.g. PEG:MEMBERSHIP_ID:SIG or GYM-XXXXXX:SIG) or direct Membership ID
    if (strpos($raw_input, ':') !== false) {
        $parts = explode(':', $raw_input);
        if (count($parts) >= 2) {
            // First part or second part is the membership ID
            $membership_id = $parts[0];
            if ($parts[0] === 'PEG' && isset($parts[1])) {
                $membership_id = $parts[1];
            }
        }
    }

    try {
        // 1. Get Member & Plan details
        $stmt = $pdo->prepare("
            SELECT m.*, 
                   (SELECT s.expiry_date 
                    FROM subscriptions s 
                    WHERE s.member_id = m.id AND s.expiry_date >= CURDATE()
                    ORDER BY s.expiry_date DESC, s.id DESC 
                    LIMIT 1) as expiry_date,
                   (SELECT p.name 
                    FROM subscriptions s 
                    LEFT JOIN membership_plans p ON p.id = s.plan_id 
                    WHERE s.member_id = m.id AND s.expiry_date >= CURDATE()
                    ORDER BY s.expiry_date DESC, s.id DESC 
                    LIMIT 1) as plan_name
            FROM members m 
            WHERE m.membership_id = ? OR REPLACE(UPPER(m.membership_id), '-', '') = REPLACE(UPPER(?), '-', '')
            LIMIT 1
        ");
        $stmt->execute([$membership_id, $membership_id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            echo json_encode([
                'success' => false, 
                'message' => 'Member not found. Please verify the QR Code or Member ID.'
            ]);
            exit;
        }

        $acc_status = $member['account_status'] ?? 'Approved';

        // 2. Validate Account Status
        if ($acc_status === 'Pending') {
            echo json_encode([
                'success' => false,
                'status_type' => 'Pending',
                'member_name' => $member['full_name'],
                'membership_id' => $member['membership_id'],
                'photo' => $member['photo'],
                'message' => 'Account is Pending Review. Please approve the registration first.'
            ]);
            exit;
        }

        if ($acc_status === 'Rejected') {
            echo json_encode([
                'success' => false,
                'status_type' => 'Rejected',
                'member_name' => $member['full_name'],
                'membership_id' => $member['membership_id'],
                'photo' => $member['photo'],
                'message' => 'Account Rejected. ' . ($member['rejection_reason'] ?: 'Please contact front desk.')
            ]);
            exit;
        }

        if ($acc_status === 'Suspended' || $member['status'] === 'Suspended') {
            echo json_encode([
                'success' => false,
                'status_type' => 'Suspended',
                'member_name' => $member['full_name'],
                'membership_id' => $member['membership_id'],
                'photo' => $member['photo'],
                'message' => 'Member account is currently Suspended.'
            ]);
            exit;
        }

        // 3. Check Subscription Expiry
        $sub_stmt = $pdo->prepare("SELECT expiry_date, plan_id FROM subscriptions WHERE member_id = ? ORDER BY expiry_date DESC LIMIT 1");
        $sub_stmt->execute([$member['id']]);
        $sub = $sub_stmt->fetch(PDO::FETCH_ASSOC);

        $is_expired = (!$sub || strtotime($sub['expiry_date']) < strtotime(date('Y-m-d')));

        if ($is_expired) {
            $pdo->prepare("UPDATE members SET status = 'Expired' WHERE id = ?")->execute([$member['id']]);
            
            echo json_encode([
                'success' => false,
                'status_type' => 'Expired',
                'member_name' => $member['full_name'],
                'membership_id' => $member['membership_id'],
                'photo' => $member['photo'],
                'expiry_date' => $sub['expiry_date'] ?? 'No Subscription',
                'message' => 'Membership Expired (' . ($sub['expiry_date'] ?? 'None') . '). Please renew at the desk.'
            ]);
            exit;
        }

        // 4. Anti-Duplicate Check-in Cooldown & Active Session Check
        $last_checkin_stmt = $pdo->prepare("
            SELECT id, time_in, time_out, 
                   TIMESTAMPDIFF(MINUTE, time_in, NOW()) as minutes_since_in
            FROM attendance 
            WHERE member_id = ? AND date = CURDATE()
            ORDER BY time_in DESC 
            LIMIT 1
        ");
        $last_checkin_stmt->execute([$member['id']]);
        $last_record = $last_checkin_stmt->fetch(PDO::FETCH_ASSOC);

        // If currently inside (checked in, no checkout yet)
        if ($last_record && empty($last_record['time_out'])) {
            $mins = intval($last_record['minutes_since_in'] ?? 0);
            
            // If scanned within 3 minutes of check-in, trigger anti-duplicate cooldown
            if ($mins < 3) {
                echo json_encode([
                    'success' => true,
                    'is_cooldown' => true,
                    'action' => 'cooldown',
                    'member_name' => $member['full_name'],
                    'membership_id' => $member['membership_id'],
                    'photo' => $member['photo'],
                    'account_status' => 'Approved',
                    'membership_status' => 'Active',
                    'plan_name' => $member['plan_name'] ?: 'Standard',
                    'expiry_date' => date('M d, Y', strtotime($member['expiry_date'])),
                    'time' => date('h:i A', strtotime($last_record['time_in'])),
                    'message' => 'Member already checked in at ' . date('h:i A', strtotime($last_record['time_in'])) . ' (Cooldown Active).'
                ]);
                exit;
            }

            // Otherwise, perform Check-out
            $upd = $pdo->prepare("UPDATE attendance SET time_out = NOW() WHERE id = ?");
            $upd->execute([$last_record['id']]);

            echo json_encode([
                'success' => true,
                'action' => 'check-out',
                'member_name' => $member['full_name'],
                'membership_id' => $member['membership_id'],
                'photo' => $member['photo'],
                'account_status' => 'Approved',
                'membership_status' => 'Active',
                'plan_name' => $member['plan_name'] ?: 'Standard',
                'expiry_date' => date('M d, Y', strtotime($member['expiry_date'])),
                'time' => date('h:i A'),
                'message' => 'Check-out successful! Goodbye, ' . $member['full_name'] . '.'
            ]);
            log_activity($pdo, 'Member Check-out', "Member {$member['full_name']} ({$member['membership_id']}) checked out.", 'Attendance');
            exit;
        }

        // 5. Log New Check-in
        $ins = $pdo->prepare("INSERT INTO attendance (member_id, date, time_in) VALUES (?, CURDATE(), NOW())");
        $ins->execute([$member['id']]);

        echo json_encode([
            'success' => true,
            'action' => 'check-in',
            'member_name' => $member['full_name'],
            'membership_id' => $member['membership_id'],
            'photo' => $member['photo'],
            'account_status' => 'Approved',
            'membership_status' => 'Active',
            'plan_name' => $member['plan_name'] ?: 'Standard',
            'expiry_date' => date('M d, Y', strtotime($member['expiry_date'])),
            'time' => date('h:i A'),
            'message' => 'VALID MEMBER • Check-in Successful! Welcome, ' . $member['full_name'] . '.'
        ]);
        log_activity($pdo, 'Member Check-in', "Member {$member['full_name']} ({$member['membership_id']}) checked in.", 'Attendance');

    } catch (Exception $e) {
        error_log('Attendance Error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A server error occurred while processing attendance.']);
    }
}

