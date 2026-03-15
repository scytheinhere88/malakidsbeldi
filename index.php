<?php require_once __DIR__.'/config.php'; $lang=getLang(); ?>
<!DOCTYPE html><html lang="<?= $lang ?>"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>BulkReplace — Bulk Content Replacement Tool for Agencies | CSV-Powered</title>
<meta name="description" content="Replace placeholders across hundreds of website folders instantly. Client-side processing, CSV-powered, perfect for agencies. Start free with 20 rows.">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?= APP_URL ?>/">
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<meta property="og:title" content="BulkReplace — Bulk Content Replacement Tool">
<meta property="og:description" content="Replace placeholders across hundreds of folders instantly. Client-side, CSV-powered, built for agencies.">
<meta property="og:url" content="<?= APP_URL ?>/">
<meta property="og:type" content="website">
<meta property="og:image" content="https://bulkreplacetool.com/img/og-cover.png">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="BulkReplace — Bulk Content Replacement Tool">
<meta name="twitter:image" content="https://bulkreplacetool.com/img/og-cover.png">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "BulkReplace",
  "url": "<?= APP_URL ?>",
  "logo": "<?= APP_URL ?>/img/logo.png",
  "description": "Bulk content replacement tool for agencies managing white-label websites",
  "sameAs": []
}
</script>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebSite",
  "name": "BulkReplace",
  "url": "<?= APP_URL ?>",
  "potentialAction": {
    "@type": "SearchAction",
    "target": {
      "@type": "EntryPoint",
      "urlTemplate": "<?= APP_URL ?>/dashboard/?q={search_term_string}"
    },
    "query-input": "required name=search_term_string"
  }
}
</script>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "SoftwareApplication",
  "name": "BulkReplace",
  "applicationCategory": "DeveloperApplication",
  "operatingSystem": "Any (Web-based)",
  "description": "Client-side bulk content replacement tool for agencies managing white-label websites",
  "url": "<?= APP_URL ?>",
  "offers": [
    {"@type": "Offer", "name": "Free Plan", "price": "0", "priceCurrency": "USD"},
    {"@type": "Offer", "name": "Pro Plan", "price": "19.9", "priceCurrency": "USD"},
    {"@type": "Offer", "name": "Lifetime Plan", "price": "469.9", "priceCurrency": "USD"}
  ],
  "aggregateRating": {
    "@type": "AggregateRating",
    "ratingValue": "4.8",
    "ratingCount": "47"
  }
}
</script>
<style>
/* ── HOMEPAGE EXTRAS (self-contained) ── */

/* Feature grid cards */
.feat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:48px;}
@media(max-width:900px){.feat-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:560px){.feat-grid{grid-template-columns:1fr;}}
.feat-c{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:28px;transition:all .2s;position:relative;overflow:hidden;}
.feat-c::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;opacity:0;transition:opacity .2s;}
.feat-c:hover{border-color:var(--border2);transform:translateY(-3px);box-shadow:0 12px 40px rgba(0,0,0,.3);}
.feat-c:hover::after{opacity:1;}
.feat-c.ca::after{background:linear-gradient(90deg,transparent,var(--a1),transparent);}
.feat-c.ct::after{background:linear-gradient(90deg,transparent,var(--a2),transparent);}
.feat-c.cp::after{background:linear-gradient(90deg,transparent,var(--purple),transparent);}
.fi{font-size:34px;margin-bottom:16px;}
.ft{font-family:'Syne',sans-serif;font-size:16px;font-weight:700;color:#fff;margin-bottom:8px;}
.fd{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);line-height:1.9;}

/* ── AUTOPILOT HERO ── */
.ap-sect{padding:96px 0;background:radial-gradient(ellipse 80% 60% at 65% 50%,rgba(240,165,0,.05),transparent),radial-gradient(ellipse 50% 50% at 10% 80%,rgba(0,212,170,.03),transparent);border-top:1px solid var(--border);border-bottom:1px solid var(--border);position:relative;overflow:hidden;}
.ap-sect::before{content:'';position:absolute;top:-80px;right:-80px;width:500px;height:500px;background:radial-gradient(circle,rgba(240,165,0,.07) 0%,transparent 70%);pointer-events:none;}
.ap-inner{display:grid;grid-template-columns:1fr 1fr;gap:64px;align-items:center;}
@media(max-width:960px){.ap-inner{grid-template-columns:1fr;gap:40px;}}
.ap-badge{display:inline-flex;align-items:center;gap:8px;background:rgba(240,165,0,.08);border:1px solid rgba(240,165,0,.3);border-radius:100px;padding:5px 16px;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--a1);letter-spacing:2px;text-transform:uppercase;margin-bottom:22px;}
.ap-dot{width:7px;height:7px;min-width:7px;background:var(--a1);border-radius:50%;animation:blink 1.2s infinite;}
.ap-h2{font-family:'Syne',sans-serif;font-size:clamp(30px,4vw,50px);font-weight:900;color:#fff;line-height:1.08;margin-bottom:18px;letter-spacing:-1.5px;}
.ap-grad{background:linear-gradient(135deg,#f0a500,#ff6b35,#c084fc);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.ap-sub{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);line-height:2;margin-bottom:28px;max-width:440px;}
.ap-steps{display:flex;align-items:flex-start;gap:10px;margin-bottom:32px;flex-wrap:wrap;}
.ap-step{flex:1;min-width:100px;display:flex;flex-direction:column;align-items:center;text-align:center;gap:6px;}
.ap-step-num{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:13px;font-weight:900;}
.sn1{background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.3);color:var(--a1);}
.sn2{background:rgba(192,132,252,.1);border:1px solid rgba(192,132,252,.3);color:#c084fc;font-size:9px!important;letter-spacing:.5px;}
.sn3{background:rgba(0,230,118,.1);border:1px solid rgba(0,230,118,.3);color:var(--ok);}
.ap-step-t{font-family:'Syne',sans-serif;font-size:12px;font-weight:700;color:#fff;}
.ap-step-s{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:1.6;}
.ap-arr{color:var(--border2);font-size:16px;margin-top:10px;flex-shrink:0;}
.ap-cta{display:flex;gap:12px;flex-wrap:wrap;}
.ap-note{margin-top:12px;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);}

/* Terminal */
.ap-term{background:var(--bg2);border:1px solid var(--border2);border-radius:14px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.5),0 0 0 1px rgba(240,165,0,.05);}
.ap-tbar{background:var(--card);padding:10px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:6px;}
.ap-tdot{width:12px;height:12px;border-radius:50%;}
.ap-tbody{padding:16px 18px;font-family:'JetBrains Mono',monospace;font-size:11px;line-height:2;background:#02020c;max-height:340px;overflow-y:auto;}
.ap-l{display:block;}
.c-dim{color:rgba(255,255,255,.2);}
.c-scan{color:rgba(200,200,232,.45);}
.c-ai{color:#c084fc;}
.c-ok{color:var(--ok);}
.c-data{color:rgba(200,200,232,.55);}
.c-dom{color:var(--a2);font-weight:700;}
.c-rep{color:var(--a1);}
.c-done{color:var(--a1);font-weight:700;}
.ap-an{opacity:0;animation:apFade .4s ease forwards;}
@keyframes apFade{to{opacity:1;}}
.ap-cursor{animation:blink .8s step-end infinite;}
.ap-tstats{display:flex;justify-content:space-around;padding:14px 18px;background:var(--card);border-top:1px solid var(--border);}
.ap-ts{text-align:center;}
.ap-tsv{font-family:'Syne',sans-serif;font-size:19px;font-weight:900;color:var(--a1);}
.ap-tsl{font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);letter-spacing:1px;text-transform:uppercase;margin-top:2px;}
.ap-ts-sep{width:1px;height:32px;background:var(--border);}

/* Why Autopilot */
.why-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:48px;}
@media(max-width:900px){.why-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:560px){.why-grid{grid-template-columns:1fr;}}
.why-c{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:26px;transition:all .2s;}
.why-c:hover{border-color:var(--border2);transform:translateY(-3px);}
.why-icon{width:46px;height:46px;border-radius:12px;border:1px solid;display:flex;align-items:center;justify-content:center;font-size:22px;margin-bottom:14px;}
.why-t{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;color:#fff;margin-bottom:8px;}
.why-d{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);line-height:1.9;}

/* Perfect for */
.perf-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:40px;}
@media(max-width:768px){.perf-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:480px){.perf-grid{grid-template-columns:1fr;}}
.perf-c{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:22px;text-align:center;transition:all .2s;}
.perf-c:hover{border-color:var(--border2);transform:translateY(-2px);}
</style>
</head><body>
<div id="toast-wrap"></div>
<nav><div class="nav-inner">
  <a href="/" class="nav-logo"><img src="/img/logo.png" alt="BulkReplace" class="nav-logo-img"><span class="nav-logo-text">BulkReplace</span></a>
  <div class="nav-links">
    <a href="/"><?= t('nav_home') ?></a>
    <a href="/landing/tutorial.php"><?= t('nav_tutorial') ?></a>
    <a href="/landing/pricing.php"><?= t('nav_pricing') ?></a>
    <a href="/landing/terms.php"><?= t('nav_terms') ?></a>
    <a href="/landing/privacy.php">Privacy</a>
  </div>
  <div class="nav-cta">
    <div style="display:inline-flex;gap:6px;margin-right:12px;background:var(--dim);border:1px solid var(--border);border-radius:8px;padding:4px;">
      <a href="/lang/switch.php?lang=en&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>" style="padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:10px;text-decoration:none;transition:all .2s;<?= $lang==='en'?'background:var(--a1);color:#000;font-weight:700;':'color:var(--muted);' ?>">EN</a>
      <a href="/lang/switch.php?lang=id&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>" style="padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:10px;text-decoration:none;transition:all .2s;<?= $lang==='id'?'background:var(--a1);color:#000;font-weight:700;':'color:var(--muted);' ?>">ID</a>
    </div>
    <?php if(isLoggedIn()): ?>
      <a href="/dashboard/" class="btn btn-ghost btn-sm"><?= t('nav_dashboard') ?></a>
    <?php else: ?>
      <a href="/auth/login.php" class="btn btn-ghost btn-sm"><?= t('nav_signin') ?></a>
      <a href="/auth/register.php" class="btn btn-amber btn-sm"><?= t('nav_register') ?></a>
    <?php endif; ?>
  </div>
</div></nav>

<!-- ─── HERO ─────────────────────────────────────────── -->
<div class="hero">
  <div>
    <div class="hero-badge"><span></span><?= t('hero_tag') ?></div>
    <h1><?= t('hero_title') ?></h1>
    <p class="hero-sub"><?= t('hero_subtitle') ?></p>
    <div class="hero-actions">
      <a href="/auth/register.php" class="btn btn-amber btn-lg"><?= t('hero_cta_primary') ?></a>
      <a href="/landing/tutorial.php" class="btn btn-ghost btn-lg"><?= t('hero_cta_secondary') ?></a>
    </div>
  </div>
</div>

<div class="wrap">

<!-- ─── FEATURES ──────────────────────────────────────── -->
<section class="section">
  <div style="text-align:center;">
    <div class="section-tag"><?= t('features_tag') ?></div>
    <div class="section-title"><?= t('features_title') ?></div>
    <p class="section-sub" style="margin:12px auto 0;">Everything you need to replace content at scale — client-side, fast, and private.</p>
  </div>
  <div class="feat-grid">
    <div class="feat-c ca">
      <div class="fi">🚀</div>
      <div class="ft"><?= t('feature_speed_title') ?></div>
      <div class="fd"><?= t('feature_speed_desc') ?></div>
    </div>
    <div class="feat-c ct">
      <div class="fi">🔒</div>
      <div class="ft"><?= t('feature_private_title') ?></div>
      <div class="fd"><?= t('feature_private_desc') ?></div>
    </div>
    <div class="feat-c cp">
      <div class="fi">📊</div>
      <div class="ft"><?= t('feature_csv_title') ?></div>
      <div class="fd"><?= t('feature_csv_desc') ?></div>
    </div>
    <div class="feat-c ca">
      <div class="fi">🎯</div>
      <div class="ft"><?= t('feature_smart_title') ?></div>
      <div class="fd"><?= t('feature_smart_desc') ?></div>
    </div>
    <div class="feat-c ct">
      <div class="fi">💾</div>
      <div class="ft"><?= t('feature_output_title') ?></div>
      <div class="fd"><?= t('feature_output_desc') ?></div>
    </div>
    <div class="feat-c cp">
      <div class="fi">🔄</div>
      <div class="ft"><?= t('feature_rollover_title') ?></div>
      <div class="fd"><?= t('feature_rollover_desc') ?></div>
    </div>
  </div>
</section>

</div><!-- /wrap -->

<!-- ─── AUTOPILOT HERO ──────────────────────────────── -->
<section class="ap-sect">
  <div class="wrap">
    <div class="ap-inner">

      <div>
        <div class="ap-badge"><span class="ap-dot"></span>NEW — LIFETIME EXCLUSIVE</div>
        <h2 class="ap-h2">Meet <span class="ap-grad">Autopilot</span><br>Your AI Website<br>Clone Engine</h2>
        <p class="ap-sub">Pick a template folder. Drop 100 domains. BulkReplace AI reads your template, detects every location-specific value, and writes 100 ready websites directly to your PC — no ZIP, no upload, no manual work.</p>

        <div class="ap-steps">
          <div class="ap-step">
            <div class="ap-step-num sn1">1</div>
            <div class="ap-step-t">Pick Template</div>
            <div class="ap-step-s">Select your website folder from PC</div>
          </div>
          <div class="ap-arr">→</div>
          <div class="ap-step">
            <div class="ap-step-num sn2">AI</div>
            <div class="ap-step-t">AI Reads</div>
            <div class="ap-step-s">AI detects all location values automatically</div>
          </div>
          <div class="ap-arr">→</div>
          <div class="ap-step">
            <div class="ap-step-num sn3">✓</div>
            <div class="ap-step-t">100 Sites Done</div>
            <div class="ap-step-s">Files written directly to your PC</div>
          </div>
        </div>

        <div class="ap-cta">
          <a href="/landing/pricing.php#autopilot" class="btn btn-amber btn-lg">Get Autopilot</a>
          <a href="/landing/tutorial.php" class="btn btn-ghost btn-lg">See How It Works</a>
        </div>
        <p class="ap-note">Exclusive for Lifetime members &nbsp;·&nbsp; Powered by BulkReplace AI &nbsp;·&nbsp; ~$0.03 per session</p>
      </div>

      <div class="ap-term">
        <div class="ap-tbar">
          <span class="ap-tdot" style="background:#ff5f57;"></span>
          <span class="ap-tdot" style="background:#ffbd2e;"></span>
          <span class="ap-tdot" style="background:#28c840;"></span>
          <span style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-left:8px;">Autopilot — BulkReplace</span>
        </div>
        <div class="ap-tbody">
          <span class="ap-l c-dim">  Autopilot Engine ready</span>
          <span class="ap-l c-scan ap-an" style="animation-delay:.3s">f  Scanning: office-template/ ... 47 files</span>
          <span class="ap-l c-ai ap-an" style="animation-delay:.9s">*  Analyzing with BulkReplace AI...</span>
          <span class="ap-l c-ok ap-an" style="animation-delay:1.8s">+  Detection complete — 9 fields found</span>
          <span class="ap-l c-data ap-an" style="animation-delay:2.1s">d    namalinkurl  →  https://office-seattle.com/</span>
          <span class="ap-l c-data ap-an" style="animation-delay:2.3s">d    location     →  Seattle / seattle</span>
          <span class="ap-l c-data ap-an" style="animation-delay:2.5s">d    address      →  123 Main St Suite 100...</span>
          <span class="ap-l c-data ap-an" style="animation-delay:2.7s">d    phone        →  +12065550100</span>
          <span class="ap-l c-data ap-an" style="animation-delay:2.9s">d    embedmap     →  maps.google.com/maps?...</span>
          <span class="ap-l c-dim ap-an" style="animation-delay:3.1s">  ── RUNNING ─────────────────────</span>
          <span class="ap-l c-dom ap-an" style="animation-delay:3.4s">@  [1/5] office-seattle.com</span>
          <span class="ap-l c-ok ap-an" style="animation-delay:3.7s">+  Written: 47 files → output/office-seattle.com/</span>
          <span class="ap-l c-dom ap-an" style="animation-delay:4.0s">@  [2/5] office-portland.com</span>
          <span class="ap-l c-ok ap-an" style="animation-delay:4.3s">+  Written: 47 files → output/office-portland.com/</span>
          <span class="ap-l c-dom ap-an" style="animation-delay:4.6s">@  [3/5] office-boston.com</span>
          <span class="ap-l c-ok ap-an" style="animation-delay:4.9s">+  Written: 47 files → output/office-boston.com/</span>
          <span class="ap-l c-dim ap-an" style="animation-delay:5.2s">  ── COMPLETE ─────────────────────</span>
          <span class="ap-l c-done ap-an" style="animation-delay:5.4s">#  5 domains · 235 files · 1,104 replacements</span>
          <span class="ap-l c-ok ap-an" style="animation-delay:5.6s">+  Saved to: output/ on your PC</span>
          <span class="ap-l c-dim ap-an" style="animation-delay:5.8s">  <span class="ap-cursor">█</span></span>
        </div>
        <div class="ap-tstats">
          <div class="ap-ts"><div class="ap-tsv">1×</div><div class="ap-tsl">API Call</div></div>
          <div class="ap-ts-sep"></div>
          <div class="ap-ts"><div class="ap-tsv" style="color:var(--a2);">$0.03</div><div class="ap-tsl">Per Session</div></div>
          <div class="ap-ts-sep"></div>
          <div class="ap-ts"><div class="ap-tsv" style="color:var(--ok);">100%</div><div class="ap-tsl">Browser-Side</div></div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ─── WHY AUTOPILOT ──────────────────────────────── -->
<section class="section" style="border-bottom:1px solid var(--border);">
  <div class="wrap">
    <div style="text-align:center;">
      <div class="section-tag">WHY AUTOPILOT</div>
      <div class="section-title">From 1 template to 100 sites in minutes</div>
    </div>
    <div class="why-grid">
      <div class="why-c">
        <div class="why-icon" style="background:rgba(240,165,0,.1);border-color:rgba(240,165,0,.25);color:var(--a1);">🧠</div>
        <div class="why-t">AI Reads Any Template</div>
        <div class="why-d">BulkReplace AI reads your HTML directly — no manual field mapping. Works for offices, branches, franchises, regional centers, or any organization type.</div>
      </div>
      <div class="why-c">
        <div class="why-icon" style="background:rgba(0,212,170,.1);border-color:rgba(0,212,170,.25);color:var(--a2);">📍</div>
        <div class="why-t">Real Data Per Location</div>
        <div class="why-d">Google Places API pulls real coordinates, real addresses, and generates accurate Maps embeds for each domain automatically.</div>
      </div>
      <div class="why-c">
        <div class="why-icon" style="background:rgba(192,132,252,.1);border-color:rgba(192,132,252,.25);color:var(--purple);">💾</div>
        <div class="why-t">Files Written to Your PC</div>
        <div class="why-d">No ZIP downloads, no server storage. Autopilot writes output directly to a folder you choose on your PC — instantly usable.</div>
      </div>
      <div class="why-c">
        <div class="why-icon" style="background:rgba(0,230,118,.1);border-color:rgba(0,230,118,.25);color:var(--ok);">⚡</div>
        <div class="why-t">Near-Zero Cost</div>
        <div class="why-d">AI called once per template — not per domain. Run 500 domains from 1 template for ~$0.03. Google Places free up to ~11,700 domains/month.</div>
      </div>
      <div class="why-c">
        <div class="why-icon" style="background:rgba(240,165,0,.1);border-color:rgba(240,165,0,.25);color:var(--a1);">🔄</div>
        <div class="why-t">All Case Variants Replaced</div>
        <div class="why-d">Auto-generates Balikpapan / balikpapan / BALIKPAPAN rules. Handles &amp;amp; HTML-encoded URLs. Nothing slips through.</div>
      </div>
      <div class="why-c">
        <div class="why-icon" style="background:rgba(0,212,170,.1);border-color:rgba(0,212,170,.25);color:var(--a2);">🔐</div>
        <div class="why-t">100% Private</div>
        <div class="why-d">Template files never leave your browser. Only a ~28KB sample sent to AI for detection. Replace &amp; write is 100% client-side.</div>
      </div>
    </div>
  </div>
</section>

<div class="wrap">

<!-- ─── PERFECT FOR ────────────────────────────────── -->
<section class="section" style="text-align:center;">
  <div class="section-tag"><?= t('perfect_tag') ?></div>
  <div class="section-title"><?= t('perfect_title') ?></div>
  <div class="perf-grid">
    <div class="perf-c">
      <div style="font-size:36px;margin-bottom:10px;">🏢</div>
      <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:6px;"><?= t('perfect_whitelabel') ?></div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);"><?= t('perfect_whitelabel_desc') ?></div>
    </div>
    <div class="perf-c">
      <div style="font-size:36px;margin-bottom:10px;">🎨</div>
      <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:6px;"><?= t('perfect_theme') ?></div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);"><?= t('perfect_theme_desc') ?></div>
    </div>
    <div class="perf-c">
      <div style="font-size:36px;margin-bottom:10px;">🌐</div>
      <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:6px;"><?= t('perfect_multiclient') ?></div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);"><?= t('perfect_multiclient_desc') ?></div>
    </div>
    <div class="perf-c">
      <div style="font-size:36px;margin-bottom:10px;">⚙️</div>
      <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:6px;"><?= t('perfect_config') ?></div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);"><?= t('perfect_config_desc') ?></div>
    </div>
  </div>
  <div style="margin-top:48px;">
    <a href="/auth/register.php" class="btn btn-amber btn-lg"><?= t('perfect_cta') ?></a>
  </div>
</section>

</div><!-- /wrap -->

<footer><div class="footer-grid">
  <div class="footer-brand"><a href="/" class="nav-logo"><img src="/img/logo.png" alt="BulkReplace" class="nav-logo-img"><span class="nav-logo-text" style="margin-left:10px;">BulkReplace</span></a><p><?= t('footer_tagline') ?></p></div>
  <div class="footer-col"><h4><?= t('footer_product') ?></h4><a href="/"><?= t('nav_home') ?></a><a href="/landing/tutorial.php"><?= t('nav_tutorial') ?></a><a href="/landing/pricing.php"><?= t('nav_pricing') ?></a></div>
  <div class="footer-col"><h4><?= t('footer_support') ?></h4><a href="<?= SUPPORT_TELEGRAM_URL ?>" target="_blank">Telegram</a><a href="/landing/terms.php"><?= t('nav_terms') ?></a><a href="/landing/privacy.php">Privacy</a></div>
  <div class="footer-col"><h4><?= t('footer_account') ?></h4><?php if(isLoggedIn()): ?><a href="/dashboard/"><?= t('nav_dashboard') ?></a><?php else: ?><a href="/auth/login.php"><?= t('nav_signin') ?></a><a href="/auth/register.php"><?= t('nav_register') ?></a><?php endif; ?></div>
</div><div class="footer-bottom"><span>&copy; <?= date('Y') ?> BulkReplace</span></div></footer>
</body></html>
