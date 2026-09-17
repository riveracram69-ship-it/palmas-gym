<?php
$page_title = 'Membership Plans';
include 'includes/header.php';
include 'includes/sidebar.php';
require_admin();

// Auto-align database schema and official plans if remote database needs synchronization
try {
    if (isset($pdo) && $pdo) {
        $cols = $pdo->query("SHOW COLUMNS FROM membership_plans")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('plan_category', $cols)) {
            $pdo->exec("ALTER TABLE membership_plans ADD COLUMN plan_category VARCHAR(50) DEFAULT 'member_pass'");
        }
        if (!in_array('floor_access', $cols)) {
            $pdo->exec("ALTER TABLE membership_plans ADD COLUMN floor_access VARCHAR(50) DEFAULT 'all'");
        }
        if (!in_array('duration_minutes', $cols)) {
            $pdo->exec("ALTER TABLE membership_plans ADD COLUMN duration_minutes INT DEFAULT 0");
        }
        if (!in_array('is_test_promo', $cols)) {
            $pdo->exec("ALTER TABLE membership_plans ADD COLUMN is_test_promo TINYINT(1) DEFAULT 0");
        }

        // Align legacy & test plans
        $pdo->exec("UPDATE membership_plans SET is_active = 0, plan_category = 'legacy' WHERE id IN (1, 2, 3, 4)");
        $pdo->exec("UPDATE membership_plans SET is_active = 0, is_test_promo = 1, plan_category = 'test_promo' WHERE id IN (5, 6, 7)");

        // Ensure 8 official tarpaulin plans exist and are active
        $official_plans = [
            8  => ['name' => 'Annual Membership Fee',                   'price' => 1000.00, 'months' => 12, 'minutes' => 0,    'category' => 'membership_fee',  'floor' => 'all'],
            9  => ['name' => 'Member — Monthly Registration',           'price' => 750.00,  'months' => 1,  'minutes' => 0,    'category' => 'member_pass',     'floor' => 'all'],
            10 => ['name' => 'Member — Yearly Registration',            'price' => 7500.00, 'months' => 12, 'minutes' => 0,    'category' => 'member_pass',     'floor' => 'all'],
            11 => ['name' => 'Member — Daily (2nd Floor Only)',         'price' => 40.00,   'months' => 0,  'minutes' => 1440, 'category' => 'member_pass',     'floor' => 'second_floor_only'],
            12 => ['name' => 'Member — Daily (Ground + 2nd Floor)',     'price' => 50.00,   'months' => 0,  'minutes' => 1440, 'category' => 'member_pass',     'floor' => 'ground_and_second'],
            13 => ['name' => 'Non-Member — Monthly Registration',       'price' => 850.00,  'months' => 1,  'minutes' => 0,    'category' => 'non_member_pass', 'floor' => 'all'],
            14 => ['name' => 'Non-Member — Daily (2nd Floor Only)',     'price' => 50.00,   'months' => 0,  'minutes' => 1440, 'category' => 'non_member_pass', 'floor' => 'second_floor_only'],
            15 => ['name' => 'Non-Member — Daily (Ground + 2nd Floor)', 'price' => 60.00,   'months' => 0,  'minutes' => 1440, 'category' => 'non_member_pass', 'floor' => 'ground_and_second'],
        ];

        foreach ($official_plans as $id => $p) {
            $exists = $pdo->prepare("SELECT COUNT(*) FROM membership_plans WHERE id = ?");
            $exists->execute([$id]);
            if ($exists->fetchColumn() > 0) {
                $upd = $pdo->prepare("UPDATE membership_plans SET name = ?, price = ?, duration_months = ?, duration_minutes = ?, plan_category = ?, floor_access = ?, is_active = 1, is_test_promo = 0 WHERE id = ?");
                $upd->execute([$p['name'], $p['price'], $p['months'], $p['minutes'], $p['category'], $p['floor'], $id]);
            } else {
                $ins = $pdo->prepare("INSERT INTO membership_plans (id, name, price, duration_months, duration_minutes, plan_category, floor_access, is_active, is_test_promo) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0)");
                $ins->execute([$id, $p['name'], $p['price'], $p['months'], $p['minutes'], $p['category'], $p['floor']]);
            }
        }
    }
} catch (\Throwable $migErr) {
    error_log("Plans migration notice: " . $migErr->getMessage());
}

// Fetch all plans grouped by category
$plans_by_cat = ['membership_fee' => [], 'member_pass' => [], 'non_member_pass' => [], 'test_promo' => [], 'legacy' => []];
try {
    if (isset($pdo) && $pdo) {
        $rows = $pdo->query(
            "SELECT p.*, COUNT(CASE WHEN s.expiry_date >= CURDATE() THEN 1 END) AS subscriber_count 
             FROM membership_plans p 
             LEFT JOIN subscriptions s ON s.plan_id = p.id
             GROUP BY p.id
             ORDER BY p.is_active DESC, p.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $cat = $r['plan_category'] ?? 'legacy';
            if (!isset($plans_by_cat[$cat])) $plans_by_cat[$cat] = [];
            $plans_by_cat[$cat][] = $r;
        }
    }
} catch (Exception $e) {}

function duration_label($p) {
    if (!empty($p['duration_minutes']) && (int)$p['duration_minutes'] > 0) {
        $m = (int)$p['duration_minutes'];
        return $m === 1440 ? '1 Day' : ($m . ' Minute' . ($m > 1 ? 's' : ''));
    }
    $mo = (int)$p['duration_months'];
    return $mo . ' Month' . ($mo > 1 ? 's' : '');
}

function floor_badge($fa) {
    if ($fa === 'second_floor_only') return '<span class="badge badge-info">2nd Floor Only</span>';
    if ($fa === 'ground_and_second') return '<span class="badge badge-success">Ground + 2nd Floor</span>';
    return '';
}

function render_plan_card($p) {
    $active = (int)($p['is_active'] ?? 1);
    $cat = $p['plan_category'] ?? 'legacy';
    ob_start();
    ?>
    <div class="card plan-card <?= $active ? '' : 'plan-inactive' ?>" style="display:flex; flex-direction:column; border-top: 4px solid <?= $active ? 'var(--accent)' : 'var(--border)' ?>; opacity: <?= $active ? '1' : '0.6' ?>;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1rem;">
            <div class="stat-icon <?= $active ? 'gold' : '' ?>" style="margin-bottom:0;">
                <i class="fas <?= $cat === 'membership_fee' ? 'fa-id-card' : ($cat === 'test_promo' ? 'fa-flask' : 'fa-gem') ?>"></i>
            </div>
            <div style="display:flex; gap:0.4rem; align-items:center;">
                <?php if ($cat === 'test_promo'): ?>
                    <span class="badge" style="background:#f59e0b; color:#fff; font-size:0.7rem; padding:2px 8px; border-radius:20px;"><i class="fas fa-flask"></i> Sandbox Test</span>
                <?php elseif (!$active || $cat === 'legacy'): ?>
                    <span class="badge" style="background:var(--text-muted); color:#fff; font-size:0.7rem; padding:2px 8px; border-radius:20px;">Legacy</span>
                <?php endif; ?>
                <?php if ($active && $cat !== 'legacy'): ?>
                <button class="btn btn-outline btn-icon btn-sm edit-btn" 
                        data-id="<?= $p['id'] ?>"
                        data-name="<?= htmlspecialchars($p['name']) ?>"
                        data-months="<?= $p['duration_months'] ?>"
                        data-minutes="<?= $p['duration_minutes'] ?? 0 ?>"
                        data-price="<?= $p['price'] ?>"
                        data-benefits="<?= htmlspecialchars($p['benefits'] ?? '') ?>"
                        data-category="<?= $p['plan_category'] ?>"
                        data-floor="<?= $p['floor_access'] ?>"
                        aria-label="Edit Plan">
                    <i class="fas fa-pen"></i>
                </button>
                <?php endif; ?>
            </div>
        </div>
        
        <h3 class="section-title" style="margin-bottom:0.25rem;"><?= htmlspecialchars($p['name']) ?></h3>
        <p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:0.5rem;">
            <i class="far fa-clock"></i> <?= duration_label($p) ?>
            <?= floor_badge($p['floor_access'] ?? 'all') ?>
        </p>

        <div style="margin-bottom:1.5rem;">
            <span style="font-size:1.75rem; font-weight:700; color:var(--text-main);">₱<?= number_format($p['price'], 2) ?></span>
            <span style="font-size:0.85rem; color:var(--text-muted);">/ total</span>
        </div>

        <div style="border-top:1px solid var(--border); padding-top:1.25rem; margin-top:auto;">
            <div style="display:flex; align-items:center; justify-content:space-between; font-size:0.85rem;">
                <span style="color:var(--text-soft);"><i class="fas fa-users" style="color:var(--accent);"></i> Active Subs</span>
                <span style="font-weight:700;"><?= (int)$p['subscriber_count'] ?></span>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

$sections = [
    'membership_fee' => ['label' => '🏅 Membership Fee', 'desc' => 'Grants Official Member discount rates for 1 year.'],
    'member_pass'    => ['label' => '🟢 Member Rates',   'desc' => 'Access passes for Official Members (valid annual membership required).'],
    'non_member_pass'=> ['label' => '⚪ Non-Member Rates','desc' => 'Access passes for Non-Members or those without a current Annual Membership Fee.'],
    'test_promo'     => ['label' => '🔬 Developer Test Plans','desc' => 'Used only for payment gateway testing. Hidden from regular customers.'],
    'legacy'         => ['label' => '🗄️ Legacy / Inactive Plans','desc' => 'Old plans preserved for historical transaction records. No longer offered to new customers.'],
];
?>

<div class="topbar">
    <div class="page-title">
        <h1>Membership Plans</h1>
        <p>Official Palma's Elite Gym pricing structure.</p>
    </div>
    <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Create Plan</button>
</div>

<?php foreach ($sections as $cat_key => $sect): 
    if (empty($plans_by_cat[$cat_key])) continue; ?>
<div style="margin-bottom:2rem;">
    <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.4rem;">
        <h2 style="font-size:1.05rem; font-weight:700; color:var(--text-main); margin:0;"><?= $sect['label'] ?></h2>
    </div>
    <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom:1rem;"><?= $sect['desc'] ?></p>
    <div class="stats-grid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap:1.25rem;">
        <?php foreach ($plans_by_cat[$cat_key] as $p): ?>
        <?= render_plan_card($p) ?>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<style>
.badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:0.7rem; font-weight:600; margin-left:6px; }
.badge-info { background:#0ea5e9; color:#fff; }
.badge-success { background:#22c55e; color:#fff; }
.plan-inactive { pointer-events: none; }
.plan-inactive .btn { pointer-events: auto; }
</style>

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
                <input type="text" id="plan-name" name="name" class="form-control" placeholder="e.g. Member Monthly Registration" required>
            </div>
            <div class="form-group">
                <label>Plan Category *</label>
                <select id="plan-category" name="plan_category" class="form-control" required>
                    <option value="membership_fee">Membership Fee</option>
                    <option value="member_pass">Member Pass</option>
                    <option value="non_member_pass">Non-Member Pass</option>
                    <option value="test_promo">Test / Promo</option>
                </select>
            </div>
            <div class="form-group">
                <label>Floor Access</label>
                <select id="plan-floor" name="floor_access" class="form-control">
                    <option value="all">All Floors</option>
                    <option value="second_floor_only">2nd Floor Only</option>
                    <option value="ground_and_second">Ground + 2nd Floor</option>
                </select>
            </div>
            <div class="form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
                <div class="form-group">
                    <label>Duration (Months) <small style="color:var(--text-muted);">(0 for daily)</small></label>
                    <input type="number" id="plan-months" name="duration_months" class="form-control" min="0" value="1" required>
                </div>
                <div class="form-group">
                    <label>Price (₱) *</label>
                    <input type="number" id="plan-price" name="price" class="form-control" step="0.01" min="0" required>
                </div>
            </div>
            <div class="form-group">
                <label>Benefits (Optional)</label>
                <textarea id="plan-benefits" name="benefits" class="form-control" rows="3" placeholder="List benefits..."></textarea>
            </div>
            <div style="display:flex; gap:1rem; justify-content:flex-end; margin-top:1rem;">
                <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="save-btn">Save Plan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(data = null) {
    const modal = document.getElementById('plan-modal');
    const form = document.getElementById('plan-form');
    const title = document.getElementById('modal-title');
    
    form.reset();
    document.getElementById('plan-id').value = '';
    document.getElementById('plan-months').value = 1;
    
    if (data) {
        title.innerHTML = '<i class="fas fa-pen-to-square" style="color:var(--accent);"></i> Edit Plan';
        document.getElementById('plan-id').value = data.id;
        document.getElementById('plan-name').value = data.name;
        document.getElementById('plan-months').value = data.months;
        document.getElementById('plan-price').value = data.price;
        document.getElementById('plan-benefits').value = data.benefits;
        document.getElementById('plan-category').value = data.category || 'member_pass';
        document.getElementById('plan-floor').value = data.floor || 'all';
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

document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(); });
});
</script>

<?php include 'includes/footer.php'; ?>
