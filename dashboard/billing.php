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

    if (
        $user['plan'] !== 'free' &&
        $user['billing_cycle'] !== 'lifetime' &&
        !empty($user['plan_expires_at']) &&
        strtotime($user['plan_expires_at']) < time()
    ) {
        try {
            $db = db();
            $db->prepare("UPDATE users SET plan='free', billing_cycle='none', plan_expires_at=NULL WHERE id=?")
               ->execute([$user['id']]);
            $db->prepare("UPDATE licenses SET status='expired' WHERE user_id=? AND (expires_at IS NOT NULL AND expires_at < NOW()) AND status='active'")
               ->execute([$user['id']]);
            $user['plan'] = 'free';
            $user['billing_cycle'] = 'none';
            $user['plan_expires_at'] = null;
        } catch (Exception $expEx) {
            error_log("Billing page: Failed to auto-downgrade - " . $expEx->getMessage());
        }
    }

    $plan  = getPlan($user['plan']);
    $quota = getUserQuota($user['id']);

    $activePromo = null;
    try {
        $promoStmt = db()->prepare("SELECT code, discount_percent, valid_until FROM promo_codes WHERE is_active=1 AND UNIX_TIMESTAMP(valid_from) <= UNIX_TIMESTAMP() AND UNIX_TIMESTAMP(valid_until) >= UNIX_TIMESTAMP() ORDER BY created_at DESC LIMIT 1");
        $promoStmt->execute();
        $activePromo = $promoStmt->fetch();
    } catch(Exception $pe) {}

    $activeAddons = [];
    try {
        $addonStmt = db()->prepare("SELECT DISTINCT addon_slug, purchased_at FROM user_addons WHERE user_id=? AND addon_slug IS NOT NULL ORDER BY purchased_at DESC");
        $addonStmt->execute([$user['id']]);
        $activeAddons = $addonStmt->fetchAll();
    } catch(Exception $ae) {}

} catch(Exception $e) {
    error_log('Billing Error: '.$e->getMessage());
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><title>Error</title><link rel="stylesheet" href="/assets/main.css"></head><body style="padding:40px;"><h1 style="color:var(--err);">Billing Error</h1><p>'.htmlspecialchars($e->getMessage()).'</p><a href="/dashboard/" style="color:var(--a2);">Back</a></body></html>';
    exit;
}

$planGradients = [
    'free'     => 'linear-gradient(135deg,rgba(120,120,140,.12),rgba(80,80,100,.06))',
    'pro'      => 'linear-gradient(135deg,rgba(0,212,170,.12),rgba(0,212,170,.04))',
    'platinum' => 'linear-gradient(135deg,rgba(240,165,0,.12),rgba(240,165,0,.04))',
    'lifetime' => 'linear-gradient(135deg,rgba(99,102,241,.12),rgba(192,132,252,.06))',
];
$planBorders = [
    'free'     => 'rgba(120,120,140,.25)',
    'pro'      => 'rgba(0,212,170,.3)',
    'platinum' => 'rgba(240,165,0,.3)',
    'lifetime' => 'rgba(192,132,252,.3)',
];
$planColors = [
    'free'     => 'var(--muted)',
    'pro'      => 'var(--a2)',
    'platinum' => 'var(--a1)',
    'lifetime' => '#c084fc',
];
$planIcons = [
    'free'     => '◻',
    'pro'      => '⚡',
    'platinum' => '★',
    'lifetime' => '♾',
];
$currentPlanGrad   = $planGradients[$user['plan']] ?? $planGradients['free'];
$currentPlanBorder = $planBorders[$user['plan']] ?? $planBorders['free'];
$currentPlanColor  = $planColors[$user['plan']] ?? $planColors['free'];
$currentPlanIcon   = $planIcons[$user['plan']] ?? '◻';

$addonMeta = [
    'csv-generator-pro' => ['name'=>'CSV Generator Pro',    'icon'=>'⊞', 'color'=>'var(--a2)'],
    'zip-manager'       => ['name'=>'ZIP Manager',          'icon'=>'⊡', 'color'=>'var(--a1)'],
    'copy-rename'       => ['name'=>'Copy & Rename',        'icon'=>'⧉', 'color'=>'#60a5fa'],
    'autopilot'         => ['name'=>'AI Autopilot',         'icon'=>'◎', 'color'=>'#c084fc'],
    'premium-bundle'    => ['name'=>'Premium Bundle',       'icon'=>'◈', 'color'=>'var(--a1)'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Billing — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>
.billing-hero{background:<?= $currentPlanGrad ?>;border:1.5px solid <?= $currentPlanBorder ?>;border-radius:16px;padding:28px 32px;margin-bottom:24px;position:relative;overflow:hidden;}
.billing-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 90% 50%,<?= $currentPlanBorder ?> 0%,transparent 65%);pointer-events:none;}
.plan-icon-big{font-size:48px;line-height:1;color:<?= $currentPlanColor ?>;filter:drop-shadow(0 0 12px <?= $currentPlanColor ?>);margin-bottom:12px;}
.plan-name-big{font-family:'Syne',sans-serif;font-size:32px;font-weight:900;color:#fff;letter-spacing:-0.5px;}
.plan-tag{display:inline-block;padding:4px 12px;background:<?= $currentPlanColor ?>;color:#000;font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;border-radius:20px;margin-left:10px;vertical-align:middle;}
.plan-meta-row{display:flex;gap:24px;margin-top:16px;flex-wrap:wrap;}
.plan-meta-item{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);display:flex;align-items:center;gap:6px;}
.plan-meta-item strong{color:var(--text);}

.key-input-wrap{position:relative;margin-bottom:8px;}
.key-input{width:100%;background:var(--dim);border:1.5px solid var(--border);border-radius:10px;padding:16px 20px;color:var(--text);font-family:'JetBrains Mono',monospace;font-size:13px;letter-spacing:2px;text-transform:uppercase;transition:border-color .2s,box-shadow .2s;box-sizing:border-box;}
.key-input:focus{outline:none;border-color:var(--a1);box-shadow:0 0 0 3px rgba(240,165,0,.12);}
.key-input.valid{border-color:var(--a2);box-shadow:0 0 0 3px rgba(0,212,170,.12);}
.key-input.invalid{border-color:var(--err);box-shadow:0 0 0 3px rgba(255,69,88,.12);}

.key-segments{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;}
.key-seg{flex:1;min-width:80px;height:6px;background:var(--dim);border-radius:3px;border:1px solid var(--border);transition:background .2s,border-color .2s;}
.key-seg.filled{background:var(--a1);border-color:var(--a1);box-shadow:0 0 6px rgba(240,165,0,.4);}
.key-seg.complete{background:var(--a2);border-color:var(--a2);box-shadow:0 0 6px rgba(0,212,170,.4);}

.key-type-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;letter-spacing:0.5px;margin-top:8px;opacity:0;transition:opacity .2s;}
.key-type-badge.show{opacity:1;}
.key-type-badge.gumroad{background:rgba(255,144,0,.1);border:1px solid rgba(255,144,0,.3);color:#ff9000;}
.key-type-badge.system{background:rgba(0,212,170,.1);border:1px solid rgba(0,212,170,.3);color:var(--a2);}

.steps-flow{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px;}
.step-item{background:var(--dim);border-radius:10px;padding:16px;border:1px solid var(--border);position:relative;}
.step-num{width:26px;height:26px;border-radius:50%;background:var(--a1);color:#000;font-family:'JetBrains Mono',monospace;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;margin-bottom:10px;}
.step-title{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:#fff;margin-bottom:6px;}
.step-desc{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:1.8;}
.step-arrow{position:absolute;right:-8px;top:50%;transform:translateY(-50%);color:var(--border);font-size:18px;z-index:1;}

.plan-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:8px;}
.plan-card{background:var(--dim);border-radius:14px;padding:22px;border:1.5px solid var(--border);position:relative;transition:border-color .2s,transform .15s;}
.plan-card:hover{transform:translateY(-2px);}
.plan-card.featured{border-color:var(--a1);}
.plan-card-badge{position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:var(--a1);color:#000;font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;letter-spacing:1px;padding:4px 14px;border-radius:20px;white-space:nowrap;}
.plan-card-name{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;margin-bottom:4px;}
.plan-card-price{font-family:'Syne',sans-serif;font-size:30px;font-weight:900;color:#fff;margin:12px 0 4px;}
.plan-card-price span{font-size:14px;color:var(--muted);font-weight:400;}
.plan-card-feat{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:2;margin-bottom:16px;}
.plan-card-feat li{list-style:none;padding:0;}
.plan-card-feat li::before{content:'✓ ';color:var(--a2);}

.addon-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;}
.addon-card{background:var(--dim);border-radius:10px;padding:14px 16px;border-left:3px solid;display:flex;align-items:center;justify-content:space-between;gap:12px;}
.addon-icon{font-size:20px;line-height:1;}
.addon-name{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--text);}
.addon-date{font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-top:3px;}

.activate-area{background:var(--dim);border-radius:14px;padding:24px;border:1.5px solid var(--border);}
.activate-title{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:#fff;margin-bottom:6px;}
.activate-sub{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-bottom:20px;line-height:1.7;}
</style>
</head>
<body>
<div class="dash-layout">
<?php include __DIR__.'/_sidebar.php'; ?>
<div class="dash-main">
<div class="dash-topbar">
  <div class="dash-page-title">Billing & Plan</div>
</div>
<div class="dash-content">

<?php if($activePromo && $user['plan'] !== 'lifetime'): ?>
<div class="info-box" style="background:linear-gradient(135deg,rgba(251,191,36,.08),rgba(251,146,60,.06));border-color:rgba(251,191,36,.2);margin-bottom:24px;position:relative;">
  <div style="position:absolute;top:-9px;right:16px;background:var(--a1);color:#000;padding:3px 12px;font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;letter-spacing:1.5px;border-radius:20px;">LIMITED OFFER</div>
  <div style="font-family:'Syne',sans-serif;font-size:16px;font-weight:700;color:var(--a1);margin-bottom:6px;">
    <?= (int)$activePromo['discount_percent'] ?>% OFF — use code <span style="background:var(--a1);color:#000;padding:3px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:13px;margin:0 4px;"><?= htmlspecialchars($activePromo['code']) ?></span> at checkout
  </div>
  <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);">
    Valid until <?= $activePromo['valid_until'] ? date('F j, Y', strtotime($activePromo['valid_until'])) : 'further notice' ?>
  </div>
</div>
<?php endif; ?>

<!-- PLAN HERO -->
<div class="billing-hero">
  <div style="position:relative;z-index:1;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:20px;">
    <div>
      <div class="plan-icon-big"><?= $currentPlanIcon ?></div>
      <div class="plan-name-big">
        <?= ucfirst($user['plan']) ?>
        <span class="plan-tag"><?= $user['billing_cycle'] ?? 'free' ?></span>
      </div>
      <div class="plan-meta-row">
        <div class="plan-meta-item">
          <strong><?= $quota['unlimited'] ? '∞' : number_format($quota['limit']) ?></strong> rows/mo
        </div>
        <?php if($quota['rollover'] > 0): ?>
        <div class="plan-meta-item">
          <strong>+<?= number_format($quota['rollover']) ?></strong> rollover
        </div>
        <?php endif; ?>
        <?php if($user['plan'] !== 'free' && $user['plan_expires_at']): ?>
        <div class="plan-meta-item">
          <?= $user['billing_cycle'] === 'lifetime' ? 'Never expires' : 'Renews '.date('M j, Y', strtotime($user['plan_expires_at'])) ?>
        </div>
        <?php endif; ?>
        <?php if($user['plan'] === 'free'): ?>
        <div class="plan-meta-item">
          <strong><?= $quota['remaining'] ?></strong> of 20 rows remaining
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:10px;align-items:flex-end;">
      <?php if($user['plan'] !== 'lifetime'): ?>
      <a href="/landing/pricing.php" class="btn btn-amber" style="white-space:nowrap;">Upgrade Plan</a>
      <?php else: ?>
      <div class="badge badge-ok" style="font-size:12px;padding:8px 16px;">Lifetime Member</div>
      <?php endif; ?>
      <?php if(!empty($activeAddons)): ?>
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);"><?= count($activeAddons) ?> add-on<?= count($activeAddons) !== 1 ? 's' : '' ?> active</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- HOW IT WORKS STEPS -->
<div class="card" style="margin-bottom:24px;">
  <div class="card-title" style="margin-bottom:16px;">How to Activate a License</div>
  <div class="steps-flow">
    <div class="step-item">
      <div class="step-num">1</div>
      <div class="step-title">Buy on Gumroad</div>
      <div class="step-desc">Purchase a plan (Pro, Platinum, Lifetime) or any add-on. Gumroad sends a license key to your email immediately after payment.</div>
    </div>
    <div class="step-item">
      <div class="step-num">2</div>
      <div class="step-title">Copy Your Key</div>
      <div class="step-desc">Open the Gumroad receipt email. Find the license key — it looks like<br><code style="color:var(--a1);">XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX</code></div>
    </div>
    <div class="step-item">
      <div class="step-num">3</div>
      <div class="step-title">Paste & Activate</div>
      <div class="step-desc">Paste the key in the activation box below and click the Activate button. Your plan upgrades instantly.</div>
    </div>
  </div>
  <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);padding:10px 14px;background:var(--bg);border-radius:8px;border:1px solid var(--border);">
    System keys are also accepted — format: <code style="color:var(--a2);">PRO-M-XXXXXXXX-XXXX</code> — sent to you directly by email support.
  </div>
</div>

<!-- ACTIVATE LICENSE -->
<div class="card" style="margin-bottom:24px;">
  <div class="card-title" style="margin-bottom:4px;">Activate License Key</div>
  <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-bottom:20px;">Paste your Gumroad license key or system key below to activate your plan or add-on.</div>

  <div id="activationMessage"></div>

  <div class="activate-area">
    <div class="key-input-wrap">
      <input type="text" id="licenseKey" class="key-input"
        placeholder="XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX"
        autocomplete="off" spellcheck="false" maxlength="64">
      <div id="keyTypeBadge" class="key-type-badge"></div>
    </div>

    <div class="key-segments">
      <div class="key-seg" id="seg0"></div>
      <div class="key-seg" id="seg1"></div>
      <div class="key-seg" id="seg2"></div>
      <div class="key-seg" id="seg3"></div>
    </div>

    <div style="display:flex;gap:12px;margin-top:16px;align-items:stretch;flex-wrap:wrap;">
      <button class="btn btn-amber" id="activateBtn" disabled style="min-width:150px;">
        <span id="activateBtnText">Activate</span>
      </button>
      <button class="btn" id="clearBtn" style="background:var(--bg);border:1px solid var(--border);color:var(--muted);">Clear</button>
    </div>
  </div>

  <div id="userLicenses" style="margin-top:24px;"></div>

  <div style="margin-top:16px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);">
    Need help? <a href="<?= htmlspecialchars(SUPPORT_TELEGRAM_URL) ?>" target="_blank" style="color:var(--a2);text-decoration:none;font-weight:600;">Contact Support on Telegram</a>
  </div>
</div>

<?php if(!empty($activeAddons)): ?>
<!-- ACTIVE ADD-ONS -->
<div class="card" style="margin-bottom:24px;">
  <div class="card-title" style="margin-bottom:16px;">Active Add-ons</div>
  <div class="addon-grid">
    <?php foreach($activeAddons as $addon):
      $slug = $addon['addon_slug'];
      $meta = $addonMeta[$slug] ?? ['name'=>ucwords(str_replace('-',' ',$slug)),'icon'=>'◎','color'=>'var(--a2)'];
    ?>
    <div class="addon-card" style="border-left-color:<?= $meta['color'] ?>;">
      <div>
        <div style="font-size:22px;color:<?= $meta['color'] ?>;margin-bottom:6px;"><?= $meta['icon'] ?></div>
        <div class="addon-name"><?= htmlspecialchars($meta['name']) ?></div>
        <div class="addon-date">Activated <?= date('M j, Y', strtotime($addon['purchased_at'])) ?></div>
      </div>
      <div style="padding:4px 10px;background:rgba(0,212,170,.1);border:1px solid rgba(0,212,170,.25);border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;color:var(--a2);text-transform:uppercase;white-space:nowrap;">Active</div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if($user['plan'] !== 'lifetime'): ?>
<!-- PLAN COMPARISON -->
<div class="card">
  <div class="card-title" style="margin-bottom:16px;">Available Plans</div>
  <div class="plan-cards">
    <div class="plan-card">
      <div class="plan-card-name" style="color:var(--a2);">Pro</div>
      <div class="plan-card-price">$<?= PLAN_DATA['pro']['pm'] ?><span>/mo</span></div>
      <ul class="plan-card-feat">
        <li>500 rows per month</li>
        <li>Rollover unused rows</li>
        <li>Priority processing</li>
        <li>Email support</li>
      </ul>
      <a href="https://bulkreplacetool.gumroad.com/l/<?= PLAN_DATA['pro']['gumroad_monthly'] ?>" target="_blank" class="btn" style="background:rgba(0,212,170,.1);border:1px solid rgba(0,212,170,.3);color:var(--a2);width:100%;text-align:center;display:block;">Buy on Gumroad</a>
    </div>

    <div class="plan-card featured">
      <div class="plan-card-badge">MOST POPULAR</div>
      <div class="plan-card-name" style="color:var(--a1);">Platinum</div>
      <div class="plan-card-price">$<?= PLAN_DATA['platinum']['pm'] ?><span>/mo</span></div>
      <ul class="plan-card-feat">
        <li>1,500 rows per month</li>
        <li>Rollover unused rows</li>
        <li>All Pro features</li>
        <li>Telegram support</li>
      </ul>
      <a href="https://bulkreplacetool.gumroad.com/l/<?= PLAN_DATA['platinum']['gumroad_monthly'] ?>" target="_blank" class="btn btn-amber" style="width:100%;text-align:center;display:block;">Buy on Gumroad</a>
    </div>

    <div class="plan-card">
      <div class="plan-card-name" style="color:#c084fc;">Lifetime</div>
      <div class="plan-card-price" style="font-size:22px;">One-time<span> payment</span></div>
      <ul class="plan-card-feat">
        <li>Unlimited rows forever</li>
        <li>All future updates</li>
        <li>All add-ons included</li>
        <li>Priority support</li>
      </ul>
      <a href="/landing/pricing.php#lifetime" class="btn" style="background:rgba(192,132,252,.1);border:1px solid rgba(192,132,252,.3);color:#c084fc;width:100%;text-align:center;display:block;">View Lifetime Deal</a>
    </div>
  </div>
  <?php if($activePromo): ?>
  <div style="text-align:center;margin-top:12px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--a1);">
    Use code <strong><?= htmlspecialchars($activePromo['code']) ?></strong> for <?= (int)$activePromo['discount_percent'] ?>% off at checkout
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

</div>
</div>
</div>

<script>
const keyInput    = document.getElementById('licenseKey');
const activateBtn = document.getElementById('activateBtn');
const clearBtn    = document.getElementById('clearBtn');
const segments    = [0,1,2,3].map(i => document.getElementById('seg'+i));
const typeBadge   = document.getElementById('keyTypeBadge');
const msgDiv      = document.getElementById('activationMessage');

function detectKeyType(val) {
    const cleaned = val.replace(/\s/g,'').toUpperCase();
    if (/^[A-Z0-9]{8}-[A-Z0-9]{8}-[A-Z0-9]{8}-[A-Z0-9]{8}$/.test(cleaned)) return 'gumroad';
    if (/^(PRO|PLT|LIFE|ADDON)-[A-Z]-[A-Z0-9]{8}-[A-Z0-9]{4}$/.test(cleaned)) return 'system';
    return null;
}

function getSegmentFill(val) {
    const cleaned = val.replace(/[\s\-]/g,'').toUpperCase();
    const filled  = Math.min(4, Math.floor(cleaned.length / 2));
    return filled;
}

function updateKeyUI() {
    const val     = keyInput.value.trim().toUpperCase();
    const keyType = detectKeyType(val);
    const parts   = val.split('-').filter(p => p.length > 0);
    const isGumroad = /^[A-Z0-9]+-[A-Z0-9]+-[A-Z0-9]+-[A-Z0-9]+/.test(val);

    segments.forEach((seg, i) => {
        seg.className = 'key-seg';
        const partLen = (parts[i] || '').length;
        if (partLen >= 8) seg.classList.add(keyType ? 'complete' : 'filled');
        else if (partLen > 0) seg.classList.add('filled');
    });

    if (keyType === 'gumroad') {
        keyInput.className = 'key-input valid';
        typeBadge.className = 'key-type-badge gumroad show';
        typeBadge.textContent = 'Gumroad License Key';
    } else if (keyType === 'system') {
        keyInput.className = 'key-input valid';
        typeBadge.className = 'key-type-badge system show';
        typeBadge.textContent = 'System Key';
    } else if (val.length > 5) {
        keyInput.className = 'key-input';
        typeBadge.className = 'key-type-badge';
    } else {
        keyInput.className = 'key-input';
        typeBadge.className = 'key-type-badge';
    }

    activateBtn.disabled = !keyType;
}

keyInput.addEventListener('input', updateKeyUI);

keyInput.addEventListener('paste', (e) => {
    setTimeout(() => {
        keyInput.value = keyInput.value.trim().toUpperCase();
        updateKeyUI();
    }, 10);
});

clearBtn.addEventListener('click', () => {
    keyInput.value = '';
    keyInput.className = 'key-input';
    typeBadge.className = 'key-type-badge';
    segments.forEach(s => s.className = 'key-seg');
    activateBtn.disabled = true;
    msgDiv.innerHTML = '';
    keyInput.focus();
});

document.getElementById('licenseKey').closest('.activate-area').addEventListener('submit', e => e.preventDefault());

activateBtn.addEventListener('click', async () => {
    const licenseKey = keyInput.value.trim();
    if (!licenseKey) return;

    activateBtn.disabled = true;
    document.getElementById('activateBtnText').textContent = 'Activating...';
    msgDiv.innerHTML = '';

    try {
        const res = await fetch('/api/activate_license.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ license_key: licenseKey })
        });
        const result = await res.json();

        if (result.success) {
            let sysKeyBox = '';
            if (result.system_license_key) {
                sysKeyBox = `
                  <div style="margin-top:12px;padding:12px 16px;background:rgba(240,165,0,.06);border:1px solid rgba(240,165,0,.2);border-radius:8px;">
                    <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--a1);font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;">Save Your System Key</div>
                    <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#fff;background:var(--bg);padding:10px 12px;border-radius:6px;word-break:break-all;border:1px solid var(--border);">${result.system_license_key}</div>
                    <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:6px;">Both keys can be used for future activations.</div>
                  </div>`;
            }
            msgDiv.innerHTML = `
              <div class="suc-box" style="margin-bottom:16px;">
                <strong>${result.product_name || 'License'}</strong> activated successfully! Reloading in 3 seconds...
                ${sysKeyBox}
              </div>`;
            keyInput.className = 'key-input valid';
            setTimeout(() => location.reload(), 3000);
        } else {
            msgDiv.innerHTML = `<div class="err-box" style="margin-bottom:16px;">${result.error || 'Activation failed. Please check your key and try again.'}</div>`;
            keyInput.className = 'key-input invalid';
            activateBtn.disabled = false;
            document.getElementById('activateBtnText').textContent = 'Activate';
        }
    } catch (err) {
        msgDiv.innerHTML = '<div class="err-box" style="margin-bottom:16px;">Network error. Check your connection and try again.</div>';
        activateBtn.disabled = false;
        document.getElementById('activateBtnText').textContent = 'Activate';
    }
});

async function loadUserLicenses() {
    try {
        const res = await fetch('/api/verify_license.php?t=' + Date.now(), { credentials: 'include' });
        const result = await res.json();
        if (!result.success || !result.licenses || result.licenses.length === 0) return;

        const div = document.getElementById('userLicenses');
        let html = '<div style="border-top:1px solid var(--border);padding-top:20px;">';
        html += '<div style="font-family:\'Syne\',sans-serif;font-size:16px;font-weight:700;color:var(--text);margin-bottom:14px;">Your Licenses</div>';
        html += '<div style="display:grid;gap:10px;">';

        result.licenses.forEach(license => {
            const isActive   = license.status === 'active';
            const isRevoked  = license.status === 'revoked';
            const statusCol  = isActive ? 'var(--a2)' : isRevoked ? 'var(--err)' : 'var(--muted)';
            const statusIcon = isActive ? '✓' : isRevoked ? '✗' : '○';
            const expiryText = license.expires_at
                ? 'Expires ' + new Date(license.expires_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})
                : 'Lifetime';

            html += `
              <div style="background:var(--bg);border-radius:10px;padding:14px 18px;border:1px solid var(--border);border-left:3px solid ${statusCol};display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div style="flex:1;min-width:200px;">
                  <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--text);margin-bottom:6px;">${statusIcon} ${license.product_name || 'License'}</div>
                  <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:${statusCol};background:var(--dim);padding:5px 10px;border-radius:5px;display:inline-block;word-break:break-all;margin-bottom:4px;">${license.license_key || '—'}</div>
                  <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);">
                    ${new Date(license.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})} &bull; ${expiryText}
                  </div>
                </div>
                <div style="padding:4px 10px;background:${isActive?'rgba(0,212,170,.1)':'var(--dim)'};border:1px solid ${statusCol};border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;color:${statusCol};text-transform:uppercase;">${license.status}</div>
              </div>`;
        });

        html += '</div></div>';
        div.innerHTML = html;
    } catch(e) {}
}

loadUserLicenses();
</script>
</body>
</html>
