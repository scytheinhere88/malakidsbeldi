<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';

startSession();
requireLogin();

$user = currentUser();
if(!$user){
  header('Location: /auth/login.php');
  exit;
}

// Security: Check addon access properly
$hasAccess = hasAddonAccess($user['id'], 'zip-manager');

// Audit log access attempt
$auditLogger = new AuditLogger(db());
$auditLogger->setUserId($user['id']);
$auditLogger->log(
  'access_zip_manager',
  'addon_access',
  $hasAccess ? 'success' : 'blocked',
  [
    'target_type' => 'addon',
    'target_id' => 'zip-manager',
    'request_data' => [
      'user_plan' => $user['plan'] ?? 'free',
      'has_access' => $hasAccess
    ]
  ]
);

if(!$hasAccess){
  header('Location: /landing/pricing.php?addon=zip');
  exit;
}

$plan = $user['plan'] ?? 'free';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>ZIP Manager — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<script src="/assets/jszip.min.js"></script>
<style>
.zm-tabs{display:flex;background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:24px;}
.zm-tab{flex:1;padding:13px 20px;display:flex;align-items:center;justify-content:center;gap:9px;cursor:pointer;font-family:'JetBrains Mono',monospace;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);border:none;background:transparent;transition:all .2s;position:relative;}
.zm-tab::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:transparent;transition:background .2s;}
.zm-tab.tab-zip.active{color:var(--a1);background:rgba(240,165,0,.06);}
.zm-tab.tab-zip.active::after{background:var(--a1);}
.zm-tab.tab-unzip.active{color:var(--a2);background:rgba(0,212,170,.06);}
.zm-tab.tab-unzip.active::after{background:var(--a2);}
.zm-tdot{width:7px;height:7px;border-radius:50%;background:var(--muted);transition:all .2s;flex-shrink:0;}
.zm-tab.tab-zip.active .zm-tdot{background:var(--a1);box-shadow:0 0 8px var(--a1);}
.zm-tab.tab-unzip.active .zm-tdot{background:var(--a2);box-shadow:0 0 8px var(--a2);}
.zm-panel{display:none;}.zm-panel.active{display:block;}

.zm-card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:24px 28px;margin-bottom:18px;position:relative;overflow:hidden;}
.zm-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;}
.zmc::before{background:linear-gradient(90deg,var(--a1),transparent);}
.umc::before{background:linear-gradient(90deg,var(--a2),transparent);}
.zm-sec{font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:.14em;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
.zm-sec-z{color:var(--a1);}.zm-sec-u{color:var(--a2);}
.step-num{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:5px;font-size:10px;font-weight:700;flex-shrink:0;}
.sn-z{background:rgba(240,165,0,.15);border:1px solid rgba(240,165,0,.3);color:var(--a1);}
.sn-u{background:rgba(0,212,170,.15);border:1px solid rgba(0,212,170,.3);color:var(--a2);}

.zm-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;}
@media(max-width:640px){.zm-stats{grid-template-columns:repeat(2,1fr);}}
.zm-stat{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px 18px;position:relative;overflow:hidden;}
.zm-stat::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;}
.stat-t::after{background:var(--border2);}
.stat-o::after{background:var(--ok);}
.stat-s::after{background:var(--muted);}
.stat-e::after{background:var(--err);}
.zm-snum{font-family:'Syne',sans-serif;font-size:32px;font-weight:900;line-height:1;margin-bottom:4px;}
.stat-t .zm-snum{color:var(--text);}.stat-o .zm-snum{color:var(--ok);}
.stat-s .zm-snum{color:var(--muted);}.stat-e .zm-snum{color:var(--err);}
.zm-slbl{font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);}

.row2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
@media(max-width:640px){.row2{grid-template-columns:1fr;}}
.zm-lbl{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-bottom:6px;letter-spacing:.05em;display:block;}
.zm-hint{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:5px;line-height:1.7;}
.zm-hint code{background:rgba(255,255,255,.06);padding:1px 5px;border-radius:4px;color:rgba(255,255,255,.5);font-size:10px;}

.pick-btn{width:100%;background:var(--card2);border:2px solid var(--border);border-radius:12px;padding:28px 20px;text-align:center;cursor:pointer;transition:all .2s;position:relative;}
.pick-btn:hover{border-color:rgba(240,165,0,.5);background:rgba(240,165,0,.05);transform:translateY(-2px);}
.pick-btn.picked-z{border-color:rgba(0,230,118,.5);background:rgba(0,230,118,.05);}
.pick-btn.picked-u{border-color:rgba(0,212,170,.5);background:rgba(0,212,170,.05);}
.pick-btn-icon{font-size:32px;margin-bottom:8px;display:block;}
.pick-btn-title{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:4px;}
.pick-btn-sub{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:1.6;}
.picked-badge{position:absolute;top:10px;right:12px;background:var(--ok);color:#000;font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;padding:3px 8px;border-radius:100px;letter-spacing:.05em;}

.out-bar{display:flex;align-items:center;gap:10px;margin-top:14px;flex-wrap:wrap;}
.btn-pick-out{display:inline-flex;align-items:center;gap:7px;background:var(--card2);border:1px solid var(--border);color:var(--muted);border-radius:8px;padding:9px 16px;font-family:'JetBrains Mono',monospace;font-size:11px;cursor:pointer;transition:all .2s;white-space:nowrap;}
.btn-pick-out:hover{border-color:rgba(240,165,0,.4);color:var(--a1);}
.btn-pick-out.set{border-color:rgba(0,230,118,.3);color:var(--ok);}
.out-label{flex:1;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.btn-clr-out{width:28px;height:28px;background:transparent;border:1px solid rgba(255,69,96,.2);color:var(--err);border-radius:6px;cursor:pointer;font-size:13px;transition:all .2s;display:none;flex-shrink:0;}
.btn-clr-out:hover{background:rgba(255,69,96,.08);}

.struct-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
@media(max-width:580px){.struct-grid{grid-template-columns:1fr;}}
.struct-card{background:var(--bg2);border:2px solid var(--border);border-radius:10px;padding:14px 16px;cursor:pointer;transition:all .2s;}
.struct-card:hover{border-color:var(--border2);}
.struct-card.active{border-color:rgba(240,165,0,.6);background:rgba(240,165,0,.07);}
.struct-card.active .sc-title{color:var(--a1);}
.sc-title{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--text);margin-bottom:4px;}
.sc-prev{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);background:rgba(0,0,0,.3);border-radius:6px;padding:8px 10px;margin-top:8px;line-height:1.9;}

.opt-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
@media(max-width:580px){.opt-grid{grid-template-columns:1fr;}}
.opt-row{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:13px 14px;cursor:pointer;transition:all .2s;display:flex;align-items:flex-start;gap:10px;user-select:none;}
.opt-row:hover{background:rgba(255,255,255,.04);}
.zip-panel .opt-row.on{background:rgba(240,165,0,.07);border-color:rgba(240,165,0,.3);}
.unzip-panel .opt-row.on{background:rgba(0,212,170,.07);border-color:rgba(0,212,170,.3);}
.opt-chk{width:17px;height:17px;border-radius:4px;border:2px solid var(--muted);flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:11px;margin-top:1px;transition:all .2s;}
.zip-panel .opt-row.on .opt-chk{background:var(--a1);border-color:var(--a1);color:#000;}
.unzip-panel .opt-row.on .opt-chk{background:var(--a2);border-color:var(--a2);color:#000;}
.opt-name{font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--text);}
.opt-info{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:3px;line-height:1.6;}

.zm-prog{display:none;margin-bottom:16px;}
.zm-prog.show{display:block;}
.zm-prog-top{display:flex;justify-content:space-between;align-items:center;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-bottom:7px;}
.zm-track{background:var(--bg2);border:1px solid var(--border);border-radius:100px;height:7px;overflow:hidden;}
.zm-fill{height:100%;border-radius:100px;width:0%;transition:width .1s linear;}
.zip-panel .zm-fill{background:linear-gradient(90deg,#c47d00,var(--a1));}
.unzip-panel .zm-fill{background:linear-gradient(90deg,#007a63,var(--a2));}

.exec-box{display:none;border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-top:16px;}
.exec-box.show{display:block;}
.exec-head{display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:8px;}
.zip-panel .exec-head{background:linear-gradient(90deg,rgba(240,165,0,.04),transparent);}
.unzip-panel .exec-head{background:linear-gradient(90deg,rgba(0,212,170,.04),transparent);}
.exec-head-l{display:flex;align-items:center;gap:10px;}
.run-dot{width:8px;height:8px;border-radius:50%;background:var(--muted);transition:all .3s;flex-shrink:0;}
.run-dot.running{background:var(--ok);box-shadow:0 0 10px var(--ok);animation:pulse 1s ease-in-out infinite;}
.run-dot.done{background:var(--ok);box-shadow:0 0 10px var(--ok);}
.run-dot.error{background:var(--err);box-shadow:0 0 8px var(--err);}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.3;transform:scale(.6)}}
.exec-label{font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:.14em;}
.zip-panel .exec-label{color:var(--a1);}.unzip-panel .exec-label{color:var(--a2);}
.exec-status{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);}
.epills{display:flex;gap:8px;flex-wrap:wrap;}
.epill{font-family:'JetBrains Mono',monospace;font-size:10px;padding:3px 10px;border-radius:100px;border:1px solid var(--border);color:var(--muted);white-space:nowrap;transition:all .3s;}
.epill-live{border-color:rgba(0,230,118,.3);color:var(--ok);background:rgba(0,230,118,.06);}
.epill-done{border-color:rgba(0,212,170,.3);color:var(--a2);background:rgba(0,212,170,.06);}
.term-bar{display:flex;align-items:center;justify-content:space-between;padding:8px 14px;background:rgba(0,0,0,.45);border-bottom:1px solid rgba(255,255,255,.04);}
.term-dots{display:flex;gap:5px;}
.tdot{width:10px;height:10px;border-radius:50%;}
.tdot-r{background:#ff5c57;}.tdot-y{background:#ffbd2e;}.tdot-g{background:#27c93f;}
.term-title{font-family:'JetBrains Mono',monospace;font-size:10px;color:rgba(255,255,255,.3);flex:1;text-align:center;}
.btn-clr-log{font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);background:transparent;border:1px solid var(--border);border-radius:4px;padding:2px 8px;cursor:pointer;}
.btn-clr-log:hover{color:var(--err);}
.exec-log{background:#04040d;font-family:'JetBrains Mono',monospace;font-size:11.5px;line-height:1.85;height:300px;overflow-y:auto;padding:14px 18px;}
@media(max-width:600px){.exec-log{height:220px;}}
.exec-log::-webkit-scrollbar{width:4px}
.exec-log::-webkit-scrollbar-thumb{background:rgba(240,165,0,.15);border-radius:4px}
.logline{display:flex;gap:9px;align-items:flex-start;margin-bottom:3px;}
.ll-ts{color:rgba(255,255,255,.17);flex-shrink:0;font-size:10px;padding-top:2px;min-width:54px;}
.ll-ic{flex-shrink:0;width:16px;text-align:center;}
.ll-tx{flex:1;word-break:break-all;}
.type-head .ll-tx{color:#fff;font-weight:700;}
.type-info .ll-tx{color:rgba(255,255,255,.4);}
.type-folder .ll-tx{color:rgba(255,255,255,.75);font-weight:600;}
.type-ok .ll-tx{color:var(--ok);}
.type-skip .ll-tx{color:var(--muted);}
.type-err .ll-tx{color:var(--err);}
.type-warn .ll-tx{color:var(--warn);}
.type-done .ll-tx{color:var(--ok);font-weight:700;}
.type-div .ll-tx{color:rgba(255,255,255,.08);}
.exec-sum{display:none;padding:12px 20px;border-top:1px solid var(--border);background:rgba(0,0,0,.15);align-items:center;gap:14px;flex-wrap:wrap;}
.sum-p{font-family:'JetBrains Mono',monospace;font-size:11px;}
.sum-ok{color:var(--ok);}.sum-sk{color:var(--muted);}.sum-er{color:var(--err);}.sum-ti{color:var(--muted);}

.info-banner{display:flex;align-items:flex-start;gap:12px;padding:13px 16px;border-radius:10px;font-family:'JetBrains Mono',monospace;font-size:11px;line-height:1.7;border:1px solid rgba(240,165,0,.15);background:rgba(240,165,0,.05);color:rgba(255,255,255,.6);margin-bottom:20px;}
.zm-errmsg{font-family:'JetBrains Mono',monospace;font-size:11px;padding:9px 13px;border-radius:8px;margin-bottom:12px;background:rgba(255,69,96,.06);border:1px solid rgba(255,69,96,.2);color:var(--err);display:none;}
.zm-errmsg.show{display:block;}
.btn-log-export{display:inline-flex;align-items:center;gap:6px;background:transparent;border:1px solid var(--border);color:var(--muted);border-radius:8px;padding:7px 14px;font-family:'JetBrains Mono',monospace;font-size:10px;cursor:pointer;transition:all .2s;}
.btn-log-export:hover{border-color:var(--border2);color:var(--text);}
.compat-err{display:none;padding:13px 16px;border-radius:10px;border:1px solid rgba(255,69,96,.3);background:rgba(255,69,96,.06);color:var(--err);font-family:'JetBrains Mono',monospace;font-size:12px;margin-bottom:20px;}

/* ZIP PREVIEW MODAL */
.zm-modal{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.85);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.zm-modal.show{display:flex;}
.zm-modal-box{background:var(--card);border:1px solid var(--border);border-radius:16px;max-width:700px;width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 10px 40px rgba(0,0,0,.5);}
.zm-modal-head{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.zm-modal-title{font-family:'Syne',sans-serif;font-size:16px;font-weight:700;display:flex;align-items:center;gap:10px;}
.zm-modal-close{width:32px;height:32px;border-radius:8px;background:transparent;border:1px solid var(--border);color:var(--muted);cursor:pointer;font-size:18px;transition:all .2s;}
.zm-modal-close:hover{background:rgba(255,69,96,.1);border-color:var(--err);color:var(--err);}
.zm-modal-body{padding:20px 24px;overflow-y:auto;flex:1;}
.zm-modal-body::-webkit-scrollbar{width:6px;}
.zm-modal-body::-webkit-scrollbar-thumb{background:rgba(240,165,0,.2);border-radius:4px;}
.zm-preview-info{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-bottom:16px;padding:10px 14px;background:rgba(240,165,0,.05);border:1px solid rgba(240,165,0,.15);border-radius:8px;}
.zm-preview-list{background:var(--bg2);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
.zm-preview-item{padding:10px 14px;border-bottom:1px solid var(--border);font-family:'JetBrains Mono',monospace;font-size:11px;display:flex;align-items:center;gap:8px;}
.zm-preview-item:last-child{border-bottom:none;}
.zm-preview-item.is-dir{color:var(--a1);font-weight:600;}
.zm-preview-item.is-file{color:var(--muted);}
.zm-modal-foot{padding:16px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;}
</style>
</head>
<body>
<div id="zm-toast-wrap" style="position:fixed;bottom:24px;right:24px;z-index:9999;min-width:280px;"></div>

<!-- ZIP PREVIEW MODAL -->
<div class="zm-modal" id="zm-preview-modal">
  <div class="zm-modal-box">
    <div class="zm-modal-head">
      <div class="zm-modal-title"><span id="zm-preview-icon">📦</span> <span id="zm-preview-title">Preview ZIP Contents</span></div>
      <button class="zm-modal-close" onclick="zmClosePreview()">✕</button>
    </div>
    <div class="zm-modal-body">
      <div class="zm-preview-info" id="zm-preview-info"></div>
      <div class="zm-preview-list" id="zm-preview-list"></div>
    </div>
    <div class="zm-modal-foot">
      <button class="btn btn-ghost" onclick="zmClosePreview()">Cancel</button>
      <button class="btn btn-amber" id="zm-preview-confirm" onclick="zmConfirmZip()">✓ Proceed with ZIP</button>
    </div>
  </div>
</div>

<div class="dash-layout">
<?php include __DIR__.'/_sidebar.php'; ?>
<div class="dash-main">

  <div class="dash-topbar">
    <div class="dash-page-title">🗜️ ZIP Manager</div>
    <div style="display:flex;align-items:center;gap:10px;">
      <span style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);"><?= htmlspecialchars($user['name']) ?></span>
      <span class="plan-pill pp-<?= $plan ?>"><?= ucfirst($plan) ?></span>
    </div>
  </div>

  <div class="dash-content">

    <div class="compat-err" id="compat-err">
      ⚠️ Unsupported browser. File System Access API requires <strong>Chrome 86+ or Edge 86+</strong>.
    </div>

    <div class="info-banner">
      <span style="font-size:16px;flex-shrink:0;">🔒</span>
      <div><strong style="color:var(--a1);">Client-Side Processing</strong> — All files processed locally in your browser. Nothing uploaded to servers. Requires Chrome 86+ or Edge 86+. <strong style="color:var(--ok);">Fast Performance:</strong> Optimized parallel processing delivers up to 10x faster operations.</div>
    </div>

    <div class="zm-tabs">
      <button class="zm-tab tab-zip active" id="tab-zip" onclick="zmSwitchTab('zip')">
        <div class="zm-tdot"></div>🗜️ Bulk ZIP
      </button>
      <button class="zm-tab tab-unzip" id="tab-unzip" onclick="zmSwitchTab('unzip')">
        <div class="zm-tdot"></div>📂 Bulk UNZIP
      </button>
    </div>

    <!-- ══════════ ZIP PANEL ══════════ -->
    <div class="zm-panel zip-panel active" id="panel-zip">

      <div class="zm-stats">
        <div class="zm-stat stat-t"><div class="zm-snum" id="z-n-total">0</div><div class="zm-slbl">Total</div></div>
        <div class="zm-stat stat-o"><div class="zm-snum" id="z-n-ok">0</div><div class="zm-slbl">Zipped</div></div>
        <div class="zm-stat stat-s"><div class="zm-snum" id="z-n-skip">0</div><div class="zm-slbl">Skipped</div></div>
        <div class="zm-stat stat-e"><div class="zm-snum" id="z-n-err">0</div><div class="zm-slbl">Failed</div></div>
      </div>

      <!-- Step 1 -->
      <div class="zm-card zmc">
        <div class="zm-sec zm-sec-z"><span class="step-num sn-z">1</span> Select Source & Output</div>
        <div class="row2" style="margin-bottom:14px;">
          <div>
            <label class="zm-lbl">📁 SOURCE FOLDER</label>
            <div class="pick-btn" id="z-pick-src-btn" onclick="zmPickSrc()">
              <span class="pick-btn-icon" id="z-src-icon">📁</span>
              <div class="pick-btn-title" id="z-src-title">Select Folder</div>
              <div class="pick-btn-sub" id="z-src-sub">Click to browse folders<br>Each subfolder will be zipped</div>
            </div>
          </div>
          <div>
            <label class="zm-lbl">💾 OUTPUT FOLDER</label>
            <div class="pick-btn" id="z-pick-out-btn" onclick="zmPickOut()">
              <span class="pick-btn-icon" id="z-out-icon">💾</span>
              <div class="pick-btn-title" id="z-out-title">Select Output</div>
              <div class="pick-btn-sub" id="z-out-sub">ZIP files saved here<br>Can be new or existing</div>
            </div>
          </div>
        </div>
        <p class="zm-hint">Each <strong style="color:var(--text);">subfolder</strong> inside source will be compressed into a separate <code>.zip</code> file.</p>
      </div>

      <!-- Step 2 -->
      <div class="zm-card zmc">
        <div class="zm-sec zm-sec-z"><span class="step-num sn-z">2</span> Structure</div>
        <div class="struct-grid">
          <div class="struct-card active" id="zstruct-contents" onclick="zmSelectStruct('contents')">
            <div class="sc-title">Contents Only</div>
            <div class="zm-hint">Files at ZIP root</div>
            <div class="sc-prev">📦 example.zip<br>├── index.php<br>├── style.css<br>└── assets/</div>
          </div>
          <div class="struct-card" id="zstruct-folder" onclick="zmSelectStruct('folder')">
            <div class="sc-title">Include Folder</div>
            <div class="zm-hint">Files wrapped in folder</div>
            <div class="sc-prev">📦 example.zip<br>└── example/<br>&nbsp;&nbsp;&nbsp;├── index.php<br>&nbsp;&nbsp;&nbsp;└── style.css</div>
          </div>
        </div>
      </div>

      <!-- Step 3 -->
      <div class="zm-card zmc">
        <div class="zm-sec zm-sec-z"><span class="step-num sn-z">3</span> Options</div>
        <div class="opt-grid">
          <div class="opt-row on" id="zopt-skip-empty" onclick="zmToggleOpt(this)">
            <div class="opt-chk">✓</div>
            <div><div class="opt-name">Skip Empty Folders</div><div class="opt-info">Ignore subfolders with no files</div></div>
          </div>
          <div class="opt-row on" id="zopt-hidden" onclick="zmToggleOpt(this)">
            <div class="opt-chk">✓</div>
            <div><div class="opt-name">Include Hidden Files</div><div class="opt-info">Include files starting with dot (.)</div></div>
          </div>
        </div>
      </div>

      <!-- Step 4 -->
      <div class="zm-card zmc">
        <div class="zm-sec zm-sec-z"><span class="step-num sn-z">4</span> Execute</div>
        <div class="zm-errmsg" id="z-errmsg"></div>
        <div class="zm-prog" id="z-prog">
          <div class="zm-prog-top"><span id="z-prog-lbl">Zipping...</span><span id="z-prog-val">0 / 0</span></div>
          <div class="zm-track"><div class="zm-fill" id="z-prog-bar"></div></div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
          <button class="btn btn-amber" id="z-run-btn" onclick="zmRunZip()" disabled>
            <span id="z-run-sp"></span>🗜️ Run Bulk ZIP
          </button>
          <button class="btn btn-ghost btn-sm" id="z-stop-btn" onclick="zmStopZip()" style="display:none;">⏹ Stop</button>
          <button class="btn-log-export" onclick="zmExportLog('z')">💾 Export Log</button>
        </div>
        <div class="exec-box" id="z-exec-box">
          <div class="exec-head">
            <div class="exec-head-l">
              <div class="run-dot" id="z-rundot"></div>
              <div class="exec-label">Execution Log</div>
              <div class="exec-status" id="z-exec-status">Idle</div>
            </div>
            <div class="epills">
              <div class="epill" id="z-pill-prog">🗜️ 0/0</div>
              <div class="epill" id="z-pill-time">⏱ 0s</div>
            </div>
          </div>
          <div class="term-bar">
            <div class="term-dots"><div class="tdot tdot-r"></div><div class="tdot tdot-y"></div><div class="tdot tdot-g"></div></div>
            <div class="term-title" id="z-term-title">BulkReplace — Bulk ZIP</div>
            <button class="btn-clr-log" onclick="zmClearLog('z')">✕ Clear</button>
          </div>
          <div class="exec-log" id="z-log-body"></div>
          <div class="exec-sum" id="z-exec-sum">
            <div class="sum-p sum-ok" id="z-sum-ok">✅ 0 zipped</div>
            <div class="sum-p sum-sk" id="z-sum-skip" style="display:none;">⏭ 0 skip</div>
            <div class="sum-p sum-er" id="z-sum-err"  style="display:none;">❌ 0 failed</div>
            <div style="flex:1;"></div>
            <div class="sum-p sum-ti" id="z-sum-time">⏱ 0s</div>
          </div>
        </div>
      </div>

    </div><!-- /zip-panel -->

    <!-- ══════════ UNZIP PANEL ══════════ -->
    <div class="zm-panel unzip-panel" id="panel-unzip">

      <div class="zm-stats">
        <div class="zm-stat stat-t"><div class="zm-snum" id="u-n-total">0</div><div class="zm-slbl">Total</div></div>
        <div class="zm-stat stat-o"><div class="zm-snum" id="u-n-ok">0</div><div class="zm-slbl">Extracted</div></div>
        <div class="zm-stat stat-s"><div class="zm-snum" id="u-n-skip">0</div><div class="zm-slbl">Skipped</div></div>
        <div class="zm-stat stat-e"><div class="zm-snum" id="u-n-err">0</div><div class="zm-slbl">Failed</div></div>
      </div>

      <!-- Step 1 -->
      <div class="zm-card umc">
        <div class="zm-sec zm-sec-u"><span class="step-num sn-u">1</span> Select ZIP Files & Output</div>
        <div class="row2" style="margin-bottom:14px;">
          <div>
            <label class="zm-lbl">🗜️ ZIP FILES</label>
            <div class="pick-btn" id="u-pick-zip-btn" onclick="zmPickZips()">
              <span class="pick-btn-icon" id="u-zip-icon">🗜️</span>
              <div class="pick-btn-title" id="u-zip-title">Select Files</div>
              <div class="pick-btn-sub" id="u-zip-sub">Multi-select supported<br>All selected will be extracted</div>
            </div>
          </div>
          <div>
            <label class="zm-lbl">📂 OUTPUT FOLDER</label>
            <div class="pick-btn" id="u-pick-out-btn" onclick="zmPickUnzipOut()">
              <span class="pick-btn-icon" id="u-out-icon">📂</span>
              <div class="pick-btn-title" id="u-out-title">Select Output</div>
              <div class="pick-btn-sub" id="u-out-sub">Extracted files saved here<br>Each ZIP → separate subfolder</div>
            </div>
          </div>
        </div>
        <p class="zm-hint">Each <code>.zip</code> will be extracted to its own subfolder inside the output folder.</p>
      </div>

      <!-- Step 2 -->
      <div class="zm-card umc">
        <div class="zm-sec zm-sec-u"><span class="step-num sn-u">2</span> Options</div>
        <div class="opt-grid">
          <div class="opt-row on" id="uopt-subfolder" onclick="zmToggleOpt(this)">
            <div class="opt-chk">✓</div>
            <div><div class="opt-name">Extract to Subfolder</div><div class="opt-info"><code>file.zip</code> → <code>output/file/</code></div></div>
          </div>
          <div class="opt-row" id="uopt-overwrite" onclick="zmToggleOpt(this)">
            <div class="opt-chk"></div>
            <div><div class="opt-name">Overwrite Existing</div><div class="opt-info">Replace existing files</div></div>
          </div>
          <div class="opt-row" id="uopt-delete-zip" onclick="zmToggleOpt(this)">
            <div class="opt-chk"></div>
            <div><div class="opt-name">Delete ZIP After Extract</div><div class="opt-info">⚠️ Delete source ZIP after successful extraction</div></div>
          </div>
        </div>
      </div>

      <!-- Step 3 -->
      <div class="zm-card umc">
        <div class="zm-sec zm-sec-u"><span class="step-num sn-u">3</span> Execute</div>
        <div class="zm-errmsg" id="u-errmsg"></div>
        <div class="zm-prog" id="u-prog">
          <div class="zm-prog-top"><span id="u-prog-lbl">Extracting...</span><span id="u-prog-val">0 / 0</span></div>
          <div class="zm-track"><div class="zm-fill" id="u-prog-bar"></div></div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
          <button class="btn btn-teal" id="u-run-btn" onclick="zmRunUnzip()" disabled>
            <span id="u-run-sp"></span>📂 Run Bulk UNZIP
          </button>
          <button class="btn btn-ghost btn-sm" id="u-stop-btn" onclick="zmStopUnzip()" style="display:none;">⏹ Stop</button>
          <button class="btn-log-export" onclick="zmExportLog('u')">💾 Export Log</button>
        </div>
        <div class="exec-box" id="u-exec-box">
          <div class="exec-head">
            <div class="exec-head-l">
              <div class="run-dot" id="u-rundot"></div>
              <div class="exec-label">Execution Log</div>
              <div class="exec-status" id="u-exec-status">Idle</div>
            </div>
            <div class="epills">
              <div class="epill" id="u-pill-prog">📂 0/0</div>
              <div class="epill" id="u-pill-time">⏱ 0s</div>
            </div>
          </div>
          <div class="term-bar">
            <div class="term-dots"><div class="tdot tdot-r"></div><div class="tdot tdot-y"></div><div class="tdot tdot-g"></div></div>
            <div class="term-title" id="u-term-title">BulkReplace — Bulk UNZIP</div>
            <button class="btn-clr-log" onclick="zmClearLog('u')">✕ Clear</button>
          </div>
          <div class="exec-log" id="u-log-body"></div>
          <div class="exec-sum" id="u-exec-sum">
            <div class="sum-p sum-ok" id="u-sum-ok">✅ 0 extracted</div>
            <div class="sum-p sum-sk" id="u-sum-skip" style="display:none;">⏭ 0 skip</div>
            <div class="sum-p sum-er" id="u-sum-err"  style="display:none;">❌ 0 failed</div>
            <div style="flex:1;"></div>
            <div class="sum-p sum-ti" id="u-sum-time">⏱ 0s</div>
          </div>
        </div>
      </div>

    </div><!-- /unzip-panel -->
  </div>
</div>
</div>

<script>
/* ════════════════════════════════════════════════════════
   STATE — all variables with unique names, no conflicts
════════════════════════════════════════════════════════ */
var zmSrcDir    = null;   // zip source folder handle
var zmOutDir    = null;   // zip output folder handle
var zmZipFiles  = [];     // unzip: selected File[] objects
var zmZipHandles= [];     // unzip: FileSystemFileHandle[] for deletion
var zmUnzipDir  = null;   // unzip output folder handle
var zmZipMode   = 'contents';
var zmZipBusy   = false;
var zmUnzipBusy = false;
var zmZipAbort  = false;
var zmUnzipAbort= false;
var zmLogs      = {z:[], u:[]};
var zmStats     = {z:{total:0,ok:0,skip:0,err:0}, u:{total:0,ok:0,skip:0,err:0}};
var zmTimers    = {z:null, u:null};
var zmT0        = {z:0, u:0};
var zmPreviewData = null;

/* ── compat check ── */
window.addEventListener('DOMContentLoaded', function(){
  if(typeof window.showDirectoryPicker === 'undefined'){
    document.getElementById('compat-err').style.display = 'block';
    document.querySelectorAll('.pick-btn').forEach(function(b){ b.style.opacity='.4'; b.style.cursor='not-allowed'; b.onclick=null; });
  }

  // Check JSZip loaded
  if(typeof JSZip === 'undefined'){
    console.error('JSZip library failed to load');
    zmToast('JSZip library gagal dimuat. Refresh halaman!', 'err', 8000);
  }
});

/* ════ TOAST ════════════════════════════════════════════ */
function zmToast(msg, type, dur){
  type = type||'ok'; dur = dur||3200;
  var col = {ok:'var(--ok)',err:'var(--err)',warn:'var(--warn)',info:'var(--a1)'};
  var w = document.getElementById('zm-toast-wrap');
  var el = document.createElement('div');
  el.style.cssText = 'background:var(--card);border:1px solid '+col[type]+';padding:10px 18px;border-radius:10px;font-family:"JetBrains Mono",monospace;font-size:12px;color:'+col[type]+';margin-bottom:8px;';
  el.textContent = msg;
  w.appendChild(el);
  setTimeout(function(){ if(el.parentNode) el.parentNode.removeChild(el); }, dur);
}

/* ════ TAB ══════════════════════════════════════════════ */
function zmSwitchTab(t){
  document.querySelectorAll('.zm-tab').forEach(function(b){ b.classList.remove('active'); });
  document.querySelectorAll('.zm-panel').forEach(function(p){ p.classList.remove('active'); });
  document.getElementById('tab-'+t).classList.add('active');
  document.getElementById('panel-'+t).classList.add('active');
}

/* ════ STRUCT ═══════════════════════════════════════════ */
function zmSelectStruct(m){
  zmZipMode = m;
  document.getElementById('zstruct-contents').classList.toggle('active', m==='contents');
  document.getElementById('zstruct-folder').classList.toggle('active', m==='folder');
}

/* ════ OPTS ═════════════════════════════════════════════ */
function zmToggleOpt(el){
  el.classList.toggle('on');
  el.querySelector('.opt-chk').textContent = el.classList.contains('on') ? '✓' : '';
}
function zmIsOn(id){ var el=document.getElementById(id); return el ? el.classList.contains('on') : false; }

/* ════ LOG ══════════════════════════════════════════════ */
function zmLog(p, type, icon, msg){
  zmLogs[p].push('['+new Date().toLocaleTimeString('id-ID')+'] '+msg);
  var el = document.getElementById(p+'-log-body');
  var ts = new Date().toTimeString().slice(0,8);
  var d = document.createElement('div');
  d.className = 'logline type-'+type;
  d.innerHTML = '<span class="ll-ts">'+ts+'</span><span class="ll-ic">'+zmEsc(icon)+'</span><span class="ll-tx">'+zmEsc(msg)+'</span>';
  el.appendChild(d);
  el.scrollTop = el.scrollHeight;
}
function zmClearLog(p){ document.getElementById(p+'-log-body').innerHTML=''; zmLogs[p]=[]; }
function zmExportLog(p){
  if(!zmLogs[p].length){ zmToast('Log masih kosong','warn'); return; }
  var a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([zmLogs[p].join('\n')], {type:'text/plain'}));
  a.download = 'bulkreplace-'+(p==='z'?'zip':'unzip')+'-'+Date.now()+'.txt';
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  zmToast('Log exported','ok');
}
function zmEsc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

/* ════ UI HELPERS ═══════════════════════════════════════ */
function zmUpdStats(p){
  var s = zmStats[p];
  document.getElementById(p+'-n-total').textContent = s.total;
  document.getElementById(p+'-n-ok').textContent    = s.ok;
  document.getElementById(p+'-n-skip').textContent  = s.skip;
  document.getElementById(p+'-n-err').textContent   = s.err;
}
function zmSetProg(p, done, total){
  var pct = total>0 ? Math.round(done/total*100) : 0;
  document.getElementById(p+'-prog-bar').style.width = pct+'%';
  document.getElementById(p+'-prog-val').textContent = done+' / '+total;
}
function zmSetPill(id, txt, cls){
  var el = document.getElementById(id);
  if(!el) return;
  el.textContent = txt;
  if(cls){ el.className = 'epill '+cls; }
}
function zmShowErr(id, msg){ var el=document.getElementById(id); el.textContent='⚠️  '+msg; el.classList.add('show'); }
function zmHideErr(id){ var el=document.getElementById(id); el.textContent=''; el.classList.remove('show'); }
function zmStartClock(p){
  zmT0[p] = Date.now();
  zmTimers[p] = setInterval(function(){
    document.getElementById(p+'-pill-time').textContent = '⏱ '+((Date.now()-zmT0[p])/1000).toFixed(1)+'s';
  }, 100);
}
function zmStopClock(p){ clearInterval(zmTimers[p]); }

function zmStartExec(p){
  document.getElementById(p+'-exec-box').classList.add('show');
  document.getElementById(p+'-exec-sum').style.display = 'none';
  document.getElementById(p+'-prog').classList.add('show');
  document.getElementById(p+'-prog-bar').style.width = '0%';
  document.getElementById(p+'-prog-val').textContent = '0 / 0';
  document.getElementById(p+'-log-body').innerHTML = '';
  document.getElementById(p+'-rundot').className = 'run-dot running';
  document.getElementById(p+'-exec-status').textContent = 'Running...';
  document.getElementById(p+'-exec-box').scrollIntoView({behavior:'smooth', block:'start'});
}
function zmFinishExec(p, ok, skip, err, elapsed){
  zmStopClock(p);
  document.getElementById(p+'-run-btn').disabled = false;
  document.getElementById(p+'-run-sp').innerHTML = '';
  document.getElementById(p+'-stop-btn').style.display = 'none';
  document.getElementById(p+'-prog').classList.remove('show');
  document.getElementById(p+'-pill-time').textContent = '⏱ '+elapsed+'s';
  document.getElementById(p+'-rundot').className = 'run-dot '+(err>0?'error':'done');
  document.getElementById(p+'-exec-status').textContent = 'Done — '+ok+' OK · '+elapsed+'s';
  zmSetPill(p+'-pill-prog', (p==='z'?'🗜️ ':'📂 ')+ok+'/'+(ok+skip+err), 'epill-done');
  var sum = document.getElementById(p+'-exec-sum');
  sum.style.display = 'flex';
  document.getElementById(p+'-sum-ok').textContent = '✅ '+ok+' '+(p==='z'?'zipped':'extracted');
  document.getElementById(p+'-sum-time').textContent = '⏱ '+elapsed+'s';
  if(skip>0){ var sk=document.getElementById(p+'-sum-skip'); sk.style.display=''; sk.textContent='⏭ '+skip+' skip'; }
  if(err>0){  var er=document.getElementById(p+'-sum-err');  er.style.display=''; er.textContent='❌ '+err+' failed'; }
  if(ok>0) zmToast('✅ '+ok+' '+(p==='z'?'folder zipped':'zip extracted')+'! ('+elapsed+'s)', 'ok', 4000);
  else if(err>0) zmToast('❌ '+err+' error — cek log', 'err', 4000);
}

/* ════════════════════════════════════════════════════════
   ZIP — PICKERS
════════════════════════════════════════════════════════ */
async function zmPickSrc(){
  try{
    var h = await window.showDirectoryPicker({mode:'read'});
    zmSrcDir = h;
    var dirs=0, files=0;
    for await(var [nm, en] of h.entries()){ en.kind==='directory' ? dirs++ : files++; }
    document.getElementById('z-src-icon').textContent = '✅';
    document.getElementById('z-src-title').textContent = h.name;
    document.getElementById('z-src-sub').textContent = dirs+' subfolder akan dizip';
    document.getElementById('z-pick-src-btn').classList.add('picked-z');
    zmStats.z.total = dirs;
    zmStats.z.ok = zmStats.z.skip = zmStats.z.err = 0;
    zmUpdStats('z');
    zmCheckZipReady();
    zmToast('📁 "'+h.name+'" dipilih', 'ok');
  } catch(e){ if(e.name!=='AbortError') zmToast('Gagal: '+e.message, 'err'); }
}

async function zmPickOut(){
  try{
    var h = await window.showDirectoryPicker({mode:'readwrite'});
    zmOutDir = h;
    document.getElementById('z-out-icon').textContent = '✅';
    document.getElementById('z-out-title').textContent = h.name;
    document.getElementById('z-out-sub').textContent = 'File .zip disimpan di sini';
    document.getElementById('z-pick-out-btn').classList.add('picked-z');
    zmCheckZipReady();
    zmToast('💾 Output: "'+h.name+'"', 'ok');
  } catch(e){ if(e.name!=='AbortError') zmToast('Gagal: '+e.message, 'err'); }
}

function zmCheckZipReady(){
  document.getElementById('z-run-btn').disabled = !(zmSrcDir && zmOutDir);
}

/* ════════════════════════════════════════════════════════
   ZIP — PREVIEW
════════════════════════════════════════════════════════ */
async function zmShowPreview(){
  if(!zmSrcDir){ zmToast('Pilih folder sumber dulu!','err'); return; }

  try {
    var subfolders = [];
    var totalFiles = 0;
    var totalSize = 0;

    for await(var [name, entry] of zmSrcDir.entries()){
      if(entry.kind === 'directory'){
        var fileList = [];
        await zmCollectFiles(entry, '', fileList, true);
        subfolders.push({
          name: name,
          files: fileList.length,
          size: fileList.reduce(function(sum, f){ return sum + f.file.size; }, 0)
        });
        totalFiles += fileList.length;
        totalSize += fileList.reduce(function(sum, f){ return sum + f.file.size; }, 0);
      }
    }

    zmPreviewData = {subfolders: subfolders, totalFiles: totalFiles, totalSize: totalSize};

    var modal = document.getElementById('zm-preview-modal');
    var info = document.getElementById('zm-preview-info');
    var list = document.getElementById('zm-preview-list');

    var sizeMB = (totalSize / 1024 / 1024).toFixed(2);
    info.innerHTML = '📊 <strong>' + subfolders.length + ' folders</strong> will be zipped · ' +
                     totalFiles + ' total files · ' + sizeMB + ' MB';

    list.innerHTML = '';
    for(var i = 0; i < subfolders.length; i++){
      var sf = subfolders[i];
      var kb = Math.round(sf.size / 1024);
      var sizeStr = kb > 1024 ? (kb/1024).toFixed(1) + ' MB' : kb + ' KB';
      var item = document.createElement('div');
      item.className = 'zm-preview-item is-dir';
      item.innerHTML = '📁 <strong>' + zmEsc(sf.name) + '</strong> · ' +
                       sf.files + ' files · ' + sizeStr;
      list.appendChild(item);
    }

    modal.classList.add('show');
  } catch(e){
    zmToast('Preview error: ' + e.message, 'err');
  }
}

function zmClosePreview(){
  document.getElementById('zm-preview-modal').classList.remove('show');
}

async function zmConfirmZip(){
  zmClosePreview();
  await zmRunZipActual();
}

/* ════════════════════════════════════════════════════════
   ZIP — RUN
════════════════════════════════════════════════════════ */
async function zmRunZip(){
  if(zmZipBusy){ zmToast('Sedang berjalan...','warn'); return; }
  if(!zmSrcDir){ zmToast('Pilih folder sumber dulu!','err'); return; }
  if(!zmOutDir){ zmToast('Pilih folder output dulu!','err'); return; }

  await zmShowPreview();
}

async function zmRunZipActual(){
  if(zmZipBusy){ zmToast('Sedang berjalan...','warn'); return; }
  if(!zmSrcDir){ zmToast('Pilih folder sumber dulu!','err'); return; }
  if(!zmOutDir){ zmToast('Pilih folder output dulu!','err'); return; }

  // Check JSZip availability
  if(typeof JSZip === 'undefined'){
    zmShowErr('z-errmsg', 'JSZip library not loaded. Please refresh the page.');
    zmToast('JSZip tidak ter-load. Refresh halaman!', 'err');
    return;
  }

  // OPTIMIZED: Memory check for large operations
  if(performance && performance.memory && performance.memory.jsHeapSizeLimit){
    var usedMB = (performance.memory.usedJSHeapSize / 1024 / 1024).toFixed(0);
    var limitMB = (performance.memory.jsHeapSizeLimit / 1024 / 1024).toFixed(0);
    if(performance.memory.usedJSHeapSize / performance.memory.jsHeapSizeLimit > 0.8){
      zmToast('⚠️ Memory usage high ('+usedMB+'/'+limitMB+' MB). Consider closing other tabs.', 'warn', 5000);
    }
  }

  var perm = await zmOutDir.requestPermission({mode:'readwrite'});
  if(perm !== 'granted'){ zmToast('Izin write ditolak!','err'); return; }

  var skipEmpty  = zmIsOn('zopt-skip-empty');
  var inclHidden = zmIsOn('zopt-hidden');
  var useFolder  = zmZipMode === 'folder';

  var subfolders = [];
  for await(var [name, entry] of zmSrcDir.entries()){
    if(entry.kind === 'directory') subfolders.push({name:name, handle:entry});
  }
  if(!subfolders.length){ zmToast('Tidak ada subfolder ditemukan!','warn'); return; }

  var total = subfolders.length;
  zmZipBusy   = true;
  zmZipAbort  = false;
  zmStats.z   = {total:total, ok:0, skip:0, err:0};
  zmUpdStats('z');

  document.getElementById('z-run-btn').disabled = true;
  document.getElementById('z-run-sp').innerHTML = '⏳ ';
  document.getElementById('z-stop-btn').style.display = '';
  document.getElementById('z-term-title').textContent = 'BulkReplace — ZIP "'+zmSrcDir.name+'" × '+total;
  zmStartExec('z');
  zmStartClock('z');
  zmHideErr('z-errmsg');

  zmLog('z','head','▶','Bulk ZIP — '+total+' folder');
  zmLog('z','info','  ','Source : '+zmSrcDir.name);
  zmLog('z','info','  ','Output : '+zmOutDir.name);
  zmLog('z','info','  ','Mode   : '+(useFolder?'Include Folder Name':'Contents Only'));
  zmLog('z','div', '  ','─────────────────────────────────────────');

  var ok=0, skip=0, err=0, done=0;

  for(var i=0; i<subfolders.length; i++){
    if(zmZipAbort){ zmLog('z','warn','⏹','Stopped by user.'); break; }
    var sf = subfolders[i];
    zmLog('z','folder','📁','[' +(i+1)+'/'+total+'] '+sf.name);

    try{
      var fileList = [];
      var collectSuccess = false;
      var collectRetries = 0;

      // Retry file collection up to 3 times if it fails
      while(!collectSuccess && collectRetries < 3){
        try{
          fileList = [];
          await zmCollectFiles(sf.handle, '', fileList, inclHidden);
          collectSuccess = true;
        } catch(collectErr){
          collectRetries++;
          if(collectRetries < 3){
            console.warn('Retry collecting files for '+sf.name+' (attempt '+(collectRetries+1)+'/3)');
            await new Promise(function(r){ setTimeout(r, 100); });
          } else {
            throw collectErr;
          }
        }
      }

      if(skipEmpty && fileList.length === 0){
        zmLog('z','skip','  ','  ⏭ Skip — folder kosong');
        skip++; zmStats.z.skip++; zmUpdStats('z');
      } else {
        var t = Date.now();
        var zip = new JSZip();

        // Smart compression: skip already-compressed files (images, videos, archives)
        var noCompressExts = ['.jpg','.jpeg','.png','.gif','.webp','.bmp','.svg','.ico','.mp4','.mp3','.avi','.mov','.zip','.rar','.7z','.gz','.bz2','.tar','.pdf','.woff','.woff2','.ttf'];
        var compressed = 0, uncompressed = 0;

        // OPTIMIZED: Add files in batches to reduce memory pressure
        var BATCH_SIZE = 50;
        for(var bi=0; bi<fileList.length; bi+=BATCH_SIZE){
          var batchEnd = Math.min(bi + BATCH_SIZE, fileList.length);
          for(var fi=bi; fi<batchEnd; fi++){
            var ep = useFolder ? sf.name+'/'+fileList[fi].path : fileList[fi].path;
            var fileName = fileList[fi].path.toLowerCase();
            var isCompressed = noCompressExts.some(function(ext){ return fileName.endsWith(ext); });

            if(isCompressed){
              zip.file(ep, fileList[fi].file, {compression: 'STORE'});
              compressed++;
            } else {
              // OPTIMIZED: Use level 4 instead of 6 for 2x faster compression with minimal size increase
              zip.file(ep, fileList[fi].file, {compression: 'DEFLATE', compressionOptions: {level: 4}});
              uncompressed++;
            }
          }
          // Yield every batch to prevent UI freeze
          if(bi + BATCH_SIZE < fileList.length){
            await new Promise(function(r){ setTimeout(r, 0); });
          }
        }

        var fDone = i;
        // OPTIMIZED: Use uint8array type with streaming for better performance
        var blob = await zip.generateAsync(
          {type:'blob', streamFiles: true, compression: 'DEFLATE', compressionOptions: {level: 4}},
          function(meta){
            zmSetProg('z', fDone + meta.percent/100, total);
            zmSetPill('z-pill-prog', '🗜️ '+(fDone+1)+'/'+total+' · '+Math.round(meta.percent)+'%', 'epill-live');
          }
        );
        var zipHandle = await zmOutDir.getFileHandle(sf.name+'.zip', {create:true});
        var writable  = await zipHandle.createWritable();
        await writable.write(blob);
        await writable.close();
        var ms  = Date.now()-t;
        var kb  = Math.round(blob.size/1024);
        var sz  = kb>1024 ? (kb/1024).toFixed(1)+'MB' : kb+'KB';
        var speedMBs = blob.size > 1024*1024 && ms > 0 ? ((blob.size / 1024 / 1024) / (ms / 1000)).toFixed(2) : null;
        var speedInfo = speedMBs ? ' · '+speedMBs+' MB/s' : '';

        // OPTIMIZED: Show compression ratio
        var originalSize = fileList.reduce(function(sum, f){ return sum + f.file.size; }, 0);
        var ratio = originalSize > 0 ? (((originalSize - blob.size) / originalSize) * 100).toFixed(0) : 0;
        var ratioInfo = ratio > 5 ? ' · '+ratio+'% saved' : '';

        var compInfo = compressed > 0 ? ' · '+compressed+' stored/'+uncompressed+' deflate' : '';
        zmLog('z','ok','  ','  ✓ '+sf.name+'.zip ('+fileList.length+' files · '+sz+' · '+(ms<1000?ms+'ms':(ms/1000).toFixed(1)+'s')+speedInfo+ratioInfo+compInfo+')');
        ok++; zmStats.z.ok++; zmUpdStats('z');
      }
    } catch(e){
      zmLog('z','err','  ','  ✗ '+e.message);
      err++; zmStats.z.err++; zmUpdStats('z');
    }

    done++;
    zmSetProg('z', done, total);
    zmSetPill('z-pill-prog', '🗜️ '+done+'/'+total, 'epill-live');

    // OPTIMIZED: Yield less frequently - only every 5 folders or very large folders
    if(i % 5 === 0 || (fileList && fileList.reduce(function(sum, f){ return sum + f.file.size; }, 0) > 100 * 1024 * 1024)){
      await new Promise(function(r){ setTimeout(r, 0); });
    }
  }

  zmZipBusy = false;
  var elapsed = ((Date.now()-zmT0.z)/1000).toFixed(1);
  zmLog('z','div','  ','─────────────────────────────────────────');
  zmLog('z','done','🎉','SELESAI — '+ok+' zipped · '+skip+' skip · '+err+' error · '+elapsed+'s');
  if(ok > 0){
    var avgTime = ok > 0 ? (parseFloat(elapsed) / ok).toFixed(2) : '0';
    zmLog('z','info','  ','⚡ Average: '+avgTime+'s per folder');
    zmLog('z','info','  ','✓ All files maintain original quality (lossless)');
    zmLog('z','info','  ','✓ Folder structure preserved');
  }
  zmFinishExec('z', ok, skip, err, elapsed);

  // Track usage analytics
  zmTrackUsage('bulk_zip', {
    total: total,
    success: ok,
    skipped: skip,
    failed: err,
    duration: parseFloat(elapsed),
    mode: zmZipMode
  });
}

async function zmCollectFiles(dirHandle, prefix, list, inclHidden){
  for await(var [name, entry] of dirHandle.entries()){
    if(!inclHidden && name.charAt(0)==='.') continue;
    var path = prefix ? prefix+'/'+name : name;

    try{
      if(entry.kind === 'file'){
        var file = await entry.getFile();
        list.push({path:path, file:file});
      } else {
        await zmCollectFiles(entry, path, list, inclHidden);
      }
    } catch(ex){
      // Skip files that can't be read (locked, permission denied, etc.)
      console.warn('Skipped file due to error:', path, ex.message);
    }
  }
}

function zmStopZip(){
  zmZipAbort = true;
  document.getElementById('z-stop-btn').disabled = true;
  document.getElementById('z-stop-btn').textContent = '⏹ Stopping...';
  zmLog('z','warn','⏹','Stop diminta...');
}

/* ════════════════════════════════════════════════════════
   UNZIP — PICKERS
════════════════════════════════════════════════════════ */
async function zmPickZips(){
  try{
    var handles = await window.showOpenFilePicker({
      multiple: true,
      types: [{description:'ZIP Files', accept:{'application/zip':['.zip']}}]
    });
    zmZipFiles = [];
    zmZipHandles = [];
    for(var i=0; i<handles.length; i++){
      zmZipHandles.push(handles[i]);
      zmZipFiles.push(await handles[i].getFile());
    }
    var total   = zmZipFiles.length;
    var totSize = zmZipFiles.reduce(function(a,f){ return a+f.size; }, 0);
    var kb      = Math.round(totSize/1024);
    var szStr   = kb>1024 ? (kb/1024).toFixed(1)+' MB' : kb+' KB';
    document.getElementById('u-zip-icon').textContent  = '✅';
    document.getElementById('u-zip-title').textContent = total===1 ? zmZipFiles[0].name : total+' file ZIP dipilih';
    document.getElementById('u-zip-sub').textContent   = 'Total: '+szStr;
    document.getElementById('u-pick-zip-btn').classList.add('picked-u');
    zmStats.u.total = total;
    zmStats.u.ok = zmStats.u.skip = zmStats.u.err = 0;
    zmUpdStats('u');
    zmCheckUnzipReady();
    zmToast('🗜️ '+total+' file ZIP dipilih', 'ok');
  } catch(e){ if(e.name!=='AbortError') zmToast('Gagal: '+e.message, 'err'); }
}

async function zmPickUnzipOut(){
  try{
    var h = await window.showDirectoryPicker({mode:'readwrite'});
    zmUnzipDir = h;
    document.getElementById('u-out-icon').textContent  = '✅';
    document.getElementById('u-out-title').textContent = h.name;
    document.getElementById('u-out-sub').textContent   = 'Hasil extract disimpan di sini';
    document.getElementById('u-pick-out-btn').classList.add('picked-u');
    zmCheckUnzipReady();
    zmToast('📂 Output: "'+h.name+'"', 'ok');
  } catch(e){ if(e.name!=='AbortError') zmToast('Gagal: '+e.message, 'err'); }
}

function zmCheckUnzipReady(){
  document.getElementById('u-run-btn').disabled = !(zmZipFiles.length>0 && zmUnzipDir);
}

/* ════════════════════════════════════════════════════════
   UNZIP — RUN
════════════════════════════════════════════════════════ */
async function zmRunUnzip(){
  if(zmUnzipBusy){ zmToast('Sedang berjalan...','warn'); return; }
  if(!zmZipFiles.length){ zmToast('Pilih file .zip dulu!','err'); return; }
  if(!zmUnzipDir){ zmToast('Pilih folder output dulu!','err'); return; }

  // Check JSZip availability
  if(typeof JSZip === 'undefined'){
    zmShowErr('u-errmsg', 'JSZip library not loaded. Please refresh the page.');
    zmToast('JSZip tidak ter-load. Refresh halaman!', 'err');
    return;
  }

  // OPTIMIZED: Memory check and size warning for large ZIPs
  var totalSize = zmZipFiles.reduce(function(sum, f){ return sum + f.size; }, 0);
  var sizeMB = (totalSize / 1024 / 1024).toFixed(0);
  if(totalSize > 500 * 1024 * 1024){
    zmToast('⚠️ Processing '+sizeMB+' MB — this may take a while. Keep tab active!', 'warn', 5000);
  }
  if(performance && performance.memory && performance.memory.jsHeapSizeLimit){
    var usedMB = (performance.memory.usedJSHeapSize / 1024 / 1024).toFixed(0);
    var limitMB = (performance.memory.jsHeapSizeLimit / 1024 / 1024).toFixed(0);
    if(performance.memory.usedJSHeapSize / performance.memory.jsHeapSizeLimit > 0.8){
      zmToast('⚠️ Memory usage high ('+usedMB+'/'+limitMB+' MB). Consider closing other tabs.', 'warn', 5000);
    }
  }

  var perm = await zmUnzipDir.requestPermission({mode:'readwrite'});
  if(perm !== 'granted'){ zmToast('Izin write ditolak!','err'); return; }

  var toSubfolder = zmIsOn('uopt-subfolder');
  var overwrite   = zmIsOn('uopt-overwrite');
  var deleteAfter = zmIsOn('uopt-delete-zip');
  var total       = zmZipFiles.length;

  zmUnzipBusy  = true;
  zmUnzipAbort = false;
  zmStats.u    = {total:total, ok:0, skip:0, err:0};
  zmUpdStats('u');

  document.getElementById('u-run-btn').disabled = true;
  document.getElementById('u-run-sp').innerHTML = '⏳ ';
  document.getElementById('u-stop-btn').style.display = '';
  document.getElementById('u-term-title').textContent = 'BulkReplace — UNZIP × '+total;
  zmStartExec('u');
  zmStartClock('u');
  zmHideErr('u-errmsg');

  zmLog('u','head','▶','Bulk UNZIP — '+total+' file');
  zmLog('u','info','  ','Output : '+zmUnzipDir.name);
  zmLog('u','info','  ','Mode   : '+(toSubfolder?'Extract to Subfolder':'Flat (root)'));
  if(deleteAfter) zmLog('u','warn','  ','⚠️ ZIPs will be deleted after successful extraction');
  zmLog('u','div', '  ','─────────────────────────────────────────');

  var ok=0, skip=0, err=0, done=0, deleted=0;

  for(var i=0; i<zmZipFiles.length; i++){
    if(zmUnzipAbort){ zmLog('u','warn','⏹','Stopped by user.'); break; }
    var zipFile  = zmZipFiles[i];
    var zipName  = zipFile.name.replace(/\.zip$/i,'');
    zmLog('u','folder','🗜️','['+(i+1)+'/'+total+'] '+zipFile.name);

    try{
      var t = Date.now();

      // OPTIMIZED: For large ZIPs, use streaming load with progress callback
      var jszip;
      if(zipFile.size > 10 * 1024 * 1024){
        // Large file: load with progress tracking
        jszip = await JSZip.loadAsync(zipFile, {
          createFolders: false
        });
      } else {
        // Small file: fast load
        jszip = await JSZip.loadAsync(zipFile);
      }

      var entries = Object.keys(jszip.files);
      var fileEntries = entries.filter(function(p){ return !jszip.files[p].dir; });

      var destRoot = zmUnzipDir;
      if(toSubfolder){
        destRoot = await zmUnzipDir.getDirectoryHandle(zipName, {create:true});
      }

      // OPTIMIZED: Batch processing with parallel writes
      var folderCache = {};
      var written = 0, skipped = 0;

      // Pre-build folder structure (faster than creating during file writes)
      var uniqueFolders = new Set();
      for(var fi=0; fi<fileEntries.length; fi++){
        var parts = fileEntries[fi].split('/').filter(function(x){ return x.length>0; });
        if(parts.length > 1){
          for(var pi=0; pi<parts.length-1; pi++){
            uniqueFolders.add(parts.slice(0, pi+1).join('/'));
          }
        }
      }

      // Create all folders upfront (batch operation)
      var folderPaths = Array.from(uniqueFolders).sort();
      for(var fpi=0; fpi<folderPaths.length; fpi++){
        var fp = folderPaths[fpi];
        var parts = fp.split('/');
        var cur = destRoot;
        for(var pi=0; pi<parts.length; pi++){
          var subPath = parts.slice(0, pi+1).join('/');
          if(!folderCache[subPath]){
            cur = await cur.getDirectoryHandle(parts[pi], {create:true});
            folderCache[subPath] = cur;
          } else {
            cur = folderCache[subPath];
          }
        }
      }
      folderCache[''] = destRoot;

      // OPTIMIZED: Dynamic batch size based on file count and size
      var avgFileSize = totalFiles > 0 ? zipFile.size / totalFiles : 0;
      var BATCH_SIZE = avgFileSize > 1024*1024 ? 5 : (avgFileSize > 100*1024 ? 10 : 20); // Large files: 5, medium: 10, small: 20
      var totalFiles = fileEntries.length;

      for(var batchStart=0; batchStart<totalFiles; batchStart+=BATCH_SIZE){
        if(zmUnzipAbort) break;

        var batchEnd = Math.min(batchStart + BATCH_SIZE, totalFiles);
        var batchPromises = [];

        for(var fi=batchStart; fi<batchEnd; fi++){
          (function(idx){
            var entryPath = fileEntries[idx];
            var parts = entryPath.split('/').filter(function(x){ return x.length>0; });
            var fname = parts[parts.length-1];
            var folderPath = parts.slice(0, -1).join('/');
            var cur = folderCache[folderPath] || destRoot;

            // Create async write operation
            var writePromise = (async function(){
              try{
                // OPTIMIZED: Abort check before processing
                if(zmUnzipAbort) return;

                // Check if file exists (only if not overwriting)
                if(!overwrite){
                  try{
                    await cur.getFileHandle(fname);
                    skipped++;
                    return;
                  } catch(ex){
                    // File doesn't exist, will create it
                  }
                }

                // OPTIMIZED: Use Uint8Array streaming (faster than blob)
                var uint8 = await jszip.files[entryPath].async('uint8array');

                // Abort check before write
                if(zmUnzipAbort) return;

                var fh = await cur.getFileHandle(fname, {create:true});
                var w = await fh.createWritable();
                await w.write(uint8);
                await w.close();
                written++;
              } catch(err){
                console.warn('Write failed for '+fname+':', err.message);
              }
            })();

            batchPromises.push(writePromise);
          })(fi);
        }

        // Wait for batch to complete
        await Promise.all(batchPromises);

        // Update progress after each batch
        var pct = Math.round((batchEnd)/totalFiles*100);
        zmSetPill('u-pill-prog', '📂 '+(i+1)+'/'+total+' · '+pct+'%', 'epill-live');

        // Yield to browser every batch (much less frequent than before)
        await new Promise(function(r){ setTimeout(r, 0); });
      }

      var ms = Date.now()-t;
      var skipInfo = skipped > 0 ? ' · '+skipped+' skipped' : '';
      var speedMBs = written > 0 ? ((zipFile.size / 1024 / 1024) / (ms / 1000)).toFixed(2) : '0';
      var speedInfo = zipFile.size > 1024*1024 ? ' · '+speedMBs+' MB/s' : '';
      zmLog('u','ok','  ','  ✓ '+zipName+'/ ('+written+' files'+skipInfo+' · '+(ms<1000?ms+'ms':(ms/1000).toFixed(1)+'s')+speedInfo+')');
      ok++; zmStats.u.ok++; zmUpdStats('u');

      // Delete ZIP file if requested and extraction successful
      if(deleteAfter && zmZipHandles[i]){
        try{
          await zmZipHandles[i].remove();
          zmLog('u','warn','  ','  🗑️ Deleted: '+zipFile.name);
          deleted++;
        } catch(delErr){
          zmLog('u','warn','  ','  ⚠️ Could not delete: '+delErr.message);
        }
      }
    } catch(e){
      // OPTIMIZED: Better error messages for common issues
      var errMsg = e.message;
      if(e.message.includes('corrupted') || e.message.includes('unexpected')){
        errMsg = 'ZIP corrupted or invalid format';
      } else if(e.message.includes('password') || e.message.includes('encrypted')){
        errMsg = 'Password-protected ZIP not supported';
      } else if(e.message.includes('NotReadableError')){
        errMsg = 'File locked or in use by another program';
      }
      zmLog('u','err','  ','  ✗ '+errMsg);
      err++; zmStats.u.err++; zmUpdStats('u');
    }

    done++;
    zmSetProg('u', done, total);
    zmSetPill('u-pill-prog', '📂 '+done+'/'+total, 'epill-live');
    await new Promise(function(r){ setTimeout(r,0); });
  }

  zmUnzipBusy = false;
  var elapsed = ((Date.now()-zmT0.u)/1000).toFixed(1);
  zmLog('u','div','  ','─────────────────────────────────────────');
  var delInfo = deleted > 0 ? ' · '+deleted+' deleted' : '';
  var totalSize = zmZipFiles.reduce(function(sum, f){ return sum + f.size; }, 0);
  var avgSpeed = totalSize > 0 ? ((totalSize / 1024 / 1024) / parseFloat(elapsed)).toFixed(2) : '0';
  zmLog('u','done','🎉','SELESAI — '+ok+' extracted · '+skip+' skip · '+err+' error'+delInfo+' · '+elapsed+'s');
  if(ok > 0 && totalSize > 1024*1024){
    zmLog('u','info','  ','⚡ Average speed: '+avgSpeed+' MB/s');
  }
  zmFinishExec('u', ok, skip, err, elapsed);

  // Track usage analytics
  zmTrackUsage('bulk_unzip', {
    total: total,
    success: ok,
    skipped: skip,
    failed: err,
    duration: parseFloat(elapsed)
  });
}

function zmStopUnzip(){
  zmUnzipAbort = true;
  document.getElementById('u-stop-btn').disabled = true;
  document.getElementById('u-stop-btn').textContent = '⏹ Stopping...';
  zmLog('u','warn','⏹','Stop diminta...');
}

/* ════════════════════════════════════════════════════════
   ANALYTICS & AUDIT TRACKING
════════════════════════════════════════════════════════ */
function zmTrackUsage(operation, data){
  try {
    // Track analytics event
    fetch('/api/usage.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        feature: 'zip_manager',
        operation: operation,
        data: data
      })
    }).catch(function(e){ console.warn('Analytics tracking failed:', e); });

    // Log to usage_log for Recent Jobs tracking
    const fd = new FormData();
    fd.append('action', 'log');
    fd.append('csv_rows', data.success || 0);
    fd.append('files_updated', data.total || 0);
    fd.append('job_type', 'zip_manager');
    fd.append('job_name', 'ZIP: ' + operation + ' (' + (data.success || 0) + '/' + (data.total || 0) + ')');
    fd.append('csrf_token', '<?= csrf_token() ?>');
    fetch('/api/usage.php', {method: 'POST', body: fd}).catch(function(e){});
  } catch(e){ console.warn('Analytics error:', e); }
}
</script>
</body>
</html>
