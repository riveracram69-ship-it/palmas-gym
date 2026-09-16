<?php
$page_title = 'Members';
include 'includes/header.php';
include 'includes/sidebar.php';

$members = [];
try {
    if (isset($pdo) && $pdo) {
        $members = $pdo->query(
            "SELECT m.id, m.membership_id, m.full_name, m.email, m.contact_number, m.status, m.account_status, m.created_at, 
                    COALESCE(sub.plan_name, '—') AS plan_name,
                    sub.expiry_date
             FROM members m
             LEFT JOIN (
                 SELECT s.member_id, p.name AS plan_name, s.expiry_date
                 FROM subscriptions s
                 JOIN (
                     SELECT member_id, MAX(id) AS latest_sub_id
                     FROM subscriptions
                     GROUP BY member_id
                 ) latest ON s.id = latest.latest_sub_id
                 LEFT JOIN membership_plans p ON p.id = s.plan_id
             ) sub ON sub.member_id = m.id
             ORDER BY m.created_at DESC"
        )->fetchAll();
    }
} catch (Exception $e) {
    error_log("Members query error: " . $e->getMessage());
}

$total = count($members);
$active = array_filter($members, function($m) {
    $is_expired = empty($m['expiry_date']) || strtotime($m['expiry_date']) < time();
    return ($m['status'] === 'Active' && !$is_expired);
});
?>

<div class="topbar">
    <div class="page-title">
        <h1>Member Directory</h1>
        <p><?php echo $total; ?> total members registered in the system.</p>
    </div>
    <div style="display:flex; gap:0.6rem; align-items:center; flex-wrap:wrap;">
        <a href="add-member.php" class="btn btn-primary">
            <i class="fas fa-user-plus"></i> Add Member
        </a>
    </div>
</div>

<div class="card">
    <div class="toolbar" style="margin-bottom:2rem;">
        <div class="search-bar">
            <i class="fas fa-search"></i>
            <input type="text" class="form-control" id="member-search" placeholder="Search name, email, or ID…">
        </div>
        <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
            <select class="form-control" id="status-filter" style="width:auto;min-width:130px;">
                <option value="">All Members</option>
                <option value="Active">Active</option>
                <option value="Expired">Expired</option>
            </select>
            <select class="form-control" id="per-page-select" style="width:auto;min-width:130px;">
                <option value="10" selected>10 per page</option>
                <option value="25">25 per page</option>
                <option value="50">50 per page</option>
                <option value="all">Show All</option>
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
                <?php foreach ($members as $m): 
                    $has_plan = ($m['plan_name'] !== '—' && !empty($m['plan_name']));
                    $is_sub_expired = empty($m['expiry_date']) || (strtotime($m['expiry_date']) < time());
                    $effective_status = ($m['status'] === 'Inactive' || ($m['account_status'] ?? '') === 'Suspended') 
                        ? 'Inactive' 
                        : ($is_sub_expired ? 'Expired' : 'Active');
                    $badge_class = ($effective_status === 'Active') ? 'badge-success' : 'badge-danger';
                ?>
                <tr class="member-row"
                    data-name="<?php echo htmlspecialchars(strtolower($m['full_name']), ENT_QUOTES, 'UTF-8'); ?>"
                    data-email="<?php echo htmlspecialchars(strtolower($m['email']), ENT_QUOTES, 'UTF-8'); ?>"
                    data-id="<?php echo htmlspecialchars(strtolower($m['membership_id']), ENT_QUOTES, 'UTF-8'); ?>"
                    data-status="<?php echo htmlspecialchars($m['account_status'] ?? 'Approved', ENT_QUOTES, 'UTF-8'); ?>"
                    data-membership-status="<?php echo htmlspecialchars($effective_status, ENT_QUOTES, 'UTF-8'); ?>">

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
                        <?php if ($has_plan): ?>
                        <div style="font-weight:600;font-size:0.85rem;color:var(--text-main);"><i class="fas fa-tag" style="color:<?php echo $is_sub_expired ? 'var(--text-muted)' : 'var(--accent)'; ?>;font-size:0.75rem;"></i> <?php echo htmlspecialchars($m['plan_name']); ?></div>
                        <div style="font-size:0.75rem;color:<?php echo $is_sub_expired ? 'var(--danger)' : 'var(--text-muted)'; ?>;font-weight:<?php echo $is_sub_expired ? '600' : 'normal'; ?>;">
                            <?php echo $m['expiry_date'] ? ($is_sub_expired ? 'Expired: ' : 'Exp: ') . date('M d, Y', strtotime($m['expiry_date'])) : '—'; ?>
                        </div>
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
                            <span class="badge <?php echo $badge_class; ?>">
                                <i class="fas fa-circle" style="font-size:0.35rem;margin-right:4px;"></i>
                                <?php echo htmlspecialchars($effective_status); ?>
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

    <!-- Table Pagination Footer -->
    <div class="table-pagination" id="table-pagination" style="display:none;">
        <div class="pagination-info" id="pagination-info">
            Showing <strong>1</strong> to <strong>10</strong> of <strong><?php echo $total; ?></strong> members
        </div>
        <div class="pagination-controls" id="pagination-controls">
            <!-- Dynamically injected pagination buttons -->
        </div>
    </div>

    <div id="no-results" style="display:none;text-align:center;padding:4rem;color:var(--text-muted);">
        <i class="fas fa-magnifying-glass" style="font-size:2rem;margin-bottom:1rem;opacity:0.2;display:block;"></i>
        No members match your search.
    </div>
</div>

<div id="toast" style="position:fixed;bottom:2rem;right:2rem;z-index:9999;display:none;"></div>

<script>
// Search, Filter & Pagination State
const searchInput = document.getElementById('member-search');
const statusFilter = document.getElementById('status-filter');
const perPageSelect = document.getElementById('per-page-select');
const paginationBox = document.getElementById('table-pagination');
const paginationInfo = document.getElementById('pagination-info');
const paginationControls = document.getElementById('pagination-controls');
const rows = Array.from(document.querySelectorAll('.member-row'));
const noResults = document.getElementById('no-results');

let currentPage = 1;
let pageSize = 10;

function renderPagination(matchingRows) {
    const total = matchingRows.length;
    if (total === 0) {
        paginationBox.style.display = 'none';
        return;
    }

    const effectivePageSize = (pageSize === 'all') ? total : parseInt(pageSize, 10);
    const totalPages = Math.ceil(total / effectivePageSize);

    if (currentPage > totalPages) currentPage = totalPages || 1;
    if (currentPage < 1) currentPage = 1;

    const startIndex = (currentPage - 1) * effectivePageSize;
    const endIndex = Math.min(startIndex + effectivePageSize, total);

    matchingRows.forEach((row, idx) => {
        if (idx >= startIndex && idx < endIndex) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });

    paginationInfo.innerHTML = `Showing <strong>${total > 0 ? startIndex + 1 : 0}</strong> to <strong>${endIndex}</strong> of <strong>${total}</strong> members`;

    if (totalPages <= 1) {
        paginationBox.style.display = total > 10 ? 'flex' : (pageSize === 'all' ? 'flex' : 'none');
        paginationControls.innerHTML = '';
        return;
    }

    paginationBox.style.display = 'flex';
    let buttonsHtml = '';

    buttonsHtml += `<button type="button" class="pagination-btn" onclick="goToPage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} aria-label="Previous Page"><i class="fas fa-chevron-left" style="font-size:0.75rem;"></i> Prev</button>`;

    for (let p = 1; p <= totalPages; p++) {
        if (p === 1 || p === totalPages || (p >= currentPage - 1 && p <= currentPage + 1)) {
            buttonsHtml += `<button type="button" class="pagination-btn ${p === currentPage ? 'active' : ''}" onclick="goToPage(${p})">${p}</button>`;
        } else if (p === currentPage - 2 || p === currentPage + 2) {
            buttonsHtml += `<span style="padding:0 4px; color:var(--text-muted); font-size:0.8rem;">…</span>`;
        }
    }

    buttonsHtml += `<button type="button" class="pagination-btn" onclick="goToPage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} aria-label="Next Page">Next <i class="fas fa-chevron-right" style="font-size:0.75rem;"></i></button>`;

    paginationControls.innerHTML = buttonsHtml;
}

function filterTable() {
    const q = searchInput.value.toLowerCase().trim();
    const s = statusFilter.value;

    const matchingRows = [];
    rows.forEach(row => {
        const nameMatch   = row.dataset.name.includes(q);
        const emailMatch  = row.dataset.email.includes(q);
        const idMatch     = row.dataset.id.includes(q);
        const statusMatch = !s || row.dataset.membershipStatus === s;
        const match = (nameMatch || emailMatch || idMatch) && statusMatch;

        if (match) {
            matchingRows.push(row);
        } else {
            row.style.display = 'none';
        }
    });

    noResults.style.display = (matchingRows.length === 0 && rows.length > 0) ? 'block' : 'none';
    renderPagination(matchingRows);
}

function goToPage(page) {
    currentPage = page;
    filterTable();
    const cardEl = document.querySelector('.card');
    if (cardEl) {
        cardEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

perPageSelect.addEventListener('change', function() {
    pageSize = this.value;
    currentPage = 1;
    filterTable();
});

searchInput.addEventListener('input', () => { currentPage = 1; filterTable(); });
statusFilter.addEventListener('change', () => { currentPage = 1; filterTable(); });

// Run initial filter on page load
filterTable();

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

<?php 
require_once __DIR__ . '/includes/ui_components.php';
render_create_account_modal();
include 'includes/footer.php'; 
?>
