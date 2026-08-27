<?php
$page_title = 'Membership Plans';
include 'includes/header.php';
include 'includes/sidebar.php';
require_admin();

$plans = [];
try {
    if (isset($pdo) && $pdo) {
        $plans = $pdo->query(
            "SELECT p.*, COUNT(s.id) AS subscriber_count 
             FROM membership_plans p 
             LEFT JOIN subscriptions s ON s.plan_id = p.id AND s.expiry_date >= CURDATE()
             GROUP BY p.id"
        )->fetchAll();
    }
} catch (Exception $e) {}
?>

<div class="topbar">
    <div class="page-title">
        <h1>Membership Plans</h1>
        <p>Manage pricing tiers and duration for your members.</p>
    </div>
    <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Create Plan</button>
</div>

<?php if (empty($plans)): ?>
<div class="card">
    <div class="empty-state">
        <i class="fas fa-tags"></i>
        <p>No membership plans found.</p>
        <button class="btn btn-primary btn-sm" onclick="openModal()"><i class="fas fa-plus"></i> Create Your First Plan</button>
    </div>
</div>
<?php else: ?>
<div class="stats-grid" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));">
    <?php foreach ($plans as $p): ?>
    <div class="card" style="display:flex; flex-direction:column; border-top: 4px solid var(--accent);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1rem;">
            <div class="stat-icon gold" style="margin-bottom:0;"><i class="fas fa-gem"></i></div>
            <div style="display:flex; gap:0.4rem;">
                <button class="btn btn-outline btn-icon btn-sm edit-btn" 
                        data-id="<?php echo $p['id']; ?>"
                        data-name="<?php echo htmlspecialchars($p['name']); ?>"
                        data-months="<?php echo $p['duration_months']; ?>"
                        data-price="<?php echo $p['price']; ?>"
                        data-benefits="<?php echo htmlspecialchars($p['benefits'] ?? ''); ?>"
                        aria-label="Edit Plan">
                    <i class="fas fa-pen"></i>
                </button>
                <button class="btn btn-outline btn-icon btn-sm del-btn" style="color:var(--danger);" data-id="<?php echo $p['id']; ?>" data-name="<?php echo htmlspecialchars($p['name']); ?>" aria-label="Delete Plan">
                    <i class="fas fa-trash-can"></i>
                </button>
            </div>
        </div>
        
        <h3 class="section-title" style="margin-bottom:0.25rem;"><?php echo htmlspecialchars($p['name']); ?></h3>
        <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:1.5rem;">
            <i class="far fa-clock"></i> Duration: <?php echo $p['duration_months']; ?> Month<?php echo $p['duration_months'] > 1 ? 's' : ''; ?>
        </p>

        <div style="margin-bottom:1.5rem;">
            <span style="font-size:1.75rem; font-weight:700; color:var(--text-main);">₱<?php echo number_format($p['price'], 2); ?></span>
            <span style="font-size:0.85rem; color:var(--text-muted);">/ total</span>
        </div>

        <div style="border-top:1px solid var(--border); padding-top:1.25rem; margin-top:auto;">
            <div style="display:flex; align-items:center; justify-content:space-between; font-size:0.85rem;">
                <span style="color:var(--text-soft);"><i class="fas fa-users" style="color:var(--accent);"></i> Subscribers</span>
                <span style="font-weight:700;"><?php echo $p['subscriber_count']; ?></span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Plan Modal -->
<div class="modal-overlay" id="plan-modal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="modal-title">Create Plan</h3>
            <button class="modal-close" onclick="closeModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <form id="plan-form">
            <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">
            <input type="hidden" id="plan-id" name="id">
            <div class="form-group">
                <label>Plan Name *</label>
                <input type="text" id="plan-name" name="name" class="form-control" placeholder="e.g. Monthly Starter" required>
            </div>
            <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
                <div class="form-group">
                    <label>Duration (Months) *</label>
                    <input type="number" id="plan-months" name="duration_months" class="form-control" min="1" required>
                </div>
                <div class="form-group">
                    <label>Price (₱) *</label>
                    <input type="number" id="plan-price" name="price" class="form-control" step="0.01" min="0" required>
                </div>
            </div>
            <div class="form-group">
                <label>Benefits (Optional)</label>
                <textarea id="plan-benefits" name="benefits" class="form-control" rows="3" placeholder="List benefits separated by commas..."></textarea>
            </div>
            <div style="display:flex; gap:1rem; justify-content:flex-end; margin-top:1rem;">
                <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="save-btn">Save Plan</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal-overlay" id="del-plan-modal">
    <div class="modal" style="max-width:400px;">
        <div class="modal-header">
            <h3><i class="fas fa-triangle-exclamation" style="color:var(--danger);"></i> Delete Plan</h3>
            <button class="modal-close" onclick="closeDelModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <p style="color:var(--text-soft); margin-bottom:2rem; line-height:1.6;">Are you sure you want to delete <strong id="del-plan-name" style="color:var(--text-main);"></strong>? This action cannot be undone.</p>
        <div style="display:flex; gap:1rem; justify-content:flex-end;">
            <button class="btn btn-outline" onclick="closeDelModal()">Cancel</button>
            <button class="btn btn-primary" id="confirm-del-plan" style="background:var(--danger);">Delete Plan</button>
        </div>
    </div>
</div>

<script>
function openModal(data = null) {
    const modal = document.getElementById('plan-modal');
    const form = document.getElementById('plan-form');
    const title = document.getElementById('modal-title');
    
    form.reset();
    document.getElementById('plan-id').value = '';
    
    if (data) {
        title.innerHTML = '<i class="fas fa-pen-to-square" style="color:var(--accent);"></i> Edit Plan';
        document.getElementById('plan-id').value = data.id;
        document.getElementById('plan-name').value = data.name;
        document.getElementById('plan-months').value = data.months;
        document.getElementById('plan-price').value = data.price;
        document.getElementById('plan-benefits').value = data.benefits;
    } else {
        title.innerHTML = '<i class="fas fa-tag" style="color:var(--accent);"></i> Create New Plan';
    }
    
    modal.classList.add('active');
}

function closeModal() { document.getElementById('plan-modal').classList.remove('active'); }

document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => openModal({...btn.dataset}));
});

const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

document.getElementById('plan-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const saveBtn = document.getElementById('save-btn');
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    saveBtn.disabled = true;

    const fd = new FormData(this);
    fetch('modules/plans/save_plan.php', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken },
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Error saving plan');
            saveBtn.innerHTML = 'Save Plan';
            saveBtn.disabled = false;
        }
    })
    .catch(() => { alert('Network error.'); saveBtn.innerHTML = 'Save Plan'; saveBtn.disabled = false; });
});

// Delete Plan logic
let pendingDelPlanId = null;
function closeDelModal() { document.getElementById('del-plan-modal').classList.remove('active'); pendingDelPlanId = null; }

document.querySelectorAll('.del-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        pendingDelPlanId = this.dataset.id;
        document.getElementById('del-plan-name').textContent = this.dataset.name;
        document.getElementById('del-plan-modal').classList.add('active');
    });
});

document.getElementById('confirm-del-plan').addEventListener('click', function() {
    if (!pendingDelPlanId) return;
    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting…';
    this.disabled = true;

    fetch('modules/plans/delete_plan.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrfToken },
        body: 'id=' + pendingDelPlanId
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) location.reload();
        else {
            alert(data.message || 'Error deleting plan.');
            this.innerHTML = 'Delete Plan'; this.disabled = false;
        }
    })
    .catch(() => { alert('Network error.'); this.innerHTML = 'Delete Plan'; this.disabled = false; });
});

document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => { if (e.target === overlay) { closeModal(); closeDelModal(); } });
});
</script>

<?php include 'includes/footer.php'; ?>
