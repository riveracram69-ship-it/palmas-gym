<?php
/**
 * UI Components — Centralized generation of reusable UI elements
 * Ensures design system consistency across Palma's Elite Gym Management System.
 */

/**
 * Render a standardized Empty State block.
 *
 * @param string $icon FontAwesome icon class (e.g., 'fas fa-folder-open')
 * @param string $title The main title text
 * @param string $message The secondary description text (optional)
 * @param bool $in_table If true, wraps the state in <tr><td colspan="100%">
 * @return void
 */
function render_empty_state($icon, $title, $message = '', $in_table = false) {
    $html = '<div class="empty-state">';
    $html .= '<i class="' . htmlspecialchars($icon) . '"></i>';
    $html .= '<h3>' . htmlspecialchars($title) . '</h3>';
    if (!empty($message)) {
        $html .= '<p>' . $message . '</p>';
    }
    $html .= '</div>';

    if ($in_table) {
        echo '<tr><td colspan="100%">' . $html . '</td></tr>';
    } else {
        echo $html;
    }
}

/**
 * Render a standardized Status Badge.
 *
 * @param string $status The status string (e.g., 'Active', 'Expired', 'Pending')
 * @return string The badge HTML
 */
function render_status_badge($status) {
    $s = trim(strtolower($status));
    $badge_class = 'badge-gray';
    $icon = 'fas fa-circle';

    if ($s === 'active' || $s === 'approved' || $s === 'paid' || $s === 'completed') {
        $badge_class = 'badge-success';
        $icon = 'fas fa-check-circle';
    } elseif ($s === 'expired' || $s === 'rejected' || $s === 'failed' || $s === 'cancelled') {
        $badge_class = 'badge-danger';
        $icon = 'fas fa-times-circle';
    } elseif ($s === 'pending' || $s === 'pending review') {
        $badge_class = 'badge-pending';
        $icon = 'fas fa-clock';
    } elseif ($s === 'expiring' || $s === 'expiring soon') {
        $badge_class = 'badge-warning';
        $icon = 'fas fa-triangle-exclamation';
    } elseif ($s === 'vip' || $s === 'premium') {
        $badge_class = 'badge-gold';
        $icon = 'fas fa-crown';
    }

    return '<span class="badge ' . $badge_class . '"><i class="' . $icon . '" style="font-size:0.65rem;"></i> ' . htmlspecialchars($status) . '</span>';
}
