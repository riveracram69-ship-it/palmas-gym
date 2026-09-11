<?php
/**
 * api/payment_history.php
 * Authenticated Payment History API for Member Mobile Application
 *
 * Returns ALL payment records for the member, combining:
 *  - payment_transactions (PayMongo / online gateway records)
 *  - payments (registration fees, staff-entered cash/GCash payments)
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/cors.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth_middleware.php';

$member_id     = $auth_member_id;
$status_filter = strtoupper(trim($_GET['status'] ?? 'ALL'));
$page          = max(1, intval($_GET['page'] ?? 1));
$limit         = min(50, max(1, intval($_GET['limit'] ?? 25)));
$offset        = ($page - 1) * $limit;

try {
    // ── QUERY 1: Gateway / PayMongo transactions (payment_transactions) ──────────
    $gatewayRows = [];
    try {
        $gSql = "
            SELECT
                CONCAT('tx-', t.id) AS uid,
                t.reference_code    AS reference_id,
                COALESCE(p.name, 'Membership')  AS membership_plan,
                COALESCE(p.duration_months, 0)  AS duration_months,
                t.amount,
                t.currency,
                t.payment_method,
                COALESCE(t.gateway, 'PayMongo') AS gateway,
                COALESCE(t.gateway_transaction_id, 'N/A') AS gateway_tx_id,
                t.status,
                t.created_at,
                t.paid_at,
                s.start_date,
                s.expiry_date,
                'gateway' AS source
            FROM payment_transactions t
            LEFT JOIN membership_plans p ON p.id = t.plan_id
            LEFT JOIN subscriptions s   ON s.id = t.subscription_id
            WHERE t.member_id = ?
        ";
        $gParams = [$member_id];
        if ($status_filter !== 'ALL') {
            $allowed = ['PAID', 'PENDING', 'FAILED', 'CANCELLED', 'EXPIRED'];
            if (in_array($status_filter, $allowed, true)) {
                $gSql    .= " AND t.status = ?";
                $gParams[] = $status_filter;
            }
        }
        $gStmt = $pdo->prepare($gSql);
        $gStmt->execute($gParams);
        $gatewayRows = $gStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ge) {
        error_log("payment_history gateway query warn: " . $ge->getMessage());
    }

    // ── QUERY 2: Registration / staff-entered payments (payments table) ──────────
    // The `payments` table uses reference_no OR reference_number depending on schema version.
    $legacyRows = [];
    try {
        // Detect column names available in the payments table
        $payColsStmt = $pdo->query("SHOW COLUMNS FROM `payments`");
        $payCols     = array_column($payColsStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

        $refCol    = in_array('reference_no', $payCols)     ? 'py.reference_no'
                   : (in_array('reference_number', $payCols) ? 'py.reference_number' : 'NULL');
        $typeCol   = in_array('payment_type', $payCols) ? 'py.payment_type'
                   : (in_array('notes', $payCols)       ? 'py.notes' : "'Membership Payment'");

        $lSql = "
            SELECT
                CONCAT('pay-', py.id)  AS uid,
                COALESCE($refCol, CONCAT('PAY-', py.id)) AS reference_id,
                COALESCE(p.name, $typeCol, 'Membership Payment') AS membership_plan,
                COALESCE(p.duration_months, 0) AS duration_months,
                COALESCE(p.duration_minutes, 0) AS duration_minutes,
                py.amount,
                'PHP'                   AS currency,
                py.payment_method,
                'Staff / Registration'  AS gateway,
                'N/A'                   AS gateway_tx_id,
                'PAID'                  AS status,
                py.created_at,
                COALESCE(py.created_at, py.payment_date) AS paid_at,
                s.start_date,
                s.expiry_date,
                'legacy'                AS source
            FROM payments py
            LEFT JOIN subscriptions s ON s.id = py.subscription_id
            LEFT JOIN membership_plans p ON p.id = s.plan_id
            WHERE py.member_id = ?
        ";
        $lParams = [$member_id];
        // Legacy rows are always PAID; exclude for non-PAID/non-ALL filters
        if ($status_filter !== 'ALL' && $status_filter !== 'PAID') {
            $lSql .= " AND 1=0";
        }
        $lStmt = $pdo->prepare($lSql);
        $lStmt->execute($lParams);
        $legacyRows = $lStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $le) {
        error_log("payment_history legacy query warn: " . $le->getMessage());
    }

    // ── QUERY 3: Renewal & Pending Requests (renewal_requests table) ──────────
    // Captures all pending/rejected member renewal submissions (GCash, Maya, QR Ph, Cash)
    $renewalRows = [];
    try {
        $rSql = "
            SELECT
                CONCAT('req-', r.id) AS uid,
                COALESCE(r.reference_no, CONCAT('REQ-', r.id)) AS reference_id,
                COALESCE(p.name, 'Membership Renewal') AS membership_plan,
                COALESCE(p.duration_months, 0) AS duration_months,
                COALESCE(p.duration_minutes, 0) AS duration_minutes,
                COALESCE(p.price, 0) AS amount,
                'PHP' AS currency,
                r.payment_method,
                'Member Submission' AS gateway,
                'N/A' AS gateway_tx_id,
                CASE 
                    WHEN UPPER(r.status) = 'PENDING' THEN 'PENDING'
                    WHEN UPPER(r.status) = 'APPROVED' THEN 'PAID'
                    WHEN UPPER(r.status) = 'REJECTED' THEN 'FAILED'
                    ELSE UPPER(r.status)
                END AS status,
                r.created_at,
                NULL AS paid_at,
                NULL AS start_date,
                NULL AS expiry_date,
                'renewal_request' AS source
            FROM renewal_requests r
            LEFT JOIN membership_plans p ON p.id = r.plan_id
            WHERE r.member_id = ?
        ";
        // To prevent duplication with payments when already Approved:
        // Only fetch Pending and Rejected renewal requests, since Approved ones already have an entry in `payments`.
        $rSql .= " AND UPPER(r.status) IN ('PENDING', 'REJECTED')";
        $rParams = [$member_id];

        if ($status_filter !== 'ALL') {
            if ($status_filter === 'PENDING') {
                $rSql .= " AND UPPER(r.status) = 'PENDING'";
            } elseif ($status_filter === 'FAILED') {
                $rSql .= " AND UPPER(r.status) = 'REJECTED'";
            } else {
                // For PAID filter, excluded because approved requests are logged in payments table
                $rSql .= " AND 1=0";
            }
        }
        $rStmt = $pdo->prepare($rSql);
        $rStmt->execute($rParams);
        $renewalRows = $rStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $re) {
        error_log("payment_history renewal query warn: " . $re->getMessage());
    }

    // ── MERGE & SORT newest-first ────────────────────────────────────────────────
    $allRows = array_merge($gatewayRows, $legacyRows, $renewalRows);
    usort($allRows, function ($a, $b) {
        return strtotime($b['created_at'] ?? '0') <=> strtotime($a['created_at'] ?? '0');
    });

    $totalRecords = count($allRows);
    $pagedRows    = array_slice($allRows, $offset, $limit);

    // ── FORMAT ───────────────────────────────────────────────────────────────────
    $payments = [];
    foreach ($pagedRows as $r) {
        $createdAt = !empty($r['created_at']) ? new DateTime($r['created_at']) : new DateTime();
        $paidAt    = !empty($r['paid_at'])    ? new DateTime($r['paid_at'])    : null;

        $effectiveDate = (!empty($r['created_at']) && strpos($r['created_at'], ':') !== false) 
            ? $createdAt 
            : ($paidAt ?? $createdAt);

        $durMins = intval($r['duration_minutes'] ?? 0);
        $durMonths = intval($r['duration_months'] ?? 0);
        if ($durMins <= 0 && preg_match('/(\d+)\s*(?:min|minute)/i', $r['membership_plan'] ?? '', $pm)) {
            $durMins = intval($pm[1]);
        }
        $durLabel = ($durMins > 0) ? "{$durMins} Minute(s)" : (($durMonths > 0) ? "{$durMonths} Month(s)" : '—');

        $payments[] = [
            'id'               => $r['uid'],
            'reference_id'     => $r['reference_id'] ?: ('PAY-' . $r['uid']),
            'membership_plan'  => $r['membership_plan'] ?: 'Membership Payment',
            'duration'         => $durLabel,
            'amount'           => (float)$r['amount'],
            'amount_formatted' => '₱' . number_format((float)$r['amount'], 2),
            'currency'         => $r['currency'] ?: 'PHP',
            'payment_method'   => $r['payment_method'] ?: 'Cash',
            'gateway'          => $r['gateway'] ?: 'Staff / Registration',
            'gateway_tx_id'    => $r['gateway_tx_id'] ?: 'N/A',
            'status'           => strtoupper($r['status'] ?? 'PAID'),
            'is_paid'          => (strtoupper($r['status'] ?? 'PAID') === 'PAID'),
            'payment_date'     => $effectiveDate->format('F j, Y'),
            'payment_time'     => $effectiveDate->format('g:i A'),
            'created_at_iso'   => $createdAt->format('c'),
            'membership_start' => !empty($r['start_date'])  ? date('F j, Y', strtotime($r['start_date']))  : null,
            'membership_end'   => !empty($r['expiry_date']) ? date('F j, Y', strtotime($r['expiry_date'])) : null,
            'source'           => $r['source'] ?? 'gateway',
        ];
    }

    // ── SUMMARY: totals across all tables ───────────────────────────────────────
    $totalSpent        = 0;
    $totalPaidCount    = 0;
    $totalPendingCount = 0;
    try {
        $sumGateway = $pdo->prepare("SELECT COALESCE(SUM(amount),0), COUNT(*) FROM payment_transactions WHERE member_id = ? AND status = 'PAID'");
        $sumGateway->execute([$member_id]);
        [$gAmt, $gCnt] = $sumGateway->fetch(PDO::FETCH_NUM);

        $sumLegacy = $pdo->prepare("SELECT COALESCE(SUM(amount),0), COUNT(*) FROM payments WHERE member_id = ?");
        $sumLegacy->execute([$member_id]);
        [$lAmt, $lCnt] = $sumLegacy->fetch(PDO::FETCH_NUM);

        $totalSpent     = (float)$gAmt + (float)$lAmt;
        $totalPaidCount = (int)$gCnt   + (int)$lCnt;

        // Pending count across gateway and renewal requests
        $pGate = $pdo->prepare("SELECT COUNT(*) FROM payment_transactions WHERE member_id = ? AND status = 'PENDING'");
        $pGate->execute([$member_id]);
        $cntPGate = (int)$pGate->fetchColumn();

        $pRnw = $pdo->prepare("SELECT COUNT(*) FROM renewal_requests WHERE member_id = ? AND status = 'Pending'");
        $pRnw->execute([$member_id]);
        $cntPRnw = (int)$pRnw->fetchColumn();

        $totalPendingCount = $cntPGate + $cntPRnw;
    } catch (Throwable $se) {
        error_log("payment_history summary warn: " . $se->getMessage());
    }

    echo json_encode([
        'success'    => true,
        'payments'   => $payments,
        'pagination' => [
            'page'          => $page,
            'limit'         => $limit,
            'total_records' => $totalRecords,
            'total_pages'   => (int)ceil($totalRecords / $limit),
        ],
        'summary' => [
            'total_spent'           => $totalSpent,
            'total_spent_formatted' => '₱' . number_format($totalSpent, 2),
            'total_paid_count'      => $totalPaidCount,
            'total_pending_count'   => $totalPendingCount,
        ],
    ]);

} catch (Throwable $e) {
    error_log('API Error in payment_history.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to retrieve payment history.']);
}


