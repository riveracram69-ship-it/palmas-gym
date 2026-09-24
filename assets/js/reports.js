/**
 * Palmas Elite Gym — Reports Page JavaScript
 * Extracted from reports.php — Priority 14 Code Quality
 *
 * Contains: Tab switcher, preset filter handler, export modal, PDF generator.
 * Chart initializations remain in reports.php because they embed PHP data.
 */

// ── CHART THEME TOKENS ────────────────────────────────────────────────────────
// These are re-exported as globals so the inline chart code in reports.php
// can reference them without duplication.
const themeGreen     = '#52b788';
const themeGreenDark = '#2d6a4f';
const themeBlue      = '#38bdf8';
const themeYellow    = '#eab308';
const themePurple    = '#c084fc';
const themeRed       = '#ef4444';
const themeFont      = { family: "'Inter', sans-serif", size: 11 };
const gridColor      = 'rgba(255, 255, 255, 0.05)';

// ── TAB SWITCHER ──────────────────────────────────────────────────────────────
function switchReportTab(tabId) {
    document.querySelectorAll('.report-tab-pane').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.nav-tab-btn').forEach(btn => btn.classList.remove('active'));

    const targetPane = document.getElementById(tabId);
    if (targetPane) targetPane.classList.add('active');

    const activeBtn = document.querySelector(`.nav-tab-btn[data-tab="${tabId}"]`);
    if (activeBtn) activeBtn.classList.add('active');

    localStorage.setItem('palmas_active_report_tab', tabId);

    // Trigger chart recalculation & rendering on visible canvases
    setTimeout(() => {
        window.dispatchEvent(new Event('resize'));
    }, 50);
}

// Restore saved tab on load
document.addEventListener('DOMContentLoaded', () => {
    let saved = localStorage.getItem('palmas_active_report_tab') || 'tab-daily';
    const validTabs = ['tab-daily', 'tab-weekly', 'tab-financials'];
    if (!validTabs.includes(saved)) {
        saved = 'tab-daily';
    }
    switchReportTab(saved);
});

// ── PRESET FILTER HANDLER ─────────────────────────────────────────────────────
function applyPreset(preset) {
    document.getElementById('master-preset-input').value = preset;
    const customContainer = document.getElementById('custom-date-container');
    if (preset === 'custom') {
        customContainer.style.display = 'flex';
    } else {
        document.getElementById('master-filter-form').submit();
    }
}

// ── SMART EXPORT MODAL ────────────────────────────────────────────────────────
function openExportModal(type) {
    document.getElementById('export-type-select').value = type;
    document.getElementById('advanced-export-modal').style.display = 'flex';
}

function closeExportModal() {
    document.getElementById('advanced-export-modal').style.display = 'none';
}

function setExportModalPreset(preset) {
    document.getElementById('export-modal-preset-input').value = preset;
    document.querySelectorAll('.export-preset-btn').forEach(btn => {
        if (btn.getAttribute('data-preset') === preset) {
            btn.classList.add('active');
            btn.style.background = 'var(--accent)';
            btn.style.color = '#fff';
        } else {
            btn.classList.remove('active');
            btn.style.background = 'transparent';
            btn.style.color = 'var(--text-muted)';
        }
    });
}

function setExportFormat(format) {
    const radio = document.getElementById('export-format-' + format);
    if (radio) radio.checked = true;
    ['csv', 'xls', 'pdf', 'json'].forEach(f => {
        const card = document.getElementById('card-format-' + f);
        if (!card) return;
        if (f === format) {
            card.style.borderColor = 'var(--accent)';
            card.style.background = 'rgba(45,106,79,0.15)';
        } else {
            card.style.borderColor = 'var(--border)';
            card.style.background = 'transparent';
        }
    });
}

// ── EXECUTIVE PDF GENERATOR ───────────────────────────────────────────────────
// Launches the dedicated high-resolution Executive PDF report view in a new window
// with auto-print triggered for instant Save-As-PDF or printing.
function generatePDFReport(filename) {
    const activeTab = localStorage.getItem('palmas_active_report_tab') || 'tab-daily';
    let exportType = 'daily_revenue';
    if (activeTab === 'tab-financials') exportType = 'financial_summary';
    else if (activeTab === 'tab-weekly') exportType = 'weekly_revenue';

    const presetInput = document.getElementById('master-preset-input');
    const preset = presetInput ? presetInput.value : 'month';
    const startInput = document.querySelector('input[name="start_date"]');
    const endInput = document.querySelector('input[name="end_date"]');

    let url = `reports.php?export=${encodeURIComponent(exportType)}&format=pdf&date_preset=${encodeURIComponent(preset)}&auto_print=1`;
    if (preset === 'custom' && startInput && endInput && startInput.value && endInput.value) {
        url += `&start_date=${encodeURIComponent(startInput.value)}&end_date=${encodeURIComponent(endInput.value)}`;
    }

    const btn = document.getElementById('btn-pdf-export');
    if (btn) {
        const oldHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating PDF...';
        btn.disabled = true;
        setTimeout(() => {
            btn.innerHTML = oldHtml;
            btn.disabled = false;
        }, 1200);
    }

    window.open(url, '_blank');
}

