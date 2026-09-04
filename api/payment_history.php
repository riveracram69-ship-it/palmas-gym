<?php
/**
 * api/payment_history.php
 * Authenticated Payment History API for Member Mobile Application
 * 
 * Securely lists all payment transactions belonging exclusively to the authenticated member.
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
require_once __DIR__ . '/auth_middleware.php';

$member_id     = $auth_member_id;
$status_filter = strtoupper(trim($_GET['status'] ?? 'ALL'));
$page          = max(1, intval($_GET['page'] ?? 1));
$limit         = min(50, max(1, intval($_GET['limit'] ?? 15)));
$offset        = ($page - 1) * $limit;

try {
    // 1. Build Query with Parameter Binding
    $sql = "
        SELECT 
            t.id,
            t.reference_code,
            t.gateway_transaction_id,
            t.gateway,
            t.payment_method,
            t.amount,
            t.currency,
            t.status,
            t.created_at,
            t.paid_at,
            p.name AS plan_name,
            p.duration_months,
            s.start_date,
            s.expiry_date
        FROM payment_transactions t
        JOIN membership_plans p ON p.id = t.plan_id
        LEFT JOIN subscriptions s ON s.id = t.subscription_id
        WHERE t.member_id = :member_id
    ";

    $params = ['member_id' => $member_id];

    if ($status_filter !== 'ALL' && !empty($status_filter)) {
        $allowedStatuses = ['PAID', 'PENDING', 'FAILED', 'CANCELLED', 'EXPIRED'];
        if (in_array($status_filter, $allowedStatuses, true)) {
            $sql .= " AND t.status = :status";
            $params['status'] = $status_filter;
        }
    }

    $sql .= " ORDER BY t.created_at DESC LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':member_id', $member_id, PDO::PARAM_INT);
    if (isset($params['status'])) {
        $stmt->bindValue(':status', $params['status'], PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Count Total Matching Records
    $countSql = "SELECT COUNT(*) FROM payment_transactions WHERE member_id = :member_id";
    if (isset($params['status'])) {
        $countSql .= " AND status = :status";
    }
    $cStmt = $pdo->prepare($countSql);
    $cStmt->bindValue(':member_id', $member_id, PDO::PARAM_INT);
    if (isset($params['status'])) {
        $cStmt->bindValue(':status', $params['status'], PDO::PARAM_STR);
    }
    $cStmt->execute();
    $totalRecords = (int)$cStmt->fetchColumn();

    // 3. Format Response Items
    $payments = [];
    foreach ($rows as $r) {
        $dt = new DateTime($r['created_at']);
        $paidDt = $r['paid_at'] ? new DateTime($r['paid_at']) : null;

        $payments[] = [
            'id'                  => (int)$r['id'],
            'reference_id'        => $r['reference_code'],
            'membership_plan'     => $r['plan_name'],
            'duration'            => $r['duration_months'] . ' Month(s)',
            'amount'              => (float)$r['amount'],
            'amount_formatted'    => '₱' . number_format((float)$r['amount'], 2),
            'currency'            => $r['currency'] ?: 'PHP',
            'payment_method'      => $r['payment_method'],
            'gateway'             => $r['gateway'] ?: 'PayMongo',
            'gateway_tx_id'       => $r['gateway_transaction_id'] ?: 'N/A',
            'status'              => $r['status'],
            'is_paid'             => ($r['status'] === 'PAID'),
            'payment_date'        => $paidDt ? $paidDt->format('F j, Y') : $dt->format('F j, Y'),
            'payment_time'        => $paidDt ? $paidDt->format('g:i A') : $dt->format('g:i A'),
            'created_at_iso'      => $dt->format('c'),
            'membership_start'    => $r['start_date'] ? date('F j, Y', strtotime($r['start_date'])) : null,
            'membership_end'      => $r['expiry_date'] ? date('F j, Y', strtotime($r['expiry_date'])) : null
        ];
    }

    // 4. Lifetime Summary for this Member
    $sumStmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(amount), 0) AS total_spent,
            COUNT(*) AS total_paid
        FROM payment_transactions 
        WHERE member_id = ? AND status = 'PAID'
    ");
    $sumStmt->execute([$member_id]);
    $summary = $sumStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'    => true,
        'payments'   => $payments,
        'pagination' => [
            'page'          => $page,
            'limit'         => $limit,
            'total_records' => $totalRecords,
            'total_pages'   => ceil($totalRecords / $limit)
        ],
        'summary'    => [
            'total_spent'           => (float)$summary['total_spent'],
            'total_spent_formatted' => '₱' . number_format((float)$summary['total_spent'], 2),
            'total_paid_count'      => (int)$summary['total_paid']
        ]
    ]);

} catch (Throwable $e) {
    error_log('API Error in payment_history.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to retrieve payment history.']);
}
