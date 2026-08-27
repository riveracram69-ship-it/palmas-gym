<?php
require_once '../../config/auth.php';
require_once '../../config/db.php';

require_login();
require_admin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit;
}

$id = intval($_POST['id']);
try {
    $pdo->prepare("DELETE FROM membership_plans WHERE id=?")->execute([$id]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'A database error occurred.']);
}
