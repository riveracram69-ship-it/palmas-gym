<?php
/**
 * payment-testing.php — Admin Payment Testing & Gateway Simulation Dashboard
 * 
 * Provides a secure, admin-only test center for:
 * - Simulating ₱1 test payments (30-min & 60-min promos)
 * - Testing DEMO / TEST / LIVE payment modes
 * - Viewing webhook logs and notification audit trails
 * - Running maintenance worker on demand
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/env.php';

// Require Admin session
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

$payment_mode = get_payment_mode();
$mode_badge = [
    'demo' => ['label' => 'DEMO MODE', 'color' => '#f59e0b', 'icon' => '🟡'],
    'test' => ['label' => 'TEST MODE', 'color' => '#3b82f6', 'icon' => '🔵'],
    'live' => ['label' => 'LIVE MODE', 'color' => '#22c55e', 'icon' => '🟢'],
][$payment_mode] ?? ['label' => 'UNKNOWN', 'color' => '#ef4444', 'icon' => '🔴'];

// Fetch test promo plans
$test_plans = [];
try {
    $tp_stmt = $pdo->query("SELECT id, name, price, duration_minutes, duration_months, promo_code FROM membership_plans WHERE is_test_promo = 1 ORDER BY duration_minutes ASC");
    $test_plans = $tp_stmt ? $tp_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {}

// Fetch recent test transactions
$recent_tx = [];
try {
    $rtx_stmt = $pdo->query("
        SELECT t.id, t.reference_code, t.status, t.amount, t.payment_method, t.is_test, t.created_at, t.paid_at,
               m.full_name, m.membership_id,
               p.name as plan_name, p.duration_minutes
        FROM payment_transactions t
        JOIN members m ON m.id = t.member_id
        JOIN membership_plans p ON p.id = t.plan_id
        WHERE t.is_test = 1
        ORDER BY t.created_at DESC
        LIMIT 20
    ");
    $recent_tx = $rtx_stmt ? $rtx_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {}

// Fetch recent notifications (last 15)
$recent_notifs = [];
try {
    $rn_stmt = $pdo->query("
        SELECT n.id, n.title, n.notification_type, n.stage, n.read_status, n.sent_at,
               m.full_name, m.membership_id
        FROM notifications n
        JOIN members m ON m.id = n.member_id
        ORDER BY n.sent_at DESC
        LIMIT 15
    ");
    $recent_notifs = $rn_stmt ? $rn_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {}

// Fetch member count for select
$members_list = [];
try {
    $ml_stmt = $pdo->query("SELECT id, full_name, membership_id FROM members ORDER BY full_name ASC LIMIT 100");
    $members_list = $ml_stmt ? $ml_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Testing Dashboard — Palma's Elite Gym</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg: #0a0f1e;
            --surface: #0f1729;
            --surface2: #151f38;
            --border: #1e2d4a;
            --text: #e2e8f0;
            --muted: #64748b;
            --accent: #3b82f6;
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --demo-color: #f59e0b;
            --test-color: #3b82f6;
            --live-color: #22c55e;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--bg); color: var(--text); font-family: 'Inter', sans-serif; min-height: 100vh; }
        .page-header {
            background: linear-gradient(135deg, #0f1729 0%, #1a2540 100%);
            border-bottom: 1px solid var(--border);
            padding: 1.25rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .page-header h1 { font-size: 1.3rem; font-weight: 700; color: #fff; }
        .page-header h1 span { color: var(--accent); }
        .mode-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.9rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            border: 2px solid;
            font-family: 'JetBrains Mono', monospace;
        }
        .back-link {
            color: var(--muted);
            text-decoration: none;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            transition: color 0.2s;
        }
        .back-link:hover { color: var(--accent); }
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1.5rem; }
        @media (max-width: 900px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.5rem;
        }
        .card-header {
            display: flex; align-items: center; gap: 0.75rem;
            margin-bottom: 1.25rem; padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }
        .card-header h2 { font-size: 1rem; font-weight: 600; }
        .card-header .icon { font-size: 1.2rem; }
        .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        @media (max-width: 700px) { .stat-grid { grid-template-columns: repeat(2, 1fr); } }
        .stat-card {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1.1rem 1rem;
            text-align: center;
        }
        .stat-card .val { font-size: 1.8rem; font-weight: 700; font-family: 'JetBrains Mono', monospace; }
        .stat-card .label { font-size: 0.72rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 0.2rem; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; font-size: 0.8rem; font-weight: 500; color: var(--muted); margin-bottom: 0.4rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .form-group select, .form-group input {
            width: 100%; background: var(--surface2); border: 1px solid var(--border);
            border-radius: 8px; padding: 0.6rem 0.9rem; color: var(--text);
            font-size: 0.9rem; font-family: 'Inter', sans-serif;
            transition: border-color 0.2s;
        }
        .form-group select:focus, .form-group input:focus {
            outline: none; border-color: var(--accent);
        }
        .btn {
            display: inline-flex; align-items: center; gap: 0.5rem;
            padding: 0.6rem 1.2rem; border-radius: 8px; border: none;
            font-size: 0.875rem; font-weight: 600; cursor: pointer;
            transition: all 0.2s; text-decoration: none; font-family: 'Inter', sans-serif;
        }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-primary:hover { background: #2563eb; }
        .btn-success { background: var(--success); color: #fff; }
        .btn-success:hover { background: #16a34a; }
        .btn-warning { background: var(--warning); color: #000; }
        .btn-warning:hover { background: #d97706; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-ghost { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }
        .btn-ghost:hover { border-color: var(--accent); color: var(--accent); }
        .btn-full { width: 100%; justify-content: center; }
        .badge {
            display: inline-block; padding: 0.2rem 0.55rem; border-radius: 20px;
            font-size: 0.7rem; font-weight: 700; font-family: 'JetBrains Mono', monospace;
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        .badge-paid { background: rgba(34,197,94,0.15); color: var(--success); border: 1px solid rgba(34,197,94,0.3); }
        .badge-pending { background: rgba(245,158,11,0.15); color: var(--warning); border: 1px solid rgba(245,158,11,0.3); }
        .badge-failed { background: rgba(239,68,68,0.15); color: var(--danger); border: 1px solid rgba(239,68,68,0.3); }
        .badge-cancelled, .badge-expired { background: rgba(100,116,139,0.15); color: var(--muted); border: 1px solid rgba(100,116,139,0.3); }
        .badge-test { background: rgba(59,130,246,0.15); color: var(--accent); border: 1px solid rgba(59,130,246,0.3); }
        table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        th { text-align: left; padding: 0.6rem 0.8rem; color: var(--muted); font-weight: 600; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; border-bottom: 1px solid var(--border); }
        td { padding: 0.7rem 0.8rem; border-bottom: 1px solid rgba(30,45,74,0.5); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(59,130,246,0.04); }
        .mono { font-family: 'JetBrains Mono', monospace; font-size: 0.78rem; }
        .result-box {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.8rem;
            line-height: 1.6;
            min-height: 80px;
            max-height: 300px;
            overflow-y: auto;
            white-space: pre-wrap;
            color: var(--muted);
            margin-top: 1rem;
        }
        .result-box.success { border-color: rgba(34,197,94,0.4); color: var(--success); }
        .result-box.error   { border-color: rgba(239,68,68,0.4); color: var(--danger); }
        .result-box.info    { border-color: rgba(59,130,246,0.4); color: #93c5fd; }
        .section-title {
            font-size: 0.7rem; font-weight: 700; color: var(--muted);
            text-transform: uppercase; letter-spacing: 0.1em;
            margin-bottom: 1rem;
        }
        .plan-card {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.75rem;
            transition: border-color 0.2s;
        }
        .plan-card:hover { border-color: var(--accent); }
        .plan-info .name { font-weight: 600; font-size: 0.9rem; }
        .plan-info .meta { font-size: 0.75rem; color: var(--muted); margin-top: 0.2rem; }
        .info-alert {
            background: rgba(59,130,246,0.1);
            border: 1px solid rgba(59,130,246,0.3);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.82rem;
            color: #93c5fd;
            margin-bottom: 1rem;
            display: flex;
            gap: 0.5rem;
            align-items: flex-start;
        }
        .warning-alert {
            background: rgba(245,158,11,0.1);
            border: 1px solid rgba(245,158,11,0.3);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.82rem;
            color: #fbbf24;
            margin-bottom: 1rem;
        }
        .spinner { animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<div class="page-header">
    <div style="display:flex;align-items:center;gap:1rem;">
        <a href="index.php" class="back-link"><i class="fa fa-arrow-left"></i> Dashboard</a>
        <h1>Payment <span>Testing</span> Dashboard</h1>
    </div>
    <div style="display:flex;align-items:center;gap:0.75rem;">
        <div class="mode-badge" style="color:<?= $mode_badge['color'] ?>;border-color:<?= $mode_badge['color'] ?>;">
            <?= $mode_badge['icon'] ?> <?= $mode_badge['label'] ?>
        </div>
        <span style="font-size:0.78rem;color:var(--muted);">PAYMENT_MODE = <code><?= htmlspecialchars($payment_mode) ?></code></span>
    </div>
</div>

<div class="container">

    <?php if ($payment_mode === 'live'): ?>
    <div class="warning-alert">
        <strong>⚠️ LIVE MODE ACTIVE:</strong> Any transactions created here will use real GCash/PayMongo payments and charge real money. Switch PAYMENT_MODE=test or PAYMENT_MODE=demo in .env for safe testing.
    </div>
    <?php elseif ($payment_mode === 'demo'): ?>
    <div class="info-alert">
        <i class="fa fa-flask"></i>
        <span><strong>DEMO MODE:</strong> All payments are fully simulated offline. No network calls to PayMongo. Gateway success/failure is triggered by this dashboard's simulate buttons.</span>
    </div>
    <?php else: ?>
    <div class="info-alert">
        <i class="fa fa-vial"></i>
        <span><strong>TEST MODE:</strong> Transactions use PayMongo Sandbox (pk_test_... / sk_test_...) with real network calls but no real money. Perfect for ₱1 GCash sandbox testing.</span>
    </div>
    <?php endif; ?>

    <!-- Stats Row -->
    <?php
    $stats = ['total_test_tx' => 0, 'paid_tx' => 0, 'failed_tx' => 0, 'pending_tx' => 0];
    try {
        $s = $pdo->query("SELECT status, COUNT(*) as c FROM payment_transactions WHERE is_test = 1 GROUP BY status");
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $stats['total_test_tx'] += $r['c'];
            if ($r['status'] === 'PAID') $stats['paid_tx'] = $r['c'];
            if ($r['status'] === 'FAILED') $stats['failed_tx'] = $r['c'];
            if ($r['status'] === 'PENDING') $stats['pending_tx'] = $r['c'];
        }
    } catch (Exception $e) {}
    ?>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="val" style="color:var(--accent)"><?= $stats['total_test_tx'] ?></div>
            <div class="label">Total Test Transactions</div>
        </div>
        <div class="stat-card">
            <div class="val" style="color:var(--success)"><?= $stats['paid_tx'] ?></div>
            <div class="label">Paid (Activated)</div>
        </div>
        <div class="stat-card">
            <div class="val" style="color:var(--warning)"><?= $stats['pending_tx'] ?></div>
            <div class="label">Pending</div>
        </div>
        <div class="stat-card">
            <div class="val" style="color:var(--danger)"><?= $stats['failed_tx'] ?></div>
            <div class="label">Failed</div>
        </div>
    </div>

    <div class="grid-2" style="margin-bottom:1.5rem;">

        <!-- Simulate Payment -->
        <div class="card">
            <div class="card-header">
                <span class="icon">⚡</span>
                <h2>Simulate Payment</h2>
            </div>

            <div class="form-group">
                <label>Member</label>
                <select id="sim_member_id">
                    <option value="">-- Select Member --</option>
                    <?php foreach ($members_list as $ml): ?>
                    <option value="<?= $ml['id'] ?>"><?= htmlspecialchars($ml['full_name']) ?> (<?= htmlspecialchars($ml['membership_id']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Test Plan</label>
                <select id="sim_plan_id">
                    <option value="">-- Select Plan --</option>
                    <?php foreach ($test_plans as $tp): ?>
                    <option value="<?= $tp['id'] ?>" data-mins="<?= $tp['duration_minutes'] ?>" data-price="<?= $tp['price'] ?>">
                        <?= htmlspecialchars($tp['name']) ?> — ₱<?= number_format($tp['price'], 2) ?>
                        (<?= $tp['duration_minutes'] ?> min)
                    </option>
                    <?php endforeach; ?>
                    <?php if (empty($test_plans)): ?>
                    <option disabled>Run migrate_clean_unified.php first to seed test plans</option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Simulate Outcome</label>
                <select id="sim_outcome">
                    <option value="PAID">✅ Payment Successful (PAID)</option>
                    <option value="FAILED">❌ Payment Failed (FAILED)</option>
                    <option value="CANCELLED">🚫 Payment Cancelled (CANCELLED)</option>
                </select>
            </div>

            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                <button class="btn btn-success" id="btn_simulate" onclick="simulatePayment()">
                    <i class="fa fa-play"></i> Run Simulation
                </button>
                <button class="btn btn-ghost" onclick="clearResult('sim_result')">
                    <i class="fa fa-eraser"></i> Clear
                </button>
            </div>
            <div id="sim_result" class="result-box">Ready. Select a member and plan above to simulate a payment.</div>
        </div>

        <!-- Run Maintenance Worker -->
        <div class="card">
            <div class="card-header">
                <span class="icon">⚙️</span>
                <h2>Maintenance Worker</h2>
            </div>
            <p style="font-size:0.85rem;color:var(--muted);margin-bottom:1.25rem;">
                Run the cron maintenance worker on-demand. This processes member status sync, minute-level promo expiry alerts, and standard expiration notifications.
            </p>
            <div class="warning-alert" style="margin-bottom:1rem;">
                <strong>Recommended:</strong> Run every 5 minutes via cron for promo memberships.<br>
                <code style="font-size:0.75rem;">*/5 * * * * php /path/to/gym/cron/daily_maintenance.php</code>
            </div>
            <button class="btn btn-warning btn-full" id="btn_cron" onclick="runMaintenance()">
                <i class="fa fa-gear"></i> Run Maintenance Worker Now
            </button>
            <div id="cron_result" class="result-box">Output will appear here after running...</div>
        </div>

    </div>

    <!-- Recent Test Transactions -->
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header">
            <span class="icon">💳</span>
            <h2>Recent Test Transactions</h2>
            <span class="badge badge-test" style="margin-left:auto;">is_test = 1</span>
        </div>
        <?php if (empty($recent_tx)): ?>
        <p style="color:var(--muted);font-size:0.85rem;text-align:center;padding:2rem 0;">No test transactions yet. Simulate a payment above!</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Member</th>
                    <th>Plan</th>
                    <th>Duration</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Paid At</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_tx as $t): ?>
                <tr>
                    <td class="mono"><?= htmlspecialchars($t['reference_code']) ?></td>
                    <td><?= htmlspecialchars($t['full_name']) ?> <span style="color:var(--muted);font-size:0.75rem;">(<?= htmlspecialchars($t['membership_id']) ?>)</span></td>
                    <td><?= htmlspecialchars($t['plan_name']) ?></td>
                    <td><?= $t['duration_minutes'] > 0 ? $t['duration_minutes'] . ' min' : 'N/A' ?></td>
                    <td class="mono">₱<?= number_format($t['amount'], 2) ?></td>
                    <td><span class="badge badge-<?= strtolower($t['status']) ?>"><?= $t['status'] ?></span></td>
                    <td style="color:var(--muted);font-size:0.78rem;"><?= date('M d, g:i A', strtotime($t['created_at'])) ?></td>
                    <td style="color:var(--muted);font-size:0.78rem;"><?= $t['paid_at'] ? date('M d, g:i A', strtotime($t['paid_at'])) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Recent Notifications Audit -->
    <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header">
            <span class="icon">🔔</span>
            <h2>Notification Audit Log</h2>
            <button class="btn btn-ghost" style="margin-left:auto;font-size:0.78rem;padding:0.3rem 0.75rem;" onclick="location.reload()">
                <i class="fa fa-refresh"></i> Refresh
            </button>
        </div>
        <?php if (empty($recent_notifs)): ?>
        <p style="color:var(--muted);font-size:0.85rem;text-align:center;padding:2rem 0;">No notifications in the log yet.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr><th>Member</th><th>Title</th><th>Type</th><th>Stage</th><th>Status</th><th>Sent At</th></tr>
            </thead>
            <tbody>
                <?php foreach ($recent_notifs as $n): ?>
                <tr>
                    <td><?= htmlspecialchars($n['full_name']) ?> <span style="color:var(--muted);font-size:0.75rem;">(<?= htmlspecialchars($n['membership_id']) ?>)</span></td>
                    <td><?= htmlspecialchars($n['title']) ?></td>
                    <td class="mono" style="font-size:0.72rem;color:var(--muted);"><?= htmlspecialchars($n['notification_type'] ?? '') ?></td>
                    <td class="mono" style="font-size:0.72rem;color:var(--accent);"><?= htmlspecialchars($n['stage'] ?? '—') ?></td>
                    <td><span class="badge" style="<?= $n['read_status'] === 'Read' ? 'background:rgba(34,197,94,0.1);color:var(--success);border:1px solid rgba(34,197,94,0.3)' : 'background:rgba(245,158,11,0.1);color:var(--warning);border:1px solid rgba(245,158,11,0.3)' ?>"><?= $n['read_status'] ?></span></td>
                    <td style="color:var(--muted);font-size:0.78rem;"><?= date('M d, g:i A', strtotime($n['sent_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Quick Links -->
    <div class="card">
        <div class="card-header">
            <span class="icon">🔗</span>
            <h2>Quick Links</h2>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:0.75rem;">
            <a href="migrate_clean_unified.php" class="btn btn-ghost" target="_blank"><i class="fa fa-database"></i> Run Unified Migration</a>
            <a href="payments.php" class="btn btn-ghost"><i class="fa fa-receipt"></i> Payments Ledger</a>
            <a href="members.php" class="btn btn-ghost"><i class="fa fa-users"></i> Members</a>
            <a href="notifications.php" class="btn btn-ghost"><i class="fa fa-bell"></i> Notifications</a>
            <a href="reports.php" class="btn btn-ghost"><i class="fa fa-chart-bar"></i> Reports</a>
            <a href="verify_workflow.php" class="btn btn-ghost" target="_blank"><i class="fa fa-check-circle"></i> Verify Workflow</a>
        </div>
    </div>

</div>

<script>
async function simulatePayment() {
    const member_id = document.getElementById('sim_member_id').value;
    const plan_id   = document.getElementById('sim_plan_id').value;
    const outcome   = document.getElementById('sim_outcome').value;
    const resultBox = document.getElementById('sim_result');

    if (!member_id || !plan_id) {
        resultBox.className = 'result-box error';
        resultBox.textContent = '⚠️ Please select both a member and a test plan.';
        return;
    }

    resultBox.className = 'result-box info';
    resultBox.textContent = '⏳ Initiating payment simulation...';
    document.getElementById('btn_simulate').disabled = true;

    try {
        // Step 1: Create checkout (transaction)
        const checkoutRes = await fetch('api/create_payment_checkout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Member-ID': member_id },
            body: JSON.stringify({ member_id, plan_id, payment_method: 'GCASH' })
        });
        const checkout = await checkoutRes.json();
        resultBox.textContent = '📝 Checkout created:\n' + JSON.stringify(checkout, null, 2);

        if (!checkout.success && !checkout.reference_code) {
            resultBox.className = 'result-box error';
            return;
        }

        const ref = checkout.reference_code || checkout.data?.reference_code;
        if (!ref) {
            resultBox.className = 'result-box error';
            resultBox.textContent += '\n\n❌ No reference_code returned from checkout.';
            return;
        }

        resultBox.textContent += '\n\n⏳ Sending webhook simulation...';

        // Step 2: Simulate webhook
        const webhookPayload = {
            data: {
                type: 'checkout_session',
                attributes: {
                    type: outcome === 'PAID' ? 'checkout_session.payment.paid' : 'payment.failed',
                    data: {
                        attributes: {
                            status: outcome === 'PAID' ? 'paid' : 'failed',
                            reference_number: ref,
                            amount: parseInt((checkout.amount || 1) * 100)
                        }
                    }
                }
            },
            reference_code: ref,
            status: outcome
        };

        const webhookRes = await fetch('api/payment_webhook.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Demo-Simulation': 'palmas_demo_sandbox'
            },
            body: JSON.stringify(webhookPayload)
        });
        const webhook = await webhookRes.json();

        const allDone = '📝 Checkout:\n' + JSON.stringify(checkout, null, 2) +
                       '\n\n🔔 Webhook Response:\n' + JSON.stringify(webhook, null, 2);
        resultBox.textContent = allDone;
        resultBox.className = webhook.success ? 'result-box success' : 'result-box error';

        if (webhook.success) {
            setTimeout(() => location.reload(), 3000);
        }

    } catch (err) {
        resultBox.className = 'result-box error';
        resultBox.textContent = '❌ Fetch error: ' + err.message;
    } finally {
        document.getElementById('btn_simulate').disabled = false;
    }
}

async function runMaintenance() {
    const resultBox = document.getElementById('cron_result');
    resultBox.className = 'result-box info';
    resultBox.textContent = '⏳ Running maintenance worker...';
    document.getElementById('btn_cron').disabled = true;

    try {
        const key = '<?= htmlspecialchars(defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : 'palmas_cron_secret_2026') ?>';
        const res = await fetch(`cron/daily_maintenance.php?key=${encodeURIComponent(key)}`);
        const text = await res.text();
        // Strip HTML if returned
        const stripped = text.replace(/<[^>]+>/g, '').replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>').trim();
        resultBox.textContent = stripped || '✅ Maintenance worker completed (no output captured).';
        resultBox.className = 'result-box success';
    } catch (err) {
        resultBox.className = 'result-box error';
        resultBox.textContent = '❌ Error: ' + err.message;
    } finally {
        document.getElementById('btn_cron').disabled = false;
    }
}

function clearResult(id) {
    const el = document.getElementById(id);
    el.className = 'result-box';
    el.textContent = 'Cleared.';
}
</script>

</body>
</html>
