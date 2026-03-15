<?php
$u   = currentUser();
$cur = basename($_SERVER['PHP_SELF']);
$plan = $u['plan'] ?? 'free';
$hasPremium = !in_array($plan, ['free','pro']);
$currentLang = $_SESSION['lang'] ?? 'en';
$currentPath = $_SERVER['REQUEST_URI'];
?>
<div class="sidebar">
  <div class="sidebar-logo">
    <a href="/dashboard/">
      <img src="/img/logo.png" alt="BulkReplace" class="sidebar-logo-img">
      <span class="sidebar-logo-text">BulkReplace</span>
    </a>
  </div>
  <div class="sidebar-nav">
    <div class="sidebar-sect">Main</div>
    <a href="/dashboard/" class="<?= $cur==='index.php'?'active':'' ?>">
      <span class="sicon">🏠</span>Dashboard
    </a>
    <a href="/tool/" class="<?= $cur==='tool'?'active':'' ?>">
      <span class="sicon">⚡</span>Run Tool
    </a>

    <div class="sidebar-sect">🚀 AUTOPILOT</div>
    <?php
    $hasAutopilot = ($plan==='lifetime' && hasAddonAccess((int)$u['id'],'autopilot'));
    $isLifetime   = ($plan==='lifetime');
    $telegramUrl  = defined('SUPPORT_TELEGRAM_URL') ? SUPPORT_TELEGRAM_URL : 'https://t.me/scytheinhere';
    ?>
    <?php if($hasAutopilot): ?>
      <a href="/dashboard/autopilot.php" class="<?= $cur==='autopilot.php'?'active':'' ?> sidebar-link-special">
        <span class="sicon">🤖</span>Autopilot
        <span class="sidebar-badge-new">NEW</span>
      </a>
    <?php elseif($isLifetime): ?>
      <a href="<?= $telegramUrl ?>" target="_blank" class="sidebar-link-locked-lifetime" title="Chat admin on Telegram to activate Autopilot">
        <span class="sicon">🤖</span>Autopilot
        <span class="sidebar-badge-chat">CHAT<br>ADMIN</span>
      </a>
    <?php else: ?>
      <a href="<?= $telegramUrl ?>" target="_blank" class="sidebar-link-locked" title="Lifetime plan required — chat admin @scytheinhere to subscribe">
        <span class="sicon">🤖</span>Autopilot
        <span class="sidebar-badge-lock">🔒</span>
      </a>
    <?php endif; ?>

    <div class="sidebar-sect">Premium Tools</div>
    <?php if(!$hasPremium): ?>
      <a href="/landing/pricing.php?addon=csv" class="sidebar-link-locked">
        <span class="sicon">📊</span>CSV Generator
        <span class="sidebar-badge-lock">🔒</span>
      </a>
      <a href="/landing/pricing.php?addon=zip" class="sidebar-link-locked">
        <span class="sicon">🗜️</span>ZIP Manager
        <span class="sidebar-badge-lock">🔒</span>
      </a>
      <a href="/landing/pricing.php?addon=premium" class="sidebar-link-locked">
        <span class="sicon">📁</span>Copy & Rename
        <span class="sidebar-badge-lock">🔒</span>
      </a>
    <?php else: ?>
      <a href="/dashboard/csv_generator.php" class="<?= $cur==='csv_generator.php'?'active':'' ?>">
        <span class="sicon">📊</span>CSV Generator
      </a>
      <a href="/dashboard/zip_manager.php" class="<?= $cur==='zip_manager.php'?'active':'' ?>">
        <span class="sicon">🗜️</span>ZIP Manager
      </a>
      <a href="/dashboard/copy_rename.php" class="<?= $cur==='copy_rename.php'?'active':'' ?>">
        <span class="sicon">📁</span>Copy & Rename
      </a>
    <?php endif; ?>

    <div class="sidebar-sect">Account</div>
    <a href="/dashboard/billing.php" class="<?= $cur==='billing.php'?'active':'' ?>">
      <span class="sicon">💳</span>Billing & Plan
    </a>
    <a href="/dashboard/analytics.php" class="<?= $cur==='analytics.php'?'active':'' ?>">
      <span class="sicon">📊</span>Usage Analytics
    </a>
    <a href="/dashboard/security.php" class="<?= $cur==='security.php'?'active':'' ?>">
      <span class="sicon">🔐</span>Security Settings
    </a>

    <?php if($isLifetime): ?>
      <a href="/dashboard/api_access.php" class="<?= $cur==='api_access.php'?'active':'' ?>">
        <span class="sicon">🔑</span>API Access
      </a>
    <?php else: ?>
      <a href="javascript:void(0)" onclick="showApiUpgradeModal()" class="sidebar-link-locked" title="API Access — Lifetime Plan Only">
        <span class="sicon">🔑</span>API Access
        <span class="sidebar-badge-lock">🔒</span>
      </a>
    <?php endif; ?>
    <a href="<?= SUPPORT_TELEGRAM_URL ?>" target="_blank">
      <span class="sicon">💬</span>Telegram Support
    </a>

    <div class="sidebar-sect">Learn</div>
    <a href="/landing/tutorial.php">
      <span class="sicon">📖</span>Tutorial
    </a>
    <a href="/landing/pricing.php">
      <span class="sicon">💰</span>Plans & Pricing
    </a>
  </div>

  <div class="sidebar-user">
    <div class="su-row">
      <div class="su-avatar"><?= strtoupper(substr($u['name']??'U',0,1)) ?></div>
      <div>
        <div class="su-name"><?= htmlspecialchars($u['name']??'User') ?></div>
        <div class="su-plan"><span class="plan-pill pp-<?= $plan ?>"><?= ucfirst($plan) ?></span></div>
      </div>
    </div>
    <a href="/auth/logout.php" class="su-logout">⎋ Sign Out</a>
  </div>
</div>

<!-- API UPGRADE MODAL -->
<div id="api-upgrade-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.85);backdrop-filter:blur(8px);align-items:center;justify-content:center;">
  <div style="background:var(--card);border:1px solid var(--border);border-radius:16px;padding:32px;max-width:500px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.5);">
    <div style="text-align:center;margin-bottom:24px;">
      <div style="font-size:64px;margin-bottom:16px;">🔒</div>
      <h2 style="font-family:'Syne',sans-serif;font-size:24px;font-weight:800;color:#fff;margin-bottom:8px;">API Access</h2>
      <div style="display:inline-block;padding:4px 12px;background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#000;border-radius:20px;font-size:11px;font-weight:700;margin-bottom:16px;">LIFETIME PLAN ONLY</div>
      <p style="color:var(--muted);font-size:14px;line-height:1.6;">
        Integrate BulkReplace CSV Generator with your applications via RESTful API.
      </p>
    </div>

    <div style="background:rgba(240,165,0,0.08);border:1px solid rgba(240,165,0,0.2);border-radius:10px;padding:16px;margin-bottom:20px;">
      <div style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--a1);margin-bottom:8px;font-weight:700;">✨ What You Get</div>
      <ul style="color:var(--muted);font-size:13px;line-height:2;list-style:none;padding-left:0;">
        <li>✓ RESTful API endpoint</li>
        <li>✓ 100 requests/hour</li>
        <li>✓ API key authentication</li>
        <li>✓ Job status tracking</li>
        <li>✓ CSV/JSON export formats</li>
      </ul>
    </div>

    <div style="display:flex;gap:10px;margin-top:24px;">
      <a href="/dashboard/billing.php" class="btn btn-amber" style="flex:1;justify-content:center;text-decoration:none;">
        🚀 Upgrade to Lifetime
      </a>
      <a href="<?= SUPPORT_TELEGRAM_URL ?>" target="_blank" class="btn btn-ghost" style="flex:1;justify-content:center;text-decoration:none;">
        💬 Chat Admin
      </a>
    </div>

    <button onclick="closeApiUpgradeModal()" class="btn btn-ghost" style="width:100%;margin-top:10px;justify-content:center;">
      Close
    </button>
  </div>
</div>

<script>
function showApiUpgradeModal() {
  const modal = document.getElementById('api-upgrade-modal');
  if (modal) {
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
}

function closeApiUpgradeModal() {
  const modal = document.getElementById('api-upgrade-modal');
  if (modal) {
    modal.style.display = 'none';
    document.body.style.overflow = '';
  }
}

// Close on ESC key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeApiUpgradeModal();
});

// Close on backdrop click
document.getElementById('api-upgrade-modal')?.addEventListener('click', function(e) {
  if (e.target === this) closeApiUpgradeModal();
});
</script>
