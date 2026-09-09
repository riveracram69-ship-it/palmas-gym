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
<?php
echo "</main></div></body></html>";

