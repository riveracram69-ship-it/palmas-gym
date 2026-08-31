<?php
$page_title = 'Members';
include 'includes/header.php';
include 'includes/sidebar.php';

$members = [];
try {
    if (isset($pdo) && $pdo) {
        $members = $pdo->query(
            "SELECT m.*, 
                    COALESCE(
                        (SELECT p.name 
                         FROM subscriptions s 
                         LEFT JOIN membership_plans p ON p.id = s.plan_id 
                         WHERE s.member_id = m.id AND s.expiry_date >= CURDATE()
                         ORDER BY s.expiry_date DESC, s.id DESC 
                         LIMIT 1), '—'
                    ) AS plan_name,
                    (SELECT s.expiry_date 
                     FROM subscriptions s 
                     WHERE s.member_id = m.id AND s.expiry_date >= CURDATE()
                     ORDER BY s.expiry_date DESC, s.id DESC 
                     LIMIT 1) AS expiry_date
             FROM members m
             ORDER BY m.created_at DESC"
        )->fetchAll();
    }
} catch (Exception $e) {}

$total = count($members);
$active = array_filter($members, fn($m) => $m['status'] === 'Active');
?>

<div class="topbar">
    <div class="page-title">
        <h1>Member Directory</h1>
        <p><?php echo $total; ?> total members registered in the system.</p>
    </div>
    <a href="add-member.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Member</a>
</div>

<div class="card">
    <div class="toolbar" style="margin-bottom:2rem;">
        <div class="search-bar">
            <i class="fas fa-search"></i>
            <input type="text" class="form-control" id="member-search" placeholder="Search name, email, or ID…">
        </div>
        <div style="display:flex;gap:0.75rem;align-items:center;">
            <select class="form-control" id="status-filter" style="width:auto;min-width:160px;">
                <option value="">All Accounts</option>
                <option value="Approved">Approved</option>
                <option value="Pending">Pending Review</option>
                <option value="Rejected">Rejected</option>
                <option value="Suspended">Suspended</option>
            </select>
        </div>
    </div>

    <div class="table-container">
        <table id="members-table">
            <thead>
                <tr>
                    <th>Member</th>
                    <th>Membership ID</th>
                    <th>Plan & Expiry</th>
                    <th>Account Status</th>
                    <th>Membership</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($members)): ?>
                <?php render_empty_state('fas fa-user-group', 'No members found', '<a href="add-member.php" class="btn btn-primary btn-sm" style="margin-top:1rem;">Add First Member</a>', true); ?>
                <?php else: ?>
                <?php foreach ($members as $m): ?>
                <tr class="member-row"
                    data-name="<?php echo strtolower($m['full_name']); ?>"
                    data-email="<?php echo strtolower($m['email']); ?>"
                    data-id="<?php echo strtolower($m['membership_id']); ?>"
                    data-status="<?php echo htmlspecialchars($m['account_status'] ?? 'Approved'); ?>">

                    <td>
                        <div class="member-cell">
                            <div class="member-avatar"><?php echo strtoupper(substr($m['full_name'], 0, 1)); ?></div>
                            <div>
                                <div style="font-weight:600;color:var(--text-main);"><?php echo htmlspecialchars($m['full_name']); ?></div>
                                <div style="font-size:0.75rem;color:var(--text-muted);"><?php echo htmlspecialchars($m['email']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td><code style="font-size:0.85rem;color:var(--accent);font-weight:700;"><?php echo htmlspecialchars($m['membership_id']); ?></code></td>
                    <td>
                        <?php if ($m['plan_name'] !== '—'): ?>
                        <div style="font-weight:600;font-size:0.85rem;color:var(--text-main);"><i class="fas fa-tag" style="color:var(--accent);font-size:0.75rem;"></i> <?php echo htmlspecialchars($m['plan_name']); ?></div>
                        <div style="font-size:0.75rem;color:var(--text-muted);"><?php echo $m['expiry_date'] ? 'Exp: ' . date('M d, Y', strtotime($m['expiry_date'])) : '—'; ?></div>
                        <?php else: ?>
                        <span style="color:var(--text-muted);font-size:0.8rem;">No active plan</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php 
                        $acc = $m['account_status'] ?? 'Approved';
                        if ($acc === 'Approved'): ?>
                            <span class="badge badge-success"><i class="fas fa-check-circle"></i> Approved</span>
                        <?php elseif ($acc === 'Pending'): ?>
                            <a href="pending-registrations.php" style="text-decoration:none;"><span class="badge" style="background:#FEF3C7;color:#D97706;border:1px solid #FDE68A;"><i class="fas fa-clock"></i> Pending Review</span></a>
                        <?php elseif ($acc === 'Rejected'): ?>
                            <span class="badge badge-danger"><i class="fas fa-times-circle"></i> Rejected</span>
                        <?php else: ?>
                            <span class="badge badge-gray"><?php echo htmlspecialchars($acc); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (($m['account_status'] ?? 'Approved') === 'Approved'): ?>
                            <span class="badge <?php echo $m['status'] === 'Active' ? 'badge-success' : 'badge-danger'; ?>">
                                <i class="fas fa-circle" style="font-size:0.35rem;margin-right:4px;"></i>
                                <?php echo htmlspecialchars($m['status']); ?>
                            </span>
                        <?php else: ?>
                            <span class="badge badge-gray">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:0.5rem;">
                            <a href="view-member.php?id=<?php echo $m['id']; ?>" class="btn btn-outline btn-icon" title="View Profile" aria-label="View Profile">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="edit-member.php?id=<?php echo $m['id']; ?>" class="btn btn-outline btn-icon" title="Edit" aria-label="Edit Member">
                                <i class="fas fa-pen-to-square"></i>
                            </a>
                            <a href="renew-member.php?id=<?php echo $m['id']; ?>" class="btn btn-outline btn-icon" title="Renew Subscription" style="color:var(--accent);border-color:rgba(45,106,79,0.2);" aria-label="Renew Subscription">
                                <i class="fas fa-rotate-right"></i>
                            </a>
                            <?php if (is_admin()): ?>
                                <?php if ($m['status'] === 'Inactive'): ?>
                                <button class="btn btn-outline btn-icon status-toggle-btn" 
                                        style="color:var(--accent);border-color:rgba(45,106,79,0.2);"
                                        data-id="<?php echo $m['id']; ?>" 
                                        data-action="reactivate"
                                        data-name="<?php echo htmlspecialchars($m['full_name']); ?>" 
                                        title="Reactivate Member"
                                        aria-label="Reactivate Member">
                                    <i class="fas fa-user-check"></i>
                                </button>
                                <?php else: ?>
                                <button class="btn btn-outline btn-icon status-toggle-btn" 
                                        style="color:var(--danger);border-color:#fce8e6;"
                                        data-id="<?php echo $m['id']; ?>" 
                                        data-action="deactivate"
                                        data-name="<?php echo htmlspecialchars($m['full_name']); ?>" 
                                        title="Deactivate Member"
                                        aria-label="Deactivate Member">
                                    <i class="fas fa-user-slash"></i>
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div id="no-results" style="display:none;text-align:center;padding:4rem;color:var(--text-muted);">
        <i class="fas fa-magnifying-glass" style="font-size:2rem;margin-bottom:1rem;opacity:0.2;display:block;"></i>
        No members match your search.
    </div>
</div>

<div id="toast" style="position:fixed;bottom:2rem;right:2rem;z-index:9999;display:none;"></div>

<script>
// Search & filter
const searchInput = document.getElementById('member-search');
const statusFilter = document.getElementById('status-filter');
const rows = document.querySelectorAll('.member-row');
const noResults = document.getElementById('no-results');

function filterTable() {
    const q = searchInput.value.toLowerCase();
    const s = statusFilter.value;
    let visible = 0;
    rows.forEach(row => {
        const nameMatch   = row.dataset.name.includes(q);
        const emailMatch  = row.dataset.email.includes(q);
        const idMatch     = row.dataset.id.includes(q);
        const statusMatch = !s || row.dataset.status === s;
        const show = (nameMatch || emailMatch || idMatch) && statusMatch;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    noResults.style.display = visible === 0 && rows.length > 0 ? 'block' : 'none';
}

searchInput.addEventListener('input', filterTable);
statusFilter.addEventListener('change', filterTable);

// Admin Status Toggle logic (Deactivate / Reactivate)
function handleStatusToggle(btn) {
    const id = btn.dataset.id;
    const name = btn.dataset.name;
    const action = btn.dataset.action;
    const isReactivate = (action === 'reactivate');

    const title = isReactivate ? 'Confirm Reactivation' : 'Confirm Deactivation';
    const msg = isReactivate 
        ? `Are you sure you want to reactivate ${name}? Their status will be set to Active.`
        : `Are you sure you want to deactivate ${name}? Their status will be set to Inactive and active subscriptions expired.`;

    const doRequest = function() {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        btn.disabled = true;
        btn.style.opacity = '0.5';

        fetch('modules/members/delete_member.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrf
            },
            body: `id=${encodeURIComponent(id)}&action=${encodeURIComponent(action)}&csrf_token=${encodeURIComponent(csrf)}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Error updating member status.');
                btn.disabled = false;
                btn.style.opacity = '1';
            }
        })
        .catch(err => {
            console.error('Status toggle error:', err);
            alert('A network error occurred while updating member status.');
            btn.disabled = false;
            btn.style.opacity = '1';
        });
    };

    if (typeof palmasConfirm === 'function') {
        palmasConfirm(
            title,
            isReactivate 
                ? `Are you sure you want to reactivate <strong style="color:var(--text-main);">${name}</strong>? Their status will be set to Active.`
                : `Are you sure you want to deactivate <strong style="color:var(--text-main);">${name}</strong>? Their status will be set to Inactive and active subscriptions expired.`,
            isReactivate ? 'Reactivate Member' : 'Deactivate Member',
            isReactivate ? 'var(--accent)' : 'var(--danger)',
            doRequest
        );
    } else if (confirm(msg)) {
        doRequest();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.status-toggle-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            handleStatusToggle(this);
        });
    });
});

// Also bind directly in case DOMContentLoaded already fired
document.querySelectorAll('.status-toggle-btn').forEach(btn => {
    btn.onclick = function(e) {
        e.preventDefault();
        handleStatusToggle(this);
    };
});
</script>

<?php include 'includes/footer.php'; ?>
