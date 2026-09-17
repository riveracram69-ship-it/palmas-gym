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
            "SELECT a.id, a.date, a.time_in, a.time_out, m.id AS member_id, m.full_name, m.membership_id, m.photo,
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

<style>
/* ══════════════════════════════════════════════════════════════════
   STAFF SCANNER OVERHAUL — 9 DISTINCT BRANDED STATES
   ══════════════════════════════════════════════════════════════════ */
.scanner-state-banner {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    border-radius: 12px;
    margin-bottom: 14px;
    transition: all 0.3s ease;
}
.scanner-state-banner .state-dot-pulse {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    flex-shrink: 0;
}
.scanner-state-banner .state-info {
    flex: 1;
    display: flex;
    flex-direction: column;
}
.scanner-state-banner .state-label {
    font-weight: 800;
    font-size: 0.92rem;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}
.scanner-state-banner .state-desc {
    font-size: 0.75rem;
    opacity: 0.85;
}
.scanner-state-banner .state-badge-icon {
    font-size: 1.25rem;
}

/* 1. READY */
.state-ready {
    background: rgba(45, 106, 79, 0.12);
    border: 1px solid rgba(82, 183, 136, 0.35);
    color: #52b788;
}
.state-ready .state-dot-pulse {
    background: #52b788;
    box-shadow: 0 0 0 0 rgba(82, 183, 136, 0.7);
    animation: pulseReady 1.8s infinite;
}

/* 2. SCANNING */
.state-scanning {
    background: rgba(26, 115, 232, 0.12);
    border: 1px solid rgba(26, 115, 232, 0.35);
    color: #3b82f6;
}
.state-scanning .state-dot-pulse {
    background: #3b82f6;
    animation: pulseScanning 0.8s infinite;
}

/* 3. SUCCESS */
.state-success {
    background: rgba(16, 185, 129, 0.15);
    border: 1px solid rgba(16, 185, 129, 0.4);
    color: #10b981;
}
.state-success .state-dot-pulse {
    background: #10b981;
}

/* 4. ALREADY SCANNED */
.state-cooldown {
    background: rgba(245, 158, 11, 0.15);
    border: 1px solid rgba(245, 158, 11, 0.4);
    color: #f59e0b;
}
.state-cooldown .state-dot-pulse {
    background: #f59e0b;
}

/* 5. INVALID */
.state-invalid {
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.4);
    color: #ef4444;
}
.state-invalid .state-dot-pulse {
    background: #ef4444;
}

/* 6. EXPIRED */
.state-expired {
    background: rgba(249, 115, 22, 0.15);
    border: 1px solid rgba(249, 115, 22, 0.4);
    color: #f97316;
}
.state-expired .state-dot-pulse {
    background: #f97316;
}

/* 7. BLOCKED */
.state-blocked {
    background: rgba(220, 38, 38, 0.18);
    border: 1px solid rgba(220, 38, 38, 0.5);
    color: #dc2626;
}
.state-blocked .state-dot-pulse {
    background: #dc2626;
}

/* 8. SERVER ERROR */
.state-server-error {
    background: rgba(168, 85, 247, 0.15);
    border: 1px solid rgba(168, 85, 247, 0.4);
    color: #a855f7;
}
.state-server-error .state-dot-pulse {
    background: #a855f7;
}

/* 9. CAMERA ERROR */
.state-camera-error {
    background: rgba(100, 116, 139, 0.18);
    border: 1px solid rgba(100, 116, 139, 0.4);
    color: #94a3b8;
}
.state-camera-error .state-dot-pulse {
    background: #ef4444;
}

@keyframes pulseReady {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(82, 183, 136, 0.7); }
    70% { transform: scale(1.1); box-shadow: 0 0 0 8px rgba(82, 183, 136, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(82, 183, 136, 0); }
}

@keyframes pulseScanning {
    0% { opacity: 0.4; }
    50% { opacity: 1; }
    100% { opacity: 0.4; }
}

.scanner-laser-line {
    position: absolute;
    left: 10%;
    right: 10%;
    height: 3px;
    background: linear-gradient(90deg, transparent, #52b788, #74c69d, #52b788, transparent);
    box-shadow: 0 0 10px #52b788;
    z-index: 5;
    pointer-events: none;
    animation: laserScan 2.4s ease-in-out infinite;
    display: block;
}

@keyframes laserScan {
    0% { top: 15%; opacity: 0.2; }
    50% { top: 85%; opacity: 1; }
    100% { top: 15%; opacity: 0.2; }
}
</style>

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
        <div class="card scanner-card-container">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:0.5rem;">
                <h3 class="section-title" style="margin:0;"><i class="fas fa-qrcode" style="color:var(--accent);"></i> Live Attendance Scanner</h3>
                <span class="badge" style="background:rgba(82,183,136,0.15); color:#52b788; border:1px solid rgba(82,183,136,0.3); font-size:0.75rem; font-weight:700;">
                    <i class="fas fa-shield-halved"></i> HMAC Secured
                </span>
            </div>

            <!-- Prominent Scanner State Bar -->
            <div id="scanner-state-bar" class="scanner-state-banner state-ready">
                <div class="state-dot-pulse" id="scanner-state-dot"></div>
                <div class="state-info">
                    <span class="state-label" id="scanner-state-title">Ready to Scan</span>
                    <span class="state-desc" id="scanner-state-desc">Position member QR code inside the viewfinder</span>
                </div>
                <i class="fas fa-camera state-badge-icon" id="scanner-state-icon"></i>
            </div>
            
            <div class="camera-tip-pill" style="display:flex; align-items:center; gap:8px; background:rgba(62,130,65,0.08); border:1px solid rgba(62,130,65,0.2); padding:8px 12px; border-radius:10px; margin-bottom:14px; font-size:0.75rem; color:#2d6a4f; line-height:1.4;">
                <i class="fas fa-mobile-screen-button" style="color:#52b788; font-size:1.1rem; flex-shrink:0;"></i>
                <span><strong>Scan Guidance:</strong> Member should present their rotating pass from the mobile app (~15–20cm from lens).</span>
            </div>

            <!-- Viewfinder with Scanner Frame -->
            <div class="scanner-viewport-wrap" style="position:relative; width:100%; border-radius:12px; overflow:hidden; border:2px solid var(--border); background:#0a1912; min-height:260px;">
                <div id="reader" style="width:100%;"></div>
                
                <!-- Laser line overlay -->
                <div id="scanner-laser" class="scanner-laser-line"></div>
                
                <!-- Camera Error Card (Hidden by default) -->
                <div id="camera-error-overlay" style="display:none; position:absolute; inset:0; background:rgba(10,25,18,0.95); z-index:10; flex-direction:column; align-items:center; justify-content:center; padding:1.5rem; text-align:center;">
                    <i class="fas fa-video-slash" style="font-size:2.5rem; color:#ef4444; margin-bottom:12px;"></i>
                    <h4 style="color:#ffffff; margin-bottom:6px; font-weight:700;">Camera Unavailable</h4>
                    <p id="camera-error-msg" style="color:var(--text-muted); font-size:0.82rem; margin-bottom:16px; max-width:280px; line-height:1.4;">Unable to access the camera. Please ensure camera permissions are granted.</p>
                    <button type="button" class="btn btn-primary btn-sm" onclick="restartCameraScanner()" style="padding:0.5rem 1.25rem;">
                        <i class="fas fa-rotate-right"></i> Restart Camera
                    </button>
                </div>
            </div>
            
            <div id="scan-result" style="display:none; margin-top:1.25rem; padding:1.25rem; border-radius:12px; position:relative; animation:fadeIn 0.3s ease;" role="alert"></div>

            <div style="margin-top:1.5rem; padding-top:1.25rem; border-top:1px solid var(--border);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
                    <p style="font-size:0.75rem; color:var(--text-muted); margin:0; font-weight:700; text-transform:uppercase; letter-spacing:1px;">Manual ID Entry (Staff Only)</p>
                    <span style="font-size:0.7rem; color:var(--text-muted);"><i class="fas fa-keyboard"></i> Press Enter to Submit</span>
                </div>
                <div style="display:flex; gap:0.75rem;">
                    <input type="text" id="manual-id" class="form-control" placeholder="Enter Member ID (e.g. GYM9537F6)..." style="flex:1;">
                    <button class="btn btn-primary" id="btn-manual-checkin" onclick="manualCheckin()"><i class="fas fa-check"></i> Submit</button>
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
                                <div class="member-avatar" style="width:36px; height:36px; border-radius:50%; overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                    <?php if (!empty($log['photo'])): ?>
                                        <img src="<?php echo htmlspecialchars($log['photo']); ?>" alt="Photo" style="width:100%; height:100%; object-fit:cover;">
                                    <?php else: ?>
                                        <?php echo strtoupper(substr($log['full_name'], 0, 1)); ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <a href="view-member.php?id=<?php echo $log['member_id']; ?>" class="cell-primary" style="font-weight:700; color:var(--text-main); text-decoration:none;">
                                        <?php echo htmlspecialchars($log['full_name']); ?>
                                    </a>
                                    <div style="font-size:0.75rem; color:var(--text-muted); font-family:monospace;"><?php echo htmlspecialchars($log['membership_id']); ?></div>
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
let isProcessingCheckin = false;
let scanner = null;
let resetStateTimer = null;

// ── 9 SCANNER STATES MANAGER ─────────────────────────────────────────
const SCANNER_STATES = {
    READY: {
        className: 'state-ready',
        title: 'Ready to Scan',
        desc: 'Position member QR code inside the viewfinder',
        icon: 'fa-camera'
    },
    SCANNING: {
        className: 'state-scanning',
        title: 'Scanning QR Code...',
        desc: 'Verifying cryptographic HMAC token...',
        icon: 'fa-spinner fa-spin'
    },
    SUCCESS: {
        className: 'state-success',
        title: '✓ Attendance Recorded',
        desc: 'Member check-in/out verified and logged',
        icon: 'fa-circle-check'
    },
    ALREADY_SCANNED: {
        className: 'state-cooldown',
        title: 'Attendance Already Recorded',
        desc: 'Duplicate scan ignored within cooldown period',
        icon: 'fa-clock'
    },
    INVALID: {
        className: 'state-invalid',
        title: 'Invalid QR Code',
        desc: 'Malformed or unrecognized QR code format',
        icon: 'fa-circle-xmark'
    },
    EXPIRED: {
        className: 'state-expired',
        title: 'QR Code Expired',
        desc: 'Dynamic QR token or membership plan has expired',
        icon: 'fa-hourglass-end'
    },
    BLOCKED: {
        className: 'state-blocked',
        title: 'Member Not Allowed',
        desc: 'Account is pending review, rejected, or suspended',
        icon: 'fa-ban'
    },
    SERVER_ERROR: {
        className: 'state-server-error',
        title: 'Unable to Connect',
        desc: 'Network timeout or server unavailable',
        icon: 'fa-triangle-exclamation'
    },
    CAMERA_ERROR: {
        className: 'state-camera-error',
        title: 'Camera Unavailable',
        desc: 'Camera access denied or device disconnected',
        icon: 'fa-video-slash'
    }
};

function setScannerState(stateKey, customDesc = null) {
    const bar = document.getElementById('scanner-state-bar');
    const title = document.getElementById('scanner-state-title');
    const desc = document.getElementById('scanner-state-desc');
    const icon = document.getElementById('scanner-state-icon');
    const state = SCANNER_STATES[stateKey] || SCANNER_STATES.READY;

    if (!bar || !title || !desc || !icon) return;

    // Reset classes
    bar.className = 'scanner-state-banner ' + state.className;
    title.textContent = state.title;
    desc.textContent = customDesc || state.desc;
    icon.className = 'fas ' + state.icon + ' state-badge-icon';

    // Auto-revert back to READY after 7 seconds if in a terminal feedback state
    if (resetStateTimer) clearTimeout(resetStateTimer);
    if (stateKey !== 'READY' && stateKey !== 'SCANNING' && stateKey !== 'CAMERA_ERROR') {
        resetStateTimer = setTimeout(() => {
            setScannerState('READY');
        }, 7000);
    }
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Audio Chime Synthesizer via Web Audio API
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
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(1760, ctx.currentTime + 0.12);
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
    } catch (e) {}
}

function triggerVibration(isSuccess = true) {
    if (typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function') {
        try {
            navigator.vibrate(isSuccess ? 80 : [100, 50, 100]);
        } catch (e) {}
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

function processCheckin(membershipId, isManual = false) {
    if (isProcessingCheckin) return;
    isProcessingCheckin = true;

    setScannerState('SCANNING', 'Processing Member ID: ' + membershipId);
    
    const safeInputId = escapeHtml(membershipId);
    const res = document.getElementById('scan-result');
    res.style.display = 'block';
    res.className = 'alert alert-info';
    res.style.background = '#e8f0fe'; res.style.color = 'var(--info)'; res.style.border = '1px solid rgba(26,115,232,0.2)';
    res.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing ID: ' + safeInputId;

    const btnManual = document.getElementById('btn-manual-checkin');
    if (btnManual) btnManual.disabled = true;

    fetch('modules/attendance/log_attendance.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: 'membership_id=' + encodeURIComponent(membershipId) + (isManual ? '&is_manual=1' : '')
    })
    .then(async r => {
        const isJson = r.headers.get('content-type')?.includes('application/json');
        const data = isJson ? await r.json().catch(() => null) : null;
        if (!r.ok) {
            if (r.status === 401) {
                throw new Error('Staff session expired. Please refresh the page to log in.');
            }
            if (data && data.message) {
                return data;
            }
            throw new Error(data?.message || 'Server communication error (HTTP ' + r.status + ')');
        }
        return data || { success: false, message: 'Invalid response from server.' };
    })
    .then(data => {
        if (data.success) {
            const isCooldown = data.is_cooldown;
            
            if (isCooldown) {
                setScannerState('ALREADY_SCANNED', data.message);
                playScanBeep(false);
                triggerVibration(false);
            } else {
                setScannerState('SUCCESS', data.action === 'check-out' ? 'Check-out Recorded' : 'Check-in Recorded');
                playScanBeep(true);
                triggerVibration(true);
                flashReaderBorder(true);
            }

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
            const safeTime = escapeHtml(data.time || new Date().toLocaleTimeString('en-PH', {hour:'2-digit',minute:'2-digit'}));
            const safeDate = escapeHtml(data.date || new Date().toLocaleDateString('en-PH', {month:'short',day:'numeric',year:'numeric'}));

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
                photoHtml = `<img src="${safePhoto}" alt="${safeName} Photo" style="width:58px;height:58px;border-radius:12px;object-fit:cover;border:2px solid ${isCooldown ? '#F59E0B' : '#10B981'};">`;
            } else {
                photoHtml = `<div style="width:58px;height:58px;border-radius:12px;background:${isCooldown ? '#FEF3C7' : '#D1FAE5'};color:${isCooldown ? '#B45309' : '#047857'};display:flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:800;">${safeName.charAt(0).toUpperCase()}</div>`;
            }

            res.style.background = bg;
            res.style.color = color;
            res.style.border = border;
            res.innerHTML = `
                <div style="display:flex;gap:1rem;align-items:center;text-align:left;">
                    ${photoHtml}
                    <div style="flex:1;">
                        <div style="font-size:0.75rem;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:${color};margin-bottom:2px;">
                            <i class="fas ${icon}"></i> ${isCooldown ? 'ATTENDANCE ALREADY RECORDED' : (data.action === 'check-out' ? 'CHECK-OUT SUCCESSFUL' : '✓ ATTENDANCE RECORDED')}
                        </div>
                        <div style="font-size:1.15rem;font-weight:800;color:var(--text-main);">${safeName}</div>
                        <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:6px;">
                            ID: <code>${safeMid}</code> • Date: <strong>${safeDate}</strong> • Time: <strong>${safeTime}</strong>
                        </div>
                        <div style="display:flex;gap:0.4rem;flex-wrap:wrap;align-items:center;">
                            <span class="badge" style="${tierBadgeStyle} font-size:0.72rem; padding:3px 8px; font-weight:700;">
                                <i class="fas fa-id-card"></i> ${safeTier}
                            </span>
                            <span class="badge" style="${floorBadgeStyle} font-size:0.72rem; padding:3px 8px; font-weight:700;">
                                <i class="fas fa-building"></i> ${safeFloor}
                            </span>
                            <span class="badge ${safeMemStatus === 'Active' ? 'badge-gold' : 'badge-danger'}" style="font-size:0.7rem;padding:3px 8px;">Plan: ${safePlan} (${safeMemStatus})</span>
                        </div>
                    </div>
                </div>
            `;

            const noLogs = document.getElementById('no-logs');
            if (noLogs) noLogs.remove();
            
            if (data.action === 'check-in' && !isCooldown) {
                const row = document.getElementById('logs-body').insertRow(0);
                row.id = 'member-' + encodeURIComponent(safeMid);
                const floorBadgeHtml = is2ndOnly
                    ? `<span class="badge" style="background:rgba(14,165,233,0.15); color:#0284c7; border:1px solid rgba(14,165,233,0.3); font-weight:700; font-size:0.72rem; padding:3px 8px;"><i class="fas fa-stairs"></i> 2nd Floor Only</span>`
                    : `<span class="badge" style="background:rgba(34,197,94,0.15); color:#16a34a; border:1px solid rgba(34,197,94,0.3); font-weight:700; font-size:0.72rem; padding:3px 8px;"><i class="fas fa-building"></i> Ground + 2nd Flr</span>`;
                const tierBadgeHtml = data.is_official_member
                    ? `<span class="badge" style="background:rgba(16,185,129,0.15); color:#059669; border:1px solid rgba(16,185,129,0.3); font-size:0.68rem; font-weight:700;"><i class="fas fa-id-card"></i> Official Member</span>`
                    : `<span class="badge" style="background:rgba(100,116,139,0.12); color:#64748b; border:1px solid rgba(100,116,139,0.25); font-size:0.68rem; font-weight:600;"><i class="fas fa-user"></i> Non-Member</span>`;

                const rowAvatarHtml = data.photo 
                    ? `<img src="${escapeHtml(data.photo)}" alt="Photo" style="width:100%; height:100%; object-fit:cover;">`
                    : safeName.charAt(0).toUpperCase();

                row.innerHTML = `
                    <td>
                        <div class="member-cell">
                            <div class="member-avatar" style="width:36px; height:36px; border-radius:50%; overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0;">${rowAvatarHtml}</div>
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
                    <td class="cell-primary" style="font-weight:600;">${safeTime}</td>
                    <td class="cell-secondary">—</td>
                    <td><span class="badge badge-success"><i class="fas fa-circle" style="font-size:0.35rem; margin-right:4px;"></i> Inside</span></td>`;
                logCount++;
                document.getElementById('log-count').textContent = logCount + ' active entries';
            } else if (data.action === 'check-out') {
                setTimeout(() => location.reload(), 1500);
            }
        } else {
            playScanBeep(false);
            triggerVibration(false);
            flashReaderBorder(false);

            const safeErrName = escapeHtml(data.member_name || 'Unverified ID');
            const safeErrMsg = escapeHtml(data.message || 'Invalid scan.');
            const rawType = (data.status_type || '').toLowerCase();
            
            let mappedState = 'INVALID';
            if (rawType.includes('expired')) {
                mappedState = 'EXPIRED';
            } else if (rawType.includes('pending') || rawType.includes('rejected') || rawType.includes('suspended')) {
                mappedState = 'BLOCKED';
            } else if (rawType.includes('server')) {
                mappedState = 'SERVER_ERROR';
            }
            setScannerState(mappedState, safeErrMsg);

            res.style.background = '#fce8e6'; 
            res.style.color = '#c5221f'; 
            res.style.border = '1px solid rgba(217,48,37,0.2)';

            let renewBtnHtml = '';
            if (mappedState === 'EXPIRED' && data.member_db_id) {
                renewBtnHtml = `<a href="renew-member.php?id=${encodeURIComponent(data.member_db_id)}" class="btn btn-primary" style="margin-top:10px;display:inline-flex;align-items:center;gap:6px;font-size:0.82rem;padding:0.5rem 1.1rem;text-decoration:none;border-radius:8px;"><i class="fas fa-rotate-right"></i> Renew Membership →</a>`;
            }

            res.innerHTML = `
                <div style="text-align:left;">
                    <div style="font-size:0.8rem;font-weight:800;text-transform:uppercase;color:#c5221f;margin-bottom:4px;">
                        <i class="fas fa-circle-xmark"></i> ${SCANNER_STATES[mappedState].title}
                    </div>
                    <div style="font-weight:700;font-size:1rem;color:var(--text-main);">${safeErrName}</div>
                    <div style="font-size:0.85rem;color:#c5221f;margin-top:2px;">${safeErrMsg}</div>
                    ${renewBtnHtml}
                </div>
            `;
        }
    })
    .catch(err => {
        playScanBeep(false);
        triggerVibration(false);
        flashReaderBorder(false);
        setScannerState('SERVER_ERROR', err.message || 'Unable to connect to attendance API');

        res.style.background = '#fce8e6'; 
        res.style.color = '#c5221f'; 
        res.style.border = '1px solid rgba(217,48,37,0.2)';
        const safeErrorDetail = escapeHtml(err.message || 'Network error or server unavailable. Please check system connection.');
        res.innerHTML = `
            <div style="text-align:left;">
                <div style="font-size:0.8rem;font-weight:800;text-transform:uppercase;color:#c5221f;margin-bottom:4px;">
                    <i class="fas fa-triangle-exclamation"></i> UNABLE TO CONNECT
                </div>
                <div style="font-size:0.85rem;color:#c5221f;">${safeErrorDetail}</div>
            </div>
        `;
    })
    .finally(() => {
        if (btnManual) btnManual.disabled = false;
        setTimeout(() => { 
            res.style.display = 'none'; 
            isProcessingCheckin = false;
        }, 8000);
    });
}

function manualCheckin() {
    if (isProcessingCheckin) return;
    const inp = document.getElementById('manual-id');
    const id = inp.value.trim();
    if (id) { 
        processCheckin(id, true); 
        inp.value = ''; 
    }
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

document.getElementById('manual-id').addEventListener('keydown', e => { 
    if(e.key === 'Enter') manualCheckin(); 
});

function onScanSuccess(text) {
    if (isProcessingCheckin) return;
    if (!text || !text.trim()) return;
    processCheckin(text.trim());
}

function onScanFailure(error) {
    // Normal continuous scanning cycle — ignore frame-by-frame misses
}

function handleCameraError(err) {
    console.warn('Camera Error:', err);
    setScannerState('CAMERA_ERROR', 'Camera Unavailable — ensure permission is granted');
    const overlay = document.getElementById('camera-error-overlay');
    const msg = document.getElementById('camera-error-msg');
    if (overlay) overlay.style.display = 'flex';
    if (msg && err) msg.textContent = String(err.message || err);
}

function startCameraScanner() {
    const overlay = document.getElementById('camera-error-overlay');
    if (overlay) overlay.style.display = 'none';

    try {
        scanner = new Html5QrcodeScanner('reader', { 
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
        scanner.render(onScanSuccess, onScanFailure);
        setScannerState('READY');
    } catch (e) {
        handleCameraError(e);
    }
}

function restartCameraScanner() {
    if (scanner) {
        try { scanner.clear(); } catch (e) {}
        scanner = null;
    }
    startCameraScanner();
}

// Initialize Camera
startCameraScanner();
</script>

<?php include 'includes/footer.php'; ?>

