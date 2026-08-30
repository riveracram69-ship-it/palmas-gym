<?php
/**
 * Palma's Elite Gym - Official Mobile Application Download Portal
 */
require_once __DIR__ . '/config/settings.php';

// Detect HTTPS behind reverse proxies (Render, Cloudflare, AWS, etc.)
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
);

$protocol = $isHttps ? "https://" : "http://";
$rawHost = $_SERVER['HTTP_HOST'];
$baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$downloadLink = "downloads/palmas-elite-gym.apk";
$apkUrl = $protocol . $rawHost . (empty($baseDir) ? '' : $baseDir) . '/' . $downloadLink;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download Mobile App | <?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
                radial-gradient(circle at 10% 20%, rgba(45, 106, 79, 0.3) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(82, 183, 136, 0.2) 0%, transparent 40%);
            min-height: 100vh;
        }

        h1, h2, h3, h4, h5 {
            font-family: 'Outfit', sans-serif;
        }

        /* Top Navigation */
        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 8%;
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
            width: 44px;
            height: 44px;
            border-radius: 10px;
            object-fit: cover;
            border: 1px solid var(--primary-light);
        }

        .brand-text h1 {
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }

        .brand-text span {
            font-size: 0.72rem;
            color: var(--primary-accent);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 20px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s ease;
            background: rgba(82, 183, 136, 0.15);
            border: 1px solid #52b788;
            color: #74c69d;
        }

        .nav-btn:hover {
            background: var(--primary);
            color: #ffffff;
        }

        /* Hero Section */
        .hero {
            padding: 40px 8%;
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 40px;
            align-items: center;
        }

        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(45, 106, 79, 0.35);
            border: 1px solid var(--primary-accent);
            color: var(--primary-accent);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 700;
            margin-bottom: 16px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .hero h2 {
            font-size: 2.6rem;
            font-weight: 900;
            line-height: 1.2;
            margin-bottom: 16px;
            background: linear-gradient(135deg, #ffffff 0%, #d8f3dc 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero p {
            color: #d8f3dc;
            font-size: 1.05rem;
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .mobile-notice {
            background: rgba(82, 183, 136, 0.15);
            border: 1px solid #52b788;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            color: #95d5b2;
        }

        .mobile-notice i {
            font-size: 1.2rem;
            color: #74c69d;
        }

        .download-actions {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-bottom: 24px;
        }

        .btn-download-main {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            background: linear-gradient(135deg, #2d6a4f 0%, #1b4332 100%);
            color: #ffffff;
            padding: 18px 30px;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 700;
            font-size: 1.15rem;
            box-shadow: 0 10px 25px rgba(45, 106, 79, 0.5);
            border: 1px solid rgba(82, 183, 136, 0.5);
            transition: transform 0.2s, box-shadow 0.2s;
            text-align: left;
        }

        .btn-download-main:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 30px rgba(45, 106, 79, 0.8);
            background: linear-gradient(135deg, #40916c 0%, #2d6a4f 100%);
        }

        .btn-download-main i {
            font-size: 2rem;
            color: #74c69d;
        }

        .meta-info {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        .meta-info span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* QR Card */
        .showcase-card {
            background: linear-gradient(145deg, #1b4332 0%, #091c14 100%);
            border: 1px solid rgba(82, 183, 136, 0.3);
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.6);
            text-align: center;
        }

        .qr-wrapper {
            background: #ffffff;
            padding: 16px;
            border-radius: 18px;
            display: inline-block;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
            margin: 16px 0;
        }

        /* Features Section */
        .features-section {
            padding: 40px 8%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .section-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .section-header h3 {
            font-size: 1.8rem;
            color: #ffffff;
            margin-bottom: 8px;
        }

        .section-header p {
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .feature-box {
            background: #0f2d20;
            border: 1px solid rgba(82, 183, 136, 0.2);
            border-radius: 16px;
            padding: 22px;
            transition: transform 0.2s;
        }

        .feature-box:hover {
            transform: translateY(-4px);
            border-color: var(--primary-light);
        }

        .feature-icon {
            width: 48px;
            height: 48px;
            background: rgba(45, 106, 79, 0.3);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            color: var(--primary-accent);
            margin-bottom: 14px;
        }

        .feature-box h4 {
            font-size: 1.1rem;
            margin-bottom: 6px;
            color: #ffffff;
        }

        .feature-box p {
            color: #b7e4c7;
            font-size: 0.85rem;
            line-height: 1.5;
        }

        /* How to Install */
        .install-guide {
            padding: 40px 8%;
            background: rgba(15, 45, 32, 0.4);
            border-top: 1px solid rgba(82, 183, 136, 0.15);
            border-bottom: 1px solid rgba(82, 183, 136, 0.15);
        }

        .steps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-top: 24px;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        .step-card {
            background: #091c14;
            border: 1px solid rgba(82, 183, 136, 0.2);
            border-radius: 14px;
            padding: 18px;
        }

        .step-number {
            width: 30px;
            height: 30px;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.85rem;
            margin-bottom: 10px;
        }

        .step-card h5 {
            font-size: 1rem;
            margin-bottom: 4px;
            color: #fff;
        }

        .step-card p {
            font-size: 0.82rem;
            color: var(--text-muted);
            line-height: 1.4;
        }

        footer {
            padding: 24px 8%;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        @media (max-width: 900px) {
            .hero {
                grid-template-columns: 1fr;
                text-align: center;
                padding: 24px 5%;
            }
            .hero h2 {
                font-size: 2rem;
            }
            .meta-info, .download-actions {
                justify-content: center;
            }
            .btn-download-main {
                width: 100%;
            }
            .showcase-card {
                margin-top: 10px;
            }
        }
    </style>
</head>
<body>

    <!-- TOP NAVBAR -->
    <nav class="navbar">
        <a href="index.php" class="brand">
            <img src="assets/images/palmas-logo.png" alt="<?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?> Logo">
            <div class="brand-text">
                <h1><?php echo htmlspecialchars($app_settings['gym_name'] ?? "PALMA'S ELITE GYM"); ?></h1>
                <span>Membership & Fitness</span>
            </div>
        </a>

        <div class="nav-links">
            <a href="member/login.php" class="nav-btn">
                <i class="fa-solid fa-right-to-bracket"></i> Member Portal
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
                Get instant access to your <b>Digital Member Pass</b>, fast QR check-in, real-time attendance tracking, renewal requests, and payment logs right on your phone.
            </p>

            <div class="download-actions">
                <a href="<?php echo htmlspecialchars($downloadLink); ?>" download="PalmasEliteGym.apk" class="btn-download-main">
                    <i class="fa-brands fa-android"></i>
                    <div>
                        <div style="font-size: 0.75rem; text-transform: uppercase; color: #95d5b2; letter-spacing: 0.5px;">Direct Android Installer</div>
                        <div>Download APK Now (18.9 MB)</div>
                    </div>
                </a>
            </div>

            <div class="meta-info">
                <span><i class="fa-solid fa-circle-check" style="color: #74c69d;"></i> Version 1.0.0</span>
                <span><i class="fa-solid fa-hard-drive" style="color: #74c69d;"></i> 18.9 MB</span>
                <span><i class="fa-brands fa-android" style="color: #74c69d;"></i> Android 7.0+</span>
            </div>
        </div>

        <!-- RIGHT SIDE: SCAN TO DOWNLOAD QR -->
        <div class="showcase-card">
            <h4 style="color: #ffffff; font-size: 1.15rem;"><i class="fa-solid fa-mobile-screen-button" style="color: #74c69d;"></i> Scan with Phone</h4>
            <p style="color: var(--text-muted); font-size: 0.8rem; margin-top: 2px;">If viewing on computer, scan to download onto your phone:</p>
            
            <div class="qr-wrapper">
                <div id="download-qr"></div>
            </div>

            <div style="font-size: 0.8rem; color: #95d5b2;">
                <i class="fa-solid fa-cloud-arrow-down"></i> Direct APK Cloud Link
            </div>
        </div>
    </section>

    <!-- INSTALLATION GUIDE -->
    <section class="install-guide">
        <div class="section-header">
            <h3>How to Install the APK on Android</h3>
            <p>3 simple steps to get started</p>
        </div>

        <div class="steps-grid">
            <div class="step-card">
                <div class="step-number">1</div>
                <h5>Tap Download</h5>
                <p>Click the <b>"Download APK Now"</b> button above.</p>
            </div>

            <div class="step-card">
                <div class="step-number">2</div>
                <h5>Accept Download</h5>
                <p>If prompted with <em>"File might be harmful"</em>, tap <b>Download anyway</b>.</p>
            </div>

            <div class="step-card">
                <div class="step-number">3</div>
                <h5>Open & Install</h5>
                <p>Tap the downloaded file to install and start using your digital pass!</p>
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
                    <i class="fa-solid fa-id-card"></i>
                </div>
                <h4>Digital Membership Card</h4>
                <p>View your live membership plan status, valid dates, and remaining days in real-time.</p>
            </div>

            <div class="feature-box">
                <div class="feature-icon">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <h4>Attendance Tracking</h4>
                <p>Monitor your workout consistency with complete time-in and time-out activity logs.</p>
            </div>

            <div class="feature-box">
                <div class="feature-icon">
                    <i class="fa-solid fa-repeat"></i>
                </div>
                <h4>Fast Renewals</h4>
                <p>Request plan extensions directly inside the app with zero front desk waiting lines.</p>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer>
        <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?>. All Rights Reserved.</p>
    </footer>

    <!-- DYNAMIC QR SCRIPT -->
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            const qrContainer = document.getElementById('download-qr');
            new QRCode(qrContainer, {
                text: '<?php echo $apkUrl; ?>',
                width: 160,
                height: 160,
                colorDark: '#091c14',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        });
    </script>
</body>
</html>
