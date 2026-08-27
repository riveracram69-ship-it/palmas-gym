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
}

// Restore saved tab on load
document.addEventListener('DOMContentLoaded', () => {
    const saved = localStorage.getItem('palmas_active_report_tab') || 'tab-daily';
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
    document.getElementById('export-format-' + format).checked = true;
    ['csv', 'xls', 'json'].forEach(f => {
        const card = document.getElementById('card-format-' + f);
        if (f === format) {
            card.style.borderColor = 'var(--accent)';
            card.style.background = 'rgba(45,106,79,0.1)';
        } else {
            card.style.borderColor = 'var(--border)';
            card.style.background = 'transparent';
        }
    });
}

// ── PDF GENERATOR ─────────────────────────────────────────────────────────────
// NOTE: filename with datestamp is set by the inline <script> in reports.php
// because it requires the PHP-rendered date value.
function generatePDFReport(filename) {
    const element = document.getElementById('analytics-master-container');
    element.classList.add('pdf-render-mode');

    const opt = {
        margin:      [0.4, 0.4, 0.4, 0.4],
        filename:    filename || 'Palmas_Gym_Analytics_Report.pdf',
        image:       { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true, logging: false },
        jsPDF:       { unit: 'in', format: 'a4', orientation: 'landscape' }
    };

    const btn = document.getElementById('btn-pdf-export');
    const oldHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Building PDF...';
    btn.disabled = true;

    html2pdf().set(opt).from(element).save().then(() => {
        element.classList.remove('pdf-render-mode');
        btn.innerHTML = oldHtml;
        btn.disabled = false;
    });
}
