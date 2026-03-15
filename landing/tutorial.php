<?php
require_once dirname(__DIR__).'/config.php';
$lang = getLang();
$canonicalUrl = APP_URL . '/landing/tutorial.php';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Tutorial — BulkReplace Full Workflow Guide</title>
<meta name="description" content="Complete step-by-step guide: CSV Generator, Copy & Rename, BulkReplace, and ZIP Manager. Full workflow from template to ready-to-upload files.">
<link rel="canonical" href="<?= $canonicalUrl ?>">
<meta name="robots" content="index, follow">
<meta property="og:title" content="Tutorial — BulkReplace Full Workflow Guide">
<meta property="og:description" content="Step-by-step guide: CSV Generator, Copy &amp; Rename, BulkReplace, ZIP Manager. Full workflow from template to ready-to-upload files.">
<meta property="og:url" content="<?= $canonicalUrl ?>">
<meta property="og:type" content="article">
<meta property="og:image" content="https://bulkreplacetool.com/img/og-cover.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Tutorial — BulkReplace Full Workflow Guide">
<meta name="twitter:description" content="Step-by-step: CSV Generator → Copy & Rename → BulkReplace → ZIP Manager">
<meta name="twitter:image" content="https://bulkreplacetool.com/img/og-cover.png">
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>
/* ═══ TAB NAV ════════════════════════════════════════════ */
.tut-tabs{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin:32px 0 40px;}
.tut-tab{padding:10px 22px;border-radius:100px;font-family:'JetBrains Mono',monospace;font-size:11px;font-weight:700;cursor:pointer;transition:all .25s;border:1px solid var(--border);background:var(--card);color:var(--muted);text-decoration:none;white-space:nowrap;display:inline-flex;align-items:center;gap:7px;}
.tut-tab:hover{border-color:var(--border2);color:var(--text);transform:translateY(-1px);}
.tut-tab.active{background:rgba(240,165,0,.1);border-color:rgba(240,165,0,.4);color:var(--a1);}

/* ═══ PANELS ═════════════════════════════════════════════ */
.tut-panel{display:none;animation:fadeUp .35s ease forwards;}
.tut-panel.active{display:block;}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:translateY(0);}}

/* ═══ CARD ═══════════════════════════════════════════════ */
.tc{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:36px 40px;margin-bottom:20px;position:relative;overflow:hidden;}
.tc::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;}
.tc-wf::before{background:linear-gradient(90deg,var(--a1),#c084fc,var(--a2));}
.tc-csv::before{background:linear-gradient(90deg,var(--a1),rgba(240,165,0,.1));}
.tc-cr::before{background:linear-gradient(90deg,#c084fc,rgba(192,132,252,.1));}
.tc-rt::before{background:linear-gradient(90deg,var(--a2),rgba(0,212,170,.1));}
.tc-zm::before{background:linear-gradient(90deg,#00e676,rgba(0,230,118,.1));}
.tc-ap::before{background:linear-gradient(90deg,#f0a500,#c084fc,var(--a2));}
.ic-ap{background:linear-gradient(135deg,rgba(240,165,0,.12),rgba(192,132,252,.12));border:1px solid rgba(240,165,0,.3);}
.sn-ap{background:rgba(240,165,0,.12);border:2px solid rgba(240,165,0,.3);color:var(--a1);}
@media(max-width:640px){.tc{padding:20px 18px;border-radius:14px;}}

/* ═══ CARD HEADER ════════════════════════════════════════ */
.tc-head{display:flex;align-items:flex-start;gap:18px;margin-bottom:28px;}
.tc-icon{width:54px;height:54px;min-width:54px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:26px;}
.ic-wf{background:linear-gradient(135deg,rgba(240,165,0,.12),rgba(192,132,252,.12));border:1px solid rgba(240,165,0,.2);}
.ic-csv{background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.2);}
.ic-cr{background:rgba(192,132,252,.1);border:1px solid rgba(192,132,252,.2);}
.ic-rt{background:rgba(0,212,170,.1);border:1px solid rgba(0,212,170,.2);}
.ic-zm{background:rgba(0,230,118,.1);border:1px solid rgba(0,230,118,.2);}
.tc-tag{font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:.15em;color:var(--muted);margin-bottom:5px;}
.tc-title{font-family:'Syne',sans-serif;font-size:22px;font-weight:900;color:#fff;line-height:1.2;}
.tc-desc{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);margin-top:6px;line-height:1.8;}

/* ═══ STEP ROWS — flex based, no grid ════════════════════ */
.steps{display:flex;flex-direction:column;gap:0;}
.si{display:flex;gap:18px;position:relative;padding-bottom:28px;}
.si:last-child{padding-bottom:0;}
.si:not(:last-child)::before{content:'';position:absolute;left:20px;top:44px;bottom:0;width:1px;background:linear-gradient(180deg,rgba(255,255,255,.1),transparent);}
.si-num{width:42px;min-width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:15px;font-weight:900;position:relative;z-index:1;flex-shrink:0;}
.sn-a{background:rgba(240,165,0,.12);border:2px solid rgba(240,165,0,.3);color:var(--a1);}
.sn-b{background:rgba(192,132,252,.12);border:2px solid rgba(192,132,252,.3);color:#c084fc;}
.sn-c{background:rgba(0,212,170,.12);border:2px solid rgba(0,212,170,.3);color:var(--a2);}
.sn-d{background:rgba(0,230,118,.12);border:2px solid rgba(0,230,118,.3);color:var(--ok);}
.si-body{flex:1;min-width:0;padding-top:6px;}
.si-title{font-family:'Syne',sans-serif;font-size:16px;font-weight:700;color:#fff;margin-bottom:7px;}
.si-desc{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);line-height:1.85;}
.si-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:6px;padding:3px 10px;font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-top:8px;}

/* ═══ CODE BLOCKS ════════════════════════════════════════ */
.cb{background:#02020a;border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:14px 18px;font-family:'JetBrains Mono',monospace;font-size:11px;color:rgba(255,255,255,.55);line-height:2;margin:10px 0;position:relative;overflow:hidden;}
.cb::after{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;}
.cba::after{background:var(--a1);}
.cbb::after{background:#c084fc;}
.cbc::after{background:var(--a2);}
.cbd::after{background:var(--ok);}
.cb-hi{color:#fff;font-weight:600;}
.cb-ok{color:var(--ok);}
.cb-err{color:var(--err);}
.cb-dim{color:rgba(255,255,255,.2);}

/* ═══ TIPS ═══════════════════════════════════════════════ */
.tip{display:flex;gap:12px;padding:13px 16px;border-radius:10px;font-family:'JetBrains Mono',monospace;font-size:11.5px;line-height:1.75;margin:12px 0;}
.tip-a{background:rgba(240,165,0,.05);border:1px solid rgba(240,165,0,.15);color:rgba(255,255,255,.55);}
.tip-a strong{color:var(--a1);}
.tip-g{background:rgba(0,230,118,.05);border:1px solid rgba(0,230,118,.15);color:rgba(255,255,255,.55);}
.tip-g strong{color:var(--ok);}
.tip-w{background:rgba(255,215,64,.05);border:1px solid rgba(255,215,64,.15);color:rgba(255,255,255,.55);}
.tip-w strong{color:var(--warn);}
.tip-icon{font-size:16px;flex-shrink:0;line-height:1;}

/* ═══ FOLDER TREE ════════════════════════════════════════ */
.ftree{background:#02020a;border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:14px 18px;font-family:'JetBrains Mono',monospace;font-size:11px;line-height:2;margin:10px 0;}
.fd{color:rgba(255,215,64,.75);}
.fh{color:var(--ok);}
.fm{color:rgba(255,255,255,.25);}

/* ═══ CSV TABLE ══════════════════════════════════════════ */
.csv-t{width:100%;border-collapse:collapse;font-family:'JetBrains Mono',monospace;font-size:11px;margin:10px 0;overflow:hidden;border-radius:8px;}
.csv-t th{background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.15);padding:8px 12px;color:var(--a1);font-weight:700;text-align:left;}
.csv-t td{border:1px solid var(--border);padding:8px 12px;color:rgba(255,255,255,.45);}
.csv-t tr:hover td{background:rgba(255,255,255,.02);}

/* ═══ WORKFLOW STEPS (big cards) ═════════════════════════ */
.wf-flow{display:flex;flex-direction:column;gap:10px;margin-top:20px;}
.wf-item{display:flex;align-items:flex-start;gap:14px;padding:18px 20px;background:var(--bg2);border:1px solid var(--border);border-radius:14px;transition:all .2s;}
.wf-item:hover{border-color:var(--border2);transform:translateX(3px);}
.wf-n{width:36px;min-width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:14px;font-weight:900;flex-shrink:0;}
.wn1,.wn2{background:rgba(240,165,0,.12);border:1px solid rgba(240,165,0,.3);color:var(--a1);}
.wn3{background:rgba(192,132,252,.12);border:1px solid rgba(192,132,252,.3);color:#c084fc;}
.wn4{background:rgba(0,212,170,.12);border:1px solid rgba(0,212,170,.3);color:var(--a2);}
.wn5{background:rgba(0,230,118,.12);border:1px solid rgba(0,230,118,.3);color:var(--ok);}
.wf-info{flex:1;min-width:0;}
.wf-title{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:4px;}
.wf-desc{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);line-height:1.75;}
.wf-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:6px;padding:3px 9px;font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-top:7px;}
.wf-arr{text-align:center;font-size:13px;color:rgba(255,255,255,.12);}

/* ═══ CTA ROW ════════════════════════════════════════════ */
.cta-row{display:flex;gap:10px;flex-wrap:wrap;padding-top:24px;margin-top:8px;border-top:1px solid var(--border);}

/* ═══ DIVIDER (within zm) ════════════════════════════════ */
.inner-div{display:flex;align-items:center;gap:12px;margin:24px 0 18px;}
.inner-div::before,.inner-div::after{content:'';flex:1;height:1px;background:var(--border);}
.inner-div span{font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:.14em;color:var(--muted);white-space:nowrap;}
</style>

<!-- JSON-LD: BreadcrumbList -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    {"@type":"ListItem","position":1,"name":"Home","item":"https://bulkreplacetool.com/"},
    {"@type":"ListItem","position":2,"name":"Tutorial","item":"https://bulkreplacetool.com/landing/tutorial.php"}
  ]
}
</script>

<!-- JSON-LD: HowTo -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "HowTo",
  "name": "How to Bulk Replace Website Content with BulkReplace",
  "description": "Complete workflow: generate CSV data, copy and rename template folders, run BulkReplace, then ZIP for upload.",
  "totalTime": "PT10M",
  "supply": [
    {"@type":"HowToSupply","name":"Template website folder"},
    {"@type":"HowToSupply","name":"Domain name list"},
    {"@type":"HowToSupply","name":"BulkReplace account"}
  ],
  "tool": [
    {"@type":"HowToTool","name":"CSV Generator"},
    {"@type":"HowToTool","name":"Copy & Rename"},
    {"@type":"HowToTool","name":"BulkReplace Tool"},
    {"@type":"HowToTool","name":"ZIP Manager"}
  ],
  "step": [
    {"@type":"HowToStep","position":1,"name":"Generate CSV","text":"Use the CSV Generator to automatically create a data CSV for all your domain names. The AI parser extracts location and institution data, and Google Places fills in real addresses."},
    {"@type":"HowToStep","position":2,"name":"Copy & Rename","text":"Use Copy & Rename to duplicate your template folder once for each domain in your list, automatically naming each copy after the domain."},
    {"@type":"HowToStep","position":3,"name":"Configure Fields","text":"Set placeholders and suffix in the field configuration. Make sure your HTML template contains matching placeholder variables."},
    {"@type":"HowToStep","position":4,"name":"Run BulkReplace","text":"Upload your CSV and run BulkReplace. The tool finds every placeholder in every folder and replaces it with the correct data row."},
    {"@type":"HowToStep","position":5,"name":"ZIP & Download","text":"Use ZIP Manager to bundle all completed folders into separate ZIP files, ready to upload to hosting."}
  ]
}
</script>
</head>
<body>
<div id="toast-wrap"></div>

<!-- ── NAVBAR (identical to pricing) ─────────────────── -->
<nav><div class="nav-inner">
  <a href="/" class="nav-logo">
    <img src="/img/logo.png" alt="BulkReplace" class="nav-logo-img">
    <span class="nav-logo-text">BulkReplace</span>
  </a>
  <div class="nav-links">
    <a href="/"><?= t('nav_home') ?></a>
    <a href="/landing/tutorial.php" style="color:var(--a1);"><?= t('nav_tutorial') ?></a>
    <a href="/landing/pricing.php"><?= t('nav_pricing') ?></a>
    <a href="/landing/terms.php"><?= t('nav_terms') ?></a>
    <a href="/landing/privacy.php">Privacy</a>
  </div>
  <div class="nav-cta">
    <div style="display:inline-flex;gap:6px;margin-right:12px;background:var(--dim);border:1px solid var(--border);border-radius:8px;padding:4px;">
      <a href="/lang/switch.php?lang=en&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>" style="padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:10px;text-decoration:none;transition:all .2s;<?= $lang==='en'?'background:var(--a1);color:#000;font-weight:700;':'color:var(--muted);' ?>">EN</a>
      <a href="/lang/switch.php?lang=id&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>" style="padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:10px;text-decoration:none;transition:all .2s;<?= $lang==='id'?'background:var(--a1);color:#000;font-weight:700;':'color:var(--muted);' ?>">ID</a>
    </div>
    <a href="/auth/login.php" class="btn btn-ghost btn-sm"><?= t('nav_signin') ?></a>
    <a href="/auth/register.php" class="btn btn-amber btn-sm"><?= t('nav_register') ?></a>
  </div>
</div></nav>

<div class="wrap">
<section class="section" style="text-align:center;">

  <!-- Hero -->
  <div class="section-tag">📖 Documentation</div>
  <h1 class="section-title" style="margin-bottom:14px;">How to Use <span style="background:linear-gradient(135deg,var(--a1),#c084fc);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">BulkReplace</span></h1>
  <p style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);max-width:520px;margin:0 auto;line-height:1.9;">
    From 1 template folder → hundreds of ready-to-upload websites. Learn how each tool works and the complete workflow.
  </p>

  <!-- Tab nav -->
  <div class="tut-tabs">
    <a class="tut-tab active" href="#" onclick="tutSwitch('wf',this);return false;">⚡ Full Workflow</a>
    <a class="tut-tab" href="#" onclick="tutSwitch('csv',this);return false;">📊 CSV Generator</a>
    <a class="tut-tab" href="#" onclick="tutSwitch('cr',this);return false;">📁 Copy &amp; Rename</a>
    <a class="tut-tab" href="#" onclick="tutSwitch('rt',this);return false;">🔄 Run Tool</a>
    <a class="tut-tab" href="#" onclick="tutSwitch('zm',this);return false;">🗜️ ZIP Manager</a>
    <a class="tut-tab" href="#" onclick="tutSwitch('ap',this);return false;">🤖 Autopilot</a>
  </div>

</section>

<!-- ════════════════════════════════════════════════════
     FULL WORKFLOW
════════════════════════════════════════════════════ -->
<section class="section tut-panel active" id="tp-wf" style="text-align:left;max-width:800px;margin-left:auto;margin-right:auto;">
  <div class="tc tc-wf">
    <div class="tc-head">
      <div class="tc-icon ic-wf">⚡</div>
      <div>
        <div class="tc-tag">Complete Workflow</div>
        <div class="tc-title">Full Setup: 1 Template → N Websites</div>
        <div class="tc-desc">Real example: 50 regional office websites. One template folder — automated chain processing from start to ready-to-upload files.</div>
      </div>
    </div>

    <div class="tip tip-a">
      <span class="tip-icon">💡</span>
      <div><strong>Overview:</strong> Create 1 template with placeholders → CSV Generator generates data → Copy & Rename duplicates folders → Run Tool replaces content → ZIP Manager zips everything. Done in minutes.</div>
    </div>

    <div class="wf-flow">
      <div class="wf-item">
        <div class="wf-n wn1">1</div>
        <div class="wf-info">
          <div class="wf-title">Prepare 1 Template Folder</div>
          <div class="wf-desc">Create <code style="background:rgba(255,255,255,.07);padding:1px 6px;border-radius:4px;">_template</code> folder containing all HTML/CSS/JS files. Replace all specific text with <strong style="color:var(--a1);">placeholders</strong>.</div>
          <div class="cb cba" style="margin-top:10px;">
            <div class="cb-dim">// Before (specific):</div>
            <div class="cb-err">Welcome to <strong>Regional Office Seattle</strong> — office<strong>seattle</strong>.com</div>
            <div class="cb-dim" style="margin-top:4px;">// After (with placeholders):</div>
            <div class="cb-ok">Welcome to <strong>officename</strong> — <strong>namalink123</strong></div>
          </div>
          <span class="wf-badge">📁 Manual — in your text editor</span>
        </div>
      </div>

      <div class="wf-arr">↓</div>

      <div class="wf-item">
        <div class="wf-n wn2">2</div>
        <div class="wf-info">
          <div class="wf-title">Generate CSV — CSV Generator</div>
          <div class="wf-desc">Paste all domains into CSV Generator. Tool scrapes data for each domain and generates CSV with columns matching template placeholders exactly.</div>
          <table class="csv-t" style="margin-top:10px;">
            <tr><th>namalink123</th><th>officename</th><th>city</th><th>phone</th></tr>
            <tr><td>officeseattle.com</td><td>Regional Office Seattle</td><td>Seattle</td><td>206-555-0100</td></tr>
            <tr><td>officeportland.com</td><td>Regional Office Portland</td><td>Portland</td><td>503-555-0200</td></tr>
            <tr><td>officeboston.com</td><td>Regional Office Boston</td><td>Boston</td><td>617-555-0300</td></tr>
          </table>
          <span class="wf-badge">📊 CSV Generator Pro</span>
        </div>
      </div>

      <div class="wf-arr">↓</div>

      <div class="wf-item">
        <div class="wf-n wn3">3</div>
        <div class="wf-info">
          <div class="wf-title">Duplicate Folders — Copy & Rename</div>
          <div class="wf-desc">Select <code style="background:rgba(255,255,255,.07);padding:1px 6px;border-radius:4px;">_template</code> folder as source. Paste 50 domains → tool creates 50 identical folders, named after each domain.</div>
          <div class="ftree" style="margin-top:10px;">
            <div class="fd">📁 OUTPUT/</div>
            <div style="padding-left:18px;"><span class="fh">📁 officeseattle.com/</span> ← copy 1</div>
            <div style="padding-left:18px;"><span class="fh">📁 officeportland.com/</span> ← copy 2</div>
            <div style="padding-left:18px;" class="fm">... 48 more folders</div>
          </div>
          <span class="wf-badge">📁 Copy &amp; Rename Tool</span>
        </div>
      </div>

      <div class="wf-arr">↓</div>

      <div class="wf-item">
        <div class="wf-n wn4">4</div>
        <div class="wf-info">
          <div class="wf-title">Replace Content — Run Tool</div>
          <div class="wf-desc">Upload CSV + set OUTPUT folder path. BulkReplace processes each folder, replaces all placeholders with CSV data. 50 folders completed automatically.</div>
          <div class="tip tip-g" style="margin-top:8px;">
            <span class="tip-icon">✅</span>
            <div>Verify after completion — check several random folders, ensure <code>namalink123</code> changed to correct domain.</div>
          </div>
          <span class="wf-badge">🔄 Run Tool (BulkReplace)</span>
        </div>
      </div>

      <div class="wf-arr">↓</div>

      <div class="wf-item">
        <div class="wf-n wn5">5</div>
        <div class="wf-info">
          <div class="wf-title">ZIP All — ZIP Manager</div>
          <div class="wf-desc">Select OUTPUT folder → ZIP Manager creates <code style="background:rgba(255,255,255,.07);padding:1px 5px;border-radius:4px;">officeseattle.com.zip</code>, <code style="background:rgba(255,255,255,.07);padding:1px 5px;border-radius:4px;">officeportland.com.zip</code>, etc. — automatically one by one. Files ready for hosting upload!</div>
          <div class="ftree" style="margin-top:8px;">
            <div class="fd">📁 ZIPPED/</div>
            <div style="padding-left:18px;"><span class="fh">📦 officeseattle.com.zip</span></div>
            <div style="padding-left:18px;"><span class="fh">📦 officeportland.com.zip</span></div>
            <div style="padding-left:18px;" class="fm">... 48 more zips</div>
          </div>
          <span class="wf-badge">🗜️ ZIP Manager</span>
        </div>
      </div>
    </div>

    <div class="tip tip-g" style="margin-top:24px;">
      <span class="tip-icon">🎉</span>
      <div><strong>50 domains completed in minutes!</strong> Upload each zip to hosting → extract → websites live. No manual copy-paste one by one.</div>
    </div>

    <div class="cta-row">
      <a href="#" class="btn btn-amber" onclick="tutSwitch('csv',document.querySelectorAll('.tut-tab')[1]);return false;">Start: CSV Generator →</a>
      <a href="/auth/register.php" class="btn btn-ghost">Register Free</a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════
     CSV GENERATOR
════════════════════════════════════════════════════ -->
<section class="section tut-panel" id="tp-csv" style="text-align:left;max-width:800px;margin-left:auto;margin-right:auto;">
  <div class="tc tc-csv">
    <div class="tc-head">
      <div class="tc-icon ic-csv">📊</div>
      <div>
        <div class="tc-tag">Tool 1 of 4</div>
        <div class="tc-title">CSV Generator Pro</div>
        <div class="tc-desc">Generate CSV data from domain list automatically. Tool scrapes name, city, contact for each domain — ready to use with BulkReplace.</div>
      </div>
    </div>

    <div class="steps">
      <div class="si">
        <div class="si-num sn-a">1</div>
        <div class="si-body">
          <div class="si-title">Open CSV Generator</div>
          <div class="si-desc">Login to dashboard → sidebar click <strong style="color:#fff;">CSV Generator</strong>. Ensure Google Places API Key is configured for scraping real data.</div>
        </div>
      </div>
      <div class="si">
        <div class="si-num sn-a">2</div>
        <div class="si-body">
          <div class="si-title">Enter Domain List</div>
          <div class="si-desc">In <strong style="color:#fff;">Domain List</strong> field, paste all domains to process — one domain per line.</div>
          <div class="cb cba">
            officeseattle.com<br>officeportland.com<br>officeboston.com<br><span class="cb-dim">... up to hundreds of domains</span>
          </div>
        </div>
      </div>
      <div class="si">
        <div class="si-num sn-a">3</div>
        <div class="si-body">
          <div class="si-title">Define Column Names / Placeholders</div>
          <div class="si-desc">CSV column names <strong style="color:var(--a1);">must match exactly</strong> with placeholders in your HTML template. These will be replaced later.</div>
          <div class="tip tip-w">
            <span class="tip-icon">⚠️</span>
            <div><strong>Case sensitive!</strong> Template uses <code>namalink123</code>? CSV column name must be <code>namalink123</code> too — not <code>NamaLink123</code>.</div>
          </div>
        </div>
      </div>
      <div class="si">
        <div class="si-num sn-a">4</div>
        <div class="si-body">
          <div class="si-title">Generate &amp; Download CSV</div>
          <div class="si-desc">Click <strong style="color:#fff;">Generate CSV</strong>. Tool scrapes each domain via Google Places API. Complete → click <strong style="color:#fff;">Download CSV</strong>. This file will be uploaded to Run Tool.</div>
          <div class="tip tip-g">
            <span class="tip-icon">✅</span>
            <div>CSV automatically saved to history — download anytime from <strong>History</strong> tab without regenerating.</div>
          </div>
          <span class="si-badge">📊 Dashboard → CSV Generator</span>
        </div>
      </div>
    </div>

    <div class="cta-row">
      <a href="/dashboard/csv_generator.php" class="btn btn-amber">📊 Open CSV Generator</a>
      <a href="#" class="btn btn-ghost btn-sm" onclick="tutSwitch('cr',document.querySelectorAll('.tut-tab')[2]);return false;">Next: Copy &amp; Rename →</a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════
     COPY & RENAME
════════════════════════════════════════════════════ -->
<section class="section tut-panel" id="tp-cr" style="text-align:left;max-width:800px;margin-left:auto;margin-right:auto;">
  <div class="tc tc-cr">
    <div class="tc-head">
      <div class="tc-icon ic-cr">📁</div>
      <div>
        <div class="tc-tag">Tool 2 of 4</div>
        <div class="tc-title">Copy &amp; Rename Tool</div>
        <div class="tc-desc">Duplicate 1 template folder into N folders, each automatically named from domain list. 100% local in browser — nothing uploaded to server.</div>
      </div>
    </div>

    <div class="tip tip-w" style="margin-bottom:20px;">
      <span class="tip-icon">⚠️</span>
      <div><strong>Requirement:</strong> Chrome or Edge version 86+. Not supported in Firefox/Safari due to File System Access API limitation.</div>
    </div>

    <div class="steps">
      <div class="si">
        <div class="si-num sn-b">1</div>
        <div class="si-body">
          <div class="si-title">Pick Template Folder (Source)</div>
          <div class="si-desc">Sidebar → <strong style="color:#fff;">Copy & Rename</strong>. Click <strong style="color:#fff;">Pick Folder</strong> card in Source section. Select <code style="background:rgba(255,255,255,.07);padding:1px 5px;border-radius:4px;">_template</code> folder — this will be duplicated.</div>
        </div>
      </div>
      <div class="si">
        <div class="si-num sn-b">2</div>
        <div class="si-body">
          <div class="si-title">Pick Output Folder</div>
          <div class="si-desc">Click <strong style="color:#fff;">Pick Output Folder</strong>. Select or create new empty folder. Don't select the same folder as source!</div>
        </div>
      </div>
      <div class="si">
        <div class="si-num sn-b">3</div>
        <div class="si-body">
          <div class="si-title">Paste Domain List</div>
          <div class="si-desc">In <strong style="color:#fff;">Name List</strong> field, paste all domains one per line. Mode <strong style="color:#c084fc;">Domain</strong> = auto strip TLD. Mode <strong style="color:#c084fc;">Raw</strong> = name as-is.</div>
          <div class="cb cbb">
            officeseattle.com<br>officeportland.com<br>officeboston.com<br><span class="cb-dim">... 50 domains → 50 folders</span>
          </div>
        </div>
      </div>
      <div class="si">
        <div class="si-num sn-b">4</div>
        <div class="si-body">
          <div class="si-title">Click ▶ Run</div>
          <div class="si-desc">Tool copies template folder N times, instantly renamed to final names. Monitor real-time in Execution Log. Speed ~1–5s per folder depending on template size.</div>
          <div class="tip tip-g">
            <span class="tip-icon">✅</span>
            <div>Complete → output folder contains N folders named after each domain, content identical to template. Ready for Run Tool.</div>
          </div>
          <span class="si-badge">📁 Dashboard → Copy &amp; Rename</span>
        </div>
      </div>
    </div>

    <div class="cta-row">
      <a href="/dashboard/copy_rename.php" class="btn" style="background:linear-gradient(135deg,#c084fc,#7c3aed);color:#fff;box-shadow:0 4px 18px rgba(192,132,252,.25);">📁 Open Copy &amp; Rename</a>
      <a href="#" class="btn btn-ghost btn-sm" onclick="tutSwitch('rt',document.querySelectorAll('.tut-tab')[3]);return false;">Next: Run Tool →</a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════
     RUN TOOL
════════════════════════════════════════════════════ -->
<section class="section tut-panel" id="tp-rt" style="text-align:left;max-width:800px;margin-left:auto;margin-right:auto;">
  <div class="tc tc-rt">
    <div class="tc-head">
      <div class="tc-icon ic-rt">🔄</div>
      <div>
        <div class="tc-tag">Tool 3 of 4 — Core Feature</div>
        <div class="tc-title">BulkReplace Run Tool</div>
        <div class="tc-desc">The core of everything. Upload CSV + folder → all placeholders replaced automatically in thousands of files via real-time streaming.</div>
      </div>
    </div>

    <div class="steps">
      <div class="si">
        <div class="si-num sn-c">1</div>
        <div class="si-body">
          <div class="si-title">Upload CSV</div>
          <div class="si-desc">Dashboard → <strong style="color:#fff;">Run Tool</strong>. Click CSV upload area → select file from CSV Generator. Tool auto-parses and shows preview of columns & data.</div>
          <div class="tip tip-a">
            <span class="tip-icon">💡</span>
            <div><strong>CSV header = placeholder.</strong> Column <code>namalink123</code> in CSV will replace all occurrences of text <code>namalink123</code> in all files within that folder.</div>
          </div>
        </div>
      </div>
      <div class="si">
        <div class="si-num sn-c">2</div>
        <div class="si-body">
          <div class="si-title">Set Base Folder Path</div>
          <div class="si-desc">Enter parent folder path containing all subfolders from Copy & Rename. Subfolders <strong style="color:var(--a2);">must be named exactly</strong> matching first column values in CSV.</div>
          <div class="cb cbc">
            <span style="color:rgba(0,212,170,.6);">Base folder :</span> <span class="cb-hi">E:\PROJECTS\OUTPUT</span><br>
            <span style="color:rgba(0,212,170,.6);">Subfolder 1 :</span> <span class="cb-hi">officeseattle.com</span> <span class="cb-dim">← match CSV row 1</span><br>
            <span style="color:rgba(0,212,170,.6);">Subfolder 2 :</span> <span class="cb-hi">officeportland.com</span> <span class="cb-dim">← match CSV row 2</span>
          </div>
        </div>
      </div>
      <div class="si">
        <div class="si-num sn-c">3</div>
        <div class="si-body">
          <div class="si-title">Click Run &amp; Monitor</div>
          <div class="si-desc">Click <strong style="color:#fff;">Run</strong>. BulkReplace streams progress real-time via SSE — each processed file appears directly in log. Don't close tab until log shows <strong style="color:var(--ok);">DONE</strong>.</div>
        </div>
      </div>
      <div class="si">
        <div class="si-num sn-c">4</div>
        <div class="si-body">
          <div class="si-title">Verify Results</div>
          <div class="si-desc">After Done → open several folders randomly. Check if <code style="background:rgba(255,255,255,.07);padding:1px 5px;border-radius:4px;">namalink123</code> changed to correct domain in each file.</div>
          <div class="tip tip-g">
            <span class="tip-icon">✅</span>
            <div>Verification OK? Continue to ZIP Manager to zip all folders at once.</div>
          </div>
          <span class="si-badge">🔄 Dashboard → Run Tool</span>
        </div>
      </div>
    </div>

    <div class="cta-row">
      <a href="/tool/" class="btn btn-teal">🔄 Open Run Tool</a>
      <a href="#" class="btn btn-ghost btn-sm" onclick="tutSwitch('zm',document.querySelectorAll('.tut-tab')[4]);return false;">Next: ZIP Manager →</a>
    </div>
  </div>
</section>

<!-- ════════════════════════════════════════════════════
     ZIP MANAGER
════════════════════════════════════════════════════ -->
<section class="section tut-panel" id="tp-zm" style="text-align:left;max-width:800px;margin-left:auto;margin-right:auto;">
  <div class="tc tc-zm">
    <div class="tc-head">
      <div class="tc-icon ic-zm">🗜️</div>
      <div>
        <div class="tc-tag">Tool 4 of 4</div>
        <div class="tc-title">ZIP Manager</div>
        <div class="tc-desc">ZIP all folders at once, or extract multiple ZIPs simultaneously. 100% local in browser — no files uploaded to server.</div>
      </div>
    </div>

    <div style="font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:.13em;color:var(--ok);margin-bottom:14px;display:flex;align-items:center;gap:10px;">
      <span style="width:24px;height:1px;background:var(--ok);display:inline-block;flex-shrink:0;"></span>Tab A: Bulk ZIP Folders
    </div>

    <div class="steps">
      <div class="si">
        <div class="si-num sn-d">1</div>
        <div class="si-body">
          <div class="si-title">Pick Source Folder</div>
          <div class="si-desc">Dashboard → <strong style="color:#fff;">ZIP Manager</strong> → tab <strong style="color:#fff;">🗜️ Bulk ZIP Folders</strong>. Click <strong style="color:#fff;">Pick Folder</strong> card in Source — select <code style="background:rgba(255,255,255,.07);padding:1px 5px;border-radius:4px;">OUTPUT/</code> folder containing all domain subfolders from Replace.</div>
        </div>
      </div>
      <div class="si">
        <div class="si-num sn-d">2</div>
        <div class="si-body">
          <div class="si-title">Pick Output Folder</div>
          <div class="si-desc">Click <strong style="color:#fff;">Pick Output Folder</strong> card — select or create new empty folder to save all .zip results.</div>
        </div>
      </div>
      <div class="si">
        <div class="si-num sn-d">3</div>
        <div class="si-body">
          <div class="si-title">Choose ZIP Structure</div>
          <div class="si-desc">Select <strong style="color:var(--ok);">Contents Only</strong> — files directly at ZIP root, without wrapper subfolder. Recommended for hosting upload (cPanel/hPanel).</div>
          <div class="cb cbd">
            <span class="cb-ok">Contents Only</span> <span class="cb-dim">← recommended for hosting</span><br>
            <span class="cb-dim">📦 officeseattle.zip → index.php, style.css, ...</span><br><br>
            <span style="color:rgba(255,255,255,.35);">Include Folder Name</span> <span class="cb-dim">← has subfolder inside zip</span><br>
            <span class="cb-dim">📦 officeseattle.zip → officeseattle/index.php, ...</span>
          </div>
        </div>
      </div>
      <div class="si">
        <div class="si-num sn-d">4</div>
        <div class="si-body">
          <div class="si-title">Click Run Bulk ZIP</div>
          <div class="si-desc">Click <strong style="color:#fff;">Run Bulk ZIP</strong>. Tool ZIPs each subfolder one by one using JSZip. Live progress in Execution Log.</div>
          <div class="ftree">
            <div class="fd">📁 ZIPPED/ (output)</div>
            <div style="padding-left:18px;"><span class="fh">📦 officeseattle.com.zip</span></div>
            <div style="padding-left:18px;"><span class="fh">📦 officeportland.com.zip</span></div>
            <div style="padding-left:18px;" class="fm">... all domains zipped</div>
          </div>
        </div>
      </div>
    </div>

    <div class="inner-div"><span>Tab B: Bulk UNZIP Files (optional)</span></div>

    <div class="steps">
      <div class="si">
        <div class="si-num sn-c">1</div>
        <div class="si-body">
          <div class="si-title">Select .ZIP Files (multi-select)</div>
          <div class="si-desc">Tab <strong style="color:#fff;">📂 Bulk UNZIP Files</strong>. Click <strong style="color:#fff;">Select .ZIP Files</strong> — can select multiple files at once. All selected .zip files will be extracted.</div>
        </div>
      </div>
      <div class="si">
        <div class="si-num sn-c">2</div>
        <div class="si-body">
          <div class="si-title">Pick Output Folder &amp; Run</div>
          <div class="si-desc">Select output folder → click <strong style="color:#fff;">Run Bulk UNZIP</strong>. Each .zip extracted to subfolder with same name. <strong style="color:var(--a2);">Extract to Subfolder</strong> option active by default.</div>
          <div class="tip tip-g">
            <span class="tip-icon">🎉</span>
            <div><strong>All zips ready!</strong> Upload to cPanel/hPanel for each domain → extract → websites live.</div>
          </div>
          <span class="si-badge">🗜️ Dashboard → ZIP Manager</span>
        </div>
      </div>
    </div>

    <div class="cta-row">
      <a href="/dashboard/zip_manager.php" class="btn btn-teal">🗜️ Open ZIP Manager</a>
      <a href="#" class="btn btn-ghost btn-sm" onclick="tutSwitch('wf',document.querySelectorAll('.tut-tab')[0]);return false;">← View Full Workflow</a>
    </div>
  </div>
</section>


<section class="section tut-panel" id="tp-ap" style="text-align:left;max-width:800px;margin-left:auto;margin-right:auto;">

  <!-- Overview card -->
  <div class="tc tc-ap">
    <div class="tc-head">
      <div class="tc-icon ic-ap">🤖</div>
      <div>
        <div class="tc-tag">AUTOPILOT &mdash; AI-POWERED PIPELINE</div>
        <div class="tc-title">1 Template &rarr; N Websites, Fully Automated</div>
        <div class="tc-desc">Pick a template folder, drop your domain list — Claude AI reads the template, detects every location-specific value, fetches real data per domain via Google Places, replaces content, and writes output files directly to your PC. No ZIP, no upload, no manual mapping.</div>
      </div>
    </div>

    <div class="tip tip-a" style="margin-bottom:20px;">
      <span class="tip-icon">⚡</span>
      <div><strong>Exclusive for Lifetime members.</strong> Autopilot is an add-on available only on the Lifetime plan. Requires an Anthropic (Claude) API key configured in settings.</div>
    </div>

    <!-- How it works visual -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px;">
      <div style="background:rgba(240,165,0,.06);border:1px solid rgba(240,165,0,.15);border-radius:12px;padding:16px;text-align:center;">
        <div style="font-size:28px;margin-bottom:8px;">📁</div>
        <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:#fff;margin-bottom:4px;">Template Folder</div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:1.7;">Your existing site<br>HTML/CSS/JS/PHP</div>
      </div>
      <div style="background:rgba(192,132,252,.06);border:1px solid rgba(192,132,252,.15);border-radius:12px;padding:16px;text-align:center;">
        <div style="font-size:28px;margin-bottom:8px;">🧠</div>
        <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:#fff;margin-bottom:4px;">Claude AI Detects</div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:1.7;">Reads content, finds<br>all replaceable values</div>
      </div>
      <div style="background:rgba(0,230,118,.06);border:1px solid rgba(0,230,118,.15);border-radius:12px;padding:16px;text-align:center;">
        <div style="font-size:28px;margin-bottom:8px;">💾</div>
        <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:#fff;margin-bottom:4px;">Written to PC</div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:1.7;">Each domain = its own<br>folder on your PC</div>
      </div>
    </div>
  </div>

  <!-- Step-by-step -->
  <div class="tc tc-ap">
    <div class="tc-head">
      <div class="tc-icon ic-ap" style="font-size:20px;">📋</div>
      <div>
        <div class="tc-tag">STEP-BY-STEP</div>
        <div class="tc-title">How to Use Autopilot</div>
      </div>
    </div>

    <div class="steps">
      <div class="si">
        <div class="si-num sn-ap">1</div>
        <div class="si-body">
          <div class="si-title">Open Autopilot</div>
          <div class="si-desc">Go to <strong style="color:#fff;">Dashboard &rarr; Autopilot</strong>. You'll see a 5-step pipeline interface. Autopilot requires Chrome or Edge (desktop) — it uses the File System Access API to read and write files directly on your PC.</div>
          <span class="si-badge">Dashboard &rarr; Autopilot</span>
        </div>
      </div>

      <div class="si">
        <div class="si-num sn-ap">2</div>
        <div class="si-body">
          <div class="si-title">Pick Template Folder</div>
          <div class="si-desc">Click <strong style="color:#fff;">Pick Template Folder</strong> and select the root folder of your website template from your PC. Autopilot scans all HTML, CSS, JS, PHP, JSON, TXT files. Priority: <code style="color:var(--a1);">index.html</code> &rarr; contact/footer pages &rarr; <code style="color:var(--a1);">.txt</code> data files.</div>
          <div class="cb cba">
<span class="cb-hi">Template folder structure example:</span>
office-template/
  index.html         <span class="cb-ok">&larr; main detection source (JSON-LD, meta, canonical)</span>
  kontak.html        <span class="cb-ok">&larr; address, phone, maps embed</span>
  assets/css/
  assets/js/
  canonical.txt      <span class="cb-ok">&larr; optional: exact URL value</span>
          </div>
        </div>
      </div>

      <div class="si">
        <div class="si-num sn-ap">3</div>
        <div class="si-body">
          <div class="si-title">Enter Target Domains</div>
          <div class="si-desc">Paste your domain list — one per line. These are the target deployments. No http://, just the domain names.</div>
          <div class="cb cba">
office-atlanta.com
office-denver.com
office-phoenix.com
office-austin.com
office-dallas.com
          </div>
          <div class="tip tip-a">
            <span class="tip-icon">💡</span>
            <div><strong>Tip:</strong> The first domain in the list is treated as the reference for fallback data. Sort your list so the most well-known domain is first.</div>
          </div>
        </div>
      </div>

      <div class="si">
        <div class="si-num sn-ap">4</div>
        <div class="si-body">
          <div class="si-title">Analyze Template (AI Detection)</div>
          <div class="si-desc">Click <strong style="color:#fff;">Analyze Template</strong>. Autopilot sends a smart ~28KB content sample to BulkReplace AI. The AI reads the template and returns a complete mapping of all location-specific values found.</div>
          <div class="cb cba">
<span class="cb-ok">Fields AI detects:</span>
namalinkurl   <span class="cb-dim">&rarr; https://office-miami.com/ (all URL variants)</span>
namalink      <span class="cb-dim">&rarr; office-miami.com, office-miami</span>
location      <span class="cb-dim">&rarr; Miami, miami, Miami FL</span>
address       <span class="cb-dim">&rarr; 1200 Brickell Ave Suite 500...</span>
phone         <span class="cb-dim">&rarr; +13055550100, 3055550100</span>
email         <span class="cb-dim">&rarr; info@office-miami.com</span>
embedmap      <span class="cb-dim">&rarr; https://maps.google.com/maps?q=...&output=embed</span>
officename    <span class="cb-dim">&rarr; Regional Office Miami, Miami Operations...</span>
zipcode       <span class="cb-dim">&rarr; 33131</span>
          </div>
          <div class="tip tip-g">
            <span class="tip-icon">✓</span>
            <div><strong>Smart detection:</strong> BulkReplace AI reads your actual HTML — title, JSON-LD, meta tags, footer, canonical. Works for any organization type (offices, branches, franchises, regional centers, etc.) without configuration.</div>
          </div>
        </div>
      </div>

      <div class="si">
        <div class="si-num sn-ap">5</div>
        <div class="si-body">
          <div class="si-title">Review Mapping (Optional)</div>
          <div class="si-desc">After detection, you'll see the full mapping. You can manually add missed values using the search panel. When satisfied, click <strong style="color:#fff;">Confirm Mapping</strong>.</div>
          <div class="tip tip-w">
            <span class="tip-icon">⚠</span>
            <div><strong>Google Maps embeds:</strong> If your template has a Google Maps iframe, Autopilot automatically handles both raw <code>&</code> and HTML-encoded <code>&amp;amp;</code> variants in the src URL.</div>
          </div>
        </div>
      </div>

      <div class="si">
        <div class="si-num sn-ap">6</div>
        <div class="si-body">
          <div class="si-title">Pick Output Folder &amp; Launch</div>
          <div class="si-desc">Click <strong style="color:#fff;">Pick Output Folder</strong> to choose where files are saved on your PC. Then click <strong style="color:#fff;">Launch Autopilot</strong>. For each domain, Autopilot: fetches real data via Google Places API, applies all replace rules with case variants, does a second verification pass, then writes all files directly to <code style="color:var(--a1);">output_folder/domain.com/</code>.</div>
          <div class="cb cba">
<span class="cb-ok">Output folder after running 5 domains:</span>
output/
  office-atlanta.com/
    index.html         <span class="cb-ok">&larr; domain, address, maps all replaced</span>
    contact.html
    assets/css/
    assets/js/
  office-denver.com/
    index.html
    ...
          </div>
          <span class="si-badge">Files written directly to your PC — no ZIP needed</span>
        </div>
      </div>
    </div>

    <!-- Cost info -->
    <div style="background:rgba(0,0,0,.3);border:1px solid var(--border);border-radius:14px;padding:20px;margin-top:24px;">
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:2px;color:var(--muted);margin-bottom:14px;">COST BREAKDOWN</div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
        <div>
          <div style="font-family:'Syne',sans-serif;font-size:22px;font-weight:900;color:var(--a1);">$0.033</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:3px;">Per template session<br>Claude API (flat, any domain count)</div>
        </div>
        <div>
          <div style="font-family:'Syne',sans-serif;font-size:22px;font-weight:900;color:var(--ok);">FREE</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:3px;">Google Places API<br>$200 free/month (~11,700 domains)</div>
        </div>
        <div>
          <div style="font-family:'Syne',sans-serif;font-size:22px;font-weight:900;color:var(--a2);">$0</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:3px;">Replace + Write to PC<br>100% browser-side processing</div>
        </div>
      </div>
    </div>

    <div class="cta-row" style="margin-top:28px;">
      <a href="/dashboard/autopilot.php" class="btn btn-amber">🤖 Open Autopilot</a>
      <a href="/landing/pricing.php#autopilot" class="btn btn-ghost btn-sm">View Autopilot Pricing →</a>
    </div>
  </div>

</section>

</div><!-- /wrap -->

<footer><div class="footer-grid">
  <div class="footer-brand">
    <a href="/" class="nav-logo"><img src="/img/logo.png" alt="BulkReplace" class="nav-logo-img"><span class="nav-logo-text" style="margin-left:10px;">BulkReplace</span></a>
    <p>Bulk content replacement tool for agencies.</p>
  </div>
  <div class="footer-col"><h4>Product</h4><a href="/">Home</a><a href="/landing/tutorial.php">Tutorial</a><a href="/landing/pricing.php">Pricing</a></div>
  <div class="footer-col"><h4>Support</h4><a href="<?= SUPPORT_TELEGRAM_URL ?>" target="_blank">Telegram</a><a href="/landing/terms.php">Terms</a><a href="/landing/privacy.php">Privacy</a></div>
  <div class="footer-col"><h4>Account</h4><a href="/auth/login.php">Sign In</a><a href="/auth/register.php">Register</a></div>
</div><div class="footer-bottom"><span>© <?= date('Y') ?> BulkReplace</span></div></footer>

<script>
function tutSwitch(id, tabEl){
  document.querySelectorAll('.tut-panel').forEach(function(p){ p.classList.remove('active'); });
  document.querySelectorAll('.tut-tab').forEach(function(t){ t.classList.remove('active'); });
  var panel = document.getElementById('tp-'+id);
  panel.classList.add('active');
  if(tabEl) tabEl.classList.add('active');
  window.scrollTo({top:0, behavior:'smooth'});
}
</script>
</body>
</html>
