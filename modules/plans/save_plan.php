<?php
require_once '../../config/auth.php';
require_once '../../config/db.php';

require_login();
require_admin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit;
}

$id           = intval($_POST['id'] ?? 0);
$name         = trim($_POST['name'] ?? '');
$months       = intval($_POST['duration_months'] ?? 0);
$price        = floatval($_POST['price'] ?? 0);
$benefits     = trim($_POST['benefits'] ?? '');
$plan_category = trim($_POST['plan_category'] ?? 'member_pass');
$floor_access  = trim($_POST['floor_access'] ?? 'all');

// Validate category and floor_access values
$valid_categories = ['membership_fee', 'member_pass', 'non_member_pass', 'test_promo'];
$valid_floors     = ['all', 'second_floor_only', 'ground_and_second'];
if (!in_array($plan_category, $valid_categories)) $plan_category = 'member_pass';
if (!in_array($floor_access, $valid_floors))     $floor_access = 'all';

if (!$name || $months < 0 || $price < 0) {
    echo json_encode(['success' => false, 'message' => 'Name, duration, and price are required.']); exit;
}

// Daily pass: duration_months = 0, duration_minutes = 1440
$duration_minutes = 0;
if ($months === 0) {
    // Treat as daily pass
    $duration_minutes = 1440;
}

try {
    if ($id > 0) {
        // Guard: do not allow editing legacy plans (IDs 1–4 are preserved)
        $check = $pdo->prepare("SELECT plan_category FROM membership_plans WHERE id = ?");
        $check->execute([$id]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        if ($existing && $existing['plan_category'] === 'legacy') {
            echo json_encode(['success' => false, 'message' => 'Legacy plans cannot be edited to preserve historical record integrity.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE membership_plans SET name=?, duration_months=?, duration_minutes=?, price=?, benefits=?, plan_category=?, floor_access=? WHERE id=?");
        $stmt->execute([$name, $months, $duration_minutes, $price, $benefits, $plan_category, $floor_access, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO membership_plans (name, duration_months, duration_minutes, price, benefits, plan_category, floor_access, is_active) VALUES (?,?,?,?,?,?,?,1)");
        $stmt->execute([$name, $months, $duration_minutes, $price, $benefits, $plan_category, $floor_access]);
    }
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'A database error occurred.']);
}
