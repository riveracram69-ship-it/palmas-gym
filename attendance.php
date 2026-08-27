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

<div style="display:grid; grid-template-columns: 1fr 1.5fr; gap: 2rem; align-items: start;">

    <!-- Left: Scanner Section -->
    <div style="display:flex; flex-direction:column; gap:1.5rem;">
        <div class="card">
            <h3 class="section-title" style="margin-bottom:1.5rem;"><i class="fas fa-camera" style="color:var(--accent);"></i> Live Scanner</h3>
            <div id="reader" style="width:100%; border-radius:12px; overflow:hidden; border:1px solid var(--border); background:#000;"></div>
            
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

function processCheckin(membershipId) {
    const res = document.getElementById('scan-result');
    res.style.display = 'block';
    res.className = 'alert alert-info';
    res.style.background = '#e8f0fe'; res.style.color = 'var(--info)'; res.style.border = '1px solid rgba(26,115,232,0.2)';
    res.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing ID: ' + membershipId;

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
            res.style.background = '#e6f4ea'; res.style.color = 'var(--success)'; res.style.border = '1px solid rgba(30,142,62,0.2)';
            res.innerHTML = '<i class="fas fa-check-circle"></i> Success: ' + data.member_name;

            const noLogs = document.getElementById('no-logs');
            if (noLogs) noLogs.remove();

            const initial = data.member_name.charAt(0).toUpperCase();
            const time = new Date().toLocaleTimeString('en-PH', {hour:'2-digit',minute:'2-digit'});
            
            if (data.action === 'check-in') {
                const row = document.getElementById('logs-body').insertRow(0);
                row.id = 'member-' + membershipId;
                row.innerHTML = `
                    <td>
                        <div class="member-cell">
                            <div class="member-avatar">${initial}</div>
                            <div>
                                <div class="cell-primary">${data.member_name}</div>
                                <div class="cell-secondary">${membershipId}</div>
                            </div>
                        </div>
                    </td>
                    <td class="cell-primary">${time}</td>
                    <td class="cell-secondary">—</td>
                    <td><span class="badge badge-success"><i class="fas fa-circle" style="font-size:0.35rem; margin-right:4px;"></i> Inside</span></td>`;
                logCount++;
            } else {
                // Find and update row
                // For simplicity in this demo, we'll refresh the page or just prepend a check-out note
                // Best practice is to update the existing row, but since logs are ordered by time_in desc, 
                // we'll just reload to keep data sync simple or show a message.
                location.reload(); 
            }
            document.getElementById('log-count').textContent = logCount + ' active entries';
        } else {
            res.style.background = '#fce8e6'; res.style.color = 'var(--danger)'; res.style.border = '1px solid rgba(217,48,37,0.2)';
            res.innerHTML = '<i class="fas fa-circle-exclamation"></i> ' + (data.message || 'Scan Error');
        }
    })
    .finally(() => {
        setTimeout(() => { res.style.display = 'none'; if(scanner) scanner.resume(); }, 3000);
    });
}

function manualCheckin() {
    const inp = document.getElementById('manual-id');
    const id = inp.value.trim();
    if (id) { processCheckin(id); inp.value = ''; }
}

document.getElementById('manual-id').addEventListener('keydown', e => { if(e.key === 'Enter') manualCheckin(); });

let scanner = new Html5QrcodeScanner('reader', { fps: 10, qrbox: 240 });
scanner.render(onScanSuccess);

function onScanSuccess(text) { scanner.pause(true); processCheckin(text); }
</script>

<?php include 'includes/footer.php'; ?>
