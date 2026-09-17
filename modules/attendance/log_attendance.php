<?php
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/logger.php';

header('Content-Type: application/json');

// Check for Kiosk API key OR logged in staff/admin
$kiosk_api_key = defined('KIOSK_API_KEY') ? (string)KIOSK_API_KEY : '';
$provided_key = isset($_SERVER['HTTP_X_KIOSK_KEY']) ? (string)$_SERVER['HTTP_X_KIOSK_KEY'] : '';
$is_kiosk = ($kiosk_api_key !== '' && $provided_key !== '' && hash_equals($kiosk_api_key, $provided_key));
$is_staff = isset($_SESSION['user_id']);

if (!$is_kiosk && !$is_staff) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_id = 'REQ-' . strtoupper(bin2hex(random_bytes(4)));
    try {
        $raw_input = trim($_POST['membership_id'] ?? '');

        if (empty($raw_input)) {
            echo json_encode(['success' => false, 'request_id' => $request_id, 'message' => 'Please scan or provide a Member ID.']);
            exit;
        }

        $is_manual = isset($_POST['is_manual']) && ($_POST['is_manual'] === '1' || $_POST['is_manual'] === 'true');
        $requested_mode = strtolower(trim($_POST['action_mode'] ?? $_POST['action'] ?? 'auto'));
        $membership_id = null;
        $token_sig = null;
        $token_slot = 0;

        // Check if input is a dynamic rotating QR token (Format: GYM-XXXXXX:time_slot:signature)
        if (strpos($raw_input, ':') !== false) {
            $parts = explode(':', trim($raw_input));
            if (count($parts) === 3) {
                $token_mem_id = strtoupper(trim($parts[0]));
                $token_slot   = intval(trim($parts[1]));
                $token_sig    = trim($parts[2]);

                if (!preg_match('/^[A-Za-z0-9_-]{4,20}$/i', $token_mem_id)) {
                    echo json_encode(['success' => false, 'request_id' => $request_id, 'message' => 'Malformed QR code: Invalid Member ID format.']);
                    exit;
                }

                $current_slot = floor(time() / 15);
                $slot_diff    = abs($current_slot - $token_slot);

                // Time window: 15-second slots, max +-4 slots (~60 seconds drift allowance)
                if ($slot_diff > 4) {
                    echo json_encode([
                        'success' => false, 
                        'status_type' => 'Expired QR',
                        'request_id' => $request_id,
                        'message' => 'QR Code has expired. Please present a freshly refreshed dynamic QR.'
                    ]);
                    exit;
                }

                // Recalculate HMAC signature using server QR_SECRET_KEY
                $secret_key = defined('QR_SECRET_KEY') ? QR_SECRET_KEY : '';
                $full_sig   = hash_hmac('sha256', $token_mem_id . '|' . $token_slot, $secret_key);
                $expected_sig = (strlen($token_sig) <= 16) ? substr($full_sig, 0, strlen($token_sig)) : $full_sig;

                if (empty($token_sig) || !hash_equals($expected_sig, $token_sig)) {
                    echo json_encode([
                        'success' => false, 
                        'status_type' => 'Tampered QR',
                        'request_id' => $request_id,
                        'message' => 'Invalid or tampered QR signature. Access denied.'
                    ]);
                    exit;
                }

                $membership_id = $token_mem_id;

                // Anti-Replay & Anti-Screenshot Protection: Validate that this dynamic QR token hasn't already been consumed
                if (!empty($token_sig)) {
                    $replay_stmt = $pdo->prepare("
                        SELECT id, member_id, action, used_at, TIMESTAMPDIFF(SECOND, used_at, NOW()) as secs_ago 
                        FROM used_qr_tokens 
                        WHERE token_sig = ? 
                        LIMIT 1
                    ");
                    $replay_stmt->execute([$token_sig]);
                    $used_token = $replay_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($used_token) {
                        $secs_since_use = intval($used_token['secs_ago'] ?? 0);
                        if ($secs_since_use < 5) {
                            echo json_encode([
                                'success' => true,
                                'is_cooldown' => true,
                                'status_type' => 'Already Scanned',
                                'action' => 'cooldown',
                                'request_id' => $request_id,
                                'message' => 'Attendance Already Recorded. Cooldown active.'
                            ]);
                            exit;
                        }

                        echo json_encode([
                            'success' => false, 
                            'status_type' => 'Replayed QR Blocked',
                            'request_id' => $request_id,
                            'message' => 'This dynamic QR code has already been used and cannot be replayed. Please present a fresh QR code from your mobile app.'
                        ]);
                        exit;
                    }
                }
            } else {
                echo json_encode(['success' => false, 'request_id' => $request_id, 'message' => 'Malformed QR code structure. Expected format: GYM-ID:slot:sig']);
                exit;
            }
        } else {
            // Raw Member ID submitted (without signature, e.g. from printed/downloaded ID card or manual entry)
            // Allowed if entered manually by authenticated front-desk staff/admin
            if ($is_staff && $is_manual) {
                $cleaned = trim($raw_input);
                $membership_id = strtoupper($cleaned);
            } else {
                // Unattended kiosk or unauthenticated scanner requires dynamic rotating QR code
                echo json_encode([
                    'success' => false, 
                    'status_type' => 'Static QR Blocked',
                    'request_id' => $request_id,
                    'message' => 'Static QR code or screenshot is prohibited for security. Please present the rotating dynamic QR code from the Palma\'s Gym mobile app.'
                ]);
                exit;
            }
        }

        // 1. Fetch Member & Active Subscription
        $stmt = $pdo->prepare("
            SELECT m.*, 
                   (SELECT s.expiry_date 
                    FROM subscriptions s 
                    WHERE s.member_id = m.id 
                    ORDER BY (s.expiry_date >= NOW()) DESC, s.expiry_date DESC, s.id DESC 
                    LIMIT 1) as expiry_date,
                   (SELECT p.name 
                    FROM subscriptions s 
                    LEFT JOIN membership_plans p ON p.id = s.plan_id 
                    WHERE s.member_id = m.id 
                    ORDER BY (s.expiry_date >= NOW()) DESC, s.expiry_date DESC, s.id DESC 
                    LIMIT 1) as plan_name,
                   (SELECT p.floor_access 
                    FROM subscriptions s 
                    LEFT JOIN membership_plans p ON p.id = s.plan_id 
                    WHERE s.member_id = m.id 
                    ORDER BY (s.expiry_date >= NOW()) DESC, s.expiry_date DESC, s.id DESC 
                    LIMIT 1) as floor_access,
                   (SELECT p.plan_category 
                    FROM subscriptions s 
                    LEFT JOIN membership_plans p ON p.id = s.plan_id 
                    WHERE s.member_id = m.id 
                    ORDER BY (s.expiry_date >= NOW()) DESC, s.expiry_date DESC, s.id DESC 
                    LIMIT 1) as plan_category
            FROM members m 
            WHERE m.membership_id = ? OR REPLACE(UPPER(m.membership_id), '-', '') = REPLACE(UPPER(?), '-', '')
            LIMIT 1
        ");
        $stmt->execute([$membership_id, $membership_id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            echo json_encode([
                'success' => false,
                'status_type' => 'Invalid',
                'request_id' => $request_id,
                'message' => 'Member ID not found. Please verify registration.'
            ]);
            exit;
        }

        $full_name = !empty($member['full_name']) 
            ? $member['full_name'] 
            : trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
        $member['full_name'] = $full_name;

        // Compute Member Tier & Floor Access
        $ann_exp = $member['annual_membership_expiry'] ?? null;
        $is_official_member = (!empty($ann_exp) && strtotime($ann_exp) >= strtotime(date('Y-m-d')));
        $member_tier_label = $is_official_member ? 'Official Member' : 'Non-Member';
        
        $floor_access = $member['floor_access'] ?? 'all';
        if ($floor_access === 'second_floor_only') {
            $floor_label = '2nd Floor Only (Non-Member/Walk-in)';
        } elseif ($floor_access === 'ground_and_second') {
            $floor_label = 'Ground + 2nd Floor';
        } else {
            $floor_label = 'Ground + 2nd Floor (All Access)';
        }

        // 2. Validate Account Status
        if ($member['account_status'] === 'Pending') {
            echo json_encode([
                'success' => false,
                'status_type' => 'Pending Review',
                'member_name' => $full_name,
                'membership_id' => $member['membership_id'],
                'is_official_member' => $is_official_member,
                'member_tier_label' => $member_tier_label,
                'floor_access' => $floor_access,
                'floor_label' => $floor_label,
                'request_id' => $request_id,
                'message' => 'Account is Pending Review. Please approach the front desk for payment verification.'
            ]);
            exit;
        }

        if ($member['account_status'] === 'Rejected') {
            echo json_encode([
                'success' => false,
                'status_type' => 'Rejected',
                'member_name' => $full_name,
                'membership_id' => $member['membership_id'],
                'is_official_member' => $is_official_member,
                'member_tier_label' => $member_tier_label,
                'floor_access' => $floor_access,
                'floor_label' => $floor_label,
                'request_id' => $request_id,
                'message' => 'Account registration was rejected. Please contact gym administration.'
            ]);
            exit;
        }

        if ($member['account_status'] === 'Suspended' || $member['status'] === 'Suspended') {
            echo json_encode([
                'success' => false,
                'status_type' => 'Suspended',
                'member_name' => $full_name,
                'membership_id' => $member['membership_id'],
                'is_official_member' => $is_official_member,
                'member_tier_label' => $member_tier_label,
                'floor_access' => $floor_access,
                'floor_label' => $floor_label,
                'request_id' => $request_id,
                'message' => 'Account is suspended. Please contact gym administration.'
            ]);
            exit;
        }

        if ($member['account_status'] !== 'Approved') {
            echo json_encode([
                'success' => false,
                'status_type' => 'Blocked',
                'member_name' => $full_name,
                'membership_id' => $member['membership_id'],
                'is_official_member' => $is_official_member,
                'member_tier_label' => $member_tier_label,
                'floor_access' => $floor_access,
                'floor_label' => $floor_label,
                'request_id' => $request_id,
                'message' => 'Account is inactive. Status: ' . $member['account_status']
            ]);
            exit;
        }

        // 3. Subscription & Plan Expiry Check
        $now_time = time();
        $sub_exp_ts = (!empty($member['expiry_date'])) 
            ? ((strpos($member['expiry_date'], ':') !== false) ? strtotime($member['expiry_date']) : strtotime($member['expiry_date'] . ' 23:59:59'))
            : 0;
        $is_expired = (empty($member['expiry_date']) || $sub_exp_ts < $now_time);

        if ($is_expired) {
            $upd_mem = $pdo->prepare("UPDATE members SET status = 'Expired' WHERE id = ?");
            $upd_mem->execute([$member['id']]);

            echo json_encode([
                'success' => false,
                'status_type' => 'Expired',
                'member_name' => $full_name,
                'membership_id' => $member['membership_id'],
                'member_db_id' => $member['id'],
                'photo' => $member['photo'],
                'is_official_member' => $is_official_member,
                'member_tier_label' => $member_tier_label,
                'floor_access' => $floor_access,
                'floor_label' => $floor_label,
                'expiry_date' => $member['expiry_date'] ?? 'No Subscription',
                'request_id' => $request_id,
                'message' => 'Membership Expired (' . ($member['expiry_date'] ?? 'None') . '). Please renew at the desk.'
            ]);
            exit;
        }

        // 4. Concurrency-Safe Anti-Duplicate Check-in with Transaction & Row Lock
        $pdo->beginTransaction();

        // Acquire exclusive lock on member record to serialize concurrent scans for this member
        $lock_stmt = $pdo->prepare("SELECT id FROM members WHERE id = ? FOR UPDATE");
        $lock_stmt->execute([$member['id']]);

        $last_checkin_stmt = $pdo->prepare("
            SELECT id, time_in, time_out, 
                   TIMESTAMPDIFF(SECOND, time_in, NOW()) as seconds_since_in,
                   TIMESTAMPDIFF(SECOND, time_out, NOW()) as seconds_since_out
            FROM attendance 
            WHERE member_id = ? AND date = CURDATE()
            ORDER BY id DESC 
            LIMIT 1
            FOR UPDATE
        ");
        $last_checkin_stmt->execute([$member['id']]);
        $last_record = $last_checkin_stmt->fetch(PDO::FETCH_ASSOC);

        $is_inside = ($last_record && empty($last_record['time_out']));

        // Mode enforcement if explicitly specified ('check-in' or 'check-out')
        if ($requested_mode === 'check-in' && $is_inside) {
            $secs_in = intval($last_record['seconds_since_in'] ?? 0);
            $pdo->commit();
            if ($secs_in < 5) {
                echo json_encode([
                    'success' => true,
                    'is_cooldown' => true,
                    'status_type' => 'Already Scanned',
                    'action' => 'cooldown',
                    'request_id' => $request_id,
                    'message' => 'Attendance Already Recorded. Cooldown active.'
                ]);
                exit;
            }
            echo json_encode([
                'success' => false,
                'status_type' => 'Already Checked In',
                'request_id' => $request_id,
                'message' => 'Member is currently checked in. Please check out first before checking in again.'
            ]);
            exit;
        }

        if ($requested_mode === 'check-out' && !$is_inside) {
            $pdo->commit();
            echo json_encode([
                'success' => false,
                'status_type' => 'Not Checked In',
                'request_id' => $request_id,
                'message' => 'Member has no active check-in today to check out from.'
            ]);
            exit;
        }

        // Case A: Currently Inside (checked in, no checkout yet) -> Perform Check-out
        if ($is_inside) {
            $secs_in = intval($last_record['seconds_since_in'] ?? 0);
            
            // If scanned within 5 seconds of check-in, ignore rapid double-scan from camera
            if ($secs_in < 5) {
                $pdo->commit();
                $formatted_expiry = (!empty($member['expiry_date']) && strtotime($member['expiry_date']) !== false)
                    ? date('M d, Y', strtotime($member['expiry_date']))
                    : 'No Active Subscription';
                $formatted_time_in = (!empty($last_record['time_in']) && strtotime($last_record['time_in']) !== false)
                    ? date('h:i A', strtotime($last_record['time_in']))
                    : date('h:i A');

                echo json_encode([
                    'success' => true,
                    'is_cooldown' => true,
                    'status_type' => 'Already Scanned',
                    'action' => 'cooldown',
                    'member_name' => $member['full_name'],
                    'membership_id' => $member['membership_id'],
                    'photo' => $member['photo'],
                    'account_status' => 'Approved',
                    'membership_status' => 'Active',
                    'is_official_member' => $is_official_member,
                    'member_tier_label' => $member_tier_label,
                    'floor_access' => $floor_access,
                    'floor_label' => $floor_label,
                    'plan_name' => $member['plan_name'] ?: 'Standard',
                    'expiry_date' => $formatted_expiry,
                    'time' => $formatted_time_in,
                    'date' => date('M d, Y'),
                    'request_id' => $request_id,
                    'message' => 'Attendance Already Recorded at ' . $formatted_time_in . '.'
                ]);
                exit;
            }

            // Otherwise, perform Check-out (UPDATE the existing row's time_out, NO new row)
            $upd = $pdo->prepare("UPDATE attendance SET time_out = NOW() WHERE id = ?");
            $upd->execute([$last_record['id']]);

            // Consume dynamic QR token to prevent replay/screenshot attacks
            if (!empty($token_sig)) {
                $rec_tok = $pdo->prepare("INSERT IGNORE INTO used_qr_tokens (token_sig, membership_id, member_id, time_slot, action, used_at) VALUES (?, ?, ?, ?, 'check-out', NOW())");
                $rec_tok->execute([$token_sig, $member['membership_id'], $member['id'], $token_slot]);
            }

            $pdo->commit();

            $formatted_expiry = (!empty($member['expiry_date']) && strtotime($member['expiry_date']) !== false)
                ? date('M d, Y', strtotime($member['expiry_date']))
                : 'No Active Subscription';

            echo json_encode([
                'success' => true,
                'action' => 'check-out',
                'status_type' => 'Success',
                'member_name' => $member['full_name'],
                'membership_id' => $member['membership_id'],
                'photo' => $member['photo'],
                'account_status' => 'Approved',
                'membership_status' => 'Active',
                'is_official_member' => $is_official_member,
                'member_tier_label' => $member_tier_label,
                'floor_access' => $floor_access,
                'floor_label' => $floor_label,
                'plan_name' => $member['plan_name'] ?: 'Standard',
                'expiry_date' => $formatted_expiry,
                'time' => date('h:i A'),
                'date' => date('M d, Y'),
                'request_id' => $request_id,
                'message' => 'Check-out successful! Goodbye, ' . $member['full_name'] . '.'
            ]);
            log_activity($pdo, 'Member Check-out', "Member {$member['full_name']} ({$member['membership_id']}) checked out.", 'Attendance');
            exit;
        }

        // Case B: Already checked out within the last 5 seconds (ignore rapid double-scan on exit)
        if ($last_record && !empty($last_record['time_out'])) {
            $secs_out = intval($last_record['seconds_since_out'] ?? 0);
            if ($secs_out < 5) {
                $pdo->commit();
                $formatted_expiry = (!empty($member['expiry_date']) && strtotime($member['expiry_date']) !== false)
                    ? date('M d, Y', strtotime($member['expiry_date']))
                    : 'No Active Subscription';
                $formatted_time_out = (!empty($last_record['time_out']) && strtotime($last_record['time_out']) !== false)
                    ? date('h:i A', strtotime($last_record['time_out']))
                    : date('h:i A');

                echo json_encode([
                    'success' => true,
                    'is_cooldown' => true,
                    'status_type' => 'Already Scanned',
                    'action' => 'cooldown',
                    'member_name' => $member['full_name'],
                    'membership_id' => $member['membership_id'],
                    'photo' => $member['photo'],
                    'account_status' => 'Approved',
                    'membership_status' => 'Active',
                    'is_official_member' => $is_official_member,
                    'member_tier_label' => $member_tier_label,
                    'floor_access' => $floor_access,
                    'floor_label' => $floor_label,
                    'plan_name' => $member['plan_name'] ?: 'Standard',
                    'expiry_date' => $formatted_expiry,
                    'time' => $formatted_time_out,
                    'date' => date('M d, Y'),
                    'request_id' => $request_id,
                    'message' => 'Attendance Already Recorded (checked out at ' . $formatted_time_out . ').'
                ]);
                exit;
            }
        }

        // 5. Log New Check-in
        $ins = $pdo->prepare("INSERT INTO attendance (member_id, date, time_in) VALUES (?, CURDATE(), NOW())");
        $ins->execute([$member['id']]);

        // Consume dynamic QR token to prevent replay/screenshot attacks
        if (!empty($token_sig)) {
            $rec_tok = $pdo->prepare("INSERT IGNORE INTO used_qr_tokens (token_sig, membership_id, member_id, time_slot, action, used_at) VALUES (?, ?, ?, ?, 'check-in', NOW())");
            $rec_tok->execute([$token_sig, $member['membership_id'], $member['id'], $token_slot]);
        }

        $pdo->commit();

        $formatted_expiry = (!empty($member['expiry_date']) && strtotime($member['expiry_date']) !== false)
            ? date('M d, Y', strtotime($member['expiry_date']))
            : 'No Active Subscription';

        echo json_encode([
            'success' => true,
            'action' => 'check-in',
            'status_type' => 'Success',
            'member_name' => $member['full_name'],
            'membership_id' => $member['membership_id'],
            'photo' => $member['photo'],
            'account_status' => 'Approved',
            'membership_status' => 'Active',
            'is_official_member' => $is_official_member,
            'member_tier_label' => $member_tier_label,
            'floor_access' => $floor_access,
            'floor_label' => $floor_label,
            'plan_name' => $member['plan_name'] ?: 'Standard',
            'expiry_date' => $formatted_expiry,
            'time' => date('h:i A'),
            'date' => date('M d, Y'),
            'request_id' => $request_id,
            'message' => 'VALID MEMBER • Check-in Successful! Welcome, ' . $member['full_name'] . '.'
        ]);
        log_activity($pdo, 'Member Check-in', "Member {$member['full_name']} ({$member['membership_id']}) checked in.", 'Attendance');

    } catch (\Throwable $e) {
        if (isset($pdo) && $pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_details = [
            'request_id' => $request_id ?? 'REQ-UNKNOWN',
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        error_log("ATTENDANCE_SERVER_ERROR: " . json_encode($error_details));
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'status_type' => 'Server Error',
            'error_code' => 'ATTENDANCE_SERVER_ERROR',
            'request_id' => $request_id ?? 'REQ-UNKNOWN',
            'message' => 'A server error occurred while processing attendance. Ref: ' . ($request_id ?? 'REQ-UNKNOWN')
        ]);
    }
}
