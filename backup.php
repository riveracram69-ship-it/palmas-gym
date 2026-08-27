<?php
$page_title = 'Backup & Restore';
include 'includes/header.php';
include 'includes/sidebar.php';
require_admin();

$backup_dir = __DIR__ . '/backups/';
if (!is_dir($backup_dir)) @mkdir($backup_dir, 0755, true);

$message = '';
$error = '';

// Handle Download
if (isset($_GET['download'])) {
    $file = basename($_GET['download']);
    $filepath = $backup_dir . $file;
    if (file_exists($filepath) && pathinfo($filepath, PATHINFO_EXTENSION) === 'sql') {
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF is already auto-verified by auth.php
    $action = $_POST['action'] ?? '';
    $file = isset($_POST['file']) ? basename($_POST['file']) : '';
    $filepath = $backup_dir . $file;
    global $host, $db, $user, $pass; // From config/db.php included via header.php/sidebar.php usually, but let's just make sure
    
    // Fallback if not loaded
    $db_host = defined('DB_HOST') ? DB_HOST : ($host ?? 'localhost');
    $db_port = defined('DB_PORT') ? DB_PORT : '3306';
    $db_name = defined('DB_NAME') ? DB_NAME : ($db ?? 'gym_management');
    $db_user = defined('DB_USER') ? DB_USER : ($user ?? 'root');
    $db_pass = defined('DB_PASS') ? DB_PASS : ($pass ?? '');

    // Dynamic Cross-Platform Binary Path Resolution (Linux / Unix / Windows)
    $resolve_bin = function(string $override_path, string $bin_name): string {
        if (!empty($override_path) && file_exists($override_path)) {
            return $override_path;
        }
        // Check Windows common paths
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $win_candidates = [
                'C:\\xam\\mysql\\bin\\' . $bin_name . '.exe',
                'C:\\xampp\\mysql\\bin\\' . $bin_name . '.exe',
                $bin_name . '.exe',
                $bin_name
            ];
            foreach ($win_candidates as $cand) {
                if (file_exists($cand)) return $cand;
            }
            return $bin_name . '.exe';
        }
        // Linux / Unix standard paths
        $linux_candidates = [
            '/usr/bin/' . $bin_name,
            '/usr/local/bin/' . $bin_name,
            '/usr/bin/mariadb-dump',
            $bin_name
        ];
        foreach ($linux_candidates as $cand) {
            if (file_exists($cand)) return $cand;
        }
        return $bin_name;
    };

    $mysqldump_bin = $resolve_bin(defined('MYSQLDUMP_PATH') ? MYSQLDUMP_PATH : '', 'mysqldump');
    $mysql_bin = $resolve_bin(defined('MYSQL_PATH') ? MYSQL_PATH : '', 'mysql');
    
    if ($action === 'generate') {
        $filename = 'gym_backup_' . date('Ymd_His') . '.sql';
        $target = $backup_dir . $filename;
        
        $e_host = escapeshellarg($db_host);
        $e_port = escapeshellarg($db_port);
        $e_user = escapeshellarg($db_user);
        $e_pass = $db_pass !== '' ? '-p' . escapeshellarg($db_pass) . ' ' : '';
        $e_name = escapeshellarg($db_name);
        $e_target = escapeshellarg($target);

        $cmd = escapeshellcmd($mysqldump_bin) . " -h {$e_host} -P {$e_port} -u {$e_user} {$e_pass}{$e_name} > {$e_target}";
        exec($cmd, $output, $return_var);
        
        if ($return_var === 0 && file_exists($target) && filesize($target) > 0) {
            $message = "Backup generated successfully: $filename";
            log_activity($pdo, 'Backup Generated', "Created database backup: $filename", 'System');
        } else {
            $error = "Failed to generate backup. Please check database permissions or configured binary paths.";
        }
    } elseif ($action === 'delete' && file_exists($filepath)) {
        if (unlink($filepath)) {
            $message = "Backup deleted successfully: $file";
            log_activity($pdo, 'Backup Deleted', "Removed database backup: $file", 'System');
        } else {
            $error = "Failed to delete backup file.";
        }
    } elseif ($action === 'restore' && file_exists($filepath)) {
        $e_host = escapeshellarg($db_host);
        $e_port = escapeshellarg($db_port);
        $e_user = escapeshellarg($db_user);
        $e_pass = $db_pass !== '' ? '-p' . escapeshellarg($db_pass) . ' ' : '';
        $e_name = escapeshellarg($db_name);
        $e_target = escapeshellarg($filepath);

        $cmd = escapeshellcmd($mysql_bin) . " -h {$e_host} -P {$e_port} -u {$e_user} {$e_pass}{$e_name} < {$e_target}";
        exec($cmd, $output, $return_var);
        
        if ($return_var === 0) {
            $message = "Database restored successfully from: $file";
            log_activity($pdo, 'Database Restored', "Restored system from backup: $file", 'System');
        } else {
            $error = "Failed to restore database. Return code: $return_var";
        }
    }
}

// Get list of backups
$backups = [];
foreach (glob($backup_dir . '*.sql') as $file) {
    $backups[] = [
        'filename' => basename($file),
        'size' => filesize($file),
        'date' => filemtime($file)
    ];
}
usort($backups, function($a, $b) { return $b['date'] <=> $a['date']; });

function formatBytes($bytes) { 
    $units = ['B', 'KB', 'MB', 'GB']; 
    $bytes = max($bytes, 0); 
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
    $pow = min($pow, count($units) - 1); 
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow]; 
} 
?>

<div class="topbar">
    <div class="page-title">
        <h1>Data Backup & Restore</h1>
        <p>Safeguard your gym data by creating and managing database backups.</p>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-error"><i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:2rem; display:flex; justify-content:space-between; align-items:center;">
    <div>
        <h3 style="margin:0 0 0.5rem 0; color:var(--text-main);">Generate New Backup</h3>
        <p style="margin:0; font-size:0.85rem; color:var(--text-muted);">Create a complete snapshot of the current database.</p>
    </div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
        <input type="hidden" name="action" value="generate">
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-database"></i> Generate Backup
        </button>
    </form>
</div>

<div class="card">
    <h3 class="section-title"><i class="fas fa-history" style="color:var(--accent);"></i> Backup History</h3>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Filename</th>
                    <th>Date Created</th>
                    <th>Size</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($backups)): ?>
                <?php render_empty_state('fas fa-folder-open', 'No backups found.', 'Generate one to get started.', true); ?>
                <?php else: ?>
                    <?php foreach($backups as $b): ?>
                    <tr>
                        <td class="cell-primary">
                            <i class="far fa-file-code" style="color:var(--accent); margin-right:8px;"></i>
                            <?php echo htmlspecialchars($b['filename']); ?>
                        </td>
                        <td class="cell-primary"><?php echo date('M d, Y h:i A', $b['date']); ?></td>
                        <td class="cell-secondary"><?php echo formatBytes($b['size']); ?></td>
                        <td style="text-align:right;">
                            <div style="display:flex; justify-content:flex-end; gap:0.5rem;">
                                <a href="?download=<?php echo urlencode($b['filename']); ?>" class="btn btn-outline btn-sm" title="Download">
                                    <i class="fas fa-download"></i>
                                </a>
                                <button type="button" class="btn btn-outline btn-sm"
                                    style="color:var(--warning); border-color:var(--warning);"
                                    title="Restore Database"
                                    data-file="<?php echo htmlspecialchars($b['filename']); ?>"
                                    onclick="palmasConfirm('Restore this backup?', 'Are you sure you want to restore? Current data will be overwritten.', () => { 
                                        const f = document.createElement('form'); f.method='POST'; 
                                        f.innerHTML='<input type=\'hidden\' name=\'csrf_token\' value=\'<?php echo get_csrf_token(); ?>\'><input type=\'hidden\' name=\'action\' value=\'restore\'><input type=\'hidden\' name=\'file\' value=\'' + this.dataset.file + '\'>'; 
                                        document.body.appendChild(f); f.submit(); 
                                    })">
                                    <i class="fas fa-rotate-left"></i> Restore
                                </button>
                                <button type="button" class="btn btn-outline btn-sm"
                                    style="color:var(--danger); border-color:var(--danger);"
                                    title="Delete"
                                    data-file="<?php echo htmlspecialchars($b['filename']); ?>"
                                    onclick="palmasConfirm('Delete this backup?', 'This action cannot be undone.', () => { 
                                        const f = document.createElement('form'); f.method='POST'; 
                                        f.innerHTML='<input type=\'hidden\' name=\'csrf_token\' value=\'<?php echo get_csrf_token(); ?>\'><input type=\'hidden\' name=\'action\' value=\'delete\'><input type=\'hidden\' name=\'file\' value=\'' + this.dataset.file + '\'>'; 
                                        document.body.appendChild(f); f.submit(); 
                                    })">
                                    <i class="fas fa-trash"></i>
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

<?php include 'includes/footer.php'; ?>
