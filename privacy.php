<?php
// Palma's Elite Gym — Privacy & Cookies Policy
require_once __DIR__ . '/config/settings.php';
$gym_name    = htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym");
$gym_address = htmlspecialchars($app_settings['gym_address'] ?? '123 Fitness Ave, Metro Manila, Philippines');
$gym_email   = htmlspecialchars($app_settings['gym_email'] ?? 'support@palmaselitegym.ph');
$gym_phone   = htmlspecialchars($app_settings['gym_phone'] ?? '+63 917 000 0000');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy &amp; Cookies Policy | <?php echo $gym_name; ?></title>
    <meta name="description" content="Privacy and Cookies Policy for <?php echo $gym_name; ?> under the Philippine Data Privacy Act of 2012 (RA 10173).">
    <link rel="stylesheet" href="assets/css/main.css?v=3.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            background: #f5f8f5;
            color: #172018;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            line-height: 1.65;
            margin: 0;
            padding: 0;
        }
        .legal-header {
            background: linear-gradient(135deg, #1b4332 0%, #0d1610 100%);
            color: #ffffff;
            padding: 3.5rem 1.5rem 3rem;
            text-align: center;
            position: relative;
        }
        .legal-header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            letter-spacing: -0.5px;
        }
        .legal-header p {
            color: #8fcfbc;
            font-size: 0.95rem;
            max-width: 600px;
            margin: 0 auto;
        }
        .legal-back-nav {
            position: absolute;
            top: 1.25rem;
            left: 1.25rem;
        }
        .legal-back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: #8fcfbc;
            text-decoration: none;
            font-size: 0.88rem;
            font-weight: 500;
            background: rgba(255, 255, 255, 0.08);
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            transition: all 0.2s ease;
        }
        .legal-back-btn:hover {
            background: rgba(255, 255, 255, 0.16);
            color: #ffffff;
        }
        .legal-container {
            max-width: 860px;
            margin: -1.5rem auto 3rem;
            padding: 0 1.25rem;
            position: relative;
            z-index: 10;
        }
        .legal-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            border: 1px solid #dce5dd;
            padding: 2.5rem 2.25rem;
        }
        .legal-section {
            margin-bottom: 2rem;
        }
        .legal-section:last-child {
            margin-bottom: 0;
        }
        .legal-section h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.25rem;
            font-weight: 600;
            color: #1b4332;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .legal-section h2 i {
            color: #3e8241;
            font-size: 1.1rem;
        }
        .legal-section p, .legal-section li {
            color: #334337;
            font-size: 0.94rem;
        }
        .legal-section ul {
            padding-left: 1.5rem;
            margin-top: 0.5rem;
            margin-bottom: 0.75rem;
        }
        .legal-section li {
            margin-bottom: 0.35rem;
        }
        .legal-highlight-box {
            background: #edf4ee;
            border-left: 4px solid #3e8241;
            padding: 1rem 1.25rem;
            border-radius: 0 8px 8px 0;
            margin: 1rem 0;
            font-size: 0.9rem;
            color: #1b4332;
        }
        .legal-footer-info {
            margin-top: 2.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #dce5dd;
            font-size: 0.85rem;
            color: #617567;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }
        @media (max-width: 640px) {
            .legal-card {
                padding: 1.75rem 1.25rem;
            }
            .legal-header h1 {
                font-size: 1.7rem;
            }
        }
    </style>
</head>
<body>

<header class="legal-header">
    <div class="legal-back-nav">
        <a href="javascript:history.back()" class="legal-back-btn">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
    <div style="margin-bottom:0.75rem;">
        <i class="fas fa-shield-halved" style="font-size:2.2rem; color:#52b788;"></i>
    </div>
    <h1>Privacy &amp; Cookies Policy</h1>
    <p>Last updated: <?php echo date('F Y'); ?> &bull; Data protection practices for <?php echo $gym_name; ?></p>
</header>

<main class="legal-container">
    <div class="legal-card">
        <div class="legal-highlight-box">
            <strong>Compliance Statement:</strong> <?php echo $gym_name; ?> strictly adheres to the <strong>Philippine Data Privacy Act of 2012 (Republic Act No. 10173)</strong> and the guidelines issued by the National Privacy Commission (NPC). We treat your personal and sensitive personal information with strict confidentiality and security.
        </div>

        <div class="legal-section">
            <h2><i class="fas fa-database"></i> 1. Information We Collect</h2>
            <p>When you register as a gym member or use our gym facilities, we collect necessary personal details, including:</p>
            <ul>
                <li><strong>Identity Information:</strong> Full name, gender, and membership identification code.</li>
                <li><strong>Contact Information:</strong> Active mobile phone number and verified email address.</li>
                <li><strong>Biometric / Visual Verification:</strong> Member profile selfie or photo solely for gym entry verification, digital ID card generation, and automated QR attendance check-in.</li>
                <li><strong>Payment Records:</strong> Payment receipts, transaction references (e.g. GCash / Maya reference numbers), and subscription history. We do not store credit card CVV numbers.</li>
                <li><strong>Access Records:</strong> QR check-in timestamps and gym entrance records.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2><i class="fas fa-bullseye"></i> 2. Purpose of Data Processing</h2>
            <p>We process your personal information exclusively for legitimate gym operational purposes:</p>
            <ul>
                <li>To activate, manage, and verify your gym membership subscription.</li>
                <li>To validate your identity upon entry via our digital QR attendance system.</li>
                <li>To send renewal reminders, payment confirmations, and essential service alerts.</li>
                <li>To uphold gym security, facility safety, and emergency response capabilities.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2><i class="fas fa-cookie-bite"></i> 3. Cookies &amp; Local Storage Policy</h2>
            <p>Our website and member portal utilize <strong>strictly essential first-party cookies and local storage tokens</strong> to operate safely:</p>
            <ul>
                <li><strong>Session Cookie (<code>PHPSESSID</code>):</strong> An encrypted identifier maintaining your logged-in status as you navigate the member portal. It expires automatically when you close your browser or log out.</li>
                <li><strong>CSRF Security Token:</strong> A cryptographic token ensuring all form submissions originate from you, preventing cross-site request forgery attacks.</li>
                <li><strong>No Advertising / 3rd-Party Tracking:</strong> We do NOT sell your data, nor do we deploy third-party advertising trackers or ad-retargeting pixels.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2><i class="fas fa-lock"></i> 4. Data Protection &amp; Security</h2>
            <p>We enforce robust technical, organizational, and physical measures to safeguard your personal data:</p>
            <ul>
                <li>Passwords are hashed using industry-standard <code>bcrypt</code> encryption.</li>
                <li>All sessions and communications utilize TLS/HTTPS encryption.</li>
                <li>Administrative access is strictly restricted to authorized gym management staff with role-based permissions.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2><i class="fas fa-user-check"></i> 5. Your Data Subject Rights (RA 10173)</h2>
            <p>Under the Philippine Data Privacy Act of 2012, you possess the following rights:</p>
            <ul>
                <li><strong>Right to be Informed:</strong> To understand how your data is collected, stored, and utilized.</li>
                <li><strong>Right of Access &amp; Rectification:</strong> To review and update your profile information at any time via the Member Portal or front desk.</li>
                <li><strong>Right to Erasure or Blocking:</strong> To request account deactivation and removal of personal records upon membership completion, subject to legal auditing requirements.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2><i class="fas fa-envelope"></i> 6. Contact Information &amp; DPO</h2>
            <p>If you have questions regarding this Privacy Policy, your personal data, or wish to exercise your rights, please reach out to us:</p>
            <ul>
                <li><strong>Gym Name:</strong> <?php echo $gym_name; ?></li>
                <li><strong>Address:</strong> <?php echo $gym_address; ?></li>
                <li><strong>Email:</strong> <a href="mailto:<?php echo $gym_email; ?>"><?php echo $gym_email; ?></a></li>
                <li><strong>Phone:</strong> <?php echo $gym_phone; ?></li>
            </ul>
        </div>

        <div class="legal-footer-info">
            <span>&copy; <?php echo date('Y'); ?> <?php echo $gym_name; ?>. All rights reserved.</span>
            <span><a href="terms.php" style="color:var(--palmas-primary); font-weight:600; text-decoration:none;">View Terms &amp; Conditions &rarr;</a></span>
        </div>
    </div>
</main>

</body>
</html>
