<?php
$page_title = 'QR Attendance';
include 'includes/header.php';
include 'includes/sidebar.php';

// Load selected date's attendance (defaults to today)
$selected_date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');
$is_today = ($selected_date === date('Y-m-d'));

$today_logs = [];
try {
    if (isset($pdo) && $pdo) {
        // Run smart auto-checkout sweep: closes past unclosed days and timed-out sessions (>4h)
        sync_attendance_auto_checkout($pdo);

        $stmt = $pdo->prepare(
            "SELECT a.id, a.date, a.time_in, a.time_out, m.full_name, m.membership_id,
                    m.annual_membership_expiry,
                    COALESCE(sub.plan_name, 'No Plan') AS plan_name,
                    COALESCE(sub.floor_access, 'all') AS floor_access,
                    sub.plan_category
             FROM attendance a
             JOIN members m ON m.id = a.member_id
             LEFT JOIN (
                 SELECT s.member_id, p.name AS plan_name, p.floor_access, p.plan_category
                 FROM subscriptions s
                 JOIN (
                     SELECT member_id, MAX(id) AS latest_sub_id
                     FROM subscriptions
                     GROUP BY member_id
                 ) latest ON s.id = latest.latest_sub_id
                 LEFT JOIN membership_plans p ON p.id = s.plan_id
             ) sub ON sub.member_id = m.id
             WHERE a.date = :log_date
             ORDER BY a.time_in DESC"
        );
        $stmt->execute([':log_date' => $selected_date]);
        $today_logs = $stmt->fetchAll();
    }
} catch (Exception $e) {}
?>

<div class="topbar">
    <div class="page-title">
        <h1>QR Attendance</h1>
        <p>Scan member QR codes or enter ID manually for entry logs.</p>
    </div>
    <div style="background:var(--secondary-bg); padding:0.6rem 1.2rem; border-radius:12px; border:1px solid var(--border); display:flex; align-items:center; gap:0.75rem;">
        <i class="far fa-calendar-check" style="color:var(--accent);"></i>
        <span style="font-size:0.9rem; font-weight:600; color:var(--text-main);"><?php echo date('l, M d, Y'); ?></span>
    </div>
</div>

<div class="attendance-layout-grid">

    <!-- Left: Scanner Section -->
    <div style="display:flex; flex-direction:column; gap:1.5rem;">
        <div class="card">
            <h3 class="section-title" style="margin-bottom:1rem;"><i class="fas fa-camera" style="color:var(--accent);"></i> Live Scanner</h3>
            
            <div class="camera-tip-pill" style="display:flex; align-items:center; gap:8px; background:rgba(62,130,65,0.08); border:1px solid rgba(62,130,65,0.2); padding:8px 12px; border-radius:10px; margin-bottom:14px; font-size:0.75rem; color:#2d6a4f; line-height:1.4;">
                <i class="fas fa-mobile-screen-button" style="color:#52b788; font-size:1.1rem; flex-shrink:0;"></i>
                <span><strong>Mobile Phone Scan Tip:</strong> Set phone brightness to 80%+, hold phone ~15–20cm away from lens, and angle slightly to avoid direct ceiling light glare.</span>
            </div>

            <div id="reader" style="width:100%; border-radius:12px; overflow:hidden; border:1px solid var(--border); background:#000; transition:outline 0.2s ease;"></div>
            
            <div id="scan-result" style="display:none; margin-top:1.5rem; padding:1.25rem; border-radius:12px; position:relative;" role="alert"></div>

            <div style="margin-top:2rem; padding-top:1.5rem; border-top:1px solid var(--border);">
                <p style="font-size:0.75rem; color:var(--text-muted); margin-bottom:1rem; font-weight:700; text-transform:uppercase; letter-spacing:1px;">Manual ID Entry</p>
                <div style="display:flex; gap:0.75rem;">
                    <input type="text" id="manual-id" class="form-control" placeholder="Enter Member ID..." style="flex:1;">
                    <button class="btn btn-primary" onclick="manualCheckin()"><i class="fas fa-check"></i></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Today's Logs -->
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem; margin-bottom:1.5rem;">
            <div>
                <h3 class="section-title" style="margin:0;"><i class="fas fa-history" style="color:var(--accent);"></i> <?php echo $is_today ? "Today's Check-ins" : "Check-ins for " . date('M d, Y', strtotime($selected_date)); ?></h3>
            </div>
            <div style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap;">
                <form method="GET" action="" style="display:inline-flex; align-items:center; gap:0.4rem; margin:0;">
                    <input type="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>" 
                           class="form-control" style="padding:0.35rem 0.6rem; font-size:0.8rem; height:auto; width:auto;" 
                           onchange="this.form.submit()" title="Filter by date">
                    <?php if (!$is_today): ?>
                        <a href="attendance.php" class="btn btn-outline btn-sm" style="font-size:0.75rem; padding:0.35rem 0.65rem;" title="Return to Today">Today</a>
                    <?php endif; ?>
                </form>
                <span class="badge badge-gold" id="log-count"><?php echo count($today_logs); ?> active entr<?php echo count($today_logs) === 1 ? 'y' : 'ies'; ?></span>
            </div>
        </div>

        <div class="table-container" style="max-height: 550px; overflow-y: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Member Tier & Plan</th>
                        <th>Floor Access</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="logs-body">
                    <?php if (empty($today_logs)): ?>
                    <tr id="no-logs">
                        <td colspan="6">
                            <div class="empty-state" style="padding:4rem 0;">
                                <i class="fas fa-qrcode" style="font-size:2.5rem; opacity:0.1; margin-bottom:1rem; display:block;"></i>
                                <p>Waiting for first scan...</p>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($today_logs as $log): 
                        $has_timed_out = (!empty($log['time_out']) && $log['time_out'] !== '00:00:00');
                        $ann_exp = $log['annual_membership_expiry'] ?? null;
                        $is_official = (!empty($ann_exp) && strtotime($ann_exp) >= strtotime(date('Y-m-d')));
                        $fa = $log['floor_access'] ?? 'all';
                    ?>
                    <tr id="att-row-<?php echo $log['id']; ?>">
                        <td>
                            <div class="member-cell">
                                <div class="member-avatar"><?php echo strtoupper(substr($log['full_name'], 0, 1)); ?></div>
                                <div>
                                    <div class="cell-primary" style="font-weight:700;"><?php echo htmlspecialchars($log['full_name']); ?></div>
                                    <code class="cell-secondary" style="font-weight:600; color:var(--accent); font-size:0.75rem;"><?php echo htmlspecialchars($log['membership_id']); ?></code>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="display:flex; flex-direction:column; gap:3px;">
                                <div>
                                    <?php if ($is_official): ?>
                                        <span class="badge" style="background:rgba(16,185,129,0.15); color:#059669; border:1px solid rgba(16,185,129,0.3); font-size:0.68rem; font-weight:700;">
                                            <i class="fas fa-id-card"></i> Official Member
                                        </span>
                                    <?php else: ?>
                                        <span class="badge" style="background:rgba(100,116,139,0.12); color:#64748b; border:1px solid rgba(100,116,139,0.25); font-size:0.68rem; font-weight:600;">
                                            <i class="fas fa-user"></i> Non-Member
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <span style="font-size:0.75rem; color:var(--text-main); font-weight:600;">
                                    <?php echo htmlspecialchars($log['plan_name']); ?>
                                </span>
                            </div>
                        </td>
                        <td>
                            <?php if ($fa === 'second_floor_only'): ?>
                                <span class="badge" style="background:rgba(14,165,233,0.15); color:#0284c7; border:1px solid rgba(14,165,233,0.3); font-weight:700; font-size:0.72rem; padding:3px 8px;">
                                    <i class="fas fa-stairs"></i> 2nd Floor Only
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background:rgba(34,197,94,0.15); color:#16a34a; border:1px solid rgba(34,197,94,0.3); font-weight:700; font-size:0.72rem; padding:3px 8px;">
                                    <i class="fas fa-building"></i> Ground + 2nd Flr
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="cell-primary" style="font-weight:600;"><?php echo date('h:i A', strtotime($log['time_in'])); ?></td>
                        <td class="cell-secondary" id="timeout-<?php echo $log['id']; ?>"><?php echo $has_timed_out ? date('h:i A', strtotime($log['time_out'])) : '—'; ?></td>
                        <td id="status-cell-<?php echo $log['id']; ?>" style="white-space:nowrap;">
                            <?php if ($has_timed_out): ?>
                                <span class="badge badge-gray">Left</span>
                            <?php else: ?>
                                <div style="display:inline-flex; align-items:center; gap:8px;">
                                    <span class="badge badge-success"><i class="fas fa-circle" style="font-size:0.35rem; margin-right:4px;"></i> Inside</span>
                                    <button type="button" class="btn btn-outline btn-sm manual-checkout-btn" 
                                            style="padding:3px 9px; font-size:0.72rem; border-radius:6px; color:var(--text-muted); border-color:var(--border); display:inline-flex; align-items:center; gap:4px; font-weight:600; cursor:pointer;"
                                            onclick="manualCheckout(<?php echo $log['id']; ?>, '<?php echo htmlspecialchars(addslashes($log['full_name'])); ?>')"
                                            title="Mark member as Left">
                                        <i class="fas fa-arrow-right-from-bracket"></i> Check Out
                                    </button>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script src="https://unpkg.com/html5-qrcode"></script>
<script>
let logCount = <?php echo count($today_logs); ?>;

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function processCheckin(membershipId, isManual = false) {
    const safeInputId = escapeHtml(membershipId);
    const res = document.getElementById('scan-result');
    res.style.display = 'block';
    res.className = 'alert alert-info';
    res.style.background = '#e8f0fe'; res.style.color = 'var(--info)'; res.style.border = '1px solid rgba(26,115,232,0.2)';
    res.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing ID: ' + safeInputId;

    fetch('modules/attendance/log_attendance.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: 'membership_id=' + encodeURIComponent(membershipId) + (isManual ? '&is_manual=1' : '')
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const isCooldown = data.is_cooldown;
            const bg = isCooldown ? '#fffbe7' : '#e6f4ea';
            const color = isCooldown ? '#b45309' : '#137333';
            const border = isCooldown ? '1px solid rgba(245,158,11,0.3)' : '1px solid rgba(30,142,62,0.2)';
            const icon = isCooldown ? 'fa-clock' : (data.action === 'check-out' ? 'fa-arrow-right-from-bracket' : 'fa-check-circle');

            const safeName = escapeHtml(data.member_name || 'Member');
            const safeMid = escapeHtml(data.membership_id || '');
            const safePlan = escapeHtml(data.plan_name || 'Standard');
            const safeAcc = escapeHtml(data.account_status || 'Approved');
            const safeMemStatus = escapeHtml(data.membership_status || 'Active');
            const safeExpiry = escapeHtml(data.expiry_date || '');

            const safeFloor = escapeHtml(data.floor_label || 'Ground + 2nd Floor');
            const safeTier = escapeHtml(data.member_tier_label || (data.is_official_member ? 'Official Member' : 'Non-Member'));
            const is2ndOnly = (data.floor_access === 'second_floor_only');
            const floorBadgeStyle = is2ndOnly 
                ? 'background:rgba(14,165,233,0.2); color:#0284c7; border:1px solid rgba(14,165,233,0.4);' 
                : 'background:rgba(34,197,94,0.2); color:#16a34a; border:1px solid rgba(34,197,94,0.4);';
            const tierBadgeStyle = data.is_official_member 
                ? 'background:rgba(16,185,129,0.2); color:#059669; border:1px solid rgba(16,185,129,0.4);' 
                : 'background:rgba(100,116,139,0.15); color:#475569; border:1px solid rgba(100,116,139,0.3);';

            let photoHtml = '';
            if (data.photo) {
                const safePhoto = escapeHtml(data.photo);
                photoHtml = `<img src="${safePhoto}" alt="${safeName} Photo" style="width:54px;height:54px;border-radius:12px;object-fit:cover;border:2px solid ${isCooldown ? '#F59E0B' : '#10B981'};">`;
            } else {
                photoHtml = `<div style="width:54px;height:54px;border-radius:12px;background:${isCooldown ? '#FEF3C7' : '#D1FAE5'};color:${isCooldown ? '#B45309' : '#047857'};display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:700;">${safeName.charAt(0).toUpperCase()}</div>`;
            }

            res.style.background = bg;
            res.style.color = color;
            res.style.border = border;
            res.innerHTML = `
                <div style="display:flex;gap:1rem;align-items:center;text-align:left;">
                    ${photoHtml}
                    <div style="flex:1;">
                        <div style="font-size:0.75rem;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:${color};margin-bottom:2px;">
                            <i class="fas ${icon}"></i> ${isCooldown ? 'COOLDOWN ACTIVE' : (data.action === 'check-out' ? 'CHECK-OUT SUCCESSFUL' : 'VALID MEMBER • CHECK-IN SUCCESS')}
                        </div>
                        <div style="font-size:1.05rem;font-weight:700;color:var(--text-main);">${safeName}</div>
                        <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:6px;">ID: <code>${safeMid}</code> • Plan: <strong>${safePlan}</strong></div>
                        <div style="display:flex;gap:0.4rem;flex-wrap:wrap;align-items:center;">
                            <span class="badge" style="${tierBadgeStyle} font-size:0.72rem; padding:3px 8px; font-weight:700;">
                                <i class="fas fa-id-card"></i> ${safeTier}
                            </span>
                            <span class="badge" style="${floorBadgeStyle} font-size:0.72rem; padding:3px 8px; font-weight:700;">
                                <i class="fas fa-building"></i> ${safeFloor}
                            </span>
                            <span class="badge ${safeMemStatus === 'Active' ? 'badge-gold' : 'badge-danger'}" style="font-size:0.7rem;padding:3px 8px;">${safeMemStatus} (Exp: ${safeExpiry})</span>
                        </div>
                    </div>
                </div>
            `;

            const noLogs = document.getElementById('no-logs');
            if (noLogs) noLogs.remove();

            const time = escapeHtml(data.time || new Date().toLocaleTimeString('en-PH', {hour:'2-digit',minute:'2-digit'}));
            
            if (data.action === 'check-in' && !isCooldown) {
                const row = document.getElementById('logs-body').insertRow(0);
                row.id = 'member-' + encodeURIComponent(safeMid);
                const floorBadgeHtml = is2ndOnly
                    ? `<span class="badge" style="background:rgba(14,165,233,0.15); color:#0284c7; border:1px solid rgba(14,165,233,0.3); font-weight:700; font-size:0.72rem; padding:3px 8px;"><i class="fas fa-stairs"></i> 2nd Floor Only</span>`
                    : `<span class="badge" style="background:rgba(34,197,94,0.15); color:#16a34a; border:1px solid rgba(34,197,94,0.3); font-weight:700; font-size:0.72rem; padding:3px 8px;"><i class="fas fa-building"></i> Ground + 2nd Flr</span>`;
                const tierBadgeHtml = data.is_official_member
                    ? `<span class="badge" style="background:rgba(16,185,129,0.15); color:#059669; border:1px solid rgba(16,185,129,0.3); font-size:0.68rem; font-weight:700;"><i class="fas fa-id-card"></i> Official Member</span>`
                    : `<span class="badge" style="background:rgba(100,116,139,0.12); color:#64748b; border:1px solid rgba(100,116,139,0.25); font-size:0.68rem; font-weight:600;"><i class="fas fa-user"></i> Non-Member</span>`;

                row.innerHTML = `
                    <td>
                        <div class="member-cell">
                            <div class="member-avatar">${safeName.charAt(0).toUpperCase()}</div>
                            <div>
                                <div class="cell-primary" style="font-weight:700;">${safeName}</div>
                                <code class="cell-secondary" style="font-weight:600; color:var(--accent); font-size:0.75rem;">${safeMid}</code>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="display:flex; flex-direction:column; gap:3px;">
                            <div>${tierBadgeHtml}</div>
                            <span style="font-size:0.75rem; color:var(--text-main); font-weight:600;">${safePlan}</span>
                        </div>
                    </td>
                    <td>${floorBadgeHtml}</td>
                    <td class="cell-primary" style="font-weight:600;">${time}</td>
                    <td class="cell-secondary">—</td>
                    <td><span class="badge badge-success"><i class="fas fa-circle" style="font-size:0.35rem; margin-right:4px;"></i> Inside</span></td>`;
                logCount++;
                document.getElementById('log-count').textContent = logCount + ' active entries';
            } else if (data.action === 'check-out') {
                setTimeout(() => location.reload(), 1500);
            }
        } else {
            const safeErrName = escapeHtml(data.member_name || 'Unverified ID');
            const safeErrMsg = escapeHtml(data.message || 'Invalid scan.');
            const safeType = escapeHtml(data.status_type ? data.status_type.toUpperCase() : '');
            const isExpired = (data.status_type && data.status_type.toLowerCase() === 'expired');

            res.style.background = '#fce8e6'; 
            res.style.color = '#c5221f'; 
            res.style.border = '1px solid rgba(217,48,37,0.2)';

            let renewBtnHtml = '';
            if (isExpired && data.member_db_id) {
                renewBtnHtml = `<a href="renew-member.php?id=${encodeURIComponent(data.member_db_id)}" class="btn btn-primary" style="margin-top:10px;display:inline-flex;align-items:center;gap:6px;font-size:0.82rem;padding:0.5rem 1.1rem;text-decoration:none;border-radius:8px;"><i class="fas fa-rotate-right"></i> Renew Now →</a>`;
            }

            res.innerHTML = `
                <div style="text-align:left;">
                    <div style="font-size:0.8rem;font-weight:800;text-transform:uppercase;color:#c5221f;margin-bottom:4px;">
                        <i class="fas fa-circle-xmark"></i> ${safeType ? 'CHECK-IN BLOCKED (' + safeType + ')' : 'SCAN FAILED'}
                    </div>
                    <div style="font-weight:700;font-size:0.95rem;color:var(--text-main);">${safeErrName}</div>
                    <div style="font-size:0.85rem;color:#c5221f;margin-top:2px;">${safeErrMsg}</div>
                    ${renewBtnHtml}
                </div>
            `;
        }
    })
    .finally(() => {
        setTimeout(() => { 
            res.style.display = 'none'; 
            isProcessingCheckin = false;
        }, 10000);
    });
}

function manualCheckin() {
    const inp = document.getElementById('manual-id');
    const id = inp.value.trim();
    if (id) { processCheckin(id, true); inp.value = ''; }
}

function manualCheckout(attendanceId, memberName) {
    const confirmMsg = 'Check out ' + (memberName || 'this member') + ' now?';
    const executeCheckout = () => {
        const btn = document.querySelector(`#att-row-${attendanceId} .manual-checkout-btn`);
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        fetch('modules/attendance/manual_checkout.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: 'attendance_id=' + encodeURIComponent(attendanceId) + '&csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const timeoutEl = document.getElementById('timeout-' + attendanceId);
                const statusEl = document.getElementById('status-cell-' + attendanceId);
                if (timeoutEl) timeoutEl.textContent = data.time_out_formatted;
                if (statusEl) {
                    statusEl.innerHTML = '<span class="badge badge-gray">Left</span>';
                }
                const scanRes = document.getElementById('scan-result');
                if (scanRes) {
                    scanRes.style.display = 'block';
                    scanRes.className = 'alert alert-success';
                    scanRes.style.background = '#e6f4ea';
                    scanRes.style.color = '#137333';
                    scanRes.style.border = '1px solid rgba(30,142,62,0.2)';
                    scanRes.innerHTML = `<i class="fas fa-check-circle"></i> ${escapeHtml(data.message)}`;
                    setTimeout(() => { scanRes.style.display = 'none'; }, 4000);
                }
                if (typeof palmasToast === 'function') {
                    palmasToast(data.message, 'success');
                }
            } else {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-arrow-right-from-bracket"></i> Check Out';
                }
                alert(data.message || 'Could not check out member.');
            }
        })
        .catch(err => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-arrow-right-from-bracket"></i> Check Out';
            }
            alert('An error occurred. Please try again.');
        });
    };

    if (typeof palmasConfirm === 'function') {
        palmasConfirm(confirmMsg, executeCheckout);
    } else if (confirm(confirmMsg)) {
        executeCheckout();
    }
}

document.getElementById('manual-id').addEventListener('keydown', e => { if(e.key === 'Enter') manualCheckin(); });

let isProcessingCheckin = false;

// Audio Chime Synthesizer via Web Audio API (No external sound files required)
function playScanBeep(isSuccess = true) {
    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        const ctx = new AudioContext();
        if (ctx.state === 'suspended') ctx.resume();

        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        if (isSuccess) {
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, ctx.currentTime); // A5 note
            osc.frequency.exponentialRampToValueAtTime(1760, ctx.currentTime + 0.12); // A6 note
            gain.gain.setValueAtTime(0.2, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.18);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.18);
        } else {
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(320, ctx.currentTime);
            osc.frequency.linearRampToValueAtTime(180, ctx.currentTime + 0.22);
            gain.gain.setValueAtTime(0.25, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.25);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.25);
        }
    } catch (e) {
        // AudioContext not allowed before gesture or unsupported
    }
}

function flashReaderBorder(isSuccess = true) {
    const readerEl = document.getElementById('reader');
    if (!readerEl) return;
    readerEl.style.outline = isSuccess ? '5px solid #10B981' : '5px solid #EF4444';
    readerEl.style.outlineOffset = '-5px';
    setTimeout(() => {
        readerEl.style.outline = 'none';
    }, 600);
}

function onScanSuccess(text) {
    if (isProcessingCheckin) return;
    if (!text || !text.trim()) return;

    isProcessingCheckin = true;
    playScanBeep(true);
    flashReaderBorder(true);
    processCheckin(text.trim());
}

// Optimized Html5QrcodeScanner tailored specifically for mobile phone screens
let scanner = new Html5QrcodeScanner('reader', { 
    fps: 20, 
    qrbox: function(viewfinderWidth, viewfinderHeight) {
        const minEdge = Math.min(viewfinderWidth, viewfinderHeight);
        const qrboxSize = Math.max(220, Math.floor(minEdge * 0.75));
        return { width: qrboxSize, height: qrboxSize };
    },
    aspectRatio: 1.0,
    formatsToSupport: (typeof Html5QrcodeSupportedFormats !== 'undefined') ? [ Html5QrcodeSupportedFormats.QR_CODE ] : undefined,
    experimentalFeatures: {
        useBarCodeDetectorIfSupported: true
    },
    rememberLastUsedCamera: true
});
scanner.render(onScanSuccess);
</script>

<?php include 'includes/footer.php'; ?>
