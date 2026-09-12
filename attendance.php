<?php
$page_title = 'QR Attendance';
include 'includes/header.php';
include 'includes/sidebar.php';

// Load today's attendance
$today_logs = [];
try {
    if (isset($pdo) && $pdo) {
        // Show logs from today OR within the last 15 hours (handles late night/early morning scans)
        $today_logs = $pdo->query(
            "SELECT a.time_in, a.time_out, m.full_name, m.membership_id
             FROM attendance a
             JOIN members m ON m.id = a.member_id
             WHERE a.date = CURRENT_DATE() 
                OR (a.date = DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY) AND a.time_in > '18:00:00')
             ORDER BY a.date DESC, a.time_in DESC"
        )->fetchAll();
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
            
            <div id="scan-result" style="display:none; margin-top:1.5rem; padding:1.25rem; border-radius:12px;" role="alert"></div>

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
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
            <h3 class="section-title" style="margin:0;"><i class="fas fa-history" style="color:var(--accent);"></i> Today's Check-ins</h3>
            <span class="badge badge-gold" id="log-count"><?php echo count($today_logs); ?> active entries</span>
        </div>

        <div class="table-container" style="max-height: 550px; overflow-y: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="logs-body">
                    <?php if (empty($today_logs)): ?>
                    <tr id="no-logs">
                        <td colspan="3">
                            <div class="empty-state" style="padding:4rem 0;">
                                <i class="fas fa-qrcode" style="font-size:2.5rem; opacity:0.1; margin-bottom:1rem; display:block;"></i>
                                <p>Waiting for first scan...</p>
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($today_logs as $log): ?>
                    <tr>
                        <td>
                            <div class="member-cell">
                                <div class="member-avatar"><?php echo strtoupper(substr($log['full_name'], 0, 1)); ?></div>
                                <div>
                                    <div class="cell-primary"><?php echo htmlspecialchars($log['full_name']); ?></div>
                                    <div class="cell-secondary"><?php echo htmlspecialchars($log['membership_id']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="cell-primary"><?php echo date('h:i A', strtotime($log['time_in'])); ?></td>
                        <td class="cell-secondary"><?php echo $log['time_out'] ? date('h:i A', strtotime($log['time_out'])) : '—'; ?></td>
                        <td>
                            <?php if ($log['time_out']): ?>
                                <span class="badge badge-gray">Left</span>
                            <?php else: ?>
                                <span class="badge badge-success"><i class="fas fa-circle" style="font-size:0.35rem; margin-right:4px;"></i> Inside</span>
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

function processCheckin(membershipId) {
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
        body: 'membership_id=' + encodeURIComponent(membershipId)
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
                        <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:4px;">ID: <code>${safeMid}</code> • Plan: <strong>${safePlan}</strong></div>
                        <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                            <span class="badge badge-success" style="font-size:0.7rem;padding:2px 8px;"><i class="fas fa-shield-check"></i> ${safeAcc}</span>
                            <span class="badge ${safeMemStatus === 'Active' ? 'badge-gold' : 'badge-danger'}" style="font-size:0.7rem;padding:2px 8px;">${safeMemStatus} (Exp: ${safeExpiry})</span>
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
                row.innerHTML = `
                    <td>
                        <div class="member-cell">
                            <div class="member-avatar">${safeName.charAt(0).toUpperCase()}</div>
                            <div>
                                <div class="cell-primary">${safeName}</div>
                                <div class="cell-secondary">${safeMid}</div>
                            </div>
                        </div>
                    </td>
                    <td class="cell-primary">${time}</td>
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

            res.style.background = '#fce8e6'; 
            res.style.color = '#c5221f'; 
            res.style.border = '1px solid rgba(217,48,37,0.2)';
            res.innerHTML = `
                <div style="text-align:left;">
                    <div style="font-size:0.8rem;font-weight:800;text-transform:uppercase;color:#c5221f;margin-bottom:4px;">
                        <i class="fas fa-circle-xmark"></i> ${safeType ? 'CHECK-IN BLOCKED (' + safeType + ')' : 'SCAN FAILED'}
                    </div>
                    <div style="font-weight:700;font-size:0.95rem;color:var(--text-main);">${safeErrName}</div>
                    <div style="font-size:0.85rem;color:#c5221f;margin-top:2px;">${safeErrMsg}</div>
                </div>
            `;
        }
    })
    .finally(() => {
        setTimeout(() => { 
            res.style.display = 'none'; 
            isProcessingCheckin = false;
        }, 3500);
    });
}

function manualCheckin() {
    const inp = document.getElementById('manual-id');
    const id = inp.value.trim();
    if (id) { processCheckin(id); inp.value = ''; }
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
