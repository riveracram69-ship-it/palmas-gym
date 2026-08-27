<?php
require_once '../../config/auth.php';
require_once '../../config/db.php';
require_once '../../config/logger.php';

require_login();
require_admin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$id = intval($_POST['id']);
$action = trim($_POST['action'] ?? 'deactivate');

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid member ID.']);
    exit;
}

try {
    $u = current_user();
    if ($action === 'reactivate') {
        $stmt = $pdo->prepare("UPDATE members SET status = 'Active' WHERE id = ?");
        $stmt->execute([$id]);
        log_activity($pdo, 'Member Reactivation', "Reactivated member ID #{$id}", 'Members', $u['id'], $u['name']);
        echo json_encode(['success' => true, 'message' => 'Member successfully reactivated.']);
    } else {
        $stmt = $pdo->prepare("UPDATE members SET status = 'Inactive' WHERE id = ?");
        $stmt->execute([$id]);
        // Also expire any active subscriptions
        $pdo->prepare("UPDATE subscriptions SET expiry_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE member_id = ? AND expiry_date >= CURDATE()")->execute([$id]);
        log_activity($pdo, 'Member Deactivation', "Deactivated member ID #{$id}", 'Members', $u['id'], $u['name']);
        echo json_encode(['success' => true, 'message' => 'Member successfully deactivated.']);
    }
} catch (Exception $e) {
    error_log("Error updating member status: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred.']);
}

