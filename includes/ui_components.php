<?php
/**
 * UI Components
 * Centralized generation of reusable HTML elements to ensure UI consistency.
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
    $html = '<div class="empty-state" style="text-align:center; padding:4rem 1rem; color:var(--text-muted);">';
    $html .= '<i class="' . htmlspecialchars($icon) . '" style="font-size:3rem; margin-bottom:1rem; opacity:0.15; display:block; color:var(--text-main);"></i>';
    $html .= '<h3 style="font-size:1.1rem; font-weight:600; color:var(--text-main); margin-bottom:0.25rem;">' . htmlspecialchars($title) . '</h3>';
    if (!empty($message)) {
        $html .= '<p style="font-size:0.9rem;">' . htmlspecialchars($message) . '</p>';
    }
    $html .= '</div>';

    if ($in_table) {
        echo '<tr><td colspan="100%">' . $html . '</td></tr>';
    } else {
        echo $html;
    }
}
