<?php
require_once __DIR__ . '/auth.php';
require_member_login();

$member = current_member($pdo);
if (!$member) { header('Location: logout.php'); exit; }

// Fetch all payments
$payments = [];
try {
    $s = $pdo->prepare("SELECT * FROM payments WHERE member_id = ? ORDER BY payment_date DESC, created_at DESC");
    $s->execute([$member['id']]);
    $payments = $s->fetchAll();
} catch (Exception $e) {}

$total_paid  = array_sum(array_column($payments, 'amount'));
$pay_count   = count($payments);
$last_payment = $payments[0] ?? null;

$method_icons = [
    'Cash'         => ['icon' => 'fa-money-bill', 'color' => '#52b788', 'bg' => 'rgba(46,125,50,0.1)'],
    'GCash'        => ['icon' => 'fa-mobile-screen', 'color' => '#60a5fa', 'bg' => 'rgba(59,130,246,0.1)'],
    'Credit Card'  => ['icon' => 'fa-credit-card', 'color' => '#a78bfa', 'bg' => 'rgba(139,92,246,0.1)'],
    'Bank Transfer'=> ['icon' => 'fa-building-columns', 'color' => '#fbbf24', 'bg' => 'rgba(251,191,36,0.1)'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Payments | <?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></title>
    <link rel="stylesheet" href="../assets/css/member.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .total-banner {
            background: linear-gradient(145deg, #1b4332 0%, #0a2218 60%, #060f0a 100%);
            border-radius: 22px;
            padding: 1.75rem;
            border: 1px solid rgba(82,183,136,0.15);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .total-banner::before {
            content: '';
            position: absolute;
            top: -50px; right: -50px;
            width: 160px; height: 160px;
            background: radial-gradient(circle, rgba(82,183,136,0.1) 0%, transparent 70%);
        }

        .total-label {
            font-size: 0.72rem;
            color: rgba(255,255,255,0.45);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .total-amount {
            font-family: 'Outfit', sans-serif;
            font-size: 2.6rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -1px;
            line-height: 1;
        }

        .total-sub {
            font-size: 0.75rem;
            color: rgba(255,255,255,0.4);
            margin-top: 0.4rem;
        }

        .payment-amount-success {
            color: #52b788;
            font-weight: 700;
        }

        .method-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 100px;
            font-size: 0.65rem;
            font-weight: 700;
        }
    </style>
    <link rel="manifest" href="manifest.json">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Palma's Elite">
    <link rel="apple-touch-icon" href="../assets/images/palmas-logo.png">
</head>
<body>
<div class="mobile-container">

    <!-- Header -->
    <header class="app-header">
        <div class="app-brand">
            <img src="../assets/images/palmas-logo.png" alt="Logo">
            <h1>Payments</h1>
        </div>
        <div class="header-actions">
            <a href="logout.php" class="header-icon-btn danger" title="Sign Out">
                <i class="fas fa-right-from-bracket"></i>
            </a>
        </div>
    </header>

    <main class="app-content">

        <!-- Total Banner -->
        <div class="total-banner fade-up">
            <p class="total-label">Total Amount Paid</p>
            <p class="total-amount">₱<?php echo number_format($total_paid, 2); ?></p>
            <p class="total-sub"><?php echo $pay_count; ?> payment<?php echo $pay_count !== 1 ? 's' : ''; ?> recorded</p>
        </div>

        <!-- Quick Stats -->
        <div class="stats-row fade-up fade-up-d1">
            <div class="stat-card">
                <div class="stat-icon gold"><i class="fas fa-receipt"></i></div>
                <div class="stat-value"><?php echo $pay_count; ?></div>
                <div class="stat-label">Transactions</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-calendar-day"></i></div>
                <div class="stat-value" style="font-size:0.95rem;">
                    <?php echo $last_payment ? date('M d', strtotime($last_payment['payment_date'])) : '—'; ?>
                </div>
                <div class="stat-label">Last Payment</div>
            </div>
        </div>

        <!-- Payment History -->
        <div class="list-card fade-up fade-up-d2">
            <div class="list-card-header">
                <p class="section-title"><i class="fas fa-clock-rotate-left"></i> History</p>
                <?php if ($pay_count > 0): ?>
                    <span class="badge badge-success"><?php echo $pay_count; ?> records</span>
                <?php endif; ?>
            </div>

            <?php if (empty($payments)): ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <p>No payment records found.<br>Your history will appear here after your first payment.</p>
                </div>
            <?php else: ?>
                <?php foreach ($payments as $p):
                    $method  = $p['payment_method'] ?? 'Cash';
                    $mdata   = $method_icons[$method] ?? $method_icons['Cash'];
                ?>
                <div class="list-item">
                    <div class="list-item-icon" style="background:<?php echo $mdata['bg']; ?>; color:<?php echo $mdata['color']; ?>;">
                        <i class="fas <?php echo $mdata['icon']; ?>"></i>
                    </div>
                    <div class="list-item-info">
                        <div class="list-item-title">
                            <?php echo htmlspecialchars($p['notes'] ?: 'Membership Payment'); ?>
                        </div>
                        <div class="list-item-sub" style="margin-top:4px;">
                            <span class="method-badge" style="background:<?php echo $mdata['bg']; ?>; color:<?php echo $mdata['color']; ?>;">
                                <i class="fas <?php echo $mdata['icon']; ?>" style="font-size:0.55rem;"></i>
                                <?php echo htmlspecialchars($method); ?>
                            </span>
                        </div>
                    </div>
                    <div class="list-item-right">
                        <div class="list-item-value payment-amount-success">
                            ₱<?php echo number_format($p['amount'], 2); ?>
                        </div>
                        <div class="list-item-date">
                            <?php echo date('M d, Y', strtotime($p['payment_date'])); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </main>

    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="index.php" class="nav-item">
            <i class="fas fa-house"></i><span>Home</span>
        </a>
        <a href="id-card.php" class="nav-item">
            <i class="fas fa-id-card"></i><span>E-ID</span>
        </a>
        <a href="attendance.php" class="nav-item">
            <i class="fas fa-calendar-check"></i><span>Visits</span>
        </a>
        <a href="payments.php" class="nav-item active">
            <i class="fas fa-receipt"></i><span>Payments</span>
        </a>
        <a href="profile.php" class="nav-item">
            <i class="fas fa-user"></i><span>Profile</span>
        </a>
    </nav>
</div>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js');
    });
}
</script>
</body>
</html>
