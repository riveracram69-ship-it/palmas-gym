<?php
/**
 * Expenses Management Page
 * Palma's Elite Gym Management System
 */

$page_title = 'Gym Expenses & Cost Management';
include 'includes/header.php';
include 'includes/sidebar.php';
require_admin();

$expenses = [];
$total_period_expenses = 0;
$this_month_expenses = 0;
$top_category_name = '—';
$top_category_amount = 0;

// Filter parameters
$date_preset = $_GET['date_preset'] ?? 'month';
$start_date  = $_GET['start_date'] ?? date('Y-m-01');
$end_date    = $_GET['end_date'] ?? date('Y-m-t');
$cat_filter  = $_GET['category'] ?? 'all';
$pay_filter  = $_GET['payment_method'] ?? 'all';
$search_q    = trim($_GET['q'] ?? '');

// Preset calculations
if ($date_preset !== 'custom' && $date_preset !== 'all') {
    switch ($date_preset) {
        case 'today':
            $start_date = date('Y-m-d');
            $end_date   = date('Y-m-d');
            break;
        case 'yesterday':
            $start_date = date('Y-m-d', strtotime('-1 day'));
            $end_date   = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'week':
            $start_date = date('Y-m-d', strtotime('monday this week'));
            $end_date   = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'month':
            $start_date = date('Y-m-01');
            $end_date   = date('Y-m-t');
            break;
        case 'last_month':
            $start_date = date('Y-m-01', strtotime('first day of last month'));
            $end_date   = date('Y-m-t', strtotime('last day of last month'));
            break;
        case 'year':
            $start_date = date('Y-01-01');
            $end_date   = date('Y-12-31');
            break;
    }
}

$categories_list = [
    'Utilities (Electricity & Water)',
    'Staff Salaries & Payroll',
    'Equipment Maintenance & Repair',
    'Rent & Facility Lease',
    'Gym Supplies & Sanitation',
    'Marketing, Promo & Ads',
    'Internet, Telecoms & Tech',
    'Taxes, Licenses & Permits',
    'Miscellaneous & Others'
];

try {
    if (isset($pdo) && $pdo) {
        // 1. Fetch filtered expenses list
        $sql = "
            SELECT e.*, u.name as recorded_by_name
            FROM expenses e
            LEFT JOIN users u ON e.recorded_by = u.id
            WHERE 1=1
        ";
        $params = [];

        if ($date_preset !== 'all' && !empty($start_date) && !empty($end_date)) {
            $sql .= " AND e.expense_date BETWEEN :start_date AND :end_date";
            $params['start_date'] = $start_date;
            $params['end_date']   = $end_date;
        }

        if ($cat_filter !== 'all' && !empty($cat_filter)) {
            $sql .= " AND e.category = :category";
            $params['category'] = $cat_filter;
        }

        if ($pay_filter !== 'all' && !empty($pay_filter)) {
            $sql .= " AND e.payment_method = :payment_method";
            $params['payment_method'] = $pay_filter;
        }

        if (!empty($search_q)) {
            $sql .= " AND (e.title LIKE :q OR e.reference_number LIKE :q OR e.notes LIKE :q)";
            $params['q'] = "%{$search_q}%";
        }

        $sql .= " ORDER BY e.expense_date DESC, e.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Fetch KPI stats
        // Period Total
        foreach ($expenses as $exp) {
            $total_period_expenses += (float)$exp['amount'];
        }

        // This Month Total
        $cur_m_start = date('Y-m-01');
        $cur_m_end   = date('Y-m-t');
        $stmt_mo = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date BETWEEN ? AND ?");
        $stmt_mo->execute([$cur_m_start, $cur_m_end]);
        $this_month_expenses = (float)$stmt_mo->fetchColumn();

        // Top Category in Period
        $top_sql = "SELECT category, SUM(amount) as cat_total FROM expenses WHERE 1=1";
        $top_params = [];
        if ($date_preset !== 'all' && !empty($start_date) && !empty($end_date)) {
            $top_sql .= " AND expense_date BETWEEN ? AND ?";
            $top_params = [$start_date, $end_date];
        }
        $top_sql .= " GROUP BY category ORDER BY cat_total DESC LIMIT 1";
        $stmt_top = $pdo->prepare($top_sql);
        $stmt_top->execute($top_params);
        $top_cat = $stmt_top->fetch(PDO::FETCH_ASSOC);
        if ($top_cat) {
            $top_category_name = $top_cat['category'];
            $top_category_amount = (float)$top_cat['cat_total'];
        }
    }
} catch (Exception $e) {
    error_log("Error in expenses.php: " . $e->getMessage());
}
?>

<div class="topbar">
    <div class="page-title">
        <div style="display:flex; align-items:center; gap:0.75rem;">
            <div style="width:44px; height:44px; border-radius:12px; background:linear-gradient(135deg, #1b4332, #2d6a4f); display:flex; align-items:center; justify-content:center; color:#52b788; font-size:1.3rem; box-shadow:0 4px 15px rgba(45,106,79,0.25);">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
            <div>
                <h1 style="margin:0; font-size:1.6rem; letter-spacing:-0.5px;">Gym Expenses &amp; Cost Ledger</h1>
                <p style="margin:0.2rem 0 0 0; color:var(--text-muted); font-size:0.85rem;">Record, track, and categorize all operational gym costs, rent, utilities, and salaries.</p>
            </div>
        </div>
    </div>
    <div style="display:flex; gap:0.75rem; align-items:center;">
        <a href="reports.php?tab=tab-financials" class="btn btn-outline" style="border-color:var(--border);">
            <i class="fas fa-chart-line" style="color:var(--accent);"></i> View Financial Reports
        </a>
        <button onclick="openExpenseModal()" class="btn btn-primary">
            <i class="fas fa-plus"></i> Record New Expense
        </button>
    </div>
</div>

<!-- ── EXPENSES KPI SUMMARY CARDS ─────────────────────────────────────────── -->
<div class="stats-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
    <!-- KPI 1: Period Total Expenses -->
    <div class="card stat-card" style="padding:1.25rem;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.5rem;">
            <span class="stat-label" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">Filtered Expenses</span>
            <div class="stat-icon red" style="width:34px; height:34px; font-size:0.95rem; margin:0; background:rgba(239,68,68,0.12); color:#f87171;"><i class="fas fa-receipt"></i></div>
        </div>
        <h2 class="stat-value" style="font-size:1.6rem; font-weight:800; color:#f87171; margin:0;">&#8369;<?php echo number_format($total_period_expenses, 2); ?></h2>
        <p class="stat-meta" style="font-size:0.72rem; margin-top:0.35rem; color:var(--text-muted);"><i class="fas fa-calendar-alt"></i> Coverage: <?php echo date('M d', strtotime($start_date)); ?> – <?php echo date('M d, Y', strtotime($end_date)); ?></p>
    </div>

    <!-- KPI 2: Current Month Expenses -->
    <div class="card stat-card" style="padding:1.25rem;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.5rem;">
            <span class="stat-label" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">This Month's Total</span>
            <div class="stat-icon yellow" style="width:34px; height:34px; font-size:0.95rem; margin:0; background:rgba(234,179,8,0.12); color:#eab308;"><i class="fas fa-calendar-check"></i></div>
        </div>
        <h2 class="stat-value" style="font-size:1.6rem; font-weight:800; color:#eab308; margin:0;">&#8369;<?php echo number_format($this_month_expenses, 2); ?></h2>
        <p class="stat-meta" style="font-size:0.72rem; margin-top:0.35rem; color:var(--text-muted);"><i class="fas fa-clock"></i> Month of <?php echo date('F Y'); ?></p>
    </div>

    <!-- KPI 3: Top Expense Category -->
    <div class="card stat-card" style="padding:1.25rem;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.5rem;">
            <span class="stat-label" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">Top Cost Category</span>
            <div class="stat-icon blue" style="width:34px; height:34px; font-size:0.95rem; margin:0; background:rgba(56,189,248,0.12); color:#38bdf8;"><i class="fas fa-chart-pie"></i></div>
        </div>
        <h2 class="stat-value" style="font-size:1.15rem; font-weight:800; color:var(--text-main); margin:0.35rem 0 0.15rem 0; line-height:1.2;"><?php echo htmlspecialchars($top_category_name); ?></h2>
        <p class="stat-meta" style="font-size:0.72rem; margin-top:0.35rem; color:#38bdf8;"><i class="fas fa-peso-sign"></i> &#8369;<?php echo number_format($top_category_amount, 2); ?> allocated</p>
    </div>

    <!-- KPI 4: Total Entries Count -->
    <div class="card stat-card" style="padding:1.25rem;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.5rem;">
            <span class="stat-label" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; font-weight:700;">Recorded Entries</span>
            <div class="stat-icon green" style="width:34px; height:34px; font-size:0.95rem; margin:0; background:rgba(82,183,136,0.12); color:#52b788;"><i class="fas fa-list-check"></i></div>
        </div>
        <h2 class="stat-value" style="font-size:1.6rem; font-weight:800; color:#52b788; margin:0;"><?php echo number_format(count($expenses)); ?></h2>
        <p class="stat-meta" style="font-size:0.72rem; margin-top:0.35rem; color:var(--text-muted);"><i class="fas fa-shield-check"></i> All entries verified</p>
    </div>
</div>

<!-- ── FILTER CONTROLS ────────────────────────────────────────────────────── -->
<div class="card" style="padding:1.25rem; margin-bottom:1.5rem; border:1px solid rgba(45,106,79,0.2);">
    <form method="GET" action="expenses.php" id="expense-filter-form" style="display:flex; flex-direction:column; gap:1rem;">
        <!-- Preset Buttons Row -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.5rem;">
            <div style="display:flex; align-items:center; gap:0.4rem; flex-wrap:wrap;">
                <span style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin-right:4px;">Date Preset:</span>
                <?php
                $presets = [
                    'month'      => 'This Month',
                    'last_month' => 'Last Month',
                    'today'      => 'Today',
                    'week'       => 'This Week',
                    'year'       => 'This Year',
                    'all'        => 'All Time',
                    'custom'     => 'Custom Range'
                ];
                foreach ($presets as $p_key => $p_name):
                    $isActive = ($date_preset === $p_key);
                ?>
                <button type="button" class="btn <?php echo $isActive ? 'btn-primary' : 'btn-outline'; ?>" 
                        onclick="applyExpensePreset('<?php echo $p_key; ?>')"
                        style="padding:0.35rem 0.75rem; font-size:0.75rem; font-weight:600; border-radius:8px;">
                    <?php echo $p_name; ?>
                </button>
                <?php endforeach; ?>
                <input type="hidden" name="date_preset" id="expense-preset-input" value="<?php echo htmlspecialchars($date_preset); ?>">
            </div>

            <div style="display:flex; align-items:center; gap:0.5rem; font-size:0.82rem; font-weight:700; color:var(--accent);">
                <i class="fas fa-calendar-check"></i>
                <span>Active Coverage: <?php echo date('M d, Y', strtotime($start_date)); ?> – <?php echo date('M d, Y', strtotime($end_date)); ?></span>
            </div>
        </div>

        <!-- Filter Dropdowns Row -->
        <div style="display:grid; grid-template-columns: auto 1.2fr 1fr 1.2fr auto; gap:1rem; align-items:flex-end; border-top:1px solid rgba(255,255,255,0.06); padding-top:1rem;">
            <!-- Custom Date Inputs -->
            <div id="expense-custom-dates" style="display: <?php echo $date_preset === 'custom' ? 'flex' : 'none'; ?>; gap:0.5rem; align-items:center;">
                <div>
                    <label style="display:block; font-size:0.72rem; color:var(--text-muted); font-weight:600; margin-bottom:0.25rem;">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>" style="padding:0.45rem 0.65rem; font-size:0.8rem; margin:0;">
                </div>
                <div>
                    <label style="display:block; font-size:0.72rem; color:var(--text-muted); font-weight:600; margin-bottom:0.25rem;">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>" style="padding:0.45rem 0.65rem; font-size:0.8rem; margin:0;">
                </div>
            </div>

            <!-- Category Filter -->
            <div>
                <label style="display:block; font-size:0.72rem; color:var(--text-muted); font-weight:600; margin-bottom:0.25rem;">Category</label>
                <select name="category" class="form-control" style="padding:0.45rem 0.75rem; font-size:0.8rem; margin:0;">
                    <option value="all">All Expense Categories</option>
                    <?php foreach ($categories_list as $cat_opt): ?>
                    <option value="<?php echo htmlspecialchars($cat_opt); ?>" <?php echo $cat_filter === $cat_opt ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat_opt); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Payment Method Filter -->
            <div>
                <label style="display:block; font-size:0.72rem; color:var(--text-muted); font-weight:600; margin-bottom:0.25rem;">Payment Method</label>
                <select name="payment_method" class="form-control" style="padding:0.45rem 0.75rem; font-size:0.8rem; margin:0;">
                    <option value="all">All Methods</option>
                    <option value="Cash" <?php echo $pay_filter === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                    <option value="GCash" <?php echo $pay_filter === 'GCash' ? 'selected' : ''; ?>>GCash</option>
                    <option value="Bank Transfer" <?php echo $pay_filter === 'Bank Transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                    <option value="Cheque" <?php echo $pay_filter === 'Cheque' ? 'selected' : ''; ?>>Cheque</option>
                    <option value="Credit Card" <?php echo $pay_filter === 'Credit Card' ? 'selected' : ''; ?>>Credit Card</option>
                </select>
            </div>

            <!-- Search Filter -->
            <div>
                <label style="display:block; font-size:0.72rem; color:var(--text-muted); font-weight:600; margin-bottom:0.25rem;">Search Description / Ref #</label>
                <div style="position:relative;">
                    <input type="text" name="q" placeholder="Filter by title, ref or notes..." value="<?php echo htmlspecialchars($search_q); ?>" class="form-control" style="padding:0.45rem 0.75rem 0.45rem 2rem; font-size:0.8rem; margin:0;">
                    <i class="fas fa-search" style="position:absolute; left:0.75rem; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:0.75rem;"></i>
                </div>
            </div>

            <!-- Action Buttons -->
            <div style="display:flex; gap:0.5rem;">
                <button type="submit" class="btn btn-primary" style="padding:0.45rem 1.15rem; font-size:0.8rem;"><i class="fas fa-filter"></i> Apply</button>
                <a href="expenses.php" class="btn btn-outline" style="padding:0.45rem 0.75rem; font-size:0.8rem; color:var(--text-muted);" title="Reset Filters"><i class="fas fa-rotate-left"></i></a>
            </div>
        </div>
    </form>
</div>

<!-- ── EXPENSES DATA TABLE ────────────────────────────────────────────────── -->
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:0.5rem;">
        <div>
            <h3 class="section-title" style="margin:0; font-size:1.1rem;"><i class="fas fa-table-list" style="color:var(--accent);"></i> Recorded Operational Expenses</h3>
            <p style="margin:0.2rem 0 0 0; font-size:0.75rem; color:var(--text-muted);">Detailed audit ledger of verified operating expenses.</p>
        </div>
        <div>
            <a href="reports.php?export=expenses&format=xls" class="btn btn-outline" style="font-size:0.78rem; padding:0.35rem 0.8rem;">
                <i class="fas fa-file-excel" style="color:#52b788;"></i> Export Excel
            </a>
            <a href="reports.php?export=expenses&format=csv" class="btn btn-outline" style="font-size:0.78rem; padding:0.35rem 0.8rem;">
                <i class="fas fa-file-csv" style="color:#a7f3d0;"></i> Export CSV
            </a>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th style="width:110px;">Date</th>
                    <th>Category</th>
                    <th>Description / Title</th>
                    <th>Method</th>
                    <th>Reference / Voucher</th>
                    <th>Receipt</th>
                    <th>Recorded By</th>
                    <th style="text-align:right;">Amount</th>
                    <th style="text-align:center; width:100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($expenses)): ?>
                <tr>
                    <td colspan="9" style="text-align:center; padding:3rem; color:var(--text-muted);">
                        <i class="fas fa-receipt" style="font-size:2.2rem; display:block; margin-bottom:0.5rem; opacity:0.4;"></i>
                        No expense records found for the selected filter period.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($expenses as $exp): 
                    $cat_color = '#52b788';
                    $cat_bg = 'rgba(82,183,136,0.12)';
                    if (stripos($exp['category'], 'utilities') !== false) {
                        $cat_color = '#38bdf8'; $cat_bg = 'rgba(56,189,248,0.12)';
                    } elseif (stripos($exp['category'], 'salaries') !== false || stripos($exp['category'], 'payroll') !== false) {
                        $cat_color = '#c084fc'; $cat_bg = 'rgba(192,132,252,0.12)';
                    } elseif (stripos($exp['category'], 'rent') !== false) {
                        $cat_color = '#f87171'; $cat_bg = 'rgba(248,113,113,0.12)';
                    } elseif (stripos($exp['category'], 'maintenance') !== false) {
                        $cat_color = '#eab308'; $cat_bg = 'rgba(234,179,8,0.12)';
                    }
                ?>
                <tr>
                    <td style="font-weight:600; color:var(--text-main); font-size:0.82rem;">
                        <?php echo date('M d, Y', strtotime($exp['expense_date'])); ?>
                    </td>
                    <td>
                        <span class="badge" style="background:<?php echo $cat_bg; ?>; color:<?php echo $cat_color; ?>; font-weight:700;">
                            <?php echo htmlspecialchars($exp['category']); ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-weight:600; color:var(--text-main); font-size:0.85rem;"><?php echo htmlspecialchars($exp['title']); ?></div>
                        <?php if (!empty($exp['notes'])): ?>
                            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;"><?php echo htmlspecialchars($exp['notes']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge" style="background:rgba(255,255,255,0.06); color:var(--text-main); font-size:0.75rem;">
                            <i class="fas <?php echo $exp['payment_method'] === 'GCash' ? 'fa-mobile-screen' : ($exp['payment_method'] === 'Cash' ? 'fa-money-bill' : 'fa-building-columns'); ?>" style="margin-right:4px;"></i>
                            <?php echo htmlspecialchars($exp['payment_method']); ?>
                        </span>
                    </td>
                    <td>
                        <span style="font-family:monospace; font-size:0.78rem; color:var(--text-muted);">
                            <?php echo htmlspecialchars($exp['reference_number'] ?: '—'); ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!empty($exp['receipt_photo'])): 
                            $isPdf = strtolower(pathinfo($exp['receipt_photo'], PATHINFO_EXTENSION)) === 'pdf';
                        ?>
                            <?php if ($isPdf): ?>
                                <a href="<?php echo htmlspecialchars($exp['receipt_photo']); ?>" target="_blank" class="btn btn-outline" style="padding:0.2rem 0.55rem; font-size:0.72rem; border-color:var(--border);">
                                    <i class="fas fa-file-pdf" style="color:#f87171;"></i> View PDF
                                </a>
                            <?php else: ?>
                                <button type="button" onclick="previewReceiptImage('<?php echo htmlspecialchars($exp['receipt_photo']); ?>', '<?php echo htmlspecialchars(addslashes($exp['title'])); ?>')" style="background:none; border:none; padding:0; cursor:pointer;">
                                    <img src="<?php echo htmlspecialchars($exp['receipt_photo']); ?>" alt="Receipt" style="width:34px; height:34px; border-radius:6px; object-fit:cover; border:1px solid var(--border);" onerror="this.src='assets/images/palmas-logo.png'">
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="font-size:0.75rem; color:var(--text-muted);">No File</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.8rem; color:var(--text-muted);">
                        <?php echo htmlspecialchars($exp['recorded_by_name'] ?: 'Admin'); ?>
                    </td>
                    <td style="text-align:right; font-weight:800; color:#f87171; font-size:0.95rem;">
                        -&#8369;<?php echo number_format((float)$exp['amount'], 2); ?>
                    </td>
                    <td style="text-align:center;">
                        <div style="display:flex; justify-content:center; gap:0.4rem;">
                            <button class="btn btn-outline" onclick="editExpense(<?php echo $exp['id']; ?>)" style="padding:0.25rem 0.55rem; font-size:0.75rem; border-color:var(--border);" title="Edit Expense">
                                <i class="fas fa-pen-to-square" style="color:var(--accent);"></i>
                            </button>
                            <button class="btn btn-outline" onclick="deleteExpense(<?php echo $exp['id']; ?>, '<?php echo htmlspecialchars(addslashes($exp['title'])); ?>')" style="padding:0.25rem 0.55rem; font-size:0.75rem; border-color:rgba(239,68,68,0.3);" title="Delete Expense">
                                <i class="fas fa-trash-can" style="color:#ef4444;"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($expenses)): ?>
            <tfoot>
                <tr>
                    <th colspan="7" style="text-align:right; font-weight:800;">TOTAL FILTERED EXPENSES:</th>
                    <th style="text-align:right; font-weight:800; color:#f87171; font-size:1.05rem;">
                        -&#8369;<?php echo number_format($total_period_expenses, 2); ?>
                    </th>
                    <th></th>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- ── ADD / EDIT EXPENSE MODAL ───────────────────────────────────────────── -->
<div class="modal-overlay" id="expense-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(10,25,18,0.75); z-index:10000; align-items:center; justify-content:center; backdrop-filter:blur(8px);">
    <div class="modal" style="background:var(--card-bg); border-radius:20px; padding:2rem; width:90%; max-width:600px; box-shadow:0 20px 40px -15px rgba(0,0,0,0.5); border:1px solid rgba(45,106,79,0.25); position:relative; max-height:90vh; overflow-y:auto;">
        <button onclick="closeExpenseModal()" aria-label="Close Modal" style="position:absolute; top:1.5rem; right:1.5rem; background:none; border:none; font-size:1.3rem; color:var(--text-muted); cursor:pointer;" onmouseover="this.style.color='var(--danger)'" onmouseout="this.style.color='var(--text-muted)'">
            <i class="fas fa-xmark"></i>
        </button>
        
        <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1.25rem;">
            <div style="width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; background:rgba(239,68,68,0.12); color:#f87171;">
                <i class="fas fa-receipt" id="modal-header-icon"></i>
            </div>
            <div>
                <h3 id="expense-modal-title" style="margin:0; font-size:1.3rem; color:var(--text-main);">Record Operational Expense</h3>
                <span style="font-size:0.8rem; color:var(--text-muted);">Enter official expense details, category, and receipt attachment.</span>
            </div>
        </div>

        <form id="expense-form" enctype="multipart/form-data" onsubmit="saveExpense(event)" style="display:flex; flex-direction:column; gap:1rem;">
            <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
            <input type="hidden" name="action" value="save_expense">
            <input type="hidden" name="id" id="form-expense-id" value="">

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                <!-- Expense Date -->
                <div>
                    <label style="display:block; font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:0.35rem;">
                        Date Incurred <span style="color:var(--danger);">*</span>
                    </label>
                    <input type="date" name="expense_date" id="form-expense-date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required style="margin:0;">
                </div>

                <!-- Category -->
                <div>
                    <label style="display:block; font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:0.35rem;">
                        Category <span style="color:var(--danger);">*</span>
                    </label>
                    <select name="category" id="form-expense-category" class="form-control" required style="margin:0;">
                        <?php foreach ($categories_list as $cat_opt): ?>
                        <option value="<?php echo htmlspecialchars($cat_opt); ?>"><?php echo htmlspecialchars($cat_opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Title / Item Description -->
            <div>
                <label style="display:block; font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:0.35rem;">
                    Expense Title / Particulars <span style="color:var(--danger);">*</span>
                </label>
                <input type="text" name="title" id="form-expense-title" class="form-control" placeholder="e.g. Meralco Electric Bill, Gym Floor Repair, Coach Allowance" required style="margin:0;">
            </div>

            <div style="display:grid; grid-template-columns: 1.2fr 1fr; gap:1rem;">
                <!-- Amount -->
                <div>
                    <label style="display:block; font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:0.35rem;">
                        Amount (PHP &#8369;) <span style="color:var(--danger);">*</span>
                    </label>
                    <input type="number" step="0.01" min="0.01" name="amount" id="form-expense-amount" class="form-control" placeholder="0.00" required style="margin:0; font-weight:700; color:var(--text-main);">
                </div>

                <!-- Payment Method -->
                <div>
                    <label style="display:block; font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:0.35rem;">
                        Payment Method
                    </label>
                    <select name="payment_method" id="form-expense-method" class="form-control" style="margin:0;">
                        <option value="Cash">Cash</option>
                        <option value="GCash">GCash</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Cheque">Cheque</option>
                        <option value="Credit Card">Credit Card</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                <!-- Reference Number -->
                <div>
                    <label style="display:block; font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:0.35rem;">
                        Voucher / Ref / OR #
                    </label>
                    <input type="text" name="reference_number" id="form-expense-ref" class="form-control" placeholder="e.g. OR-849204, GCash Ref #" style="margin:0;">
                </div>

                <!-- Receipt Attachment -->
                <div>
                    <label style="display:block; font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:0.35rem;">
                        Receipt Attachment (JPG/PNG/PDF, Max 5MB)
                    </label>
                    <input type="file" name="receipt" id="form-expense-receipt" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf" style="margin:0; font-size:0.75rem;">
                    <div id="existing-receipt-link" style="margin-top:4px; font-size:0.75rem;"></div>
                </div>
            </div>

            <!-- Notes -->
            <div>
                <label style="display:block; font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:0.35rem;">
                    Additional Notes / Audit Remarks
                </label>
                <textarea name="notes" id="form-expense-notes" class="form-control" rows="2" placeholder="Optional audit details, vendor name, or remarks..." style="margin:0; resize:vertical;"></textarea>
            </div>

            <!-- Submit Buttons -->
            <div style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:0.5rem;">
                <button type="button" onclick="closeExpenseModal()" class="btn btn-outline" style="border-color:var(--border);">Cancel</button>
                <button type="submit" id="btn-save-expense" class="btn btn-primary" style="padding:0.6rem 1.5rem;">
                    <i class="fas fa-check"></i> Save Expense Record
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── RECEIPT IMAGE PREVIEW MODAL ────────────────────────────────────────── -->
<div class="modal-overlay" id="receipt-preview-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(10,25,18,0.85); z-index:10001; align-items:center; justify-content:center; backdrop-filter:blur(8px);">
    <div style="position:relative; max-width:90%; max-height:90%; background:var(--card-bg); border-radius:14px; padding:1rem; border:1px solid var(--border); box-shadow:0 25px 50px rgba(0,0,0,0.5); text-align:center;">
        <button onclick="closeReceiptPreview()" aria-label="Close Preview" style="position:absolute; top:0.75rem; right:0.75rem; background:rgba(0,0,0,0.6); border:none; width:36px; height:36px; border-radius:50%; color:#fff; font-size:1.1rem; cursor:pointer; display:flex; align-items:center; justify-content:center;">
            <i class="fas fa-xmark"></i>
        </button>
        <h4 id="receipt-preview-title" style="margin:0 0 0.75rem 0; font-size:1rem; color:var(--text-main); text-align:left;"></h4>
        <img id="receipt-preview-img" src="" alt="Receipt Photo" style="max-width:100%; max-height:75vh; border-radius:8px; object-fit:contain;">
    </div>
</div>

<script>
function applyExpensePreset(preset) {
    document.getElementById('expense-preset-input').value = preset;
    const customDiv = document.getElementById('expense-custom-dates');
    if (preset === 'custom') {
        customDiv.style.display = 'flex';
    } else {
        document.getElementById('expense-filter-form').submit();
    }
}

function openExpenseModal(isEdit = false) {
    if (!isEdit) {
        document.getElementById('expense-form').reset();
        document.getElementById('form-expense-id').value = '';
        document.getElementById('form-expense-date').value = '<?php echo date('Y-m-d'); ?>';
        document.getElementById('expense-modal-title').textContent = 'Record Operational Expense';
        document.getElementById('existing-receipt-link').innerHTML = '';
    }
    document.getElementById('expense-modal').style.display = 'flex';
}

function closeExpenseModal() {
    document.getElementById('expense-modal').style.display = 'none';
}

function previewReceiptImage(url, title) {
    document.getElementById('receipt-preview-img').src = url;
    document.getElementById('receipt-preview-title').textContent = 'Receipt Preview: ' + title;
    document.getElementById('receipt-preview-modal').style.display = 'flex';
}

function closeReceiptPreview() {
    document.getElementById('receipt-preview-modal').style.display = 'none';
}

async function editExpense(id) {
    try {
        const res = await fetch(`api/expenses_ajax.php?action=get_expense&id=${id}`);
        const data = await res.json();
        if (!data.success) {
            alert(data.message || 'Failed to fetch expense details.');
            return;
        }

        const exp = data.expense;
        document.getElementById('form-expense-id').value = exp.id;
        document.getElementById('form-expense-date').value = exp.expense_date;
        document.getElementById('form-expense-category').value = exp.category;
        document.getElementById('form-expense-title').value = exp.title;
        document.getElementById('form-expense-amount').value = parseFloat(exp.amount).toFixed(2);
        document.getElementById('form-expense-method').value = exp.payment_method;
        document.getElementById('form-expense-ref').value = exp.reference_number || '';
        document.getElementById('form-expense-notes').value = exp.notes || '';
        document.getElementById('expense-modal-title').textContent = 'Edit Expense #' + exp.id;

        const receiptLink = document.getElementById('existing-receipt-link');
        if (exp.receipt_photo) {
            receiptLink.innerHTML = `<span style="color:var(--accent);"><i class="fas fa-paperclip"></i> Current file: <a href="${exp.receipt_photo}" target="_blank" style="color:var(--accent); text-decoration:underline;">View existing receipt</a></span>`;
        } else {
            receiptLink.innerHTML = '';
        }

        openExpenseModal(true);
    } catch (e) {
        console.error(e);
        alert('An error occurred while loading expense details.');
    }
}

async function saveExpense(e) {
    e.preventDefault();
    const form = document.getElementById('expense-form');
    const formData = new FormData(form);
    const saveBtn = document.getElementById('btn-save-expense');

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    try {
        const res = await fetch('api/expenses_ajax.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            alert(data.message);
            window.location.reload();
        } else {
            alert(data.message || 'Error saving expense.');
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fas fa-check"></i> Save Expense Record';
        }
    } catch (err) {
        console.error(err);
        alert('An error occurred during submission.');
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fas fa-check"></i> Save Expense Record';
    }
}

async function deleteExpense(id, title) {
    if (!confirm(`Are you sure you want to delete expense "${title}"? This cannot be undone.`)) {
        return;
    }

    const formData = new FormData();
    formData.append('csrf_token', '<?php echo get_csrf_token(); ?>');
    formData.append('action', 'delete_expense');
    formData.append('id', id);

    try {
        const res = await fetch('api/expenses_ajax.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            alert(data.message);
            window.location.reload();
        } else {
            alert(data.message || 'Error deleting expense.');
        }
    } catch (err) {
        console.error(err);
        alert('An error occurred while deleting.');
    }
}
</script>

<?php include 'includes/footer.php'; ?>
