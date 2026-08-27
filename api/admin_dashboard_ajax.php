<?php
/**
 * Admin Dashboard AJAX Endpoints
 * Extracted from index.php — Priority 14 Code Quality
 *
 * Handles:
 *   GET ?ajax=live_feed        → JSON activity feed
 *   GET ?action=send_reminder  → Send email reminder to a member
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/logger.php';
require_once __DIR__ . '/../config/email.php';
require_once __DIR__ . '/../config/settings.php';

require_login();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');

// ── 1. Live Activity Feed ──────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'live_feed') {
    $feed = [];

    try {
        // A. Check-ins & Check-outs (Last 12)
        $stmt = $pdo->query(
            "SELECT a.id, a.date, a.time_in, a.time_out, m.full_name, m.membership_id, m.photo
             FROM attendance a
             JOIN members m ON m.id = a.member_id
             ORDER BY a.date DESC, a.time_in DESC
             LIMIT 12"
        );
        foreach ($stmt->fetchAll() as $att) {
            $ts = strtotime($att['date'] . ' ' . $att['time_in']);
            $feed[] = [
                'type'          => 'checkin',
                'timestamp'     => $ts,
                'time_formatted'=> date('h:i A', $ts),
                'date_formatted'=> date('M d', $ts),
                'title'         => htmlspecialchars($att['full_name']) . ' checked in',
                'description'   => 'Scanned ID ' . htmlspecialchars($att['membership_id']) . ' at reception.',
                'icon'          => 'fa-qrcode',
                'color'         => '#38bdf8',
                'bg'            => 'rgba(56, 189, 248, 0.12)',
                'badge'         => 'Check-in',
            ];
            if (!empty($att['time_out'])) {
                $out = strtotime($att['date'] . ' ' . $att['time_out']);
                $feed[] = [
                    'type'          => 'checkout',
                    'timestamp'     => $out,
                    'time_formatted'=> date('h:i A', $out),
                    'date_formatted'=> date('M d', $out),
                    'title'         => htmlspecialchars($att['full_name']) . ' checked out',
                    'description'   => 'Completed gym session.',
                    'icon'          => 'fa-door-open',
                    'color'         => '#94a3b8',
                    'bg'            => 'rgba(148, 163, 184, 0.12)',
                    'badge'         => 'Check-out',
                ];
            }
        }

        // B. Payments (Recent 10 - Admin Only)
        if (is_admin()) {
            $stmt = $pdo->query(
                "SELECT p.id, p.payment_date, p.created_at, p.amount, p.payment_method, m.full_name, m.membership_id
                 FROM payments p
                 JOIN members m ON m.id = p.member_id
                 ORDER BY p.created_at DESC
                 LIMIT 10"
            );
            foreach ($stmt->fetchAll() as $pay) {
                $ts = strtotime($pay['created_at'] ?: $pay['payment_date']);
                $feed[] = [
                    'type'          => 'payment',
                    'timestamp'     => $ts,
                    'time_formatted'=> date('h:i A', $ts),
                    'date_formatted'=> date('M d', $ts),
                    'title'         => htmlspecialchars($pay['full_name']) . ' made a payment',
                    'description'   => 'Paid ₱' . number_format($pay['amount'], 2) . ' via ' . htmlspecialchars($pay['payment_method']) . '.',
                    'icon'          => 'fa-money-bill-wave',
                    'color'         => '#52b788',
                    'bg'            => 'rgba(82, 183, 136, 0.12)',
                    'badge'         => 'Payment',
                ];
            }
        }

        // C. Renewals & Subscriptions (Recent 8)
        $stmt = $pdo->query(
            "SELECT s.id, s.start_date, s.created_at, m.full_name, p.name as plan_name
             FROM subscriptions s
             JOIN members m ON m.id = s.member_id
             JOIN membership_plans p ON p.id = s.plan_id
             ORDER BY s.created_at DESC
             LIMIT 8"
        );
        foreach ($stmt->fetchAll() as $sub) {
            $ts = strtotime($sub['created_at'] ?: $sub['start_date']);
            $feed[] = [
                'type'          => 'renewal',
                'timestamp'     => $ts,
                'time_formatted'=> date('h:i A', $ts),
                'date_formatted'=> date('M d', $ts),
                'title'         => htmlspecialchars($sub['full_name']) . ' renewed membership',
                'description'   => 'Activated ' . htmlspecialchars($sub['plan_name']) . ' plan.',
                'icon'          => 'fa-arrows-rotate',
                'color'         => '#eab308',
                'bg'            => 'rgba(234, 179, 8, 0.12)',
                'badge'         => 'Renewal',
            ];
        }

        // D. New Registrations (Recent 8)
        $stmt = $pdo->query(
            "SELECT id, full_name, membership_id, created_at
             FROM members
             ORDER BY created_at DESC
             LIMIT 8"
        );
        foreach ($stmt->fetchAll() as $m_reg) {
            $ts = strtotime($m_reg['created_at']);
            $feed[] = [
                'type'          => 'registration',
                'timestamp'     => $ts,
                'time_formatted'=> date('h:i A', $ts),
                'date_formatted'=> date('M d', $ts),
                'title'         => htmlspecialchars($m_reg['full_name']) . ' registered as a new member',
                'description'   => 'Assigned Membership ID: ' . htmlspecialchars($m_reg['membership_id']) . '.',
                'icon'          => 'fa-user-plus',
                'color'         => '#c084fc',
                'bg'            => 'rgba(192, 132, 252, 0.12)',
                'badge'         => 'Registration',
            ];
        }

        // Sort descending, cap at 20, add relative time
        usort($feed, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
        $feed = array_slice($feed, 0, 20);
        $now  = time();
        foreach ($feed as &$item) {
            $diff = $now - $item['timestamp'];
            if ($diff < 60)         $item['relative_time'] = 'Just now';
            elseif ($diff < 3600)   $item['relative_time'] = floor($diff / 60) . ' mins ago';
            elseif ($diff < 86400)  $item['relative_time'] = floor($diff / 3600) . ' hrs ago';
            else                    $item['relative_time'] = floor($diff / 86400) . ' days ago';
        }
    } catch (Exception $e) {
        $feed = [];
    }

    echo json_encode($feed);
    exit;
}

// ── 2. Send Reminder ───────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'send_reminder') {
    $member_id = intval($_GET['member_id'] ?? 0);

    if ($member_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid member ID specified.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT id, full_name, email, membership_id FROM members WHERE id = ?");
        $stmt->execute([$member_id]);
        $target = $stmt->fetch();

        if (!$target) {
            echo json_encode(['success' => false, 'message' => 'Member not found.']);
            exit;
        }

        $gym_name = $app_settings['gym_name'] ?? "Palma's Elite Gym";
        $subject  = "We Miss You at {$gym_name}!";
        $title    = "Friendly Gym Reminder";
        $body     = "Hi " . htmlspecialchars($target['full_name']) . ",<br><br>"
                  . "We noticed you haven't visited {$gym_name} recently. "
                  . "Staying consistent is key to reaching your fitness goals! "
                  . "Drop by this week for a workout session or check your portal for plan status.";

        send_email_notification($target['email'], $subject, $title, $body);
        log_activity($pdo, 'Sent Member Reminder', "Sent reminder notification to {$target['full_name']} ({$target['membership_id']})", 'Member');

        echo json_encode(['success' => true, 'message' => 'Reminder successfully dispatched to ' . htmlspecialchars($target['full_name']) . '!']);
    } catch (Exception $e) {
        error_log('API Error in admin_dashboard_ajax.php reminder: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to send reminder. An internal server error occurred.']);
    }
    exit;
}

// Unrecognised action
http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
