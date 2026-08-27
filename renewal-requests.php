<?php
$page_title = 'Renewal Requests';
include 'includes/header.php';
include 'includes/sidebar.php';
require_admin();

$message = '';
$error = '';

// Handle POST actions (Approve / Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $request_id = intval($_POST['request_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    try {
        // Fetch request details
        $stmt = $pdo->prepare("
            SELECT r.*, m.full_name, m.status as member_status, p.name as plan_name, p.price as plan_price, p.duration_months 
            FROM renewal_requests r
            JOIN members m ON r.member_id = m.id
            JOIN membership_plans p ON r.plan_id = p.id
            WHERE r.id = ? AND r.status = 'Pending'
        ");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();

        if (!$req) {
            $error = 'Renewal request not found or already processed.';
        } else {
            $pdo->beginTransaction();

            $admin_id = $_SESSION['user_id'] ?? null;

            if ($action === 'approve') {
                // 1. Expire existing active subscriptions for this member (set expiry to yesterday)
                $pdo->prepare("UPDATE subscriptions SET expiry_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE member_id = ? AND expiry_date >= CURDATE()")
                    ->execute([$req['member_id']]);

                // 2. Calculate subscription dates
                $start_date = date('Y-m-d');
                $months = intval($req['duration_months'] ?? 1);
                $expiry_date = date('Y-m-d', strtotime('+' . $months . ' months'));

                // 3. Insert new active subscription
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

                // 4. Update member status to Active
                $pdo->prepare("UPDATE members SET status = 'Active' WHERE id = ?")
                    ->execute([$req['member_id']]);

                // 5. Record payment
                $pay_stmt = $pdo->prepare("
                    INSERT INTO payments (member_id, subscription_id, amount, payment_method, payment_date, notes, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $payment_notes = 'Approved renewal via Member Portal' . ($req['reference_no'] ? ' | Ref: ' . $req['reference_no'] : '');
                $pay_stmt->execute([
                    $req['member_id'],
                    $subscription_id,
                    $req['plan_price'],
                    $req['payment_method'],
                    $start_date,
                    $payment_notes
                ]);

                // 6. Update request status to Approved
                $up_stmt = $pdo->prepare("
                    UPDATE renewal_requests 
                    SET status = 'Approved', processed_by = ?, notes = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $up_stmt->execute([$admin_id, 'Approved by admin', $request_id]);

                $pdo->commit();

                // Send Email Notification
                require_once 'config/email.php';
                $email_subject = "Renewal Approved - Palma's Elite Gym";
                $email_title = "Renewal Approved!";
                $email_body = "Hello {$req['full_name']}, your renewal request for the plan <strong>{$req['plan_name']}</strong> has been approved. Your new subscription is active and will expire on " . date('F d, Y', strtotime($expiry_date)) . ". Thank you for your payment!";
                send_email_notification($req['email'], $email_subject, $email_title, $email_body);

                log_activity(
                    $pdo, 
                    'Approved Renewal', 
                    "Approved renewal for {$req['full_name']} (Plan: {$req['plan_name']}, Expiry: {$expiry_date})", 
                    'Subscription',
                    $admin_id,
                    $_SESSION['user_name'] ?? 'Admin'
                );

                $message = "Renewal request for <strong>" . htmlspecialchars($req['full_name']) . "</strong> has been approved successfully!";
            } elseif ($action === 'reject') {
                if (empty($notes)) {
                    $error = 'Please provide a reason for rejecting the renewal.';
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

                    // Send Email Notification
                    require_once 'config/email.php';
                    $email_subject = "Renewal Request Update - Palma's Elite Gym";
                    $email_title = "Renewal Request Declined";
                    $email_body = "Hello {$req['full_name']}, your renewal request for the plan <strong>{$req['plan_name']}</strong> was declined by the administrator. Reason: <em>\"{$notes}\"</em>. Please contact the front desk or submit another request with corrected payment details.";
                    send_email_notification($req['email'], $email_subject, $email_title, $email_body);

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
    $count_pending  = $pdo->query("SELECT COUNT(*) FROM renewal_requests WHERE status = 'Pending'")->fetchColumn();
    $count_approved = $pdo->query("SELECT COUNT(*) FROM renewal_requests WHERE status = 'Approved'")->fetchColumn();
    $count_rejected = $pdo->query("SELECT COUNT(*) FROM renewal_requests WHERE status = 'Rejected'")->fetchColumn();
    $count_all      = $pdo->query("SELECT COUNT(*) FROM renewal_requests")->fetchColumn();
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
        <h1>Renewal Requests</h1>
        <p>Review and verify self-renewal payments submitted by gym members.</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success" style="margin-bottom:1.5rem;"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:1.5rem;"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
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
                    <th>Date Requested</th>
                    <th>Member</th>
                    <th>ID</th>
                    <th>Plan</th>
                    <th>Payment Details</th>
                    <th>Status</th>
                    <th>Actions / Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <?php render_empty_state('fas fa-file-invoice-dollar', 'No renewal requests found in this section.', '', true); ?>
                <?php else: ?>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <td class="cell-secondary"><?php echo date('M d, Y · h:i A', strtotime($r['created_at'])); ?></td>
                            <td>
                                <div class="member-cell">
                                    <div class="member-avatar"><?php echo strtoupper(substr($r['full_name'], 0, 1)); ?></div>
                                    <div>
                                        <span class="cell-primary"><?php echo htmlspecialchars($r['full_name']); ?></span>
                                    </div>
                                </div>
                            </td>
                            <td><code class="cell-secondary"><?php echo htmlspecialchars($r['membership_id']); ?></code></td>
                            <td>
                                <div class="cell-primary"><?php echo htmlspecialchars($r['plan_name']); ?></div>
                                <div class="cell-secondary">₱<?php echo number_format($r['plan_price'], 2); ?> / <?php echo $r['duration_months']; ?> mo.</div>
                            </td>
                            <td>
                                <span class="cell-primary">
                                    <i class="fas fa-wallet" style="margin-right:4px; opacity:0.6;"></i> <?php echo htmlspecialchars($r['payment_method']); ?>
                                </span>
                                <?php if ($r['reference_no']): ?>
                                    <div class="cell-secondary" style="margin-top:2px;">Ref: <code style="background:var(--accent-dim); padding:1px 4px; border-radius:4px;"><?php echo htmlspecialchars($r['reference_no']); ?></code></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['status'] === 'Pending'): ?>
                                    <span class="badge badge-pending">Pending</span>
                                <?php elseif ($r['status'] === 'Approved'): ?>
                                    <span class="badge badge-success">Approved</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Rejected</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['status'] === 'Pending'): ?>
                                    <div style="display:flex; gap:0.5rem;">
                                        <button type="button" class="btn btn-primary btn-sm"
                                            style="background:var(--success); font-size:0.75rem;"
                                            data-id="<?php echo $r['id']; ?>"
                                            data-name="<?php echo htmlspecialchars(addslashes($r['full_name'])); ?>"
                                            onclick="confirmApprove(this.dataset.id, this.dataset.name)">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button type="button" class="btn btn-outline btn-sm"
                                            style="border-color:var(--danger); color:var(--danger); font-size:0.75rem;"
                                            onclick="openRejectModal(<?php echo $r['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['full_name'])); ?>')">
                                            <i class="fas fa-xmark"></i> Reject
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="cell-meta">
                                        <span class="by-label">By:</span> <strong><?php echo htmlspecialchars($r['admin_name'] ?: 'System'); ?></strong>
                                        <?php if ($r['notes']): ?>
                                            <div class="notes-label">Notes: "<?php echo htmlspecialchars($r['notes']); ?>"</div>
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

<!-- Reject Reason Modal -->
<div class="modal-overlay" id="reject-modal">
    <div class="modal" style="max-width:450px;">
        <div class="modal-header">
            <h3><i class="fas fa-circle-xmark" style="color:var(--danger); margin-right:8px;"></i> Reject Renewal</h3>
            <button class="modal-close" onclick="closeRejectModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <p style="color:var(--text-muted); font-size:0.875rem; margin-bottom:1.25rem;">
            Specify a reason for rejecting the renewal from <strong id="reject-member-name"></strong>. This will be visible to the member.
        </p>
        <form method="POST" action="" id="reject-form">
            <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="reject-request-id" value="">
            <div class="form-group">
                <label>Rejection Reason *</label>
                <textarea name="notes" id="reject-notes" class="form-control" rows="4"
                    placeholder="e.g. Invalid reference number, transaction not received…" required></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1rem;">
                <button type="button" onclick="closeRejectModal()" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:var(--danger);">Confirm Rejection</button>
            </div>
        </form>
    </div>
</div>

<script>
function confirmApprove(id, name) {
    palmasConfirm(
        'Approve Renewal',
        `Approve the renewal request from <strong style="color:var(--text-main);">${name}</strong>? This will activate their membership immediately.`,
        'Approve',
        'var(--success)',
        function() {
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
</script>

<?php include 'includes/footer.php'; ?>
