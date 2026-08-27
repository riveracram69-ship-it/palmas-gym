<?php
require_once '../../config/auth.php';
require_once '../../config/db.php';

require_login();
require_admin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit;
}

$id       = intval($_POST['id'] ?? 0);
$name     = trim($_POST['name'] ?? '');
$months   = intval($_POST['duration_months'] ?? 0);
$price    = floatval($_POST['price'] ?? 0);
$benefits = trim($_POST['benefits'] ?? '');

if (!$name || $months < 1 || $price < 0) {
    echo json_encode(['success' => false, 'message' => 'Name, duration, and price are required.']); exit;
}

try {
    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE membership_plans SET name=?, duration_months=?, price=?, benefits=? WHERE id=?");
        $stmt->execute([$name, $months, $price, $benefits, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO membership_plans (name, duration_months, price, benefits) VALUES (?,?,?,?)");
        $stmt->execute([$name, $months, $price, $benefits]);
    }
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'A database error occurred.']);
}
