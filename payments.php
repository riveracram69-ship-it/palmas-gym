<?php
$page_title = 'Payments & Revenue';
include 'includes/header.php';
include 'includes/sidebar.php';
require_admin();

$payments = [];
$members = [];
try {
    if (isset($pdo) && $pdo) {
        $payments = $pdo->query(
            "SELECT p.*, m.full_name, m.membership_id, plan.name as plan_name, u.name as verified_by_name
             FROM payments p 
             JOIN members m ON p.member_id = m.id 
             LEFT JOIN subscriptions s ON p.subscription_id = s.id
             LEFT JOIN membership_plans plan ON s.plan_id = plan.id
             LEFT JOIN users u ON p.verified_by = u.id
             ORDER BY p.payment_date DESC, p.created_at DESC"
        )->fetchAll();

        $members = $pdo->query(
            "SELECT id, full_name, membership_id 
             FROM members 
             ORDER BY full_name ASC"
        )->fetchAll();
    }
} catch (Exception $e) {}
?>

<div class="topbar">
    <div class="page-title">
        <h1>Payments & Revenue</h1>
        <p>Monitor all financial transactions and gym earnings.</p>
    </div>
    <div style="display:flex; gap:1rem;">
        <a href="reports.php" class="btn btn-outline"><i class="fas fa-file-invoice-dollar"></i> Revenue Report</a>
        <button onclick="openPaymentModal()" class="btn btn-primary"><i class="fas fa-plus"></i> New Payment</button>
    </div>
</div>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem; flex-wrap:wrap; gap:1rem;">
        <h3 class="section-title" style="margin:0;"><i class="fas fa-list-ul" style="color:var(--accent);"></i> Transaction History</h3>
        <div style="display:flex; align-items:center; gap:1rem;">
            <input type="text" id="txn-search-input" placeholder="Search transactions..." class="form-control search-bar" style="max-width:250px; padding:0.4rem 0.8rem; font-size:0.85rem; margin:0;">
            <div style="font-size:0.85rem; color:var(--text-muted); white-space:nowrap;">
                Showing <span id="txn-count"><?php echo count($payments); ?></span> records
            </div>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Member</th>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Method</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody id="txn-table-body">
                <?php if (empty($payments)): ?>
                <?php render_empty_state('fas fa-money-bill-transfer', 'No payment records found.', '', true); ?>
                <?php else: ?>
                <?php foreach ($payments as $p): ?>
                <tr class="txn-row" data-name="<?php echo strtolower(htmlspecialchars($p['full_name'])); ?>" data-id="<?php echo strtolower(htmlspecialchars($p['membership_id'])); ?>">
                    <td><?php echo date('M d, Y', strtotime($p['payment_date'])); ?></td>
                    <td>
                        <div class="member-cell">
                            <div class="member-avatar"><?php echo strtoupper(substr($p['full_name'], 0, 1)); ?></div>
                            <span class="cell-primary"><?php echo htmlspecialchars($p['full_name']); ?></span>
                        </div>
                    </td>
                    <td><code class="cell-secondary"><?php echo htmlspecialchars($p['membership_id']); ?></code></td>
                    <td>
                        <span class="badge badge-success" style="background:var(--accent-dim); color:var(--accent);">
                            <?php echo htmlspecialchars($p['plan_name'] ?? 'Subscription'); ?>
                        </span>
                    </td>
                    <td>
                        <span class="cell-primary">
                            <i class="fas fa-credit-card" style="margin-right:6px; font-size:0.8rem; opacity:0.5;"></i>
                            <?php echo htmlspecialchars($p['payment_method']); ?>
                        </span>
                        <?php if (!empty($p['reference_number'])): ?>
                            <div class="cell-secondary" style="margin-top:2px;" title="Reference Number">Ref: <span style="font-family:monospace;"><?php echo htmlspecialchars($p['reference_number']); ?></span></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="font-weight:700; color:var(--success);">₱<?php echo number_format($p['amount'], 2); ?></span>
                        <?php if (!empty($p['verified_by_name'])): ?>
                            <div class="cell-secondary" style="margin-top:2px;" title="Verified by"><i class="fas fa-user-check"></i> <?php echo htmlspecialchars($p['verified_by_name']); ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="no-txn-results" style="display:none;">
                    <td colspan="6">
                        <div class="empty-state" style="padding:2rem;">
                            <i class="fas fa-magnifying-glass" style="font-size:2rem; opacity:0.1; margin-bottom:0.5rem;"></i>
                            <p>No transactions match your search.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Select Member Modal -->
<div class="modal-overlay" id="payment-member-modal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h3><i class="fas fa-money-bill-wave" style="color:var(--success); margin-right:8px;"></i> Record New Payment</h3>
            <button class="modal-close" onclick="closePaymentModal()" aria-label="Close Modal"><i class="fas fa-xmark"></i></button>
        </div>
        <p style="color:var(--text-muted); font-size:0.875rem; margin-bottom:1.5rem;">Select an existing member to record a subscription renewal or plan payment.</p>
        
        <div class="search-bar" style="max-width:100%; margin-bottom:1.25rem;">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" id="member-search-input" placeholder="Type member name or ID..." class="form-control">
        </div>
        
        <div id="modal-members-list" style="max-height:280px; overflow-y:auto; display:flex; flex-direction:column; gap:0.5rem; padding-right:5px; margin-bottom:1.5rem;">
            <?php if (empty($members)): ?>
            <div style="text-align:center; padding:2rem; color:var(--text-muted);">No registered members found.</div>
            <?php else: ?>
            <?php foreach ($members as $m): ?>
            <div class="modal-member-item" 
                 data-name="<?php echo strtolower(htmlspecialchars($m['full_name'])); ?>" 
                 data-id="<?php echo strtolower(htmlspecialchars($m['membership_id'])); ?>"
                 style="display:flex; align-items:center; justify-content:space-between; padding:0.75rem 1rem; border:1px solid var(--border); border-radius:10px; transition:all 0.2s;"
                 onmouseover="this.style.borderColor='var(--accent-border)'; this.style.background='var(--accent-dim)';"
                 onmouseout="this.style.borderColor='var(--border)'; this.style.background='transparent';">
                <div class="member-cell">
                    <div class="member-avatar"><?php echo strtoupper(substr($m['full_name'], 0, 1)); ?></div>
                    <div>
                        <div class="cell-primary"><?php echo htmlspecialchars($m['full_name']); ?></div>
                        <code class="cell-secondary"><?php echo htmlspecialchars($m['membership_id']); ?></code>
                    </div>
                </div>
                <a href="renew-member.php?id=<?php echo $m['id']; ?>" class="btn btn-primary btn-sm" style="text-decoration:none;">Select</a>
            </div>
            <?php endforeach; ?>
            <div id="no-modal-members-results" style="display:none; text-align:center; padding:2rem; color:var(--text-muted);">No matching members found.</div>
            <?php endif; ?>
        </div>
        
        <div style="padding-top:1.25rem; border-top:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
            <span class="cell-secondary">New member?</span>
            <a href="add-member.php" class="btn btn-outline btn-sm" style="text-decoration:none;"><i class="fas fa-user-plus"></i> Register Member</a>
        </div>
    </div>
</div>

<script>
function openPaymentModal() {
    document.getElementById('payment-member-modal').classList.add('active');
    document.getElementById('member-search-input').value = '';
    
    // reset list display
    const items = document.querySelectorAll('.modal-member-item');
    items.forEach(item => item.style.setProperty('display', 'flex', 'important'));
    document.getElementById('no-modal-members-results').style.display = 'none';
    
    setTimeout(() => {
        document.getElementById('member-search-input').focus();
    }, 100);
}

function closePaymentModal() {
    document.getElementById('payment-member-modal').classList.remove('active');
}

// Modal Member Search Filter
document.getElementById('member-search-input').addEventListener('input', function(e) {
    const q = e.target.value.toLowerCase().trim();
    const items = document.querySelectorAll('.modal-member-item');
    let visibleCount = 0;
    
    items.forEach(item => {
        const name = item.dataset.name;
        const id = item.dataset.id;
        if (name.includes(q) || id.includes(q)) {
            item.style.setProperty('display', 'flex', 'important');
            visibleCount++;
        } else {
            item.style.setProperty('display', 'none', 'important');
        }
    });
    
    const noResults = document.getElementById('no-modal-members-results');
    if (noResults) {
        noResults.style.display = visibleCount === 0 ? 'block' : 'none';
    }
});

// Real-time Transaction List Filter
const txnSearch = document.getElementById('txn-search-input');
if (txnSearch) {
    txnSearch.addEventListener('input', function(e) {
        const q = e.target.value.toLowerCase().trim();
        const rows = document.querySelectorAll('.txn-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            const name = row.dataset.name || '';
            const id = row.dataset.id || '';
            if (name.includes(q) || id.includes(q)) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Update count indicator
        const countSpan = document.getElementById('txn-count');
        if (countSpan) countSpan.textContent = visibleCount;
        
        // Show/hide no results row
        const noResultsRow = document.getElementById('no-txn-results');
        if (noResultsRow) {
            noResultsRow.style.display = visibleCount === 0 ? '' : 'none';
        }
    });
}
</script>

<?php include 'includes/footer.php'; ?>
