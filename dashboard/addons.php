<?php
require_once dirname(__DIR__).'/config.php';
requireLogin();

$u       = currentUser();
$uid     = (int)$u['id'];
$plan    = $u['plan'] ?? 'free';
$planData= getPlan($plan);

// Owned slugs (direct + bundle)
$ownedSlugs = [];
$stmt = db()->prepare("SELECT a.slug FROM user_addons ua JOIN addons a ON ua.addon_id=a.id WHERE ua.user_id=? AND ua.is_active=1");
$stmt->execute([$uid]);
$directOwned = array_column($stmt->fetchAll(),'slug');

// Bundle expands
foreach($directOwned as $s){
    if(!empty(ADDON_DATA[$s]['includes'])){
        foreach(ADDON_DATA[$s]['includes'] as $inc) $ownedSlugs[] = $inc;
    }
    $ownedSlugs[] = $s;
}
// has_addons plan unlocks non-separate addons
if($planData['has_addons']??false){
    foreach(ADDON_DATA as $s=>$a){
        if(!in_array($s, ADDON_ALWAYS_SEPARATE)) $ownedSlugs[] = $s;
    }
}
$ownedSlugs = array_unique($ownedSlugs);

$isLifetime  = ($plan === 'lifetime');
$hasAutopilot= in_array('autopilot', $ownedSlugs) || hasAddonAccess($uid,'autopilot');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Add-ons — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>
/* ── ADDONS PAGE ────────────────────────────────────── */
.addons-wrap{max-width:1100px;margin:0 auto;}
.addons-hero{text-align:center;padding:48px 0 36px;}
.addons-hero h1{font-size:clamp(24px,3vw,36px);font-weight:900;color:#fff;margin-bottom:8px;}
.addons-hero p{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);line-height:2;}

/* Grid for regular addons */
.addon-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:32px;}
@media(max-width:900px){.addon-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:560px){.addon-grid{grid-template-columns:1fr;}}

.addon-card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:26px;display:flex;flex-direction:column;position:relative;transition:all .2s;}
.addon-card:hover{border-color:var(--border2);transform:translateY(-2px);}
.addon-card.owned{border-color:rgba(0,230,118,.25);background:linear-gradient(160deg,rgba(0,230,118,.04),transparent);}
.addon-card.locked{opacity:.6;filter:grayscale(.3);}

.ac-icon{font-size:32px;margin-bottom:14px;}
.ac-name{font-family:'Syne',sans-serif;font-size:17px;font-weight:800;color:#fff;margin-bottom:6px;}
.ac-desc{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);line-height:1.85;flex:1;margin-bottom:16px;}
.ac-features{margin-bottom:16px;}
.ac-features div{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text);padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04);display:flex;gap:7px;}
.ac-features div:last-child{border-bottom:none;}
.ac-price{font-family:'Syne',sans-serif;font-size:22px;font-weight:900;color:#fff;margin-bottom:14px;}
.ac-price span{font-size:12px;color:var(--muted);font-weight:400;}
.ac-owned-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(0,230,118,.1);border:1px solid rgba(0,230,118,.25);border-radius:8px;padding:8px 14px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--ok);font-weight:700;width:100%;justify-content:center;}
.ac-lock-msg{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:8px;padding:8px 14px;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);width:100%;justify-content:center;}

/* Plan badge in corner */
.plan-corner-badge{position:absolute;top:-10px;left:50%;transform:translateX(-50%);font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:1.5px;padding:3px 12px;border-radius:100px;white-space:nowrap;}
.pcb-ok{background:rgba(0,230,118,.15);border:1px solid rgba(0,230,118,.3);color:var(--ok);}
.pcb-lock{background:rgba(255,255,255,.05);border:1px solid var(--border);color:var(--muted);}

/* Bundle card */
.bundle-addon-card{background:linear-gradient(135deg,rgba(240,165,0,.06),rgba(0,212,170,.04));border:1px solid rgba(240,165,0,.25);border-radius:20px;padding:32px;margin-bottom:32px;position:relative;}
.bundle-addon-card.owned{border-color:rgba(0,230,118,.3);background:linear-gradient(135deg,rgba(0,230,118,.05),rgba(0,212,170,.03));}
.bundle-items-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:16px 0;}
.bundle-item-chip{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:10px 16px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text);display:flex;align-items:center;gap:8px;}
.bundle-plus-sign{color:var(--muted);font-size:18px;font-weight:300;}

/* Autopilot exclusive card */
.ap-addon-card{background:linear-gradient(135deg,rgba(240,165,0,.07),rgba(192,132,252,.04));border:1px solid rgba(240,165,0,.3);border-radius:24px;padding:36px;position:relative;overflow:hidden;margin-bottom:40px;}
.ap-addon-card::before{content:'';position:absolute;top:-80px;right:-80px;width:300px;height:300px;background:radial-gradient(circle,rgba(240,165,0,.06),transparent 70%);pointer-events:none;}
.ap-addon-card.owned{border-color:rgba(0,230,118,.35);background:linear-gradient(135deg,rgba(0,230,118,.06),rgba(0,212,170,.03));}
.ap-inner{display:grid;grid-template-columns:1fr auto;gap:32px;align-items:flex-start;}
@media(max-width:700px){.ap-inner{grid-template-columns:1fr;}}
.ap-features-list{list-style:none;}
.ap-features-list li{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--text);padding:7px 0;border-bottom:1px solid rgba(255,255,255,.04);display:flex;gap:8px;}
.ap-features-list li:last-child{border-bottom:none;}
.ap-features-list li .ck{color:var(--ok);flex-shrink:0;}
.ap-price-box{background:rgba(0,0,0,.35);border:1px solid rgba(240,165,0,.2);border-radius:16px;padding:24px 28px;text-align:center;min-width:180px;}
.ap-price-box.owned-box{border-color:rgba(0,230,118,.3);background:rgba(0,230,118,.05);}
.ap-pval{font-family:'Syne',sans-serif;font-size:44px;font-weight:900;color:#fff;line-height:1;}
.ap-pval sup{font-size:18px;color:var(--a1);vertical-align:super;}
.ap-pper{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:4px;}
.ap-req-badge{background:rgba(192,132,252,.08);border:1px solid rgba(192,132,252,.2);border-radius:8px;padding:6px 12px;font-family:'JetBrains Mono',monospace;font-size:9px;color:#c084fc;margin-top:10px;letter-spacing:.5px;}
.ap-need-lifetime{background:rgba(255,69,96,.07);border:1px solid rgba(255,69,96,.2);border-radius:10px;padding:12px 16px;font-family:'JetBrains Mono',monospace;font-size:11px;color:#ff8888;line-height:1.8;margin-top:12px;}
</style>
</head>
<body>
<?php include __DIR__.'/_sidebar.php'; ?>
<div class="dash-main">
<?php include __DIR__.'/_topbar.php'; ?>
<div class="dash-content">
<div class="addons-wrap">

  <!-- Hero -->
  <div class="addons-hero">
    <h1>Premium Add-ons</h1>
    <p>Unlock powerful features to supercharge your bulk workflow.</p>
    <?php if($planData['has_addons']??false): ?>
    <div style="margin-top:12px;" class="info-box" style="display:inline-block;">
      Your <strong><?= $planData['name'] ?></strong> plan includes all standard add-ons for free.
    </div>
    <?php endif; ?>
  </div>

  <!-- ═══════════════════════════════════════════════════
       AUTOPILOT — Lifetime exclusive, shown first
       ═══════════════════════════════════════════════════ -->
  <div class="ap-addon-card <?= $hasAutopilot ? 'owned' : '' ?>">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:2px;background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.3);border-radius:100px;padding:4px 14px;color:var(--a1);">
        <?= $hasAutopilot ? '✓ OWNED' : 'LIFETIME EXCLUSIVE' ?>
      </div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:2px;background:rgba(192,132,252,.08);border:1px solid rgba(192,132,252,.2);border-radius:100px;padding:4px 14px;color:#c084fc;">
        BOT-POWERED
      </div>
    </div>

    <div class="ap-inner">
      <div>
        <div style="font-family:'Syne',sans-serif;font-size:clamp(22px,2.5vw,32px);font-weight:900;color:#fff;margin-bottom:10px;letter-spacing:-1px;">
          🤖 Autopilot
          <span style="background:linear-gradient(135deg,#f0a500,#c084fc);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;">Add-on</span>
        </div>
        <p style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);line-height:2;margin-bottom:20px;max-width:480px;">
          Pick 1 template folder + drop 100 domains = 100 ready websites written directly to your PC.
          Bulk Replace Bot reads your template, detects every location value, fetches real data per domain, and replaces everything automatically.
        </p>

        <ul class="ap-features-list">
          <li><span class="ck">✓</span> Bulk Replace Bot detects domain, address, phone, email, maps — any template type</li>
          <li><span class="ck">✓</span> Google Places API — real coordinates + Maps embed per domain</li>
          <li><span class="ck">✓</span> Files written directly to your PC (no ZIP, no upload)</li>
          <li><span class="ck">✓</span> Auto case-variants: Balikpapan / balikpapan / BALIKPAPAN</li>
          <li><span class="ck">✓</span> HTML-encoded URL handling — maps embed always replaced</li>
          <li><span class="ck">✓</span> 2-pass verification — zero missed replacements</li>
          <li><span class="ck">✓</span> Unlimited domains per session</li>
          <li><span class="ck">✓</span> ~$0.03 per session (Claude API, flat rate any domain count)</li>
        </ul>

        <!-- API cost info -->
        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:20px;padding:16px;background:rgba(0,0,0,.25);border-radius:12px;border:1px solid var(--border);">
          <div>
            <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:900;color:var(--a1);">$0.033</div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);">Claude / session (flat)</div>
          </div>
          <div>
            <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:900;color:var(--ok);">FREE</div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);">Google Maps ($200/mo)</div>
          </div>
          <div>
            <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:900;color:var(--a2);">$0</div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);">Replace + Write to PC</div>
          </div>
        </div>
      </div>

      <!-- Price box + CTA -->
      <div class="ap-price-box <?= $hasAutopilot ? 'owned-box' : '' ?>">
        <?php if($hasAutopilot): ?>
          <div style="font-size:36px;margin-bottom:10px;">✅</div>
          <div style="font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--ok);margin-bottom:4px;">Owned</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-bottom:16px;">Lifetime access active</div>
          <a href="/dashboard/autopilot.php" class="btn btn-teal" style="width:100%;justify-content:center;padding:12px;">
            Open Autopilot →
          </a>
        <?php elseif(!$isLifetime): ?>
          <div class="ap-pval"><sup>$</sup>99.9</div>
          <div class="ap-pper">one-time · lifetime access</div>
          <div class="ap-req-badge">REQUIRES LIFETIME PLAN</div>
          <div class="ap-need-lifetime">
            You need the <strong style="color:#fff;">Lifetime plan</strong> to unlock Autopilot.<br>
            <a href="/landing/pricing.php" style="color:var(--a1);text-decoration:underline;">Upgrade to Lifetime →</a>
          </div>
        <?php else: ?>
          <div class="ap-pval"><sup>$</sup>99.9</div>
          <div class="ap-pper">one-time · lifetime access</div>
          <div class="ap-req-badge" style="margin-bottom:16px;">LIFETIME PLAN ✓</div>
          <a href="https://bulkreplacetool.lemonsqueezy.com/checkout/buy/<?= ADDON_DATA['autopilot']['lemon_variant'] ?>?embed=1&checkout[custom][user_id]=<?= $uid ?>"
             class="btn btn-amber lemonsqueezy-button"
             style="width:100%;justify-content:center;padding:13px;font-size:14px;box-shadow:0 6px 24px rgba(240,165,0,.3);">
            Get Autopilot — $99.9
          </a>
          <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-top:8px;text-align:center;">One-time · Permanent access</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════
       BUNDLE
       ═══════════════════════════════════════════════════ -->
  <?php
  $bundleOwned = in_array('premium-bundle',$directOwned)
    || (($planData['has_addons']??false) && !in_array('premium-bundle',ADDON_ALWAYS_SEPARATE));
  $bundleData  = ADDON_DATA['premium-bundle'];
  ?>
  <div class="bundle-addon-card <?= $bundleOwned ? 'owned' : '' ?>">
    <?php if($bundleOwned): ?>
      <div style="position:absolute;top:-10px;left:50%;transform:translateX(-50%);" class="plan-corner-badge pcb-ok">✓ Owned</div>
    <?php else: ?>
      <div style="position:absolute;top:-10px;left:50%;transform:translateX(-50%);" class="plan-corner-badge" style="background:linear-gradient(135deg,#f0a500,#c47d00);color:#000;">🔥 BEST VALUE</div>
    <?php endif; ?>

    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;">
      <div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:2px;color:var(--a1);margin-bottom:8px;">ALL-IN-ONE BUNDLE</div>
        <div style="font-family:'Syne',sans-serif;font-size:22px;font-weight:900;color:#fff;margin-bottom:6px;">💎 Full Add-ons Bundle</div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);">CSV Generator + ZIP Manager + Copy & Rename in one payment</div>
        <div class="bundle-items-row">
          <div class="bundle-item-chip">📊 CSV Generator</div>
          <div class="bundle-plus-sign">+</div>
          <div class="bundle-item-chip">🗜️ ZIP Manager</div>
          <div class="bundle-plus-sign">+</div>
          <div class="bundle-item-chip">📋 Copy & Rename</div>
        </div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);">
          <span style="text-decoration:line-through;">$59.7</span> if bought separately &nbsp;→&nbsp;
          <span style="color:var(--ok);font-weight:700;">Save $9.8</span>
        </div>
      </div>
      <div style="text-align:center;">
        <?php if($bundleOwned): ?>
          <div class="ac-owned-badge" style="width:auto;padding:10px 24px;">✓ Bundle Active</div>
        <?php else: ?>
          <div style="font-family:'Syne',sans-serif;font-size:38px;font-weight:900;color:#fff;margin-bottom:6px;">$49.9</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-bottom:12px;">one-time · all 3 unlocked</div>
          <a href="https://bulkreplacetool.lemonsqueezy.com/checkout/buy/<?= ADDON_DATA['premium-bundle']['lemon_variant'] ?>?embed=1&checkout[custom][user_id]=<?= $uid ?>"
             class="btn btn-amber lemonsqueezy-button"
             style="padding:12px 28px;font-size:14px;box-shadow:0 4px 18px rgba(240,165,0,.3);">
            🔥 Get Bundle — $49.9
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════
       INDIVIDUAL ADDONS
       ═══════════════════════════════════════════════════ -->
  <div style="font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:2px;color:var(--muted);margin-bottom:20px;">
    Individual Add-ons
  </div>
  <div class="addon-grid">
  <?php
  $individuals = ['csv-generator-pro','zip-manager','copy-rename'];
  $colors = [
    'csv-generator-pro' => ['rgba(240,165,0,.25)','rgba(240,165,0,.08)','var(--a1)','#f0a500'],
    'zip-manager'       => ['rgba(0,212,170,.25)','rgba(0,212,170,.08)','var(--a2)','#00d4aa'],
    'copy-rename'       => ['rgba(192,132,252,.25)','rgba(192,132,252,.08)','#c084fc','#c084fc'],
  ];
  $featureMap = [
    'csv-generator-pro' => ['Bot domain data scraping','Real Google Places data','Maps embed URLs','Bulk CSV export','Alamat, notelp, email'],
    'zip-manager'       => ['Bulk UNZIP files','Create ZIP archives','Content search in ZIPs','Parallel processing','No upload needed'],
    'copy-rename'       => ['Bulk folder copy','Domain-based renaming','Pattern rules','Parallel processing','100% local / no upload'],
  ];
  foreach($individuals as $slug):
    $a       = ADDON_DATA[$slug];
    $isOwned = in_array($slug,$ownedSlugs);
    $col     = $colors[$slug];
    $btnGrad = "background:linear-gradient(135deg,{$col[3]},rgba(0,0,0,.3));";
  ?>
  <div class="addon-card <?= $isOwned?'owned':'' ?>" style="<?= $isOwned ? "border-color:{$col[0]};" : '' ?>">
    <?php if($isOwned): ?><div class="plan-corner-badge pcb-ok">✓ Owned</div><?php endif; ?>
    <div class="ac-icon"><?= $a['icon'] ?></div>
    <div class="ac-name"><?= htmlspecialchars($a['name']) ?></div>
    <div class="ac-desc"><?= htmlspecialchars($a['desc']) ?></div>
    <div class="ac-features">
      <?php foreach($featureMap[$slug] as $f): ?>
        <div><span style="color:var(--ok);">✓</span> <?= htmlspecialchars($f) ?></div>
      <?php endforeach; ?>
    </div>
    <div class="ac-price" style="color:<?= $col[2] ?>;">$<?= $a['price'] ?><span> one-time</span></div>
    <?php if($isOwned): ?>
      <div class="ac-owned-badge">✓ Active</div>
    <?php else: ?>
      <a href="https://bulkreplacetool.lemonsqueezy.com/checkout/buy/<?= $a['lemon_variant'] ?>?embed=1&checkout[custom][user_id]=<?= $uid ?>"
         class="btn lemonsqueezy-button"
         style="width:100%;justify-content:center;<?= $btnGrad ?>color:#000;border:none;box-shadow:0 3px 12px rgba(0,0,0,.3);">
        Get <?= htmlspecialchars($a['name']) ?>
      </a>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  </div>

  <!-- Already on has_addons plan notice -->
  <?php if($planData['has_addons']??false): ?>
  <div class="info-box" style="text-align:center;margin-bottom:40px;">
    Your <strong><?= $planData['name'] ?></strong> plan already includes all standard add-ons.
    <?php if(!$hasAutopilot): ?>
    Autopilot is the only add-on that requires a separate purchase.
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /addons-wrap -->
</div><!-- /dash-content -->
</div><!-- /dash-main -->

<script src="https://app.lemonsqueezy.com/js/lemon.js" defer></script>
<script>window.createLemonSqueezy?.();</script>
</body>
</html>
