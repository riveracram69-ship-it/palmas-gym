<?php
$page_title = 'Activity Logs';
include 'includes/header.php';
include 'includes/sidebar.php';
require_admin();

// Filtering
$module_filter = $_GET['module'] ?? '';
$date_filter   = $_GET['date'] ?? '';

$where_clauses = [];
$params = [];

if ($module_filter) {
    $where_clauses[] = "module = ?";
    $params[] = $module_filter;
}
if ($date_filter) {
    $where_clauses[] = "DATE(created_at) = ?";
    $params[] = $date_filter;
}

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = "WHERE " . implode(' AND ', $where_clauses);
}

// Fetch Logs
$logs = [];
try {
    if (isset($pdo) && $pdo) {
        $stmt = $pdo->prepare("SELECT * FROM activity_logs $where_sql ORDER BY created_at DESC LIMIT 200");
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        // Get unique modules for the filter dropdown
        $modules = $pdo->query("SELECT DISTINCT module FROM activity_logs ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
    error_log('System Error in activity-logs.php: ' . $e->getMessage());
    $error = "An internal error occurred while fetching activity logs.";
}
?>

<div class="topbar">
    <div class="page-title">
        <h1>Activity Logs</h1>
        <p>Monitor system events and user actions.</p>
    </div>
</div>

<div class="card" style="margin-bottom:2rem;">
    <form method="GET" action="" class="filter-form">
        <div class="form-group">
            <label for="module">Filter by Module</label>
            <select name="module" id="module" class="form-control">
                <option value="">All Modules</option>
                <?php foreach ($modules as $mod): ?>
                    <option value="<?php echo htmlspecialchars($mod); ?>" <?php echo $module_filter === $mod ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($mod); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="date">Filter by Date</label>
            <input type="date" name="date" id="date" class="form-control" value="<?php echo htmlspecialchars($date_filter); ?>">
        </div>
        <div style="display:flex; gap:0.5rem; align-self:flex-end;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply Filters</button>
            <a href="activity-logs.php" class="btn btn-outline">Clear</a>
        </div>
    </form>
</div>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h3 class="section-title" style="margin:0;"><i class="fas fa-list-ol" style="color:var(--accent);"></i> System Events</h3>
        <span style="font-size:0.85rem; color:var(--text-muted);">Showing recent <?php echo count($logs); ?> records</span>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Module</th>
                    <th>Action</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <?php render_empty_state('fas fa-clipboard-list', 'No activity logs found.', 'Try adjusting your filters.', true); ?>
                <?php else: ?>
                    <?php foreach ($logs as $log): 
                        $module_color = 'var(--text-muted)';
                        switch (strtolower($log['module'])) {
                            case 'auth': $module_color = 'var(--info)'; break;
                            case 'member': $module_color = 'var(--accent)'; break;
                            case 'attendance': $module_color = 'var(--success)'; break;
                            case 'payment': $module_color = '#10b981'; break;
                            case 'subscription': $module_color = '#8b5cf6'; break;
                        }
                    ?>
                        <tr>
                            <td class="cell-secondary" style="white-space:nowrap;">
                                <?php echo date('M d, Y', strtotime($log['created_at'])); ?><br>
                                <span style="font-size:0.75rem;"><?php echo date('h:i A', strtotime($log['created_at'])); ?></span>
                            </td>
                            <td>
                                <div class="member-cell">
                                    <div class="member-avatar" style="width:28px; height:28px; font-size:0.7rem; border-radius:50%;">
                                        <?php echo strtoupper(substr($log['user_name'], 0, 1)); ?>
                                    </div>
                                    <span class="cell-primary"><?php echo htmlspecialchars($log['user_name']); ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="badge" style="background:transparent; border:1px solid <?php echo $module_color; ?>33; color:<?php echo $module_color; ?>;">
                                    <?php echo htmlspecialchars($log['module']); ?>
                                </span>
                            </td>
                            <td class="cell-primary"><?php echo htmlspecialchars($log['action']); ?></td>
                            <td class="cell-secondary" style="max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars($log['description']); ?>">
                                <?php echo htmlspecialchars($log['description']); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
