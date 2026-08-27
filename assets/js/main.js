document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('mobileSidebarToggle');
    const sidebar = document.querySelector('.sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');

    if (toggleBtn && sidebar && backdrop) {
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.add('active');
            backdrop.classList.add('active');
        });

        backdrop.addEventListener('click', function() {
            sidebar.classList.remove('active');
            backdrop.classList.remove('active');
        });

        // Also close sidebar if user clicks a nav link inside mobile
        const navLinks = sidebar.querySelectorAll('.nav-list .nav-link');
        navLinks.forEach(link => {
            link.addEventListener('click', function() {
                sidebar.classList.remove('active');
                backdrop.classList.remove('active');
            });
        });
    }
});

// Global Confirmation Dialog Logic
let globalConfirmCallback = null;

function palmasConfirm(title, message, confirmBtnText, confirmBtnColor, callback) {
    const modal = document.getElementById('global-confirm-modal');
    if (!modal) return;
    
    document.getElementById('global-confirm-title').textContent = title;
    document.getElementById('global-confirm-message').innerHTML = message; // Allow HTML like <strong>
    
    const confirmBtn = document.getElementById('global-confirm-btn');
    confirmBtn.textContent = confirmBtnText;
    confirmBtn.style.background = confirmBtnColor;
    
    globalConfirmCallback = callback;
    
    // Remove old listeners
    const newBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newBtn, confirmBtn);
    
    newBtn.addEventListener('click', function() {
        closeGlobalConfirm();
        if (typeof globalConfirmCallback === 'function') {
            globalConfirmCallback();
        }
    });
    
    modal.style.display = 'flex';
}

function closeGlobalConfirm() {
    const modal = document.getElementById('global-confirm-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}

// Form Validation Logic
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('.needs-validation');
    Array.prototype.slice.call(forms).forEach(function(form) {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            } else {
                // If valid, show loading state on submit button
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.classList.add('is-loading');
                }
            }
            form.classList.add('was-validated');
        }, false);
    });
});
