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

$type_meta = [
    'Registration' => [
        'icon'  => 'fas fa-user-plus',
        'bg'    => 'rgba(45, 106, 79, 0.1)',
        'color' => '#2d6a4f',
        'label' => 'Registration'
    ],
    'Renewal' => [
        'icon'  => 'fas fa-arrows-rotate',
        'bg'    => 'rgba(2, 132, 199, 0.1)',
        'color' => '#0284c7',
        'label' => 'Renewal'
    ],
    'Expiration' => [
        'icon'  => 'fas fa-calendar-xmark',
        'bg'    => 'rgba(239, 68, 68, 0.1)',
        'color' => '#ef4444',
        'label' => 'Expiration'
    ],
    'Inactivity' => [
        'icon'  => 'fas fa-moon',
        'bg'    => 'rgba(245, 158, 11, 0.1)',
        'color' => '#d97706',
        'label' => 'Inactivity'
    ],
    'Payment' => [
        'icon'  => 'fas fa-receipt',
        'bg'    => 'rgba(16, 185, 129, 0.1)',
        'color' => '#10b981',
        'label' => 'Payment'
    ],
    'System' => [
        'icon'  => 'fas fa-bell',
        'bg'    => 'rgba(100, 116, 139, 0.1)',
        'color' => '#64748b',
        'label' => 'System'
    ]
];
?>

<style>
/* ══════════════════════════════════════════════════════════════════
   NOTIFICATIONS PAGE PROFESSIONAL CARD & LIST SYSTEM
   ══════════════════════════════════════════════════════════════════ */
.notif-card-container {
    background: var(--card-bg, #ffffff) !important;
    border: 1px solid var(--border) !important;
    border-radius: var(--radius-md, 14px) !important;
    box-shadow: var(--shadow-sm) !important;
    margin-bottom: 2rem !important;
}

.notif-card-header {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    padding: 1.15rem 1.5rem !important;
    border-bottom: 1px solid var(--border) !important;
    background: var(--card-bg, #ffffff) !important;
    flex-wrap: wrap !important;
    gap: 0.65rem !important;
}

.notif-list-body {
    display: flex !important;
    flex-direction: column !important;
}

.notif-item-row {
    display: flex !important;
    align-items: center !important;
    gap: 1rem !important;
    padding: 0.85rem 1.5rem !important;
    border-bottom: 1px solid var(--border-subtle, rgba(0, 0, 0, 0.05)) !important;
    transition: background 0.15s ease !important;
    position: relative !important;
}

.notif-item-row:last-child {
    border-bottom: none !important;
}

.notif-item-row:hover {
    background: rgba(0, 0, 0, 0.015) !important;
}

.notif-item-row.is-unread {
    background: rgba(45, 106, 79, 0.025) !important;
    border-left: 3.5px solid var(--primary, #2d6a4f) !important;
}

.notif-item-row.is-read {
    border-left: 3.5px solid transparent !important;
}

.notif-item-icon {
    width: 38px !important;
    height: 38px !important;
    border-radius: 10px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 0.95rem !important;
    flex-shrink: 0 !important;
}

.notif-item-content {
    flex: 1 1 0 !important;
    min-width: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 0.2rem !important;
}

.notif-item-header {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 0.5rem !important;
    flex-wrap: wrap !important;
}

.notif-member-group {
    display: inline-flex !important;
    align-items: center !important;
    gap: 0.45rem !important;
    flex-wrap: wrap !important;
}

.notif-member-name {
    font-size: 0.88rem !important;
    font-weight: 700 !important;
    color: var(--text-main) !important;
    white-space: nowrap !important;
}

.notif-member-id {
    font-family: monospace !important;
    font-size: 0.72rem !important;
    color: var(--primary, #2d6a4f) !important;
    background: rgba(45, 106, 79, 0.08) !important;
    border: 1px solid rgba(45, 106, 79, 0.18) !important;
    padding: 1px 6px !important;
    border-radius: 4px !important;
    font-weight: 600 !important;
}

.notif-type-pill {
    font-size: 0.68rem !important;
    font-weight: 700 !important;
    padding: 2px 7px !important;
    border-radius: 6px !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    flex-shrink: 0 !important;
}

.notif-item-msg {
    font-size: 0.82rem !important;
    color: var(--text-muted) !important;
    line-height: 1.35 !important;
    word-break: break-word !important;
}

.notif-item-meta {
    display: flex !important;
    align-items: center !important;
    gap: 1.1rem !important;
    flex-shrink: 0 !important;
}

.notif-status-box {
    display: flex !important;
    flex-direction: column !important;
    align-items: flex-end !important;
    gap: 2px !important;
}

.notif-status-badge {
    font-size: 0.72rem !important;
    font-weight: 700 !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
}

.notif-status-badge.status-sent {
    color: #16a34a !important;
}

.notif-status-badge.status-failed {
    color: #dc2626 !important;
}

.notif-time {
    font-size: 0.72rem !important;
    color: var(--text-muted) !important;
    white-space: nowrap !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
}

.notif-action-btn {
    padding: 0.3rem 0.65rem !important;
    font-size: 0.74rem !important;
    font-weight: 600 !important;
    border-radius: 7px !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
    white-space: nowrap !important;
    color: var(--primary, #2d6a4f) !important;
    border-color: rgba(45, 106, 79, 0.3) !important;
    background: transparent !important;
}

.notif-action-btn:hover {
    background: var(--primary, #2d6a4f) !important;
    color: #ffffff !important;
    border-color: var(--primary, #2d6a4f) !important;
}

.notif-read-indicator {
    color: #94a3b8 !important;
    font-size: 0.85rem !important;
    padding: 0 4px !important;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .notif-item-row {
        flex-direction: column !important;
        align-items: flex-start !important;
        padding: 0.85rem 1rem !important;
        gap: 0.65rem !important;
    }
    .notif-item-icon {
        display: none !important;
    }
    .notif-item-meta {
        width: 100% !important;
        justify-content: space-between !important;
        border-top: 1px dashed var(--border-subtle, rgba(0, 0, 0, 0.06)) !important;
        padding-top: 0.5rem !important;
        margin-top: 0.2rem !important;
    }
    .notif-status-box {
        align-items: flex-start !important;
    }
}
</style>

<div class="topbar">
    <div class="page-title">
        <h1>Notifications</h1>
        <p>A history of automated system notifications and member events.</p>
    </div>
</div>

<div class="card notif-card-container" style="padding:0; overflow:hidden;">
    <?php if (empty($notifications)): ?>
    <?php render_empty_state('fas fa-bell-slash', 'No activity recorded yet.', 'Automated notifications and member events will appear here.', false); ?>
    <?php else: ?>
    
    <div class="notif-card-header">
        <div>
            <h3 class="section-title" style="margin:0; font-size:1.05rem; display:flex; align-items:center; gap:0.5rem;">
                <i class="fas fa-bell" style="color:var(--primary, #2d6a4f);"></i> System Notifications Log
            </h3>
            <p class="section-subtitle" style="margin:0.2rem 0 0 0; font-size:0.78rem; color:var(--text-muted);">
                A clean chronological history of automated member alerts and events
            </p>
        </div>
        <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
            <span class="badge" style="background:rgba(45,106,79,0.08); color:var(--primary, #2d6a4f); border:1px solid rgba(45,106,79,0.2); font-weight:700; font-size:0.75rem;">
                <?php echo count($notifications); ?> Total
            </span>
            <?php 
                $unread_cnt = count(array_filter($notifications, fn($n) => ($n['read_status'] ?? '') === 'Unread'));
                if ($unread_cnt > 0): 
            ?>
            <span class="badge" style="background:rgba(239,68,68,0.1); color:#ef4444; border:1px solid rgba(239,68,68,0.25); font-weight:700; font-size:0.75rem;">
                <?php echo $unread_cnt; ?> Unread
            </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="notif-list-body">
        <?php foreach ($notifications as $n):
            $is_unread = (isset($n['read_status']) && $n['read_status'] === 'Unread');
            $meta = $type_meta[$n['type']] ?? ['icon' => 'fas fa-bell', 'bg' => 'rgba(100,116,139,0.1)', 'color' => '#64748b', 'label' => ($n['type'] ?: 'System')];
        ?>
        <div class="notif-item-row <?php echo $is_unread ? 'is-unread' : 'is-read'; ?>">
            <!-- Left: Icon Column -->
            <div class="notif-item-icon" style="background:<?php echo $meta['bg']; ?>; color:<?php echo $meta['color']; ?>;">
                <i class="<?php echo $meta['icon']; ?>"></i>
            </div>

            <!-- Center: Content Hierarchy -->
            <div class="notif-item-content">
                <div class="notif-item-header">
                    <div class="notif-member-group">
                        <span class="notif-member-name"><?php echo htmlspecialchars($n['full_name'] ?? 'System Event'); ?></span>
                        <?php if (!empty($n['membership_id'])): ?>
                            <span class="notif-member-id"><?php echo htmlspecialchars($n['membership_id']); ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="notif-type-pill" style="background:<?php echo $meta['bg']; ?>; color:<?php echo $meta['color']; ?>;">
                        <i class="<?php echo $meta['icon']; ?>"></i> <?php echo htmlspecialchars($meta['label']); ?>
                    </span>
                </div>

                <div class="notif-item-msg">
                    <?php echo htmlspecialchars($n['title']); ?>
                </div>
            </div>

            <!-- Right: Meta & Action Column -->
            <div class="notif-item-meta">
                <div class="notif-status-box">
                    <?php if ($n['delivery_status'] === 'Failed'): ?>
                        <span class="notif-status-badge status-failed">
                            <i class="fas fa-circle-exclamation"></i> Failed
                        </span>
                    <?php else: ?>
                        <span class="notif-status-badge status-sent">
                            <i class="fas fa-check"></i> Sent
                        </span>
                    <?php endif; ?>
                    <span class="notif-time" title="<?php echo htmlspecialchars($n['sent_at']); ?>">
                        <i class="far fa-clock"></i> <?php echo date('M d, Y • h:i A', strtotime($n['sent_at'])); ?>
                    </span>
                </div>

                <?php if ($is_unread): ?>
                    <a href="?mark_read=<?php echo (int)$n['id']; ?>" class="btn btn-outline btn-sm notif-action-btn" title="Mark as Read">
                        <i class="fas fa-check"></i> <span>Mark Read</span>
                    </a>
                <?php else: ?>
                    <span class="notif-read-indicator" title="Already Read"><i class="fas fa-check-double"></i></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
