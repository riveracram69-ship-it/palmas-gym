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

    if ($s === 'active' || $s === 'approved' || $s === 'paid' || $s === 'completed' || $s === 'present') {
        $badge_class = 'badge-success';
        $icon = 'fas fa-check-circle';
    } elseif ($s === 'expired' || $s === 'rejected' || $s === 'failed' || $s === 'cancelled' || $s === 'absent' || $s === 'suspended') {
        $badge_class = 'badge-danger';
        $icon = ($s === 'suspended') ? 'fas fa-ban' : 'fas fa-times-circle';
    } elseif ($s === 'pending' || $s === 'pending review' || $s === 'unpaid') {
        $badge_class = 'badge-pending';
        $icon = 'fas fa-clock';
    } elseif ($s === 'expiring' || $s === 'expiring soon') {
        $badge_class = 'badge-warning';
        $icon = 'fas fa-triangle-exclamation';
    } elseif ($s === 'vip' || $s === 'premium') {
        $badge_class = 'badge-gold';
        $icon = 'fas fa-crown';
    } elseif ($s === 'inactive') {
        $badge_class = 'badge-gray';
        $icon = 'fas fa-circle-minus';
    }

    return '<span class="badge ' . $badge_class . '"><i class="' . $icon . '" style="font-size:0.65rem;"></i> ' . htmlspecialchars($status) . '</span>';
}

/**
 * Render the interactive Account Creation Choice Modal (Member vs Staff/Admin).
 */
function render_create_account_modal() {
    ?>
    <div class="modal-backdrop" id="modal-account-choice" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:99999; align-items:center; justify-content:center; backdrop-filter:blur(5px); padding:1rem;">
        <div class="card" style="max-width:560px; width:100%; border-radius:24px; box-shadow:0 30px 60px -12px rgba(0,0,0,0.5); padding:2rem 2rem 2.25rem; border:1px solid rgba(82,183,136,0.25); background:#ffffff; position:relative; animation:fadeInUp 0.3s ease;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem;">
                <div>
                    <span style="display:inline-block; font-size:0.75rem; font-weight:800; text-transform:uppercase; letter-spacing:0.8px; color:#2D6A4F; background:#ECFDF5; padding:3px 10px; border-radius:20px; margin-bottom:6px;">PALMA'S ELITE GYM</span>
                    <h2 style="margin:0; font-size:1.45rem; font-family:'Outfit',sans-serif; font-weight:800; color:#1B4332;">Select Account Type to Create</h2>
                </div>
                <button type="button" onclick="closeAccountChoiceModal()" style="background:none; border:none; color:#64748b; font-size:1.3rem; cursor:pointer; padding:4px;" aria-label="Close modal"><i class="fas fa-times"></i></button>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-bottom:1.5rem;">
                <!-- Option 1: Gym Member -->
                <a href="add-member.php" style="text-decoration:none; display:flex; flex-direction:column; padding:1.5rem 1.25rem; border-radius:18px; border:2px solid #E2EFE7; background:#F7FCF9; transition:all 0.25s ease; text-align:center; color:#1B4332;" class="account-choice-card" onmouseover="this.style.borderColor='#3E8241'; this.style.transform='translateY(-3px)'; this.style.boxShadow='0 10px 25px rgba(45,106,79,0.15)';" onmouseout="this.style.borderColor='#E2EFE7'; this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                    <div style="width:56px; height:56px; border-radius:16px; background:linear-gradient(135deg, #3E8241 0%, #1B4332 100%); color:#ffffff; display:flex; align-items:center; justify-content:center; font-size:1.6rem; margin:0 auto 1rem; box-shadow:0 6px 16px rgba(45,106,79,0.3);">
                        <i class="fas fa-dumbbell"></i>
                    </div>
                    <h3 style="margin:0 0 6px; font-size:1.15rem; font-family:'Outfit',sans-serif; font-weight:800; color:#1B4332;">Gym Member</h3>
                    <p style="margin:0 0 1rem; font-size:0.82rem; color:#475569; line-height:1.45; flex-grow:1;">Register a gym client/athlete, assign membership plan, generate digital QR pass, and record payment.</p>
                    <span class="btn btn-primary btn-sm" style="width:100%; border-radius:10px; font-weight:700; pointer-events:none;">
                        Register Member &rarr;
                    </span>
                </a>

                <!-- Option 2: Staff / Admin -->
                <a href="users.php?create=1" style="text-decoration:none; display:flex; flex-direction:column; padding:1.5rem 1.25rem; border-radius:18px; border:2px solid #E2E8F0; background:#F8FAFC; transition:all 0.25s ease; text-align:center; color:#0F172A;" class="account-choice-card" onmouseover="this.style.borderColor='#2563EB'; this.style.transform='translateY(-3px)'; this.style.boxShadow='0 10px 25px rgba(37,99,235,0.15)';" onmouseout="this.style.borderColor='#E2E8F0'; this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                    <div style="width:56px; height:56px; border-radius:16px; background:linear-gradient(135deg, #2563EB 0%, #1E40AF 100%); color:#ffffff; display:flex; align-items:center; justify-content:center; font-size:1.6rem; margin:0 auto 1rem; box-shadow:0 6px 16px rgba(37,99,235,0.3);">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <h3 style="margin:0 0 6px; font-size:1.15rem; font-family:'Outfit',sans-serif; font-weight:800; color:#0F172A;">Staff / Admin</h3>
                    <p style="margin:0 0 1rem; font-size:0.82rem; color:#475569; line-height:1.45; flex-grow:1;">Create a receptionist or system admin account with management dashboard access and email credentials.</p>
                    <span class="btn btn-outline btn-sm" style="width:100%; border-radius:10px; font-weight:700; color:#2563EB; border-color:#2563EB; pointer-events:none;">
                        Create Staff User &rarr;
                    </span>
                </a>
            </div>

            <div style="text-align:center;">
                <button type="button" onclick="closeAccountChoiceModal()" style="background:none; border:none; color:#64748B; font-size:0.88rem; font-weight:600; cursor:pointer;">
                    Close
                </button>
            </div>
        </div>
    </div>
    <script>
    function openAccountChoiceModal() {
        const m = document.getElementById('modal-account-choice');
        if (m) m.style.display = 'flex';
    }
    function closeAccountChoiceModal() {
        const m = document.getElementById('modal-account-choice');
        if (m) m.style.display = 'none';
    }
    window.addEventListener('click', function(e) {
        const m = document.getElementById('modal-account-choice');
        if (m && e.target === m) {
            m.style.display = 'none';
        }
    });
    </script>
    <?php
}

