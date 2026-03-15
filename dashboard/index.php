<?php
require_once dirname(__DIR__).'/config.php';
startSession();
requireLogin();
try {
    $user=currentUser();
    if(!$user){
        session_destroy();
        header('Location: /auth/login.php?error=session_expired');
        exit;
    }
    $plan=getPlan($user['plan'] ?? 'free');
    $quota=getUserQuota($user['id']);
} catch(Exception $e) {
    error_log('Dashboard Error: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html><head><title>Error</title><style>body{font-family:monospace;padding:40px;background:#0a0a0a;color:#999;}h1{color:#ff4560;}.error-box{background:#1a1a1a;padding:20px;border-radius:8px;border-left:4px solid #ff4560;margin:20px 0;}</style></head><body>';
    echo '<h1>Dashboard Error</h1>';
    echo '<div class="error-box"><p>An error occurred while loading your dashboard.</p></div>';
    echo '<p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><a href="/dashboard/" style="color:#00d4aa;">Try Again</a> or <a href="'.SUPPORT_TELEGRAM_URL.'" style="color:#00d4aa;">Contact Support</a></p>';
    echo '</body></html>';
    exit;
}
// Recent usage
try {
    $recent=db()->prepare("SELECT * FROM usage_log WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
    $recent->execute([$user['id']]);
    $jobs=$recent->fetchAll();
} catch(Exception $e) {
    $jobs = [];
}
// This month totals
try {
    $mstats=db()->prepare("SELECT COUNT(*) as jobs, COALESCE(SUM(csv_rows),0) as rows, COALESCE(SUM(files_updated),0) as files FROM usage_log WHERE user_id=? AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())");
    $mstats->execute([$user['id']]);
    $ms=$mstats->fetch();
} catch(Exception $e) {
    $ms = ['jobs'=>0, 'rows'=>0, 'files'=>0];
}
// Lifetime total stats (all time)
try {
    $lifetimeStats=db()->prepare("SELECT COUNT(*) as total_jobs, COALESCE(SUM(csv_rows),0) as total_rows, COALESCE(SUM(files_updated),0) as total_files, COALESCE(SUM(CASE WHEN job_type='autopilot' THEN csv_rows ELSE 0 END),0) as autopilot_rows FROM usage_log WHERE user_id=?");
    $lifetimeStats->execute([$user['id']]);
    $lifetime=$lifetimeStats->fetch();
} catch(Exception $e) {
    $lifetime = ['total_jobs'=>0, 'total_rows'=>0, 'total_files'=>0, 'autopilot_rows'=>0];
}
// Quota pct
$total_limit = $quota['limit'] + $quota['rollover'];
$qpct=$quota['unlimited']?0:($total_limit>0?min(100,round($quota['used']/$total_limit*100)):100);
$qclass=$qpct>=90?'danger':($qpct>=70?'warn':'');
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Dashboard — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<link rel="stylesheet" href="/assets/loading.css"></head><body>
<div id="toast-wrap"></div>
<div class="dash-layout">
<?php include __DIR__.'/_sidebar.php'; ?>
<div class="dash-main">
  <div class="dash-topbar">
    <div class="dash-page-title">Dashboard</div>
    <div style="display:flex;align-items:center;gap:12px;">
      <?php if($user['plan']==='free'): ?>
      <button onclick="showLicenseModal()" class="btn btn-ghost btn-sm" style="border:1px dashed rgba(240,165,0,.3);color:var(--a1);">🔑 Activate License</button>
      <a href="/landing/pricing.php" class="btn btn-amber btn-sm">⚡ Upgrade Plan</a>
      <?php endif; ?>
      <a href="/tool/" class="btn btn-teal btn-sm">🚀 Open Tool</a>
    </div>
  </div>
  <div class="dash-content">

    <!-- WELCOME -->
    <div style="background:linear-gradient(135deg,rgba(240,165,0,.08),rgba(240,165,0,.03));border:1px solid rgba(240,165,0,.15);border-radius:16px;padding:24px 28px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
      <div>
        <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:#fff;">Welcome back, <?= htmlspecialchars($user['name']) ?>! 👋</div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-top:4px;">
          Plan: <span class="plan-pill pp-<?= $user['plan'] ?>"><?= ucfirst($user['plan']) ?></span>
          <?php if($user['plan_expires_at']&&$user['plan']!=='lifetime'): ?>
          &nbsp; Expires: <b style="color:var(--warn);"><?= date('M j, Y',strtotime($user['plan_expires_at'])) ?></b>
          <?php endif; ?>
        </div>
      </div>
      <a href="/tool/" class="btn btn-amber">🚀 Start New Job</a>
    </div>

    <!-- STATS -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="sc-val" style="color:var(--a1);"><?= $quota['unlimited']?'∞':number_format($quota['remaining']) ?></div>
        <div class="sc-label">Rows Remaining</div>
        <div class="sc-sub"><?= ($quota['unlimited']) ? 'Unlimited' : 'This month' ?></div>
      </div>
      <div class="stat-card">
        <div class="sc-val"><?= number_format($ms['jobs']) ?></div>
        <div class="sc-label">Jobs This Month</div>
        <div class="sc-sub"><?= number_format($ms['rows']) ?> rows processed</div>
      </div>
      <div class="stat-card">
        <div class="sc-val" style="color:var(--ok);"><?= number_format($ms['files']) ?></div>
        <div class="sc-label">Files Updated</div>
        <div class="sc-sub">This month</div>
      </div>
      <div class="stat-card">
        <div class="sc-val" style="color:var(--a2);"><?= number_format($quota['rollover']) ?></div>
        <div class="sc-label">Rollover Balance</div>
        <div class="sc-sub"><?= ($user['plan'] !== 'free') ? 'Carries to next month' : 'Not available on free' ?></div>
      </div>
    </div>

    <!-- LIFETIME USAGE STATS (Only for lifetime plan users) -->
    <?php if($user['plan'] === 'lifetime'): ?>
    <div class="card" style="margin-top:24px;margin-bottom:24px;background:linear-gradient(135deg,rgba(0,212,170,.08),rgba(0,212,170,.03));border:1px solid rgba(0,212,170,.2);">
      <div class="card-title" style="color:var(--a2);">♾️ Lifetime Total Usage</div>
      <div class="stats-grid" style="margin-top:16px;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));">
        <div style="text-align:center;padding:16px;">
          <div style="font-size:32px;font-weight:900;color:var(--a2);font-family:'Syne',sans-serif;"><?= number_format($lifetime['total_rows']) ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:1.5px;">Total Rows Processed</div>
        </div>
        <div style="text-align:center;padding:16px;">
          <div style="font-size:32px;font-weight:900;color:var(--a1);font-family:'Syne',sans-serif;"><?= number_format($lifetime['total_jobs']) ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:1.5px;">Total Jobs</div>
        </div>
        <div style="text-align:center;padding:16px;">
          <div style="font-size:32px;font-weight:900;color:var(--ok);font-family:'Syne',sans-serif;"><?= number_format($lifetime['total_files']) ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:1.5px;">Total Files Updated</div>
        </div>
        <div style="text-align:center;padding:16px;">
          <div style="font-size:32px;font-weight:900;color:#c084fc;font-family:'Syne',sans-serif;"><?= number_format($lifetime['autopilot_rows']) ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:1.5px;">Autopilot Usage</div>
        </div>
        <div style="text-align:center;padding:16px;">
          <div style="font-size:32px;font-weight:900;color:#8b5cf6;font-family:'Syne',sans-serif;">∞</div>
          <div style="font-size:11px;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:1.5px;">No Limits Ever</div>
        </div>
      </div>
      <div style="text-align:center;margin-top:16px;padding:12px;background:rgba(0,0,0,.2);border-radius:8px;">
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:rgba(200,200,232,.6);">
          <span style="color:var(--a2);font-weight:700;">🎉 Lifetime Access</span> • Unlimited usage • No monthly quotas • No expiration
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- QUOTA BAR -->
    <?php if(!$quota['unlimited']): ?>
    <div class="card" style="margin-bottom:24px;">
      <div class="card-title">📊 Monthly Quota</div>
      <div class="quota-wrap">
        <div class="quota-info">
          <span>Used: <b><?= number_format($quota['used']) ?> rows</b></span>
          <span>Limit: <b><?= number_format($quota['limit']) ?> + <?= number_format($quota['rollover']) ?> rollover</b></span>
        </div>
        <div class="quota-track"><div class="quota-fill <?= $qclass ?>" style="width:<?= $qpct ?>%;"></div></div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:8px;"><?= $qpct ?>% used &nbsp;·&nbsp; <?= number_format($quota['remaining']) ?> rows remaining</div>
      </div>
      <?php if($user['plan']==='free'): ?>
      <div class="warn-box" style="margin-top:12px;margin-bottom:0;">Free plan: <?= $quota['remaining'] ?> of 20 rows remaining. <a href="/landing/pricing.php" style="color:var(--a1);">Upgrade to Pro</a> for 500 rows/month with rollover.</div>
      <?php elseif($qpct>=80): ?>
      <div class="warn-box" style="margin-top:12px;margin-bottom:0;">You've used <?= $qpct ?>% of your monthly quota. <a href="/dashboard/billing.php" style="color:var(--a1);">Consider upgrading.</a></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- RECENT JOBS -->
    <div class="card">
      <div class="card-title">📋 Recent Jobs</div>
      <?php if(empty($jobs)): ?>
      <div style="text-align:center;padding:32px;font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);">No jobs yet. <a href="/tool/" style="color:var(--a1);">Run your first job →</a></div>
      <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Time</th><th>Type</th><th>CSV Rows</th><th>Files Updated</th><th>Job</th></tr></thead>
          <tbody>
          <?php foreach($jobs as $j):
            $jtype = $j['job_type'] ?? 'bulk_replace';
            $typeLabel = match($jtype) {
              'csv_generator' => 'CSV Gen',
              'zip_manager' => 'ZIP Mgr',
              'copy_rename' => 'Copy/Rename',
              'autopilot' => 'Autopilot',
              default => 'Bulk Replace'
            };
            $typeColor = match($jtype) {
              'csv_generator' => '#3b82f6',
              'zip_manager' => '#f59e0b',
              'copy_rename' => '#10b981',
              'autopilot' => '#c084fc',
              default => 'var(--a2)'
            };
          ?>
          <tr>
            <td><?= ago($j['created_at']) ?></td>
            <td><span style="color:<?= $typeColor ?>;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;"><?= $typeLabel ?></span></td>
            <td><span style="color:var(--a1);"><?= number_format($j['csv_rows']) ?></span></td>
            <td><span style="color:var(--ok);"><?= number_format($j['files_updated']) ?></span></td>
            <td style="color:var(--muted);"><?= htmlspecialchars($j['job_name']??'Untitled Job') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- SUPPORT -->
    <div style="background:var(--card);border:1px solid rgba(0,212,170,.15);border-radius:16px;padding:24px;margin-top:24px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
      <div style="font-size:36px;">💬</div>
      <div style="flex:1;">
        <div style="font-family:'Syne',sans-serif;font-size:16px;font-weight:700;color:#fff;margin-bottom:4px;">Need help?</div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);">Direct support via Telegram. We usually respond within a few hours.</div>
      </div>
      <a href="<?= SUPPORT_TELEGRAM_URL ?>" target="_blank" class="btn btn-teal btn-sm">💬 Chat on Telegram</a>
    </div>

  </div>
</div>
</div>

<!-- LICENSE ACTIVATION MODAL (Free Users Only) -->
<?php if($user['plan'] === 'free'): ?>
<div id="license-modal" class="modal-overlay" style="display:none;">
  <div class="modal-box" style="max-width:580px;animation:modalFadeIn .3s ease-out;">
    <div style="position:relative;padding:32px;">

      <!-- Close button -->
      <button onclick="closeLicenseModal()" style="position:absolute;top:16px;right:16px;background:rgba(255,255,255,.05);border:1px solid var(--border);width:32px;height:32px;border-radius:8px;color:var(--muted);font-size:18px;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;" onmouseover="this.style.background='rgba(255,255,255,.1)';this.style.color='#fff';" onmouseout="this.style.background='rgba(255,255,255,.05)';this.style.color='var(--muted)';">×</button>

      <!-- Header -->
      <div style="text-align:center;margin-bottom:28px;">
        <div style="display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;background:linear-gradient(135deg,rgba(240,165,0,.15),rgba(192,132,252,.08));border:2px solid rgba(240,165,0,.3);border-radius:16px;margin-bottom:16px;">
          <span style="font-size:32px;">🔑</span>
        </div>
        <h2 style="font-family:'Syne',sans-serif;font-size:24px;font-weight:900;color:#fff;margin:0 0 8px;">Unlock Premium Features</h2>
        <p style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);margin:0;">Already purchased? Activate your license instantly</p>
      </div>

      <!-- License Key Input -->
      <div style="background:var(--dim);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:20px;">
        <label style="font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);display:block;margin-bottom:10px;">License Key</label>
        <input type="text" id="modal-license-key" placeholder="XXXX-XXXX-XXXX-XXXX" style="width:100%;background:var(--card);border:1px solid var(--border);border-radius:8px;padding:12px 16px;color:#fff;font-family:'JetBrains Mono',monospace;font-size:14px;letter-spacing:1px;margin-bottom:12px;" onkeypress="if(event.key==='Enter')activateLicenseFromModal()">

        <label style="font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);display:block;margin-bottom:10px;">Product Type</label>
        <select id="modal-product-type" style="width:100%;background:var(--card);border:1px solid var(--border);border-radius:8px;padding:12px 16px;color:#fff;font-family:'JetBrains Mono',monospace;font-size:13px;">
          <option value="">-- Select Product --</option>
          <option value="lifetime">Lifetime Plan</option>
          <option value="csv-generator-pro">CSV Generator</option>
          <option value="zip-manager">ZIP Manager</option>
          <option value="copy-rename">Copy & Rename</option>
          <option value="autopilot">Autopilot (Lifetime only)</option>
          <option value="premium-bundle">Premium Bundle (All 3 Add-ons)</option>
        </select>

        <div id="modal-activation-msg" style="margin-top:12px;"></div>
      </div>

      <!-- CTA Buttons -->
      <div style="display:flex;gap:12px;margin-bottom:20px;">
        <button onclick="activateLicenseFromModal()" class="btn btn-amber" style="flex:1;justify-content:center;">
          <span id="modal-activate-text">🔓 Activate License</span>
        </button>
        <button onclick="closeLicenseModal()" class="btn btn-ghost" style="flex:1;justify-content:center;">Continue Free</button>
      </div>

      <!-- Info Box -->
      <div style="background:rgba(0,212,170,.06);border:1px dashed rgba(0,212,170,.25);border-radius:10px;padding:14px;">
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:1.8;">
          <strong style="color:var(--a2);">💡 Don't have a license key?</strong><br>
          • Monthly/Yearly plans activate automatically via email<br>
          • One-time add-ons & Lifetime require license key activation<br>
          • <a href="/landing/pricing.php" style="color:var(--a1);">View all plans & pricing →</a>
        </div>
      </div>

    </div>
  </div>
</div>

<style>
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.85);
  backdrop-filter: blur(8px);
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.modal-box {
  background: var(--card);
  border: 1px solid rgba(240, 165, 0, 0.2);
  border-radius: 20px;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
  position: relative;
}
@keyframes modalFadeIn {
  from {
    opacity: 0;
    transform: translateY(-20px) scale(0.95);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}
</style>

<script>
// License Modal Functions
function showLicenseModal() {
  document.getElementById('license-modal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function closeLicenseModal() {
  document.getElementById('license-modal').style.display = 'none';
  document.body.style.overflow = '';
  localStorage.setItem('license_modal_dismissed', Date.now());
}

async function activateLicenseFromModal() {
  const key = document.getElementById('modal-license-key').value.trim();
  const product = document.getElementById('modal-product-type').value;
  const btn = document.querySelector('#license-modal .btn-amber');
  const btnText = document.getElementById('modal-activate-text');
  const msgDiv = document.getElementById('modal-activation-msg');

  if (!key) {
    msgDiv.innerHTML = '<div class="err-box" style="font-size:11px;">Please enter your license key</div>';
    return;
  }
  if (!product) {
    msgDiv.innerHTML = '<div class="err-box" style="font-size:11px;">Please select a product type</div>';
    return;
  }

  btn.disabled = true;
  btnText.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite;">⏳</span> Activating...';
  msgDiv.innerHTML = '';

  try {
    const res = await fetch('/api/activate_license.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ license_key: key, product_slug: product })
    });
    const result = await res.json();

    if (result.success) {
      msgDiv.innerHTML = `<div class="info-box" style="background:rgba(0,212,170,.08);border-color:rgba(0,212,170,.3);font-size:11px;">✅ ${result.message || 'License activated successfully!'}<br><span style="font-size:10px;">Reloading page...</span></div>`;
      setTimeout(() => location.reload(), 2000);
    } else {
      msgDiv.innerHTML = `<div class="err-box" style="font-size:11px;">⚠ ${result.error || 'Activation failed. Please check your license key.'}</div>`;
      btn.disabled = false;
      btnText.textContent = '🔓 Activate License';
    }
  } catch (error) {
    msgDiv.innerHTML = '<div class="err-box" style="font-size:11px;">⚠ Network error. Please try again.</div>';
    btn.disabled = false;
    btnText.textContent = '🔓 Activate License';
  }
}

// Auto-show modal for free users (once per 7 days)
document.addEventListener('DOMContentLoaded', function() {
  const dismissed = localStorage.getItem('license_modal_dismissed');
  const sevenDays = 7 * 24 * 60 * 60 * 1000;

  if (!dismissed || (Date.now() - parseInt(dismissed)) > sevenDays) {
    // Show modal after 2 seconds delay
    setTimeout(() => showLicenseModal(), 2000);
  }
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeLicenseModal();
});

// Close modal on outside click
document.getElementById('license-modal')?.addEventListener('click', function(e) {
  if (e.target === this) closeLicenseModal();
});
</script>
<?php endif; ?>

<script>function toast(m,t='ok'){const w=document.getElementById('toast-wrap');const el=document.createElement('div');el.className='toast '+t;el.innerHTML=`<span>${t==='ok'?'✅':t==='err'?'❌':'⚠️'}</span><span>${m}</span>`;w.appendChild(el);setTimeout(()=>{el.style.cssText+='opacity:0;transform:translateX(30px);transition:all .3s';setTimeout(()=>el.remove(),300);},3500);}</script>
</body></html>
