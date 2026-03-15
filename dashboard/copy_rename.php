<?php
require_once dirname(__DIR__).'/config.php';
startSession();
requireLogin();
$user = currentUser();
if(!$user){ header('Location: /auth/login.php'); exit; }
if(!hasAddonAccess((int)$user['id'], 'copy-rename')){ header('Location: /dashboard/addons.php?ref=copy-rename'); exit; }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Copy & Rename — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>
/* ─── Layout ─────────────────────────────────── */
.cr-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:24px 28px;margin-bottom:20px;position:relative;overflow:hidden;}
.cr-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--a1),transparent);}
.cr-title{font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:.14em;color:var(--a1);margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.step-pill{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:5px;font-size:10px;font-weight:700;background:rgba(240,165,0,.15);border:1px solid rgba(240,165,0,.3);color:var(--a1);flex-shrink:0;transition:all .3s;}
.step-pill.done{background:rgba(0,230,118,.15);border-color:rgba(0,230,118,.3);color:var(--ok);}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
@media(max-width:600px){.row2{grid-template-columns:1fr;}}
.cr-label{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-bottom:6px;display:flex;align-items:center;justify-content:space-between;}
.cr-input{width:100%;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:10px 14px;color:var(--text);font-family:'JetBrains Mono',monospace;font-size:12px;transition:border .2s,box-shadow .2s;outline:none;}
.cr-input:focus{border-color:rgba(240,165,0,.4);box-shadow:0 0 0 3px rgba(240,165,0,.06);}
textarea.cr-input{resize:vertical;min-height:170px;line-height:1.75;}
.cr-hint{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:6px;line-height:1.7;}
.cr-hint code{background:rgba(255,255,255,.06);padding:1px 5px;border-radius:4px;color:rgba(255,255,255,.55);font-size:10px;}

/* ─── Folder Picker Cards ──────────────────── */
.picker-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;}
@media(max-width:580px){.picker-grid{grid-template-columns:1fr;}}
.pick-card{background:var(--card2);border:2px solid var(--border);border-radius:12px;padding:22px 16px;text-align:center;cursor:pointer;transition:all .2s;position:relative;user-select:none;}
.pick-card:hover:not(.disabled){border-color:rgba(240,165,0,.4);background:rgba(240,165,0,.04);transform:translateY(-2px);}
.pick-card.selected{border-color:rgba(240,165,0,.6);background:rgba(240,165,0,.07);box-shadow:0 0 0 3px rgba(240,165,0,.08);}
.pick-card.disabled{opacity:.4;cursor:not-allowed;}
.pick-badge{position:absolute;top:-1px;right:14px;background:linear-gradient(135deg,#00d4aa,#007a63);color:#000;font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:3px 10px;border-radius:0 0 6px 6px;}
.pick-icon{font-size:34px;margin-bottom:8px;display:block;}
.pick-title{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:4px;}
.pick-desc{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:1.7;}

/* ─── Folder Banner ────────────────────────── */
.folder-banner{display:none;align-items:center;gap:12px;background:rgba(0,230,118,.06);border:1px solid rgba(0,230,118,.2);border-radius:10px;padding:12px 16px;margin-bottom:10px;}
.folder-banner.show{display:flex;}
.fb-icon{font-size:20px;flex-shrink:0;}
.fb-info{flex:1;min-width:0;}
.fb-name{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.fb-meta{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:2px;}
.fb-clear{background:transparent;border:1px solid rgba(255,69,96,.2);color:var(--err);border-radius:6px;width:28px;height:28px;cursor:pointer;font-size:12px;transition:all .2s;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.fb-clear:hover{background:rgba(255,69,96,.08);border-color:var(--err);}

/* ─── Output row ───────────────────────────── */
.out-row{display:flex;align-items:center;gap:10px;margin-top:10px;flex-wrap:wrap;}
.btn-out{display:inline-flex;align-items:center;gap:7px;background:var(--card2);border:1px solid var(--border);color:var(--muted);border-radius:8px;padding:8px 14px;font-family:'JetBrains Mono',monospace;font-size:11px;cursor:pointer;transition:all .2s;white-space:nowrap;}
.btn-out:hover{border-color:rgba(240,165,0,.4);color:var(--a1);}
.btn-out.set{border-color:rgba(0,230,118,.3);color:var(--ok);}
.out-path{flex:1;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}

/* ─── Output diagram ─────────────────────────── */
.out-diagram{margin-top:10px;background:rgba(255,255,255,.03);border:1px solid rgba(240,165,0,.15);border-radius:8px;padding:10px 14px;}
.od-label{font-size:10px;color:var(--muted);margin-bottom:6px;font-family:'JetBrains Mono',monospace;}
.od-tree{display:flex;flex-direction:column;gap:3px;}
.od-row{display:flex;align-items:center;gap:8px;font-family:'JetBrains Mono',monospace;font-size:11px;}
.od-child{padding-left:20px;}
.od-parent{color:rgba(240,165,0,.9);font-weight:600;}
.od-item{color:rgba(0,230,118,.75);}
.od-dots{color:var(--muted);}
.od-tag{font-size:9px;padding:1px 6px;border-radius:3px;}
.tag-you{background:rgba(240,165,0,.15);color:rgba(240,165,0,.9);}
.tag-copy{background:rgba(0,230,118,.1);color:rgba(0,230,118,.75);}


/* ─── Mode btns ────────────────────────────── */
.mode-row{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;}
.mode-btn{padding:6px 14px;border-radius:8px;font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;letter-spacing:.06em;cursor:pointer;border:1px solid var(--border);background:transparent;color:var(--muted);transition:all .2s;text-transform:uppercase;}
.mode-btn.active{background:rgba(240,165,0,.1);border-color:rgba(240,165,0,.35);color:var(--a1);}

/* ─── Count badge / Val strip ──────────────── */
.cnt-badge{display:inline-flex;padding:2px 9px;border-radius:100px;font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;transition:all .2s;}
.cnt-ok{background:rgba(0,230,118,.1);color:var(--ok);border:1px solid rgba(0,230,118,.2);}
.cnt-err{background:rgba(255,69,96,.1);color:var(--err);border:1px solid rgba(255,69,96,.2);}
.cnt-neu{background:rgba(255,255,255,.05);color:var(--muted);border:1px solid var(--border);}
.val-strip{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:10px;margin-top:14px;font-family:'JetBrains Mono',monospace;font-size:12px;border:1px solid transparent;transition:all .25s;}
.val-idle{background:var(--card2);border-color:var(--border);color:var(--muted);}
.val-ok{background:rgba(0,230,118,.06);border-color:rgba(0,230,118,.2);color:var(--ok);}
.val-err{background:rgba(255,69,96,.06);border-color:rgba(255,69,96,.2);color:var(--err);}

/* ─── Opts ─────────────────────────────────── */
.opt-row{display:flex;flex-direction:column;gap:10px;}
.opt-item{display:flex;align-items:center;gap:9px;cursor:pointer;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text);}
.opt-item input{accent-color:var(--a1);cursor:pointer;width:14px;height:14px;}

/* ─── Exec Log ─────────────────────────────── */
.exec-section{display:none;margin-bottom:20px;}
.exec-card{background:var(--card);border:1px solid var(--border);border-radius:16px;overflow:hidden;}
.exec-hdr{display:flex;align-items:center;justify-content:space-between;padding:14px 22px;border-bottom:1px solid var(--border);background:linear-gradient(90deg,rgba(240,165,0,.05),transparent);flex-wrap:wrap;gap:10px;}
.exec-hdr-l{display:flex;align-items:center;gap:10px;}
.edot{width:8px;height:8px;border-radius:50%;background:var(--muted);transition:all .3s;flex-shrink:0;}
.edot.running{background:var(--ok);box-shadow:0 0 10px var(--ok);animation:pdot 1s ease-in-out infinite;}
.edot.done{background:var(--ok);box-shadow:0 0 10px var(--ok);}
.edot.err{background:var(--err);box-shadow:0 0 8px var(--err);}
@keyframes pdot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.3;transform:scale(.6)}}
.etitle{font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:.14em;color:var(--a1);}
.estatus{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);}
.epills{display:flex;gap:8px;flex-wrap:wrap;}
.epill{font-family:'JetBrains Mono',monospace;font-size:10px;padding:3px 10px;border-radius:100px;border:1px solid var(--border);color:var(--muted);white-space:nowrap;transition:all .3s;}
.ep-c{border-color:rgba(92,158,255,.4);color:#5c9eff;background:rgba(92,158,255,.06);}
.ep-r{border-color:rgba(240,165,0,.4);color:var(--a1);background:rgba(240,165,0,.06);}
.ep-done{border-color:rgba(0,230,118,.3);color:var(--ok);background:rgba(0,230,118,.06);}

/* Dual progress */
.prog-row{display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid var(--border);}
@media(max-width:560px){.prog-row{grid-template-columns:1fr;}}
.prog-lane{padding:12px 22px;}
.prog-lane:first-child{border-right:1px solid var(--border);}
@media(max-width:560px){.prog-lane:first-child{border-right:none;border-bottom:1px solid var(--border);}}
.pl-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:7px;}
.pl-lbl{font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);}
.pl-cnt{font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;}
.pl-cnt.c{color:#5c9eff;} .pl-cnt.r{color:var(--a1);}
.pb-track{background:var(--bg2);border:1px solid var(--border);border-radius:100px;height:6px;overflow:hidden;}
.pb-fill{height:100%;border-radius:100px;width:0%;transition:width .12s linear;}
.pb-c{background:linear-gradient(90deg,#2d5db5,#5c9eff);}
.pb-r{background:linear-gradient(90deg,#c47d00,var(--a1));}

/* Terminal */
.terminal{background:#04040d;}
.t-bar{display:flex;align-items:center;justify-content:space-between;padding:8px 16px;border-bottom:1px solid rgba(255,255,255,.05);background:rgba(0,0,0,.4);}
.t-dots{display:flex;gap:5px;}
.td{width:10px;height:10px;border-radius:50%;}
.td-r{background:#ff5c57;}.td-y{background:#ffbd2e;}.td-g{background:#27c93f;}
.t-name{font-family:'JetBrains Mono',monospace;font-size:10px;color:rgba(255,255,255,.3);flex:1;text-align:center;}
.btn-clr{font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);background:transparent;border:1px solid var(--border);border-radius:4px;padding:2px 8px;cursor:pointer;transition:all .2s;}
.btn-clr:hover{color:var(--err);border-color:rgba(255,69,96,.3);}
.exec-log{font-family:'JetBrains Mono',monospace;font-size:11.5px;line-height:1.8;height:320px;overflow-y:auto;padding:14px 18px;}
@media(max-width:600px){.exec-log{height:240px;font-size:11px;}}
.exec-log::-webkit-scrollbar{width:4px}
.exec-log::-webkit-scrollbar-thumb{background:rgba(240,165,0,.15);border-radius:4px}

/* Log lines */
.ll{display:flex;gap:9px;align-items:flex-start;margin-bottom:3px;animation:lin .12s ease;}
.ll-ts{color:rgba(255,255,255,.17);flex-shrink:0;font-size:10px;padding-top:2px;min-width:54px;}
.ll-ic{flex-shrink:0;width:16px;text-align:center;}
.ll-msg{flex:1;word-break:break-all;}
.lt-head .ll-msg{color:#fff;font-weight:700;}
.lt-info .ll-msg{color:rgba(255,255,255,.42);}
.lt-copy .ll-msg{color:#5c9eff;}
.lt-ok .ll-msg{color:var(--ok);}
.lt-rename .ll-msg{color:var(--a1);}
.lt-warn .ll-msg{color:var(--warn);}
.lt-err .ll-msg{color:var(--err);}
.lt-done .ll-msg{color:var(--ok);font-weight:700;}
.lt-skip .ll-msg{color:var(--muted);}
.lt-div .ll-msg{color:rgba(255,255,255,.08);}

/* Summary */
.exec-sum{display:none;padding:14px 22px;border-top:1px solid var(--border);background:rgba(0,0,0,.15);align-items:center;gap:14px;flex-wrap:wrap;}
.sumpill{font-family:'JetBrains Mono',monospace;font-size:11px;display:flex;align-items:center;gap:5px;}
.sumpill.ok{color:var(--ok);}.sumpill.warn{color:var(--warn);}.sumpill.err{color:var(--err);}.sumpill.muted{color:var(--muted);}

/* Alert */
.cr-alert{display:flex;align-items:flex-start;gap:12px;padding:13px 16px;border-radius:10px;font-family:'JetBrains Mono',monospace;font-size:11px;line-height:1.7;border:1px solid rgba(240,165,0,.15);background:rgba(240,165,0,.05);color:rgba(255,255,255,.6);margin-bottom:20px;}
.cr-alert.err{border-color:rgba(255,69,96,.2);background:rgba(255,69,96,.05);color:var(--err);}
@keyframes lin{from{opacity:0;transform:translateX(-4px)}to{opacity:1;transform:none}}
</style>
</head>
<body>
<div id="toast-wrap"></div>
<div class="dash-layout">
<?php include __DIR__.'/_sidebar.php'; ?>
<div class="dash-main">

  <div class="dash-topbar">
    <div class="dash-page-title">📁 Copy &amp; Rename</div>
    <div style="display:flex;align-items:center;gap:10px;">
      <span style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);"><?= htmlspecialchars($user['name']) ?></span>
      <span class="plan-pill pp-<?= $plan ?>"><?= ucfirst($plan) ?></span>
    </div>
  </div>

  <div class="dash-content">

    <!-- Compat check banner -->
    <div id="compat-banner" style="display:none;"></div>

    <!-- How it works -->
    <div class="cr-alert">
      <span style="font-size:16px;flex-shrink:0;">⚡</span>
      <div><strong style="color:var(--a1);">100% Lokal di Browser</strong> — Tidak ada file diupload ke server. Browser langsung akses folder di PC kamu, copy sumber N kali, rename sesuai daftar nama, semua tersimpan di PC. Butuh <strong>Chrome / Edge 86+</strong>.</div>
    </div>

    <!-- STEP 1: Pilih Folder -->
    <div class="cr-card">
      <div class="cr-title"><span class="step-pill" id="sp1">1</span> Pilih Folder Sumber &amp; Output</div>

      <div class="picker-grid">
        <div class="pick-card" id="card-pick" onclick="pickSource()">
          <div class="pick-badge">RECOMMENDED</div>
          <span class="pick-icon">📁</span>
          <div class="pick-title">Pick Folder</div>
          <div class="pick-desc">Chrome / Edge 86+<br>Akses langsung ke PC kamu</div>
        </div>
        <div class="pick-card disabled">
          <span class="pick-icon" style="opacity:.35;">📂</span>
          <div class="pick-title">Browse Folder</div>
          <div class="pick-desc" style="opacity:.4;">Tidak tersedia untuk<br>operasi copy lokal</div>
        </div>
      </div>

      <!-- Source banner -->
      <div class="folder-banner" id="src-banner">
        <div class="fb-icon">📁</div>
        <div class="fb-info">
          <div class="fb-name" id="src-name">—</div>
          <div class="fb-meta" id="src-meta">—</div>
        </div>
        <button class="fb-clear" onclick="clearSource()">✕</button>
      </div>

      <!-- Output folder -->
      <div class="out-row">
        <button class="btn-out" id="btn-out" onclick="pickOutput()">📂 Pilih Folder INDUK (Output)</button>
        <div class="out-path" id="out-path">Kosong = pilih saat run</div>
        <button class="fb-clear" id="btn-out-clr" onclick="clearOutput()" style="display:none;">✕</button>
      </div>
      <div class="out-diagram">
        <div class="od-label">📌 Struktur hasil yang akan dibuat:</div>
        <div class="od-tree">
          <div class="od-row"><span class="od-parent">📂 <span id="od-parent-name">FolderInduk</span></span><span class="od-tag tag-you">← Pilih ini sebagai output</span></div>
          <div class="od-row od-child"><span class="od-item">📁 <span id="od-name1">NamaDomain1</span></span><span class="od-tag tag-copy">← copy dari sumber</span></div>
          <div class="od-row od-child"><span class="od-item">📁 <span id="od-name2">NamaDomain2</span></span><span class="od-tag tag-copy">← copy dari sumber</span></div>
          <div class="od-row od-child"><span class="od-item od-dots">📁 ...</span></div>
        </div>
      </div>
      <p class="cr-hint">⚠️ Pilih folder <strong>INDUK</strong> tempat semua hasil copy disimpan — <strong>bukan</strong> folder yang ingin diganti namanya.</p>
    </div>

    <!-- STEP 2: Names -->
    <div class="cr-card">
      <div class="cr-title"><span class="step-pill" id="sp2">2</span> Daftar Nama / Domain</div>

      <div class="mode-row">
        <button class="mode-btn active" id="md-domain" onclick="setMode('domain')">🌐 Domain (strip TLD)</button>
        <button class="mode-btn" id="md-raw" onclick="setMode('raw')">📝 Nama Langsung</button>
      </div>

      <label class="cr-label">
        <span>PASTE 1 PER BARIS</span>
        <span id="cnt-badge" class="cnt-badge cnt-neu">0 names</span>
      </label>
      <textarea class="cr-input" id="names-in" oninput="updateDiagram()"
        placeholder="officeseattle.com&#10;officeportland.com&#10;officeboston.com&#10;..."></textarea>
      <p class="cr-hint" id="mode-hint">Mode Domain: TLD (.com/.net/.co.id/.go.id/dll) otomatis dihapus dari nama folder.</p>

      <div class="val-strip val-idle" id="val-strip">
        <span>⏳</span>
        <span id="val-msg">Pilih folder sumber dan isi daftar nama.</span>
      </div>
    </div>

    <!-- STEP 3: Options & Run -->
    <div class="cr-card">
      <div class="cr-title"><span class="step-pill" id="sp3">3</span> Opsi &amp; Jalankan</div>
      <div class="row2" style="margin-bottom:18px;">
        <div class="opt-row">
          <label class="opt-item">
            <input type="checkbox" id="opt-overwrite"> Overwrite jika folder sudah ada
          </label>
          <label class="opt-item">
            <input type="checkbox" id="opt-skip-dupe" checked> Skip nama duplikat
          </label>
        </div>
        <div>
          <label class="cr-label"><span>LIMIT PER RUN</span></label>
          <select class="cr-input" id="sel-limit" style="cursor:pointer;">
            <option value="9999">Semua (tanpa limit)</option>
            <option value="50">Maks 50</option>
            <option value="100">Maks 100</option>
            <option value="200">Maks 200</option>
            <option value="500">Maks 500</option>
          </select>
        </div>
      </div>

      <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <button class="btn btn-amber" id="btn-run" onclick="runIt()" disabled>
          ▶ Jalankan Copy &amp; Rename
        </button>
        <button class="btn btn-ghost btn-sm" id="btn-stop" onclick="doStop()" style="display:none;">
          ⏹ Stop
        </button>
        <span style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);" id="run-hint"></span>
      </div>
    </div>

    <!-- EXECUTION LOG -->
    <div class="exec-section" id="exec-section">
      <div class="exec-card">

        <!-- Header -->
        <div class="exec-hdr">
          <div class="exec-hdr-l">
            <div class="edot" id="edot"></div>
            <div class="etitle">Execution Log</div>
            <div class="estatus" id="estatus">Idle</div>
          </div>
          <div class="epills">
            <div class="epill" id="ep-c">📋 0/0</div>
            <div class="epill" id="ep-conc" style="display:none;"></div>
            <div class="epill" id="ep-t">⏱ 0.0s</div>
          </div>
        </div>

        <!-- Single progress bar -->
        <div style="padding:14px 22px;border-bottom:1px solid var(--border);background:var(--bg2);">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <span style="font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);">📋 Progress</span>
            <div style="display:flex;align-items:center;gap:12px;">
              <span style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--a1);" id="active-jobs">0 aktif</span>
              <span style="font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;color:var(--ok);" id="pc-cnt">0 / 0</span>
            </div>
          </div>
          <div class="pb-track"><div class="pb-fill pb-c" id="pb-c"></div></div>
          <div style="display:flex;justify-content:space-between;margin-top:6px;">
            <span style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);" id="speed-txt"></span>
            <span style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);" id="eta-txt"></span>
          </div>
        </div>

        <!-- Terminal -->
        <div class="terminal">
          <div class="t-bar">
            <div class="t-dots"><div class="td td-r"></div><div class="td td-y"></div><div class="td td-g"></div></div>
            <div class="t-name" id="t-name">BulkReplace — Copy & Rename</div>
            <button class="btn-clr" onclick="document.getElementById('exec-log').innerHTML=''">✕ Clear</button>
          </div>
          <div class="exec-log" id="exec-log"></div>
        </div>

        <!-- Summary -->
        <div class="exec-sum" id="exec-sum">
          <div class="sumpill ok"   id="s-ok">✅ 0 berhasil</div>
          <div class="sumpill warn" id="s-skip" style="display:none;">⚠️ 0 skip</div>
          <div class="sumpill err"  id="s-err"  style="display:none;">❌ 0 error</div>
          <div style="flex:1;"></div>
          <div class="sumpill muted" id="s-time">⏱ 0s total</div>
        </div>

      </div>
    </div>

  </div>
</div>
</div>

<script>
/* ══════════════════════════════════════════════════════════
   STATE
══════════════════════════════════════════════════════════ */
let srcHandle = null;   // FileSystemDirectoryHandle (source, read)
let outHandle = null;   // FileSystemDirectoryHandle (output, readwrite)
let mode      = 'domain';
let running   = false;
let stopReq   = false;
let clock     = null;
let t0        = 0;

/* ══════════════════════════════════════════════════════════
   COMPAT CHECK
══════════════════════════════════════════════════════════ */
(function(){
  if(!window.showDirectoryPicker){
    const b = document.getElementById('compat-banner');
    b.className = 'cr-alert err'; b.style.display='flex';
    b.innerHTML = '<span style="font-size:16px;flex-shrink:0;">⚠️</span><div><strong>Browser tidak didukung!</strong> File System Access API hanya ada di Chrome/Edge 86+. Buka di Chrome untuk pakai fitur ini.</div>';
    document.getElementById('card-pick').classList.add('disabled');
  }
})();

/* ══════════════════════════════════════════════════════════
   TOAST
══════════════════════════════════════════════════════════ */
function toast(msg,type='ok',dur=3200){
  const w=document.getElementById('toast-wrap');
  const c={ok:'var(--ok)',err:'var(--err)',warn:'var(--warn)',info:'var(--a1)'};
  const el=document.createElement('div');
  el.style.cssText=`background:var(--card);border:1px solid ${c[type]};padding:10px 18px;border-radius:10px;font-family:'JetBrains Mono',monospace;font-size:12px;color:${c[type]};margin-bottom:8px;animation:lin .3s;`;
  el.textContent=msg; w.appendChild(el);
  setTimeout(()=>el.remove(),dur);
}

/* ══════════════════════════════════════════════════════════
   FOLDER PICKER
══════════════════════════════════════════════════════════ */
async function pickSource(){
  try{
    const h = await window.showDirectoryPicker({mode:'read'});
    srcHandle = h;
    let dirs=0, files=0;
    for await(const [,e] of h.entries()){ e.kind==='directory'?dirs++:files++; }
    document.getElementById('src-banner').classList.add('show');
    document.getElementById('src-name').textContent = h.name;
    document.getElementById('src-meta').textContent = `${dirs} subfolder · ${files} file`;
    document.getElementById('card-pick').classList.add('selected');
    markStep('sp1','✓');
    validate();
    toast(`📁 "${h.name}" dipilih`,'ok');
  }catch(e){ if(e.name!=='AbortError') toast('Gagal: '+e.message,'err'); }
}
function clearSource(){
  srcHandle=null;
  document.getElementById('src-banner').classList.remove('show');
  document.getElementById('card-pick').classList.remove('selected');
  markStep('sp1','1',false);
  validate();
}
async function pickOutput(){
  try{
    const h = await window.showDirectoryPicker({mode:'readwrite'});
    outHandle = h;
    document.getElementById('btn-out').innerHTML='✅ '+h.name;
    document.getElementById('btn-out').classList.add('set');
    document.getElementById('out-path').textContent='📂 '+h.name;
    document.getElementById('out-path').style.color='var(--ok)';
    document.getElementById('btn-out-clr').style.display='flex';
    toast('📂 Output: "'+h.name+'"','ok');
    updateDiagram();
  }catch(e){ if(e.name!=='AbortError') toast('Gagal pilih output: '+e.message,'err'); }
}
function clearOutput(){
  outHandle=null;
  document.getElementById('btn-out').innerHTML='📂 Pilih Folder INDUK (Output)';
  document.getElementById('btn-out').classList.remove('set');
  document.getElementById('out-path').textContent='Kosong = pilih saat run';
  document.getElementById('out-path').style.color='';
  document.getElementById('btn-out-clr').style.display='none';
  updateDiagram();
}
// Live-update the output diagram based on selected folder + loaded names
function updateDiagram(){
  var parentName = outHandle ? outHandle.name : 'FolderInduk';
  document.getElementById('od-parent-name').textContent = parentName;
  var names = getNamesArr();
  var n1 = names[0] || 'NamaDomain1';
  var n2 = names[1] || 'NamaDomain2';
  document.getElementById('od-name1').textContent = n1;
  document.getElementById('od-name2').textContent = n2;
}
function getNamesArr(){
  var raw = (document.getElementById('names-in') || {value:''}).value || '';
  return raw.split('\n').map(function(s){ return s.trim(); }).filter(Boolean);
}
function markStep(id,txt,done=true){
  const el=document.getElementById(id);
  el.textContent=txt;
  done?el.classList.add('done'):el.classList.remove('done');
}

/* ══════════════════════════════════════════════════════════
   NAMES / MODE
══════════════════════════════════════════════════════════ */
function setMode(m){
  mode=m;
  document.getElementById('md-domain').classList.toggle('active',m==='domain');
  document.getElementById('md-raw').classList.toggle('active',m==='raw');
  document.getElementById('mode-hint').textContent = m==='domain'
    ? 'Mode Domain: TLD (.com/.net/.co.id/.go.id/dll) dihapus otomatis dari nama folder.'
    : 'Mode Nama Langsung: teks dipakai as-is sebagai nama folder.';
  validate();
}
function getNames(){
  const raw=document.getElementById('names-in').value.trim();
  if(!raw) return [];
  return [...new Set(
    raw.split('\n').map(l=>l.trim()).filter(Boolean).map(n=>{
      if(mode==='domain')
        n=n.replace(/\.(?:co\.id|net\.id|sch\.id|go\.id|or\.id|ac\.id|web\.id|my\.id|biz\.id|[a-z]{2,8})$/i,'');
      return n.replace(/[\\/:*?"<>|]/g,'').trim();
    }).filter(Boolean)
  )];
}
document.getElementById('names-in').addEventListener('input',validate);

/* ══════════════════════════════════════════════════════════
   VALIDATE
══════════════════════════════════════════════════════════ */
function validate(){
  const names=getNames(), n=names.length;
  const badge=document.getElementById('cnt-badge');
  const strip=document.getElementById('val-strip');
  const msg=document.getElementById('val-msg');
  const btn=document.getElementById('btn-run');
  badge.textContent=n+' names';
  badge.className='cnt-badge '+(n>0?'cnt-ok':'cnt-neu');
  if(!srcHandle){
    strip.className='val-strip val-idle'; msg.textContent='Pilih folder sumber (Step 1).'; btn.disabled=true; return;
  }
  if(!n){
    strip.className='val-strip val-idle'; msg.textContent='Isi daftar nama (Step 2).'; btn.disabled=true; return;
  }
  strip.className='val-strip val-ok';
  msg.textContent=`✅ Siap — ${n} folder akan dibuat dari "${srcHandle.name}".`;
  btn.disabled=false;
}

/* ══════════════════════════════════════════════════════════
   LOG HELPERS
══════════════════════════════════════════════════════════ */
function addLog(type,icon,msg){
  const log=document.getElementById('exec-log');
  const ts=new Date().toTimeString().slice(0,8);
  const d=document.createElement('div');
  d.className=`ll lt-${type}`;
  d.innerHTML=`<span class="ll-ts">${ts}</span><span class="ll-ic">${icon}</span><span class="ll-msg">${String(msg).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span>`;
  log.appendChild(d);
  log.scrollTop=log.scrollHeight;
}
function setPb(id,pct){ document.getElementById(id).style.width=pct+'%'; }
function setCnt(id,a,b){ document.getElementById(id).textContent=`${a} / ${b}`; }
function setPill(id,txt,cls){ const e=document.getElementById(id); e.textContent=txt; if(cls)e.className='epill '+cls; }
function setStatus(txt,dot){ document.getElementById('estatus').textContent=txt; document.getElementById('edot').className='edot '+(dot||''); }

/* ══════════════════════════════════════════════════════════
   RECURSIVE COPY  (src FSDirectoryHandle → destParent, newName)
══════════════════════════════════════════════════════════ */
/* ══════════════════════════════════════════════════════════
   COPY ENGINE — streaming + parallel concurrency
══════════════════════════════════════════════════════════ */

// Recursive stream copy: src dir → destParent/newName
async function copyDirTo(src, destParent, newName, preloadedEntries){
  const newDir = await destParent.getDirectoryHandle(newName,{create:true});
  // Use pre-loaded entries if provided (avoids concurrent .entries() on shared handle)
  let entries;
  if(preloadedEntries){
    entries = preloadedEntries;
  } else {
    entries = [];
    for await(const [name,entry] of src.entries()) entries.push([name,entry]);
  }

  // Copy files in parallel (up to 4 at once within a folder)
  const files = entries.filter(([,e])=>e.kind==='file');
  const dirs  = entries.filter(([,e])=>e.kind==='directory');

  await concPool(files, 4, async ([name,entry])=>{
    const f        = await entry.getFile();
    const writable = await (await newDir.getFileHandle(name,{create:true})).createWritable();
    await f.stream().pipeTo(writable); // streaming — no RAM buffer
  });

  // Recurse dirs sequentially (avoid too many concurrent handles)
  for(const [name,entry] of dirs){
    await copyDirTo(entry, newDir, name);
  }
}

// Generic concurrency pool: run tasks with max N in-flight at once
async function concPool(items, limit, fn){
  let i=0;
  async function run(){
    while(i < items.length){
      const item = items[i++];
      await fn(item);
    }
  }
  const workers = Array.from({length:Math.min(limit,items.length)},run);
  await Promise.all(workers);
}

// Remove dir recursively
async function rmDir(parent, name){
  try{ await parent.removeEntry(name,{recursive:true}); }
  catch(e){
    const d=await parent.getDirectoryHandle(name);
    for await(const [n,en] of d.entries()){
      en.kind==='file' ? await d.removeEntry(n) : await rmDir(d,n);
    }
    await parent.removeEntry(name);
  }
}

/* ══════════════════════════════════════════════════════════
   MAIN RUN — direct copy to final name, parallel folders
══════════════════════════════════════════════════════════ */
async function runIt(){
  if(running){ toast('Sedang berjalan...','warn'); return; }
  if(!srcHandle){ toast('Pilih folder sumber dulu!','err'); return; }

  const names  = getNames();
  if(!names.length){ toast('Isi daftar nama dulu!','err'); return; }

  const overwrite = document.getElementById('opt-overwrite').checked;
  const limit     = parseInt(document.getElementById('sel-limit').value)||9999;
  const total     = Math.min(names.length, limit);
  const finalNames = names.slice(0,total);

  // Concurrency: ALWAYS 1 for folder-level parallelism.
  // Chrome File System Access API throws "file not found" errors when
  // multiple workers call srcHandle.entries() concurrently on the same handle.
  // File-level parallelism inside copyDirTo is still used (up to 4 files at once).
  const CONC = 1;

  // Need output folder
  let out = outHandle;
  if(!out){
    try{
      toast('Pilih folder OUTPUT tempat menyimpan hasil...','info',5000);
      out = await window.showDirectoryPicker({mode:'readwrite'});
      outHandle = out;
      document.getElementById('btn-out').innerHTML='✅ '+out.name;
      document.getElementById('btn-out').classList.add('set');
      document.getElementById('out-path').textContent='📂 '+out.name;
      document.getElementById('out-path').style.color='var(--ok)';
      document.getElementById('btn-out-clr').style.display='flex';
    }catch(e){ if(e.name!=='AbortError') toast('Folder output diperlukan!','err'); return; }
  }

  const perm = await out.requestPermission({mode:'readwrite'});
  if(perm!=='granted'){ toast('Izin write ditolak!','err'); return; }

  // ── UI init ────────────────────────────────────────────
  running=true; stopReq=false;
  document.getElementById('btn-run').disabled=true;
  document.getElementById('btn-run').innerHTML='<span style="opacity:.4">▶ Running...</span>';
  document.getElementById('btn-stop').style.display='';
  document.getElementById('btn-stop').disabled=false;
  document.getElementById('btn-stop').textContent='⏹ Stop';
  document.getElementById('exec-section').style.display='block';
  document.getElementById('exec-sum').style.display='none';
  document.getElementById('exec-log').innerHTML='';
  document.getElementById('run-hint').textContent='';
  setPb('pb-c',0);
  setCnt('pc-cnt',0,total);
  setPill('ep-c',`📋 0/${total}`,'');
  const concPill=document.getElementById('ep-conc');
  concPill.style.display=''; concPill.className='epill ep-c';
  concPill.textContent=`⚡ ${CONC}× parallel`;
  setStatus('Running...','running');
  document.getElementById('t-name').textContent=`BulkReplace — "${srcHandle.name}" × ${total} (${CONC}× parallel)`;
  document.getElementById('active-jobs').textContent='0 aktif';
  document.getElementById('speed-txt').textContent='';
  document.getElementById('eta-txt').textContent='';
  document.getElementById('exec-section').scrollIntoView({behavior:'smooth',block:'start'});

  t0=Date.now();
  clock=setInterval(()=>{
    document.getElementById('ep-t').textContent=`⏱ ${((Date.now()-t0)/1000).toFixed(1)}s`;
  },100);

  addLog('head','▶',`Bulk Copy & Rename — ${total} folder · ${CONC}× parallel`);
  addLog('info','  ',`Source : ${srcHandle.name}`);
  addLog('info','  ',`Output : ${out.name}`);
  addLog('info','  ',`Mode   : Direct copy → final name (no tmp phase)`);
  addLog('div', '  ','─────────────────────────────────────────────────');

  let done=0, okCount=0, skipCount=0, errCount=0;
  let activeJobs=0;
  const foldersPerSec = [];

  // Build task queue
  const tasks = finalNames.map((name,i)=>({name,idx:i+1}));

  // Worker function for each parallel slot
  async function copyWorker(){
    while(tasks.length > 0){
      if(stopReq) break;
      const {name,idx} = tasks.shift();

      activeJobs++;
      document.getElementById('active-jobs').textContent=`${activeJobs} aktif`;
      addLog('copy','  ',`[${idx}/${total}] Copying → "${name}"...`);

      try{
        // Check if target already exists
        let ex=false;
        try{ await out.getDirectoryHandle(name); ex=true; }catch(e){}

        if(ex && !overwrite){
          addLog('skip','  ',`  → Skip "${name}" sudah ada (overwrite OFF)`);
          skipCount++;
        } else {
          if(ex) await rmDir(out, name);
          const folderStart = Date.now();
          await copyDirTo(srcHandle, out, name, srcSnapshot);
          const ms = Date.now() - folderStart;
          foldersPerSec.push(ms);
          addLog('ok','  ',`  ✓ "${name}" (${ms<1000?(ms+'ms'):(ms/1000).toFixed(1)+'s'})`);
          okCount++;
        }
      }catch(e){
        addLog('err','  ',`  ✗ "${name}" — ${e.message}`);
        errCount++;
      }

      activeJobs--;
      done++;
      const pct = Math.round(done/total*100);
      setPb('pb-c', pct);
      setCnt('pc-cnt', done, total);
      setPill('ep-c', `📋 ${done}/${total}`, done===total?'ep-done':'ep-c');
      document.getElementById('active-jobs').textContent=`${activeJobs} aktif`;

      // Speed + ETA
      if(foldersPerSec.length >= 2){
        const last5 = foldersPerSec.slice(-5);
        const avgMs = last5.reduce((a,b)=>a+b,0)/last5.length;
        const remaining = total - done;
        const etaSec = Math.round((remaining * avgMs) / (CONC * 1000));
        document.getElementById('speed-txt').textContent =
          `~${(avgMs/1000).toFixed(1)}s/folder`;
        document.getElementById('eta-txt').textContent =
          remaining>0 ? `ETA ~${etaSec}s` : 'Selesai!';
      }

      await new Promise(r=>setTimeout(r,0)); // yield to browser
    }
  }

  // Pre-snapshot the source directory entries ONCE before workers start.
  // This prevents "file not found" errors from concurrent srcHandle.entries() calls.
  addLog('info','  ','Scanning source folder...');
  let srcSnapshot = null;
  try{
    srcSnapshot = [];
    for await(const [name,entry] of srcHandle.entries()) srcSnapshot.push([name,entry]);
    addLog('info','  ', srcSnapshot.length + ' items in source folder');
  }catch(e){
    addLog('err','✗','Gagal scan source: '+e.message); running=false; return;
  }

  // Launch CONC parallel workers (CONC=1 to avoid concurrent handle access)
  const workers = Array.from({length: CONC}, ()=>copyWorker());
  await Promise.all(workers);

  finishRun(okCount, skipCount, errCount);
}

/* ══════════════════════════════════════════════════════════
   FINISH
══════════════════════════════════════════════════════════ */
function finishRun(ok, skip, err){
  clearInterval(clock); running=false; stopReq=false;
  const elapsed=((Date.now()-t0)/1000).toFixed(1);
  document.getElementById('btn-run').disabled=false;
  document.getElementById('btn-run').innerHTML='▶ Jalankan Copy & Rename';
  document.getElementById('btn-stop').style.display='none';
  document.getElementById('ep-t').textContent=`⏱ ${elapsed}s`;
  document.getElementById('active-jobs').textContent='Selesai';
  document.getElementById('eta-txt').textContent='Done!';
  addLog('div','  ','─────────────────────────────────────────────────');
  addLog('done','🎉',`SELESAI — ${ok} berhasil · ${skip} skip · ${err} error · ${elapsed}s`);
  setStatus(`Done — ${ok} berhasil`,'done');
  setPill('ep-c',`✅ ${ok}/${ok+skip+err}`,'ep-done');
  document.getElementById('ep-conc').style.display='none';
  const sum=document.getElementById('exec-sum');
  sum.style.display='flex';
  document.getElementById('s-ok').textContent=`✅ ${ok} berhasil`;
  document.getElementById('s-time').textContent=`⏱ ${elapsed}s total`;
  if(skip>0){ document.getElementById('s-skip').style.display=''; document.getElementById('s-skip').textContent=`⚠️ ${skip} skip`; }
  if(err>0){  document.getElementById('s-err').style.display='';  document.getElementById('s-err').textContent=`❌ ${err} error`; }
  document.getElementById('run-hint').textContent=ok>0?`${ok} folder siap di "${outHandle?.name}"`:err>0?`${err} error — cek log`:'';
  if(ok>0) toast(`✅ ${ok} folder berhasil! (${elapsed}s)`,'ok',5000);
  else if(err>0) toast(`❌ ${err} error — cek execution log`,'err',5000);

  // Log usage to server for tracking
  try{
    const fd=new FormData();
    fd.append('action','log');
    fd.append('csv_rows',ok);
    fd.append('files_updated',ok+skip+err);
    fd.append('job_type','copy_rename');
    fd.append('job_name','Copy/Rename: '+ok+' folders');
    fd.append('csrf_token','<?= csrf_token() ?>');
    fetch('/api/usage.php',{method:'POST',body:fd}).catch(function(e){});
  }catch(e){}
}

function doStop(){
  stopReq=true;
  document.getElementById('btn-stop').disabled=true;
  document.getElementById('btn-stop').textContent='⏹ Stopping...';
  addLog('warn','⏹','Stop diminta — menunggu operasi saat ini selesai...');
  toast('Stopping after current item...','warn');
}
</script>
</body>
</html>
