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

        $plans = $pdo->query("SELECT id, name, price, duration_months FROM membership_plans ORDER BY price ASC")->fetchAll();

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
    $amount_paid    = floatval($_POST['amount_paid'] ?? 0);
    $payment_method = $_POST['payment_method'] ?? 'Cash';
    $payment_date   = $_POST['payment_date'] ?? date('Y-m-d');
    $notes          = trim($_POST['notes'] ?? '');

    if (!$plan_id) {
        $error = 'Please select a membership plan.';
    } elseif ($amount_paid < 0) {
        $error = 'Amount paid cannot be negative.';
    } else {
        try {
            // Calculate renewal expiry: if still active, extend from current expiry; otherwise start from today
            $base_date = date('Y-m-d');
            if ($current_sub && !empty($current_sub['expiry_date']) && strtotime($current_sub['expiry_date']) >= strtotime(date('Y-m-d'))) {
                $base_date = $current_sub['expiry_date'];
            }

            // Get plan duration
            $p_stmt = $pdo->prepare("SELECT duration_months, name FROM membership_plans WHERE id = ?");
            $p_stmt->execute([$plan_id]);
            $plan = $p_stmt->fetch();

            $duration_months = intval($plan['duration_months'] ?? 1);
            $start_date  = $base_date;
            $expiry_date = date('Y-m-d', strtotime("{$base_date} + {$duration_months} months"));

            // Insert new subscription
            $stmt = $pdo->prepare("INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$member_id, $plan_id, $start_date, $expiry_date, $_SESSION['user_id'] ?? null]);
            $subscription_id = $pdo->lastInsertId();

            // Reactivate member status
            $pdo->prepare("UPDATE members SET status = 'Active' WHERE id = ?")->execute([$member_id]);

            // Record payment
            $reference_num = trim($_POST['reference_number'] ?? '');
            if (in_array($payment_method, ['GCash', 'Bank Transfer']) && empty($reference_num)) {
                throw new Exception("Reference number is required for GCash and Bank Transfer.");
            }
            $verified_by = $_SESSION['user_id'] ?? null;

            $stmt = $pdo->prepare("INSERT INTO payments (member_id, subscription_id, amount, payment_method, reference_number, payment_date, verified_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$member_id, $subscription_id, $amount_paid, $payment_method, $reference_num ?: null, $payment_date, $verified_by]);

            $pdo->commit();

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
if ($current_sub && $current_sub['expiry_date']) {
    $days_left  = ceil((strtotime($current_sub['expiry_date']) - time()) / 86400);
    $is_expired = $days_left < 0;
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

<div style="display:grid; grid-template-columns: 1fr 340px; gap:2rem; align-items:start;">

    <form method="POST" action="">
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
                        <?php foreach ($plans as $p): ?>
                        <option value="<?php echo $p['id']; ?>"
                                data-price="<?php echo $p['price']; ?>"
                                data-months="<?php echo $p['duration_months']; ?>">
                            <?php echo htmlspecialchars($p['name']); ?> — ₱<?php echo number_format($p['price'], 2); ?> / <?php echo $p['duration_months']; ?> mo.
                        </option>
                        <?php endforeach; ?>
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
                    <span class="badge <?php echo $member['status']==='Active' ? 'badge-success' : 'badge-danger'; ?>" style="margin-left:4px;">
                        <?php echo htmlspecialchars($member['status']); ?>
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
                Renewing will <strong>expire the current active plan</strong> immediately and start the new one from today. The member's status will be set to Active.
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
    const price  = parseFloat(opt.dataset.price)  || 0;
    const months = parseInt(opt.dataset.months)   || 1;

    amountInput.value = price.toFixed(2);

    const expiry = new Date();
    expiry.setMonth(expiry.getMonth() + months);
    document.getElementById('preview-months').textContent = months + ' Month' + (months > 1 ? 's' : '');
    document.getElementById('preview-expiry').textContent = expiry.toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' });
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
