<?php
require_once __DIR__ . '/../config.php';
requireLogin();
$user       = currentUser();
$has_access = hasAddonAccess($user['id'], 'csv-generator-pro');
if(!$has_access){ header('Location: /dashboard/addons.php?ref=csv-generator-pro'); exit; }
$api_key_ok = defined('GOOGLE_PLACES_API_KEY') && GOOGLE_PLACES_API_KEY !== 'YOUR_GOOGLE_PLACES_API_KEY';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(getLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>CSV Generator — <?= APP_NAME ?></title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>
/* ══════════════════════════════════════════
   LAYOUT — 3 column on desktop, 1 on mobile
══════════════════════════════════════════ */
.csv-page{display:grid;grid-template-columns:1fr 280px;gap:16px;align-items:start;}
@media(max-width:960px){.csv-page{grid-template-columns:1fr;}}

/* ── CARDS ── */
.scard{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px;margin-bottom:12px;}
.scard-head{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;}
.snum{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--a1),#c47d00);color:#000;font-size:12px;font-weight:900;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.stitle{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;color:#fff;flex:1;}
.sactions{display:flex;gap:6px;flex-wrap:wrap;}

/* ── STATS ── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px;}
/* ── AI/Google Status Badges ─────────────────── */
.sys-badges-row{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px;}
.sys-badge{font-size:10px;font-family:'JetBrains Mono',monospace;padding:4px 10px;border-radius:20px;cursor:default;transition:all .3s;}
.badge-on{background:rgba(0,230,118,.12);color:rgba(0,230,118,.9);border:1px solid rgba(0,230,118,.25);}
.badge-off{background:rgba(255,255,255,.05);color:var(--muted);border:1px solid var(--border);}
.badge-info{background:rgba(240,165,0,.08);color:rgba(240,165,0,.7);border:1px solid rgba(240,165,0,.15);}

@media(max-width:700px){.stats-row{grid-template-columns:repeat(2,1fr);}}
.sstat{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:10px 14px;text-align:center;}
.sstat-v{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;color:#fff;}
.sstat-l{font-family:'JetBrains Mono',monospace;font-size:8px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-top:3px;}

/* ── DOMAIN INPUT ── */
.dom-ta{width:100%;background:rgba(0,0,0,.35);border:1px solid var(--border);border-radius:10px;padding:12px 14px;font-family:'JetBrains Mono',monospace;font-size:11px;color:#fff;resize:vertical;min-height:100px;outline:none;transition:border-color .2s;box-sizing:border-box;}
.dom-ta:focus{border-color:rgba(240,165,0,.4);}
.dom-ta::placeholder{color:rgba(255,255,255,.18);}

/* ── PARSE CARDS GRID ── */
.parse-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:7px;margin-top:12px;}
@media(max-width:480px){.parse-grid{grid-template-columns:1fr 1fr;}}
.pcard{background:rgba(255,255,255,.025);border:1px solid var(--border);border-radius:9px;padding:9px 11px;position:relative;transition:all .2s;}
.pcard.cached{border-color:rgba(0,230,118,.3);background:rgba(0,230,118,.03);}
.pcard.scraping{border-color:rgba(240,165,0,.3);background:rgba(240,165,0,.03);}
.pcard.done{border-color:rgba(0,212,170,.3);background:rgba(0,212,170,.02);}
.pc-dom{font-family:'JetBrains Mono',monospace;font-size:9.5px;color:var(--warn);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding-right:36px;}
.pc-loc{font-family:'Syne',sans-serif;font-size:12px;font-weight:700;color:#fff;margin-top:3px;}
.pc-kw{font-family:'JetBrains Mono',monospace;font-size:8.5px;color:var(--a2);margin-top:1px;}
.pc-inst{font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:1px;color:var(--a1);margin-top:2px;}
.pc-badge{position:absolute;top:7px;right:7px;font-size:8px;padding:2px 5px;border-radius:4px;white-space:nowrap;}
.pb-cache{background:rgba(0,230,118,.1);color:var(--ok);border:1px solid rgba(0,230,118,.2);}
.pb-scrape{background:rgba(255,215,64,.1);color:var(--warn);border:1px solid rgba(255,215,64,.2);}
.pb-done{background:rgba(0,212,170,.1);color:var(--a2);border:1px solid rgba(0,212,170,.2);}

/* ── FIELD GRID ── */
.field-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px;}
@media(max-width:480px){.field-grid{grid-template-columns:1fr;}}
.fcard{background:rgba(255,255,255,.022);border:1px solid var(--border);border-radius:9px;padding:11px 13px;cursor:pointer;transition:all .18s;-webkit-tap-highlight-color:transparent;}
.fcard:hover{border-color:rgba(240,165,0,.25);}
.fcard.on{border-color:rgba(240,165,0,.5);background:rgba(240,165,0,.06);}

/* ── Additional Fields toggle ──────────────────────────── */
.extra-fields-wrap{margin-top:10px;}
.extra-fields-toggle{
  display:flex;align-items:center;justify-content:space-between;
  padding:8px 14px;border-radius:9px;cursor:pointer;
  background:rgba(255,255,255,.03);border:1px dashed var(--border2);
  font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);
  transition:all .2s;user-select:none;
}
.extra-fields-toggle:hover{border-color:rgba(168,85,247,.4);color:rgba(168,85,247,.9);background:rgba(168,85,247,.05);}
.extra-toggle-arrow{font-size:9px;transition:transform .2s;}
.fc-top{display:flex;align-items:center;gap:8px;}
.fc-icon{font-size:14px;flex-shrink:0;}
.fc-info{flex:1;min-width:0;}
.fc-key{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:700;color:#fff;}
.fc-dsc{font-family:'JetBrains Mono',monospace;font-size:8.5px;color:var(--muted);margin-top:1px;}
.fc-chk{width:14px;height:14px;accent-color:var(--a1);flex-shrink:0;pointer-events:none;}
.fc-bot{display:flex;align-items:center;gap:8px;margin-top:8px;}
.fc-sfx-lbl{font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--muted);flex:1;}
.fc-sfx{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--warn);background:rgba(255,215,64,.07);border:1px solid rgba(255,215,64,.2);border-radius:5px;padding:4px 8px;width:52px;text-align:center;outline:none;}
.fc-sfx:focus{border-color:rgba(255,215,64,.5);}
.fc-ph{font-family:'JetBrains Mono',monospace;font-size:8.5px;color:rgba(255,215,64,.5);margin-top:5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

/* ── PHONE ROW ── */
.phone-row{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:8px;padding:10px 14px;margin-bottom:10px;flex-wrap:wrap;}
.phone-label{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);}
.phone-inp{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--a2);background:transparent;border:none;outline:none;flex:1;min-width:120px;}

/* ── LOG BOX ── */
.log-box{background:#030308;border:1px solid var(--border);border-radius:10px;padding:10px 13px;height:220px;overflow-y:auto;font-family:'JetBrains Mono',monospace;font-size:11px;line-height:1.9;scroll-behavior:smooth;}
@media(max-width:480px){.log-box{height:170px;font-size:10px;}}
.le{display:flex;gap:6px;align-items:flex-start;}
.le-ts{color:rgba(255,255,255,.2);font-size:9px;flex-shrink:0;padding-top:2px;}
.le.info .le-m{color:var(--text);}
.le.cache .le-m{color:var(--ok);}
.le.scrape .le-m{color:var(--warn);}
.le.ok .le-m{color:var(--a2);}
.le.warn .le-m{color:#f0a500;}
.le.done .le-m{color:var(--a1);font-weight:700;}
.le.error .le-m{color:var(--err);}

/* ── PROGRESS ── */
.pbar-wrap{margin:8px 0 3px;}
.pbar{height:5px;background:rgba(255,255,255,.05);border-radius:100px;overflow:hidden;}
.pbar-fill{height:100%;border-radius:100px;background:linear-gradient(90deg,var(--a1),var(--a2));transition:width .35s;width:0%;}
.pbar-info{display:flex;justify-content:space-between;font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);}

/* ── PREVIEW TABLE ── */
.ptable-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:9px;margin-top:12px;}
.ptable{width:100%;border-collapse:collapse;font-family:'JetBrains Mono',monospace;font-size:9.5px;}
.ptable th{padding:7px 10px;background:rgba(240,165,0,.07);border-bottom:1px solid var(--border);color:var(--a1);white-space:nowrap;text-align:left;font-size:8.5px;text-transform:uppercase;}
.ptable td{padding:5px 10px;border-bottom:1px solid rgba(20,20,45,.6);color:var(--text);max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.ptable tr:last-child td{border-bottom:none;}

/* ── HISTORY ── */
.hitem{background:rgba(255,255,255,.016);border:1px solid var(--border);border-radius:9px;padding:11px 14px;margin-bottom:5px;display:flex;align-items:center;gap:10px;cursor:pointer;transition:all .15s;}
.hitem:hover{border-color:rgba(255,255,255,.1);}

/* ═══════════════════════════════════════════
   SIDEBAR STYLES
═══════════════════════════════════════════ */
.side-sticky{position:sticky;top:14px;}
@media(max-width:960px){.side-sticky{position:static;}}
.side-card{background:var(--card);border:1px solid var(--border);border-radius:13px;padding:16px;margin-bottom:12px;}
.side-title{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:#fff;margin-bottom:10px;display:flex;align-items:center;gap:7px;}

/* ── ANNOTATED CODE BLOCK ── */
.anno-wrap{background:#030308;border:1px solid var(--border);border-radius:9px;padding:12px;font-family:'JetBrains Mono',monospace;font-size:9.5px;line-height:2;overflow-x:auto;position:relative;}
.anno-line{display:flex;align-items:flex-start;gap:0;white-space:pre;}
.anno-plain{color:var(--a2);}
.anno-ph{color:var(--warn);background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.25);border-radius:3px;padding:0 3px;cursor:help;position:relative;display:inline-block;}
/* Tooltip on hover */
.anno-ph .ph-tip{position:absolute;bottom:calc(100% + 5px);left:50%;transform:translateX(-50%);background:#1a1a2e;border:1px solid rgba(240,165,0,.3);color:#fff;font-size:8px;padding:4px 8px;border-radius:5px;white-space:nowrap;z-index:9;opacity:0;pointer-events:none;transition:opacity .2s;box-shadow:0 4px 12px rgba(0,0,0,.5);}
.anno-ph:hover .ph-tip{opacity:1;}
.anno-ph .ph-tip::after{content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);border:4px solid transparent;border-top-color:rgba(240,165,0,.3);}
.anno-comment{color:rgba(255,255,255,.2);font-size:8.5px;}

/* Replacement preview (after) */
.anno-replaced{color:var(--ok);background:rgba(0,230,118,.07);border:1px solid rgba(0,230,118,.2);border-radius:3px;padding:0 3px;display:inline-block;}

/* ── PH GUIDE ROWS ── */
.ph-row{display:flex;align-items:center;gap:7px;padding:6px 9px;background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:7px;margin-bottom:4px;}
.ph-col{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--warn);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.ph-val{font-family:'JetBrains Mono',monospace;font-size:8.5px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1.2;}
.ph-cp{background:none;border:1px solid var(--border);color:var(--muted);padding:2px 7px;border-radius:4px;font-family:'JetBrains Mono',monospace;font-size:8px;cursor:pointer;transition:all .15s;flex-shrink:0;}
.ph-cp:hover{border-color:var(--a1);color:var(--a1);}

/* ── TOGGLE SECTION (BEFORE/AFTER) ── */
.ba-toggle{display:flex;background:rgba(255,255,255,.04);border:1px solid var(--border);border-radius:7px;overflow:hidden;margin-bottom:8px;}
.ba-btn{flex:1;padding:6px;border:none;background:none;font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);cursor:pointer;transition:all .15s;}
.ba-btn.active{background:rgba(240,165,0,.15);color:var(--a1);font-weight:700;}

/* ── WORKFLOW MINI ── */
.wf-step{display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px dashed rgba(255,255,255,.06);}
.wf-step:last-child{border-bottom:none;}
.wf-ico{width:28px;height:28px;border-radius:50%;background:rgba(240,165,0,.08);border:1px solid rgba(240,165,0,.12);display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
.wf-txt{flex:1;}
.wf-name{font-family:'Syne',sans-serif;font-size:11px;font-weight:700;color:#fff;}
.wf-desc{font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-top:1px;line-height:1.6;}

/* ── ANALYTICS DASHBOARD ── */
.stat-mini{background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:10px;padding:12px;text-align:center;}
.stat-mini-val{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:#fff;margin-bottom:4px;}
.stat-mini-lbl{font-family:'JetBrains Mono',monospace;font-size:8px;text-transform:uppercase;letter-spacing:1.2px;color:var(--muted);}
.status-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:20px;font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);}
.status-badge-dot{width:6px;height:6px;border-radius:50%;}
</style>
</head>
<body>
<div id="toast-wrap"></div>
<div class="dash-layout">
<?php include __DIR__ . '/_sidebar.php'; ?>
<div class="dash-main">
  <div class="dash-topbar">
    <div class="dash-page-title">📊 CSV Generator Pro</div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <a href="/landing/tutorial.php" class="btn btn-ghost btn-sm">📚 Tutorial</a>
      <a href="/tool/" class="btn btn-amber btn-sm">⚡ BulkReplace →</a>
    </div>
  </div>
  <div class="dash-content">

<?php if (!$has_access): ?>
<div class="scard" style="text-align:center;padding:56px 24px;">
  <div style="font-size:52px;margin-bottom:14px;">🔒</div>
  <div style="font-family:'Syne',sans-serif;font-size:22px;font-weight:800;color:#fff;margin-bottom:8px;">Addon Required</div>
  <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);max-width:380px;margin:0 auto 20px;line-height:1.9;">
    Tersedia di plan <b style="color:var(--a2);">Platinum</b> / <b style="color:#a78bfa;">Lifetime</b>, atau beli addon $19.9.
  </div>
  <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
    <a href="/landing/pricing.php" class="btn btn-amber">⬆️ Upgrade Plan</a>
    <a href="/dashboard/addons.php" class="btn btn-ghost">💎 Beli Addon</a>
  </div>
</div>
<?php else: ?>

<?php if (!$api_key_ok): ?>
<div class="warn-box">
  ⚠️ <b>Google Places API Key belum diset</b> — data menggunakan fallback generator. Set <code>GOOGLE_PLACES_API_KEY</code> di <code>config.php</code> untuk data real.
  <a href="https://console.cloud.google.com/apis/library/places-backend.googleapis.com" target="_blank" style="color:var(--a1);margin-left:6px;">→ Get API Key</a>
</div>
<?php endif; ?>

<!-- STATS -->
<div class="stats-row">
  <div class="sstat"><div class="sstat-v" style="color:var(--a1);" id="s-gen">0</div><div class="sstat-l">Generated</div></div>
  <div class="sstat"><div class="sstat-v" style="color:var(--a2);" id="s-rows">0</div><div class="sstat-l">Total Rows</div></div>
  <div class="sstat"><div class="sstat-v" style="color:var(--ok);" id="s-cache">0</div><div class="sstat-l">Cached Locs</div></div>
  <div class="sstat"><div class="sstat-v" style="color:#a78bfa;" id="s-hits">0</div><div class="sstat-l">Cache Hits</div></div>
</div>

<div class="sys-badges-row">
  <span class="sys-badge badge-off" id="badge-ai" title="Set Smart Bot API Key di config.php">🤖 Bot Parser: OFF</span>
  <span class="sys-badge badge-off" id="badge-google" title="Set GOOGLE_PLACES_API_KEY di config.php">📍 Google Places: OFF</span>
  <span class="sys-badge badge-info">⚡ Fallback: Regex + Generated Data</span>
</div>

<div class="csv-page">
<!-- ════════════════ MAIN COLUMN ════════════════ -->
<div>

<!-- ── STEP 1: DOMAIN INPUT ── -->
<div class="scard">
  <div class="scard-head">
    <div class="snum">1</div>
    <div class="stitle">Input Domain List</div>
    <div id="dom-count" style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);">0 domain</div>
  </div>

  <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-bottom:8px;line-height:1.8;">
    Paste domain per baris. Sistem otomatis extract <b style="color:var(--warn);">keyword</b> + <b style="color:var(--a2);">lokasi/nama</b> dari domain.
    Support: Damkar, Beacukai, Sekolah, RSUD, Bank, Hotel, dll.
  </div>

  <!-- KEYWORD HINT INPUT - BOOST BotACCURACY -->
  <div style="background:rgba(168,85,247,.06);border:1px solid rgba(168,85,247,.2);border-radius:10px;padding:12px 14px;margin-bottom:10px;">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
      <span style="font-size:18px;">💡</span>
      <span style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:rgba(168,85,247,.95);">Keyword Hint</span>
      <span style="font-family:'JetBrains Mono',monospace;font-size:9px;background:rgba(168,85,247,.15);border:1px solid rgba(168,85,247,.3);color:rgba(168,85,247,.95);padding:3px 8px;border-radius:5px;letter-spacing:0.5px;">OPTIONAL - BOT BOOST</span>
    </div>

    <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:rgba(255,255,255,.65);line-height:1.8;margin-bottom:8px;background:rgba(0,0,0,.2);padding:10px;border-radius:6px;">
      <div style="color:rgba(168,85,247,.95);font-weight:700;margin-bottom:6px;">🎯 Kapan pakai Hint?</div>
      <div style="margin-left:14px;">
        ✅ Domain punya <b>prefix sama</b> → <code style="background:rgba(255,255,255,.08);padding:1px 5px;border-radius:3px;color:#a78bfa;">damkar</code>surabaya.com, <code style="background:rgba(255,255,255,.08);padding:1px 5px;border-radius:3px;color:#a78bfa;">damkar</code>jakarta.com<br>
        ✅ Nama instansi <b>tidak jelas</b> dari domain → <code style="background:rgba(255,255,255,.08);padding:1px 5px;border-radius:3px;color:#a78bfa;">office</code>seattle.com (<i>office apa?</i>)<br>
        ✅ Domain pakai <b>singkatan/kode</b> → <code style="background:rgba(255,255,255,.08);padding:1px 5px;border-radius:3px;color:#a78bfa;">bnn</code>balikpapan.com (<i>hint: BNN = Badan Narkotika Nasional</i>)
      </div>
    </div>

    <input type="text" class="dom-ta" id="keyword-hint"
      placeholder="Contoh: damkar, bnn, rsud, kantor, toko, cabang, franchise, dll"
      style="width:100%;margin-bottom:8px;font-size:11px;padding:10px 12px;min-height:auto;">

    <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:rgba(168,85,247,.75);line-height:1.8;background:rgba(168,85,247,.08);padding:8px 10px;border-radius:5px;border-left:3px solid rgba(168,85,247,.5);">
      <b style="color:rgba(168,85,247,.95);">🚀 Cara kerja:</b><br>
      1️⃣ Bot <b>hapus keyword</b> dari domain (damkar<s>jakarta</s> → jakarta)<br>
      2️⃣ Bot <b>parse lokasi</b> yang tersisa (jakarta → Jakarta, DKI Jakarta)<br>
      3️⃣ Bot pakai <b>hint sebagai nama instansi</b> (DAMKAR / Dinas Pemadam Kebakaran)<br>
      <div style="margin-top:6px;color:rgba(168,85,247,.95);">
        📈 <b>Result:</b> Akurasi parsing +40% | Nama instansi 100% konsisten | Lokasi lebih akurat
      </div>
    </div>
  </div>

  <textarea class="dom-ta" id="inp-domains" rows="5"
    placeholder="officeseattle.com&#10;officeportland.com&#10;officeboston.com&#10;regionalnewyork.com&#10;branchaustin.com&#10;&#10;Ctrl+Enter → auto parse"></textarea>

  <div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-top:10px;">
    <button class="btn btn-teal btn-sm" onclick="doParse()">🔍 Parse Domains</button>
    <button class="btn btn-ghost btn-sm" onclick="clearAll()">✕ Clear</button>
    <span id="kw-badge" style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-left:auto;">keyword: <b id="kw-label" style="color:var(--warn);">—</b></span>
  </div>

  <!-- PARSE RESULTS -->
  <div id="parse-section" class="hidden">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:14px;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);">
        📍 Lokasi terdeteksi otomatis · <span id="cache-info" style="color:var(--ok);">cek cache...</span>
      </div>
    </div>
    <div class="parse-grid" id="parse-grid"></div>
  </div>
</div>

<!-- ── STEP 2: FIELDS ── -->
<div class="scard">
  <div class="scard-head">
    <div class="snum">2</div>
    <div class="stitle">Pilih Fields & Suffix</div>
    <div class="sactions">
      <button class="btn btn-ghost btn-sm" onclick="toggleFilters()">🔍 Filters</button>
      <button class="btn btn-ghost btn-sm" onclick="selAll()">✓ All</button>
      <button class="btn btn-ghost btn-sm" onclick="deselAll()">✗ None</button>
      <button class="btn btn-ghost btn-sm" onclick="randSfx()">🎲</button>
    </div>
  </div>

  <div class="phone-row">
    <span class="phone-label">📞 Phone start:</span>
    <input type="text" class="phone-inp" id="phone-start" value="0811-0401-1110">
    <span style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);">+10 per baris</span>
  </div>

  <!-- Core Fields -->
  <div class="field-grid" id="field-grid-core"></div>

  <!-- Additional Fields (collapsible) -->
  <div class="extra-fields-wrap" id="extra-fields-wrap">
    <div class="extra-fields-toggle" onclick="toggleExtra()" id="extra-toggle">
      <span>⚙️ Additional Fields</span>
      <span class="extra-toggle-arrow" id="extra-arrow">▼</span>
    </div>
    <div class="field-grid" id="field-grid-extra" style="display:none;margin-top:8px;"></div>
  </div>

  <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:9px;">
    <span id="fc-count">0</span> field dipilih
    <span style="margin-left:12px;color:rgba(255,255,255,.25);">|</span>
    <span style="margin-left:12px;">Suffix = angka di akhir nama kolom. Harus sama dengan di HTML template kamu.</span>
  </div>
</div>

<!-- ── STEP 2.5: ADVANCED FILTERS ── -->
<div class="scard" id="filters-card" style="display:none;">
  <div class="scard-head">
    <div class="snum" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed);color:#000;">🔍</div>
    <div class="stitle">Advanced Filters</div>
    <button class="btn btn-ghost btn-sm" onclick="resetFilters()">Reset</button>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:10px;">
    <div>
      <label style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);display:block;margin-bottom:4px;">📍 Filter by Location</label>
      <input type="text" id="filter-location" placeholder="e.g., Jakarta, Bandung" style="width:100%;background:rgba(0,0,0,.35);border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-family:'JetBrains Mono',monospace;font-size:11px;color:#fff;">
    </div>
    <div>
      <label style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);display:block;margin-bottom:4px;">🏫 Institution Type</label>
      <select id="filter-institution" style="width:100%;background:rgba(0,0,0,.35);border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-family:'JetBrains Mono',monospace;font-size:11px;color:#fff;">
        <option value="">All Types</option>
        <option value="universitas">Universitas</option>
        <option value="sekolah">Sekolah</option>
        <option value="instansi">Instansi</option>
        <option value="dinas">Dinas</option>
      </select>
    </div>
  </div>

  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
    <label style="display:flex;align-items:center;gap:6px;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);cursor:pointer;">
      <input type="checkbox" id="filter-remove-dupes" style="cursor:pointer;">
      Remove duplicate domains/emails
    </label>
    <label style="display:flex;align-items:center;gap:6px;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);cursor:pointer;">
      <input type="checkbox" id="filter-sort-alpha" style="cursor:pointer;">
      Sort alphabetically
    </label>
  </div>

  <div style="margin-top:10px;padding:8px 12px;background:rgba(139,92,246,.08);border:1px solid rgba(139,92,246,.2);border-radius:8px;font-family:'JetBrains Mono',monospace;font-size:9px;color:rgba(139,92,246,.9);">
    ℹ️ Filters akan diterapkan saat generate CSV
  </div>
</div>

<!-- ── STEP 3: GENERATE ── -->
<div class="scard">
  <div class="scard-head">
    <div class="snum">3</div>
    <div class="stitle">Generate & Download</div>
    <button class="btn btn-ghost btn-sm" onclick="toggleCustomHeaders()" style="margin-left:auto;">✏️ Custom Headers</button>
  </div>

  <!-- CUSTOM HEADERS (collapsible) -->
  <div id="custom-headers-section" style="display:none;margin-bottom:14px;padding:12px;background:rgba(255,255,255,.02);border:1px solid var(--border);border-radius:10px;">
    <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-bottom:8px;">
      Customize CSV column headers (leave blank to use default):
    </div>
    <div id="header-custom-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;">
      <!-- Will be populated dynamically -->
    </div>
    <button class="btn btn-ghost btn-sm" onclick="resetHeaders()" style="margin-top:8px;">Reset to Default</button>
  </div>

  <!-- LIVE LOG -->
  <div class="log-box" id="log-box">
    <div class="le info"><span class="le-ts">--:--</span><span class="le-m">Siap. Lengkapi Step 1 & 2, lalu klik Generate.</span></div>
  </div>

  <!-- PROGRESS -->
  <div class="pbar-wrap">
    <div class="pbar"><div class="pbar-fill" id="pbar-fill"></div></div>
    <div class="pbar-info"><span id="pbar-label">0 / 0</span><span id="pbar-pct">0%</span></div>
  </div>

  <!-- PREVIEW TABLE -->
  <div id="prev-wrap" class="ptable-wrap hidden">
    <table class="ptable"><thead id="prev-head"></thead><tbody id="prev-body"></tbody></table>
    <div id="prev-more" style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);padding:7px 12px;text-align:center;border-top:1px solid var(--border);"></div>
  </div>

  <!-- ACTIONS -->
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:14px;">
    <button class="btn btn-amber" id="btn-gen" onclick="doGenerate()">📥 Generate & Download CSV</button>
    <button class="btn btn-ghost" id="btn-copy" onclick="copyCSV()" style="display:none;">📋 Copy CSV</button>
    <button class="btn btn-ghost" id="btn-retry" style="display:none;border-color:rgba(255,165,0,.4);color:var(--warn);">🔄 Retry</button>
    <span id="gen-info" style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);"></span>
  </div>
</div>

<!-- ── ANALYTICS DASHBOARD ── -->
<div class="scard">
  <div class="scard-head">
    <div class="snum" style="background:linear-gradient(135deg,#06b6d4,#0891b2);color:#000;">📊</div>
    <div class="stitle">Analytics Dashboard</div>
  </div>
  <div id="analytics-wrap">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:12px;">
      <div class="stat-mini">
        <div class="stat-mini-val" id="stat-total-jobs">—</div>
        <div class="stat-mini-lbl">Total Jobs</div>
      </div>
      <div class="stat-mini">
        <div class="stat-mini-val" id="stat-success-rate">—</div>
        <div class="stat-mini-lbl">Success Rate</div>
      </div>
      <div class="stat-mini">
        <div class="stat-mini-val" id="stat-domains">—</div>
        <div class="stat-mini-lbl">Domains Processed</div>
      </div>
      <div class="stat-mini">
        <div class="stat-mini-val" id="stat-avg-time">—</div>
        <div class="stat-mini-lbl">Avg Time</div>
      </div>
    </div>
    <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap;">
      <div class="status-badge" id="badge-success">
        <span class="status-badge-dot" style="background:#10b981;"></span>
        <span id="badge-success-count">0</span> success
      </div>
      <div class="status-badge" id="badge-partial">
        <span class="status-badge-dot" style="background:#f59e0b;"></span>
        <span id="badge-partial-count">0</span> partial
      </div>
      <div class="status-badge" id="badge-failed">
        <span class="status-badge-dot" style="background:#ef4444;"></span>
        <span id="badge-failed-count">0</span> failed
      </div>
      <div class="status-badge">
        <span class="status-badge-dot" style="background:#8b5cf6;"></span>
        BulkReplace Bot: <span id="badge-ai-count">0</span>x
      </div>
      <div class="status-badge">
        <span class="status-badge-dot" style="background:#06b6d4;"></span>
        Google: <span id="badge-google-count">0</span>x
      </div>
    </div>
  </div>
</div>

<!-- ── FAILED JOBS ── -->
<div class="scard" id="failed-jobs-card" style="display:none;">
  <div class="scard-head">
    <div class="snum" style="background:linear-gradient(135deg,#ef4444,#dc2626);color:#000;">⚠️</div>
    <div class="stitle">Failed Domains <span id="failed-count-badge" style="font-size:10px;color:var(--muted);font-weight:400;">(<span id="failed-count">0</span>)</span></div>
    <div style="margin-left:auto;display:flex;gap:6px;">
      <button class="btn btn-ghost btn-sm" onclick="retryFailed()">🔄 Retry All</button>
      <button class="btn btn-ghost btn-sm" onclick="clearFailed()">✕ Clear</button>
    </div>
  </div>
  <div id="failed-wrap" style="max-height:200px;overflow-y:auto;">
    <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);text-align:center;padding:12px;">No failed domains</div>
  </div>
</div>

<!-- ── HISTORY ── -->
<div class="scard">
  <div class="scard-head">
    <div class="snum" style="background:linear-gradient(135deg,rgba(255,255,255,.07),rgba(255,255,255,.02));color:var(--muted);">📋</div>
    <div class="stitle">History</div>
    <button class="btn btn-ghost btn-sm" onclick="clearHistory()" style="margin-left:auto;">🗑️ Clear</button>
  </div>
  <div id="hist-wrap">
    <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);text-align:center;padding:20px;">Loading...</div>
  </div>
</div>

</div>
<!-- ════════════════ SIDEBAR ════════════════ -->
<div class="side-sticky">

  <!-- PLACEHOLDER GUIDE -->
  <div class="side-card" style="border-color:rgba(240,165,0,.2);">
    <div class="side-title">🗂 Placeholder Guide</div>
    <div style="font-family:'JetBrains Mono',monospace;font-size:9.5px;color:var(--muted);margin-bottom:10px;line-height:1.7;">
      Nama kolom CSV = placeholder di HTML. BulkReplace ganti ini secara massal.
    </div>
    <div id="ph-list" style="max-height:240px;overflow-y:auto;">
      <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);text-align:center;padding:10px;">Parse domain dulu</div>
    </div>
    <button id="ph-copy-all" class="btn btn-ghost btn-sm hidden" style="width:100%;justify-content:center;margin-top:8px;" onclick="copyAllPh()">📋 Copy All</button>
  </div>

  <!-- ANNOTATED HTML EXAMPLE -->
  <div class="side-card">
    <div class="side-title">📝 HTML Snippet</div>

    <!-- BEFORE/AFTER TOGGLE -->
    <div class="ba-toggle" id="ba-toggle">
      <button class="ba-btn active" onclick="setBA('before')">Sebelum (placeholder)</button>
      <button class="ba-btn" onclick="setBA('after')">Sesudah (data nyata)</button>
    </div>

    <!-- BEFORE: annotated placeholders -->
    <div id="anno-before">
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-bottom:6px;">Hover placeholder = lihat isi yang akan masuk</div>
      <div class="anno-wrap" id="anno-code">
        <span style="color:rgba(255,255,255,.2);font-size:9px;">— parse domain dulu —</span>
      </div>
    </div>

    <!-- AFTER: replaced values -->
    <div id="anno-after" class="hidden">
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-bottom:6px;">Contoh hasil setelah BulkReplace dijalankan</div>
      <div class="anno-wrap" id="anno-code-after">
        <span style="color:rgba(255,255,255,.2);font-size:9px;">— generate dulu —</span>
      </div>
    </div>
  </div>

  <!-- MINI WORKFLOW -->
  <div class="side-card" style="background:rgba(0,212,170,.03);border-color:rgba(0,212,170,.12);">
    <div class="side-title" style="color:var(--a2);">⚡ Workflow</div>
    <div id="wf-steps">
      <?php
      $wf = [
        ['1','📋','Paste domain list'],
        ['2','🔍','System extract lokasi'],
        ['3','⚙️','Config fields + suffix'],
        ['4','📥','Download CSV'],
        ['5','📝','Paste placeholder ke HTML'],
        ['6','⚡','Run BulkReplace Tool'],
        ['7','🚀','Done!'],
      ];
      foreach ($wf as [$n,$icon,$desc]):
      ?>
      <div class="wf-step">
        <div class="wf-ico"><?= $icon ?></div>
        <div class="wf-txt">
          <div class="wf-name"><?= "Step $n" ?></div>
          <div class="wf-desc"><?= htmlspecialchars($desc) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>
</div><!-- /csv-page -->

<?php endif; ?>
  </div>
</div>
</div><!-- /dash-layout -->

<script>
/* ═══════════════════════════════════════════════════════
   BulkReplace — CSV Generator Pro
   Clean rebuild v17 — no async parse, explicit error catch
═══════════════════════════════════════════════════════ */

// ── Helpers ─────────────────────────────────────────────
function $(id){ return document.getElementById(id); }

function toast(msg, type, dur){
  type = type || 'ok';
  dur  = dur  || 3000;
  var colors = {ok:'var(--ok)', err:'var(--err)', warn:'var(--warn)', info:'var(--a1)'};
  var w = $('toast-wrap');
  if(!w){ console.warn('no toast-wrap'); return; }
  var el = document.createElement('div');
  el.style.cssText = 'background:var(--card);border:1px solid '+(colors[type]||colors.ok)+';padding:10px 18px;border-radius:10px;font-family:monospace;font-size:12px;color:'+(colors[type]||colors.ok)+';margin-bottom:8px;';
  el.textContent = msg;
  w.appendChild(el);
  setTimeout(function(){ if(el.parentNode) el.remove(); }, dur);
}

function esc(s){
  if(!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function phoneInc(base, idx){
  var m = base.match(/^(.*[-\s])(\d+)$/);
  if(m){ return m[1] + (parseInt(m[2],10) + idx*10); }
  var clean = base.replace(/\D/g,'');
  return String(parseInt(clean,10) + idx*10);
}

// ── State ────────────────────────────────────────────────
var parsedDomains = [];
var keyword       = 'data';
var cacheStatus   = {};
var genRunning    = false;
var csvBlob       = null;

// ── Field definitions ─────────────────────────────────
// ── Core Fields (8 field utama — default checked) ─────────────────────
var CORE_FIELDS = [
  {key:'namalink',    icon:'🔗', dsc:'Domain (nama folder)',           sfx:'123'},
  {key:'namalinkurl', icon:'🌐', dsc:'URL lengkap https://domain.com', sfx:'123'},
  {key:'daerah',      icon:'📍', dsc:'Nama daerah / kota / instansi',  sfx:'321'},
  {key:'email',       icon:'📧', dsc:'Email @gmail.com dari domain',   sfx:'123'},
  {key:'notelp',      icon:'📞', dsc:'Nomor telepon (+10/baris)',       sfx:'123'},
  {key:'alamat',      icon:'🏠', dsc:'Alamat real Google Maps',         sfx:'123'},
  {key:'embedmap',    icon:'🗺', dsc:'Google Maps embed iframe URL',   sfx:'123'},
  {key:'linkmaps',    icon:'📌', dsc:'Link Google Maps langsung',      sfx:'123'},
];

// ── Additional Fields (opsional — default unchecked) ────────────────────
var EXTRA_FIELDS = [
  {key:'daerahshort',       icon:'🗺️', dsc:'Nama daerah singkat (tanpa provinsi)',    sfx:'321'},
  {key:'provinsi',          icon:'🏛️', dsc:'Nama provinsi otomatis',                  sfx:'321'},
  {key:'level',             icon:'📊', dsc:'Level: kota/kecamatan/kabupaten/dll',     sfx:'321'},
  {key:'namainstansi',      icon:'🏢', dsc:'Nama instansi/organisasi lengkap',        sfx:'123'},
  {key:'singkataninstansi', icon:'🏷️', dsc:'Singkatan instansi (BPBD, Dinkes, dll)', sfx:'123'},
  {key:'kodepos',           icon:'📮', dsc:'Kode pos wilayah',                        sfx:'123'},
  {key:'placename',         icon:'🏷', dsc:'Nama tempat dari Google Places',          sfx:'123'},
  {key:'rating',            icon:'⭐', dsc:'Rating Google Maps (jika tersedia)',       sfx:'123'},
];

// Combined (core always first, extras after)
var FIELDS = CORE_FIELDS.concat(EXTRA_FIELDS);
var MAX_DOMAINS = 500; // Professional SaaS limit: 500 domains per batch

// ── Region map (abbrev) ──────────────────────────────
var REGIONS = {
  'bandaaceh':'Banda Aceh','sabang':'Sabang','langsa':'Langsa','lhokseumawe':'Lhokseumawe',
  'subulussalam':'Subulussalam','acehbesar':'Aceh Besar','acehbarat':'Aceh Barat',
  'acehjaya':'Aceh Jaya','acehbaratdaya':'Aceh Barat Daya','acehselatan':'Aceh Selatan',
  'acehsingkil':'Aceh Singkil','acehtamiang':'Aceh Tamiang','acehtengah':'Aceh Tengah',
  'acehtenggara':'Aceh Tenggara','acehtimur':'Aceh Timur','acehutara':'Aceh Utara',
  'benermeriah':'Bener Meriah','bireuen':'Bireuen','gayolues':'Gayo Lues',
  'naganraya':'Nagan Raya','pidie':'Pidie',
  'medan':'Medan','binjai':'Binjai','tebingtinggi':'Tebing Tinggi',
  'pematangsiantar':'Pematang Siantar','sibolga':'Sibolga','tanjungbalai':'Tanjung Balai',
  'padangsidimpuan':'Padang Sidimpuan','gunungsitoli':'Gunungsitoli',
  'deliserdang':'Deli Serdang','langkat':'Langkat','karo':'Karo','simalungun':'Simalungun',
  'asahan':'Asahan','labuhanbatu':'Labuhan Batu','labuhanbatuutara':'Labuhan Batu Utara',
  'labuhanbatuselatan':'Labuhan Batu Selatan','tapanuliselatan':'Tapanuli Selatan',
  'tapanulitengah':'Tapanuli Tengah','tapanuliutara':'Tapanuli Utara','tobasa':'Toba Samosir',
  'nias':'Nias','mandailingnatal':'Mandailing Natal','pakpakbharat':'Pakpak Bharat',
  'samosir':'Samosir','serdangbedagai':'Serdang Bedagai','batubara':'Batu Bara',
  'padanglawasutara':'Padang Lawas Utara','padanglawas':'Padang Lawas',
  'niasutara':'Nias Utara','niasselatan':'Nias Selatan','niasbarat':'Nias Barat',
  'padang':'Padang','solok':'Solok','sawahlunto':'Sawahlunto','padangpanjang':'Padang Panjang',
  'bukittinggi':'Bukittinggi','payakumbuh':'Payakumbuh','pariaman':'Pariaman','agam':'Agam',
  'pesisirselatan':'Pesisir Selatan','sijunjung':'Sijunjung','tanahdatar':'Tanah Datar',
  'pasamanbarat':'Pasaman Barat','pasaman':'Pasaman','limapuluhkota':'Lima Puluh Kota',
  'kepulauanmentawai':'Kepulauan Mentawai','dharmasraya':'Dharmasraya','solokselatan':'Solok Selatan',
  'pekanbaru':'Pekanbaru','dumai':'Dumai','kampar':'Kampar','pelalawan':'Pelalawan',
  'rokanhilir':'Rokan Hilir','rokanhulu':'Rokan Hulu','bengkalis':'Bengkalis','siak':'Siak',
  'kuantansingingi':'Kuantan Singingi','indragirihilir':'Indragiri Hilir',
  'indragirihulu':'Indragiri Hulu','kepulauanmeranti':'Kepulauan Meranti',
  'tanjungpinang':'Tanjung Pinang','batam':'Batam','bintan':'Bintan','karimun':'Karimun',
  'natuna':'Natuna','lingga':'Lingga','kepulauananambas':'Kepulauan Anambas',
  'jambi':'Jambi','sungaipenuh':'Sungai Penuh','batanghari':'Batang Hari',
  'muarojambi':'Muaro Jambi','tanjungjabungtimur':'Tanjung Jabung Timur',
  'tanjungjabungbarat':'Tanjung Jabung Barat','tebo':'Tebo','bungo':'Bungo',
  'merangin':'Merangin','sarolangun':'Sarolangun','kerinci':'Kerinci',
  'palembang':'Palembang','prabumulih':'Prabumulih','pagaralam':'Pagar Alam',
  'lubuklinggau':'Lubuk Linggau','oganilir':'Ogan Ilir','ogankomeringilir':'Ogan Komering Ilir',
  'musirawas':'Musi Rawas','musibanyuasin':'Musi Banyuasin','lahat':'Lahat',
  'empatlawang':'Empat Lawang','muaraenim':'Muara Enim','banyuasin':'Banyuasin',
  'pangkalpinang':'Pangkal Pinang','bangka':'Bangka','belitung':'Belitung',
  'bangkatengah':'Bangka Tengah','bangkabarat':'Bangka Barat','bangkaselatan':'Bangka Selatan',
  'belitungtimur':'Belitung Timur',
  'bengkulu':'Bengkulu','kaur':'Kaur','seluma':'Seluma','mukomuko':'Mukomuko',
  'lebong':'Lebong','rejanglebong':'Rejang Lebong','kepahiang':'Kepahiang',
  'bengkuluutara':'Bengkulu Utara','bengkuluselatan':'Bengkulu Selatan',
  'bandarlampung':'Bandar Lampung','metro':'Metro','lampungbarat':'Lampung Barat',
  'lampungselatan':'Lampung Selatan','lampungtengah':'Lampung Tengah',
  'lampungutara':'Lampung Utara','lampungtimur':'Lampung Timur',
  'serang':'Serang','cilegon':'Cilegon','tangerang':'Tangerang',
  'tangerangselatan':'Tangerang Selatan','pandeglang':'Pandeglang','lebak':'Lebak',
  'jakartapusat':'Jakarta Pusat','jakartautara':'Jakarta Utara',
  'jakartabarat':'Jakarta Barat','jakartaselatan':'Jakarta Selatan',
  'jakartatimur':'Jakarta Timur','jakarta':'Jakarta','kepulauanseribu':'Kepulauan Seribu',
  'dki':'DKI Jakarta',
  // ── Jakarta Kecamatan (sering dipakai di domain) ─────────────────────
  'setiabudi':'Setiabudi','tebet':'Tebet','pancoran':'Pancoran',
  'kebayoranbaru':'Kebayoran Baru','kebayoranlama':'Kebayoran Lama',
  'pesanggrahan':'Pesanggrahan','cilandak':'Cilandak','pasar minggu':'Pasar Minggu',
  'pasarminggu':'Pasar Minggu','jagakarsa':'Jagakarsa','mampangjati':'Mampang Prapatan',
  'mampang':'Mampang','pulo':'Pulo Gadung',
  'pulogadung':'Pulogadung','cakung':'Cakung','duren sawit':'Duren Sawit',
  'durensawit':'Duren Sawit','makasar':'Makasar','kramatjati':'Kramat Jati',
  'jatinegara':'Jatinegara','ciracas':'Ciracas','cipayung':'Cipayung',
  'penjaringan':'Penjaringan','pademangan':'Pademangan','tanjungpriok':'Tanjung Priok',
  'koja':'Koja','cilincing':'Cilincing','kelapa gading':'Kelapa Gading',
  'kelapagading':'Kelapa Gading','cempaka putih':'Cempaka Putih',
  'cempakaputih':'Cempaka Putih','johar baru':'Johar Baru',
  'joharbaru':'Johar Baru','kemayoran':'Kemayoran','sawah besar':'Sawah Besar',
  'sawahbesar':'Sawah Besar','senen':'Senen','tanah abang':'Tanah Abang',
  'tanahabang':'Tanah Abang','gambir':'Gambir','menteng':'Menteng',
  'kalideres':'Kalideres','cengkareng':'Cengkareng','grogol':'Grogol',
  'tambora':'Tambora','tamansari':'Taman Sari','palmerah':'Palmerah',
  'kebon jeruk':'Kebon Jeruk','kebonjeruk':'Kebon Jeruk','kembangan':'Kembangan',
  // ── Tangerang kecamatan ───────────────────────────────────────────────
  'gadingserpong':'Gading Serpong','serpong':'Serpong','cisauk':'Cisauk',
  'pamulang':'Pamulang','ciputat':'Ciputat','pondok aren':'Pondok Aren',
  'pondokaren':'Pondok Aren','bintaro':'Bintaro','larangan':'Larangan','ciledug':'Ciledug',
  'karangtengan':'Karang Tengah','benda':'Benda','neglasari':'Neglasari',
  'batuceper':'Batu Ceper','cibodas':'Cibodas','periuk':'Periuk',
  // ── Bekasi / Depok area ──────────────────────────────────────────────
  'jatiasih':'Jatiasih','bantargebang':'Bantar Gebang','pondokgede':'Pondok Gede',
  'rawalumbu':'Rawa Lumbu','bekasibarat':'Bekasi Barat','bekasitimur':'Bekasi Timur',
  'bekasiselatan':'Bekasi Selatan','bekasiutara':'Bekasi Utara',
  'cipayung':'Cipayung','cimanggis':'Cimanggis','sukmajaya':'Sukmajaya',
  'beji':'Beji','cilodong':'Cilodong','cinere':'Cinere','sawangan':'Sawangan',
  // ── Bogor area ────────────────────────────────────────────────────────
  'cibinong':'Cibinong','gunung putri':'Gunung Putri','gunungputri':'Gunung Putri',
  'cileungsi':'Cileungsi','jonggol':'Jonggol','cariu':'Cariu',
  'bandungbarat':'Bandung Barat','bandung':'Bandung','bekasi':'Bekasi',
  'depok':'Depok','bogor':'Bogor','sukabumi':'Sukabumi','cimahi':'Cimahi',
  'tasikmalaya':'Tasikmalaya','cirebon':'Cirebon','majalengka':'Majalengka',
  'sumedang':'Sumedang','indramayu':'Indramayu','subang':'Subang',
  'purwakarta':'Purwakarta','karawang':'Karawang','cianjur':'Cianjur',
  'garut':'Garut','pangandaran':'Pangandaran','kuningan':'Kuningan','ciamis':'Ciamis',
  'semarang':'Semarang','surakarta':'Surakarta','salatiga':'Salatiga',
  'magelang':'Magelang','pekalongan':'Pekalongan','tegal':'Tegal',
  'kudus':'Kudus','jepara':'Jepara','demak':'Demak','grobogan':'Grobogan',
  'blora':'Blora','rembang':'Rembang','pati':'Pati','kendal':'Kendal',
  'batang':'Batang','pemalang':'Pemalang','brebes':'Brebes',
  'banyumas':'Banyumas','purbalingga':'Purbalingga','banjarnegara':'Banjarnegara',
  'kebumen':'Kebumen','purworejo':'Purworejo','wonosobo':'Wonosobo',
  'boyolali':'Boyolali','klaten':'Klaten','sukoharjo':'Sukoharjo',
  'wonogiri':'Wonogiri','karanganyar':'Karanganyar','sragen':'Sragen',
  'cilacap':'Cilacap','temanggung':'Temanggung',
  'yogyakarta':'Yogyakarta','bantul':'Bantul','sleman':'Sleman',
  'gunungkidul':'Gunung Kidul','kulonprogo':'Kulon Progo',
  'surabaya':'Surabaya','malang':'Malang','mojokerto':'Mojokerto',
  // Surabaya kecamatan
  'pakuwon':'Pakuwon','darmo':'Darmo','wonokromo':'Wonokromo',
  'gubeng':'Gubeng','tegalsari':'Tegalsari','genteng':'Genteng',
  'bubutan':'Bubutan','simokerto':'Simokerto','semampir':'Semampir',
  'pabean':'Pabean Cantian','krembangan':'Krembangan',
  'kenjeran':'Kenjeran','bulak':'Bulak','tambaksari':'Tambaksari',
  'mulyorejo':'Mulyorejo','sukolilo':'Sukolilo','rungkut':'Rungkut',
  'tenggilis':'Tenggilis Mejoyo','gunung':'Gunung Anyar',
  'wonocolo':'Wonocolo','wiyung':'Wiyung','karang':'Karang Pilang',
  'jambangan':'Jambangan','gayungan':'Gayungan','dukuh':'Dukuh Pakis',
  'sawahan':'Sawahan','sukomanunggal':'Sukomanunggal','tandes':'Tandes',
  'sambikerep':'Sambikerep','lakarsantri':'Lakarsantri','benowo':'Benowo',
  'pakal':'Pakal','asemrowo':'Asemrowo','krembangan':'Krembangan',
  'perak':'Perak','bongkaran':'Bongkaran',
  'pasuruan':'Pasuruan','probolinggo':'Probolinggo','blitar':'Blitar',
  'kediri':'Kediri','madiun':'Madiun','batu':'Batu','sidoarjo':'Sidoarjo',
  'gresik':'Gresik','lamongan':'Lamongan','tuban':'Tuban',
  'bojonegoro':'Bojonegoro','ngawi':'Ngawi','magetan':'Magetan',
  'nganjuk':'Nganjuk','jombang':'Jombang','bangkalan':'Bangkalan',
  'sampang':'Sampang','pamekasan':'Pamekasan','sumenep':'Sumenep',
  'jember':'Jember','bondowoso':'Bondowoso','situbondo':'Situbondo',
  'banyuwangi':'Banyuwangi','lumajang':'Lumajang','trenggalek':'Trenggalek',
  'tulungagung':'Tulungagung','ponorogo':'Ponorogo','pacitan':'Pacitan',
  'denpasar':'Denpasar','badung':'Badung','gianyar':'Gianyar',
  'tabanan':'Tabanan','jembrana':'Jembrana','buleleng':'Buleleng',
  'bangli':'Bangli','klungkung':'Klungkung','karangasem':'Karangasem',
  'mataram':'Mataram','bima':'Bima','lombokbarat':'Lombok Barat',
  'lombokutara':'Lombok Utara','lomboktengah':'Lombok Tengah',
  'lombokimur':'Lombok Timur','sumbawa':'Sumbawa','dompu':'Dompu',
  'sumbawabarat':'Sumbawa Barat',
  'kupang':'Kupang','ende':'Ende','sikka':'Sikka','ngada':'Ngada',
  'manggarai':'Manggarai','manggaraibarat':'Manggarai Barat',
  'manggaraitimur':'Manggarai Timur',
  'pontianak':'Pontianak','singkawang':'Singkawang','kuburay':'Kubu Raya',
  'sambas':'Sambas','bengkayang':'Bengkayang','landak':'Landak',
  'mempawah':'Mempawah','sanggau':'Sanggau','sekadau':'Sekadau',
  'sintang':'Sintang','melawi':'Melawi','kapuashulu':'Kapuas Hulu',
  'kayongutara':'Kayong Utara','ketapang':'Ketapang',
  'palangkaraya':'Palangka Raya','katingan':'Katingan',
  'kotawaringinbarat':'Kotawaringin Barat','kotawaringintimur':'Kotawaringin Timur',
  'kapuas':'Kapuas','baritoutara':'Barito Utara','baritokuala':'Barito Kuala',
  'banjarmasin':'Banjarmasin','banjarbaru':'Banjarbaru','balangan':'Balangan',
  'tabalong':'Tabalong','hulutengah':'Hulu Sungai Tengah',
  'tanahlaut':'Tanah Laut','tanahumbu':'Tanah Bumbu','kotabaru':'Kotabaru',
  'samarinda':'Samarinda','balikpapan':'Balikpapan','bontang':'Bontang',
  'kutaikartanegara':'Kutai Kartanegara','kutaibarat':'Kutai Barat',
  'kutaitimur':'Kutai Timur','berau':'Berau','paser':'Paser',
  'penajam':'Penajam Paser Utara','mahakamulu':'Mahakam Ulu',
  'tanjungselor':'Tanjung Selor','tarakan':'Tarakan','nunukan':'Nunukan',
  'bulungan':'Bulungan','malinau':'Malinau',
  'manado':'Manado','bitung':'Bitung','tomohon':'Tomohon',
  'kotamobagu':'Kotamobagu','minahasautara':'Minahasa Utara',
  'minahasaselatan':'Minahasa Selatan','minahasa':'Minahasa',
  'bolaangmongondow':'Bolaang Mongondow',
  'kepulauansangihe':'Kepulauan Sangihe','kepulauantalaud':'Kepulauan Talaud',
  'gorontalo':'Gorontalo','bonebolango':'Bone Bolango','boalemo':'Boalemo',
  'palu':'Palu','donggala':'Donggala','sigi':'Sigi',
  'parigimoutong':'Parigi Moutong','parigi':'Parigi',
  'tolioli':'Toli-Toli','buol':'Buol','morowali':'Morowali',
  'tojoununa':'Tojo Una-Una','banggai':'Banggai',
  'makassar':'Makassar','parepare':'Parepare','palopo':'Palopo',
  'gowa':'Gowa','maros':'Maros','takalar':'Takalar','jeneponto':'Jeneponto',
  'bantaeng':'Bantaeng','bulukumba':'Bulukumba','sinjai':'Sinjai',
  'bone':'Bone','soppeng':'Soppeng','wajo':'Wajo',
  'sidenrengrapang':'Sidenreng Rappang','pinrang':'Pinrang',
  'enrekang':'Enrekang','luwu':'Luwu','luwuutara':'Luwu Utara',
  'luwutimur':'Luwu Timur','tanahtoraja':'Tana Toraja',
  'kepulauanselayar':'Kepulauan Selayar','pangkep':'Pangkep','barru':'Barru',
  'kendari':'Kendari','baubau':'Bau-Bau','kolaka':'Kolaka',
  'konawe':'Konawe','muna':'Muna','buton':'Buton','bombana':'Bombana',
  'wakatobi':'Wakatobi','mamuju':'Mamuju','majene':'Majene',
  'ambon':'Ambon','tual':'Tual','malukutengah':'Maluku Tengah',
  'malukutenggara':'Maluku Tenggara','malukubaratdaya':'Maluku Barat Daya',
  'malukuutara':'Maluku Utara','ternate':'Ternate','tidore':'Tidore Kepulauan',
  'halmaherautara':'Halmahera Utara','halmaheraselatan':'Halmahera Selatan',
  'halmahera':'Halmahera','morotai':'Pulau Morotai',
  'jayapura':'Jayapura','sorong':'Sorong','manokwari':'Manokwari',
  'fakfak':'Fak-Fak','kaimana':'Kaimana','nabire':'Nabire',
  'mimika':'Mimika','merauke':'Merauke','biak':'Biak',
  'ntb':'NTB','ntt':'NTT','aceh':'Aceh',
};

// Known multi-segment domains where sub3 is NOT part of the domain name
var STRIP_SUBS = ['go','co','sch','ac','or','net','org','web','my','id','kemdikbud','kemkes','kemendagri','polri','esdm'];

function parseDomain(raw, keywordHint){
  try {
    var domain = raw.toLowerCase().replace(/^https?:\/\//,'').split('/')[0].trim();
    var parts = domain.split('.');
    // Remove TLD from right, keep stripping known sub-extensions
    while(parts.length > 1 && STRIP_SUBS.indexOf(parts[parts.length-1]) >= 0) parts.pop();
    // The meaningful part is the leftmost segment (before first dot in remaining)
    var mainPart = parts[0]; // e.g. "kpai-kabsidoarjo" or "pormikimataram" or "lpmpaceh"

    // Normalize: lowercase, remove hyphens for matching
    var slug = mainPart.replace(/-/g,'');

    // KEYWORD HINT LOGIC — strip keyword prefix if provided
    var instProperHint = '';
    if(keywordHint){
      var kwNorm = keywordHint.replace(/[-_]/g,'').toLowerCase();
      if(slug.indexOf(kwNorm) === 0){
        slug = slug.slice(kwNorm.length);  // Remove keyword prefix
        instProperHint = acronymOrTitle(keywordHint);  // Use hint as institution
      }
    }

    // Build sorted region keys, longest first (greedy)
    var RKEYS = Object.keys(REGIONS).sort(function(a,b){ return b.length-a.length; });

    // Find longest region match
    var regionKey = null, regionPos = -1;
    for(var i=0; i<RKEYS.length; i++){
      var k = RKEYS[i];
      var pos = slug.indexOf(k);
      if(pos >= 0){ regionKey = k; regionPos = pos; break; }
    }

    var regionDisplay, keyword, geoMod='', instSlug='';

    if(regionKey){
      regionDisplay = REGIONS[regionKey];
      keyword = regionDisplay.toLowerCase().replace(/[\s\.]/g,'-').replace(/-+/g,'-');

      var before = slug.slice(0, regionPos);          // everything before region
      var after  = slug.slice(regionPos+regionKey.length); // after region (usually empty or digits)

      // Detect kab/kota RIGHT BEFORE region in 'before'
      if(/kab$/.test(before)){
        geoMod = 'kab';
        instSlug = before.slice(0,-3);
      } else if(/kota$/.test(before)){
        geoMod = 'kota';
        instSlug = before.slice(0,-4);
      } else if(/^kab/.test(before) && before.length <= 5){
        geoMod = 'kab';
        instSlug = before.slice(3);
      } else if(/^kota/.test(before) && before.length <= 6){
        geoMod = 'kota';
        instSlug = before.slice(4);
      } else {
        instSlug = before;
      }
      // Strip trailing numbers (sman1, smkn3)
      instSlug = instSlug.replace(/\d+$/,'');

    } else {
      // No region → whole slug is institution/entity
      instSlug = slug;
      regionDisplay = toProperCase(slug);
      keyword = slug;
    }

    // Build display
    var display = geoMod==='kab' ? 'Kab. '+regionDisplay
                : geoMod==='kota' ? 'Kota '+regionDisplay
                : regionDisplay;

    // Institution proper (use hint if available, else detect from slug)
    var instProper = instProperHint || (instSlug ? acronymOrTitle(instSlug) : '');
    var searchQuery = instProper ? (instProper+' '+display) : display;

    return {
      full_domain:  domain,
      keyword:      keyword || 'data',
      location_slug: regionKey || slug,
      location_display: display,
      institution:  instProper,
      search_query: searchQuery,
      email_domain: (instSlug||slug.slice(0,8))+'@gmail.com',
    };
  } catch(e){
    return { full_domain:raw, keyword:'data', location_slug:'unknown',
             location_display:'—', institution:'', search_query:raw, email_domain:'data@gmail.com' };
  }
}

// Known acronyms → proper uppercase display
var ACRONYMS = {
  'kpai':'KPAI','bpbd':'BPBD','rsud':'RSUD','dinkes':'Dinkes','dishub':'Dishub',
  'dinas':'Dinas','dispora':'Dispora','diskominfo':'Diskominfo','bpjs':'BPJS',
  'kpu':'KPU','kpud':'KPUD','dprd':'DPRD','dprk':'DPRK','bawaslu':'Bawaslu',
  'polres':'Polres','polsek':'Polsek','kejaksaan':'Kejaksaan',
  'pormiki':'Pormiki','perbasi':'Perbasi','persi':'Persi','pdgi':'PDGI',
  'idi':'IDI','ibi':'IBI','ppni':'PPNI','hipmi':'HIPMI','kadin':'KADIN',
  'iwapi':'IWAPI','apindo':'Apindo','hmi':'HMI','pmii':'PMII','imm':'IMM',
  'knpi':'KNPI','bnnp':'BNNP','bnnk':'BNNK',
  'lpmp':'LPMP','lpse':'LPSE','mpp':'MPP','bkd':'BKD','bkpsdm':'BKPSDM',
  'bappeda':'Bappeda','pemkot':'Pemkot','pemkab':'Pemkab',
  'sman':'SMAN','smpn':'SMPN','smkn':'SMKN','sdn':'SDN','mtsn':'MTsN',
  'man':'MAN','uin':'UIN','iain':'IAIN','pss':'PSS','pssi':'PSSI',
  'porbasi':'Porbasi','pormikibandung':'Pormiki',
  'hipmijakarta':'Hipmi','bpbddki':'BPBD',
  'aptisi':'APTISI','beacukai':'Bea Cukai','damkar':'Damkar','butikemas':'Butikemas',
};

function acronymOrTitle(s){
  if(!s) return '';
  if(ACRONYMS[s]) return ACRONYMS[s];
  // Fallback: capitalize first letter
  return s.charAt(0).toUpperCase()+s.slice(1);
}
// Known acronyms → proper uppercase display
var ACRONYMS = {
  'kpai':'KPAI','bpbd':'BPBD','rsud':'RSUD','dinkes':'Dinkes','dishub':'Dishub',
  'dinas':'Dinas','dispora':'Dispora','diskominfo':'Diskominfo','bpjs':'BPJS',
  'kpu':'KPU','kpud':'KPUD','dprd':'DPRD','dprk':'DPRK','bawaslu':'Bawaslu',
  'polres':'Polres','polsek':'Polsek','kejaksaan':'Kejaksaan',
  'pormiki':'Pormiki','perbasi':'Perbasi','persi':'Persi','pdgi':'PDGI',
  'idi':'IDI','ibi':'IBI','ppni':'PPNI','hipmi':'HIPMI','kadin':'KADIN',
  'iwapi':'IWAPI','apindo':'Apindo','hmi':'HMI','pmii':'PMII','imm':'IMM',
  'knpi':'KNPI','bnnp':'BNNP','bnnk':'BNNK',
  'lpmp':'LPMP','lpse':'LPSE','mpp':'MPP','bkd':'BKD','bkpsdm':'BKPSDM',
  'bappeda':'Bappeda','pemkot':'Pemkot','pemkab':'Pemkab',
  'sman':'SMAN','smpn':'SMPN','smkn':'SMKN','sdn':'SDN','mtsn':'MTsN',
  'man':'MAN','uin':'UIN','iain':'IAIN','pss':'PSS','pssi':'PSSI',
  'porbasi':'Porbasi','pormikibandung':'Pormiki',
  'hipmijakarta':'Hipmi','bpbddki':'BPBD',
  'aptisi':'APTISI','beacukai':'Bea Cukai','damkar':'Damkar','butikemas':'Butikemas',
};

function acronymOrTitle(s){
  if(!s) return '';
  if(ACRONYMS[s]) return ACRONYMS[s];
  // Fallback: capitalize first letter
  return s.charAt(0).toUpperCase()+s.slice(1);
}

function toProperCase(s){
  if(!s) return '';
  return s.replace(/(?:^|[-_])(\w)/g,function(_,c){return c.toUpperCase();}).replace(/[-_]/g,' ');
}


// ── initFields ────────────────────────────────────────
function makeFieldCard(f, checked){
  var chkAttr = checked ? 'checked' : '';
  var onClass = checked ? 'fcard on' : 'fcard';
  return '<div class="'+onClass+'" id="fc-'+f.key+'" onclick="toggleF(\''+f.key+'\')">'
    +'<div class="fc-top">'
    +'<span class="fc-icon">'+f.icon+'</span>'
    +'<div class="fc-info"><div class="fc-key">'+f.key+'</div><div class="fc-dsc">'+f.dsc+'</div></div>'
    +'<input type="checkbox" class="fc-chk" id="fchk-'+f.key+'" '+chkAttr+'>'
    +'</div>'
    +'<div class="fc-sfx-row">'
    +'<label class="fc-sfx-lbl">Suffix:</label>'
    +'<input class="fc-sfx-inp" id="sfx-'+f.key+'" type="text" value="'+f.sfx+'" maxlength="8" oninput="rebuildSidebar()">'
    +'</div>'
    +'<div class="fc-preview" id="fpr-'+f.key+'">'+f.key+(keyword||'data')+f.sfx+'</div>'
    +'</div>';
}

function initFields(){
  // Render core fields (always checked)
  var coreGrid = $('field-grid-core');
  if(coreGrid){
    coreGrid.innerHTML = CORE_FIELDS.map(function(f){ return makeFieldCard(f, true); }).join('');
  }
  // Render extra fields (always unchecked by default)
  var extraGrid = $('field-grid-extra');
  if(extraGrid){
    extraGrid.innerHTML = EXTRA_FIELDS.map(function(f){ return makeFieldCard(f, false); }).join('');
  }
  updateFCount();
  rebuildSidebar();
}

function toggleExtra(){
  var grid  = $('field-grid-extra');
  var arrow = $('extra-arrow');
  if(!grid) return;
  var open = grid.style.display !== 'none';
  grid.style.display  = open ? 'none' : 'grid';
  if(arrow) arrow.textContent = open ? '▼' : '▲';
}

function toggleF(key){
  var chk  = $('fchk-'+key);
  var card = $('fc-'+key);
  if(!chk||!card) return;
  chk.checked = !chk.checked;
  card.classList.toggle('on', chk.checked);
  updateFCount();
  rebuildSidebar();
}

function selAll(){
  // Select all CORE + all EXTRA (show extra section)
  FIELDS.forEach(function(f){
    var chk=$('fchk-'+f.key), card=$('fc-'+f.key);
    if(chk) chk.checked=true;
    if(card) card.classList.add('on');
  });
  // Auto-open extra section
  var eg=$('field-grid-extra'), ea=$('extra-arrow');
  if(eg) eg.style.display='grid';
  if(ea) ea.textContent='▲';
  updateFCount(); rebuildSidebar();
}

function deselAll(){
  // Deselect all — but keep core visible, collapse extra
  FIELDS.forEach(function(f){
    var chk=$('fchk-'+f.key), card=$('fc-'+f.key);
    if(chk) chk.checked=false;
    if(card) card.classList.remove('on');
  });
  var eg=$('field-grid-extra'), ea=$('extra-arrow');
  if(eg) eg.style.display='none';
  if(ea) ea.textContent='▼';
  updateFCount(); rebuildSidebar();
}

function randSfx(){
  var n = Math.floor(100+Math.random()*900);
  FIELDS.forEach(function(f){ var el=$('sfx-'+f.key); if(el) el.value=n; });
  rebuildSidebar();
}

function updateFCount(){
  var n = FIELDS.filter(function(f){ var c=$('fchk-'+f.key); return c&&c.checked; }).length;
  var el = $('fc-count');
  if(el) el.textContent = n+' field dipilih';
}

function getSfx(key){ var el=$('sfx-'+key); return el?el.value.trim():(key==='daerah'?'321':'123'); }

function getActive(){
  return FIELDS.filter(function(f){ var c=$('fchk-'+f.key); return c&&c.checked; });
}

function rebuildSidebar(){
  var kw = keyword||'data';
  // Update preview text inside each card
  FIELDS.forEach(function(f){
    var el = $('fpr-'+f.key);
    if(el) el.textContent = f.key + kw + getSfx(f.key);
  });
  // Update sidebar placeholder guide
  var list = $('ph-list');
  if(!list) return;
  var active = getActive();
  if(!active.length){
    list.innerHTML = '<div style="font-family:monospace;font-size:10px;color:var(--muted);text-align:center;padding:8px;">Pilih field dulu</div>';
    var ca = $('ph-copy-all');
    if(ca) ca.classList.add('hidden');
    return;
  }
  list.innerHTML = active.map(function(f){
    var ph = f.key + kw + getSfx(f.key);
    return '<div class="ph-item"><span class="anno-ph" title="Placeholder">'+esc(ph)+'</span></div>';
  }).join('');
  var ca = $('ph-copy-all');
  if(ca) ca.classList.remove('hidden');

  // Update anno preview
  var before = $('anno-code');
  if(before){
    before.innerHTML = active.map(function(f){
      return '<div><span class="anno-ph">'+esc(f.key+kw+getSfx(f.key))+'</span></div>';
    }).join('');
  }
}

// ── copyAllPh ─────────────────────────────────────────
function copyAllPh(){
  var kw = keyword||'data';
  var active = getActive();
  if(!active.length){ toast('Pilih field dulu','warn'); return; }
  var text = active.map(function(f){ return f.key+kw+getSfx(f.key); }).join('\n');
  navigator.clipboard.writeText(text).then(function(){
    toast('Placeholder disalin ke clipboard ✅','ok');
  }).catch(function(){ toast('Gagal copy','err'); });
}

// ── doParse ───────────────────────────────────────────
function doParse(){
  try {
    var ta = $('inp-domains');
    if(!ta){ toast('Element not found','err'); return; }
    var raw = ta.value.trim();
    if(!raw){ toast('Paste domain dulu!','err'); return; }
    var doms = raw.split('\n').map(function(s){ return s.trim(); }).filter(function(s){ return s.length>3; });
    if(!doms.length){ toast('Tidak ada domain valid','err'); return; }
    if(doms.length > MAX_DOMAINS){ doms = doms.slice(0,MAX_DOMAINS); toast('Limit '+MAX_DOMAINS+' domain, sisanya diabaikan','warn'); }

    // Get keyword hint from user input
    var keywordHintEl = $('keyword-hint');
    var keywordHint = keywordHintEl ? keywordHintEl.value.trim().toLowerCase() : '';
    if(keywordHint){
      toast('💡 Hint Active: "'+keywordHint+'" → Botakan parse lebih akurat!', 'info', 4000);
    }

    parsedDomains = doms.map(function(d){ return parseDomain(d, keywordHint); });
    keyword = (parsedDomains[0] && parsedDomains[0].keyword) ? parsedDomains[0].keyword : 'data';

    // Update UI
    var dcEl = $('dom-count');
    if(dcEl) dcEl.textContent = doms.length+' domain';
    var kwEl = $('kw-label');
    if(kwEl){
      kwEl.textContent = keyword;
      // Add hint indicator next to keyword
      if(keywordHint){
        kwEl.innerHTML = keyword + ' <span style="font-size:9px;color:rgba(168,85,247,.95);margin-left:4px;" title="Keyword hint active: '+keywordHint+'">💡</span>';
      }
    }
    var ciEl = $('cache-info');
    if(ciEl){ ciEl.textContent = 'mengecek cache...'; ciEl.style.color='var(--muted)'; }

    // Render parse grid
    var pg = $('parse-grid');
    if(pg){
      pg.innerHTML = parsedDomains.map(function(p,i){
        var levelColors = {nasional:'#a78bfa',provinsi:'#60a5fa',kabupaten:'#f0a500',kota:'#00d4aa',kecamatan:'#34d399',kelurahan:'#6ee7b7'};
        var lvl   = p.location_level || 'kota';
        var lvlCl = levelColors[lvl] || '#888';
        var inst  = p.institution || '';
        var prov  = p.province || '';
        var dispLine = p.location_display || '—';
        if(prov && prov !== p.location_display) dispLine = p.location_display ? p.location_display+', '+prov : prov;
        return '<div class="pcard" id="pi-'+i+'">'
          +'<div class="pc-badge pb-scrape">⏳</div>'
          +'<div class="pc-dom" title="'+esc(p.full_domain)+'">'+esc(p.full_domain)+'</div>'
          +(inst ? '<div class="pc-inst">'+esc(inst)+'</div>' : '')
          +'<div class="pc-loc">'+esc(dispLine)+'</div>'
          +'<div style="display:flex;gap:4px;align-items:center;margin-top:2px;">'
            +'<span class="pc-kw">'+esc(p.keyword||'—')+'</span>'
            +'<span style="font-size:8px;padding:1px 5px;border-radius:4px;background:rgba(255,255,255,.05);color:'+lvlCl+';border:1px solid '+lvlCl+'33;font-family:monospace;">'+lvl+'</span>'
          +'</div>'
          +'</div>';
      }).join('');
    }
    var ps = $('parse-section');
    if(ps) ps.classList.remove('hidden');

    rebuildSidebar();

    // Show toast with hint indicator
    var toastMsg = doms.length+' domain parsed — keyword: '+keyword;
    if(keywordHint){
      toastMsg += ' 💡';
    }
    toast(toastMsg, 'ok');

    // Async: check cache (non-blocking)
    setTimeout(function(){ _checkCacheAsync(doms); }, 50);

  } catch(e){
    console.error('doParse error:', e);
    toast('Error: '+e.message, 'err');
  }
}

function _checkCacheAsync(doms){
  fetch('/api/csv_generator.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'check_cache', domains:doms})
  })
  .then(function(r){ return r.json(); })
  .then(function(data){
    cacheStatus = data.cache || {};
    var cached = 0;
    parsedDomains.forEach(function(p,i){
      var c = cacheStatus[p.full_domain];
      var el = $('pi-'+i); if(!el) return;
      if(c && c.cached){
        cached++;
        el.classList.add('cached');
        el.querySelector('.pc-badge').className='pc-badge pb-cache';
        el.querySelector('.pc-badge').textContent='✅';
      } else {
        el.classList.add('scraping');
        el.querySelector('.pc-badge').className='pc-badge pb-scrape';
        el.querySelector('.pc-badge').textContent='🔍';
      }
    });
    var ci = $('cache-info');
    if(ci){
      ci.textContent = cached+' cached · '+(doms.length-cached)+' akan discrape';
      ci.style.color = cached===doms.length ? 'var(--ok)' : 'var(--warn)';
    }
  })
  .catch(function(e){ console.warn('cache check failed:',e); });
}

// ── clearAll ──────────────────────────────────────────
function clearAll(){
  var ta=$('inp-domains'); if(ta) ta.value='';
  var ps=$('parse-section'); if(ps) ps.classList.add('hidden');
  var pw=$('prev-wrap'); if(pw) pw.classList.add('hidden');
  var lb=$('log-box'); if(lb) lb.innerHTML='';
  var gi=$('gen-info'); if(gi) gi.textContent='';
  parsedDomains=[]; keyword='data'; cacheStatus={};
  var dc=$('dom-count'); if(dc) dc.textContent='0 domain';
  var kw=$('kw-label'); if(kw) kw.textContent='—';
  toast('Cleared','ok',1500);
}

// ── doGenerate ────────────────────────────────────────
async function doGenerate(){
  if(genRunning){ toast('Generate sedang berjalan...','warn'); return; }
  if(!parsedDomains.length){ toast('Parse domain dulu!','err'); return; }
  var active = getActive();
  if(!active.length){ toast('Pilih minimal 1 field!','err'); return; }

  var btn = $('btn-gen');
  if(btn){ btn.disabled=true; btn.innerHTML='<span class="spinner"></span> Memulai...'; }
  genRunning = true;
  csvBlob = null;

  var lb = $('log-box');
  if(lb) lb.innerHTML='';
  var gi = $('gen-info');
  if(gi) gi.textContent='';

  addLog('info','🚀 BulkReplace Bot™ CSV Generator — Starting...');
  addLog('info','📊 Total Domains: ' + parsedDomains.length);

  var doms = parsedDomains.map(function(p){ return p.full_domain; });

  // Apply filters
  var filters = getFilters();
  if(filters.location){
    var locLower = filters.location.toLowerCase();
    doms = doms.filter(function(d){
      var p = parsedDomains.find(function(pd){ return pd.full_domain === d; });
      return p && p.location_display && p.location_display.toLowerCase().includes(locLower);
    });
    addLog('info','🔍 Filter Location: '+filters.location+' ('+doms.length+' matches)');
  }
  if(filters.institution){
    doms = doms.filter(function(d){
      var p = parsedDomains.find(function(pd){ return pd.full_domain === d; });
      return p && p.institution && p.institution.toLowerCase().includes(filters.institution);
    });
    addLog('info','🔍 Filter Institution: '+filters.institution+' ('+doms.length+' matches)');
  }
  if(filters.removeDupes){
    var seen = {};
    doms = doms.filter(function(d){
      if(seen[d]) return false;
      seen[d] = true;
      return true;
    });
    addLog('info','🔍 Removed duplicates ('+doms.length+' unique)');
  }
  if(filters.sortAlpha){
    doms.sort();
    addLog('info','🔍 Sorted alphabetically');
  }

  var fields = active.map(function(f){ return f.key; });
  var fieldSuffixes = {};
  active.forEach(function(f){ fieldSuffixes[f.key]=getSfx(f.key); });
  var phoneStart = ($('phone-start')&&$('phone-start').value) ? $('phone-start').value : '0811-0401-1110';

  try {
    // Get keyword hint
    var keywordHintEl = $('keyword-hint');
    var keywordHint = keywordHintEl ? keywordHintEl.value.trim() : '';

    // Queue job
    if(btn){ btn.innerHTML='<span class="spinner"></span> Sending to server...'; }
    addLog('info','📬 Step 1/3: Queueing job to server...');
    if(keywordHint){
      addLog('info','💡 Keyword Hint Active: "'+keywordHint.toUpperCase()+'" → Botparsing dengan context boost');
      addLog('info','   ↳ Botakan: (1) Hapus keyword dari domain, (2) Parse lokasi sisa, (3) Pakai hint sebagai nama instansi');
    }

    // Prepare custom headers if any
    var customHeadersMap = {};
    fields.forEach(function(fkey){
      var custom = getCustomHeader(fkey);
      if(custom) customHeadersMap[fkey] = custom;
    });

    var qRes = await fetch('/api/csv_generator.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({
        action:'queue_job',
        domains: doms,
        fields: fields,
        field_suffixes: fieldSuffixes,
        custom_headers: customHeadersMap,
        phone_start: phoneStart,
        force_refresh: true,
        keyword_hint: keywordHint
      })
    });

    // Check if response is JSON
    var contentType = qRes.headers.get('content-type');
    if(!contentType || !contentType.includes('application/json')){
      addLog('error','❌ Server returned invalid response (not JSON)');
      addLog('error','Response type: ' + (contentType || 'unknown'));
      addLog('info','💡 This usually means a PHP error occurred. Contact support if this persists');
      _genDone(btn);
      return;
    }

    var qData = await qRes.json();
    if(!qData.ok){ addLog('error','❌ Failed to queue job: '+(qData.msg||'Unknown error')); _genDone(btn); return; }

    // CHECK IF AUTO-BATCHED (large jobs split into multiple batches)
    if(qData.batched){
      addLog('ok','✅ Job queued — ' + qData.count + ' domains split into ' + qData.batch_count + ' batches');
      addLog('info','📦 Batch size: ' + qData.batch_size + ' domains per batch (optimal performance)');
      addLog('info','📡 Step 2/3: Processing batches sequentially...');

      var estimatedMinutes = Math.ceil(qData.count * 1.2 / 60);
      if(estimatedMinutes > 1){
        addLog('info','💡 Estimated time: ~' + estimatedMinutes + ' minutes total');
        addLog('info','⏳ Large batch job — Keep this tab open, grab a coffee ☕');
      } else {
        addLog('info','💡 Estimated time: ~' + Math.ceil(qData.count * 1.2) + ' seconds total');
      }

      if(btn){ btn.innerHTML='<span class="spinner"></span> Processing batch 1/' + qData.batch_count + '...'; }

      // Process batches sequentially
      var allCsvData = [];
      var processedDomains = 0;

      for(var i = 0; i < qData.tokens.length; i++){
        var batchNum = i + 1;
        var token = qData.tokens[i];

        addLog('info','━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        addLog('info','📦 BATCH ' + batchNum + '/' + qData.batch_count + ' — Starting...');
        addLog('info','📊 Progress: ' + processedDomains + '/' + qData.count + ' domains completed');
        if(btn){ btn.innerHTML='<span class="spinner"></span> Batch ' + batchNum + '/' + qData.batch_count + ' (' + processedDomains + '/' + qData.count + ' done)'; }

        // Process this batch via SSE
        var batchCsv = await processBatch(token, batchNum, qData.batch_count);
        if(batchCsv){
          allCsvData.push(batchCsv);
          processedDomains += qData.batch_size;
          // Success message already logged in processBatch
        }else{
          addLog('error','❌ BATCH ' + batchNum + '/' + qData.batch_count + ' — FAILED! Stopping process.');
          _genDone(btn);
          return;
        }
      }

      // Merge all CSV data
      addLog('info','━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
      addLog('info','🔄 Merging ' + qData.batch_count + ' batches into single CSV...');
      var finalCsv = mergeCsvBatches(allCsvData);
      csvBlob = new Blob([finalCsv], {type:'text/csv;charset=utf-8;'});

      addLog('ok','✅ ALL BATCHES COMPLETED — ' + qData.count + ' domains processed!');
      addLog('ok','📥 Step 3/3: CSV ready for download!');
      if(btn){
        btn.disabled=false;
        btn.innerHTML='💾 Download CSV (' + qData.count + ' rows)';
        btn.onclick=function(){ downloadCsv(); };
      }
      genRunning = false;
      return;
    }

    // SINGLE BATCH (≤50 domains) — process normally
    if(btn){ btn.innerHTML='<span class="spinner"></span> Connecting stream...'; }
    addLog('ok','✅ Job queued successfully — ' + qData.count + ' domains');
    addLog('info','📡 Step 2/3: Opening real-time stream connection...');

    // Show helpful message immediately
    setTimeout(function(){
      if(genRunning){
        addLog('info','⚙️ BulkReplace Bot Botis analyzing ' + qData.count + ' domains...');
        addLog('info','💡 Estimated time: ~' + Math.ceil(qData.count * 1.2) + ' seconds');
        if(btn){ btn.innerHTML='<span class="spinner"></span> Botanalyzing domains...'; }
      }
    }, 500);

    // Stream via SSE
    var token = qData.token;
    if(btn){ btn.innerHTML='<span class="spinner"></span> Streaming live updates...'; }
    addLog('ok','✅ Stream connected — Real-time updates starting...');

    var es = new EventSource('/api/csv_generator.php?action=stream_generate&token='+token);

    // Show progress updates every 10 seconds if no events
    var _keepaliveTimer = null;
    var _lastEventTime = Date.now();

    function resetKeepalive(){
      _lastEventTime = Date.now();
      if(_keepaliveTimer){ clearInterval(_keepaliveTimer); }
      _keepaliveTimer = setInterval(function(){
        var elapsed = Math.floor((Date.now() - _lastEventTime) / 1000);
        if(elapsed > 20 && genRunning){
          addLog('info','⏳ Still processing... (' + elapsed + 's elapsed) — Botanalyzing domains');
          if(btn){ btn.innerHTML='<span class="spinner"></span> Botanalyzing (' + elapsed + 's)...'; }
        }
      }, 15000);
    }
    resetKeepalive();

    // Hard timeout: 15 minutes for very large batches (500 domains ~8-10 mins expected)
    var _hardTimeout = setTimeout(function(){
      if(es.readyState !== EventSource.CLOSED){
        es.close();
        addLog('error','❌ Timeout after 15 minutes. Try smaller batch (100-200 domains).');
        addLog('info','💡 Tip: Split very large lists into multiple batches for better reliability');
        _genDone(btn);
        if(_keepaliveTimer){ clearInterval(_keepaliveTimer); }
      }
    }, 900000); // 15 minutes

    es.addEventListener('log', function(e){
      resetKeepalive();
      _hasReceivedData = true;
      _sseRetry = 0; // Reset retry counter on successful data
      try{
        var d=JSON.parse(e.data);
        var msg = d.msg||'';
        addLog(d.type||'info', msg);

        // Update button text based on log messages
        if(msg.includes('BotEngine')){
          if(btn){ btn.innerHTML='<span class="spinner"></span> Botinitializing...'; }
        } else if(msg.includes('Analyzing') && msg.includes('domain')){
          if(btn){ btn.innerHTML='<span class="spinner"></span> Botanalyzing...'; }
        } else if(msg.includes('Processing') && msg.includes('[')){
          if(btn){ btn.innerHTML='<span class="spinner"></span> Processing domains...'; }
        } else if(msg.includes('Complete')){
          if(btn){ btn.innerHTML='<span class="spinner"></span> Finalizing...'; }
        }
      }catch(err){
        console.error('Failed to parse log event:', e.data, err);
        addLog('error','⚠️ Invalid log data received (check console)');
      }
    });
    es.addEventListener('progress', function(e){
      resetKeepalive();
      _hasReceivedData = true;
      _sseRetry = 0; // Reset retry counter on successful data
      try{
        var d=JSON.parse(e.data);
        setProgress(d.pct,d.done,d.total);
        if(btn){ btn.innerHTML='<span class="spinner"></span> Processing [' + d.done + '/' + d.total + ']...'; }
      }catch(err){}
    });
    es.addEventListener('done', function(e){
      if(_keepaliveTimer){ clearInterval(_keepaliveTimer); _keepaliveTimer=null; }
      if(_hardTimeout){ clearTimeout(_hardTimeout); _hardTimeout=null; }
      es.close();
      if(btn){ btn.innerHTML='<span class="spinner"></span> Building CSV...'; }
      try{
        var d=JSON.parse(e.data);
        addLog('done','🎉 Selesai! '+d.rows+' rows · keyword: '+d.keyword);
        setProgress(100,d.rows,d.rows);
        if(d.csv){
          csvBlob = new Blob([d.csv], {type:'text/csv;charset=utf-8'});
          if(btn){ btn.disabled=false; btn.innerHTML='📥 Download CSV'; btn.onclick=dlCSV; }
          genRunning=false;
          // Show preview
          showPreview(d.csv);
          toast('CSV siap! Klik Download ✅','ok',5000);
          // Update stats
          loadStats();
        }
      }catch(err){
        console.error('Failed to parse done event:', e.data, err);
        addLog('error','❌ Invalid response data. Check browser console & debug log.');
        addLog('info','💡 Try again or contact support');
        _genDone(btn);
      }
    });
    es.addEventListener('error', function(e){
      es.close();
      try{
        var d=JSON.parse(e.data);
        addLog('error','❌ '+(d.msg||'Server error'));
      }catch(err){
        addLog('error','❌ Server error - check /api/debug_view.php for details');
      }
      _genDone(btn);
    });
    var _sseRetry = 0;
    var _hasReceivedData = false;
    es.onerror = function(ev){
      if(es.readyState === EventSource.CLOSED){
        _sseRetry++;
        if(_sseRetry <= 5){
          addLog('warn','⚠️ Koneksi terputus — reconnecting ('+_sseRetry+'/5)...');
          if(btn){ btn.innerHTML='<span class="spinner"></span> Reconnecting ('+_sseRetry+'/5)...'; }
          // Browser auto-reconnects via retry: header (2s interval)
        } else {
          es.close();
          if(_hasReceivedData){
            addLog('error','❌ Koneksi gagal setelah 6x reconnect. Kemungkinan server overload.');
            addLog('info','💡 Data mungkin sudah sebagian terproses. Coba lagi atau gunakan batch lebih kecil.');
          } else {
            addLog('error','❌ Tidak bisa connect ke server. Check koneksi internet.');
            addLog('info','💡 Refresh halaman dan pastikan koneksi stabil sebelum generate.');
          }
          _genDone(btn);
        }
      }
    };

  } catch(e){
    console.error('doGenerate error:',e);
    addLog('error','❌ Error: '+e.message);
    _genDone(btn);
  }
}

// Process single batch via SSE (returns CSV data)
async function processBatch(token, batchNum, totalBatches){
  return new Promise(function(resolve, reject){
    var es = new EventSource('/api/csv_generator.php?action=stream_generate&token='+token);
    var batchCsvData = '';
    var completed = false;

    // Safety timeout: 5 minutes per batch (very generous)
    var timeout = setTimeout(function(){
      if(!completed){
        completed = true;
        addLog('error', '❌ Batch ' + batchNum + ' — Timeout after 5 minutes');
        es.close();
        resolve(null);
      }
    }, 300000); // 5 minutes

    es.addEventListener('log', function(e){
      try {
        var d = JSON.parse(e.data);
        if(d.msg) addLog(d.type || 'info', d.msg);
      } catch(err) {
        console.warn('Failed to parse log event:', e.data, err);
      }
    });

    es.addEventListener('progress', function(e){
      try {
        var d = JSON.parse(e.data);
        if(d.pct !== undefined && d.done !== undefined && d.total !== undefined){
          setProgress(d.pct, d.done, d.total);
        }
      } catch(err) {
        console.warn('Failed to parse progress event:', e.data, err);
      }
    });

    es.addEventListener('complete', function(e){
      if(completed) return; // Prevent double-completion
      completed = true;
      clearTimeout(timeout); // Clear safety timeout

      try {
        var d = JSON.parse(e.data);
        if(d.csv_data){
          batchCsvData = d.csv_data;
          keyword = d.keyword || keyword;
          addLog('ok', '✅ BATCH ' + batchNum + '/' + totalBatches + ' — Completed! (' + d.rows + ' rows)');
        }
      } catch(err) {
        console.error('Failed to parse complete event:', e.data, err);
        addLog('error', '❌ Batch ' + batchNum + ' — Invalid data format');
      }

      es.close();
      resolve(batchCsvData);
    });

    es.addEventListener('error', function(e){
      if(completed) return;
      completed = true;
      clearTimeout(timeout);
      addLog('error', '❌ Batch ' + batchNum + ' — SSE stream error');
      es.close();
      resolve(null);
    });

    es.onerror = function(e){
      if(completed) return;
      completed = true;
      clearTimeout(timeout);
      addLog('error', '❌ Batch ' + batchNum + ' — Connection failed');
      es.close();
      resolve(null);
    };
  });
}

// Merge multiple CSV batches into one
function mergeCsvBatches(csvBatches){
  if(!csvBatches || csvBatches.length === 0) return '';
  if(csvBatches.length === 1) return csvBatches[0];

  // Extract header from first batch
  var lines = csvBatches[0].split('\n');
  var header = lines[0];
  var merged = [header];

  // Add all data rows (skip header from each batch)
  for(var i = 0; i < csvBatches.length; i++){
    var batchLines = csvBatches[i].split('\n');
    for(var j = 1; j < batchLines.length; j++){
      if(batchLines[j].trim()){
        merged.push(batchLines[j]);
      }
    }
  }

  return merged.join('\n');
}

function _genDone(btn){
  genRunning=false;
  if(btn){ btn.disabled=false; btn.innerHTML='📥 Generate & Download CSV'; btn.onclick=doGenerate; }
}

function addLog(type, msg){
  var lb=$('log-box'); if(!lb) return;
  var colors={ok:'var(--ok)',err:'var(--err)',warn:'var(--warn)',done:'var(--a1)',info:'rgba(255,255,255,.45)',scrape:'#a78bfa'};
  var line=document.createElement('div');
  line.style.cssText='font-family:monospace;font-size:10.5px;color:'+(colors[type]||colors.info)+';padding:1px 0;line-height:1.7;';
  line.textContent=msg;
  lb.appendChild(line);
  lb.scrollTop=lb.scrollHeight;
  var gi=$('gen-info');
  if(gi) gi.textContent=msg.slice(0,60);
}

function setProgress(pct,done,total){
  var pf=$('pbar-fill'); if(pf) pf.style.width=pct+'%';
  var pp=$('pbar-pct'); if(pp) pp.textContent=pct+'%';
  var pl=$('pbar-label'); if(pl) pl.textContent=(done||0)+'/'+(total||0)+' domain';
}

function dlCSV(){
  if(!csvBlob){ toast('Generate CSV dulu!','err'); return; }
  var url=URL.createObjectURL(csvBlob);
  var a=document.createElement('a');
  a.href=url; a.download='bulkreplace-'+keyword+'-'+Date.now()+'.csv';
  document.body.appendChild(a); a.click();
  document.body.removeChild(a); URL.revokeObjectURL(url);
  toast('CSV didownload ✅','ok');
}

// Alias for batch system
function downloadCsv(){ dlCSV(); }

function copyCSV(){
  if(!csvBlob){ toast('Generate CSV dulu!','err'); return; }
  csvBlob.text().then(function(t){
    navigator.clipboard.writeText(t).then(function(){ toast('CSV disalin ke clipboard ✅','ok'); });
  });
}

function showPreview(csv){
  try{
    var pw=$('prev-wrap'); if(!pw) return;
    var lines=csv.replace(/^\uFEFF/,'').split(/\r?\n/).filter(Boolean);
    if(!lines.length) return;
    var head=parseCSVLine(lines[0]);
    var rows=lines.slice(1,6).map(parseCSVLine);
    var th='<tr>'+head.map(function(h){ return '<th>'+esc(h)+'</th>'; }).join('')+'</tr>';
    var td=rows.map(function(r){ return '<tr>'+r.map(function(c){ return '<td>'+esc(c)+'</td>'; }).join('')+'</tr>'; }).join('');
    var ph=$('prev-head'); if(ph) ph.innerHTML=th;
    var pb=$('prev-body'); if(pb) pb.innerHTML=td;
    var pm=$('prev-more');
    if(pm){ pm.textContent=lines.length>6?'+'+(lines.length-6)+' rows lagi...':''; }
    pw.classList.remove('hidden');
    // Show after block
    var aa=$('anno-after'); if(aa) aa.classList.remove('hidden');
    var ac=$('anno-code-after');
    if(ac && rows[0]){
      ac.innerHTML=head.map(function(h,i){ return '<div><span class="anno-replaced">'+esc(rows[0][i]||'—')+'</span></div>'; }).join('');
    }
  }catch(e){ console.warn('preview error:',e); }
}

function parseCSVLine(line){
  var r=[],cur='',q=false;
  for(var i=0;i<line.length;i++){
    var c=line[i];
    if(c==='"'){if(q&&line[i+1]==='"'){cur+='"';i++;}else q=!q;}
    else if(c===','&&!q){r.push(cur);cur='';}
    else cur+=c;
  }
  r.push(cur);
  return r;
}

function setBA(mode){
  var bb=$('btn-before'),ba=$('btn-after');
  var ab=$('anno-before'),aa=$('anno-after');
  if(!bb||!ba||!ab) return;
  if(mode==='before'){
    bb.classList.add('active'); ba.classList.remove('active');
    ab.classList.remove('hidden');
    if(aa) aa.classList.add('hidden');
  } else {
    ba.classList.add('active'); bb.classList.remove('active');
    if(aa) aa.classList.remove('hidden');
    ab.classList.add('hidden');
  }
}

// ── History ───────────────────────────────────────────
function loadHistory(){
  var hw = $('hist-wrap'); if(!hw) return;
  fetch('/api/csv_generator.php?action=history')
  .then(function(r){ return r.json(); })
  .then(function(data){
    if(!data.history||!data.history.length){
      hw.innerHTML='<div style="font-family:monospace;font-size:10px;color:var(--muted);text-align:center;padding:12px;">Belum ada history</div>';
      return;
    }
    hw.innerHTML=data.history.map(function(h){
      var doms=[]; try{ doms=JSON.parse(h.domains)||[]; }catch(e){}
      return '<div class="hitem" onclick=\'reloadH('+JSON.stringify(h)+')\'>'
        +'<div style="flex:1;min-width:0;">'
        +'<div style="font-family:\'Syne\',sans-serif;font-size:12px;font-weight:700;color:#fff;">'+esc(h.keyword||'—')+' <span style="font-size:9.5px;font-weight:400;color:var(--muted);">'+h.row_count+' rows</span></div>'
        +'<div style="font-family:monospace;font-size:8.5px;color:var(--muted);margin-top:2px;">'+doms.length+' domains · '+ago(h.created_at)+'</div>'
        +'</div>'
        +'<span style="font-size:16px;opacity:.3;">↺</span>'
        +'</div>';
    }).join('');
  })
  .catch(function(){ hw.innerHTML='<div style="font-family:monospace;font-size:10px;color:var(--muted);">Gagal load history</div>'; });
}

function reloadH(h){
  var doms=[]; try{ doms=JSON.parse(h.domains)||[]; }catch(e){}
  if(!doms.length) return;
  var ta=$('inp-domains'); if(ta) ta.value=doms.join('\n');
  var ps=$('phone-start'); if(ps&&h.phone_start) ps.value=h.phone_start;
  doParse();
  window.scrollTo({top:0,behavior:'smooth'});
  toast('Domain dimuat dari history','ok');
}

function ago(ts){
  if(!ts) return '';
  var d=new Date(ts.replace(' ','T')+'Z');
  var diff=Math.floor((Date.now()-d.getTime())/1000);
  if(diff<60) return diff+'s ago';
  if(diff<3600) return Math.floor(diff/60)+'m ago';
  if(diff<86400) return Math.floor(diff/3600)+'h ago';
  return Math.floor(diff/86400)+'d ago';
}

function loadAnalytics(){
  fetch('/api/csv_generator.php?action=analytics')
  .then(function(r){ return r.json(); })
  .then(function(d){
    if(!d.ok || !d.stats) return;
    var s = d.stats;
    var el;
    el = $('stat-total-jobs'); if(el) el.textContent = s.total_jobs || 0;
    el = $('stat-success-rate'); if(el) el.textContent = (s.avg_success_rate || 0) + '%';
    el = $('stat-domains'); if(el) el.textContent = (s.total_domains_processed || 0).toLocaleString();
    el = $('stat-avg-time'); if(el) el.textContent = Math.round(s.avg_processing_time/1000 || 0) + 's';

    if(d.status_breakdown){
      var b = d.status_breakdown;
      el = $('badge-success-count'); if(el) el.textContent = b.success || 0;
      el = $('badge-partial-count'); if(el) el.textContent = b.partial || 0;
      el = $('badge-failed-count'); if(el) el.textContent = b.failed || 0;
    }

    el = $('badge-ai-count'); if(el) el.textContent = s.ai_usage_count || 0;
    el = $('badge-google-count'); if(el) el.textContent = s.google_usage_count || 0;
  })
  .catch(function(e){ console.error('Analytics error:', e); });
}

function clearHistory(){
  if(!confirm('Clear history? (Analytics data akan tetap tersimpan untuk admin)')) return;
  fetch('/api/csv_generator.php?action=clear_history', {method:'DELETE'})
  .then(function(r){ return r.json(); })
  .then(function(d){
    if(d.ok){
      toast('History cleared successfully','ok');
      loadHistory();
      loadAnalytics();
    } else {
      toast('Failed to clear history','error');
    }
  })
  .catch(function(){ toast('Error clearing history','error'); });
}

function loadFailedJobs(){
  fetch('/api/csv_generator.php?action=failed_jobs')
  .then(function(r){ return r.json(); })
  .then(function(d){
    if(!d.ok || !d.failed_jobs) return;
    var jobs = d.failed_jobs;
    var card = $('failed-jobs-card');
    var wrap = $('failed-wrap');
    var countEl = $('failed-count');

    if(countEl) countEl.textContent = jobs.length;

    if(jobs.length === 0){
      if(card) card.style.display = 'none';
      return;
    }

    if(card) card.style.display = 'block';
    if(!wrap) return;

    wrap.innerHTML = jobs.map(function(j){
      return '<div style="padding:8px 10px;background:rgba(239,68,68,.05);border:1px solid rgba(239,68,68,.15);border-radius:8px;margin-bottom:6px;">'
        + '<div style="font-family:\'JetBrains Mono\',monospace;font-size:11px;color:#fff;">'+esc(j.domain)+'</div>'
        + '<div style="font-family:\'JetBrains Mono\',monospace;font-size:8px;color:var(--muted);margin-top:2px;">'+esc(j.error_message||'Unknown error')+' · '+ago(j.created_at)+'</div>'
        + '</div>';
    }).join('');
  })
  .catch(function(){ console.error('Failed to load failed jobs'); });
}

function retryFailed(){
  fetch('/api/csv_generator.php?action=failed_jobs')
  .then(function(r){ return r.json(); })
  .then(function(d){
    if(!d.ok || !d.failed_jobs || d.failed_jobs.length === 0){
      toast('No failed domains to retry','warn');
      return;
    }
    var domains = d.failed_jobs.map(function(j){ return j.domain; });
    var ta = $('inp-domains');
    if(ta) ta.value = domains.join('\n');
    doParse();
    window.scrollTo({top:0,behavior:'smooth'});
    toast('Loaded '+domains.length+' failed domains for retry','ok');
  })
  .catch(function(){ toast('Error loading failed jobs','error'); });
}

function clearFailed(){
  if(!confirm('Clear all failed domains?')) return;
  fetch('/api/csv_generator.php?action=clear_failed', {method:'DELETE'})
  .then(function(r){ return r.json(); })
  .then(function(d){
    if(d.ok){
      toast('Failed jobs cleared','ok');
      loadFailedJobs();
    } else {
      toast('Failed to clear','error');
    }
  })
  .catch(function(){ toast('Error clearing failed jobs','error'); });
}

function loadStats(){
  fetch('/api/csv_generator.php?action=cache_stats')
  .then(function(r){ return r.json(); })
  .then(function(d){
    var sc=$('s-cache'); if(sc) sc.textContent=d.cached_locations||0;
    var sh=$('s-hits'); if(sh) sh.textContent=d.total_hits||0;
    // Bot+ Google status badges
    var aiB=$('badge-ai');
    var gpB=$('badge-google');
    if(aiB){
      aiB.textContent = d.ai_active ? '🤖 Bot Parser: ON' : '🤖 Bot Parser: OFF';
      aiB.className   = 'sys-badge ' + (d.ai_active ? 'badge-on' : 'badge-off');
      aiB.title       = d.ai_active ? 'Smart Bot aktif — domain parsing akurat' : 'Set SMART_BOT_API_KEY di config.php';
    }
    if(gpB){
      gpB.textContent = d.google_active ? '📍 Google Places: ON' : '📍 Google Places: OFF';
      gpB.className   = 'sys-badge ' + (d.google_active ? 'badge-on' : 'badge-off');
      gpB.title       = d.google_active ? 'Google Places API aktif — data alamat real' : 'Set GOOGLE_PLACES_API_KEY di config.php';
    }
  })
  .catch(function(){});
}

// ── Custom Headers ───────────────────────────────────
var customHeaders = {};

function toggleCustomHeaders(){
  var section = $('custom-headers-section');
  if(!section) return;
  var isHidden = section.style.display === 'none';
  section.style.display = isHidden ? 'block' : 'none';
  if(isHidden) updateHeaderCustomGrid();
}

function updateHeaderCustomGrid(){
  var grid = $('header-custom-grid');
  if(!grid) return;
  var active = getActive();
  if(!active.length){
    grid.innerHTML = '<div style="font-family:monospace;font-size:10px;color:var(--muted);">Select fields first</div>';
    return;
  }
  var kw = keyword || 'data';
  grid.innerHTML = active.map(function(f){
    var defaultHeader = f.key + kw + getSfx(f.key);
    var customVal = customHeaders[f.key] || '';
    return '<div style="display:flex;flex-direction:column;gap:4px;">'
      + '<label style="font-family:\'JetBrains Mono\',monospace;font-size:9px;color:var(--muted);">'+esc(f.key)+'</label>'
      + '<input type="text" id="custom-header-'+f.key+'" value="'+esc(customVal)+'" placeholder="'+esc(defaultHeader)+'" '
      + 'style="width:100%;background:rgba(0,0,0,.35);border:1px solid var(--border);border-radius:6px;padding:6px 8px;font-family:\'JetBrains Mono\',monospace;font-size:10px;color:#fff;" '
      + 'onchange="saveCustomHeader(\''+f.key+'\')">'
      + '</div>';
  }).join('');
}

function saveCustomHeader(key){
  var inp = $('custom-header-'+key);
  if(!inp) return;
  var val = inp.value.trim();
  if(val) customHeaders[key] = val;
  else delete customHeaders[key];
}

function resetHeaders(){
  customHeaders = {};
  updateHeaderCustomGrid();
  toast('Headers reset to default','ok');
}

function getCustomHeader(key){
  return customHeaders[key] || null;
}

// ── Filters ──────────────────────────────────────────
function toggleFilters(){
  var fc = $('filters-card');
  if(!fc) return;
  fc.style.display = fc.style.display === 'none' ? 'block' : 'none';
}

function resetFilters(){
  var el;
  el = $('filter-location'); if(el) el.value = '';
  el = $('filter-institution'); if(el) el.value = '';
  el = $('filter-remove-dupes'); if(el) el.checked = false;
  el = $('filter-sort-alpha'); if(el) el.checked = false;
  toast('Filters reset','ok');
}

function getFilters(){
  var loc = $('filter-location') ? $('filter-location').value.trim() : '';
  var inst = $('filter-institution') ? $('filter-institution').value : '';
  var dupes = $('filter-remove-dupes') ? $('filter-remove-dupes').checked : false;
  var sort = $('filter-sort-alpha') ? $('filter-sort-alpha').checked : false;
  return {
    location: loc,
    institution: inst,
    removeDupes: dupes,
    sortAlpha: sort
  };
}

// ── Init ─────────────────────────────────────────────
(function init(){
  // Show page immediately
  document.body.style.opacity = '1';

  var ta=$('inp-domains');
  if(ta){
    ta.addEventListener('keydown',function(e){
      if(e.key==='Enter'&&e.ctrlKey){ e.preventDefault(); doParse(); }
    });
  }
  var bg=$('btn-gen');
  if(bg) bg.onclick=doGenerate;
  initFields();

  // Defer heavy operations
  setTimeout(function(){
    loadAnalytics();
    loadHistory();
    loadStats();
    loadFailedJobs();
  }, 100);

  console.log('🤖 BulkReplace Bot™ CSV Generator — Ready!');
})();
</script>
</body>
</html>