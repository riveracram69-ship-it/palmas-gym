// Palma's Elite Gym Management System — Global JavaScript Interactivity

document.addEventListener('DOMContentLoaded', function() {
    // ── Mobile Sidebar Drawer Toggle ──────────────────────────────────────────
    const toggleBtn = document.getElementById('mobileSidebarToggle');
    const sidebar = document.querySelector('.sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');

    if (toggleBtn && sidebar && backdrop) {
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.add('active');
            backdrop.classList.add('active');
            document.body.style.overflow = 'hidden';
        });

        backdrop.addEventListener('click', function() {
            sidebar.classList.remove('active');
            backdrop.classList.remove('active');
            document.body.style.overflow = '';
        });

        // Close sidebar when clicking any navigation link on mobile
        const navLinks = sidebar.querySelectorAll('.nav-list .nav-link');
        navLinks.forEach(link => {
            link.addEventListener('click', function() {
                sidebar.classList.remove('active');
                backdrop.classList.remove('active');
                document.body.style.overflow = '';
            });
        });
    }

    // ── Global Keyboard Shortcuts (Escape to dismiss modals & drawers) ────────
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeGlobalConfirm();
            if (sidebar && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                if (backdrop) backdrop.classList.remove('active');
                document.body.style.overflow = '';
            }
            // Close any open modals
            const openModals = document.querySelectorAll('.modal-overlay.active, .modal-overlay[style*="display: flex"]');
            openModals.forEach(m => m.style.display = 'none');
        }
    });

    // ── Click Outside to Close Modals ─────────────────────────────────────────
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                overlay.style.display = 'none';
                overlay.classList.remove('active');
            }
        });
    });

    // ── Form Validation & Loading State ───────────────────────────────────────
    const forms = document.querySelectorAll('.needs-validation');
    Array.prototype.slice.call(forms).forEach(function(form) {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            } else {
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.classList.add('is-loading');
                }
            }
            form.classList.add('was-validated');
        }, false);
    });
});

// ── Global Confirmation Dialog Logic ─────────────────────────────────────────
let globalConfirmCallback = null;

function palmasConfirm(title, message, confirmBtnText, confirmBtnColor, callback) {
    const modal = document.getElementById('global-confirm-modal');
    if (!modal) return;
    
    const titleEl = document.getElementById('global-confirm-title');
    const msgEl = document.getElementById('global-confirm-message');
    const confirmBtn = document.getElementById('global-confirm-btn');

    if (titleEl) titleEl.textContent = title;
    if (msgEl) msgEl.innerHTML = message;
    
    if (confirmBtn) {
        confirmBtn.textContent = confirmBtnText || 'Confirm';
        if (confirmBtnColor) {
            confirmBtn.style.background = confirmBtnColor;
        }
        
        globalConfirmCallback = callback;
        
        // Clone button to remove previous event listeners cleanly
        const newBtn = confirmBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(newBtn, confirmBtn);
        
        newBtn.addEventListener('click', function() {
            closeGlobalConfirm();
            if (typeof globalConfirmCallback === 'function') {
                globalConfirmCallback();
            }
        });
    }
    
    modal.style.display = 'flex';
}

function closeGlobalConfirm() {
    const modal = document.getElementById('global-confirm-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}
