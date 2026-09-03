<?php
/**
 * api/get_plans.php — Public Endpoint for Active Membership Plans
 * Allows mobile app registration wizard and public views to fetch plans dynamically.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';

try {
    $stmt = $pdo->query("
        SELECT id, name, price, duration_months, benefits 
        FROM membership_plans 
        ORDER BY price ASC
    ");
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'plans' => $plans]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'plans' => [], 'message' => 'Unable to fetch plans.']);
}
