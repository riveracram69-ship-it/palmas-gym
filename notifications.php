<?php
$page_title = 'Notifications';
include 'includes/header.php';
include 'includes/sidebar.php';
require_once __DIR__ . '/config/notifications.php';
require_admin();

$notifications = [];
try {
    if (isset($pdo) && $pdo) {
        ensure_notifications_table($pdo);

        // Mark as read handler
        if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
            $pdo->prepare("UPDATE notifications SET read_status = 'Read' WHERE id = ?")->execute([$_GET['mark_read']]);
            header("Location: notifications.php");
            exit;
        }

        $stmt = $pdo->query(
            "SELECT n.*, m.full_name, m.membership_id, m.email 
             FROM notifications n 
             INNER JOIN members m ON n.member_id = m.id 
             ORDER BY n.sent_at DESC 
             LIMIT 50"
        );
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $notifications = [];
}

$type_icons = [
    'Registration' => ['fas fa-user-plus',   '#e6f4ea', 'var(--success)'],
    'Inactivity'   => ['fas fa-moon',         '#fdf7ec', 'var(--accent)'],
    'Expiration'   => ['fas fa-calendar-xmark','#fce8e6', 'var(--danger)'],
    'Renewal'      => ['fas fa-file-invoice-dollar', '#e8f0fe', 'var(--accent)'],
];
?>

<div class="topbar">
    <div class="page-title">
        <h1>Notifications</h1>
        <p>A history of automated system notifications and member events.</p>
    </div>
</div>

<div class="card" style="padding:0; overflow:hidden;">
    <?php if (empty($notifications)): ?>
    <?php render_empty_state('fas fa-bell-slash', 'No activity recorded yet.', '', false); ?>
    <?php else: ?>
    <div>
        <?php foreach ($notifications as $n):
            $is_unread = (isset($n['read_status']) && $n['read_status'] === 'Unread');
            [$icon, $bg, $color] = $type_icons[$n['type']] ?? ['fas fa-bell', '#f8f9fa', 'var(--text-muted)'];
        ?>
        <div class="notification-item <?php echo $is_unread ? 'unread' : ''; ?>">
            <div class="notification-icon" style="background:<?php echo $bg; ?>; color:<?php echo $color; ?>;">
                <i class="<?php echo $icon; ?>"></i>
            </div>
            <div style="flex:1;">
                <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.25rem;">
                    <span class="cell-primary"><?php echo htmlspecialchars($n['full_name'] ?? 'System'); ?></span>
                    <?php if(!empty($n['membership_id'])): ?>
                    <span class="cell-secondary" style="font-family:monospace; color:var(--accent); background:var(--accent-dim); padding:2px 6px; border-radius:4px;"><?php echo htmlspecialchars($n['membership_id']); ?></span>
                    <?php endif; ?>
                    <span class="notification-type-badge" style="color:<?php echo $color; ?>;">
                        <?php echo htmlspecialchars($n['type']); ?>
                    </span>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:0.25rem;">
                    <span class="cell-secondary"><?php echo htmlspecialchars($n['title']); ?></span>
                    <div style="display:flex; gap:1rem; align-items:center;">
                        <span class="cell-secondary" style="<?php echo ($n['delivery_status'] === 'Failed') ? 'color:var(--danger);' : 'color:var(--success);'; ?>">
                            <i class="<?php echo ($n['delivery_status'] === 'Failed') ? 'fas fa-circle-exclamation' : 'fas fa-check-double'; ?>"></i> <?php echo htmlspecialchars($n['delivery_status']); ?>
                        </span>
                        <span class="cell-secondary"><i class="far fa-clock"></i> <?php echo date('M d, Y • h:i A', strtotime($n['sent_at'])); ?></span>
                        <?php if ($is_unread): ?>
                            <a href="?mark_read=<?php echo $n['id']; ?>" class="btn btn-outline btn-sm" title="Mark as Read"><i class="fas fa-check"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
