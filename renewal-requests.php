<?php
$page_title = 'Renewal Requests';
include 'includes/header.php';
include 'includes/sidebar.php';
require_admin();

$message = '';
$error = '';

// Handle POST actions (Approve / Reject) with Idempotency Protection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $request_id = intval($_POST['request_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    try {
        $pdo->beginTransaction();

        // 1. Fetch request details with ROW LOCK to prevent concurrent double-approvals
        $stmt = $pdo->prepare("
            SELECT r.*, m.full_name, m.email, m.status as member_status, p.name as plan_name, p.price as plan_price, p.duration_months 
            FROM renewal_requests r
            JOIN members m ON r.member_id = m.id
            JOIN membership_plans p ON r.plan_id = p.id
            WHERE r.id = ? AND r.status = 'Pending'
            FOR UPDATE
        ");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            $pdo->rollBack();
            $error = 'Renewal request not found or has already been processed.';
        } else {
            $admin_id = $_SESSION['user_id'] ?? null;

            if ($action === 'approve') {
                // 1. Fetch active subscription expiry date if any
                $cur_sub_stmt = $pdo->prepare("
                    SELECT expiry_date FROM subscriptions 
                    WHERE member_id = ? AND expiry_date >= CURDATE() 
                    ORDER BY expiry_date DESC LIMIT 1
                ");
                $cur_sub_stmt->execute([$req['member_id']]);
                $active_sub = $cur_sub_stmt->fetch(PDO::FETCH_ASSOC);

                $base_date = ($active_sub && !empty($active_sub['expiry_date'])) ? $active_sub['expiry_date'] : date('Y-m-d');
                $start_date = $base_date;
                $months = intval($req['duration_months'] ?? 1);
                if ($months <= 0) $months = 1;
                $expiry_date = date('Y-m-d', strtotime("{$base_date} + {$months} months"));

                // 2. Insert new active subscription
                $sub_stmt = $pdo->prepare("
                    INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date, created_by) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $sub_stmt->execute([
                    $req['member_id'],
                    $req['plan_id'],
                    $start_date,
                    $expiry_date,
                    $admin_id
                ]);
                $subscription_id = $pdo->lastInsertId();

                // 3. Update member status to Active
                $pdo->prepare("UPDATE members SET status = 'Active', account_status = 'Approved' WHERE id = ?")
                    ->execute([$req['member_id']]);

                // 4. Record payment
                $pay_stmt = $pdo->prepare("
                    INSERT INTO payments (member_id, subscription_id, amount, payment_method, reference_number, payment_date, verified_by, notes, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $payment_notes = ($req['payment_method'] === 'Cash') 
                    ? 'Front Desk Cash Payment Approved' 
                    : ('Verified ' . $req['payment_method'] . ' Online Transfer' . ($req['reference_no'] ? ' | Ref: ' . $req['reference_no'] : ''));

                $pay_stmt->execute([
                    $req['member_id'],
                    $subscription_id,
                    $req['plan_price'],
                    $req['payment_method'],
                    $req['reference_no'] ?: null,
                    date('Y-m-d'),
                    $admin_id,
                    $payment_notes
                ]);

                // 5. Update request status to Approved
                $up_stmt = $pdo->prepare("
                    UPDATE renewal_requests 
                    SET status = 'Approved', processed_by = ?, notes = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $up_stmt->execute([$admin_id, 'Approved by ' . ($_SESSION['user_name'] ?? 'Admin'), $request_id]);

                $pdo->commit();

                // Send In-App & Email Notification
                try {
                    require_once __DIR__ . '/config/notifications.php';
                    create_notification(
                        $pdo, 
                        (int)$req['member_id'], 
                        'MEMBERSHIP_RENEWED', 
                        'Renewal Approved! 🎉', 
                        "Your renewal request for {$req['plan_name']} was approved! Valid until " . date('F j, Y', strtotime($expiry_date)) . "."
                    );
                } catch (Exception $nE) {}

                if (!empty($req['email'])) {
                    require_once 'config/email.php';
                    $email_subject = "Renewal Approved - Palma's Elite Gym";
                    $email_title = "Renewal Approved!";
                    $email_body = "Hello {$req['full_name']}, your renewal request for the plan <strong>{$req['plan_name']}</strong> has been approved. Your subscription is active and will expire on " . date('F d, Y', strtotime($expiry_date)) . ". Thank you for your payment!";
                    send_email_notification($req['email'], $email_subject, $email_title, $email_body);
                }

                log_activity(
                    $pdo, 
                    'Membership Renewed', 
                    "Approved renewal for {$req['full_name']} (Plan: {$req['plan_name']}, Expiry: {$expiry_date}, Method: {$req['payment_method']})", 
                    'Subscription',
                    $admin_id,
                    $_SESSION['user_name'] ?? 'Admin'
                );

                $message = "Renewal request for <strong>" . htmlspecialchars($req['full_name']) . "</strong> has been approved successfully! Expiry extended to <strong>" . date('M d, Y', strtotime($expiry_date)) . "</strong>.";
            } elseif ($action === 'reject') {
                if (empty($notes)) {
                    $error = 'Please select or provide a reason for rejecting the renewal.';
                    $pdo->rollBack();
                } else {
                    // 1. Update request status to Rejected
                    $up_stmt = $pdo->prepare("
                        UPDATE renewal_requests 
                        SET status = 'Rejected', processed_by = ?, notes = ?, updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $up_stmt->execute([$admin_id, $notes, $request_id]);

                    $pdo->commit();

                    // Send In-App & Email Notification
                    try {
                        require_once __DIR__ . '/config/notifications.php';
                        create_notification(
                            $pdo, 
                            (int)$req['member_id'], 
                            'MEMBERSHIP_RENEWAL_REJECTED', 
                            'Renewal Request Declined', 
                            "Your renewal for {$req['plan_name']} was declined. Reason: {$notes}. Please contact the front desk."
                        );
                    } catch (Exception $nE) {}

                    if (!empty($req['email'])) {
                        require_once 'config/email.php';
                        $email_subject = "Renewal Request Update - Palma's Elite Gym";
                        $email_title = "Renewal Request Declined";
                        $email_body = "Hello {$req['full_name']}, your renewal request for the plan <strong>{$req['plan_name']}</strong> was declined. <br><br><strong>Reason:</strong> <em>\"" . htmlspecialchars($notes) . "\"</em>.<br><br>Please contact the front desk or submit a new request with valid payment details.";
                        send_email_notification($req['email'], $email_subject, $email_title, $email_body);
                    }

                    log_activity(
                        $pdo, 
                        'Rejected Renewal', 
                        "Rejected renewal for {$req['full_name']} (Reason: {$notes})", 
                        'Subscription',
                        $admin_id,
                        $_SESSION['user_name'] ?? 'Admin'
                    );

                    $message = "Renewal request for <strong>" . htmlspecialchars($req['full_name']) . "</strong> has been rejected.";
                }
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('System Error in renewal-requests.php: ' . $e->getMessage());
        $error = 'A system error occurred while processing the request.';
    }
}

// Fetch counts for tabs
$count_pending = 0;
$count_approved = 0;
$count_rejected = 0;
$count_all = 0;

try {
    $count_pending  = (int)$pdo->query("SELECT COUNT(*) FROM renewal_requests WHERE status = 'Pending'")->fetchColumn();
    $count_approved = (int)$pdo->query("SELECT COUNT(*) FROM renewal_requests WHERE status = 'Approved'")->fetchColumn();
    $count_rejected = (int)$pdo->query("SELECT COUNT(*) FROM renewal_requests WHERE status = 'Rejected'")->fetchColumn();
    $count_all      = (int)$pdo->query("SELECT COUNT(*) FROM renewal_requests")->fetchColumn();
} catch (Exception $e) {}

// Determine status filter
$status_filter = $_GET['status'] ?? 'Pending';
if (!in_array($status_filter, ['Pending', 'Approved', 'Rejected', 'All'])) {
    $status_filter = 'Pending';
}

$requests = [];
try {
    $query = "
        SELECT r.*, m.full_name, m.membership_id, m.status as member_status,
               (SELECT expiry_date FROM subscriptions WHERE member_id = m.id ORDER BY expiry_date DESC LIMIT 1) as current_expiry,
               p.name as plan_name, p.price as plan_price, p.duration_months,
               u.name as admin_name
        FROM renewal_requests r
        JOIN members m ON r.member_id = m.id
        JOIN membership_plans p ON r.plan_id = p.id
        LEFT JOIN users u ON r.processed_by = u.id
    ";
    if ($status_filter !== 'All') {
        $query .= " WHERE r.status = :status";
    }
    $query .= " ORDER BY r.created_at DESC";

    $stmt = $pdo->prepare($query);
    if ($status_filter !== 'All') {
        $stmt->execute(['status' => $status_filter]);
    } else {
        $stmt->execute();
    }
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>

<div class="topbar">
    <div class="page-title">
        <h1>Renewal & Payment Requests</h1>
        <p>Review, verify, and approve member renewal requests (GCash, Maya, and Front-Desk Cash).</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success" style="margin-bottom:1.5rem;"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:1.5rem;"><i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="tab-nav">
    <a href="?status=Pending" class="btn <?php echo $status_filter === 'Pending' ? 'btn-primary' : 'btn-outline'; ?>">
        Pending <span class="tab-count"><?php echo $count_pending; ?></span>
    </a>
    <a href="?status=Approved" class="btn <?php echo $status_filter === 'Approved' ? 'btn-primary' : 'btn-outline'; ?>">
        Approved <span class="tab-count"><?php echo $count_approved; ?></span>
    </a>
    <a href="?status=Rejected" class="btn <?php echo $status_filter === 'Rejected' ? 'btn-primary' : 'btn-outline'; ?>">
        Rejected <span class="tab-count"><?php echo $count_rejected; ?></span>
    </a>
    <a href="?status=All" class="btn <?php echo $status_filter === 'All' ? 'btn-primary' : 'btn-outline'; ?>">
        All <span class="tab-count"><?php echo $count_all; ?></span>
    </a>
</div>

<div class="card">
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>Member Details</th>
                    <th>Plan & Amount</th>
                    <th>Current Expiry</th>
                    <th>Payment Method & Ref</th>
                    <th>Status</th>
                    <th>Actions / Review</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <?php render_empty_state('fas fa-file-invoice-dollar', 'No renewal requests found in this section.', '', true); ?>
                <?php else: ?>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <!-- Date & Time -->
                            <td class="cell-secondary" style="white-space:nowrap;">
                                <div style="font-weight:600; color:var(--text-main);"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></div>
                                <div style="font-size:0.75rem; color:var(--text-muted);"><i class="far fa-clock"></i> <?php echo date('h:i A', strtotime($r['created_at'])); ?></div>
                            </td>

                            <!-- Member Name & ID -->
                            <td>
                                <div class="member-cell">
                                    <div class="member-avatar"><?php echo strtoupper(substr($r['full_name'], 0, 1)); ?></div>
                                    <div>
                                        <div class="cell-primary" style="font-weight:600;"><?php echo htmlspecialchars($r['full_name']); ?></div>
                                        <code class="cell-secondary" style="font-size:0.75rem;"><?php echo htmlspecialchars($r['membership_id']); ?></code>
                                    </div>
                                </div>
                            </td>

                            <!-- Plan & Amount -->
                            <td>
                                <div class="cell-primary" style="font-weight:600;"><?php echo htmlspecialchars($r['plan_name']); ?></div>
                                <div style="font-weight:700; color:var(--success); font-size:0.95rem;">₱<?php echo number_format($r['plan_price'], 2); ?></div>
                                <div class="cell-secondary" style="font-size:0.72rem;"><?php echo $r['duration_months']; ?> month(s)</div>
                            </td>

                            <!-- Current Expiration -->
                            <td>
                                <?php if (!empty($r['current_expiry'])): ?>
                                    <?php 
                                    $is_expired = (strtotime($r['current_expiry']) < strtotime(date('Y-m-d')));
                                    ?>
                                    <div style="font-size:0.85rem; font-weight:600; color:<?php echo $is_expired ? 'var(--danger)' : 'var(--text-main)'; ?>;">
                                        <?php echo date('M d, Y', strtotime($r['current_expiry'])); ?>
                                    </div>
                                    <div style="font-size:0.72rem; color:<?php echo $is_expired ? 'var(--danger)' : 'var(--text-muted)'; ?>;">
                                        <?php echo $is_expired ? '● Expired' : '● Active'; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="cell-secondary" style="font-size:0.8rem;">No prior plan</span>
                                <?php endif; ?>
                            </td>

                            <!-- Payment Details -->
                            <td>
                                <?php if ($r['payment_method'] === 'GCash'): ?>
                                    <span class="badge" style="background:rgba(59,130,246,0.12); color:#2563eb; border:1px solid rgba(59,130,246,0.25);">
                                        <i class="fas fa-mobile-screen"></i> GCash
                                    </span>
                                <?php elseif ($r['payment_method'] === 'Maya'): ?>
                                    <span class="badge" style="background:rgba(16,185,129,0.12); color:#059669; border:1px solid rgba(16,185,129,0.25);">
                                        <i class="fas fa-wallet"></i> Maya
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background:rgba(82,183,136,0.12); color:var(--brand-primary); border:1px solid rgba(82,183,136,0.25);">
                                        <i class="fas fa-hand-holding-dollar"></i> Cash
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($r['reference_no'])): ?>
                                    <div style="margin-top:4px; font-size:0.78rem;">
                                        <span style="color:var(--text-muted);">Ref:</span> <code style="background:var(--primary-bg); border:1px solid var(--border); padding:2px 6px; border-radius:4px; font-weight:700; color:var(--text-main);"><?php echo htmlspecialchars($r['reference_no']); ?></code>
                                    </div>
                                <?php elseif ($r['payment_method'] === 'Cash'): ?>
                                    <div style="margin-top:4px; font-size:0.72rem; color:var(--warning); font-weight:600;">
                                        <i class="fas fa-circle-exclamation"></i> Collect at Desk
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- Status Badge -->
                            <td>
                                <?php if ($r['status'] === 'Pending'): ?>
                                    <?php if ($r['payment_method'] === 'Cash'): ?>
                                        <span class="badge badge-warning"><i class="fas fa-clock"></i> Pending Cash</span>
                                    <?php else: ?>
                                        <span class="badge badge-pending"><i class="fas fa-clock"></i> Pending Online</span>
                                    <?php endif; ?>
                                <?php elseif ($r['status'] === 'Approved'): ?>
                                    <span class="badge badge-success"><i class="fas fa-check-circle"></i> Approved</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fas fa-times-circle"></i> Rejected</span>
                                <?php endif; ?>
                            </td>

                            <!-- Actions -->
                            <td>
                                <?php if ($r['status'] === 'Pending'): ?>
                                    <div style="display:flex; gap:0.5rem; flex-wrap:nowrap;">
                                        <button type="button" class="btn btn-primary btn-sm approve-action-btn"
                                            style="background:var(--success); font-size:0.78rem; padding:0.4rem 0.75rem; white-space:nowrap;"
                                            data-id="<?php echo $r['id']; ?>"
                                            data-name="<?php echo htmlspecialchars(addslashes($r['full_name'])); ?>"
                                            data-method="<?php echo htmlspecialchars($r['payment_method']); ?>"
                                            data-amount="<?php echo number_format($r['plan_price'], 2); ?>"
                                            onclick="confirmApprove(this)">
                                            <?php if ($r['payment_method'] === 'Cash'): ?>
                                                <i class="fas fa-money-bill-check"></i> Confirm Cash
                                            <?php else: ?>
                                                <i class="fas fa-check"></i> Approve
                                            <?php endif; ?>
                                        </button>
                                        <button type="button" class="btn btn-outline btn-sm"
                                            style="border-color:var(--danger); color:var(--danger); font-size:0.78rem; padding:0.4rem 0.65rem;"
                                            onclick="openRejectModal(<?php echo $r['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['full_name'])); ?>')">
                                            <i class="fas fa-xmark"></i> Reject
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="cell-meta" style="font-size:0.8rem;">
                                        <span class="by-label">By:</span> <strong><?php echo htmlspecialchars($r['admin_name'] ?: 'Admin'); ?></strong>
                                        <?php if ($r['notes']): ?>
                                            <div class="notes-label" style="margin-top:2px; font-style:italic; color:var(--text-muted);">"<?php echo htmlspecialchars($r['notes']); ?>"</div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Hidden approve form (submitted via palmasConfirm) -->
<form method="POST" action="" id="approve-form" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
    <input type="hidden" name="action" value="approve">
    <input type="hidden" name="request_id" id="approve-request-id" value="">
</form>

<!-- Reject Reason Modal with Quick Presets -->
<div class="modal-overlay" id="reject-modal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h3><i class="fas fa-circle-xmark" style="color:var(--danger); margin-right:8px;"></i> Reject Renewal Request</h3>
            <button class="modal-close" onclick="closeRejectModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <p style="color:var(--text-muted); font-size:0.875rem; margin-bottom:1rem;">
            Please select or specify a reason for rejecting the renewal of <strong id="reject-member-name" style="color:var(--text-main);"></strong>.
        </p>

        <!-- Quick Preset Reasons -->
        <div style="margin-bottom:1rem;">
            <p style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.5rem;">Quick Reasons:</p>
            <div style="display:flex; flex-wrap:wrap; gap:0.4rem;">
                <button type="button" class="btn btn-outline btn-sm preset-btn" onclick="applyPreset('Invalid Reference Number / Ref not found.')">Invalid Ref No.</button>
                <button type="button" class="btn btn-outline btn-sm preset-btn" onclick="applyPreset('Payment was not received in our GCash/Maya account.')">Payment Not Found</button>
                <button type="button" class="btn btn-outline btn-sm preset-btn" onclick="applyPreset('Incorrect amount sent for the selected membership plan.')">Incorrect Amount</button>
                <button type="button" class="btn btn-outline btn-sm preset-btn" onclick="applyPreset('Duplicate transaction or reference number already used.')">Duplicate Transaction</button>
                <button type="button" class="btn btn-outline btn-sm preset-btn" onclick="applyPreset('Member did not settle cash payment at the front desk.')">Unpaid Cash</button>
            </div>
        </div>

        <form method="POST" action="" id="reject-form">
            <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="reject-request-id" value="">
            <div class="form-group">
                <label>Rejection Reason Details *</label>
                <textarea name="notes" id="reject-notes" class="form-control" rows="3"
                    placeholder="Select a quick reason above or type a custom reason..." required></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1.25rem;">
                <button type="button" onclick="closeRejectModal()" class="btn btn-outline">Cancel</button>
                <button type="submit" id="reject-submit-btn" class="btn btn-primary" style="background:var(--danger); border-color:var(--danger);">
                    <i class="fas fa-xmark"></i> Confirm Rejection
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function confirmApprove(button) {
    const id     = button.dataset.id;
    const name   = button.dataset.name;
    const method = button.dataset.method;
    const amount = button.dataset.amount;

    let confirmMsg = `Approve ${method} renewal payment of <strong>₱${amount}</strong> for <strong>${name}</strong>? This will activate their membership immediately.`;
    if (method === 'Cash') {
        confirmMsg = `Confirm that you have received <strong>₱${amount} CASH</strong> at the front desk from <strong>${name}</strong>?`;
    }

    palmasConfirm(
        method === 'Cash' ? 'Confirm Cash Payment' : 'Approve Online Payment',
        confirmMsg,
        method === 'Cash' ? 'Confirm Received' : 'Approve',
        'var(--success)',
        function() {
            // Anti-duplicate protection: disable button and submit
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            document.getElementById('approve-request-id').value = id;
            document.getElementById('approve-form').submit();
        }
    );
}

function openRejectModal(id, name) {
    document.getElementById('reject-request-id').value = id;
    document.getElementById('reject-member-name').textContent = name;
    document.getElementById('reject-notes').value = '';
    document.getElementById('reject-modal').classList.add('active');
}

function closeRejectModal() {
    document.getElementById('reject-modal').classList.remove('active');
}

function applyPreset(reason) {
    const textarea = document.getElementById('reject-notes');
    textarea.value = reason;
    textarea.focus();
}

// Anti-duplicate protection on Reject submit
document.getElementById('reject-form').addEventListener('submit', function() {
    const btn = document.getElementById('reject-submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Rejecting...';
});
</script>

<?php include 'includes/footer.php'; ?>
