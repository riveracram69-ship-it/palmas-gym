<?php
/**
 * api/check_status.php
 * Authenticated Real-Time Payment Status & Verification API + Browser Return Handler
 * 
 * Securely verifies payment status against the database and on-demand payment gateway API (PayMongo).
 * Supports:
 * - JSON API response for mobile app polling (with or without Bearer token)
 * - Branded HTML return page when redirected from PayMongo / gateway checkout
 * - Instant on-demand auto-activation when PayMongo confirms payment
 */

require_once __DIR__ . '/cors.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/payment.php';
require_once __DIR__ . '/../config/paymongo.php';

// ── MEMBER REGISTRATION APPROVAL STATUS CHECK ──────────────────────
// Supports mobile app polling when a user is on the "Registration Pending" screen
$raw_input = file_get_contents('php://input');
$input_data = json_decode($raw_input, true) ?: $_POST;
$identifier = trim($input_data['identifier'] ?? $_GET['identifier'] ?? '');

if (!empty($identifier)) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $stmt = $pdo->prepare("
            SELECT id, membership_id, full_name, email, contact_number, account_status, status, rejection_reason
            FROM members 
            WHERE email = ? OR membership_id = ? OR contact_number = ?
            LIMIT 1
        ");
        $stmt->execute([$identifier, $identifier, $identifier]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($member) {
            echo json_encode([
                'success'          => true,
                'account_status'   => $member['account_status'] ?? 'Pending',
                'status'           => $member['status'] ?? 'Inactive',
                'membership_id'    => $member['membership_id'],
                'email'            => $member['email'],
                'full_name'        => $member['full_name'],
                'rejection_reason' => $member['rejection_reason'] ?? null
            ]);
            exit;
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'No registration record found for this email or Member ID.'
            ]);
            exit;
        }
    } catch (Throwable $e) {
        error_log('check_status identifier lookup error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error checking status.']);
        exit;
    }
}

// ── PAYMENT TRANSACTION STATUS CHECK ──────────────────────────────
$ref = trim($_GET['ref'] ?? $_GET['reference'] ?? $input_data['ref'] ?? $input_data['reference'] ?? '');

if (empty($ref)) {
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') || isset($_GET['status'])) {
        header('Content-Type: text/html; charset=utf-8');
        echo "<h1>Invalid Request</h1><p>Missing transaction reference code.</p>";
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Missing transaction reference code.']);
    exit;
}

// Optional Bearer Authentication check
$headers = function_exists('apache_request_headers')
    ? apache_request_headers()
    : (function_exists('getallheaders') ? getallheaders() : []);

$authHeader = $headers['Authorization']
    ?? $headers['authorization']
    ?? $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? null;

$auth_member_id = null;
if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', trim($authHeader), $matches)) {
    try {
        $tokenStmt = $pdo->prepare("
            SELECT t.member_id 
            FROM auth_tokens t
            WHERE t.token = ? AND t.expires_at > NOW()
            LIMIT 1
        ");
        $tokenStmt->execute([$matches[1]]);
        $auth_member_id = (int)$tokenStmt->fetchColumn() ?: null;
    } catch (Throwable $e) {}
}

try {
    // 1. Fetch transaction (by reference_code + optional member_id filter)
    if ($auth_member_id) {
        $stmt = $pdo->prepare("
            SELECT 
                t.id, t.member_id, t.plan_id, t.subscription_id, t.reference_code,
                t.gateway_transaction_id, t.gateway, t.payment_method, t.amount,
                t.currency, t.status, t.created_at, t.paid_at, t.expires_at,
                p.name AS plan_name, p.duration_months, p.duration_minutes, p.is_test_promo,
                s.expiry_date AS subscription_expiry, m.full_name, m.membership_id
            FROM payment_transactions t
            JOIN membership_plans p ON p.id = t.plan_id
            JOIN members m ON m.id = t.member_id
            LEFT JOIN subscriptions s ON s.id = t.subscription_id
            WHERE t.reference_code = ? AND t.member_id = ?
            LIMIT 1
        ");
        $stmt->execute([$ref, $auth_member_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                t.id, t.member_id, t.plan_id, t.subscription_id, t.reference_code,
                t.gateway_transaction_id, t.gateway, t.payment_method, t.amount,
                t.currency, t.status, t.created_at, t.paid_at, t.expires_at,
                p.name AS plan_name, p.duration_months, p.duration_minutes, p.is_test_promo,
                s.expiry_date AS subscription_expiry, m.full_name, m.membership_id
            FROM payment_transactions t
            JOIN membership_plans p ON p.id = t.plan_id
            JOIN members m ON m.id = t.member_id
            LEFT JOIN subscriptions s ON s.id = t.subscription_id
            WHERE t.reference_code = ?
            LIMIT 1
        ");
        $stmt->execute([$ref]);
    }
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tx) {
        http_response_code(404);
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') || isset($_GET['status'])) {
            header('Content-Type: text/html; charset=utf-8');
            echo "<h1>Transaction Not Found</h1><p>We could not locate transaction reference: " . htmlspecialchars($ref) . "</p>";
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Transaction not found or unauthorized access.'
        ]);
        exit;
    }

    // 2. Check if expired
    if ($tx['status'] === 'PENDING' && !empty($tx['expires_at'])) {
        if (strtotime($tx['expires_at']) < time()) {
            $tx['status'] = 'EXPIRED';
            $pdo->prepare("UPDATE payment_transactions SET status = 'EXPIRED' WHERE id = ?")->execute([$tx['id']]);
        }
    }

    // 3. Live On-Demand Gateway Verification (for PENDING transactions)
    if ($tx['status'] === 'PENDING' && !empty($tx['gateway_transaction_id'])) {
        $paymentMode = get_payment_mode();
        
        if ($paymentMode === 'live' || PayMongoGateway::isConfigured()) {
            $session = PayMongoGateway::getCheckoutSession($tx['gateway_transaction_id']);
            
            if ($session && isset($session['attributes']['status'])) {
                $sessionStatus = $session['attributes']['status'];
                
                // If PayMongo confirms payment is completed
                if ($sessionStatus === 'paid' || !empty($session['attributes']['payments'])) {
                    $hasPaidPayment = false;
                    foreach ($session['attributes']['payments'] ?? [] as $payItem) {
                        if (($payItem['attributes']['status'] ?? '') === 'paid') {
                            $hasPaidPayment = true;
                            break;
                        }
                    }

                    if ($hasPaidPayment || $sessionStatus === 'paid') {
                        // Trigger idempotent activation within single transaction
                        $pdo->beginTransaction();
                        $lockStmt = $pdo->prepare("SELECT status FROM payment_transactions WHERE id = ? FOR UPDATE");
                        $lockStmt->execute([$tx['id']]);
                        $currentStatus = $lockStmt->fetchColumn();

                        if ($currentStatus !== 'PAID') {
                            $act = process_automated_subscription_activation(
                                $pdo,
                                (int)$tx['member_id'],
                                (int)$tx['plan_id'],
                                (float)$tx['amount'],
                                $tx['payment_method'],
                                $tx['reference_code'],
                                true // Single transaction boundary
                            );

                            if ($act['success']) {
                                $pdo->prepare("UPDATE payment_transactions SET status = 'PAID', paid_at = NOW(), subscription_id = ? WHERE id = ?")
                                    ->execute([$act['subscription_id'] ?? null, $tx['id']]);
                                $pdo->commit();
                                $tx['status']  = 'PAID';
                                $tx['paid_at'] = date('Y-m-d H:i:s');
                                $tx['subscription_expiry'] = $act['expiry_date'] ?? null;
                            } else {
                                $pdo->rollBack();
                            }
                        } else {
                            $pdo->commit();
                            $tx['status'] = 'PAID';
                        }
                    }
                } elseif ($sessionStatus === 'cancelled' || $sessionStatus === 'expired') {
                    $newStatus = strtoupper($sessionStatus);
                    $pdo->prepare("UPDATE payment_transactions SET status = ? WHERE id = ?")->execute([$newStatus, $tx['id']]);
                    $tx['status'] = $newStatus;
                }
            }
        }
    }

    // 4. Fetch latest subscription expiry if active
    $validUntil = $tx['subscription_expiry'];
    if (empty($validUntil)) {
        $subStmt = $pdo->prepare("SELECT expiry_date FROM subscriptions WHERE member_id = ? AND expiry_date >= NOW() ORDER BY expiry_date DESC LIMIT 1");
        $subStmt->execute([$tx['member_id']]);
        $validUntil = $subStmt->fetchColumn() ?: null;
    }

    $is_minute_promo = (!empty($tx['duration_minutes']) && (int)$tx['duration_minutes'] > 0);
    $duration_label = $is_minute_promo ? ($tx['duration_minutes'] . ' Minute(s)') : ($tx['duration_months'] . ' Month(s)');
    $formatted_valid_until = $validUntil ? ($is_minute_promo ? date('F j, Y, g:i A', strtotime($validUntil)) : date('F j, Y', strtotime($validUntil))) : null;

    // If browser redirect from PayMongo or status URL requested, render branded HTML receipt
    $isHtmlRequest = isset($_GET['status']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html');
    if ($isHtmlRequest) {
        header('Content-Type: text/html; charset=utf-8');
        $isPaid = ($tx['status'] === 'PAID');
        $isCancelled = ($tx['status'] === 'CANCELLED');
        $statusColor = $isPaid ? '#3E8241' : ($isCancelled ? '#F06A6A' : '#F4C95D');
        $statusTitle = $isPaid ? 'Payment Successful!' : ($isCancelled ? 'Payment Cancelled' : 'Payment Processing');
        $statusIcon = $isPaid ? 'fa-check' : ($isCancelled ? 'fa-xmark' : 'fa-hourglass-half');
        $appUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : '..';
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
            <title><?= htmlspecialchars($statusTitle) ?> — Palma's Elite Gym</title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
            <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body {
                    font-family: 'Inter', sans-serif;
                    background: #082119;
                    color: #F4FFF9;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                .receipt-card {
                    background: #123B2D;
                    border: 1px solid #205743;
                    border-radius: 24px;
                    padding: 32px 24px;
                    max-width: 440px;
                    width: 100%;
                    text-align: center;
                    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.4);
                }
                .icon-circle {
                    width: 72px;
                    height: 72px;
                    border-radius: 50%;
                    background: <?= $isPaid ? 'rgba(62, 130, 65, 0.2)' : ($isCancelled ? 'rgba(240, 106, 106, 0.2)' : 'rgba(244, 201, 93, 0.2)') ?>;
                    border: 2px solid <?= $statusColor ?>;
                    color: <?= $statusColor ?>;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 32px;
                    margin: 0 auto 18px;
                }
                h1 { font-family: 'Outfit', sans-serif; font-size: 24px; font-weight: 800; margin-bottom: 6px; }
                .sub-text { font-size: 14px; color: #B2D8C7; margin-bottom: 24px; }
                .info-box {
                    background: #0D2E23;
                    border: 1px solid #205743;
                    border-radius: 16px;
                    padding: 16px;
                    margin-bottom: 24px;
                    text-align: left;
                }
                .info-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 8px 0;
                    border-bottom: 1px solid rgba(82, 183, 136, 0.15);
                    font-size: 13.5px;
                }
                .info-row:last-child { border-bottom: none; }
                .info-row .lbl { color: #7EAA96; }
                .info-row .val { font-weight: 600; color: #F4FFF9; text-align: right; }
                .amount-val { font-size: 18px; font-weight: 800; color: #52B788; font-family: 'Outfit', sans-serif; }
                .btn-action {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                    width: 100%;
                    height: 52px;
                    background: linear-gradient(135deg, #3E8241 0%, #2D6A4F 100%);
                    color: #fff;
                    text-decoration: none;
                    border-radius: 14px;
                    font-family: 'Outfit', sans-serif;
                    font-size: 16px;
                    font-weight: 800;
                    margin-bottom: 10px;
                    border: 1px solid rgba(82, 183, 136, 0.35);
                    box-shadow: 0 4px 18px rgba(62, 130, 65, 0.35);
                }
                .btn-secondary {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                    width: 100%;
                    height: 48px;
                    background: transparent;
                    color: #B2D8C7;
                    text-decoration: none;
                    border-radius: 14px;
                    font-size: 14px;
                    font-weight: 600;
                    border: 1px solid #205743;
                }
            </style>
        </head>
        <body>
            <div class="receipt-card">
                <div class="icon-circle">
                    <i class="fa-solid <?= $statusIcon ?>"></i>
                </div>
                <?php if (!empty($tx['is_test'])): ?>
                <div style="background:rgba(59, 130, 246, 0.15); border:1px solid rgba(59, 130, 246, 0.4); border-radius:10px; padding:8px 12px; margin-bottom:16px; font-size:12px; color:#93C5FD; display:flex; align-items:center; justify-content:center; gap:6px;">
                    <i class="fa-solid fa-flask"></i> <strong>TEST MODE:</strong> Simulated transaction. No real money was charged.
                </div>
                <?php endif; ?>
                <h1><?= htmlspecialchars($statusTitle) ?></h1>
                <p class="sub-text">
                    <?= $isPaid ? 'Your membership has been activated and is ready to use.' : ($isCancelled ? 'The checkout session was cancelled.' : 'Please wait while we confirm your payment.') ?>
                </p>

                <div class="info-box">
                    <div class="info-row">
                        <span class="lbl">Reference Number</span>
                        <span class="val" style="font-family:monospace;"><?= htmlspecialchars($tx['reference_code']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="lbl">Member</span>
                        <span class="val"><?= htmlspecialchars($tx['full_name'] ?? 'Member') ?></span>
                    </div>
                    <div class="info-row">
                        <span class="lbl">Plan Selected</span>
                        <span class="val"><?= htmlspecialchars($tx['plan_name']) ?> (<?= htmlspecialchars($duration_label) ?>)</span>
                    </div>
                    <div class="info-row">
                        <span class="lbl">Amount</span>
                        <span class="val amount-val">₱<?= number_format((float)$tx['amount'], 2) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="lbl">Payment Method</span>
                        <span class="val"><?= htmlspecialchars($tx['payment_method']) ?> (<?= htmlspecialchars($tx['gateway']) ?>)</span>
                    </div>
                    <?php if ($formatted_valid_until): ?>
                    <div class="info-row">
                        <span class="lbl">Valid Until</span>
                        <span class="val" style="color:#52B788;"><?= htmlspecialchars($formatted_valid_until) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <a href="<?= htmlspecialchars($appUrl) ?>/member/index.php" class="btn-action">
                    <i class="fa-solid fa-id-card"></i> VIEW DIGITAL PASS
                </a>
                <button type="button" id="btn-return-app" onclick="returnToMobileApp()" class="btn-secondary" style="cursor:pointer; width:100%;">
                    <i class="fa-solid fa-mobile-screen"></i> Return to Mobile App
                </button>
                <div id="auto-return-status" style="margin-top:12px; font-size:12px; color:#7EAA96; min-height:18px;"></div>
            </div>

            <script>
            function returnToMobileApp() {
                var ref = <?= json_encode($tx['reference_code']) ?>;
                var status = <?= json_encode($tx['status']) ?>;
                var deepScheme = "palmasgym://checkout/result?ref=" + encodeURIComponent(ref) + "&status=" + encodeURIComponent(status);
                var androidIntent = "intent://checkout/result?ref=" + encodeURIComponent(ref) + "&status=" + encodeURIComponent(status) + "#Intent;scheme=palmasgym;package=com.palmaselite.gymmember;end;";
                var webFallback = <?= json_encode(htmlspecialchars($appUrl) . '/member/index.php') ?>;

                var btn = document.getElementById('btn-return-app');
                if (btn) {
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Returning to app...';
                }

                // If opened as popup/tab with opener, post message and close
                try {
                    if (window.opener && !window.opener.closed) {
                        window.opener.postMessage({ type: 'PALMAS_PAYMENT_RESULT', ref: ref, status: status }, '*');
                        window.close();
                        return;
                    }
                } catch(e) {}

                var isAndroid = /Android/i.test(navigator.userAgent);
                var isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);

                if (isAndroid) {
                    // Try Android Intent syntax first (native handling for Chrome Custom Tabs)
                    window.location.href = androidIntent;
                    setTimeout(function() {
                        window.location.href = deepScheme;
                    }, 500);
                } else if (isMobile) {
                    window.location.href = deepScheme;
                } else {
                    // Desktop browser fallback
                    window.location.href = webFallback;
                }
            }

            // Auto-trigger return countdown on mobile devices
            (function() {
                var isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
                var statusEl = document.getElementById('auto-return-status');
                if (!isMobile || !statusEl) return;

                var seconds = 3;
                statusEl.textContent = 'Auto-returning to mobile app in ' + seconds + 's...';
                var countdownInterval = setInterval(function() {
                    seconds--;
                    if (seconds > 0) {
                        statusEl.textContent = 'Auto-returning to mobile app in ' + seconds + 's...';
                    } else {
                        clearInterval(countdownInterval);
                        statusEl.textContent = 'Returning to app...';
                        returnToMobileApp();
                    }
                }, 1000);
            })();
            </script>
        </body>
        </html>
        <?php
        exit;
    }

    // 5. Standard JSON API Response
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'          => true,
        'status'           => $tx['status'],
        'reference_code'   => $tx['reference_code'],
        'plan_name'        => $tx['plan_name'],
        'duration'         => $duration_label,
        'is_test_promo'    => ((int)($tx['is_test_promo'] ?? 0) === 1),
        'amount'           => (float)$tx['amount'],
        'amount_formatted' => '₱' . number_format((float)$tx['amount'], 2),
        'payment_method'   => $tx['payment_method'],
        'gateway'          => $tx['gateway'],
        'valid_until'      => $formatted_valid_until,
        'created_at'       => date('F j, Y, g:i A', strtotime($tx['created_at'])),
        'paid_at'          => $tx['paid_at'] ? date('F j, Y, g:i A', strtotime($tx['paid_at'])) : null,
        'is_paid'          => ($tx['status'] === 'PAID')
    ]);

} catch (Throwable $e) {
    error_log('API Error in check_status.php: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unable to check transaction status.']);
}
