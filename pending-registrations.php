<?php
$page_title = 'Pending Registrations';
include 'includes/header.php';
include 'includes/sidebar.php';

$message = '';
$error = '';

// Process POST Actions (Approve / Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $member_id = intval($_POST['member_id'] ?? 0);
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');
    $admin_id = $_SESSION['user_id'] ?? null;
    $admin_name = $_SESSION['user_name'] ?? 'Admin';

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT m.*, p.name as plan_name, p.duration_months, p.price as plan_price
            FROM members m
            LEFT JOIN membership_plans p ON p.id = m.selected_plan_id
            WHERE m.id = ? AND m.account_status = 'Pending'
            FOR UPDATE
        ");
        $stmt->execute([$member_id]);
        $pending_member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pending_member) {
            $pdo->rollBack();
            $error = 'Pending registration not found or already processed.';
        } else {
            if ($action === 'approve') {
                $plan_id = $pending_member['selected_plan_id'];
                $months = intval($pending_member['duration_months'] ?? 1);
                if ($plan_id <= 0) {
                    $first_plan = $pdo->query("SELECT id, duration_months FROM membership_plans ORDER BY price ASC LIMIT 1")->fetch();
                    if ($first_plan) {
                        $plan_id = (int)$first_plan['id'];
                        $months = (int)$first_plan['duration_months'];
                    }
                }

                $start_date = date('Y-m-d');
                $expiry_date = date('Y-m-d', strtotime("+{$months} months"));

                // 1. Update Member status
                $upd = $pdo->prepare("
                    UPDATE members 
                    SET account_status = 'Approved', 
                        status = 'Active', 
                        approved_by = ?, 
                        approved_at = NOW(),
                        rejection_reason = NULL
                    WHERE id = ?
                ");
                $upd->execute([$admin_id, $member_id]);

                // 2. Create Active Subscription if plan exists
                if ($plan_id > 0) {
                    $sub_stmt = $pdo->prepare("
                        INSERT INTO subscriptions (member_id, plan_id, start_date, expiry_date, created_by)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $sub_stmt->execute([$member_id, $plan_id, $start_date, $expiry_date, $admin_id]);
                }

                // 3. Log Activity
                log_activity($pdo, 'Member Registration Approved', "Admin approved registration for {$pending_member['full_name']} ({$pending_member['membership_id']})", 'Member', $admin_id, $admin_name);

                // 4. Send Notification & Email
                try {
                    require_once __DIR__ . '/config/notifications.php';
                    create_notification(
                        $pdo, 
                        $member_id, 
                        'ACCOUNT_APPROVED', 
                        'Account Approved 🎉', 
                        "Your Palma's Elite Gym account has been approved! You can now log in to the Member Portal and Mobile App."
                    );

                    require_once __DIR__ . '/config/email.php';
                    $email_subject = "Your Palma's Elite Gym Account Has Been Approved";
                    $email_title   = "Hello, {$pending_member['full_name']}!";
                    $email_body    = "Good news!<br><br>Your registration for Palma's Elite Gym has been <strong>Approved</strong>.<br><br>Your Membership ID is: <strong>{$pending_member['membership_id']}</strong>.<br><br>You can now log in to the Palma's Elite Gym mobile application and member portal to access your account.";
                    send_email_notification($pending_member['email'], $email_subject, $email_title, $email_body);
                } catch (Exception $emErr) {}

                $pdo->commit();
                $message = "Member <strong>" . htmlspecialchars($pending_member['full_name']) . "</strong> ({$pending_member['membership_id']}) has been successfully approved and activated!";

            } elseif ($action === 'reject') {
                if (empty($rejection_reason)) {
                    $pdo->rollBack();
                    $error = "Please provide a reason for rejecting the registration.";
                } else {
                    // Update Member status to Rejected
                    $upd = $pdo->prepare("
                        UPDATE members 
                        SET account_status = 'Rejected', 
                            status = 'Inactive', 
                            rejection_reason = ?,
                            approved_by = ?, 
                            approved_at = NOW()
                        WHERE id = ?
                    ");
                    $upd->execute([$rejection_reason, $admin_id, $member_id]);

                    // Log Activity
                    log_activity($pdo, 'Member Registration Rejected', "Registration rejected for {$pending_member['full_name']} ({$pending_member['membership_id']}). Reason: {$rejection_reason}", 'Member', $admin_id, $admin_name);

                    // Send Notification & Email
                    try {
                        require_once __DIR__ . '/config/notifications.php';
                        create_notification(
                            $pdo, 
                            $member_id, 
                            'ACCOUNT_REJECTED', 
                            'Registration Update', 
                            "Your registration was not approved. Reason: {$rejection_reason}"
                        );

                        require_once __DIR__ . '/config/email.php';
                        $email_subject = "Update Regarding Your Palma's Elite Gym Registration";
                        $email_title   = "Hello, {$pending_member['full_name']}";
                        $email_body    = "Thank you for registering with Palma's Elite Gym.<br><br>Unfortunately, your registration was not approved at this time.<br><br><strong>Reason:</strong> " . htmlspecialchars($rejection_reason) . "<br><br>Please contact the gym staff if you need assistance.<br><br>Thank you,<br>Palma's Elite Gym Management";
                        send_email_notification($pending_member['email'], $email_subject, $email_title, $email_body);
                    } catch (Exception $emErr) {}

                    $pdo->commit();
                    $message = "Registration for <strong>" . htmlspecialchars($pending_member['full_name']) . "</strong> has been rejected.";
                }
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "An error occurred while processing the request: " . $e->getMessage();
    }
}

// Fetch Pending Registrations
$pending_list = [];
$history_list = [];
try {
    if (isset($pdo) && $pdo) {
        $stmt = $pdo->query("
            SELECT m.*, p.name as plan_name, p.price as plan_price, p.duration_months
            FROM members m
            LEFT JOIN membership_plans p ON p.id = m.selected_plan_id
            WHERE m.account_status = 'Pending'
            ORDER BY m.created_at ASC
        ");
        $pending_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Recent Approval/Rejection History
        $hist_stmt = $pdo->query("
            SELECT m.*, u.name as reviewer_name, p.name as plan_name
            FROM members m
            LEFT JOIN users u ON u.id = m.approved_by
            LEFT JOIN membership_plans p ON p.id = m.selected_plan_id
            WHERE m.account_status IN ('Approved', 'Rejected') AND m.approved_at IS NOT NULL
            ORDER BY m.approved_at DESC
            LIMIT 10
        ");
        $history_list = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}
?>

<div class="topbar">
    <div class="page-title">
        <h1>Pending Registrations</h1>
        <p>Review and approve new member sign-ups before granting gym access.</p>
    </div>
    <div style="display:flex;gap:0.75rem;align-items:center;">
        <span class="badge badge-gold" style="font-size:0.9rem;padding:0.5rem 1rem;">
            <i class="fas fa-user-clock"></i> <?php echo count($pending_list); ?> Awaiting Review
        </span>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success" style="background:#e6f4ea;color:#137333;border:1px solid rgba(30,142,62,0.2);padding:1rem;border-radius:12px;margin-bottom:1.5rem;">
    <i class="fas fa-check-circle"></i> <?php echo $message; ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger" style="background:#fce8e6;color:#c5221f;border:1px solid rgba(217,48,37,0.2);padding:1rem;border-radius:12px;margin-bottom:1.5rem;">
    <i class="fas fa-circle-exclamation"></i> <?php echo $error; ?>
</div>
<?php endif; ?>

<!-- PENDING REGISTRATIONS CARD -->
<div class="card" style="margin-bottom: 2rem;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 class="section-title" style="margin:0;"><i class="fas fa-clock" style="color:var(--accent);"></i> Applications Waiting for Approval</h3>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Reference ID</th>
                    <th>Contact & Gender</th>
                    <th>Requested Plan</th>
                    <th>Registered At</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pending_list)): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state" style="padding:3.5rem 0; text-align:center;">
                            <i class="fas fa-clipboard-check" style="font-size:2.5rem; opacity:0.15; margin-bottom:1rem; display:block; color:var(--success);"></i>
                            <p style="font-weight:600; color:var(--text-main); font-size:1.1rem; margin:0 0 4px 0;">All caught up!</p>
                            <p style="color:var(--text-muted); font-size:0.85rem; margin:0;">There are currently no member registrations waiting for approval.</p>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($pending_list as $pm): ?>
                <tr>
                    <td>
                        <div class="member-cell">
                            <?php if (!empty($pm['google_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($pm['google_picture']); ?>" alt=""
                                     style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #4285F4;"
                                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex';" />
                                <div class="member-avatar" style="background:var(--accent-dim);color:var(--accent);font-weight:700;display:none;">
                                    <?php echo strtoupper(substr($pm['full_name'], 0, 1)); ?>
                                </div>
                            <?php else: ?>
                                <div class="member-avatar" style="background:var(--accent-dim);color:var(--accent);font-weight:700;">
                                    <?php echo strtoupper(substr($pm['full_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <div style="font-weight:600;color:var(--text-main);"><?php echo htmlspecialchars($pm['full_name']); ?></div>
                                <div style="font-size:0.75rem;color:var(--text-muted);"><?php echo htmlspecialchars($pm['email']); ?></div>
                                <?php if (($pm['auth_provider'] ?? 'password') === 'google' || ($pm['auth_provider'] ?? '') === 'both'): ?>
                                    <span style="background:rgba(66,133,244,0.15);color:#4285F4;border-radius:6px;padding:1px 7px;font-size:0.68rem;font-weight:700;display:inline-block;margin-top:3px;">
                                        <i class="fab fa-google"></i> Google Sign-In
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>

                    <td><code style="font-size:0.85rem;color:var(--accent);font-weight:700;"><?php echo htmlspecialchars($pm['membership_id']); ?></code></td>
                    <td>
                        <div style="font-size:0.85rem;color:var(--text-main);"><?php echo htmlspecialchars($pm['contact_number'] ?: '—'); ?></div>
                        <div style="font-size:0.75rem;color:var(--text-muted);"><?php echo htmlspecialchars($pm['gender'] ?: 'Male'); ?></div>
                    </td>
                    <td>
                        <?php if (!empty($pm['plan_name'])): ?>
                            <span class="badge badge-gold"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($pm['plan_name']); ?> (₱<?php echo number_format($pm['plan_price'], 2); ?>)</span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:0.8rem;">Standard (Default)</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-size:0.85rem;color:var(--text-main);"><?php echo date('M d, Y', strtotime($pm['created_at'])); ?></div>
                        <div style="font-size:0.75rem;color:var(--text-muted);"><?php echo date('h:i A', strtotime($pm['created_at'])); ?></div>
                    </td>
                    <td style="text-align:right;">
                        <div style="display:inline-flex;gap:0.5rem;">
                            <!-- Approve Form -->
                            <form method="POST" onsubmit="return confirm('Approve registration for <?php echo addslashes($pm['full_name']); ?>? This will activate their account and grant gym access.');" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="member_id" value="<?php echo $pm['id']; ?>">
                                <button type="submit" class="btn btn-sm" style="background:#10B981;color:#fff;font-weight:600;border:none;border-radius:8px;padding:6px 14px;cursor:pointer;">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                            </form>


                            <!-- Reject Button trigger modal -->
                            <button type="button" class="btn btn-sm" style="background:#EF4444;color:#fff;font-weight:600;border:none;border-radius:8px;padding:6px 12px;cursor:pointer;" onclick="openRejectModal(<?php echo $pm['id']; ?>, '<?php echo addslashes($pm['full_name']); ?>', '<?php echo addslashes($pm['membership_id']); ?>')">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- APPROVAL / REJECTION HISTORY -->
<div class="card">
    <h3 class="section-title" style="margin-bottom:1.5rem;"><i class="fas fa-history" style="color:var(--accent);"></i> Recent Review Activity</h3>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Member</th>
                    <th>Status</th>
                    <th>Requested Plan</th>
                    <th>Reviewed By</th>
                    <th>Date & Time</th>
                    <th>Notes / Reason</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history_list)): ?>
                <tr>
                    <td colspan="6" style="text-align:center;color:var(--text-muted);padding:2rem;">No review history recorded yet.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($history_list as $h): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;color:var(--text-main);"><?php echo htmlspecialchars($h['full_name']); ?></div>
                        <div style="font-size:0.75rem;color:var(--text-muted);"><?php echo htmlspecialchars($h['membership_id']); ?></div>
                    </td>
                    <td>
                        <?php if ($h['account_status'] === 'Approved'): ?>
                            <span class="badge badge-success"><i class="fas fa-check-circle"></i> Approved</span>
                        <?php else: ?>
                            <span class="badge badge-danger"><i class="fas fa-times-circle"></i> Rejected</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($h['plan_name'] ?: 'Standard'); ?></td>
                    <td><?php echo htmlspecialchars($h['reviewer_name'] ?: 'Staff/Admin'); ?></td>
                    <td><?php echo date('M d, Y h:i A', strtotime($h['approved_at'])); ?></td>
                    <td>
                        <?php if (!empty($h['rejection_reason'])): ?>
                            <span style="color:#EF4444;font-size:0.85rem;"><?php echo htmlspecialchars($h['rejection_reason']); ?></span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:0.85rem;">Account activated</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- REJECTION REASON MODAL -->
<div id="rejectModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;backdrop-filter:blur(4px);">
    <div style="background:var(--secondary-bg,#1A202C);border:1px solid var(--border,#2D3748);border-radius:16px;padding:24px;width:90%;max-width:480px;color:var(--text-main,#fff);box-shadow:0 20px 25px -5px rgba(0,0,0,0.5);">
        <h3 style="margin:0 0 8px 0;color:#EF4444;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-triangle-exclamation"></i> Reject Registration
        </h3>
        <p style="font-size:0.85rem;color:var(--text-muted);margin:0 0 16px 0;">
            Rejecting application for <strong id="rejectMemberName" style="color:var(--text-main);"></strong> (<code id="rejectMemberId"></code>).
        </p>

        <form method="POST" id="rejectForm">
            <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="member_id" id="rejectInputMemberId" value="">


            <div style="margin-bottom:16px;">
                <label style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:6px;">Rejection Reason <span style="color:#EF4444;">*</span></label>
                <textarea name="rejection_reason" id="rejectionReasonText" class="form-control" rows="3" placeholder="e.g. Incomplete information, invalid contact number, or duplicate registration." required style="width:100%;box-sizing:border-box;border-radius:10px;padding:10px;"></textarea>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-muted);border-radius:8px;padding:8px 16px;cursor:pointer;" onclick="closeRejectModal()">
                    Cancel
                </button>
                <button type="submit" class="btn" style="background:#EF4444;color:#fff;border:none;border-radius:8px;padding:8px 18px;font-weight:600;cursor:pointer;">
                    Confirm Rejection
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openRejectModal(memberId, name, code) {
    document.getElementById('rejectInputMemberId').value = memberId;
    document.getElementById('rejectMemberName').innerText = name;
    document.getElementById('rejectMemberId').innerText = code;
    document.getElementById('rejectionReasonText').value = '';
    const modal = document.getElementById('rejectModal');
    modal.style.display = 'flex';
}

function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}
</script>

<?php include 'includes/footer.php'; ?>
