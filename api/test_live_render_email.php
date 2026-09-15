<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/email.php';

$to = 'santosemman90@gmail.com';
$email_subject = "Membership Successfully Renewed! — Palma's Elite Gym";
$email_title = "Your Membership Has Been Successfully Renewed! 🔄";
$email_body = "
    <p>Dear <strong>Emmanuel Santos</strong>,</p>
    <p>Great news! Your gym membership with <strong>Palma's Elite Gym</strong> has been <strong>successfully renewed</strong>.</p>
    
    <div style=\"background-color:#F4F9F6; border:1px solid #D8E6DC; border-radius:10px; padding:18px; margin:20px 0;\">
        <p style=\"margin:0 0 10px; font-weight:bold; color:#1B4332; font-size:14px; text-transform:uppercase; letter-spacing:0.5px;\">Membership &amp; Payment Summary</p>
        <table style=\"width:100%; font-size:13px; color:#334155; border-collapse:collapse;\">
            <tr><td style=\"padding:4px 0;\"><strong>Membership ID:</strong></td><td style=\"text-align:right; font-family:monospace; font-weight:bold; color:#1B4332;\">GYM-265151</td></tr>
            <tr><td style=\"padding:4px 0;\"><strong>Plan:</strong></td><td style=\"text-align:right; font-weight:bold;\">STANDARD PASS</td></tr>
            <tr><td style=\"padding:4px 0;\"><strong>Duration:</strong></td><td style=\"text-align:right;\">1 Month(s)</td></tr>
            <tr><td style=\"padding:4px 0;\"><strong>Amount Paid:</strong></td><td style=\"text-align:right; font-weight:bold; color:#2D6A4F;\">₱500.00</td></tr>
            <tr><td style=\"padding:4px 0;\"><strong>Payment Method:</strong></td><td style=\"text-align:right;\">GCash</td></tr>
            <tr><td style=\"padding:4px 0;\"><strong>Reference No:</strong></td><td style=\"text-align:right; font-family:monospace;\">PEG-LIVE-TEST</td></tr>
            <tr style=\"border-top:1px dashed #CBD5E1;\"><td style=\"padding:8px 0 0;\"><strong>New Expiry Date:</strong></td><td style=\"padding:8px 0 0; text-align:right; font-weight:bold; color:#1B4332;\">October 14, 2026</td></tr>
        </table>
    </div>

    <p>Your <strong>Digital QR Pass</strong> is live and updated! You can present your pass at the gym entrance kiosk for immediate access.</p>
    <p style=\"margin-top:16px;\">Thank you for staying committed to your fitness journey with Palma's Elite Gym! 💪</p>
";

$res = send_email_notification($to, $email_subject, $email_title, $email_body);

echo json_encode([
    'success' => $res['sent'] ?? false,
    'res' => $res,
    'has_brevo_key' => defined('BREVO_API_KEY') && !empty(BREVO_API_KEY),
    'brevo_key_prefix' => defined('BREVO_API_KEY') && !empty(BREVO_API_KEY) ? substr(BREVO_API_KEY, 0, 8) . '...' : 'NONE',
    'has_resend_key' => defined('RESEND_API_KEY') && !empty(RESEND_API_KEY)
]);
