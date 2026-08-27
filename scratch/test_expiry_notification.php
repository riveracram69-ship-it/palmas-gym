<?php
/**
 * TEST EXPIRY NOTIFICATION SCRIPT
 * Inserts an "Expiration" notification for the first member after a 1-minute delay.
 * Run via browser: http://localhost/gym/scratch/test_expiry_notification.php
 */

require_once __DIR__ . '/../config/db.php';

$member_id = intval($_GET['member_id'] ?? 0);
$delay     = intval($_GET['delay'] ?? 60); // seconds, default 60 (1 min)

// Fetch all members for the selection form
$members = [];
try {
    $members = $pdo->query("SELECT m.id, m.full_name, m.membership_id, s.expiry_date
                             FROM members m
                             LEFT JOIN subscriptions s ON s.member_id = m.id AND s.status = 'Active'
                             ORDER BY m.full_name")->fetchAll();
} catch(Exception $e) {}

// If countdown reached zero and member selected → insert notification
$fired = false;
if (isset($_GET['fire']) && $member_id > 0) {
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (member_id, type, sent_at) VALUES (?, 'Expiration', NOW())");
        $stmt->execute([$member_id]);
        $fired = true;

        // Also update the member status to Expired for realism
        $pdo->prepare("UPDATE subscriptions SET status='Expired' WHERE member_id=? AND status='Active'")->execute([$member_id]);
        $pdo->prepare("UPDATE members SET status='Inactive' WHERE id=?")->execute([$member_id]);
    } catch(Exception $e) {
        die("DB Error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔔 Test Expiry Notification</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Outfit', sans-serif;
            background: #080e0b;
            color: #f0f7f3;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .container {
            background: #131f18;
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 24px;
            padding: 2.5rem;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 30px 70px rgba(0,0,0,0.5);
            text-align: center;
        }
        .icon-wrap {
            width: 80px; height: 80px;
            border-radius: 50%;
            background: rgba(211,47,47,0.1);
            border: 2px solid rgba(211,47,47,0.2);
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1.5rem;
            color: #ff6b6b;
        }
        h1 { font-size: 1.5rem; font-weight: 800; margin-bottom: 0.4rem; }
        .sub { font-size: 0.85rem; color: #8faaa0; margin-bottom: 2rem; line-height: 1.5; }
        select, .delay-input {
            width: 100%;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 0.85rem 1rem;
            color: #f0f7f3;
            font-size: 0.9rem;
            font-family: 'Outfit', sans-serif;
            margin-bottom: 1rem;
        }
        select option { background: #131f18; }
        .label {
            font-size: 0.72rem;
            font-weight: 700;
            color: #8faaa0;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            text-align: left;
            display: block;
            margin-bottom: 0.4rem;
        }
        .btn {
            width: 100%;
            padding: 0.9rem;
            background: linear-gradient(135deg, #40916c, #2d6a4f);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            font-family: 'Outfit', sans-serif;
            transition: all 0.2s;
            margin-top: 0.5rem;
        }
        .btn:hover { background: linear-gradient(135deg, #52b788, #40916c); }
        .btn.danger {
            background: linear-gradient(135deg, #c62828, #b71c1c);
            margin-top: 0;
        }
        .btn.danger:hover { background: linear-gradient(135deg, #ef5350, #c62828); }

        /* Countdown display */
        .countdown-box {
            background: rgba(211,47,47,0.08);
            border: 1px solid rgba(211,47,47,0.2);
            border-radius: 16px;
            padding: 2rem;
            margin: 1.5rem 0;
        }
        .countdown-timer {
            font-size: 4rem;
            font-weight: 800;
            color: #ff6b6b;
            line-height: 1;
            font-variant-numeric: tabular-nums;
        }
        .countdown-label {
            font-size: 0.75rem;
            color: #8faaa0;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 0.4rem;
        }
        .member-info {
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            text-align: left;
            font-size: 0.85rem;
        }
        .member-info strong { color: #52b788; }

        /* Success screen */
        .success-icon {
            width: 80px; height: 80px;
            border-radius: 50%;
            background: rgba(46,125,50,0.12);
            border: 2px solid rgba(82,183,136,0.3);
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1.5rem;
            color: #52b788;
        }
        .notification-preview {
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.06);
            border-left: 3px solid #ff6b6b;
            border-radius: 12px;
            padding: 1rem 1.25rem;
            margin: 1.5rem 0;
            text-align: left;
        }
        .notification-preview .notif-header {
            display: flex; align-items: center; gap: 0.5rem;
            font-size: 0.8rem; font-weight: 700; color: #ff6b6b;
            margin-bottom: 0.4rem;
        }
        .notification-preview .notif-body {
            font-size: 0.85rem; color: #8faaa0; line-height: 1.5;
        }
        .link-btn {
            display: inline-flex; align-items: center; gap: 0.5rem;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 10px;
            padding: 0.65rem 1.25rem;
            color: #f0f7f3;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
            margin-top: 1rem;
        }
        .link-btn:hover { background: rgba(255,255,255,0.08); }
    </style>
</head>
<body>

<?php if ($fired): 
    // Find member name
    $m = $pdo->prepare("SELECT full_name, membership_id FROM members WHERE id=?");
    $m->execute([$member_id]);
    $mdata = $m->fetch();
?>
<!-- ✅ SUCCESS STATE -->
<div class="container">
    <div class="success-icon"><i class="fas fa-bell"></i></div>
    <h1>Notification Fired! 🎉</h1>
    <p class="sub">The expiry notification has been logged in the system successfully.</p>

    <div class="notification-preview">
        <div class="notif-header">
            <i class="fas fa-calendar-xmark"></i>
            EXPIRATION ALERT
        </div>
        <div class="notif-body">
            <strong style="color:#f0f7f3;"><?php echo htmlspecialchars($mdata['full_name'] ?? '—'); ?></strong>
            (<?php echo htmlspecialchars($mdata['membership_id'] ?? '—'); ?>) — membership has <strong style="color:#ff6b6b;">expired</strong>. Member status updated to Inactive.
        </div>
    </div>

    <a href="/gym/notifications.php" class="link-btn" style="width:100%; justify-content:center;">
        <i class="fas fa-bell"></i> View in Notifications Panel
    </a>
    <a href="/gym/members.php" class="link-btn" style="width:100%; justify-content:center; margin-top:0.5rem;">
        <i class="fas fa-users"></i> View Members
    </a>
    <a href="test_expiry_notification.php" class="link-btn" style="width:100%; justify-content:center; margin-top:0.5rem;">
        <i class="fas fa-rotate-left"></i> Run Another Test
    </a>
</div>

<?php elseif ($member_id > 0 && !$fired):
    $sel = $pdo->prepare("SELECT m.full_name, m.membership_id, s.expiry_date FROM members m LEFT JOIN subscriptions s ON s.member_id=m.id WHERE m.id=?");
    $sel->execute([$member_id]);
    $seldata = $sel->fetch();
?>
<!-- ⏳ COUNTDOWN STATE -->
<div class="container">
    <div class="icon-wrap"><i class="fas fa-hourglass-half"></i></div>
    <h1>Countdown Active</h1>
    <p class="sub">The expiry notification will fire automatically when the timer hits zero.</p>

    <div class="member-info">
        👤 <strong><?php echo htmlspecialchars($seldata['full_name'] ?? '—'); ?></strong>
        &nbsp;·&nbsp; <?php echo htmlspecialchars($seldata['membership_id'] ?? '—'); ?>
        <?php if ($seldata['expiry_date']): ?>
        <br>📅 Expiry: <?php echo date('M d, Y', strtotime($seldata['expiry_date'])); ?>
        <?php endif; ?>
    </div>

    <div class="countdown-box">
        <div class="countdown-timer" id="timer"><?php echo $delay; ?></div>
        <div class="countdown-label">seconds until notification fires</div>
    </div>

    <a href="?member_id=<?php echo $member_id; ?>&delay=<?php echo $delay; ?>&fire=1" id="fire-link" style="display:none;"></a>
    <button class="btn danger" onclick="cancelTest()">
        <i class="fas fa-xmark"></i> Cancel Test
    </button>
</div>

<script>
let seconds = <?php echo $delay; ?>;
const timer = document.getElementById('timer');
const fireLink = document.getElementById('fire-link');

const interval = setInterval(() => {
    seconds--;
    timer.textContent = seconds;

    if (seconds <= 5) {
        timer.style.color = '#ff4444';
        document.querySelector('.countdown-box').style.background = 'rgba(211,47,47,0.15)';
    }

    if (seconds <= 0) {
        clearInterval(interval);
        timer.textContent = '🔔';
        fireLink.click(); // Auto-redirect to fire the notification
    }
}, 1000);

function cancelTest() {
    clearInterval(interval);
    window.location.href = 'test_expiry_notification.php';
}
</script>

<?php else: ?>
<!-- 🔧 SETUP STATE -->
<div class="container">
    <div class="icon-wrap"><i class="fas fa-bell"></i></div>
    <h1>Test Expiry Notification</h1>
    <p class="sub">Select a member and set the timer. The system will automatically fire an expiry notification after the countdown ends.</p>

    <form method="GET" action="">
        <label class="label">Select Member</label>
        <select name="member_id" required>
            <option value="">— Choose a member —</option>
            <?php foreach ($members as $m): ?>
            <option value="<?php echo $m['id']; ?>">
                <?php echo htmlspecialchars($m['full_name']); ?> 
                (<?php echo htmlspecialchars($m['membership_id']); ?>)
                <?php echo $m['expiry_date'] ? ' · Expires: ' . date('M d, Y', strtotime($m['expiry_date'])) : ' · No active plan'; ?>
            </option>
            <?php endforeach; ?>
        </select>

        <label class="label">Delay (seconds)</label>
        <input type="number" name="delay" class="delay-input" value="60" min="5" max="300" required>
        <p style="font-size:0.72rem; color:#4d6b5e; text-align:left; margin-top:-0.5rem; margin-bottom:1rem;">
            60 = 1 minute · 5 = instant test · 300 = 5 minutes
        </p>

        <button type="submit" class="btn">
            <i class="fas fa-play"></i> Start Countdown
        </button>
    </form>
</div>
<?php endif; ?>

</body>
</html>
