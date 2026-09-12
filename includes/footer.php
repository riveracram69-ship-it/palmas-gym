<?php
// Palmas Elite Gym — Shared Page Footer
?>
<!-- Global Confirmation Modal -->
<div class="modal-overlay" id="global-confirm-modal" style="display:none; z-index: 9999;">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <h3 id="global-confirm-title" style="display:flex; align-items:center; gap:0.5rem;">
                <i class="fas fa-triangle-exclamation" style="color:var(--warning); font-size:1.1rem;"></i>
                <span>Confirm Action</span>
            </h3>
            <button class="modal-close" onclick="closeGlobalConfirm()" aria-label="Close Dialog"><i class="fas fa-xmark"></i></button>
        </div>
        <p id="global-confirm-message" style="color:var(--text-soft); margin-bottom:1.75rem; line-height:1.6; font-size:0.92rem;"></p>
        <div style="display:flex; gap:0.75rem; justify-content:flex-end;">
            <button class="btn btn-outline" onclick="closeGlobalConfirm()">Cancel</button>
            <button class="btn btn-primary" id="global-confirm-btn">Confirm</button>
        </div>
    </div>
</div>

<!-- Site Info Footer -->
<footer style="margin-top:auto; padding:1.5rem 0 0.5rem; border-top:1px solid var(--border-subtle, #ebf0ec); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:0.75rem; font-size:0.8rem; color:var(--text-muted);">
    <div>
        <strong><?php echo htmlspecialchars($app_settings['gym_name'] ?? "Palma's Elite Gym"); ?></strong> &bull; <?php echo htmlspecialchars($app_settings['gym_address'] ?? '123 Fitness Ave, Metro Manila, Philippines'); ?> &bull; <?php echo htmlspecialchars($app_settings['gym_phone'] ?? '+63 917 000 0000'); ?>
    </div>
    <div style="display:flex; gap:1rem;">
        <a href="privacy.php" style="color:var(--text-soft); text-decoration:none;" target="_blank">Privacy Policy</a>
        <a href="terms.php" style="color:var(--text-soft); text-decoration:none;" target="_blank">Terms &amp; Conditions</a>
    </div>
</footer>

<!-- Essential Cookie & Privacy Notice Banner -->
<div id="palmas-cookie-banner" style="display:none; position:fixed; bottom:1.25rem; right:1.25rem; max-width:420px; background:#1b4332; color:#ffffff; border-radius:14px; padding:1.1rem 1.35rem; box-shadow:0 12px 35px rgba(0,0,0,0.25); z-index:9998; border:1px solid rgba(82,183,136,0.25); font-size:0.84rem; line-height:1.5;">
    <div style="display:flex; align-items:flex-start; gap:0.75rem; margin-bottom:0.75rem;">
        <i class="fas fa-shield-halved" style="color:#52b788; font-size:1.25rem; margin-top:2px;"></i>
        <div>
            <strong style="display:block; font-size:0.9rem; margin-bottom:2px; color:#ffffff;">Privacy &amp; Essential Cookies</strong>
            We use strictly necessary session cookies to secure your session and process requests under Philippine RA 10173.
            Read our <a href="privacy.php" target="_blank" style="color:#8fcfbc; text-decoration:underline;">Privacy Policy</a>.
        </div>
    </div>
    <div style="display:flex; justify-content:flex-end;">
        <button type="button" onclick="acceptPalmasCookieNotice()" style="background:#52b788; color:#0d1610; font-weight:700; border:none; padding:0.4rem 1.1rem; border-radius:9999px; font-size:0.8rem; cursor:pointer; transition:background 0.2s;">
            Acknowledge &amp; Close
        </button>
    </div>
</div>
<script>
function acceptPalmasCookieNotice() {
    localStorage.setItem('palmas_cookie_ack', '1');
    const b = document.getElementById('palmas-cookie-banner');
    if (b) b.style.display = 'none';
}
document.addEventListener('DOMContentLoaded', function() {
    if (!localStorage.getItem('palmas_cookie_ack')) {
        const b = document.getElementById('palmas-cookie-banner');
        if (b) b.style.display = 'block';
    }
});
</script>
<?php
echo "</main></div></body></html>";
?>
