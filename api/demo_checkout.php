<?php
/**
 * api/demo_checkout.php
 * Sandbox Payment Simulator (Active only in Demo Mode)
 * 
 * Accurately exercises the server-side webhook, row-level locking,
 * and idempotent activation pipeline.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/env.php';

$paymentMode = strtolower(defined('PAYMENT_MODE') ? PAYMENT_MODE : 'demo');
if ($paymentMode === 'live') {
    http_response_code(403);
    echo "<h1>403 Forbidden</h1><p>Demo checkout simulator is strictly disabled in LIVE production mode.</p>";
    exit;
}

$ref = trim($_GET['ref'] ?? '');
if (empty($ref)) {
    die("Error: Missing transaction reference.");
}

$stmt = $pdo->prepare("
    SELECT t.*, p.name AS plan_name, m.full_name, m.email, m.membership_id
    FROM payment_transactions t
    JOIN membership_plans p ON p.id = t.plan_id
    JOIN members m ON m.id = t.member_id
    WHERE t.reference_code = ?
    LIMIT 1
");
$stmt->execute([$ref]);
$tx = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tx) {
    die("Error: Transaction record not found.");
}

// Check if action requested (POST simulation)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? 'pay';

    $app_url = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://palmas-gym-4oxn.onrender.com';
    $webhook_url = "{$app_url}/api/payment_webhook.php";

    $amountCentavos = (int)round($tx['amount'] * 100);

    if ($action === 'pay') {
        $webhookPayload = [
            'data' => [
                'id' => 'evt_' . bin2hex(random_bytes(8)),
                'type' => 'event',
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id' => $tx['gateway_transaction_id'] ?: 'cs_' . bin2hex(random_bytes(8)),
                        'type' => 'checkout_session',
                        'attributes' => [
                            'reference_number' => $tx['reference_code'],
                            'amount'           => $amountCentavos,
                            'status'           => 'paid',
                            'payments' => [
                                [
                                    'id' => 'pay_' . bin2hex(random_bytes(8)),
                                    'attributes' => [
                                        'status' => 'paid',
                                        'amount' => $amountCentavos,
                                        'source' => [
                                            'type' => strtolower($tx['payment_method'])
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            'reference_code' => $tx['reference_code']
        ];
    } elseif ($action === 'fail') {
        $webhookPayload = [
            'data' => [
                'id' => 'evt_' . bin2hex(random_bytes(8)),
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment.failed',
                    'data' => [
                        'attributes' => [
                            'reference_number' => $tx['reference_code'],
                            'failed_reason'    => 'Insufficient funds in simulated e-wallet account'
                        ]
                    ]
                ]
            ],
            'reference_code' => $tx['reference_code'],
            'status'         => 'FAILED',
            'failure_reason' => 'Simulated checkout failure'
        ];
    } else {
        // Cancelled
        $pdo->prepare("UPDATE payment_transactions SET status = 'CANCELLED' WHERE id = ?")->execute([$tx['id']]);
        echo json_encode(['success' => true, 'redirect' => "{$app_url}/api/check_status.php?ref={$ref}&status=cancelled"]);
        exit;
    }

    // Dispatch webhook to internal handler
    $ch = curl_init($webhook_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($webhookPayload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Demo-Simulation: palmas_demo_sandbox'
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        // Local direct execution fallback if loopback curl fails
        require_once __DIR__ . '/payment.php';
        if ($action === 'pay') {
            process_automated_subscription_activation(
                $pdo,
                (int)$tx['member_id'],
                (int)$tx['plan_id'],
                (float)$tx['amount'],
                $tx['payment_method'],
                $tx['reference_code']
            );
            $pdo->prepare("UPDATE payment_transactions SET status = 'PAID', paid_at = NOW() WHERE id = ?")->execute([$tx['id']]);
        }
    }

    echo json_encode([
        'success'  => true,
        'action'   => $action,
        'redirect' => "{$app_url}/api/check_status.php?ref={$ref}&status=" . ($action === 'pay' ? 'success' : 'failed')
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>PayMongo / GCash Sandbox Simulator</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <style>
    :root {
      --gcash-blue: #005ce6;
      --gcash-dark: #0041a3;
      --bg: #091c14;
      --card-bg: #112d21;
      --border: rgba(82, 183, 136, 0.25);
      --text: #ffffff;
      --muted: #95d5b2;
      --success: #10b981;
      --danger: #ef4444;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
    body {
      background: radial-gradient(ellipse at top, #143828 0%, #06140e 100%);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }
    .checkout-card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 20px;
      width: 100%;
      max-width: 440px;
      box-shadow: 0 20px 40px rgba(0,0,0,0.5);
      overflow: hidden;
    }
    .header {
      background: linear-gradient(135deg, #005ce6, #00368a);
      padding: 24px 20px;
      text-align: center;
      position: relative;
    }
    .sandbox-tag {
      background: rgba(255,255,255,0.25);
      color: #fff;
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: 1px;
      text-transform: uppercase;
      padding: 3px 10px;
      border-radius: 20px;
      display: inline-block;
      margin-bottom: 8px;
    }
    .amount-display {
      font-family: 'Outfit', sans-serif;
      font-size: 2.2rem;
      font-weight: 800;
      color: #fff;
      margin-top: 4px;
    }
    .content { padding: 22px 20px; }
    .info-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 10px 0;
      border-bottom: 1px solid rgba(255,255,255,0.06);
      font-size: 0.88rem;
    }
    .info-row:last-child { border-bottom: none; }
    .label { color: var(--muted); }
    .val { color: #fff; font-weight: 600; text-align: right; }
    .btn-pay {
      background: #005ce6;
      color: #fff;
      border: none;
      width: 100%;
      padding: 15px;
      border-radius: 12px;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      margin-top: 20px;
      transition: all 0.2s;
    }
    .btn-pay:hover { background: #004fc4; }
    .btn-fail {
      background: transparent;
      color: #f87171;
      border: 1px solid rgba(239,68,68,0.3);
      width: 100%;
      padding: 12px;
      border-radius: 12px;
      font-size: 0.88rem;
      font-weight: 600;
      cursor: pointer;
      margin-top: 10px;
    }
    .btn-cancel {
      background: transparent;
      color: var(--muted);
      border: none;
      width: 100%;
      padding: 12px;
      font-size: 0.85rem;
      cursor: pointer;
      margin-top: 4px;
    }
    .security-note {
      text-align: center;
      font-size: 0.74rem;
      color: var(--muted);
      margin-top: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }
    .spinner {
      width: 18px; height: 18px;
      border: 2px solid rgba(255,255,255,0.3);
      border-top-color: #fff;
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
      display: none;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>
<body>

<div class="checkout-card">
  <div class="header">
    <span class="sandbox-tag"><i class="fa-solid fa-flask"></i> PayMongo Sandbox Simulator</span>
    <h2 style="font-family:'Outfit'; font-size:1.1rem; color:#dbeafe;">Palma's Elite Gym</h2>
    <div class="amount-display">₱<?php echo number_format($tx['amount'], 2); ?></div>
  </div>

  <div class="content">
    <div class="info-row">
      <span class="label">Plan:</span>
      <span class="val"><?php echo htmlspecialchars($tx['plan_name']); ?></span>
    </div>
    <div class="info-row">
      <span class="label">Member:</span>
      <span class="val"><?php echo htmlspecialchars($tx['full_name']); ?> (<?php echo htmlspecialchars($tx['membership_id']); ?>)</span>
    </div>
    <div class="info-row">
      <span class="label">Payment Channel:</span>
      <span class="val" style="color:#60a5fa;"><i class="fa-solid fa-wallet"></i> <?php echo htmlspecialchars($tx['payment_method']); ?></span>
    </div>
    <div class="info-row">
      <span class="label">Reference:</span>
      <span class="val" style="font-family:monospace; font-size:0.82rem;"><?php echo htmlspecialchars($tx['reference_code']); ?></span>
    </div>

    <button type="button" class="btn-pay" id="btn-pay" onclick="triggerSimulation('pay')">
      <div class="spinner" id="pay-spinner"></div>
      <i class="fa-solid fa-shield-halved" id="pay-icon"></i>
      <span id="pay-text">Simulate Successful GCash Payment</span>
    </button>

    <button type="button" class="btn-fail" id="btn-fail" onclick="triggerSimulation('fail')">
      <i class="fa-solid fa-triangle-exclamation"></i> Simulate Payment Failure
    </button>

    <button type="button" class="btn-cancel" onclick="triggerSimulation('cancel')">
      Cancel Payment & Return to App
    </button>

    <div class="security-note">
      <i class="fa-solid fa-lock"></i> 256-Bit Encrypted Payment Simulation
    </div>
  </div>
</div>

<script>
async function triggerSimulation(action) {
  const btnPay = document.getElementById('btn-pay');
  const btnFail = document.getElementById('btn-fail');
  const spinner = document.getElementById('pay-spinner');
  const icon = document.getElementById('pay-icon');
  const text = document.getElementById('pay-text');

  btnPay.disabled = true;
  btnFail.disabled = true;
  if (spinner) spinner.style.display = 'block';
  if (icon) icon.style.display = 'none';
  if (text) text.textContent = 'Processing with Payment Gateway...';

  try {
    const formData = new FormData();
    formData.append('action', action);

    const res = await fetch(window.location.href, {
      method: 'POST',
      body: formData
    });
    const data = await res.json();

    if (data && data.redirect) {
      setTimeout(() => {
        window.location.href = data.redirect;
      }, 700);
    } else {
      alert("Payment processed. You may now return to the app.");
      window.close();
    }
  } catch (e) {
    alert("Simulation complete! You can now check your app.");
    window.close();
  }
}
</script>

</body>
</html>
