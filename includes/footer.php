<?php
// Palmas Elite Gym — Shared Page Footer
?>
<!-- Global Confirmation Modal -->
<div class="modal-overlay" id="global-confirm-modal" style="display:none; z-index: 9999;">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <h3 id="global-confirm-title">Confirm Action</h3>
            <button class="modal-close" onclick="closeGlobalConfirm()"><i class="fas fa-xmark"></i></button>
        </div>
        <p id="global-confirm-message" style="color:var(--text-soft);margin-bottom:2rem;line-height:1.6;"></p>
        <div style="display:flex;gap:1rem;justify-content:flex-end;">
            <button class="btn btn-outline" onclick="closeGlobalConfirm()">Cancel</button>
            <button class="btn btn-primary" id="global-confirm-btn">Confirm</button>
        </div>
    </div>
</div>
<?php
echo "</main></div></body></html>";

