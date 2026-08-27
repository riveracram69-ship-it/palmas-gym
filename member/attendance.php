<?php
require_once __DIR__ . '/auth.php';
require_member_login();

$member = current_member($pdo);
if (!$member) { header('Location: /gym/member/logout.php'); exit; }

// Fetch all attendance records
$attendance_records = [];
try {
    $s = $pdo->prepare("SELECT * FROM attendance WHERE member_id = ? ORDER BY date DESC");
    $s->execute([$member['id']]);
    $attendance_records = $s->fetchAll();
} catch (Exception $e) {}

// Stats
$total_visits   = count($attendance_records);
$this_month     = 0;
$this_week      = 0;
$current_month  = date('Y-m');
$week_start     = date('Y-m-d', strtotime('monday this week'));

foreach ($attendance_records as $rec) {
    if (substr($rec['date'], 0, 7) === $current_month) $this_month++;
    if ($rec['date'] >= $week_start) $this_week++;
}

// Build last 28 days calendar
$calendar_days = [];
for ($i = 27; $i >= 0; $i--) {
    $calendar_days[] = date('Y-m-d', strtotime("-{$i} days"));
}

$attendance_dates = array_column($attendance_records, 'date');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Attendance | <?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></title>
    <link rel="stylesheet" href="/gym/assets/css/member.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
            margin-top: 0.75rem;
        }

        .cal-day-label {
            text-align: center;
            font-size: 0.58rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding-bottom: 4px;
        }

        .cal-day {
            aspect-ratio: 1;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.62rem;
            font-weight: 600;
            cursor: default;
        }

        .cal-day.present {
            background: rgba(46,125,50,0.15);
            color: #52b788;
            border: 1px solid rgba(82,183,136,0.25);
        }

        .cal-day.absent {
            background: rgba(255,255,255,0.02);
            color: var(--text-muted);
            border: 1px solid var(--border);
        }

        .cal-day.today {
            background: linear-gradient(135deg, #2d6a4f, #1b4332);
            color: #fff;
            border: none;
            box-shadow: 0 4px 12px rgba(45,106,79,0.3);
        }

        .cal-legend {
            display: flex;
            gap: 1rem;
            margin-top: 0.75rem;
            justify-content: flex-end;
        }

        .cal-legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.65rem;
            color: var(--text-muted);
        }

        .cal-legend-dot {
            width: 10px; height: 10px;
            border-radius: 3px;
        }

        .streak-banner {
            background: linear-gradient(135deg, rgba(212,169,66,0.1), rgba(240,201,110,0.06));
            border: 1px solid rgba(212,169,66,0.2);
            border-radius: 16px;
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .streak-icon {
            font-size: 1.8rem;
        }

        .streak-count {
            font-family: 'Outfit', sans-serif;
            font-size: 2rem;
            font-weight: 800;
            color: #d4a942;
            line-height: 1;
        }

        .streak-label {
            font-size: 0.72rem;
            color: var(--text-secondary);
            margin-top: 2px;
        }
    </style>
    <link rel="manifest" href="/gym/member/manifest.json">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Palma's Elite">
    <link rel="apple-touch-icon" href="/gym/assets/images/palmas-logo.png">
</head>
<body>
<div class="mobile-container">

    <!-- Header -->
    <header class="app-header">
        <div class="app-brand">
            <img src="/gym/assets/images/palmas-logo.png" alt="Logo">
            <h1>Attendance</h1>
        </div>
        <div class="header-actions">
            <a href="logout.php" class="header-icon-btn danger" title="Sign Out">
                <i class="fas fa-right-from-bracket"></i>
            </a>
        </div>
    </header>

    <main class="app-content">

        <!-- Stats Row -->
        <div class="stats-row fade-up">
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-trophy"></i></div>
                <div class="stat-value"><?php echo $total_visits; ?></div>
                <div class="stat-label">Total Visits</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple" style="background:rgba(139,92,246,0.1); color:#a78bfa;">
                    <i class="fas fa-calendar-week"></i>
                </div>
                <div class="stat-value"><?php echo $this_month; ?></div>
                <div class="stat-label">This Month</div>
            </div>
        </div>

        <!-- This week streak -->
        <div class="streak-banner fade-up fade-up-d1">
            <div class="streak-icon">🔥</div>
            <div>
                <div class="streak-count"><?php echo $this_week; ?></div>
                <div class="streak-label">visits this week — keep it up!</div>
            </div>
        </div>

        <!-- 28-Day Calendar Heatmap -->
        <div class="list-card fade-up fade-up-d2">
            <div class="list-card-header">
                <p class="section-title"><i class="fas fa-calendar-days"></i> Last 28 Days</p>
            </div>
            <div style="padding: 0.5rem 1.25rem 1.25rem;">
                <!-- Day labels -->
                <div class="cal-grid">
                    <?php foreach (['S','M','T','W','T','F','S'] as $dl): ?>
                        <div class="cal-day-label"><?php echo $dl; ?></div>
                    <?php endforeach; ?>

                    <?php
                    $first_day_of_week = date('w', strtotime($calendar_days[0]));
                    // Fill blanks before start
                    for ($b = 0; $b < $first_day_of_week; $b++):
                    ?>
                        <div class="cal-day absent" style="opacity:0;"></div>
                    <?php endfor; ?>

                    <?php foreach ($calendar_days as $d):
                        $is_today   = ($d === date('Y-m-d'));
                        $is_present = in_array($d, $attendance_dates);
                        $cls = $is_today ? 'today' : ($is_present ? 'present' : 'absent');
                    ?>
                        <div class="cal-day <?php echo $cls; ?>" title="<?php echo date('M j', strtotime($d)); ?>">
                            <?php echo date('j', strtotime($d)); ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="cal-legend">
                    <div class="cal-legend-item">
                        <div class="cal-legend-dot" style="background:rgba(82,183,136,0.4); border:1px solid rgba(82,183,136,0.4);"></div>
                        Present
                    </div>
                    <div class="cal-legend-item">
                        <div class="cal-legend-dot" style="background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.06);"></div>
                        Absent
                    </div>
                    <div class="cal-legend-item">
                        <div class="cal-legend-dot" style="background:linear-gradient(135deg,#2d6a4f,#1b4332);"></div>
                        Today
                    </div>
                </div>
            </div>
        </div>

        <!-- Full History List -->
        <div class="list-card fade-up fade-up-d3">
            <div class="list-card-header">
                <p class="section-title"><i class="fas fa-list"></i> Visit History</p>
                <span class="badge badge-active"><?php echo $total_visits; ?> total</span>
            </div>

            <?php if (empty($attendance_records)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-xmark"></i>
                    <p>No attendance records yet.<br>Visit the gym to get started!</p>
                </div>
            <?php else: ?>
                <?php foreach (array_slice($attendance_records, 0, 30) as $i => $rec): ?>
                <div class="list-item">
                    <div class="list-item-icon" style="background:var(--success-bg); color:var(--accent-light);">
                        <i class="fas fa-dumbbell"></i>
                    </div>
                    <div class="list-item-info">
                        <div class="list-item-title">Gym Visit</div>
                        <div class="list-item-sub"><?php echo date('l', strtotime($rec['date'])); ?></div>
                    </div>
                    <div class="list-item-right">
                        <div class="list-item-value" style="color:var(--accent-light); font-size:0.78rem;">
                            <?php echo date('M d, Y', strtotime($rec['date'])); ?>
                        </div>
                        <?php if (!empty($rec['time_in'])): ?>
                        <div class="list-item-date"><?php echo date('h:i A', strtotime($rec['time_in'])); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if ($total_visits > 30): ?>
                <div style="text-align:center; padding:0.85rem; font-size:0.75rem; color:var(--text-muted);">
                    Showing latest 30 of <?php echo $total_visits; ?> visits
                </div>
                <?php endif; ?>
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
        <a href="attendance.php" class="nav-item active">
            <i class="fas fa-calendar-check"></i><span>Visits</span>
        </a>
        <a href="payments.php" class="nav-item">
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
        navigator.serviceWorker.register('/gym/member/sw.js');
    });
}
</script>
</body>
</html>
