<?php
$page_title = 'Member Profile';
include 'includes/header.php';
include 'includes/sidebar.php';

$id = $_GET['id'] ?? 0;
$member = null;
$attendance = [];
$payments   = [];

try {
    if ($id && isset($pdo) && $pdo) {
        $stmt = $pdo->prepare("SELECT m.*, 
                                      (SELECT s.expiry_date 
                                       FROM subscriptions s 
                                       WHERE s.member_id = m.id AND s.expiry_date >= CURDATE()
                                       ORDER BY s.expiry_date DESC, s.id DESC 
                                       LIMIT 1) as expiry_date,
                                      (SELECT p.name 
                                       FROM subscriptions s 
                                       LEFT JOIN membership_plans p ON p.id = s.plan_id 
                                       WHERE s.member_id = m.id AND s.expiry_date >= CURDATE()
                                       ORDER BY s.expiry_date DESC, s.id DESC 
                                       LIMIT 1) as plan_name 
                               FROM members m 
                               WHERE m.id = ?");
        $stmt->execute([$id]);
        $member = $stmt->fetch();

        if ($member) {
            $attendance = $pdo->prepare("SELECT * FROM attendance WHERE member_id = ? ORDER BY date DESC LIMIT 5");
            $attendance->execute([$id]);
            $attendance = $attendance->fetchAll();

            $payments_stmt = $pdo->prepare("SELECT * FROM payments WHERE member_id = ? ORDER BY payment_date DESC, created_at DESC");
            $payments_stmt->execute([$id]);
            $payments = $payments_stmt->fetchAll();
        }
    }
} catch (Exception $e) {}

if (!$member): ?>
    <div class="topbar"><h1>Member Not Found</h1></div>
<?php else: ?>

<div class="topbar">
    <div class="page-title">
        <h1>Member Account</h1>
        <p>Viewing profile for <?php echo htmlspecialchars($member['full_name']); ?></p>
    </div>
    <div style="display:flex; gap:0.75rem;">
        <a href="renew-member.php?id=<?php echo $id; ?>" class="btn btn-outline" style="color:var(--accent); border-color:var(--accent-border); background:var(--accent-dim); text-decoration:none; display:inline-flex; align-items:center; gap:0.5rem;"><i class="fas fa-rotate-right"></i> Renew Plan</a>
        <button class="btn btn-primary" onclick="showIDModal()"><i class="fas fa-id-card"></i> View E-ID Card</button>
        <a href="edit-member.php?id=<?php echo $id; ?>" class="btn btn-outline">Edit</a>
        <a href="members.php" class="btn btn-outline">Back</a>
    </div>
</div>

<div style="display:grid; grid-template-columns: 1fr 340px; gap: 2rem;">
    <div style="display:flex; flex-direction:column; gap:2rem;">
        <div class="card" style="padding:0; overflow:hidden;">
            <div style="height:100px; background:var(--accent-gradient);"></div>
            <div style="padding:2rem; margin-top:-60px; display:flex; gap:2rem; align-items:flex-end;">
                <div style="width:130px; height:130px; border-radius:20px; background:#fff; border:5px solid #fff; box-shadow:var(--shadow-md); overflow:hidden; display:flex; align-items:center; justify-content:center;">
                    <?php if($member['photo']): ?>
                        <img src="<?php echo htmlspecialchars($member['photo']); ?>" style="width:100%; height:100%; object-fit:cover;">
                    <?php else: ?>
                        <span style="font-size:3rem; font-family:'Playfair Display', serif; color:var(--accent);"><?php echo strtoupper(substr($member['full_name'], 0, 1)); ?></span>
                    <?php endif; ?>
                </div>
                <div style="padding-bottom:0.5rem;">
                    <h2 style="font-family:'Playfair Display', serif; font-size:1.8rem; margin-bottom:0.2rem;"><?php echo htmlspecialchars($member['full_name']); ?></h2>
                    <p style="color:var(--text-muted); font-size:0.9rem; font-weight:600; text-transform:uppercase; letter-spacing:1px;"><?php echo htmlspecialchars($member['membership_id']); ?></p>
                </div>
                <div style="margin-left:auto; padding-bottom:0.5rem;">
                    <span class="badge <?php echo $member['status']==='Active' ? 'badge-success' : 'badge-danger'; ?>" style="padding:0.5rem 1rem; font-size:0.85rem;">
                        <i class="fas fa-circle" style="font-size:0.35rem; margin-right:4px;"></i>
                        <?php echo htmlspecialchars($member['status']); ?>
                    </span>
                </div>
            </div>
            <div style="padding:1.5rem 2.5rem 2.5rem; display:grid; grid-template-columns:repeat(3,1fr); gap:2rem; border-top:1px solid #f8f9fa;">
                <div><p class="stat-label">Email</p><p style="font-weight:600;"><?php echo htmlspecialchars($member['email']); ?></p></div>
                <div><p class="stat-label">Contact</p><p style="font-weight:600;"><?php echo htmlspecialchars($member['contact_number'] ?: '—'); ?></p></div>
                <div><p class="stat-label">Plan</p><p style="font-weight:600; color:var(--accent);"><?php echo htmlspecialchars($member['plan_name'] ?: 'No Plan'); ?></p></div>
            </div>
        </div>

        <div class="card">
            <h3 class="section-title"><i class="fas fa-credit-card" style="color:var(--accent); margin-right:8px;"></i> Payment History</h3>
            <div class="table-container">
                <table>
                    <thead><tr><th>Date</th><th>Amount</th><th>Method</th></tr></thead>
                    <tbody>
                        <?php if(empty($payments)): ?>
                        <?php render_empty_state('fas fa-receipt', 'No payments recorded.', '', true); ?>
                        <?php else: ?>
                        <?php foreach($payments as $p): ?>
                        <tr>
                            <td class="cell-secondary"><?php echo date('M d, Y', strtotime($p['payment_date'])); ?></td>
                            <td style="font-weight:700; color:var(--success);">₱<?php echo number_format($p['amount'], 2); ?></td>
                            <td class="cell-primary"><?php echo htmlspecialchars($p['payment_method']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card" style="text-align:center; display:flex; flex-direction:column; gap:0.75rem;">
        <h3 class="section-title" style="margin:0;">Scan Code</h3>
        <div id="side-qr" style="padding:1rem; display:inline-block; border:1px solid #eee; border-radius:12px; margin:0.5rem auto;"></div>
        <button class="btn btn-primary w-100" onclick="showIDModal()"><i class="fas fa-id-card"></i> View ID Card</button>
        <a href="renew-member.php?id=<?php echo $id; ?>" class="btn btn-outline w-100" style="color:var(--accent); border-color:var(--accent-border); text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:0.5rem;"><i class="fas fa-rotate-right"></i> Renew Subscription</a>
    </div>
</div>

<!-- FINAL PIXEL-PERFECT E-ID -->
<div class="modal-overlay" id="id-modal">
    <div class="modal" style="max-width:440px; padding:0; background:transparent; box-shadow:none;">
            <div id="id-card-capture" style="width:380px; height:640px; background:#ffffff; border-radius:32px; position:relative; overflow:hidden; margin:0 auto; box-shadow:0 30px 70px rgba(8,28,21,0.2); display:flex; flex-direction:column; border:1px solid rgba(255,255,255,0.8); justify-content:space-between;">
            
            <!-- OVERSIZED LOGO WATERMARK IN BACKGROUND (2.5% opacity) -->
            <img src="assets/images/palmas-logo.png" style="position:absolute; right:-60px; top:-20px; width:280px; opacity:0.025; pointer-events:none; z-index:1; filter:grayscale(100%);">
            
            <!-- TOP GRADIENT SECTION -->
            <div style="height:170px; background:linear-gradient(135deg, #133c2a 0%, #061510 100%); display:flex; flex-direction:column; align-items:center; justify-content:flex-start; padding:1.5rem 2rem 0; text-align:center; position:relative; z-index:2; overflow:hidden; flex-shrink:0;">
                <!-- Delicate background geometric circle to add premium depth -->
                <div style="position:absolute; top:-90px; right:-90px; width:180px; height:180px; border-radius:50%; border:1px solid rgba(255,255,255,0.03); pointer-events:none;"></div>
                <div style="position:absolute; top:-100px; left:-100px; width:200px; height:200px; background:radial-gradient(circle, rgba(45,106,79,0.3) 0%, rgba(0,0,0,0) 70%); pointer-events:none;"></div>
                
                <!-- Gym Logo Container -->
                <div style="width:38px; height:38px; background:#ffffff; border-radius:10px; display:flex; align-items:center; justify-content:center; padding:3px; margin-bottom:0.4rem; box-shadow:0 4px 10px rgba(0,0,0,0.15); flex-shrink:0;">
                    <img src="assets/images/palmas-logo.png" alt="Logo" style="width:100%; height:100%; object-fit:contain;">
                </div>
                
                <!-- Gym Name & Subtitle -->
                <h1 style="font-family:'Outfit', sans-serif; font-weight:700; font-size:1.15rem; color:#ffffff; letter-spacing:0.8px; line-height:1.2; margin:0; text-shadow:0 2px 4px rgba(0,0,0,0.15); text-transform:uppercase;">
                    <?php echo htmlspecialchars($app_settings['gym_name'] ?? 'Palma\'s Elite Gym'); ?>
                </h1>
                <p style="font-size:0.52rem; text-transform:uppercase; letter-spacing:2.5px; color:rgba(255,255,255,0.45); font-weight:700; margin-top:3px; margin-bottom:0;">
                    Elite Fitness Club
                </p>
            </div>

            <!-- CARD BODY (FLOATS UP OVER GRADIENT SECTION) -->
            <div style="flex:1; display:flex; flex-direction:column; align-items:center; padding:0 2rem 1rem; position:relative; z-index:3; margin-top:-65px; justify-content:space-between;">
                
                <div style="display:flex; flex-direction:column; align-items:center; width:100%;">
                    <!-- CIRCULAR PREMIUM AVATAR -->
                    <div style="width:130px; height:130px; border-radius:50%; background:#ffffff; position:relative; padding:6px; box-shadow:0 12px 30px rgba(8,28,21,0.12); z-index:10; margin-bottom:0.75rem;">
                        <!-- Inner glow border ring -->
                        <div style="position:absolute; inset:2px; border-radius:50%; border:2px solid rgba(45,106,79,0.12); pointer-events:none;"></div>
                        <!-- Actual Photo/Fallback Area -->
                        <div style="width:100%; height:100%; border-radius:50%; overflow:hidden; background:#f1f5f9; display:flex; align-items:center; justify-content:center; border:2px solid #ffffff; box-shadow:inset 0 2px 5px rgba(0,0,0,0.05);">
                            <?php if($member['photo']): ?>
                                <img src="<?php echo htmlspecialchars($member['photo']); ?>" style="width:100%; height:100%; object-fit:cover;">
                            <?php else: ?>
                                <div style="width:100%; height:100%; background:linear-gradient(135deg, var(--accent-dim) 0%, rgba(45,106,79,0.16) 100%); display:flex; align-items:center; justify-content:center;">
                                    <span style="font-size:3.2rem; font-family:'Outfit', sans-serif; font-weight:700; color:var(--accent); text-shadow:0 1px 0 rgba(255,255,255,0.7);"><?php echo strtoupper(substr($member['full_name'], 0, 1)); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- Mini Verified Check Badge -->
                        <div style="position:absolute; bottom:5px; right:5px; width:28px; height:28px; background:linear-gradient(135deg, #2d6a4f 0%, #1b4332 100%); border:2.5px solid #ffffff; border-radius:50%; display:flex; align-items:center; justify-content:center; box-shadow:0 4px 10px rgba(8,28,21,0.15); z-index:12;">
                            <i class="fas fa-check" style="color:#ffffff; font-size:0.65rem; font-weight:900;"></i>
                        </div>
                    </div>

                    <!-- MEMBER NAME -->
                    <h2 style="font-family:'Outfit', sans-serif; font-size:1.65rem; font-weight:700; color:#0c2219; margin:0 0 0.3rem; text-transform:capitalize; letter-spacing:-0.4px;">
                        <?php echo htmlspecialchars(strtolower($member['full_name'])); ?>
                    </h2>
                    
                    <!-- MODERN "ACTIVE MEMBER" BADGE WITH SOFT GREEN STYLING -->
                    <div style="display:inline-flex; align-items:center; gap:0.4rem; background:<?php echo $member['status']==='Active'?'rgba(46,125,50,0.06)':'rgba(229,57,53,0.06)'; ?>; color:<?php echo $member['status']==='Active'?'var(--success)':'var(--danger)'; ?>; padding:4px 12px; border-radius:30px; font-size:0.62rem; font-weight:800; text-transform:uppercase; letter-spacing:1.5px; margin-bottom:1rem; border:1px solid <?php echo $member['status']==='Active'?'rgba(46,125,50,0.15)':'rgba(229,57,53,0.15)'; ?>; box-shadow:0 2px 8px rgba(0,0,0,0.02);">
                        <i class="fas fa-circle-check" style="font-size:0.75rem; color:<?php echo $member['status']==='Active'?'var(--success)':'var(--danger)'; ?>;"></i>
                        <?php echo htmlspecialchars($member['status']); ?> Member
                    </div>
                </div>
                
                <!-- QR CODE INSIDE SOFT ELEVATED CARD -->
                <div style="background:#ffffff; border-radius:24px; padding:1rem 1rem 0.75rem; border:1px solid #f1f5f9; box-shadow:0 12px 36px rgba(8,28,21,0.06); display:flex; flex-direction:column; align-items:center; width:100%; max-width:160px; margin-bottom:0.75rem; flex-shrink:0;">
                    <div id="id-qr-box" style="padding:2px; background:#fff;"></div>
                    <span style="font-family:'Outfit', sans-serif; font-size:0.5rem; font-weight:800; color:#94a3b8; letter-spacing:1.5px; text-transform:uppercase; margin-top:0.6rem; text-align:center;">
                        SCAN FOR GYM ACCESS
                    </span>
                </div>

                <!-- MEMBERSHIP DETAILS GRID (Boarding pass style) -->
                <div style="border-top:1px dashed #e2e8f0; padding-top:0.75rem; display:flex; justify-content:space-between; align-items:center; width:100%; max-width:290px; margin:0 auto; position:relative; width:100%; flex-shrink:0;">
                    <!-- Left Side Details -->
                    <div style="text-align:left;">
                        <p style="font-size:0.52rem; color:#94a3b8; text-transform:uppercase; font-weight:700; letter-spacing:1.2px; margin:0 0 2px 0;">Account ID</p>
                        <p style="font-size:0.9rem; font-weight:700; color:#0c2219; font-family:monospace; margin:0;"><?php echo htmlspecialchars($member['membership_id']); ?></p>
                    </div>
                    <!-- Right Side Details -->
                    <div style="text-align:right;">
                        <p style="font-size:0.52rem; color:#94a3b8; text-transform:uppercase; font-weight:700; letter-spacing:1.2px; margin:0 0 2px 0;">Expiry Date</p>
                        <p style="font-size:0.9rem; font-weight:700; color:#0c2219; margin:0;"><?php echo $member['expiry_date'] ? date('M d, Y', strtotime($member['expiry_date'])) : 'No Active Plan'; ?></p>
                    </div>
                </div>
            </div>

            <!-- FOOTER - LUXURY BRAND MARK -->
            <div style="height:48px; width:100%; text-align:center; background:#f8fafc; border-top:1px solid #f1f5f9; display:flex; align-items:center; justify-content:center; gap:0.5rem; flex-shrink:0;">
                <span style="font-size:0.58rem; font-weight:700; color:#94a3b8; letter-spacing:1.5px; text-transform:uppercase;"><?php echo htmlspecialchars($app_settings['gym_name'] ?? 'Palma\'s Elite Gym'); ?></span>
                <span style="color:#cbd5e1; font-size:0.6rem;">•</span>
                <span style="font-size:0.58rem; font-weight:700; color:#94a3b8; letter-spacing:1.5px; text-transform:uppercase;">Official E-ID</span>
            </div>
        </div>

        <div style="display:flex; gap:1rem; margin-top:2rem; justify-content:center;">
            <button class="btn btn-outline" style="background:#fff;" onclick="closeIDModal()">Cancel</button>
            <button class="btn btn-primary" onclick="downloadID()" id="dl-btn"><i class="fas fa-download"></i> Download E-ID</button>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
const mId = "<?php echo $member['membership_id']; ?>";
new QRCode(document.getElementById("side-qr"), { text: mId, width: 120, height: 120 });
new QRCode(document.getElementById("id-qr-box"), { text: mId, width: 100, height: 100 });

function showIDModal() { document.getElementById('id-modal').classList.add('active'); }
function closeIDModal() { document.getElementById('id-modal').classList.remove('active'); }

function downloadID() {
    const btn = document.getElementById('dl-btn');
    const captureArea = document.getElementById('id-card-capture');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing...';
    btn.disabled = true;

    const qrCanvas = document.querySelector('#id-qr-box canvas');
    if (qrCanvas) {
        const qrImage = new Image();
        qrImage.src = qrCanvas.toDataURL("image/png");
        qrImage.style.width = "100px";
        qrImage.style.height = "100px";
        document.getElementById('id-qr-box').innerHTML = '';
        document.getElementById('id-qr-box').appendChild(qrImage);
    }

    setTimeout(() => {
        html2canvas(captureArea, { scale: 3, useCORS: true }).then(canvas => {
            const link = document.createElement('a');
            link.download = 'Official_ID_<?php echo $member['membership_id']; ?>.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
            btn.innerHTML = '<i class="fas fa-download"></i> Download E-ID';
            btn.disabled = false;
        });
    }, 400);
}
</script>

<?php endif; ?>
<?php include 'includes/footer.php'; ?>
