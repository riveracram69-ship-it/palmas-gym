// Palma's Elite Gym Management System — Global JavaScript Interactivity

document.addEventListener('DOMContentLoaded', function() {
    // ── Mobile Sidebar Drawer Toggle ──────────────────────────────────────────
    const toggleBtn = document.getElementById('mobileSidebarToggle');
    const closeBtn = document.getElementById('sidebarCloseBtn');
    const sidebar = document.querySelector('.sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');

    function openSidebar() {
        if (sidebar && backdrop) {
            sidebar.classList.add('active');
            backdrop.classList.add('active');
            document.body.classList.add('sidebar-open');
        }
    }

    function closeSidebar() {
        if (sidebar && backdrop) {
            sidebar.classList.remove('active');
            backdrop.classList.remove('active');
            document.body.classList.remove('sidebar-open');
        }
    }

    if (toggleBtn) toggleBtn.addEventListener('click', openSidebar);
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
    if (backdrop) backdrop.addEventListener('click', closeSidebar);

    // Close sidebar when clicking any navigation link on mobile
    if (sidebar) {
        const navLinks = sidebar.querySelectorAll('.nav-list .nav-link, .sidebar-footer a');
        navLinks.forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 900) {
                    closeSidebar();
                }
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
    const forms = document.querySelectorAll('form');
    Array.prototype.slice.call(forms).forEach(function(form) {
        form.addEventListener('submit', function(event) {
            if (form.classList.contains('needs-validation')) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                    form.classList.add('was-validated');
                    return;
                }
            }
            const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
            if (submitBtn && !submitBtn.classList.contains('no-spin') && !submitBtn.classList.contains('no-loading')) {
                submitBtn.classList.add('is-loading');
            }
            form.classList.add('was-validated');
        }, false);
    });

    // Initialize Real-time Pending Approvals Poller for authenticated staff/admin
    if (document.querySelector('.sidebar')) {
        startPendingCashPoller();
    }
});

// ── Global Confirmation Dialog Logic ─────────────────────────────────────────
let globalConfirmCallback = null;

function palmasConfirm(title, message, confirmBtnText, confirmBtnColor, callback) {
    const modal = document.getElementById('global-confirm-modal');
    if (!modal) return;
    
    // Support overloaded arguments: palmasConfirm(title, message, callback)
    if (typeof confirmBtnText === 'function') {
        callback = confirmBtnText;
        confirmBtnText = 'Confirm';
        confirmBtnColor = null;
    } else if (typeof confirmBtnColor === 'function') {
        callback = confirmBtnColor;
        confirmBtnColor = null;
    }

    const titleTextEl = document.getElementById('global-confirm-title-text');
    const titleEl = titleTextEl || document.getElementById('global-confirm-title');
    const iconEl = document.getElementById('global-confirm-icon');
    const msgEl = document.getElementById('global-confirm-message');
    const confirmBtn = document.getElementById('global-confirm-btn');

    if (titleEl) titleEl.textContent = title;
    if (msgEl) msgEl.innerHTML = message;

    // Detect destructive or warning actions to adapt icon styling
    const isDanger = (confirmBtnColor && (confirmBtnColor.includes('danger') || confirmBtnColor.includes('ef4444') || confirmBtnColor.includes('dc2626') || confirmBtnColor.includes('red'))) ||
                     (confirmBtnText && (confirmBtnText.toLowerCase().includes('delete') || confirmBtnText.toLowerCase().includes('deactivate') || confirmBtnText.toLowerCase().includes('remove')));

    if (iconEl) {
        if (isDanger) {
            iconEl.className = 'fas fa-triangle-exclamation';
            iconEl.style.color = '#ef4444';
        } else {
            iconEl.className = 'fas fa-circle-question';
            iconEl.style.color = '#2d6a4f';
        }
    }
    
    if (confirmBtn) {
        confirmBtn.textContent = confirmBtnText || 'Confirm';
        if (confirmBtnColor) {
            confirmBtn.style.background = confirmBtnColor;
        } else {
            confirmBtn.style.background = '';
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

// Close confirmation modal on Escape key or backdrop click
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeGlobalConfirm();
    }
});

document.addEventListener('click', function(e) {
    const modal = document.getElementById('global-confirm-modal');
    if (modal && e.target === modal) {
        closeGlobalConfirm();
    }
});

// ── Global Toast Notification Helper ──────────────────────────────────────────
function palmasToast(message, type = 'success') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.style.cssText = 'position:fixed;top:1.5rem;right:1.5rem;z-index:99999;display:flex;flex-direction:column;gap:0.5rem;max-width:380px;pointer-events:none;';
        document.body.appendChild(container);
    }
    
    const toast = document.createElement('div');
    toast.className = `alert alert-${type}`;
    toast.style.cssText = 'margin:0;pointer-events:all;box-shadow:var(--shadow-lg);animation:fadeInUp 0.25s ease both;';
    
    const iconMap = {
        success: 'fa-circle-check',
        error: 'fa-circle-exclamation',
        danger: 'fa-circle-exclamation',
        warning: 'fa-triangle-exclamation',
        info: 'fa-circle-info'
    };
    
    const icon = iconMap[type] || 'fa-bell';
    toast.innerHTML = `<i class="fas ${icon}" style="margin-right:8px;"></i> <span>${message}</span>`;
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-10px)';
        toast.style.transition = 'all 0.25s ease';
        setTimeout(() => toast.remove(), 250);
    }, 5000);
}

// ── HTML Sanitizer Helper ───────────────────────────────────────────────────
function escapePalmasHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// ── Web Audio Feedback for Pending Cash Requests ────────────────────────────
function unlockPalmasAudio() {
    try {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (AudioContextClass && !window._palmasAudioCtx) {
            window._palmasAudioCtx = new AudioContextClass();
        }
        if (window._palmasAudioCtx && window._palmasAudioCtx.state === 'suspended') {
            window._palmasAudioCtx.resume().catch(() => {});
        }
    } catch (e) {}
}
['pointerdown', 'keydown', 'touchstart'].forEach(evt => {
    window.addEventListener(evt, unlockPalmasAudio, { once: true, passive: true });
});

function playPendingCashChime() {
    try {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return;
        if (!window._palmasAudioCtx) {
            window._palmasAudioCtx = new AudioContextClass();
        }
        const ctx = window._palmasAudioCtx;
        if (ctx.state === 'suspended') {
            ctx.resume().catch(() => {});
        }
        if (ctx.state !== 'running') return;

        const now = ctx.currentTime;
        // Soft major triad chime (C5 523.25Hz -> E5 659.25Hz -> G5 783.99Hz)
        const notes = [523.25, 659.25, 783.99];

        notes.forEach((freq, idx) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            const startTime = now + (idx * 0.07);

            osc.type = 'sine';
            osc.frequency.setValueAtTime(freq, startTime);

            gain.gain.setValueAtTime(0.001, startTime);
            gain.gain.linearRampToValueAtTime(0.14, startTime + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, startTime + 0.22);

            osc.connect(gain);
            gain.connect(ctx.destination);

            osc.start(startTime);
            osc.stop(startTime + 0.22);
        });
    } catch (e) {
        // Non-blocking: Audio failures must NEVER interrupt UI or workflows
    }
}

// ── Sidebar Pending Approvals Badge Updater ─────────────────────────────────
function updateSidebarPendingBadge(count) {
    const badge = document.getElementById('sidebar-pending-badge');
    if (!badge) return;
    const num = parseInt(count, 10) || 0;
    if (num > 0) {
        badge.textContent = num;
        badge.style.display = 'inline-block';
    } else {
        badge.style.display = 'none';
    }
}

// ── PENDING CASH APPROVALS REAL-TIME NOTIFIER ───────────────────────────────
let pendingPollTimer = null;
let isPollingPending = false;

async function checkPendingCashApprovals() {
    if (isPollingPending) return;
    isPollingPending = true;

    try {
        const res = await fetch('api/admin_dashboard_ajax.php?ajax=check_pending_approvals', {
            headers: { 'Accept': 'application/json' }
        });
        if (!res.ok) {
            isPollingPending = false;
            return;
        }
        const data = await res.json();
        if (!data || !data.success) {
            isPollingPending = false;
            return;
        }

        const totalPending = parseInt(data.total_pending || 0, 10);
        updateSidebarPendingBadge(totalPending);

        const currentRegId = parseInt(data.latest_reg_id || 0, 10);
        const currentRenewId = parseInt(data.latest_renew_id || 0, 10);

        const isInitialized = sessionStorage.getItem('palmas_pending_initialized');

        if (!isInitialized) {
            // First time establishing baseline in this session — silent initialization
            sessionStorage.setItem('palmas_last_cash_reg_id', currentRegId);
            sessionStorage.setItem('palmas_last_cash_renew_id', currentRenewId);
            sessionStorage.setItem('palmas_pending_initialized', '1');
        } else {
            const lastKnownRegId = parseInt(sessionStorage.getItem('palmas_last_cash_reg_id') || '0', 10);
            const lastKnownRenewId = parseInt(sessionStorage.getItem('palmas_last_cash_renew_id') || '0', 10);

            const isNewReg = (currentRegId > 0 && currentRegId > lastKnownRegId);
            const isNewRenew = (currentRenewId > 0 && currentRenewId > lastKnownRenewId);

            if (isNewReg || isNewRenew) {
                // Update stored baseline
                sessionStorage.setItem('palmas_last_cash_reg_id', Math.max(currentRegId, lastKnownRegId));
                sessionStorage.setItem('palmas_last_cash_renew_id', Math.max(currentRenewId, lastKnownRenewId));

                // Play ONE notification chime
                playPendingCashChime();

                // Trigger ONE contextual toast
                const req = data.latest_request || {};
                const name = escapePalmasHtml(req.member_name || 'A member');
                const plan = escapePalmasHtml(req.plan_name || 'Gym Plan');
                const price = Number(req.plan_price || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                if (isNewReg && isNewRenew) {
                    palmasToast(`💵 <strong>New Cash Payment Requests:</strong> ${name} and other cash requests are waiting for review. <a href="pending-approvals.php" style="color:#fff;text-decoration:underline;margin-left:6px;font-weight:700;">View Requests →</a>`, 'info');
                } else if (isNewReg) {
                    palmasToast(`💵 <strong>New Cash Registration:</strong> ${name} submitted ${plan} (₱${price}). <a href="pending-approvals.php?tab=registrations" style="color:#fff;text-decoration:underline;margin-left:6px;font-weight:700;">View Request →</a>`, 'info');
                } else if (isNewRenew) {
                    palmasToast(`💵 <strong>New Cash Renewal:</strong> ${name} requested ${plan} (₱${price}). <a href="pending-approvals.php?tab=renewals" style="color:#fff;text-decoration:underline;margin-left:6px;font-weight:700;">View Request →</a>`, 'info');
                }
            }
        }
    } catch (e) {
        // Network or parse error: fail silently without blocking user
    } finally {
        isPollingPending = false;
    }
}

function startPendingCashPoller() {
    if (pendingPollTimer) return;
    // Initial check
    checkPendingCashApprovals();

    // 15-second interval
    pendingPollTimer = setInterval(() => {
        if (document.visibilityState === 'visible') {
            checkPendingCashApprovals();
        }
    }, 15000);

    // Immediate check upon returning to tab
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            checkPendingCashApprovals();
        }
    });
}
