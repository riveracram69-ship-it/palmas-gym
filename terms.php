<?php
// Palma's Elite Gym — Terms & Conditions (T&C)
require_once __DIR__ . '/config/settings.php';
$gym_name    = htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym");
$gym_address = htmlspecialchars($app_settings['gym_address'] ?? '123 Fitness Ave, Metro Manila, Philippines');
$gym_email   = htmlspecialchars($app_settings['gym_email'] ?? 'support@palmaselitegym.ph');
$gym_phone   = htmlspecialchars($app_settings['gym_phone'] ?? '+63 917 000 0000');
$gym_hours   = htmlspecialchars($app_settings['gym_hours'] ?? 'Mon – Sat: 6:00 AM – 10:00 PM | Sun: 8:00 AM – 8:00 PM');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms &amp; Conditions | <?php echo $gym_name; ?></title>
    <meta name="description" content="Membership rules, code of conduct, and terms of use for <?php echo $gym_name; ?>.">
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
        <i class="fas fa-file-contract" style="font-size:2.2rem; color:#52b788;"></i>
    </div>
    <h1>Terms &amp; Conditions</h1>
    <p>Facility membership rules, etiquette, and safety agreement for <?php echo $gym_name; ?></p>
</header>

<main class="legal-container">
    <div class="legal-card">
        <div class="legal-highlight-box">
            <strong>Welcome to <?php echo $gym_name; ?>!</strong> By registering for an account, accessing our facilities, or utilizing our digital membership pass, you agree to comply with and be bound by the following Terms &amp; Conditions.
        </div>

        <div class="legal-section">
            <h2><i class="fas fa-id-card"></i> 1. Membership &amp; Digital QR Pass</h2>
            <p>Every member is issued a personal digital QR pass for gym entry and attendance logging:</p>
            <ul>
                <li><strong>Non-Transferable:</strong> Membership passes and accounts are strictly personal and cannot be loaned, shared, or transferred to another individual.</li>
                <li><strong>Verification:</strong> Members must present their digital QR pass upon entry. Gym staff reserves the right to request photo ID verification to confirm identity.</li>
                <li><strong>Subscription Validity:</strong> Access is valid only during active membership plan periods. Expired accounts must be renewed to maintain access.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2><i class="fas fa-dumbbell"></i> 2. Gym Etiquette &amp; House Rules</h2>
            <p>To preserve a clean, respectful, and safe athletic environment for all athletes, members must observe:</p>
            <ul>
                <li><strong>Equipment Re-Racking:</strong> Always return dumbbells, barbells, and plates to their designated racks after finishing an exercise set.</li>
                <li><strong>Sanitation:</strong> Wipe down benches and machine pads using the provided sanitizer and towels after each use.</li>
                <li><strong>Appropriate Attire:</strong> Athletic footwear (closed-toe gym shoes) and athletic gym clothing must be worn at all times. Barefoot lifting or sandals are not permitted on the gym floor.</li>
                <li><strong>Respectful Conduct:</strong> Aggressive behavior, harassment, excessive noise, or abusive language toward staff or fellow gym members will result in immediate termination of membership.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2><i class="fas fa-heart-pulse"></i> 3. Physical Health &amp; Assumption of Risk</h2>
            <p>Physical exercise involves inherent risks of injury:</p>
            <ul>
                <li>Members affirm that they are in adequate physical health and have no medical conditions preventing them from participating in physical fitness training.</li>
                <li>Members are advised to consult a physician prior to commencing any high-intensity training regimen.</li>
                <li>Members assume all ordinary risks associated with weightlifting, cardiovascular conditioning, and gym equipment use.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2><i class="fas fa-shield"></i> 4. Personal Property &amp; Security</h2>
            <p><?php echo $gym_name; ?> is not liable for loss, theft, or damage to personal items, jewelry, money, or electronics brought onto the gym premises. Lockers are available for day-use only; items left overnight are subject to removal.</p>
        </div>

        <div class="legal-section">
            <h2><i class="fas fa-clock"></i> 5. Operating Hours &amp; Capacity</h2>
            <p>The facility operates during the following schedule: <strong><?php echo $gym_hours; ?></strong>. In compliance with safety standards, maximum facility capacity limits are actively enforced. The gym reserves the right to temporarily regulate entry during peak capacity hours.</p>
        </div>

        <div class="legal-section">
            <h2><i class="fas fa-circle-xmark"></i> 6. Account Suspension &amp; Termination</h2>
            <p>Management reserves the right to suspend or revoke membership without liability if a member:</p>
            <ul>
                <li>Violates safety regulations or gym house rules.</li>
                <li>Allows unauthorized third parties to enter using their digital QR pass.</li>
                <li>Engages in misconduct or vandalism of gym equipment and facilities.</li>
            </ul>
        </div>

        <div class="legal-section">
            <h2><i class="fas fa-building"></i> 7. Inquiries &amp; Club Office</h2>
            <p>For questions or assistance regarding your membership, contact us at:</p>
            <ul>
                <li><strong>Facility:</strong> <?php echo $gym_name; ?></li>
                <li><strong>Location:</strong> <?php echo $gym_address; ?></li>
                <li><strong>Email:</strong> <a href="mailto:<?php echo $gym_email; ?>"><?php echo $gym_email; ?></a></li>
                <li><strong>Hotline:</strong> <?php echo $gym_phone; ?></li>
            </ul>
        </div>

        <div class="legal-footer-info">
            <span>&copy; <?php echo date('Y'); ?> <?php echo $gym_name; ?>. All rights reserved.</span>
            <span><a href="privacy.php" style="color:var(--palmas-primary); font-weight:600; text-decoration:none;">View Privacy Policy &rarr;</a></span>
        </div>
    </div>
</main>

</body>
</html>
