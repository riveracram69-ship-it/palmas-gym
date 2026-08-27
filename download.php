<?php
/**
 * Palma's Elite Gym - Official Mobile Application Download Portal
 */
require_once __DIR__ . '/config/settings.php';

// Detect local LAN IP for mobile network access
$localIp = gethostbyname(gethostname());
if (!$localIp || $localIp === '127.0.0.1') {
    $localIp = '192.168.100.11';
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$rawHost = $_SERVER['HTTP_HOST'];

// If accessed via localhost/127.0.0.1 on PC, convert to LAN IP for phone QR scanning
$mobileHost = (strpos($rawHost, 'localhost') !== false || strpos($rawHost, '127.0.0.1') !== false) ? $localIp : $rawHost;

$baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$currentMobileUrl = $protocol . $mobileHost . $_SERVER['REQUEST_URI'];
$directApkMobileUrl = $protocol . $mobileHost . $baseDir . '/downloads/palmas-elite-gym.apk';

$downloadLink = "downloads/palmas-elite-gym.apk";
$apkExists = file_exists(__DIR__ . '/' . $downloadLink);
$apkSize = $apkExists ? round(filesize(__DIR__ . '/' . $downloadLink) / (1024 * 1024), 1) . ' MB' : '7.5 MB';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download Mobile App - Palma's Elite Gym</title>
    
    <!-- Google Fonts: Outfit & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- QRCode.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <style>
        :root {
            --primary: #2d6a4f;
            --primary-light: #52b788;
            --primary-accent: #74c69d;
            --primary-dark: #1b4332;
            --bg-dark: #091c14;
            --bg-card: #0f2d20;
            --text-main: #f8f9fa;
            --text-muted: #95d5b2;
            --gold: #d4af37;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-main);
            line-height: 1.6;
            overflow-x: hidden;
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(45, 106, 79, 0.25) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(82, 183, 136, 0.15) 0%, transparent 40%);
        }

        h1, h2, h3, h4, h5, .brand-font {
            font-family: 'Outfit', sans-serif;
        }

        /* Top Navigation */
        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 8%;
            border-bottom: 1px solid rgba(82, 183, 136, 0.2);
            background: rgba(9, 28, 20, 0.85);
            backdrop-filter: blur(12px);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #ffffff;
        }

        .brand img {
            height: 44px;
            width: auto;
            border-radius: 8px;
        }

        .brand-text h1 {
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            line-height: 1.1;
        }

        .brand-text span {
            font-size: 0.75rem;
            color: var(--text-muted);
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .nav-links {
            display: flex;
            gap: 16px;
        }

        .nav-btn {
            padding: 8px 18px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            transition: 0.2s;
        }

        .nav-btn-outline {
            border: 1px solid var(--primary-accent);
            color: var(--primary-accent);
        }
        .nav-btn-outline:hover {
            background: rgba(82, 183, 136, 0.15);
        }

        /* Hero Section */
        .hero {
            padding: 60px 8% 40px 8%;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 50px;
            align-items: center;
            max-width: 1300px;
            margin: 0 auto;
        }

        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(82, 183, 136, 0.15);
            border: 1px solid var(--primary-accent);
            color: var(--primary-accent);
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.82rem;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .hero h2 {
            font-size: 3rem;
            font-weight: 900;
            line-height: 1.15;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #ffffff 0%, #d8f3dc 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero p {
            color: #d8f3dc;
            font-size: 1.1rem;
            margin-bottom: 30px;
            max-width: 540px;
        }

        .download-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
            margin-bottom: 30px;
        }

        .btn-download-main {
            display: inline-flex;
            align-items: center;
            gap: 14px;
            background: linear-gradient(135deg, #2d6a4f 0%, #1b4332 100%);
            color: #ffffff;
            padding: 16px 28px;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 700;
            font-size: 1.1rem;
            box-shadow: 0 10px 25px rgba(45, 106, 79, 0.5);
            border: 1px solid rgba(82, 183, 136, 0.4);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .btn-download-main:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 30px rgba(45, 106, 79, 0.7);
            background: linear-gradient(135deg, #40916c 0%, #2d6a4f 100%);
        }

        .btn-download-main i {
            font-size: 1.8rem;
            color: #74c69d;
        }

        .meta-info {
            display: flex;
            gap: 20px;
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        .meta-info span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Mockup & QR Card */
        .showcase-card {
            background: linear-gradient(145deg, #1b4332 0%, #091c14 100%);
            border: 1px solid rgba(82, 183, 136, 0.3);
            border-radius: 24px;
            padding: 26px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.6);
            text-align: center;
            position: relative;
        }

        .qr-wrapper {
            background: #ffffff;
            padding: 16px;
            border-radius: 18px;
            display: inline-block;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
            margin: 12px 0;
        }

        .ip-config-box {
            background: rgba(8, 28, 21, 0.8);
            border: 1px solid rgba(82, 183, 136, 0.25);
            border-radius: 12px;
            padding: 10px 14px;
            margin-top: 14px;
            text-align: left;
            font-size: 0.8rem;
        }

        .ip-config-box label {
            color: var(--text-muted);
            font-weight: 600;
            display: block;
            margin-bottom: 4px;
        }

        .ip-input-row {
            display: flex;
            gap: 6px;
        }

        .ip-input-row input {
            flex: 1;
            padding: 6px 10px;
            border-radius: 6px;
            background: #06150f;
            border: 1px solid #2d6a4f;
            color: #74c69d;
            font-size: 0.82rem;
            font-family: monospace;
        }

        .ip-input-row button {
            padding: 6px 12px;
            border-radius: 6px;
            background: #2d6a4f;
            color: #fff;
            border: none;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
        }

        .ip-input-row button:hover {
            background: #40916c;
        }

        /* Feature Grid */
        .features-section {
            padding: 60px 8%;
            max-width: 1300px;
            margin: 0 auto;
        }

        .section-header {
            text-align: center;
            margin-bottom: 45px;
        }

        .section-header h3 {
            font-size: 2.2rem;
            font-weight: 800;
            color: #ffffff;
        }

        .section-header p {
            color: var(--text-muted);
            margin-top: 8px;
            font-size: 1rem;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 24px;
        }

        .feature-box {
            background: var(--bg-card);
            border: 1px solid rgba(82, 183, 136, 0.2);
            border-radius: 16px;
            padding: 26px;
            transition: transform 0.2s, border-color 0.2s;
        }

        .feature-box:hover {
            transform: translateY(-4px);
            border-color: var(--primary-accent);
        }

        .feature-icon {
            width: 54px;
            height: 54px;
            background: rgba(82, 183, 136, 0.15);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--primary-accent);
            margin-bottom: 18px;
        }

        .feature-box h4 {
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: #ffffff;
        }

        .feature-box p {
            color: #b7e4c7;
            font-size: 0.92rem;
        }

        /* How to Install Section */
        .install-guide {
            padding: 50px 8%;
            background: rgba(15, 45, 32, 0.4);
            border-top: 1px solid rgba(82, 183, 136, 0.15);
            border-bottom: 1px solid rgba(82, 183, 136, 0.15);
        }

        .guide-container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .steps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        .step-card {
            background: #091c14;
            border: 1px solid rgba(82, 183, 136, 0.2);
            border-radius: 14px;
            padding: 20px;
            position: relative;
        }

        .step-number {
            width: 32px;
            height: 32px;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.9rem;
            margin-bottom: 12px;
        }

        .step-card h5 {
            font-size: 1.05rem;
            margin-bottom: 6px;
            color: #fff;
        }

        .step-card p {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        /* Footer */
        footer {
            padding: 30px 8%;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.85rem;
            border-top: 1px solid rgba(82, 183, 136, 0.1);
        }

        @media (max-width: 900px) {
            .hero {
                grid-template-columns: 1fr;
                text-align: center;
            }
            .hero p, .download-actions, .meta-info {
                margin-left: auto;
                margin-right: auto;
                justify-content: center;
            }
            .hero h2 {
                font-size: 2.2rem;
            }
        }
    </style>
</head>
<body>

    <!-- TOP NAVBAR -->
    <nav class="navbar">
        <a href="index.php" class="brand">
            <img src="assets/images/palmas-logo.png" alt="Palma's Elite Gym Logo">
            <div class="brand-text">
                <h1>PALMA'S ELITE GYM</h1>
                <span>Membership & Fitness</span>
            </div>
        </a>

        <div class="nav-links">
            <a href="member/login.php" class="nav-btn nav-btn-outline">
                <i class="fa-solid fa-right-to-bracket"></i> Member Web Portal
            </a>
        </div>
    </nav>

    <!-- HERO SECTION -->
    <section class="hero">
        <div>
            <div class="badge-pill">
                <i class="fa-solid fa-sparkles"></i> Official Mobile Application
            </div>
            <h2>Elevate Your Gym Experience On The Go</h2>
            <p>
                Get instant access to your <b>Digital Member Pass</b>, fast QR check-in, real-time attendance tracking, renewal requests, and payment logs right on your Android phone.
            </p>

            <div class="download-actions">
                <?php if ($apkExists): ?>
                    <a href="<?php echo htmlspecialchars($downloadLink); ?>" download="PalmasEliteGym.apk" class="btn-download-main">
                        <i class="fa-brands fa-android"></i>
                        <div style="text-align: left;">
                            <div style="font-size: 0.75rem; text-transform: uppercase; color: #95d5b2; letter-spacing: 0.5px;">Download Direct APK</div>
                            <div>Palma's Elite Gym App</div>
                        </div>
                    </a>
                <?php else: ?>
                    <a href="javascript:void(0)" onclick="showBuildGuide()" class="btn-download-main" style="background: linear-gradient(135deg, #1b4332 0%, #0d281e 100%);">
                        <i class="fa-brands fa-android" style="color: #d4af37;"></i>
                        <div style="text-align: left;">
                            <div style="font-size: 0.75rem; text-transform: uppercase; color: #f39c12; letter-spacing: 0.5px;">Ready to Compile</div>
                            <div>Generate APK via Android Studio</div>
                        </div>
                    </a>
                <?php endif; ?>
            </div>

            <div class="meta-info">
                <span><i class="fa-solid fa-circle-check" style="color: #74c69d;"></i> Version 1.0.0</span>
                <span><i class="fa-solid fa-hard-drive" style="color: #74c69d;"></i> <?php echo $apkSize; ?></span>
                <span><i class="fa-brands fa-android" style="color: #74c69d;"></i> Android 7.0+</span>
            </div>

            <div id="build-guide-modal" style="display: none; background: rgba(15, 45, 32, 0.95); border: 1px solid #52b788; border-radius: 14px; padding: 18px; margin-top: 15px;">
                <h4 style="color: #74c69d; margin-bottom: 8px;"><i class="fa-solid fa-gear"></i> 1-Minute APK Generation Step</h4>
                <ol style="padding-left: 20px; font-size: 0.85rem; color: #d8f3dc; line-height: 1.6;">
                    <li>Open terminal in <code>mobile-app/</code> and run: <br><code style="background: #081c15; padding: 2px 6px; border-radius: 4px; color: #74c69d;">npx cap open android</code></li>
                    <li>In Android Studio, click top menu: <br><b>Build</b> → <b>Build Bundle(s) / APK(s)</b> → <b>Build APK(s)</b>.</li>
                    <li>Copy generated <code style="color: #74c69d;">app-debug.apk</code> into: <br><code>downloads/palmas-elite-gym.apk</code></li>
                </ol>
            </div>
        </div>

        <!-- RIGHT SIDE: SCAN TO DOWNLOAD QR -->
        <div class="showcase-card">
            <h4 style="color: #ffffff; font-size: 1.2rem;"><i class="fa-solid fa-mobile-screen-button" style="color: #74c69d;"></i> Scan to Download on Phone</h4>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin-top: 2px;">Point your phone camera to download directly on mobile</p>
            
            <div class="qr-wrapper">
                <div id="download-qr"></div>
            </div>

            <div style="font-size: 0.75rem; color: #95d5b2;">
                <i class="fa-solid fa-wifi"></i> Ensure phone is on the same Wi-Fi network: <strong style="color: #fff;"><?php echo htmlspecialchars($localIp); ?></strong>
            </div>

            <div class="ip-config-box">
                <label><i class="fa-solid fa-network-wired"></i> Target Server Wi-Fi IP / Domain:</label>
                <div class="ip-input-row">
                    <input type="text" id="custom-ip-input" value="<?php echo htmlspecialchars($mobileHost); ?>" />
                    <button onclick="updateTargetUrl()">Update QR</button>
                </div>
            </div>
        </div>
    </section>

    <!-- FEATURES GRID -->
    <section class="features-section">
        <div class="section-header">
            <h3>Designed for Dedicated Members</h3>
            <p>Everything you need to manage your gym membership at your fingertips.</p>
        </div>

        <div class="features-grid">
            <div class="feature-box">
                <div class="feature-icon">
                    <i class="fa-solid fa-qrcode"></i>
                </div>
                <h4>Dynamic Security Pass</h4>
                <p>Auto-rotating HMAC QR code pass for contactless, fast check-in at the front desk counter.</p>
            </div>

            <div class="feature-box">
                <div class="feature-icon">
                    <i class="fa-solid fa-clipboard-check"></i>
                </div>
                <h4>Attendance Tracking</h4>
                <p>View your workout logs, check-in timestamps, and monthly attendance count in real-time.</p>
            </div>

            <div class="feature-box">
                <div class="feature-icon">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </div>
                <h4>Instant Plan Renewals</h4>
                <p>Submit membership extensions, choose payment methods (GCash, Cash, Card), and track approvals.</p>
            </div>

            <div class="feature-box">
                <div class="feature-icon">
                    <i class="fa-solid fa-receipt"></i>
                </div>
                <h4>Payment & Invoice History</h4>
                <p>Access your billing records, receipts, and membership expiry countdown whenever you need.</p>
            </div>
        </div>
    </section>

    <!-- HOW TO INSTALL GUIDE -->
    <section class="install-guide">
        <div class="guide-container">
            <div class="section-header" style="margin-bottom: 20px;">
                <h3 style="font-size: 1.8rem;">How to Install on Android</h3>
                <p>Follow these 4 simple steps to install the APK on your device</p>
            </div>

            <div class="steps-grid">
                <div class="step-card">
                    <div class="step-number">1</div>
                    <h5>Download File</h5>
                    <p>Tap <b>Download APK</b> or scan the QR code using your phone's camera.</p>
                </div>

                <div class="step-card">
                    <div class="step-number">2</div>
                    <h5>Accept Download</h5>
                    <p>If prompted with <em>"File might be harmful"</em>, tap <b>Download anyway</b>.</p>
                </div>

                <div class="step-card">
                    <div class="step-number">3</div>
                    <h5>Allow Install</h5>
                    <p>Open the downloaded file. Enable <em>"Allow from this source"</em> in settings if asked.</p>
                </div>

                <div class="step-card">
                    <div class="step-number">4</div>
                    <h5>Login & Workout</h5>
                    <p>Open Palma's Elite Gym app and sign in with your Member Code!</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer>
        <p>&copy; <?php echo date('Y'); ?> Palma's Elite Gym. All Rights Reserved. Built with Ionic & Capacitor.</p>
    </footer>

    <!-- DYNAMIC QR SCRIPT -->
    <script>
        let qrCodeInstance = null;
        const defaultProtocol = '<?php echo $protocol; ?>';
        const defaultHost = '<?php echo $mobileHost; ?>';
        const baseDir = '<?php echo $baseDir; ?>';

        function renderQr(url) {
            const qrContainer = document.getElementById('download-qr');
            qrContainer.innerHTML = '';
            qrCodeInstance = new QRCode(qrContainer, {
                text: url,
                width: 170,
                height: 170,
                colorDark: '#091c14',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        }

        function updateTargetUrl() {
            const hostVal = document.getElementById('custom-ip-input').value.trim();
            if (!hostVal) return;
            const finalUrl = `${defaultProtocol}${hostVal}${baseDir}/download.php`;
            renderQr(finalUrl);
        }

        function showBuildGuide() {
            const modal = document.getElementById('build-guide-modal');
            modal.style.display = (modal.style.display === 'none' || !modal.style.display) ? 'block' : 'none';
        }

        window.addEventListener('DOMContentLoaded', () => {
            const initialUrl = '<?php echo $currentMobileUrl; ?>';
            renderQr(initialUrl);
        });
    </script>
</body>
</html>
