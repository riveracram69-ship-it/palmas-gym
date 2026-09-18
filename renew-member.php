<?php
$page_title = 'Renew Subscription';
include 'includes/header.php';
include 'includes/sidebar.php';

$member_id = intval($_GET['id'] ?? 0);
$member = null;
$plans = [];
$current_sub = null;

try {
    if ($member_id && isset($pdo) && $pdo) {
        $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$member_id]);
        $member = $stmt->fetch();

        $plans = $pdo->query("SELECT id, name, price, duration_months, duration_minutes, is_test_promo, plan_category FROM membership_plans WHERE is_active = 1 ORDER BY (plan_category = 'membership_fee') DESC, (plan_category = 'member_pass') DESC, price ASC")->fetchAll();

        $sub_stmt = $pdo->prepare(
            "SELECT s.*, p.name as plan_name FROM subscriptions s
             JOIN membership_plans p ON p.id = s.plan_id
             WHERE s.member_id = ?
             ORDER BY s.expiry_date DESC LIMIT 1"
        );
        $sub_stmt->execute([$member_id]);
        $current_sub = $sub_stmt->fetch();
    }
} catch (Exception $e) {}

if (!$member) {
    echo "<div class='topbar'><h1>Member Not Found</h1></div>";
    include 'includes/footer.php'; exit;
}

$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $plan_id        = intval($_POST['plan_id'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'Cash';
    $payment_date   = $_POST['payment_date'] ?? date('Y-m-d');
    $notes          = trim($_POST['notes'] ?? '');

    if (!$plan_id) {
        $error = 'Please select a membership plan.';
    } else {
        // Fetch and validate selected plan
        $p_stmt = $pdo->prepare("SELECT id, name, price, duration_months, duration_minutes, is_test_promo, plan_category FROM membership_plans WHERE id = ?");
        $p_stmt->execute([$plan_id]);
        $plan = $p_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan || (int)($plan['is_active'] ?? 0) !== 1) {
            $error = 'The selected membership plan is invalid or no longer active.';
        } else {
            $plan_category = $plan['plan_category'] ?? 'legacy';
            $has_active_annual = (!empty($member['annual_membership_expiry']) && strtotime($member['annual_membership_expiry']) >= strtotime(date('Y-m-d')));

            // Enforce member rate eligibility
            if ($plan_category === 'member_pass' && !$has_active_annual) {
                $error = 'This member does not have an active Annual Membership. Member discounted rates require an active ₱1,000 Annual Membership. Please renew their Annual Membership Fee or select a Non-Member pass.';
            }
        }
    }

        if (empty($error) && $plan) {
            try {
                $pdo->beginTransaction();

                $now_str = date('Y-m-d H:i:s');
                $duration_minutes = intval($plan['duration_minutes'] ?? 0);
                $duration_months  = intval($plan['duration_months'] ?? 0);

                $is_daily_pass     = ($duration_minutes === 1440 || ($duration_months === 0 && stripos($plan['name'] ?? '', 'Daily') !== false));
                $is_minute_promo   = ($duration_minutes > 0 && !$is_daily_pass);
                if ($is_minute_promo && $duration_minutes <= 0 && preg_match('/(\d+)\s*(?:min|minute)/i', $plan['name'] ?? '', $pm)) {
                    $duration_minutes = intval($pm[1]);
                }
                $is_membership_fee = ($plan_category === 'membership_fee' || stripos($plan['name'] ?? '', 'Annual Membership Fee') !== false);
                $is_test_payment   = ((int)($plan['is_test_promo'] ?? 0) === 1 || $plan_category === 'test_promo') ? 1 : 0;

                if ($is_membership_fee) {
                    $current_ann_expiry = $member['annual_membership_expiry'] ?? null;
                    if ($current_ann_expiry && strtotime($current_ann_expiry) >= strtotime(date('Y-m-d'))) {
                        $new_ann_expiry = date('Y-m-d', strtotime($current_ann_expiry . ' +1 year'));
                    } else {
                        $new_ann_expiry = date('Y-m-d', strtotime('+1 year'));
                    }
                    $pdo->prepare("UPDATE members SET status = 'Active', account_status = 'Approved', annual_membership_expiry = ? WHERE id = ?")
                        ->execute([$new_ann_expiry, $member_id]);

                    $start_date  = $now_str;
                    $expiry_date = $new_ann_expiry . ' 23:59:59';
                } elseif ($is_minute_promo) {
                    $base_datetime = $now_str;
                    if ($current_sub && !empty($current_sub['expiry_date'])) {
                        $diff_sec = strtotime($current_sub['expiry_date']) - time();
                        if ($diff_sec > 0 && $diff_sec <= 300) {
                            $base_datetime = $current_sub['expiry_date'];
                        }
                    }
                    $start_date  = $base_datetime;
                    $expiry_date = date('Y-m-d H:i:s', strtotime("{$base_datetime} + {$duration_minutes} minutes"));
                } elseif ($is_daily_pass) {
                    $start_date  = $now_str;
                    $expiry_date = date('Y-m-d 23:59:59'); // Valid until end of today only
                } else {
                    if ($current_sub && !empty($current_sub['expiry_date']) && strtotime($current_sub['expiry_date']) > time()) {
                        $base_datetime = $current_sub['expiry_date'];
                    } else {
                        $base_datetime = $now_str;
                    }
                    $start_date = $base_datetime;
                    if ($duration_months <= 0) $duration_months = 1;
                    $expiry_date = date('Y-m-d 23:59:59', strtotime("{$base_datetime} + {$duration_months} months"));
                }

                if ($is_membership_fee) {
                    $subscription_id = null;
                    $pdo->prepare("UPDATE members SET status = 'Active', account_status = 'Approved' WHERE id = ?")->execute([$member_id]);
                } else {
                    // Insert new workout access subscription
                    $stmt = $pdo->prepare("INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date, created_by) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$member_id, $plan_id, $start_date, $expiry_date, $_SESSION['user_id'] ?? null]);
                    $subscription_id = $pdo->lastInsertId();

                    // Reactivate member status
                    $pdo->prepare("UPDATE members SET status = 'Active' WHERE id = ?")->execute([$member_id]);
                }

                // Authoritative server-side price
                $amount_paid = floatval($plan['price']);

                // Record payment
                $reference_num = trim($_POST['reference_number'] ?? '');
                if (in_array($payment_method, ['GCash', 'Bank Transfer']) && empty($reference_num)) {
                    throw new Exception("Reference number is required for GCash and Bank Transfer.");
                }
                $verified_by = $_SESSION['user_id'] ?? null;
                $payment_notes = $notes ?: ($is_membership_fee ? 'Annual Membership Fee Renewal' : 'Subscription Renewal');

                $stmt = $pdo->prepare("
                    INSERT INTO payments (member_id, subscription_id, amount, payment_method, reference_number, payment_date, verified_by, notes, is_test, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$member_id, $subscription_id, $amount_paid, $payment_method, $reference_num ?: null, $payment_date, $verified_by, $payment_notes, $is_test_payment]);

                $pdo->commit();

            if (!empty($member['email'])) {
                require_once __DIR__ . '/config/email.php';
                $time_tag = date('M d, Y h:i A');
                $email_subject = "Membership Successfully Renewed! [{$time_tag}] — Palma's Elite Gym";
                $email_title = "Your Membership Has Been Successfully Renewed! 🔄";
                $email_body = "
                    <p>Dear <strong>{$member['full_name']}</strong>,</p>
                    <p>Your gym membership with <strong>Palma's Elite Gym</strong> has been <strong>successfully renewed</strong> by the front desk.</p>
                    
                    <div style=\"background-color:#F4F9F6; border:1px solid #D8E6DC; border-radius:10px; padding:18px; margin:20px 0;\">
                        <p style=\"margin:0 0 10px; font-weight:bold; color:#1B4332; font-size:14px; text-transform:uppercase; letter-spacing:0.5px;\">Membership Summary</p>
                        <table style=\"width:100%; font-size:13px; color:#334155; border-collapse:collapse;\">
                            <tr><td style=\"padding:4px 0;\"><strong>Membership ID:</strong></td><td style=\"text-align:right; font-family:monospace; font-weight:bold; color:#1B4332;\">{$member['membership_id']}</td></tr>
                            <tr><td style=\"padding:4px 0;\"><strong>Plan:</strong></td><td style=\"text-align:right; font-weight:bold;\">{$plan['name']}</td></tr>
                            <tr><td style=\"padding:4px 0;\"><strong>Amount Paid:</strong></td><td style=\"text-align:right; font-weight:bold; color:#2D6A4F;\">₱" . number_format($amount_paid, 2) . "</td></tr>
                            <tr><td style=\"padding:4px 0;\"><strong>Payment Method:</strong></td><td style=\"text-align:right;\">{$payment_method}</td></tr>
                            <tr style=\"border-top:1px dashed #CBD5E1;\"><td style=\"padding:8px 0 0;\"><strong>New Expiry Date:</strong></td><td style=\"padding:8px 0 0; text-align:right; font-weight:bold; color:#1B4332;\">" . date('F j, Y', strtotime($expiry_date)) . "</td></tr>
                        </table>
                    </div>

                    <p>Your <strong>Digital QR Pass</strong> has been updated. You can present it at the gym entrance kiosk for instant check-in.</p>
                    <p style=\"margin-top:16px;\">Thank you for staying with Palma's Elite Gym! 💪</p>
                ";
                try {
                    send_email_notification($member['email'], $email_subject, $email_title, $email_body);
                } catch (\Throwable $emEx) {
                    error_log("Renewal email error in renew-member.php: " . $emEx->getMessage());
                }
            }

            log_activity($pdo, 'Renewed Subscription', "Renewed {$member['full_name']} on plan: {$plan['name']}, expires {$expiry_date}", 'Subscription');

            echo "<script>window.location.href='view-member.php?id={$member_id}&renewed=1';</script>";
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('System Error in renew-member.php: ' . $e->getMessage());
            $error = 'A system error occurred while processing the renewal.';
        }
    }
}

$days_left = null;
$is_expired = true;
if ($current_sub && !empty($current_sub['expiry_date'])) {
    $sub_exp_ts = (strpos($current_sub['expiry_date'], ':') !== false) 
        ? strtotime($current_sub['expiry_date']) 
        : strtotime($current_sub['expiry_date'] . ' 23:59:59');
    $days_left  = ceil(($sub_exp_ts - time()) / 86400);
    $is_expired = ($sub_exp_ts < time());
}

// Auto-sync status if subscription is expired
if ($is_expired && $member && ($member['status'] ?? '') === 'Active') {
    try {
        $pdo->prepare("UPDATE members SET status = 'Expired' WHERE id = ?")->execute([$member_id]);
        $member['status'] = 'Expired';
    } catch (Exception $e) {}
}
?>

<div class="topbar">
    <div class="page-title">
        <h1>Renew Subscription</h1>
        <p>Assign a new plan and record payment for <strong><?php echo htmlspecialchars($member['full_name']); ?></strong></p>
    </div>
    <a href="view-member.php?id=<?php echo $member_id; ?>" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Profile</a>
</div>

<?php if ($error): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="renew-page-grid" style="display:grid; grid-template-columns: 1fr 340px; gap:2rem; align-items:start;">

    <form method="POST" action="" onsubmit="const btn=this.querySelector('button[type=submit]'); if(btn && !btn.disabled){ btn.disabled=true; btn.innerHTML='<i class=\'fas fa-spinner fa-spin\'></i> Processing Renewal...'; return true; } return false;">
        <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
        <div style="display:flex; flex-direction:column; gap:2rem;">

            <!-- Current Status Banner -->
            <div class="card <?php echo $is_expired ? 'status-expired' : 'status-active'; ?>" style="border-left:4px solid <?php echo $is_expired ? 'var(--danger)' : 'var(--accent)'; ?>; padding:1.5rem 2rem;">
                <div style="display:flex; align-items:center; gap:1.5rem;">
                    <div class="stat-icon" style="margin:0; background:<?php echo $is_expired ? '#fce8e6' : 'rgba(45,106,79,0.08)'; ?>; color:<?php echo $is_expired ? 'var(--danger)' : 'var(--accent)'; ?>;">
                        <i class="fas fa-<?php echo $is_expired ? 'circle-xmark' : 'circle-check'; ?>"></i>
                    </div>
                    <div>
                        <p class="stat-label">Current Subscription Status</p>
                        <?php if ($current_sub): ?>
                            <p style="font-weight:700; font-size:1rem; color:var(--text-main); margin-bottom:0.2rem;">
                                <?php echo htmlspecialchars($current_sub['plan_name']); ?>
                                — <span style="color:<?php echo $is_expired ? 'var(--danger)' : 'var(--accent)'; ?>;">
                                    <?php echo $is_expired ? 'Expired ' . abs($days_left) . ' days ago' : $days_left . ' days remaining'; ?>
                                </span>
                            </p>
                            <p style="font-size:0.82rem; color:var(--text-muted);">
                                Expires: <?php echo date('F d, Y', strtotime($current_sub['expiry_date'])); ?>
                            </p>
                        <?php else: ?>
                            <p style="font-weight:600; color:var(--text-muted);">No active plan — assigning a new plan will activate this member.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Plan Selection -->
            <div class="card">
                <h3 class="section-title"><i class="fas fa-tags" style="color:var(--accent);"></i> Select New Plan</h3>
                <div class="form-group" style="margin-bottom:1.5rem;">
                    <label>Membership Plan *</label>
                    <select name="plan_id" id="plan-select" class="form-control" required>
                        <option value="" disabled selected>Choose a plan…</option>
                        <?php 
                        $sec_fee = array_filter($plans, fn($p) => ($p['plan_category'] ?? '') === 'membership_fee' || stripos($p['name'], 'Annual Membership') !== false);
                        $sec_daily = array_filter($plans, fn($p) => (intval($p['duration_minutes'] ?? 0) === 1440 || (intval($p['duration_months'] ?? 0) === 0 && (intval($p['duration_minutes'] ?? 0) > 0 || stripos($p['name'], 'Daily') !== false))) && ($p['plan_category'] ?? '') !== 'membership_fee');
                        $sec_monthly = array_filter($plans, fn($p) => intval($p['duration_months'] ?? 0) > 0 && ($p['plan_category'] ?? '') !== 'membership_fee' && stripos($p['name'], 'Annual Membership') === false);
                        ?>
                        <?php if (!empty($sec_fee)): ?>
                        <optgroup label="🏅 Membership Fee">
                            <?php foreach ($sec_fee as $p): ?>
                            <option value="<?php echo $p['id']; ?>" data-price="<?php echo $p['price']; ?>" data-months="<?php echo $p['duration_months']; ?>" data-minutes="<?php echo $p['duration_minutes'] ?? 0; ?>">
                                <?php echo htmlspecialchars($p['name']); ?> — ₱<?php echo number_format($p['price'], 2); ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>

                        <?php if (!empty($sec_daily)): ?>
                        <optgroup label="⚡ Daily Access Passes">
                            <?php foreach ($sec_daily as $p): ?>
                            <option value="<?php echo $p['id']; ?>" data-price="<?php echo $p['price']; ?>" data-months="<?php echo $p['duration_months']; ?>" data-minutes="<?php echo $p['duration_minutes'] ?? 0; ?>">
                                <?php echo htmlspecialchars($p['name']); ?> — ₱<?php echo number_format($p['price'], 2); ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>

                        <?php if (!empty($sec_monthly)): ?>
                        <optgroup label="📅 Monthly / Yearly Registrations">
                            <?php foreach ($sec_monthly as $p): 
                                $dur_label = $p['duration_months'] . ' mo.';
                            ?>
                            <option value="<?php echo $p['id']; ?>" data-price="<?php echo $p['price']; ?>" data-months="<?php echo $p['duration_months']; ?>" data-minutes="<?php echo $p['duration_minutes'] ?? 0; ?>">
                                <?php echo htmlspecialchars($p['name']); ?> — ₱<?php echo number_format($p['price'], 2); ?> / <?php echo $dur_label; ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- Plan Preview -->
                <div id="plan-preview" style="display:none; padding:1rem 1.25rem; background:var(--accent-dim); border-radius:10px; font-size:0.85rem; color:var(--text-soft);">
                    <div style="display:flex; gap:2rem;">
                        <span><i class="fas fa-calendar-days" style="color:var(--accent);"></i> Duration: <strong id="preview-months">—</strong></span>
                        <span><i class="fas fa-arrow-right" style="color:var(--accent);"></i> New Expiry: <strong id="preview-expiry">—</strong></span>
                    </div>
                </div>
            </div>

            <!-- Payment -->
            <div class="card">
                <h3 class="section-title"><i class="fas fa-money-bill-wave" style="color:var(--success);"></i> Payment Details</h3>
                <div class="form-grid" style="grid-template-columns:1fr 1fr; margin-bottom:1.25rem;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Amount Paid (₱) *</label>
                        <input type="number" name="amount_paid" id="amount-paid" class="form-control" placeholder="0.00" min="0" step="0.01" required>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Payment Method *</label>
                        <select name="payment_method" id="payment-method-select" class="form-control" required>
                            <option value="Cash">Cash</option>
                            <option value="GCash">GCash</option>
                            <option value="Maya">Maya</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid" style="grid-template-columns: 1fr 1fr; margin-bottom:1.25rem;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Payment Date</label>
                        <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group" id="ref-number-group" style="margin-bottom:0; display:none;">
                        <label>Reference Number *</label>
                        <input type="text" name="reference_number" class="form-control" placeholder="e.g. 100239401923" title="Required for online transfers">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100" style="padding:1.1rem; font-size:1rem;">
                <i class="fas fa-rotate-right"></i> Confirm Renewal
            </button>
        </div>
    </form>

    <!-- Member Info Sidebar -->
    <div style="display:flex; flex-direction:column; gap:1.5rem;">
        <div class="card" style="text-align:center; padding:2rem;">
            <div class="member-avatar" style="width:80px; height:80px; font-size:2rem; margin:0 auto 1rem; border-radius:20px;">
                <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
            </div>
            <h3 style="font-family:'Playfair Display', serif; margin-bottom:0.25rem;"><?php echo htmlspecialchars($member['full_name']); ?></h3>
            <code class="cell-secondary" style="color:var(--accent);"><?php echo htmlspecialchars($member['membership_id']); ?></code>
            <div style="margin-top:1rem; padding-top:1rem; border-top:1px solid var(--border); text-align:left; display:flex; flex-direction:column; gap:0.6rem;">
                <div class="cell-secondary"><span class="by-label">Email:</span> <span class="cell-primary"><?php echo htmlspecialchars($member['email']); ?></span></div>
                <div class="cell-secondary"><span class="by-label">Status:</span>
                    <?php 
                        $effective_status = ($is_expired) ? 'Expired' : ($member['status'] ?? 'Active');
                        $badge_class = ($effective_status === 'Active') ? 'badge-success' : 'badge-danger';
                    ?>
                    <span class="badge <?php echo $badge_class; ?>" style="margin-left:4px;">
                        <?php echo htmlspecialchars($effective_status); ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="card warning-card">
            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.75rem;">
                <i class="far fa-lightbulb" style="color:#d97706;"></i>
                <span style="font-size:0.7rem; font-weight:800; color:#d97706; text-transform:uppercase; letter-spacing:1px;">Note</span>
            </div>
            <p style="font-size:0.82rem; color:#92400e; line-height:1.6;">
                <?php if ($is_expired): ?>
                    Assigning a new plan will <strong>reactivate</strong> this member's subscription and set their status to <strong>Active</strong>.
                <?php else: ?>
                    Renewing will extend/renew the plan and keep the member's status Active.
                <?php endif; ?>
            </p>
        </div>
    </div>
</div>

<script>
const planSelect  = document.getElementById('plan-select');
const amountInput = document.getElementById('amount-paid');
const preview     = document.getElementById('plan-preview');

planSelect.addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    const price   = parseFloat(opt.dataset.price)   || 0;
    const months  = parseInt(opt.dataset.months)    || 0;
    const minutes = parseInt(opt.dataset.minutes)   || 0;

    amountInput.value = price.toFixed(2);

    const baseExpiry = <?php echo ($current_sub && !empty($current_sub['expiry_date']) && strtotime($current_sub['expiry_date']) > time()) ? json_encode($current_sub['expiry_date']) : 'null'; ?>;
    const expiry = baseExpiry ? new Date(baseExpiry) : new Date();

    if (minutes > 0) {
        expiry.setMinutes(expiry.getMinutes() + minutes);
        document.getElementById('preview-months').textContent = minutes + ' Minute' + (minutes > 1 ? 's' : '');
        document.getElementById('preview-expiry').textContent = expiry.toLocaleString('en-PH', { year:'numeric', month:'short', day:'numeric', hour:'numeric', minute:'2-digit' });
    } else {
        const m = months > 0 ? months : 1;
        expiry.setMonth(expiry.getMonth() + m);
        document.getElementById('preview-months').textContent = m + ' Month' + (m > 1 ? 's' : '');
        document.getElementById('preview-expiry').textContent = expiry.toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' });
    }
    preview.style.display = 'block';
});

// Toggle reference number field
document.getElementById('payment-method-select').addEventListener('change', function() {
    const val = this.value;
    const refGroup = document.getElementById('ref-number-group');
    const refInput = refGroup.querySelector('input');
    if (val === 'GCash' || val === 'Bank Transfer') {
        refGroup.style.display = 'block';
        refInput.required = true;
    } else {
        refGroup.style.display = 'none';
        refInput.required = false;
        refInput.value = '';
    }
});
</script>

<?php include 'includes/footer.php'; ?>
