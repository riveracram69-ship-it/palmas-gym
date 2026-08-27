<?php
require_once '../../config/db.php';
// require 'vendor/autoload.php'; // PHPMailer

/**
 * Check and send inactivity alerts
 */
function checkInactivity($pdo) {
    // Members with no attendance for 30 days
    $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
    
    $query = "SELECT m.id, m.full_name, m.email 
              FROM members m
              LEFT JOIN attendance a ON m.id = a.member_id AND a.date > ?
              WHERE a.id IS NULL AND m.status = 'Active'";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$thirtyDaysAgo]);
    $inactiveMembers = $stmt->fetchAll();

    foreach ($inactiveMembers as $member) {
        sendEmail($member['email'], "We miss you!", "Hi {$member['full_name']}, you haven't visited the gym for a while. We'd love to see you back!");
        logNotification($pdo, $member['id'], 'Inactivity');
    }
}

/**
 * Check and send expiration reminders
 */
function checkExpirations($pdo) {
    // Expiring in 3-5 days
    $threeDays = date('Y-m-d', strtotime('+3 days'));
    $fiveDays = date('Y-m-d', strtotime('+5 days'));

    $query = "SELECT m.id, m.full_name, m.email, s.expiry_date 
              FROM members m
              JOIN subscriptions s ON m.id = s.member_id
              WHERE s.expiry_date BETWEEN ? AND ?";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$threeDays, $fiveDays]);
    $expiringMembers = $stmt->fetchAll();

    foreach ($expiringMembers as $member) {
        sendEmail($member['email'], "Membership Expiring Soon", "Hi {$member['full_name']}, your membership expires on {$member['expiry_date']}. Renew now to keep your access!");
        logNotification($pdo, $member['id'], 'Expiration');
    }
}

function sendEmail($to, $subject, $body) {
    require_once __DIR__ . '/../../config/email.php';
    send_email_notification($to, $subject, $subject, $body);
}

function logNotification($pdo, $member_id, $type, $title = '', $body = '') {
    require_once __DIR__ . '/../../config/logger.php';
    require_once __DIR__ . '/../../config/notifications.php';
    try {
        $stmt = $pdo->prepare("SELECT full_name, membership_id FROM members WHERE id = ?");
        $stmt->execute([$member_id]);
        $m = $stmt->fetch();
        if ($m) {
            $notif_title = !empty($title) ? $title : "{$type} Alert";
            create_notification($pdo, (int)$member_id, $type, $notif_title, $body, 'Sent');
            log_activity($pdo, 'Notification Sent', "System {$type} notification sent to {$m['full_name']} ({$m['membership_id']})", 'Notification');
        }
    } catch (Exception $e) {
        error_log("Error in check_notifications.php logNotification: " . $e->getMessage());
    }
}

// Run checks
if ($pdo) {
    checkInactivity($pdo);
    checkExpirations($pdo);
}
?>
