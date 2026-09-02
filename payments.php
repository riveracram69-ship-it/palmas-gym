<?php
$page_title = 'Payment & Transaction History';
include 'includes/header.php';
include 'includes/sidebar.php';
require_admin();

$payments = [];
$members = [];
$total_revenue = 0;
$gcash_total = 0;
$maya_total = 0;
$cash_total = 0;

// Filters
$method_filter = $_GET['method'] ?? 'all';
$date_start    = $_GET['start_date'] ?? '';
$date_end      = $_GET['end_date'] ?? '';

try {
    if (isset($pdo) && $pdo) {
        $sql = "
            SELECT p.*, m.full_name, m.membership_id, plan.name as plan_name, u.name as verified_by_name
            FROM payments p 
            JOIN members m ON p.member_id = m.id 
            LEFT JOIN subscriptions s ON p.subscription_id = s.id
            LEFT JOIN membership_plans plan ON s.plan_id = plan.id
            LEFT JOIN users u ON p.verified_by = u.id
            WHERE 1=1
        ";
        $params = [];

        if ($method_filter !== 'all' && !empty($method_filter)) {
            $sql .= " AND p.payment_method = :method";
            $params['method'] = $method_filter;
        }

        if (!empty($date_start)) {
            $sql .= " AND p.payment_date >= :start_date";
            $params['start_date'] = $date_start;
        }

        if (!empty($date_end)) {
            $sql .= " AND p.payment_date <= :end_date";
            $params['end_date'] = $date_end;
        }

        $sql .= " ORDER BY p.payment_date DESC, p.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch KPI stats
        $stats_stmt = $pdo->query("
            SELECT 
                SUM(amount) as total,
                SUM(CASE WHEN payment_method = 'GCash' THEN amount ELSE 0 END) as gcash,
                SUM(CASE WHEN payment_method = 'Maya' THEN amount ELSE 0 END) as maya,
                SUM(CASE WHEN payment_method = 'Cash' THEN amount ELSE 0 END) as cash
            FROM payments
        ")->fetch(PDO::FETCH_ASSOC);

        $total_revenue = floatval($stats_stmt['total'] ?? 0);
        $gcash_total   = floatval($stats_stmt['gcash'] ?? 0);
        $maya_total    = floatval($stats_stmt['maya'] ?? 0);
        $cash_total    = floatval($stats_stmt['cash'] ?? 0);

        $members = $pdo->query("SELECT id, full_name, membership_id FROM members ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log('Error in payments.php: ' . $e->getMessage());
}
?>

<div class="topbar">
    <div class="page-title">
        <h1>Payment & Transaction History</h1>
        <p>Complete official ledger and audit trail of all approved gym revenue & subscriptions.</p>
    </div>
    <div style="display:flex; gap:0.75rem;">
        <a href="renewal-requests.php" class="btn btn-outline"><i class="fas fa-clock"></i> Pending Requests</a>
        <button onclick="openPaymentModal()" class="btn btn-primary"><i class="fas fa-plus"></i> Record Cash Payment</button>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="stats-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
    <div class="card" style="padding:1.25rem;">
        <div style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">All-Time Revenue</div>
        <div style="font-family:'Outfit',sans-serif; font-size:1.6rem; font-weight:800; color:var(--success); margin-top:4px;">₱<?php echo number_format($total_revenue, 2); ?></div>
        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Across all payment channels</div>
    </div>
    <div class="card" style="padding:1.25rem;">
        <div style="font-size:0.75rem; font-weight:700; color:#2563eb; text-transform:uppercase; letter-spacing:0.5px;"><i class="fas fa-mobile-screen"></i> GCash Collections</div>
        <div style="font-family:'Outfit',sans-serif; font-size:1.6rem; font-weight:800; color:#2563eb; margin-top:4px;">₱<?php echo number_format($gcash_total, 2); ?></div>
        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Online QR / Direct E-Wallet</div>
    </div>
    <div class="card" style="padding:1.25rem;">
        <div style="font-size:0.75rem; font-weight:700; color:#059669; text-transform:uppercase; letter-spacing:0.5px;"><i class="fas fa-wallet"></i> Maya Collections</div>
        <div style="font-family:'Outfit',sans-serif; font-size:1.6rem; font-weight:800; color:#059669; margin-top:4px;">₱<?php echo number_format($maya_total, 2); ?></div>
        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Online QR / Direct E-Wallet</div>
    </div>
    <div class="card" style="padding:1.25rem;">
        <div style="font-size:0.75rem; font-weight:700; color:var(--brand-primary); text-transform:uppercase; letter-spacing:0.5px;"><i class="fas fa-hand-holding-dollar"></i> Front-Desk Cash</div>
        <div style="font-family:'Outfit',sans-serif; font-size:1.6rem; font-weight:800; color:var(--brand-primary); margin-top:4px;">₱<?php echo number_format($cash_total, 2); ?></div>
        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Over-the-counter payments</div>
    </div>
</div>

<!-- Filter & Search Toolbar -->
<div class="card" style="margin-bottom:1.5rem; padding:1.25rem;">
    <form method="GET" action="" style="display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-end;">
        <div style="flex:1; min-width:200px;">
            <label style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Search Member / Ref</label>
            <input type="text" id="txn-search-input" placeholder="Type member name, ID, or Ref No..." class="form-control" style="margin-top:4px;">
        </div>

        <div style="width:160px;">
            <label style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Method</label>
            <select name="method" class="form-control" style="margin-top:4px;" onchange="this.form.submit()">
                <option value="all" <?php echo $method_filter === 'all' ? 'selected' : ''; ?>>All Methods</option>
                <option value="GCash" <?php echo $method_filter === 'GCash' ? 'selected' : ''; ?>>GCash</option>
                <option value="Maya" <?php echo $method_filter === 'Maya' ? 'selected' : ''; ?>>Maya</option>
                <option value="Cash" <?php echo $method_filter === 'Cash' ? 'selected' : ''; ?>>Cash</option>
            </select>
        </div>

        <div style="width:140px;">
            <label style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Start Date</label>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($date_start); ?>" class="form-control" style="margin-top:4px;">
        </div>

        <div style="width:140px;">
            <label style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">End Date</label>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($date_end); ?>" class="form-control" style="margin-top:4px;">
        </div>

        <div>
            <button type="submit" class="btn btn-primary" style="height:42px;"><i class="fas fa-filter"></i> Filter</button>
            <?php if ($method_filter !== 'all' || !empty($date_start) || !empty($date_end)): ?>
                <a href="payments.php" class="btn btn-outline" style="height:42px;"><i class="fas fa-rotate-left"></i> Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Transaction Ledger Table -->
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem;">
        <h3 class="section-title" style="margin:0;"><i class="fas fa-receipt" style="color:var(--accent);"></i> Official Transaction Ledger</h3>
        <div style="font-size:0.85rem; color:var(--text-muted);">
            Showing <strong id="txn-count"><?php echo count($payments); ?></strong> verified transactions
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Transaction ID</th>
                    <th>Date Paid</th>
                    <th>Member Details</th>
                    <th>Plan / Service</th>
                    <th>Method</th>
                    <th>Amount Paid</th>
                    <th>Reference No.</th>
                    <th>Status</th>
                    <th>Verified By</th>
                </tr>
            </thead>
            <tbody id="txn-table-body">
                <?php if (empty($payments)): ?>
                    <?php render_empty_state('fas fa-money-bill-transfer', 'No payment records found.', '', true); ?>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                    <?php $txn_code = 'TXN-' . str_pad($p['id'], 5, '0', STR_PAD_LEFT); ?>
                    <tr class="txn-row" 
                        data-name="<?php echo strtolower(htmlspecialchars($p['full_name'])); ?>" 
                        data-id="<?php echo strtolower(htmlspecialchars($p['membership_id'])); ?>"
                        data-ref="<?php echo strtolower(htmlspecialchars($p['reference_number'] ?? '')); ?>"
                        data-txn="<?php echo strtolower($txn_code); ?>">
                        
                        <!-- Transaction ID -->
                        <td>
                            <code style="background:var(--primary-bg); border:1px solid var(--border); padding:3px 8px; border-radius:6px; font-weight:700; color:var(--text-main); font-size:0.8rem;">
                                <?php echo $txn_code; ?>
                            </code>
                        </td>

                        <!-- Date Paid -->
                        <td class="cell-secondary" style="white-space:nowrap;">
                            <div style="font-weight:600; color:var(--text-main);"><?php echo date('M d, Y', strtotime($p['payment_date'])); ?></div>
                            <div style="font-size:0.72rem; color:var(--text-muted);"><i class="far fa-clock"></i> <?php echo date('h:i A', strtotime($p['created_at'])); ?></div>
                        </td>

                        <!-- Member Name & ID -->
                        <td>
                            <div class="member-cell">
                                <div class="member-avatar"><?php echo strtoupper(substr($p['full_name'], 0, 1)); ?></div>
                                <div>
                                    <div class="cell-primary" style="font-weight:600;"><?php echo htmlspecialchars($p['full_name']); ?></div>
                                    <code class="cell-secondary" style="font-size:0.72rem;"><?php echo htmlspecialchars($p['membership_id']); ?></code>
                                </div>
                            </div>
                        </td>

                        <!-- Plan / Service -->
                        <td>
                            <span class="cell-primary" style="font-weight:600;">
                                <?php echo htmlspecialchars($p['plan_name'] ?? 'Membership Renewal'); ?>
                            </span>
                        </td>

                        <!-- Payment Method -->
                        <td>
                            <?php if ($p['payment_method'] === 'GCash'): ?>
                                <span class="badge" style="background:rgba(59,130,246,0.12); color:#2563eb; border:1px solid rgba(59,130,246,0.25);">
                                    <i class="fas fa-mobile-screen"></i> GCash
                                </span>
                            <?php elseif ($p['payment_method'] === 'Maya'): ?>
                                <span class="badge" style="background:rgba(16,185,129,0.12); color:#059669; border:1px solid rgba(16,185,129,0.25);">
                                    <i class="fas fa-wallet"></i> Maya
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background:rgba(82,183,136,0.12); color:var(--brand-primary); border:1px solid rgba(82,183,136,0.25);">
                                    <i class="fas fa-hand-holding-dollar"></i> Cash
                                </span>
                            <?php endif; ?>
                        </td>

                        <!-- Amount -->
                        <td>
                            <span style="font-weight:800; color:var(--success); font-size:0.95rem;">₱<?php echo number_format($p['amount'], 2); ?></span>
                        </td>

                        <!-- Reference No. -->
                        <td>
                            <?php if (!empty($p['reference_number'])): ?>
                                <code style="background:var(--primary-bg); border:1px solid var(--border); padding:2px 6px; border-radius:4px; font-weight:700; color:var(--text-main); font-size:0.78rem;">
                                    <?php echo htmlspecialchars($p['reference_number']); ?>
                                </code>
                            <?php else: ?>
                                <span class="cell-secondary" style="font-size:0.75rem;">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Status -->
                        <td>
                            <span class="badge badge-success"><i class="fas fa-circle-check"></i> Paid</span>
                        </td>

                        <!-- Verified By -->
                        <td>
                            <div class="cell-secondary" style="font-size:0.8rem;">
                                <i class="fas fa-user-check" style="color:var(--accent-light);"></i> <?php echo htmlspecialchars($p['verified_by_name'] ?: 'Admin'); ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr id="no-txn-results" style="display:none;">
                        <td colspan="9">
                            <div class="empty-state" style="padding:2rem;">
                                <i class="fas fa-magnifying-glass" style="font-size:2rem; opacity:0.1; margin-bottom:0.5rem;"></i>
                                <p>No transactions match your search filter.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Select Member Modal for Front-Desk Cash -->
<div class="modal-overlay" id="payment-member-modal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h3><i class="fas fa-hand-holding-dollar" style="color:var(--success); margin-right:8px;"></i> Record Front-Desk Cash Payment</h3>
            <button class="modal-close" onclick="closePaymentModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <p style="color:var(--text-muted); font-size:0.875rem; margin-bottom:1.25rem;">
            Select an existing member to record an over-the-counter cash renewal.
        </p>
        
        <div class="search-bar" style="max-width:100%; margin-bottom:1.25rem;">
            <i class="fas fa-magnifying-glass"></i>
            <input type="text" id="member-search-input" placeholder="Search member name or ID..." class="form-control">
        </div>
        
        <div id="modal-members-list" style="max-height:280px; overflow-y:auto; display:flex; flex-direction:column; gap:0.5rem; padding-right:5px; margin-bottom:1.25rem;">
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
                            <div class="cell-primary" style="font-weight:600;"><?php echo htmlspecialchars($m['full_name']); ?></div>
                            <code class="cell-secondary" style="font-size:0.72rem;"><?php echo htmlspecialchars($m['membership_id']); ?></code>
                        </div>
                    </div>
                    <a href="renew-member.php?id=<?php echo $m['id']; ?>" class="btn btn-primary btn-sm" style="text-decoration:none;">Select</a>
                </div>
                <?php endforeach; ?>
                <div id="no-modal-members-results" style="display:none; text-align:center; padding:2rem; color:var(--text-muted);">No matching members found.</div>
            <?php endif; ?>
        </div>
        
        <div style="padding-top:1rem; border-top:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
            <span class="cell-secondary">New walk-in member?</span>
            <a href="add-member.php" class="btn btn-outline btn-sm" style="text-decoration:none;"><i class="fas fa-user-plus"></i> Register Member</a>
        </div>
    </div>
</div>

<script>
function openPaymentModal() {
    document.getElementById('payment-member-modal').classList.add('active');
    document.getElementById('member-search-input').value = '';
    const items = document.querySelectorAll('.modal-member-item');
    items.forEach(item => item.style.setProperty('display', 'flex', 'important'));
    document.getElementById('no-modal-members-results').style.display = 'none';
    setTimeout(() => { document.getElementById('member-search-input').focus(); }, 100);
}

function closePaymentModal() {
    document.getElementById('payment-member-modal').classList.remove('active');
}

// Modal Member Search
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

// Real-time Transaction Filter
const txnSearch = document.getElementById('txn-search-input');
if (txnSearch) {
    txnSearch.addEventListener('input', function(e) {
        const q = e.target.value.toLowerCase().trim();
        const rows = document.querySelectorAll('.txn-row');
        let visibleCount = 0;
        
        rows.forEach(row => {
            const name = row.dataset.name || '';
            const id   = row.dataset.id || '';
            const ref  = row.dataset.ref || '';
            const txn  = row.dataset.txn || '';
            if (name.includes(q) || id.includes(q) || ref.includes(q) || txn.includes(q)) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        const countSpan = document.getElementById('txn-count');
        if (countSpan) countSpan.textContent = visibleCount;
        
        const noResultsRow = document.getElementById('no-txn-results');
        if (noResultsRow) {
            noResultsRow.style.display = visibleCount === 0 ? '' : 'none';
        }
    });
}
</script>

<?php include 'includes/footer.php'; ?>
