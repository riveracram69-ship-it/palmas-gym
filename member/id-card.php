<?php
require_once __DIR__ . '/auth.php';
require_member_login();

$member = current_member($pdo);
if (!$member) { header('Location: logout.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>E-ID Card | <?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></title>
    <link rel="stylesheet" href="../assets/css/member.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ── E-ID Card Styles ── */
        .eid-scene {
            padding: 1.5rem 1.25rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.5rem;
        }

        .eid-card-wrap {
            width: 320px;
            max-width: 100%;
            perspective: 1200px;
        }

        .eid-card {
            width: 100%;
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 32px 80px rgba(0,0,0,0.65), 0 0 0 1px rgba(255,255,255,0.06);
            display: flex;
            flex-direction: column;
            background: #fff;
            position: relative;
            transform-origin: center center;
            transition: transform 0.6s cubic-bezier(0.4,0,0.2,1);
        }

        .eid-card:hover {
            transform: rotateY(3deg) rotateX(-2deg) scale(1.01);
        }

        /* Watermark */
        .eid-watermark {
            position: absolute;
            right: -30px; top: -20px;
            width: 200px;
            opacity: 0.03;
            pointer-events: none;
            z-index: 1;
            filter: grayscale(100%);
        }

        /* Top gradient header */
        .eid-header {
            height: 150px;
            background: linear-gradient(145deg, #1a4d35 0%, #0d2a1c 60%, #060f0a 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 1.5rem 1.5rem 0;
            text-align: center;
            position: relative;
            z-index: 2;
            overflow: hidden;
            flex-shrink: 0;
        }

        /* Decorative circles */
        .eid-header::before {
            content: '';
            position: absolute;
            top: -60px; right: -60px;
            width: 150px; height: 150px;
            border-radius: 50%;
            border: 1px solid rgba(82,183,136,0.08);
        }

        .eid-header::after {
            content: '';
            position: absolute;
            bottom: -40px; left: -40px;
            width: 120px; height: 120px;
            background: radial-gradient(circle, rgba(45,106,79,0.25) 0%, transparent 70%);
        }

        .eid-logo-box {
            width: 38px; height: 38px;
            background: #fff;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            padding: 4px;
            margin-bottom: 0.4rem;
            box-shadow: 0 4px 14px rgba(0,0,0,0.2);
            flex-shrink: 0;
            position: relative;
            z-index: 3;
        }

        .eid-logo-box img { width: 100%; height: 100%; object-fit: contain; }

        .eid-gym-name {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.95rem;
            color: #fff;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            margin: 0;
            position: relative;
            z-index: 3;
        }

        .eid-gym-sub {
            font-size: 0.45rem;
            text-transform: uppercase;
            letter-spacing: 2.5px;
            color: rgba(255,255,255,0.35);
            font-weight: 700;
            margin-top: 3px;
            position: relative;
            z-index: 3;
        }

        /* Body area */
        .eid-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0 1.5rem 1rem;
            position: relative;
            z-index: 3;
            margin-top: -60px;
        }

        /* Avatar */
        .eid-avatar-wrap {
            width: 110px; height: 110px;
            border-radius: 50%;
            background: #fff;
            padding: 5px;
            box-shadow: 0 10px 30px rgba(8,28,21,0.15);
            position: relative;
            margin-bottom: 0.6rem;
        }

        .eid-avatar-inner {
            width: 100%; height: 100%;
            border-radius: 50%;
            overflow: hidden;
            background: #f1f5f9;
            display: flex; align-items: center; justify-content: center;
        }

        .eid-avatar-inner img {
            width: 100%; height: 100%; object-fit: cover;
        }

        .eid-avatar-initial {
            font-family: 'Outfit', sans-serif;
            font-size: 2.5rem;
            font-weight: 800;
            color: #2d6a4f;
        }

        .eid-check-badge {
            position: absolute;
            bottom: 4px; right: 4px;
            width: 26px; height: 26px;
            background: linear-gradient(135deg, #2d6a4f, #1b4332);
            border: 2.5px solid #fff;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
        }

        .eid-check-badge i {
            color: #fff;
            font-size: 0.55rem;
            font-weight: 900;
        }

        .eid-member-name {
            font-family: 'Outfit', sans-serif;
            font-size: 1.35rem;
            font-weight: 700;
            color: #0c2219;
            text-transform: capitalize;
            letter-spacing: -0.3px;
            text-align: center;
            margin: 0 0 0.3rem;
        }

        .eid-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 4px 12px;
            border-radius: 100px;
            font-size: 0.55rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 0.9rem;
        }

        .eid-status-badge.active {
            background: rgba(46,125,50,0.07);
            color: #2d6a4f;
            border: 1px solid rgba(46,125,50,0.18);
        }

        .eid-status-badge.expired {
            background: rgba(211,47,47,0.07);
            color: #c62828;
            border: 1px solid rgba(211,47,47,0.18);
        }

        /* QR container */
        .eid-qr-box {
            background: #fff;
            border-radius: 18px;
            padding: 0.85rem 0.85rem 0.6rem;
            border: 1px solid #f0f4f8;
            box-shadow: 0 8px 24px rgba(8,28,21,0.07);
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 0.85rem;
        }

        .eid-qr-label {
            font-family: 'Outfit', sans-serif;
            font-size: 0.42rem;
            font-weight: 800;
            color: #94a3b8;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-top: 0.5rem;
            text-align: center;
        }

        /* Details row (boarding pass style) */
        .eid-details {
            border-top: 1px dashed #e2e8f0;
            padding-top: 0.7rem;
            display: flex;
            justify-content: space-between;
            width: 100%;
        }

        .eid-detail-item p:first-child {
            font-size: 0.45rem;
            color: #94a3b8;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 1px;
            margin-bottom: 2px;
        }

        .eid-detail-item p:last-child {
            font-size: 0.75rem;
            font-weight: 700;
            color: #0c2219;
        }

        .eid-detail-item.right { text-align: right; }

        /* Footer */
        .eid-footer {
            height: 42px;
            background: #f8fafc;
            border-top: 1px solid #f0f4f8;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
        }

        .eid-footer span {
            font-size: 0.5rem;
            font-weight: 700;
            color: #94a3b8;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .eid-footer-dot { color: #cbd5e1; }

        /* Action buttons below card */
        .eid-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            width: 320px;
            max-width: 100%;
        }

        /* Tips card */
        .tip-card {
            background: rgba(45,106,79,0.06);
            border: 1px solid rgba(82,183,136,0.15);
            border-radius: 16px;
            padding: 1rem 1.25rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            width: 320px;
            max-width: 100%;
        }

        .tip-card i {
            color: #52b788;
            margin-top: 2px;
            font-size: 0.9rem;
        }

        .tip-card p {
            font-size: 0.78rem;
            color: #8faaa0;
            line-height: 1.55;
        }

        /* Screenshot and copy protection */
        body {
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            user-select: none;
        }
        
        img {
            -webkit-user-drag: none;
            user-drag: none;
            pointer-events: none;
        }
        
        @media print {
            body, .mobile-container, #card-capture {
                display: none !important;
                background: #000 !important;
                color: #000 !important;
            }
        }
        
        .protected-mode {
            background: #000000 !important;
        }
        .protected-mode * {
            opacity: 0 !important;
            visibility: hidden !important;
            display: none !important;
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
            <h1>My E-ID Card</h1>
        </div>
        <div class="header-actions">
            <a href="logout.php" class="header-icon-btn danger" title="Sign Out">
                <i class="fas fa-right-from-bracket"></i>
            </a>
        </div>
    </header>

    <main class="eid-scene">

        <!-- The Card -->
        <div class="eid-card-wrap fade-up" id="card-capture">
            <div class="eid-card">

                <!-- Subtle watermark -->
                <img src="../assets/images/palmas-logo.png" class="eid-watermark" alt="">

                <!-- Green header -->
                <div class="eid-header">
                    <div class="eid-logo-box">
                        <img src="../assets/images/palmas-logo.png" alt="Logo">
                    </div>
                    <h1 class="eid-gym-name"><?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></h1>
                    <p class="eid-gym-sub">Elite Fitness Club</p>
                </div>

                <!-- Body -->
                <div class="eid-body">

                    <!-- Avatar -->
                    <div class="eid-avatar-wrap">
                        <div class="eid-avatar-inner">
                            <?php if ($member['photo']): ?>
                                <img src="/gym/<?php echo htmlspecialchars($member['photo']); ?>" alt="Photo">
                            <?php else: ?>
                                <span class="eid-avatar-initial"><?php echo strtoupper(substr($member['full_name'], 0, 1)); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="eid-check-badge"><i class="fas fa-check"></i></div>
                    </div>

                    <!-- Name -->
                    <h2 class="eid-member-name"><?php echo htmlspecialchars(strtolower($member['full_name'])); ?></h2>

                    <!-- Status badge -->
                    <?php $is_active = ($member['status'] === 'Active'); ?>
                    <div class="eid-status-badge <?php echo $is_active ? 'active' : 'expired'; ?>">
                        <i class="fas fa-circle-check" style="font-size:0.65rem;"></i>
                        <?php echo $is_active ? 'Active Member' : 'Expired Member'; ?>
                    </div>

                    <!-- QR Code -->
                    <div class="eid-qr-box">
                        <div id="eid-qr"></div>
                        <span class="eid-qr-label">Scan for Gym Access</span>
                    </div>

                    <!-- Details -->
                    <div class="eid-details">
                        <div class="eid-detail-item">
                            <p>Account ID</p>
                            <p><?php echo htmlspecialchars($member['membership_id']); ?></p>
                        </div>
                        <div class="eid-detail-item right">
                            <p>Expiry Date</p>
                            <p><?php echo $member['expiry_date'] ? date('M d, Y', strtotime($member['expiry_date'])) : 'No Plan'; ?></p>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="eid-footer">
                    <span><?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></span>
                    <span class="eid-footer-dot">•</span>
                    <span>Official E-ID</span>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="eid-actions fade-up fade-up-d1">
            <button class="btn" id="download-btn" onclick="downloadEID()">
                <i class="fas fa-download"></i> Download E-ID
            </button>
            <button class="btn btn-secondary" onclick="shareEID()">
                <i class="fas fa-share-nodes"></i> Share Card
            </button>
        </div>

        <!-- Tip -->
        <div class="tip-card fade-up fade-up-d2">
            <i class="fas fa-lightbulb"></i>
            <p>Show your QR code at the front desk for instant check-in. Keep this card handy for all gym access and verification.</p>
        </div>

    </main>

    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="index.php" class="nav-item">
            <i class="fas fa-house"></i><span>Home</span>
        </a>
        <a href="id-card.php" class="nav-item active">
            <i class="fas fa-id-card"></i><span>E-ID</span>
        </a>
        <a href="attendance.php" class="nav-item">
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

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
// Screenshot / Capture Protection (toggles black screen when page loses focus or visibility changes)
const protectScreen = () => {
    document.body.classList.add('protected-mode');
};
const restoreScreen = () => {
    document.body.classList.remove('protected-mode');
};

window.addEventListener('blur', protectScreen);
window.addEventListener('focus', restoreScreen);
document.addEventListener('visibilitychange', () => {
    if (document.hidden) protectScreen();
    else restoreScreen();
});

// Disable PrintScreen, Ctrl+P, Ctrl+S, Ctrl+U, F12
document.addEventListener('keydown', (e) => {
    if (
        e.key === 'PrintScreen' ||
        (e.ctrlKey && (e.key === 'p' || e.key === 'P' || e.key === 's' || e.key === 'S' || e.key === 'u' || e.key === 'U')) ||
        (e.metaKey && (e.key === 'p' || e.key === 'P' || e.key === 's' || e.key === 'S')) ||
        e.keyCode === 123
    ) {
        e.preventDefault();
        e.stopPropagation();
        protectScreen();
        setTimeout(restoreScreen, 1000);
    }
});

document.addEventListener('keyup', (e) => {
    if (e.key === 'PrintScreen') {
        navigator.clipboard.writeText('');
    }
});

// Prevent right-click / context menu
document.addEventListener('contextmenu', e => e.preventDefault());

const memberID = "<?php echo htmlspecialchars($member['membership_id']); ?>";
let isCapturing = false;

async function refreshQR() {
    if (isCapturing) return;
    const qrContainer = document.getElementById("eid-qr");
    let offlineBadge = document.getElementById("offline-qr-badge");
    
    try {
        const res = await fetch('get_qr_token.php');
        const data = await res.json();
        if (data.success && data.token) {
            localStorage.setItem('cached_qr_token_' + memberID, data.token);
            qrContainer.innerHTML = ''; // Clear previous
            
            new QRCode(qrContainer, {
                text: data.token,
                width: 110,
                height: 110,
                colorDark: "#0c2219",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.M
            });
            if (offlineBadge) offlineBadge.remove();
        }
    } catch (e) {
        console.warn("Failed to load secure QR token (offline fallback):", e);
        const cachedToken = localStorage.getItem('cached_qr_token_' + memberID);
        if (cachedToken) {
            qrContainer.innerHTML = '';
            new QRCode(qrContainer, {
                text: cachedToken,
                width: 110,
                height: 110,
                colorDark: "#0c2219",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.M
            });
            if (!offlineBadge) {
                offlineBadge = document.createElement('div');
                offlineBadge.id = 'offline-qr-badge';
                offlineBadge.style.cssText = 'font-size:0.55rem; color:#ef4444; font-weight:800; text-transform:uppercase; margin-top:5px; text-align:center; letter-spacing:0.5px;';
                offlineBadge.innerHTML = '<i class="fas fa-wifi-slash"></i> Offline E-ID';
                qrContainer.appendChild(offlineBadge);
            }
        } else {
            qrContainer.innerHTML = '<div style="font-size:0.6rem;color:#ef4444;padding:0.5rem;text-align:center;line-height:1.3;">Connect online once<br>to sync E-ID</div>';
        }
    }
}

// Initial draw
refreshQR();

function downloadEID() {
    isCapturing = true;
    const btn = document.getElementById('download-btn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing...';
    btn.disabled = true;

    // Convert canvas QR to image first
    const qrCanvas = document.querySelector('#eid-qr canvas');
    if (qrCanvas) {
        const img = new Image();
        img.src = qrCanvas.toDataURL('image/png');
        img.style.cssText = 'width:110px;height:110px;display:block;';
        document.getElementById('eid-qr').innerHTML = '';
        document.getElementById('eid-qr').appendChild(img);
    }

    setTimeout(() => {
        html2canvas(document.getElementById('card-capture'), {
            scale: 3,
            useCORS: true,
            backgroundColor: null,
            logging: false
        }).then(canvas => {
            const link = document.createElement('a');
            link.download = `EID_${memberID}.png`;
            link.href = canvas.toDataURL('image/png');
            link.click();
            btn.innerHTML = '<i class="fas fa-download"></i> Download E-ID';
            btn.disabled = false;
            
            isCapturing = false;
            refreshQR(); // Resume and refresh
        });
    }, 500);
}

async function shareEID() {
    isCapturing = true;
    const qrCanvas = document.querySelector('#eid-qr canvas');
    if (qrCanvas) {
        const img = new Image();
        img.src = qrCanvas.toDataURL('image/png');
        img.style.cssText = 'width:110px;height:110px;display:block;';
        document.getElementById('eid-qr').innerHTML = '';
        document.getElementById('eid-qr').appendChild(img);
    }

    await new Promise(r => setTimeout(r, 300));

    html2canvas(document.getElementById('card-capture'), { scale: 3, useCORS: true, backgroundColor: null })
    .then(async canvas => {
        canvas.toBlob(async blob => {
            if (navigator.share) {
                try {
                    const file = new File([blob], `EID_${memberID}.png`, { type: 'image/png' });
                    await navigator.share({ files: [file], title: 'My Gym Membership E-ID' });
                } catch(e) {}
            } else {
                const link = document.createElement('a');
                link.href = canvas.toDataURL('image/png');
                link.download = `EID_${memberID}.png`;
                link.click();
            }
            
            isCapturing = false;
            refreshQR(); // Resume and refresh
        }, 'image/png');
    });
}
</script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js');
    });
}
</script>
</body>
</html>
