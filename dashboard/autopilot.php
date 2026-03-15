<?php
require_once dirname(__DIR__).'/config.php';
requireLogin();

// Force create autopilot tables if not exist
try {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS autopilot_jobs (
        id VARCHAR(36) PRIMARY KEY,
        user_id INT NOT NULL,
        total_domains INT NOT NULL DEFAULT 0,
        processed_domains INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        keyword_hint TEXT,
        user_hints TEXT,
        result_data JSON,
        error_log JSON,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        INDEX idx_user_id (user_id),
        INDEX idx_status (status),
        INDEX idx_created_at (created_at DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS autopilot_queue (
        id VARCHAR(36) PRIMARY KEY,
        job_id VARCHAR(36) NOT NULL,
        domain VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        result_data JSON,
        error_message TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL,
        INDEX idx_job_id (job_id),
        INDEX idx_status (status),
        INDEX idx_job_status (job_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    error_log("Autopilot tables creation: " . $e->getMessage());
}

$u    = currentUser();
$plan = $u['plan'] ?? 'free';
if ($plan !== 'lifetime') {
    header('Location: /landing/pricing.php?plan=lifetime&ref=autopilot'); exit;
}
if (!hasAddonAccess((int)$u['id'], 'autopilot')) {
    header('Location: /dashboard/addons.php?ref=autopilot'); exit;
}
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Autopilot — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>
/* ── Autopilot Design System ─────────────────────────────── */
:root{
  --ap-gold:   #f0a500;
  --ap-purple: #c084fc;
  --ap-teal:   #00d4aa;
  --ap-ok:     #00e676;
  --ap-err:    #ff4560;
  --ap-warn:   #fbbf24;
  --ap-grad:   linear-gradient(135deg,#f0a500,#c084fc);
}

/* Header */
.ap-header{
  background:linear-gradient(135deg,rgba(240,165,0,.07) 0%,rgba(192,132,252,.07) 100%);
  border:1px solid rgba(240,165,0,.18);
  border-radius:16px;padding:24px 28px;margin-bottom:24px;
  position:relative;overflow:hidden;
}
.ap-header::before{
  content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:var(--ap-grad);
}
.ap-title{
  font-family:'Syne',sans-serif;font-size:26px;font-weight:900;
  background:var(--ap-grad);-webkit-background-clip:text;-webkit-text-fill-color:transparent;
  margin-bottom:4px;
}
.ap-subtitle{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);}

/* Pipeline stepper */
.ap-pipeline{
  display:flex;align-items:center;gap:0;
  background:var(--dim);border:1px solid var(--border);border-radius:12px;
  padding:16px 20px;margin-bottom:24px;overflow-x:auto;
}
.ap-step{
  display:flex;flex-direction:column;align-items:center;gap:6px;
  min-width:80px;text-align:center;position:relative;
}
.ap-step-icon{
  width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;
  font-size:16px;background:rgba(255,255,255,.05);border:1px solid var(--border);
  transition:all .3s;position:relative;z-index:1;
}
.ap-step.active .ap-step-icon{
  background:linear-gradient(135deg,rgba(240,165,0,.2),rgba(192,132,252,.2));
  border-color:rgba(240,165,0,.5);box-shadow:0 0 16px rgba(240,165,0,.2);
}
.ap-step.done .ap-step-icon{
  background:rgba(0,230,118,.12);border-color:rgba(0,230,118,.4);
}
.ap-step.done .ap-step-icon::after{
  content:'✓';position:absolute;bottom:-3px;right:-3px;
  width:14px;height:14px;background:var(--ap-ok);border-radius:50%;
  font-size:8px;display:flex;align-items:center;justify-content:center;
  color:#000;font-weight:700;line-height:14px;
}
.ap-step-label{font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);letter-spacing:.3px;}
.ap-step.active .ap-step-label{color:var(--ap-gold);}
.ap-step.done .ap-step-label{color:var(--ap-ok);}
.ap-step-arrow{flex:1;height:1px;background:var(--border);min-width:20px;margin-top:-20px;}
.ap-step-arrow.done{background:rgba(0,230,118,.3);}

/* Section cards */
.ap-section{
  background:var(--card);border:1px solid var(--border);
  border-radius:16px;padding:24px;margin-bottom:16px;
  transition:border-color .2s;
}
.ap-section.active{border-color:rgba(240,165,0,.3);}
.ap-section.done{border-color:rgba(0,230,118,.2);}
.ap-section.locked{opacity:.45;pointer-events:none;}
.ap-section-head{
  display:flex;align-items:center;gap:12px;margin-bottom:18px;
}
.ap-section-num{
  width:28px;height:28px;border-radius:8px;
  background:rgba(255,255,255,.05);border:1px solid var(--border);
  font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:700;
  display:flex;align-items:center;justify-content:center;color:var(--muted);
  flex-shrink:0;
}
.ap-section.active .ap-section-num{
  background:linear-gradient(135deg,rgba(240,165,0,.2),rgba(192,132,252,.15));
  border-color:rgba(240,165,0,.4);color:var(--ap-gold);
}
.ap-section.done .ap-section-num{
  background:rgba(0,230,118,.1);border-color:rgba(0,230,118,.3);color:var(--ap-ok);
}
.ap-section-title{
  font-family:'Syne',sans-serif;font-size:16px;font-weight:700;color:#fff;
}
.ap-section-sub{
  font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);
  margin-left:auto;
}

/* Drop zone */
.ap-dropzone{
  border:2px dashed var(--border);border-radius:12px;padding:32px;
  text-align:center;cursor:pointer;transition:all .2s;
  background:rgba(255,255,255,.01);
}
.ap-dropzone:hover,.ap-dropzone.drag{
  border-color:rgba(240,165,0,.5);background:rgba(240,165,0,.03);
}
.ap-dropzone-icon{font-size:36px;margin-bottom:10px;}
.ap-dropzone-title{
  font-family:'Syne',sans-serif;font-size:15px;font-weight:700;color:#fff;margin-bottom:4px;
}
.ap-dropzone-hint{
  font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);
}

/* Folder picked state */
.ap-folder-picked{
  display:flex;align-items:center;gap:12px;
  background:rgba(0,230,118,.05);border:1px solid rgba(0,230,118,.2);
  border-radius:10px;padding:14px 16px;
}
.ap-folder-icon{font-size:24px;}
.ap-folder-info{flex:1;}
.ap-folder-name{
  font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;
}
.ap-folder-meta{
  font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:2px;
}

/* Domain textarea */
.ap-domain-wrap{position:relative;}
.ap-domain-ta{
  width:100%;min-height:140px;background:var(--dim);
  border:1px solid var(--border);border-radius:10px;
  padding:14px;font-family:'JetBrains Mono',monospace;font-size:12px;
  color:#fff;resize:vertical;outline:none;transition:border-color .2s;
  box-sizing:border-box;line-height:1.8;
}
.ap-domain-ta:focus{border-color:rgba(240,165,0,.4);}
.ap-domain-counter{
  position:absolute;bottom:10px;right:14px;
  font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);
}

/* Hint System */
.ap-hint-wrap{
  margin-top:16px;background:rgba(96,165,250,.04);
  border:1px solid rgba(96,165,250,.2);border-radius:10px;
  overflow:hidden;transition:all .3s;
}
.ap-hint-header{
  display:flex;align-items:center;gap:10px;padding:12px 16px;
  cursor:pointer;user-select:none;
}
.ap-hint-header:hover{background:rgba(96,165,250,.06);}
.ap-hint-icon{font-size:18px;}
.ap-hint-title{
  font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:#60a5fa;
  flex:1;
}
.ap-hint-badge{
  font-family:'JetBrains Mono',monospace;font-size:9px;
  background:rgba(96,165,250,.15);border:1px solid rgba(96,165,250,.3);
  color:#60a5fa;padding:3px 8px;border-radius:5px;
  letter-spacing:0.5px;
}
.ap-hint-toggle{
  background:none;border:none;color:#60a5fa;cursor:pointer;
  font-family:'JetBrains Mono',monospace;font-size:11px;
  display:flex;align-items:center;gap:6px;padding:4px 8px;
  border-radius:5px;transition:background .2s;
}
.ap-hint-toggle:hover{background:rgba(96,165,250,.1);}
.ap-hint-content{
  padding:0 16px 16px 16px;
}
.ap-hint-description{
  font-family:'JetBrains Mono',monospace;font-size:11px;
  color:rgba(255,255,255,.6);line-height:1.7;
  margin-bottom:12px;padding:10px;
  background:rgba(0,0,0,.2);border-radius:8px;
}
.ap-hint-description code{
  color:#60a5fa;background:rgba(96,165,250,.1);
  padding:2px 6px;border-radius:4px;font-size:10px;
}
.ap-hint-ta{
  width:100%;min-height:160px;background:rgba(0,0,0,.3);
  border:1px solid rgba(96,165,250,.25);border-radius:8px;
  padding:12px;font-family:'JetBrains Mono',monospace;font-size:11px;
  color:#fff;resize:vertical;outline:none;
  transition:border-color .2s;box-sizing:border-box;line-height:1.7;
}
.ap-hint-ta:focus{border-color:rgba(96,165,250,.5);}
.ap-hint-ta::placeholder{color:rgba(255,255,255,.25);}
.ap-hint-stats{
  display:flex;align-items:center;gap:12px;margin-top:8px;
  font-family:'JetBrains Mono',monospace;font-size:10px;
  color:rgba(255,255,255,.4);
}
.ap-hint-tip{color:#60a5fa;}

/* Bot Detection Results */
.ap-detect-grid{
  display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;
}
@media(max-width:640px){.ap-detect-grid{grid-template-columns:1fr;}}

.ap-detect-row{
  background:var(--dim);border:1px solid var(--border);border-radius:10px;
  padding:12px 14px;display:flex;flex-direction:column;gap:6px;
}
.ap-detect-row.confirmed{border-color:rgba(0,230,118,.3);background:rgba(0,230,118,.04);}
.ap-detect-row.undetected{border-color:rgba(251,191,36,.3);background:rgba(251,191,36,.04);}
.ap-detect-header{
  display:flex;align-items:center;justify-content:space-between;gap:8px;
}
.ap-detect-field{
  font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;
  letter-spacing:1px;text-transform:uppercase;padding:2px 8px;border-radius:5px;
}
.df-namalink{background:rgba(240,165,0,.12);color:var(--ap-gold);}
.df-daerah{background:rgba(0,212,170,.12);color:var(--ap-teal);}
.df-email{background:rgba(96,165,250,.12);color:#60a5fa;}
.df-alamat{background:rgba(251,146,60,.12);color:#fb923c;}
.df-embedmap{background:rgba(167,139,250,.12);color:#a78bfa;}
.df-linkmaps{background:rgba(244,114,182,.12);color:#f472b4;}
.df-provinsi{background:rgba(52,211,153,.12);color:#34d399;}
.df-other{background:rgba(255,255,255,.06);color:var(--muted);}
.ap-detect-conf{
  font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);
}
.ap-detect-strings{
  display:flex;flex-wrap:wrap;gap:4px;margin-top:2px;
}
.ap-detect-str{
  font-family:'JetBrains Mono',monospace;font-size:10px;
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);
  border-radius:5px;padding:2px 7px;color:var(--text);max-width:200px;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}

/* Manual check */
.ap-manual-wrap{
  background:rgba(251,191,36,.04);border:1px solid rgba(251,191,36,.2);
  border-radius:10px;padding:16px;
}
.ap-manual-title{
  font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;
  color:var(--ap-warn);letter-spacing:1px;margin-bottom:10px;
}
.ap-manual-input-row{
  display:flex;gap:8px;margin-bottom:10px;
}
.ap-manual-inp{
  flex:1;background:var(--dim);border:1px solid var(--border);border-radius:8px;
  padding:9px 12px;font-family:'JetBrains Mono',monospace;font-size:12px;
  color:#fff;outline:none;transition:border-color .2s;
}
.ap-manual-inp:focus{border-color:rgba(251,191,36,.5);}
.ap-manual-result{
  display:flex;flex-direction:column;gap:6px;
}
.ap-manual-hit{
  display:flex;align-items:center;gap:10px;
  background:var(--dim);border:1px solid var(--border);border-radius:8px;
  padding:10px 12px;
}
.ap-manual-hit-str{
  flex:1;font-family:'JetBrains Mono',monospace;font-size:11px;color:#fff;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.ap-manual-hit-files{
  font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);
  white-space:nowrap;
}
.ap-manual-assign{
  background:var(--dim);border:1px solid var(--border);border-radius:6px;
  padding:5px 8px;font-family:'JetBrains Mono',monospace;font-size:10px;
  color:#fff;outline:none;cursor:pointer;
}

/* Progress / log */
.ap-log{
  background:#0a0a0f;border:1px solid var(--border);border-radius:10px;
  padding:14px;height:420px;overflow-y:auto;
  font-family:'JetBrains Mono',monospace;font-size:11px;line-height:1.8;
}
.ap-log-line{display:flex;gap:6px;padding:2px 0;align-items:baseline;}
.ap-log-ts{color:rgba(255,255,255,.18);flex-shrink:0;width:60px;font-size:10px;}
.ap-log-icon{flex-shrink:0;width:18px;font-size:11px;line-height:1.8;}
.ap-log-extra{color:rgba(255,255,255,.35);font-size:10px;margin-left:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:260px;}
.ap-log-ok{color:var(--ap-ok);}
.ap-log-err{color:var(--ap-err);}
.ap-log-warn{color:var(--ap-warn);}
.ap-log-info{color:rgba(255,255,255,.8);}
.ap-log-dim{color:rgba(255,255,255,.3);}
.ap-log-scan{color:rgba(96,165,250,.8);}
.ap-log-ai{color:var(--ap-purple);}
.ap-log-replace{color:var(--ap-gold);}
.ap-log-zip{color:var(--ap-teal);}
.ap-log-verify{color:#f472b4;}
.ap-log-domain{color:#60a5fa;}
.ap-log-data{color:var(--ap-teal);}
.ap-log-start{color:rgba(255,255,255,.6);}
.ap-log-done{color:var(--ap-ok);font-weight:700;}
.ap-log-net{color:#a78bfa;}
.ap-log-retry{color:#fbbf24;font-weight:700;}
.ap-log-sep{display:flex;align-items:center;gap:8px;padding:6px 0;margin:2px 0;}
.ap-log-sep-line{flex:1;height:1px;background:rgba(255,255,255,.06);}
.ap-log-sep-label{font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;letter-spacing:1.5px;color:rgba(255,255,255,.25);white-space:nowrap;text-transform:uppercase;}
.ap-log-sep-retry{background:rgba(251,191,36,.15);border:1px solid rgba(251,191,36,.3);border-radius:6px;padding:8px 12px;}

/* Progress bar */
.ap-progress-wrap{margin:14px 0;}
.ap-progress-label{
  display:flex;justify-content:space-between;
  font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-bottom:6px;
}
.ap-progress-track{
  height:6px;background:var(--dim);border-radius:100px;overflow:hidden;
}
.ap-progress-bar{
  height:100%;background:var(--ap-grad);border-radius:100px;
  transition:width .3s;width:0%;
}

/* Domain result grid */
.ap-result-grid{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));
  gap:8px;max-height:300px;overflow-y:auto;
}
.ap-result-item{
  background:var(--dim);border:1px solid var(--border);border-radius:8px;
  padding:10px 12px;font-family:'JetBrains Mono',monospace;font-size:10px;
}
.ap-result-item.done{border-color:rgba(0,230,118,.3);}
.ap-result-item.err{border-color:rgba(255,69,96,.3);}
.ap-result-domain{color:#fff;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.ap-result-status{color:var(--muted);margin-top:3px;}
.ap-result-item.done .ap-result-status{color:var(--ap-ok);}
.ap-result-item.err .ap-result-status{color:var(--ap-err);}

/* Buttons */
.btn-ap{
  display:inline-flex;align-items:center;gap:8px;
  padding:11px 22px;border-radius:10px;font-family:'Syne',sans-serif;
  font-size:13px;font-weight:700;cursor:pointer;border:none;
  transition:all .2s;letter-spacing:.3px;
}
.btn-ap-primary{
  background:var(--ap-grad);color:#000;
  box-shadow:0 4px 20px rgba(240,165,0,.25);
}
.btn-ap-primary:hover{transform:translateY(-1px);box-shadow:0 6px 28px rgba(240,165,0,.35);}
.btn-ap-primary:disabled{opacity:.4;cursor:not-allowed;transform:none;}
.btn-ap-ghost{
  background:rgba(255,255,255,.05);color:#fff;border:1px solid var(--border);
}
.btn-ap-ghost:hover{border-color:rgba(255,255,255,.2);background:rgba(255,255,255,.08);}
.btn-ap-ok{
  background:rgba(0,230,118,.12);color:var(--ap-ok);border:1px solid rgba(0,230,118,.3);
}

/* Inline stat chips */
.ap-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;}
.ap-chip{
  display:inline-flex;align-items:center;gap:5px;
  background:var(--dim);border:1px solid var(--border);border-radius:100px;
  padding:4px 12px;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);
}
.ap-chip.gold{border-color:rgba(240,165,0,.3);color:var(--ap-gold);}
.ap-chip.ok{border-color:rgba(0,230,118,.3);color:var(--ap-ok);}
.ap-chip.warn{border-color:rgba(251,191,36,.3);color:var(--ap-warn);}

/* Divider */
.ap-divider{height:1px;background:var(--border);margin:20px 0;}
</style>
</head><body>
<div class="dash-layout">
<?php require_once '_sidebar.php'; ?>
<div class="dash-content">

<!-- Architecture info -->
<div style="background:rgba(0,0,0,.3);border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:10px 18px;margin-bottom:14px;display:flex;gap:20px;flex-wrap:wrap;font-family:'JetBrains Mono',monospace;font-size:10px;line-height:1.8;color:rgba(255,255,255,.45);">
  <span style="color:rgba(0,230,118,.85);">&#10003; Files scanned: your browser (no upload)</span>
  <span style="color:rgba(255,195,0,.8);">&#8764; Bot detect: API call only</span>
  <span style="color:rgba(0,230,118,.85);">&#10003; Replace + ZIP: your browser</span>
  <span style="color:rgba(0,230,118,.85);">&#10003; Download: direct to your PC</span>
</div>
<!-- Header -->
<div class="ap-header">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
      <div class="ap-title">🤖 Autopilot</div>
      <div class="ap-subtitle">Pick folder → drop domains → system handles everything. 50 ZIPs ready to deploy.</div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
      <span class="ap-chip gold">✦ Lifetime Exclusive</span>
      <span class="ap-chip" id="ap-status-chip">⏸ Idle</span>
    </div>
  </div>
</div>

<!-- Pipeline Stepper -->
<div class="ap-pipeline">
  <div class="ap-step active" id="ps-1"><div class="ap-step-icon">📁</div><div class="ap-step-label">TEMPLATE</div></div>
  <div class="ap-step-arrow" id="pa-1"></div>
  <div class="ap-step" id="ps-2"><div class="ap-step-icon">🌐</div><div class="ap-step-label">DOMAINS</div></div>
  <div class="ap-step-arrow" id="pa-2"></div>
  <div class="ap-step" id="ps-3"><div class="ap-step-icon">🧠</div><div class="ap-step-label">BOT DETECT</div></div>
  <div class="ap-step-arrow" id="pa-3"></div>
  <div class="ap-step" id="ps-4"><div class="ap-step-icon">✅</div><div class="ap-step-label">VERIFY</div></div>
  <div class="ap-step-arrow" id="pa-4"></div>
  <div class="ap-step" id="ps-5"><div class="ap-step-icon">⚡</div><div class="ap-step-label">RUN</div></div>
  <div class="ap-step-arrow" id="pa-5"></div>
  <div class="ap-step" id="ps-6"><div class="ap-step-icon">📦</div><div class="ap-step-label">ZIP</div></div>
</div>

<!-- ── STEP 1: Template Folder ── -->
<div class="ap-section active" id="sec-1">
  <div class="ap-section-head">
    <div class="ap-section-num">1</div>
    <div class="ap-section-title">Pick Template Folder</div>
    <div class="ap-section-sub" id="sec1-meta"></div>
  </div>

  <div id="dropzone-1" class="ap-dropzone" style="position:relative;">
    <div class="ap-dropzone-icon">📁</div>
    <div class="ap-dropzone-title">Click to pick your template folder</div>
    <div class="ap-dropzone-hint">Folder will be scanned locally — no upload to server</div>
    <div class="ap-dropzone-hint" style="margin-top:6px;color:rgba(240,165,0,.6);">Chrome / Edge required · File System Access API</div>
    <button id="btn-pick-template"
      onclick="pickFolder()"
      type="button"
      style="position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;background:none;border:none;z-index:10;">
    </button>
  </div>

  <div style="text-align:center;margin-top:12px;display:none;" id="pick-btn-alt">
    <button type="button" onclick="pickFolder()" class="btn-ap btn-ap-primary" style="padding:14px 32px;font-size:14px;">
      📁 Pick Template Folder
    </button>
    <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:8px;">Chrome / Edge required</div>
  </div>

  <div id="folder-picked" style="display:none;">
    <div class="ap-folder-picked">
      <div class="ap-folder-icon">📂</div>
      <div class="ap-folder-info">
        <div class="ap-folder-name" id="folder-name">—</div>
        <div class="ap-folder-meta" id="folder-meta">—</div>
      </div>
      <button class="btn-ap btn-ap-ghost" onclick="pickFolder()" style="font-size:11px;padding:7px 14px;">Change</button>
    </div>
    <div class="ap-chips" id="folder-chips"></div>
  </div>
</div>

<!-- ── STEP 2: Domains ── -->
<div class="ap-section locked" id="sec-2">
  <div class="ap-section-head">
    <div class="ap-section-num">2</div>
    <div class="ap-section-title">Drop Your Domains</div>
    <div class="ap-section-sub" id="sec2-meta">1 domain per line</div>
  </div>

  <div class="ap-domain-wrap">
    <textarea class="ap-domain-ta" id="domain-input"
      placeholder="butikemasmedan.com&#10;butikemasjakarta.com&#10;butikemassurabaya.com&#10;..."
      oninput="countDomains()"></textarea>
    <span class="ap-domain-counter" id="domain-count">0 domains</span>
  </div>

  <!-- KEYWORD HINT - Help AI understand institution name -->
  <div class="ap-keyword-hint-wrap" style="margin-top:16px;">
    <div class="ap-keyword-hint-header">
      <span class="ap-hint-icon">🏢</span>
      <span style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:#60a5fa;">Keyword Hint</span>
      <span class="ap-hint-badge">OPTIONAL - Better Location Data</span>
    </div>
    <input type="text"
      id="keyword-hint-input"
      class="ap-keyword-hint-input"
      placeholder="e.g., damkar, puskesmas, bnn, polres, koramil, camat, desa, pkk, posyandu..."
      style="width:100%;margin-top:8px;padding:10px 14px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:var(--text);font-family:'JetBrains Mono',monospace;font-size:12px;">
    <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:6px;">
      💡 Helps AI parse location names correctly from domains (e.g., "damkar" → Dinas Pemadam Kebakaran)
    </div>
  </div>

  <!-- HINT SYSTEM - Guide Bot Detection -->
  <div class="ap-hint-wrap" id="hint-wrap">
    <div class="ap-hint-header" onclick="toggleHint()">
      <span class="ap-hint-icon">💡</span>
      <span class="ap-hint-title">Bot Detection Hints</span>
      <span class="ap-hint-badge">OPTIONAL - Boost Accuracy</span>
      <button class="ap-hint-toggle" id="hint-toggle-btn" onclick="event.stopPropagation();">
        <span id="hint-toggle-text">Show Hints</span>
        <span id="hint-toggle-icon">▼</span>
      </button>
    </div>

    <div class="ap-hint-content" id="hint-content" style="display:none;">
      <div class="ap-hint-description">
        Guide Bot to detect field locations more accurately. Examples:<br>
        <code>• Email ada di footer kanan bawah</code><br>
        <code>• Alamat lengkap ada di halaman kontak.html</code><br>
        <code>• Nomor HP ada di header + footer</code>
      </div>

      <textarea class="ap-hint-ta" id="hint-input"
        placeholder="Example hints:&#10;&#10;Email: Cek footer kanan bawah, ada di tag <a> dengan class 'contact-email'&#10;&#10;Alamat: Halaman kontak.html, section dengan id 'alamat-toko', format lengkap dengan Jl. dan kode pos&#10;&#10;No HP/WA: Ada di header sticky + footer, format +62 atau 08xx&#10;&#10;Maps: Embed Google Maps ada di tentang-kami.html&#10;&#10;... tambahkan hint lain yang membantu Bot ..."></textarea>

      <div class="ap-hint-stats" id="hint-stats">
        <span id="hint-char-count">0</span> characters • <span class="ap-hint-tip">💡 Tip: Specific hints = better accuracy</span>
      </div>
    </div>
  </div>

  <div style="display:flex;justify-content:flex-end;margin-top:12px;">
    <button class="btn-ap btn-ap-primary" onclick="runAIDetect()" id="btn-detect">
      🧠 Analyze Template + Parse Domains
    </button>
  </div>
</div>

<!-- ── STEP 3: Bot Detection Results ── -->
<div class="ap-section locked" id="sec-3">
  <div class="ap-section-head">
    <div class="ap-section-num">3</div>
    <div class="ap-section-title">Bot Detection Results</div>
    <div class="ap-section-sub" id="sec3-meta"></div>
  </div>

  <!-- Detection status -->
  <div id="detect-loading" style="display:none;text-align:center;padding:32px;">
    <div style="font-size:32px;margin-bottom:12px;animation:spin 1.5s linear infinite;display:inline-block;">🧠</div>
    <div style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);" id="detect-status-msg">Scanning files...</div>
  </div>

  <div id="detect-results" style="display:none;">
    <!-- Confirmed detections -->
    <div style="font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;color:var(--ap-ok);letter-spacing:1px;margin-bottom:10px;">✅ BOT DETECTED</div>
    <div class="ap-detect-grid" id="detect-confirmed"></div>

    <!-- ── VERIFY: always visible after detection ── -->
    <div class="ap-divider"></div>
    <div class="ap-manual-wrap">

      <!-- Header with tabs -->
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
        <div style="display:flex;align-items:center;gap:10px;">
          <span style="font-family:'Syne',sans-serif;font-size:14px;font-weight:800;color:#fff;">🔍 Verify Mapping</span>
          <span style="font-family:'JetBrains Mono',monospace;font-size:9px;background:rgba(244,114,182,.1);border:1px solid rgba(244,114,182,.3);color:#f472b4;padding:2px 8px;border-radius:5px;">MANUAL CHECK</span>
        </div>
        <button class="btn-ap btn-ap-ghost" onclick="scanUnassigned()" id="btn-scan-unassigned" style="font-size:11px;padding:6px 12px;">
          🔎 Scan for unassigned strings
        </button>
      </div>

      <!-- Undetected chips (low confidence from Bot) -->
      <div id="undetected-wrap" style="display:none;">
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;color:var(--ap-warn);letter-spacing:1px;margin-bottom:8px;">
          ⚠️ LOW CONFIDENCE — Bot couldn't assign these:
        </div>
        <div id="undetected-chips" class="ap-chips" style="margin-bottom:14px;"></div>
      </div>

      <!-- Unassigned scan results -->
      <div id="unassigned-wrap" style="display:none;margin-bottom:14px;">
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;color:#60a5fa;letter-spacing:1px;margin-bottom:8px;">
          📋 UNASSIGNED STRINGS FOUND IN TEMPLATE:
        </div>
        <div id="unassigned-chips" class="ap-chips" style="margin-bottom:6px;"></div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);">
          Click any chip to search it. Green = already assigned. Blue = not yet assigned.
        </div>
      </div>

      <!-- Manual search -->
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-bottom:10px;line-height:1.7;">
        Search any keyword in your template files — assign undetected strings to the right field:
      </div>
      <div class="ap-manual-input-row">
        <input class="ap-manual-inp" id="manual-kw"
          placeholder="e.g: kotamedan · 20111 · jl.sudirman · 081234..."
          onkeydown="if(event.key==='Enter')searchManual()">
        <button class="btn-ap btn-ap-ghost" onclick="searchManual()" style="padding:9px 16px;font-size:12px;">
          Search
        </button>
      </div>
      <div id="manual-results" class="ap-manual-result"></div>
    </div>

    <!-- Confirm mapping -->
    <div class="ap-divider"></div>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
      <div>
        <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:4px;">Mapping Confirmed</div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);" id="mapping-summary">—</div>
      </div>
      <button class="btn-ap btn-ap-ok" onclick="confirmMapping()" id="btn-confirm-map">
        ✅ Confirm & Continue
      </button>
    </div>
  </div>
</div>

<!-- ── STEP 4: Run Pipeline ── -->
<div class="ap-section locked" id="sec-4">
  <div class="ap-section-head">
    <div class="ap-section-num">4</div>
    <div class="ap-section-title">Run Autopilot</div>
    <div class="ap-section-sub" id="sec4-meta"></div>
  </div>

  <!-- Summary before run -->
  <div id="run-summary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:20px;">
    <div style="background:var(--dim);border:1px solid var(--border);border-radius:10px;padding:14px;text-align:center;">
      <div style="font-family:'Syne',sans-serif;font-size:26px;font-weight:800;color:var(--ap-gold);" id="rs-domains">0</div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);">Domains</div>
    </div>
    <div style="background:var(--dim);border:1px solid var(--border);border-radius:10px;padding:14px;text-align:center;">
      <div style="font-family:'Syne',sans-serif;font-size:26px;font-weight:800;color:var(--ap-teal);" id="rs-fields">0</div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);">Fields Mapped</div>
    </div>
    <div style="background:var(--dim);border:1px solid var(--border);border-radius:10px;padding:14px;text-align:center;">
      <div style="font-family:'Syne',sans-serif;font-size:26px;font-weight:800;color:var(--ap-purple);" id="rs-files">0</div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);">Files/Template</div>
    </div>
    <div style="background:var(--dim);border:1px solid var(--border);border-radius:10px;padding:14px;text-align:center;">
      <div style="font-family:'Syne',sans-serif;font-size:26px;font-weight:800;color:#60a5fa;" id="rs-replacements">0</div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);">Replace Ops</div>
    </div>
  </div>

  <!-- Output folder picker -->
  <div style="background:var(--dim);border:1px solid var(--border);border-radius:12px;padding:16px 18px;margin-bottom:16px;">
    <div style="font-size:12px;font-weight:700;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;">Step 4b &mdash; Output Folder (write destination)</div>
    <div style="font-size:12px;color:rgba(255,255,255,.6);line-height:1.7;margin-bottom:12px;">
      Pick a folder on your PC where Autopilot will write the output.<br>
      Each domain gets its own subfolder: <code style="color:var(--ap-gold);">output_folder/domain.com/...</code>
    </div>
    <button class="btn-ap btn-ap-ghost" onclick="pickOutputFolder()" id="btn-pick-output" style="padding:10px 18px;font-size:12px;">
      Pick Output Folder
    </button>
    <div id="output-folder-status" style="display:none;margin-top:10px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--ap-ok);">
      Output: <span id="output-folder-name" style="color:#fff;font-weight:700;"></span>
    </div>
  </div>

  <button class="btn-ap btn-ap-primary" onclick="runPipeline()" id="btn-run"
    disabled
    style="width:100%;justify-content:center;padding:15px;font-size:15px;opacity:.5;cursor:not-allowed;"
    onmouseover="if(!this.disabled)this.style.opacity='1'" onmouseout="if(!this.disabled)this.style.opacity=''">
    Launch Autopilot
  </button>
  <div style="text-align:center;margin-top:8px;font-size:10px;color:var(--muted);">
    Files written directly to your PC &mdash; no ZIP, no upload
  </div>
</div>

<!-- ── STEP 5: Live Progress ── -->
<div class="ap-section locked" id="sec-5">
  <div class="ap-section-head">
    <div class="ap-section-num">5</div>
    <div class="ap-section-title">Processing</div>
    <div class="ap-section-sub" id="sec5-meta">0 / 0</div>
  </div>

  <div class="ap-progress-wrap">
    <div class="ap-progress-label">
      <span id="prog-label">Waiting...</span>
      <span id="prog-pct">0%</span>
    </div>
    <div class="ap-progress-track"><div class="ap-progress-bar" id="prog-bar"></div></div>
  </div>

  <div class="ap-log" id="ap-log">
    <div class="ap-log-line"><span class="ap-log-ts">--:--</span><span class="ap-log-dim">Autopilot ready. Configure steps above and click Launch.</span></div>
  </div>

  <!-- Domain results -->
  <div class="ap-divider"></div>
  <div style="font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;color:var(--muted);letter-spacing:1px;margin-bottom:10px;" id="result-label">OUTPUT</div>
  <div class="ap-result-grid" id="result-grid"></div>

  <!-- Download all -->
  <div id="done-summary" style="display:none;background:rgba(0,230,118,.05);border:1px solid rgba(0,230,118,.25);border-radius:12px;padding:18px 20px;margin-top:16px;">
    <div style="font-size:16px;font-weight:800;color:var(--ap-ok);margin-bottom:6px;">All Done!</div>
    <div style="font-family:'JetBrains Mono',monospace;font-size:12px;color:rgba(255,255,255,.7);line-height:2;">
      <b style="color:#fff;" id="done-count">0</b> domains processed<br>
      Files saved to: <b style="color:var(--ap-gold);" id="done-folder"></b>/<i>[domain]/</i>
    </div>
    <div style="margin-top:10px;font-size:10px;color:var(--muted);">Open your output folder in Explorer to access the files.</div>
  </div>
</div>

</div><!-- /dash-content -->
</div><!-- /dash-layout -->

<style>
@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script>
/* =========================================================
   AUTOPILOT ENGINE v3 - BulkReplace
   Workflow: scan folder -> drop domains -> Bot detects values
             -> replace in browser -> save to PC folder (no ZIP)
   ========================================================= */

// Global error trap
window.onerror = function(msg, src, line){
  var log = document.getElementById('ap-log');
  if(log){ apLog('err','JS Error: '+String(msg).slice(0,80)); }
  return false;
};

// Browser check
window.addEventListener('DOMContentLoaded', function(){
  if(!('showDirectoryPicker' in window)){
    var dz = document.getElementById('dropzone-1');
    if(dz){
      dz.innerHTML = '<div style="text-align:center;padding:30px;">'
        +'<div style="font-size:40px;margin-bottom:12px;">⚠️</div>'
        +'<div style="font-size:15px;font-weight:700;color:#fff;margin-bottom:8px;">Chrome or Edge Required</div>'
        +'<div style="font-size:11px;color:var(--muted);">Autopilot uses the File System Access API.<br>Please open this page in <strong style=\"color:#fff\">Chrome</strong> or <strong style=\"color:#fff\">Edge</strong> on desktop.</div>'
        +'</div>';
    }
  } else {
    // Supported — show the alt button too as a visible backup
    var altBtn = document.getElementById('pick-btn-alt');
    if(altBtn) altBtn.style.display = 'block';
  }
});

// ---- STATE ----------------------------------------------------------
var AP = {
  templateHandle: null,   // source folder handle (read)
  outputHandle:   null,   // output folder handle (readwrite)
  templateFiles:  {},     // path -> text content
  binaryFiles:    {},     // path -> ArrayBuffer (images, fonts)
  domainList:     [],
  mapping:        {},     // field -> [values to replace]
  confirmed:      false,
  results:        {}      // domain -> {ok, files, errors}
};

// ---- LOG ---------------------------------------------------------------
function apLog(type, msg, extra){
  var log = document.getElementById('ap-log');
  if(!log) return;
  var now = new Date();
  var ts = pad(now.getHours())+':'+pad(now.getMinutes())+':'+pad(now.getSeconds());
  var cls = {ok:'ap-log-ok',err:'ap-log-err',warn:'ap-log-warn',info:'ap-log-info',
    dim:'ap-log-dim',scan:'ap-log-scan',ai:'ap-log-ai',replace:'ap-log-replace',
    zip:'ap-log-zip',verify:'ap-log-verify',domain:'ap-log-domain',
    data:'ap-log-data',start:'ap-log-start',done:'ap-log-done',net:'ap-log-net',retry:'ap-log-retry'}[type]||'ap-log-dim';
  var line = document.createElement('div');
  line.className = 'ap-log-line';
  line.innerHTML = '<span class="ap-log-ts">'+ts+'</span>'
    +'<span class="ap-log-icon">'+({ok:'+',err:'!',warn:'~',info:'i',dim:'.',scan:'f',
      ai:'*',replace:'>',zip:'z',verify:'?',domain:'@',data:'d',start:'>',done:'#',net:'~',retry:'↻'}[type]||'.')+'</span>'
    +'<span class="'+cls+'">'+escHTML(msg)+'</span>'
    +(extra?'<span class="ap-log-extra">'+escHTML(extra)+'</span>':'');
  log.appendChild(line);
  log.scrollTop = log.scrollHeight;
}

function apLogSep(label){
  var log = document.getElementById('ap-log');
  if(!log) return;
  var line = document.createElement('div');
  var isRetry = label.includes('RETRY');
  line.className = isRetry ? 'ap-log-sep ap-log-sep-retry' : 'ap-log-sep';
  line.innerHTML = '<span class="ap-log-sep-line"></span>'
    +'<span class="ap-log-sep-label">'+(isRetry?'🔄 ':'')+escHTML(label)+'</span>'
    +'<span class="ap-log-sep-line"></span>';
  log.appendChild(line);
  log.scrollTop = log.scrollHeight;
}

function escHTML(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function pad(n){ return String(n).padStart(2,'0'); }

// ---- SECTION HELPERS ----------------------------------------------------
function sectionActivate(id){
  var el = document.getElementById('sec-'+id);
  if(el){ el.classList.remove('locked'); el.classList.add('active'); }
}
function sectionDone(id){
  var el = document.getElementById('sec-'+id);
  if(el){ el.classList.remove('active','locked'); el.classList.add('done'); }
}
function stepDone(n){
  var el = document.getElementById('ps-'+n); if(el){ el.classList.remove('active'); el.classList.add('done'); }
  var pa = document.getElementById('pa-'+n); if(pa) pa.classList.add('done');
}
function setProgress(pct, label){
  document.getElementById('prog-bar').style.width = pct+'%';
  document.getElementById('prog-pct').textContent = Math.round(pct)+'%';
  document.getElementById('prog-label').textContent = label;
}

// ---- STEP 1: PICK TEMPLATE FOLDER (read-only) ----------------------------
async function pickFolder(){
  var dz = document.getElementById('dropzone-1');
  if(dz) dz.style.opacity = '0.6';
  if(!('showDirectoryPicker' in window)){
    if(dz) dz.style.opacity = '1';
    alert('Please use Chrome or Edge (desktop).');
    return;
  }
  try{
    var handle = await window.showDirectoryPicker({mode:'read'});
    AP.templateHandle = handle;
    document.getElementById('dropzone-1').style.display = 'none';
    document.getElementById('folder-picked').style.display = 'block';
    document.getElementById('folder-name').textContent = handle.name;
    document.getElementById('sec1-meta').textContent = 'scanning...';

    apLogSep('SCAN TEMPLATE');
    apLog('start', 'Scanning: '+handle.name);
    AP.templateFiles = {};
    AP.binaryFiles = {};
    var stats = {files:0, ext:{}, skipped:0};
    await scanDir(handle, '', stats);

    var extList = Object.entries(stats.ext).sort(function(a,b){return b[1]-a[1];}).slice(0,6)
      .map(function(e){return e[0]+'('+e[1]+')';}).join(' ');
    var totalKB = (Object.values(AP.templateFiles).reduce(function(a,v){return a+v.length;},0)/1024).toFixed(1);
    document.getElementById('folder-meta').textContent = stats.files+' files / '+totalKB+' KB / '+extList;
    document.getElementById('sec1-meta').textContent   = stats.files+' files';
    document.getElementById('folder-chips').innerHTML  = Object.entries(stats.ext)
      .sort(function(a,b){return b[1]-a[1];})
      .map(function(e){return '<span class="ap-chip">'+e[0]+' <strong style="color:#fff">'+e[1]+'</strong></span>';}).join('');

    apLog('ok','Scan done',stats.files+' files / '+stats.skipped+' skipped / '+totalKB+' KB');
    sectionDone(1); stepDone(1); sectionActivate(2);
    apLog('info','Step 1 done - enter your domains below');

  } catch(e){
    if(dz) dz.style.opacity = '1';
    if(e.name !== 'AbortError'){
      apLog('err','Folder error', e.message);
      document.getElementById('sec1-meta').textContent = 'Error';
    }
  }
}

async function scanDir(dirHandle, prefix, stats){
  var SKIP = ['node_modules','.git','vendor','__pycache__','.DS_Store','thumbs.db','desktop.ini'];
  // Text extensions — read as string so replacements can be applied
  var TEXT_EXTS = ['.html','.htm','.css','.js','.php','.json','.xml','.txt','.md','.svg',
                   '.yml','.yaml','.ini','.env','.htaccess','.conf','.ts','.jsx','.vue',
                   '.scss','.less','.twig','.blade','.phtml','.asp','.aspx'];
  for await(var entry of dirHandle.values()){
    var name = entry.name;
    if(SKIP.some(function(s){return name.toLowerCase()===s.toLowerCase();})){stats.skipped++;continue;}
    var path = prefix ? prefix+'/'+name : name;
    if(entry.kind === 'directory'){
      await scanDir(entry, path, stats);
    } else {
      var ext = name.includes('.') ? '.'+name.split('.').pop().toLowerCase() : '';
      try{
        var file = await entry.getFile();
        if(TEXT_EXTS.includes(ext)){
          // Text file — store as string, replacement will be applied
          var txt = await file.text();
          AP.templateFiles[path] = txt;
          stats.files++;
          stats.ext[ext||'(none)'] = (stats.ext[ext||'(none)']||0)+1;
          apLog('scan', path, (txt.length/1024).toFixed(1)+' KB');
        } else {
          // EVERYTHING ELSE — read as binary ArrayBuffer, copy as-is
          // (images, video, audio, fonts, zip, pdf, ico, woff, mp4, etc.)
          var buf = await file.arrayBuffer();
          AP.binaryFiles[path] = buf;
          stats.files++;
          stats.ext[ext||'(bin)'] = (stats.ext[ext||'(bin)']||0)+1;
          apLog('scan', path, (buf.byteLength/1024).toFixed(1)+' KB [copy]');
        }
      } catch(e){ apLog('warn','Skip: '+path+' — '+e.message); }
    }
  }
}
  
// ---- DOMAIN COUNTER ------------------------------------------------------
function countDomains(){
  var lines = document.getElementById('domain-input').value
    .split('\n').map(function(l){return l.trim();}).filter(Boolean);
  document.getElementById('domain-count').textContent = lines.length+' domain'+(lines.length!==1?'s':'');
}

// Toggle hint section
function toggleHint(){
  var content = document.getElementById('hint-content');
  var icon = document.getElementById('hint-toggle-icon');
  var text = document.getElementById('hint-toggle-text');

  if(content.style.display === 'none'){
    content.style.display = 'block';
    icon.textContent = '▲';
    text.textContent = 'Hide Hints';
  } else {
    content.style.display = 'none';
    icon.textContent = '▼';
    text.textContent = 'Show Hints';
  }
}

// Update hint character count & auto-expand on focus
document.addEventListener('DOMContentLoaded', function(){
  var hintInput = document.getElementById('hint-input');
  if(hintInput){
    hintInput.addEventListener('input', function(){
      var count = this.value.length;
      document.getElementById('hint-char-count').textContent = count;
    });

    // Auto-expand hint section when user clicks to type
    hintInput.addEventListener('focus', function(){
      var content = document.getElementById('hint-content');
      if(content.style.display === 'none'){
        toggleHint();
      }
    });
  }
});

// ---- STEP 3: BOT DETECT - new strategy ------------------------------------
// Send template CONTENT to Bot, not a list of extracted strings.
// AI reads the template and finds all location-specific VALUES directly.
async function runAIDetect(){
  var raw = document.getElementById('domain-input').value;
  AP.domainList = raw.split('\n').map(function(l){return l.trim().toLowerCase();}).filter(Boolean);
  if(!AP.domainList.length){ alert('Enter at least 1 domain.'); return; }
  if(!AP.templateHandle){ alert('Pick template folder first.'); return; }

  document.getElementById('btn-detect').disabled = true;
  document.getElementById('btn-detect').textContent = 'Analyzing...';
  sectionDone(2); stepDone(2);
  sectionActivate(3);
  document.getElementById('detect-loading').style.display = 'block';
  document.getElementById('detect-results').style.display = 'none';
  document.getElementById('sec3-meta').textContent = 'analyzing...';

  var setMsg = function(m){ var el = document.getElementById('detect-status-msg'); if(el) el.textContent = m; };

  try{
    apLogSep('BOT DETECTION');
    apLog('start', 'Building template sample for Bot...');
    setMsg('Building template sample...');

    // Build a smart content sample for Bot:
    // Priority: HTML files first (index.html/index.php), then others
    // We send a ~8KB representative slice
    var sampleContent = buildTemplateSample(AP.templateFiles, AP.domainList[0]);
    apLog('info', 'Sample built', (sampleContent.length/1024).toFixed(1)+' KB sent to Bot');

    setMsg('Sending to BulkReplace Bot...');
    apLog('net', 'Sending template to BulkReplace Bot for context-aware detection...');

    var elapsed = 0;
    var timer = setInterval(function(){
      elapsed++;
      setMsg('BulkReplace Bot reading template... '+elapsed+'s');
    }, 1000);

    // Get user hints (if provided)
    var userHints = document.getElementById('hint-input').value.trim();
    if(userHints){
      apLog('info', 'User hints detected', (userHints.length)+' chars - will guide Bot detection');
    }

    var controller = new AbortController();
    var tid = setTimeout(function(){ controller.abort(); }, 90000);
    var detectRes = await fetch('/api/autopilot_detect.php',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      signal: controller.signal,
      body: JSON.stringify({
        template_content: sampleContent,
        domains:          AP.domainList,
        ref_domain:       AP.domainList[0],
        user_hints:       userHints  // Send hints to Bot
      })
    });
    clearTimeout(tid);
    clearInterval(timer);

    var rawText = await detectRes.text();
    var detectData;
    try{ detectData = JSON.parse(rawText); }
    catch(je){ throw new Error('Server error: '+rawText.replace(/<[^>]+>/g,'').slice(0,150).trim()); }

    if(!detectData.ok) throw new Error(detectData.msg || 'Detection failed');

    var method = detectData.method || 'local';
    var methodLabel = {
      'claude':       'Bulk Replace Bot (context-aware)',
      'openai':       'GPT-4o-mini',
      'local':        'Local engine',
      'local_fallback': 'Local engine (AI unavailable)'
    }[method] || method;

    // Show hint usage indicator
    var hintUsed = userHints ? ' + User Hints 💡' : '';

    apLog('ok', 'Detection done via '+methodLabel+hintUsed,
      detectData.fields_found+' fields / '+detectData.total_strings+' values');

    if(userHints){
      apLog('info', 'Hint boost active', 'AI guided by your custom hints ('+userHints.length+' chars)');
    }

    // Show what was found
    for(var field in (detectData.mapping||{})){
      var vals = detectData.mapping[field];
      if(vals && vals.length){
        apLog('ai', '  '+field+' ('+vals.length+')', vals.slice(0,3).join(' | '));
      }
    }
    if((detectData.undetected||[]).length){
      apLog('warn','Undetected: '+(detectData.undetected||[]).length,(detectData.undetected||[]).slice(0,4).join(' | '));
    }

    AP.mapping = detectData.mapping || {};
    renderDetectResults(AP.mapping, detectData.undetected || []);

    apLogSep('REVIEW MAPPING');
    apLog('info','Review detected values below. Add missing ones manually, then confirm.');
    document.getElementById('sec3-meta').textContent = Object.keys(AP.mapping).length+' fields';

  } catch(e){
    clearInterval && clearInterval();
    apLog('err','Detection error', e.message);
    document.getElementById('detect-loading').style.display = 'none';
    var dr = document.getElementById('detect-results');
    dr.style.display = 'block';
    dr.innerHTML = '<div style="background:rgba(255,69,96,.08);border:1px solid rgba(255,69,96,.3);'
      +'border-radius:12px;padding:20px 24px;">'
      +'<div style="font-size:15px;font-weight:800;color:#ff4560;margin-bottom:8px;">Detection Failed</div>'
      +'<div style="font-family:\'JetBrains Mono\',monospace;font-size:11px;color:rgba(255,255,255,.7);'
      +'line-height:1.8;margin-bottom:14px;">'+escHTML(e.message)+'</div>'
      +'<div style="font-size:10px;color:var(--muted);margin-bottom:14px;">'
      +'Fix: set ANTHROPIC_API_KEY or OPENAI_API_KEY in config.php</div>'
      +'<button class="btn-ap btn-ap-ghost" onclick="retryDetect()" style="font-size:12px;padding:8px 16px;">Retry</button></div>';
    document.getElementById('sec3-meta').textContent = 'error';
  }
  document.getElementById('btn-detect').disabled = false;
  document.getElementById('btn-detect').textContent = 'Analyze Template';
}

// Build a smart ~8KB sample from template files for Bot
function buildTemplateSample(files, refDomain){
  var parts   = [];
  var used    = new Set();
  var budget  = 28000; // ~28KB total sent to Claude

  // Helper: add a file chunk
  function addFile(path, maxChars){
    if(used.has(path) || budget < 200) return;
    var content = files[path];
    if(!content) return;
    var chunk = content.slice(0, Math.min(maxChars, budget));
    parts.push('--- FILE: '+path+' ---\n'+chunk);
    budget -= chunk.length;
    used.add(path);
  }

  // -- TIER 1: .txt data files  -  single-value, highest signal --------------
  // e.g. canonical.txt, nama.txt, email.txt, alamat.txt, notelp.txt
  for(var path in files){
    if(path.toLowerCase().endsWith('.txt')){
      var val = files[path].trim();
      if(val.length >= 2 && val.length <= 400){
        parts.push('--- TXT VALUE: '+path+' = '+val+' ---');
        used.add(path);
        budget -= val.length + 30;
      }
    }
  }

  // -- TIER 2: index file (most JSON-LD, meta, canonical) ------------------
  var indexPriority = ['index.html','index.php','index.htm',
    'home.html','home.php','beranda.html','beranda.php'];
  for(var i=0; i<indexPriority.length && budget>2000; i++){
    for(var path in files){
      var pl = path.toLowerCase();
      if(pl === indexPriority[i] || pl.endsWith('/'+indexPriority[i])){
        addFile(path, 14000);
        break;
      }
    }
  }

  // -- TIER 3: contact / footer pages (phone, address, maps) ---------------
  // Priority: files containing "alamat" keyword in filename or content
  var contactKeys = ['kontak','contact','footer','hubungi','tentang','about','alamat','lokasi'];
  var alamatFiles = [];
  var regularContactFiles = [];

  for(var path in files){
    if(used.has(path)) continue;
    var pl2 = path.toLowerCase();
    var content = files[path] || '';

    // High priority: alamat/lokasi in filename OR contains address patterns
    if(pl2.includes('alamat') || pl2.includes('lokasi') ||
       /(?:Jl|Jalan|Komplek)\.?\s+[A-Z]/i.test(content)){
      alamatFiles.push(path);
    } else if(contactKeys.some(function(k){return pl2.includes(k);})){
      regularContactFiles.push(path);
    }
  }

  // Process alamat files first (higher budget)
  alamatFiles.forEach(function(path){
    if(budget > 1000) addFile(path, 4000);
  });

  // Then regular contact files
  regularContactFiles.forEach(function(path){
    if(budget > 1000) addFile(path, 2500);
  });

  // -- TIER 4: any remaining HTML/PHP if budget left ---------------------
  var htmlExts = ['.html','.htm','.php'];
  for(var path in files){
    if(budget < 1000) break;
    if(used.has(path)) continue;
    var ext = path.toLowerCase().split('.').pop();
    if(htmlExts.indexOf('.'+ext) >= 0){
      addFile(path, 2000);
    }
  }

  return 'Reference domain: '+refDomain+'\n'
    +'Files in template: '+Object.keys(files).length+'\n\n'
    + parts.join('\n\n');
}

function retryDetect(){
  document.getElementById('detect-results').innerHTML = '';
  document.getElementById('detect-results').style.display = 'none';
  document.getElementById('detect-loading').style.display = 'none';
  document.getElementById('sec-3').classList.remove('done','active');
  document.getElementById('sec-3').classList.add('locked');
  document.getElementById('sec-2').classList.remove('done');
  document.getElementById('sec-2').classList.add('active');
  document.getElementById('btn-detect').disabled = false;
  document.getElementById('btn-detect').textContent = 'Analyze Template';
  apLog('info','Retry - click Analyze Template again');
}

// ---- RENDER DETECTION RESULTS -------------------------------------------
var FIELD_LABELS = {
  namalink:'namalink',namalinkurl:'namalinkurl',daerah:'daerah',
  email:'email',alamat:'alamat',embedmap:'embedmap',linkmaps:'linkmaps',
  provinsi:'provinsi',kodepos:'kodepos',namainstansi:'namainstansi',notelp:'notelp'
};

function renderDetectResults(mapping, undetected){
  document.getElementById('detect-loading').style.display = 'none';
  document.getElementById('detect-results').style.display = 'block';
  var grid = document.getElementById('detect-confirmed');
  grid.innerHTML = '';

  // Show hint indicator if hints were used
  var hintInput = document.getElementById('hint-input');
  var hasHints = hintInput && hintInput.value.trim().length > 0;
  if(hasHints){
    grid.innerHTML = '<div style="background:rgba(96,165,250,.08);border:1px solid rgba(96,165,250,.25);border-radius:8px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;">'
      +'<span style="font-size:18px;">💡</span>'
      +'<span style="font-family:\'JetBrains Mono\',monospace;font-size:11px;color:#60a5fa;">Bot detection guided by your custom hints</span>'
      +'<span style="margin-left:auto;font-family:\'JetBrains Mono\',monospace;font-size:9px;background:rgba(96,165,250,.15);border:1px solid rgba(96,165,250,.3);color:#60a5fa;padding:3px 8px;border-radius:5px;">HINT BOOST ACTIVE</span>'
      +'</div>';
  }

  var total = 0;
  for(var field in mapping){
    var vals = mapping[field];
    if(!vals||!vals.length) continue;
    total += vals.length;
    var pills = vals.slice(0,4).map(function(s){
      return '<span class="ap-detect-str" title="'+escHTML(s)+'">'+escHTML(s.length>40?s.slice(0,40)+'...':s)+'</span>';
    }).join('');
    if(vals.length>4) pills += '<span class="ap-detect-str" style="color:var(--muted);">+'+(vals.length-4)+'</span>';
    var fk = FIELD_LABELS[field]||field;
    grid.innerHTML += '<div class="ap-detect-row confirmed" data-field="'+fk+'">'
      +'<div class="ap-detect-header">'
      +'<span class="ap-detect-field df-'+fk+'">'+fk+'</span>'
      +'<span class="ap-detect-conf">'+vals.length+' value'+(vals.length>1?'s':'')+'</span>'
      +'</div><div class="ap-detect-strings">'+pills+'</div>'
      +'</div>';
  }

  if(undetected && undetected.length){
    document.getElementById('undetected-wrap').style.display = 'block';
    document.getElementById('undetected-chips').innerHTML = undetected.map(function(s){
      return '<span class="ap-chip warn" style="cursor:pointer" onclick="searchManualKw(\''+escHTML(s.replace(/'/g,"\\'"))+'\')">! '
        +escHTML(s.length>28?s.slice(0,28)+'...':s)+'</span>';
    }).join('');
  }

  var nf = Object.keys(mapping).length;
  document.getElementById('mapping-summary').textContent = nf+' fields / '+total+' values';
  document.getElementById('sec3-meta').textContent  = nf+' fields mapped';
  document.getElementById('rs-domains').textContent = AP.domainList.length;
  document.getElementById('rs-fields').textContent  = nf;
  document.getElementById('rs-files').textContent   = Object.keys(AP.templateFiles).length;
  document.getElementById('rs-replacements').textContent = AP.domainList.length * nf;
}

// ---- MANUAL SEARCH -------------------------------------------------------
function scanUnassigned(){
  apLog('info','Scanning template for unassigned strings...');
  var assigned = new Set(Object.values(AP.mapping).reduce(function(a,b){return a.concat(b);},[]).map(function(s){return s.toLowerCase();}));
  var candidates = new Set();

  var patterns = [
    /<title>([^<]{5,200})<\/title>/gi,
    /content=["']([^"']{10,300})["']/gi,
    /"(?:name|streetAddress|addressLocality|addressRegion|telephone|email|url)":\s*"([^"]{5,300})"/gi,
    /(?:Jl|Jalan)\.?\s+[A-Z][^<\n"']{15,200}/gi,
    /\b(?:Kota|Kabupaten)\s+[A-Z][a-zA-Z\s]{3,40}\b/g,
    /\+?62\s?[0-9\-\s]{8,18}/g,
    /\b[a-z0-9._-]+@[a-z0-9.-]+\.[a-z]{2,6}\b/gi
  ];

  for(var path in AP.templateFiles){
    var content = AP.templateFiles[path];
    if(path.toLowerCase().endsWith('.txt')){
      var val = content.trim();
      if(val.length >= 5 && val.length <= 300 && !assigned.has(val.toLowerCase())){
        candidates.add(val);
      }
      continue;
    }
    patterns.forEach(function(re){
      var r = new RegExp(re.source, re.flags);
      var m;
      while((m=r.exec(content))!==null){
        var str = (m[1]||m[0]).trim();
        if(str.length >= 5 && str.length <= 300 && !assigned.has(str.toLowerCase())){
          candidates.add(str);
        }
      }
    });
  }

  var arr = Array.from(candidates).sort(function(a,b){return a.length-b.length;}).slice(0,30);
  if(!arr.length){
    apLog('ok','No unassigned strings found - all detected!');
    document.getElementById('unassigned-wrap').style.display = 'none';
    return;
  }

  apLog('info','Found '+arr.length+' unassigned candidates');
  document.getElementById('unassigned-wrap').style.display = 'block';
  document.getElementById('unassigned-chips').innerHTML = arr.map(function(s){
    var preview = s.length>35 ? s.slice(0,35)+'...' : s;
    return '<span class="ap-chip" style="cursor:pointer;border-color:rgba(96,165,250,.3);color:#60a5fa;" '
      +'onclick="searchManualKw(\''+escHTML(s.replace(/'/g,"\\'"))+'\')">'+escHTML(preview)+'</span>';
  }).join('');
}

function searchManualKw(kw){
  document.getElementById('manual-kw').value = kw;
  searchManual();
}

function searchManual(){
  var kw = document.getElementById('manual-kw').value.trim().toLowerCase();
  if(!kw) return;

  // Already assigned values
  var assigned = new Set(Object.values(AP.mapping).reduce(function(a,b){return a.concat(b);},[]).map(function(s){return s.toLowerCase();}));

  // Extract attribute-level tokens from HTML containing keyword
  var found = new Map(); // value -> [paths]

  var patterns = [
    /<title>([^<]{2,200})<\/title>/gi,
    /content=["']([^"']{2,300})["']/gi,
    /href=["']([^"']{2,300})["']/gi,
    /src=["']([^"']{2,300})["']/gi,
    /"(?:name|streetAddress|addressLocality|telephone|email|url|description)":\s*"([^"]{2,300})"/gi,
    /(?:Jl|Jalan)\.?\s+[^<\n"']{10,200}/gi,
    /\b[A-Z][a-zA-Z\s,.-]{3,60}\b/g,
  ];

  for(var path in AP.templateFiles){
    var content = AP.templateFiles[path];
    // For .txt files: whole content is the value
    if(path.toLowerCase().endsWith('.txt')){
      var val = content.trim();
      if(val.toLowerCase().includes(kw) && !assigned.has(val.toLowerCase())){
        if(!found.has(val)) found.set(val,[]);
        if(!found.get(val).includes(path)) found.get(val).push(path);
      }
      continue;
    }
    patterns.forEach(function(re){
      var r = new RegExp(re.source, re.flags);
      var m;
      while((m=r.exec(content))!==null){
        var raw = (m[1]||m[0]).trim();
        raw.split(/(?<=[,;|])\s*/).forEach(function(part){
          part = part.trim();
          if(part.length < 2 || part.length > 300) return;
          if(!part.toLowerCase().includes(kw)) return;
          if(assigned.has(part.toLowerCase())) return;
          if(!found.has(part)) found.set(part,[]);
          if(!found.get(part).includes(path)) found.get(part).push(path);
        });
      }
    });
  }

  var res = document.getElementById('manual-results');
  if(!found.size){
    res.innerHTML = '<div style="font-size:11px;color:var(--muted);padding:8px;">Nothing found for "'+escHTML(kw)+'" (may already be detected)</div>';
    apLog('dim','Manual search: "'+kw+'" - not found');
    return;
  }

  var sorted = [...found.entries()].sort(function(a,b){return a[0].length-b[0].length;}).slice(0,10);
  apLog('info','Manual search: "'+kw+'" - '+sorted.length+' result(s)');

  var FIELDS=['namalinkurl','namalink','daerah','email','alamat','embedmap','linkmaps','provinsi','kodepos','notelp','namainstansi','skip'];
  var html = '';
  sorted.forEach(function(pair, i){
    var str=pair[0], files=pair[1];
    var disp = escHTML(str.length>60?str.slice(0,60)+'...':str);
    // Highlight kw
    disp = disp.replace(new RegExp('('+escHTML(kw).replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi'),
      '<mark style="background:rgba(240,165,0,.3);color:#f0a500;border-radius:2px;padding:0 2px;">$1</mark>');
    var opts = FIELDS.map(function(f){return '<option value="'+f+'">'+f+'</option>';}).join('');
    html += '<div class="ap-manual-hit" id="mh-'+i+'">'
      +'<div class="ap-manual-hit-str" title="'+escHTML(str)+'">'+disp+'</div>'
      +'<div class="ap-manual-hit-files">'+files.length+' file(s)</div>'
      +'<select class="ap-manual-assign" onchange="assignManual(\''+escHTML(str).replace(/'/g,"\\'")+'\''+',this.value,'+i+')">'
      +'<option value="">-- assign field --</option>'+opts+'</select></div>';
  });
  res.innerHTML = html;
}

function assignManual(str, field, rowId){
  if(!field || field==='skip') return;
  if(!AP.mapping[field]) AP.mapping[field]=[];
  if(!AP.mapping[field].includes(str)) AP.mapping[field].push(str);
  var row = document.getElementById('mh-'+rowId);
  if(row){ row.style.opacity='.3'; row.style.pointerEvents='none'; }
  apLog('ok','Assigned "'+str.slice(0,35)+'" -> '+field);

  // Update summary
  var nf = Object.keys(AP.mapping).length;
  var nt = Object.values(AP.mapping).reduce(function(a,b){return a+b.length;},0);
  document.getElementById('mapping-summary').textContent = nf+' fields / '+nt+' values / user-edited';
  document.getElementById('sec3-meta').textContent = nf+' fields';
  document.getElementById('rs-fields').textContent = nf;

  // Update grid
  var fk = FIELD_LABELS[field]||field;
  var grid = document.getElementById('detect-confirmed');
  var existing = grid.querySelector('[data-field="'+fk+'"]');
  if(existing){
    var pills = existing.querySelector('.ap-detect-strings');
    if(pills){
      var pill = document.createElement('span');
      pill.className = 'ap-detect-str';
      pill.title = str;
      pill.textContent = str.length>40?str.slice(0,40)+'...':str;
      pills.appendChild(pill);
    }
    var conf = existing.querySelector('.ap-detect-conf');
    if(conf) conf.textContent = AP.mapping[field].length+' values';
  } else {
    grid.innerHTML += '<div class="ap-detect-row confirmed" data-field="'+fk+'">'
      +'<div class="ap-detect-header"><span class="ap-detect-field df-'+fk+'">'+fk+'</span>'
      +'<span class="ap-detect-conf">1 value</span></div>'
      +'<div class="ap-detect-strings"><span class="ap-detect-str" title="'+escHTML(str)+'">'+escHTML(str.length>40?str.slice(0,40)+'...':str)+'</span></div>'
      +'</div>';
  }
}

// ---- CONFIRM MAPPING -------------------------------------------------------
function confirmMapping(){
  AP.confirmed = true;
  document.getElementById('btn-confirm-map').textContent = 'Confirmed';
  document.getElementById('btn-confirm-map').disabled = true;
  sectionDone(3); stepDone(3); sectionActivate(4);
  apLogSep('MAPPING LOCKED');
  var fields = Object.keys(AP.mapping);
  apLog('ok','Confirmed',fields.length+' fields / '
    +Object.values(AP.mapping).reduce(function(a,b){return a+b.length;},0)+' values');
  fields.forEach(function(f){
    apLog('dim','  '+f+': '+AP.mapping[f].length+' value(s)',AP.mapping[f].slice(0,2).join(' | '));
  });
  apLog('info','Pick output folder, then launch');
  document.getElementById('sec-4').scrollIntoView({behavior:'smooth',block:'start'});
}

// ---- STEP 5: PICK OUTPUT FOLDER (readwrite) --------------------------------
async function pickOutputFolder(){
  if(!('showDirectoryPicker' in window)){ alert('Chrome/Edge required'); return; }
  try{
    var handle = await window.showDirectoryPicker({mode:'readwrite'});
    AP.outputHandle = handle;
    document.getElementById('output-folder-name').textContent = handle.name;
    document.getElementById('output-folder-status').style.display = 'block';
    document.getElementById('btn-pick-output').textContent = 'Change Output Folder';
    apLog('ok','Output folder set: '+handle.name);
    // Enable run button
    var runBtn = document.getElementById('btn-run');
    runBtn.disabled = false;
    runBtn.style.opacity = '1';
    runBtn.style.cursor  = 'pointer';
  } catch(e){
    if(e.name !== 'AbortError') apLog('err','Output folder error', e.message);
  }
}

// ---- STEP 6: RUN PIPELINE --------------------------------------------------
async function runPipeline(){
  if(!AP.confirmed){ alert('Confirm the mapping first.'); return; }
  if(!AP.domainList.length){ alert('No domains.'); return; }
  if(!AP.outputHandle){ alert('Pick an output folder first.'); return; }

  document.getElementById('btn-run').disabled = true;
  document.getElementById('btn-run').textContent = 'Running...';
  sectionDone(4); sectionActivate(5);
  document.getElementById('sec-5').scrollIntoView({behavior:'smooth',block:'start'});

  var chip = document.getElementById('ap-status-chip');
  if(chip){ chip.textContent='Running'; chip.style.borderColor='rgba(240,165,0,.5)'; chip.style.color='var(--ap-gold)'; }

  apLogSep('FETCHING DOMAIN DATA');

  // Get user hints and keyword hint
  var userHints = document.getElementById('hint-input') ? document.getElementById('hint-input').value.trim() : '';
  var keywordHint = document.getElementById('keyword-hint-input') ? document.getElementById('keyword-hint-input').value.trim() : '';

  if(keywordHint){
    apLog('info','Using keyword hint: "'+keywordHint+'"');
  }

  var csvData = await fetchDomainDataChunked(AP.domainList, keywordHint, userHints);

  var total = AP.domainList.length;
  var grid  = document.getElementById('result-grid');
  grid.innerHTML = '';
  var done=0, errors=0, totalOps=0;
  var startTime = performance.now();
  var processStats = {zipped:0, fallback:0, warnings:0};
  var failedDomainsList = [];

  AP.domainList.forEach(function(d){
    var rid = 'ri-'+d.replace(/[^a-z0-9]/gi,'-');
    grid.innerHTML += '<div class="ap-result-item" id="'+rid+'">'
      +'<div class="ap-result-domain" title="'+d+'">'+d+'</div>'
      +'<div class="ap-result-status">queued</div></div>';
  });
  document.getElementById('sec5-meta').textContent = '0 / '+total;

  var CHUNK_SIZE = 50;
  apLog('info','Processing '+total+' domains in chunks of '+CHUNK_SIZE+' (prevents timeout)');

  for(var i=0; i<AP.domainList.length; i++){
    var domain = AP.domainList[i];
    var rid2   = 'ri-'+domain.replace(/[^a-z0-9]/gi,'-');
    var row    = document.getElementById(rid2);
    apLogSep('['+(i+1)+'/'+total+'] '+domain);
    try{
      setProgress((i/total)*100, '['+(i+1)+'/'+total+'] '+domain);
      if(row) row.querySelector('.ap-result-status').textContent = 'processing...';

      var data  = csvData[domain] || buildFallbackData(domain);
      var dataSource = data.parse_source || (csvData[domain] ? 'csv' : 'fallback');
      if(dataSource === 'fallback') processStats.fallback++;

      apLog('domain','['+domain+'] Data source: '+dataSource,
        'city='+(data.daerah||'?')+' | address='+(data.alamat?'✓':'✗')+' | province='+(data.provinsi||'?'));

      var rules = buildReplaceRules(AP.mapping, data, domain);
      apLog('replace','Rules: '+Object.keys(rules).length+' find/replace pairs');

      var result    = applyRules(AP.templateFiles, rules);
      var modified  = result.modified;
      var hitCount  = result.hits;
      totalOps     += hitCount;
      apLog('ok','Replace done',hitCount+' substitutions');

      var missed = verifyReplacements(modified, rules);
      if(missed.length){
        processStats.warnings++;
        apLog('warn','['+domain+'] Second pass: '+missed.length+' string(s) still present', missed.slice(0,2).join(' | ').slice(0,60));
        var rules2 = {};
        missed.forEach(function(s){if(rules[s]) rules2[s]=rules[s];});
        var r2 = applyRules(modified, rules2);
        modified  = r2.modified;
        totalOps += r2.hits;
        var still = verifyReplacements(modified, rules2);
        if(still.length){
          var hasEmbedMiss = still.some(function(s){return s.includes('maps.google') || s.includes('output=embed') || s.includes('maps/embed');});
          if(hasEmbedMiss) apLog('warn','['+domain+'] embedmap: HTML-encoded variant (&amp;) - auto-handled');
          apLog('warn','['+domain+'] Missed after 2 passes: '+still.length, still.slice(0,2).join(' | ').slice(0,80));
        } else {
          apLog('ok','['+domain+'] All strings replaced on second pass');
        }
      } else {
        apLog('ok','['+domain+'] Verify passed - all strings replaced');
      }

      apLog('zip','['+domain+'] Zipping '+Object.keys(modified).length+' files → '+domain+'.zip ...');
      var t2 = performance.now();
      var fileCount = await writeToOutputFolder(AP.outputHandle, domain, modified, AP.binaryFiles);
      var elapsed2  = ((performance.now()-t2)/1000).toFixed(2);
      apLog('ok','['+domain+'] ✅ ZIP done: '+fileCount+' files ('+elapsed2+'s)', AP.outputHandle.name+'/');

      if(row){
        row.classList.add('done');
        row.querySelector('.ap-result-status').textContent = fileCount+' files → '+domain+'.zip';
        row.innerHTML += '<div style="font-family:\'JetBrains Mono\',monospace;font-size:9px;color:var(--ap-ok);margin-top:4px;">'+domain+'.zip saved to output folder</div>';
      }
      done++;
      processStats.zipped++;

      var elapsed = ((performance.now()-startTime)/1000);
      var avgPerDomain = elapsed/(i+1);
      var remaining = Math.ceil((total-(i+1))*avgPerDomain);
      var etaMin = Math.floor(remaining/60);
      var etaSec = remaining % 60;
      var etaText = etaMin > 0 ? etaMin+'m '+etaSec+'s' : etaSec+'s';

      var statsText = '✅ '+done+' | ⚠️ '+processStats.fallback+' | ❌ '+errors;
      if(remaining > 5) statsText += ' | ETA ~'+etaText;

      document.getElementById('sec5-meta').textContent = statsText;
      setProgress(((i+1)/total)*100, statsText);

    } catch(e){
      if(row){ row.classList.add('err'); row.querySelector('.ap-result-status').textContent = '! '+e.message.slice(0,30); }
      apLog('err','['+domain+'] ❌ Error: '+e.message);
      errors++;
      failedDomainsList.push({domain: domain, error: e.message});
    }

    await new Promise(function(r){setTimeout(r,50);});

    if((i+1) % CHUNK_SIZE === 0 && i+1 < total){
      apLog('info','Chunk complete: '+(i+1)+'/'+total+' — yielding to browser (prevents freeze)');
      await new Promise(function(r){setTimeout(r,800);});

      // Memory reporting
      if(performance.memory && performance.memory.usedJSHeapSize){
        var memMB = (performance.memory.usedJSHeapSize/1024/1024).toFixed(1);
        apLog('dim','Memory: '+memMB+' MB');
      }

      // Trigger garbage collection hint (browser may ignore)
      if(typeof gc !== 'undefined'){
        try{ gc(); apLog('dim','GC triggered'); }catch(e){}
      }
    }

    // Light cleanup every 10 domains
    if((i+1) % 10 === 0){
      modified = null;
      rules = null;
      data = null;
    }
  }

  apLogSep('COMPLETE');
  setProgress(100,'Done!');

  var totalElapsed = ((performance.now()-startTime)/1000);
  var elapsedMin = Math.floor(totalElapsed/60);
  var elapsedSec = Math.floor(totalElapsed % 60);
  var elapsedText = elapsedMin > 0 ? elapsedMin+'m '+elapsedSec+'s' : elapsedSec+'s';
  var avgTime = (totalElapsed/total).toFixed(1);

  apLog('done','✅ Pipeline Complete!', total+' domains processed in '+elapsedText);
  apLog('ok','Success: '+done+' domains ('+processStats.zipped+' ZIP files created)');
  if(processStats.fallback > 0) apLog('warn','Fallback data: '+processStats.fallback+' domains (no CSV/AI data)');
  if(processStats.warnings > 0) apLog('warn','Warnings: '+processStats.warnings+' domains had replacement issues');
  if(errors > 0){
    apLog('err','Failed: '+errors+' domains');
    if(failedDomainsList.length > 0 && failedDomainsList.length <= 10){
      failedDomainsList.forEach(function(f){
        apLog('err','  • '+f.domain+' → '+f.error.slice(0,60));
      });
    } else if(failedDomainsList.length > 10){
      apLog('err','Failed domains (showing first 10):');
      failedDomainsList.slice(0,10).forEach(function(f){
        apLog('err','  • '+f.domain+' → '+f.error.slice(0,60));
      });
      apLog('err','  ... and '+(failedDomainsList.length-10)+' more');
    }
  }
  apLog('info','Performance: '+avgTime+'s avg per domain | '+totalOps.toLocaleString()+' total substitutions');
  apLog('ok','Output: '+AP.outputHandle.name+'/ on your PC → ready to deploy 🚀');

  stepDone(5); stepDone(6);
  if(chip){ chip.textContent='Done'; chip.style.borderColor='rgba(0,230,118,.4)'; chip.style.color='var(--ap-ok)'; }
  document.getElementById('done-summary').style.display = 'block';
  document.getElementById('done-count').textContent = done;
  document.getElementById('done-folder').textContent = AP.outputHandle.name;
}

// Write modified files directly to a subfolder in the output dir
// Build a ZIP for one domain, write single .zip file to output folder
// textFiles: {path -> string}, binaryFiles: {path -> ArrayBuffer}
async function writeToOutputFolder(outputHandle, domain, textFiles, binaryFiles){
  var zip = new JSZip();
  var count = 0;

  // Add text files (replacement already applied)
  for(var path in textFiles){
    zip.file(path, textFiles[path]);
    count++;
  }
  // Add binary files as exact copy
  for(var path in binaryFiles){
    zip.file(path, binaryFiles[path]);
    count++;
  }

  // Generate ZIP blob
  var blob = await zip.generateAsync({
    type: 'blob',
    compression: 'DEFLATE',
    compressionOptions: {level: 6}
  });

  // Write single .zip file to output folder
  var zipName = domain + '.zip';
  var fileHandle = await outputHandle.getFileHandle(zipName, {create:true});
  var writable = await fileHandle.createWritable();
  await writable.write(blob);
  await writable.close();

  return count;
}

// ---- DOMAIN DATA ----------------------------------------------------------
// NEW QUEUE-BASED SYSTEM - Handles unlimited domains without timeout
async function fetchDomainDataQueue(domains, keywordHint, userHints){
  try{
    // Step 1: Create queue job
    apLog('net','Preparing '+domains.length+' domain(s) for processing...');
    var t0 = performance.now();

    var initRes = await fetch('/api/autopilot_data.php',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({
        domains: domains,
        keyword_hint: keywordHint || '',
        user_hints: userHints || ''
      })
    }).catch(function(err){
      throw new Error('API endpoint not reachable');
    });

    if(!initRes.ok){
      throw new Error('API initialization failed (HTTP '+initRes.status+')');
    }

    var initData = await initRes.json().catch(function(){
      throw new Error('Invalid API response');
    });

    if(!initData.ok){
      throw new Error(initData.msg || 'Failed to initialize data fetch');
    }

    var jobId = initData.job_id;
    apLog('ok','✓ Ready to fetch data for '+initData.total_domains+' domains');

    // Step 2: Process queue in chunks with progress tracking
    var completed = false;
    var processedCount = 0;
    var totalDomains = initData.total_domains;
    var allData = {};

    var retryCount = 0;
    var maxRetries = 2; // Reduced from 3 to fail faster
    var maxLoopIterations = 30; // Prevent infinite loop (max 30 iterations)
    var loopCount = 0;
    var lastProgress = 0;

    while(!completed && loopCount < maxLoopIterations){
      loopCount++;

      try {
        // Process next chunk
        var processRes = await fetch('/api/autopilot_queue_process.php',{
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({
            job_id: jobId,
            chunk_size: 10
          })
        });

        if(!processRes.ok){
          throw new Error('Network error');
        }

        var processData = await processRes.json();
        if(!processData.ok){
          throw new Error(processData.msg || 'Processing error');
        }

        // Reset retry count on success
        retryCount = 0;

        processedCount = processData.processed;
        var progress = processData.progress;

        // Show progress (only if changed significantly)
        if(processData.completed){
          completed = true;
          allData = processData.data || {};

          // Count quality data
          var qualityCount = 0;
          var fallbackCount = 0;
          for(var d in allData){
            if(allData[d].has_quality_data || allData[d].alamat || allData[d].parse_source === 'csv'){
              qualityCount++;
            } else {
              fallbackCount++;
            }
          }

          var elapsed = ((performance.now()-t0)/1000).toFixed(1);
          apLog('ok','Data fetch complete',processedCount+'/'+totalDomains+' domains | '+elapsed+'s');

          // Show quality stats if we have meaningful data
          if(qualityCount > 0 || fallbackCount > 0){
            var qualityPercent = ((qualityCount/totalDomains)*100).toFixed(0);
            apLog('ok','Data quality',qualityCount+' with full data ('+qualityPercent+'%) | '+fallbackCount+' with fallback data');
          }
        } else {
          // Only log every 10% progress or major milestones
          if(progress - lastProgress >= 10 || processedCount === 10){
            apLog('net','Fetching data... '+processedCount+'/'+totalDomains+' ('+progress+'%)');
            lastProgress = progress;
          }

          // Small delay before next chunk
          await new Promise(function(r){setTimeout(r,200);});
        }

      } catch(chunkError) {
        retryCount++;

        // If max retries reached, STOP and throw error to trigger fallback
        if(retryCount >= maxRetries){
          throw new Error('API queue system unavailable after '+maxRetries+' retries');
        }

        // Wait before retry (shorter delay)
        await new Promise(function(r){setTimeout(r,500);});
      }
    }

    // If we hit max iterations without completion, throw error
    if(!completed){
      throw new Error('API queue processing timeout');
    }

    return allData;

  } catch(e){
    apLog('warn','API unavailable - using smart fallback parsing');
    // Return empty object to trigger smart fallback for each domain
    return {};
  }
}

// LEGACY FALLBACK - Keep old system as backup
async function fetchDomainData(domains){
  try{
    var res = await fetch('/api/autopilot_data.php',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({domains:domains})
    });
    var d = await res.json();
    if(d.ok) return d.data||{};
    apLog('warn','Data API: '+(d.msg||'error'));
    return {};
  } catch(e){
    apLog('warn','Domain API unreachable - using fallback data');
    return {};
  }
}

// NEW: Main entry point - uses queue system by default
async function fetchDomainDataChunked(domains, keywordHint, userHints){
  // For 20+ domains, use professional queue system
  if(domains.length >= 20){
    return await fetchDomainDataQueue(domains, keywordHint, userHints);
  }

  // For small batches, use direct fetch
  apLog('net','Requesting data for '+domains.length+' domain(s)...');
  var t0 = performance.now();
  var result = await fetchDomainData(domains);
  var elapsed = ((performance.now()-t0)/1000).toFixed(1);
  apLog('ok','Data fetch complete',Object.keys(result).length+'/'+domains.length+' domains | '+elapsed+'s');
  return result;
}

async function fetchDomainDataChunkedLegacy(domains){
  var CHUNK_SIZE = 100;
  var allData = {};
  var totalChunks = Math.ceil(domains.length/CHUNK_SIZE);
  var stats = {success:0, fallback:0, failed:0};
  var failedDomains = [];

  if(domains.length <= CHUNK_SIZE){
    return await fetchDomainData(domains);
  }

  apLog('info','Fetching data in '+totalChunks+' chunks ('+CHUNK_SIZE+' domains/chunk)');

  for(var i=0; i<domains.length; i+=CHUNK_SIZE){
    var chunk = domains.slice(i, Math.min(i+CHUNK_SIZE, domains.length));
    var chunkNum = Math.floor(i/CHUNK_SIZE)+1;
    var preview = chunk.slice(0,3).join(', ')+(chunk.length>3?' ...':'');

    apLog('net','[Chunk '+chunkNum+'/'+totalChunks+'] Fetching '+chunk.length+' domains → '+preview);
    var t0 = performance.now();

    try{
      var res = await fetch('/api/autopilot_data.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({domains:chunk})
      });
      var d = await res.json();
      var elapsed = ((performance.now()-t0)/1000).toFixed(1);

      if(d.ok && d.data){
        var received = Object.keys(d.data).length;
        var missing = chunk.length - received;

        chunk.forEach(function(domain){
          if(d.data[domain]){
            if(d.data[domain].parse_source === 'ai' || d.data[domain].alamat){
              stats.success++;
            } else {
              stats.fallback++;
            }
          } else {
            stats.failed++;
            failedDomains.push(domain);
          }
        });

        Object.assign(allData, d.data);

        var statusLine = '✅ '+received+' OK';
        if(missing > 0) statusLine += ' | ⚠️ '+missing+' fallback';
        statusLine += ' | '+elapsed+'s';

        apLog('ok','[Chunk '+chunkNum+'/'+totalChunks+'] '+statusLine);
      } else {
        stats.failed += chunk.length;
        failedDomains.push.apply(failedDomains, chunk);
        apLog('err','[Chunk '+chunkNum+'/'+totalChunks+'] API error: '+(d.msg||'unknown'));
      }
    } catch(e){
      stats.failed += chunk.length;
      failedDomains.push.apply(failedDomains, chunk);
      apLog('err','[Chunk '+chunkNum+'/'+totalChunks+'] Network error: '+e.message);
    }

    if(i+CHUNK_SIZE < domains.length){
      await new Promise(function(r){setTimeout(r,200);});
    }
  }

  var successRate = ((stats.success/domains.length)*100).toFixed(1);
  apLog('ok','Data fetch complete: ✅ '+stats.success+' ('+successRate+'%) | ⚠️ '+stats.fallback+' fallback | ❌ '+stats.failed+' failed');

  if(failedDomains.length > 0 && failedDomains.length <= 10){
    apLog('warn','Failed domains: '+failedDomains.join(', '));
  } else if(failedDomains.length > 10){
    apLog('warn','Failed domains: '+failedDomains.slice(0,10).join(', ')+' ... and '+(failedDomains.length-10)+' more');
  }

  // RETRY MECHANISM - Auto retry failed domains (max 2 retries)
  if(failedDomains.length > 0){
    var MAX_RETRIES = 2;
    var retryAttempt = 0;
    var stillFailed = failedDomains.slice();

    while(retryAttempt < MAX_RETRIES && stillFailed.length > 0){
      retryAttempt++;
      apLogSep('RETRY #'+retryAttempt);
      apLog('retry','Retrying '+stillFailed.length+' failed domain(s) — Attempt '+retryAttempt+'/'+MAX_RETRIES);

      var retryFailed = [];
      var retrySuccess = 0;

      for(var ri=0; ri<stillFailed.length; ri+=CHUNK_SIZE){
        var retryChunk = stillFailed.slice(ri, Math.min(ri+CHUNK_SIZE, stillFailed.length));
        var retryChunkNum = Math.floor(ri/CHUNK_SIZE)+1;
        var retryPreview = retryChunk.slice(0,3).join(', ')+(retryChunk.length>3?' ...':'');

        apLog('retry','[Retry '+retryChunkNum+'] '+retryChunk.length+' domains → '+retryPreview);
        var rt0 = performance.now();

        try{
          var rres = await fetch('/api/autopilot_data.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({domains:retryChunk})
          });
          var rd = await rres.json();
          var relapsed = ((performance.now()-rt0)/1000).toFixed(1);

          if(rd.ok && rd.data){
            var recovered = Object.keys(rd.data).length;
            retrySuccess += recovered;
            Object.assign(allData, rd.data);

            retryChunk.forEach(function(domain){
              if(!rd.data[domain]){
                retryFailed.push(domain);
              }
            });

            apLog('ok','[Retry '+retryChunkNum+'] ✅ '+recovered+' recovered | '+relapsed+'s');
          } else {
            retryFailed.push.apply(retryFailed, retryChunk);
            apLog('err','[Retry '+retryChunkNum+'] Still failed');
          }
        } catch(e){
          retryFailed.push.apply(retryFailed, retryChunk);
          apLog('err','[Retry '+retryChunkNum+'] Error: '+e.message);
        }

        await new Promise(function(r){setTimeout(r,300);});
      }

      stillFailed = retryFailed;

      if(retrySuccess > 0){
        apLog('ok','Retry #'+retryAttempt+' complete: ✅ '+retrySuccess+' recovered | ❌ '+stillFailed.length+' still failed');
      } else {
        apLog('warn','Retry #'+retryAttempt+' complete: No domains recovered');
      }

      if(stillFailed.length === 0){
        apLog('ok','All failed domains recovered! 🎉');
        break;
      }
    }

    // Final failed domain report
    if(stillFailed.length > 0){
      apLog('err','Final failed domains after '+MAX_RETRIES+' retries: '+stillFailed.length);
      if(stillFailed.length <= 20){
        apLog('err','Failed list: '+stillFailed.join(', '));
      } else {
        apLog('err','Failed list: '+stillFailed.slice(0,20).join(', ')+' ... and '+(stillFailed.length-20)+' more');
      }
    }
  }

  return allData;
}

// SMART DOMAIN PARSER - Mimics PHP parseDomain() logic
// Extracts proper location name from domain using keyword hint
function buildFallbackData(domain){
  var slug = domain.replace(/\.[^.]+$/,''); // Remove TLD
  var parsed = smartParseDomain(slug, AP.keywordHint || '');

  return {
    namalink: domain,
    namalinkurl: 'https://' + domain,
    daerah: parsed.locationDisplay, // ✅ Now uses smart parsing!
    email: slug + '@gmail.com',
    alamat: '',
    linkmaps: '',
    embedmap: '',
    provinsi: '',
    kodepos: '',
    namainstansi: parsed.institution + ' ' + parsed.locationDisplay,
    source: 'fallback'
  };
}

// Smart domain parser with keyword hint support
function smartParseDomain(slug, keywordHint){
  // Remove common prefixes/suffixes
  var clean = slug.toLowerCase();

  // If keyword hint provided, strip it from slug
  if(keywordHint){
    var kwNorm = keywordHint.toLowerCase().replace(/[-_]/g, '');
    var kwPos = clean.indexOf(kwNorm);
    if(kwPos !== -1){
      // Found keyword, remove it
      clean = clean.substring(0, kwPos) + clean.substring(kwPos + kwNorm.length);
    }
  }

  // Remove common modifiers (kab, kota, kab/kota prefixes/suffixes)
  var geoMod = '';
  if(clean.endsWith('kab')){
    geoMod = 'Kab. ';
    clean = clean.substring(0, clean.length - 3);
  } else if(clean.endsWith('kota')){
    geoMod = 'Kota ';
    clean = clean.substring(0, clean.length - 4);
  } else if(clean.startsWith('kabkota')){
    geoMod = 'Kab. ';
    clean = clean.substring(7);
  } else if(clean.startsWith('kab')){
    geoMod = 'Kab. ';
    clean = clean.substring(3);
  } else if(clean.startsWith('kota')){
    geoMod = 'Kota ';
    clean = clean.substring(4);
  }

  // Capitalize properly (handle multi-word locations)
  var locationDisplay = capitalizeLocation(clean);
  if(geoMod) locationDisplay = geoMod + locationDisplay;

  // Institution name from keyword hint
  var institution = keywordHint ? keywordHint.toUpperCase() : '';

  return {
    locationDisplay: locationDisplay,
    institution: institution,
    cleanSlug: clean
  };
}

// Capitalize location name properly
// Examples: "gorontalo" → "Gorontalo", "bandarbaru" → "Bandar Baru"
function capitalizeLocation(str){
  if(!str) return '';

  // Common Indonesian location word boundaries
  var parts = str.match(/(bandar|tanjung|sungai|kuala|teluk|batang|pulau|kota|kabupaten)(.+)|(.+)/i);

  if(parts && parts[1] && parts[2]){
    // Has prefix like "bandar", "tanjung", etc
    return capitalize(parts[1]) + ' ' + capitalize(parts[2]);
  }

  // Single word - just capitalize
  return capitalize(str);
}

function capitalize(word){
  if(!word) return '';
  return word.charAt(0).toUpperCase() + word.slice(1);
}

// ---- BUILD REPLACE RULES ---------------------------------------------------
// Rules: {oldValue: newValue}
// For each field detected in the template, find all old values and map to new
function buildReplaceRules(mapping, data, domain){
  var rules = {};
  var slug  = domain.replace(/\.[^.]+$/,'');

  function addRule(find, replace){
    if(find && replace && find !== replace) rules[find] = replace;
  }

  for(var field in mapping){
    var oldVals = mapping[field];
    var newVal  = '';

    switch(field){
      case 'namalinkurl':
        // Replace every URL variant preserving the path
        var seen = {};
        oldVals.forEach(function(old){
          var path = old.replace(/^https?:\/\/[^\/]+/, '');
          var newUrl = 'https://'+domain+path;
          if(!seen[old]){ addRule(old, newUrl); seen[old]=1; }
        });
        // Also add bare domain variants
        var oldDomain = AP.domainList[0] && oldVals[0]
          ? (oldVals[0].match(/^https?:\/\/([^\/]+)/)||[])[1] : '';
        if(oldDomain && oldDomain !== domain){
          if(!seen[oldDomain])       { addRule(oldDomain, domain); seen[oldDomain]=1; }
          if(!seen['https://'+oldDomain]) { addRule('https://'+oldDomain, 'https://'+domain); }
          if(!seen['https://'+oldDomain+'/']){ addRule('https://'+oldDomain+'/', 'https://'+domain+'/'); }
        }
        continue;

      case 'namalink':
        // Replace slug/domain-as-text
        oldVals.forEach(function(old){
          // If old value looks like a full domain (contains dot), use new domain
          if(old.includes('.')){
            addRule(old, domain);
          } else {
            // It's a slug - use new slug
            addRule(old, slug);
          }
        });
        continue;

      case 'daerah':
        newVal = data.daerah || data.daerah_short || slug;
        break;

      case 'email':
        newVal = data.email || (slug+'@gmail.com');
        break;

      case 'alamat':
        newVal = data.alamat || '';
        break;

      case 'embedmap':
        newVal = data.embedmap || '';
        // Special: also replace any iframe wrapper pattern that contains the old embed
        // Handle both encoded (&amp;) and raw (&) versions
        if(newVal){
          oldVals.forEach(function(old){
            // add both encoded/decoded variants explicitly for reliable replace
            var decoded = old.replace(/&amp;/g,'&');
            var encoded = decoded.replace(/&/g,'&amp;');
            if(decoded !== old) addRule(decoded, newVal.replace(/&amp;/g,'&'));
            if(encoded !== old) addRule(encoded, newVal.replace(/&/g,'&amp;'));
          });
        }
        break;

      case 'linkmaps':
        newVal = data.linkmaps || '';
        break;

      case 'provinsi':
        newVal = data.provinsi || '';
        break;

      case 'kodepos':
        newVal = data.kodepos || '';
        break;

      case 'notelp':
        newVal = data.notelp || '';
        break;

      case 'namainstansi':
        var instBase  = data.institution_full || data.institution || '';
        var daerahPart= data.daerah || '';
        newVal = (instBase && daerahPart && instBase.toLowerCase().indexOf(daerahPart.toLowerCase()) === -1)
          ? instBase+' '+daerahPart : instBase;
        break;

      default:
        newVal = data[field] || '';
    }

    if(!newVal) continue;
    oldVals.forEach(function(old){ addRule(old, newVal); });
  }
  return rules;
}

// ---- EXPAND RULES WITH CASE VARIANTS ---------------------------------------
// For daerah/namainstansi: if template has "Balikpapan", auto-add rules for
// "balikpapan" -> "makassar" and "BALIKPAPAN" -> "MAKASSAR" etc.
function expandRulesWithCaseVariants(rules){
  var expanded = Object.assign({}, rules);
  Object.entries(rules).forEach(function(pair){
    var find=pair[0], rep=pair[1];
    // Only expand pure-text values (not URLs, emails, addresses, phone)
    if(find.includes('://') || find.includes('@') || find.includes('Jl') ||
       /\d{5,}/.test(find) || find.startsWith('+') || find.startsWith('62')) return;
    // Title Case
    var titleFind = find.charAt(0).toUpperCase() + find.slice(1).toLowerCase();
    var titleRep  = rep.charAt(0).toUpperCase() + rep.slice(1).toLowerCase();
    if(titleFind !== find && !expanded[titleFind]) expanded[titleFind] = titleRep;
    // UPPER
    var upperFind = find.toUpperCase();
    var upperRep  = rep.toUpperCase();
    if(upperFind !== find && !expanded[upperFind]) expanded[upperFind] = upperRep;
    // lower
    var lowerFind = find.toLowerCase();
    var lowerRep  = rep.toLowerCase();
    if(lowerFind !== find && !expanded[lowerFind]) expanded[lowerFind] = lowerRep;
  });
  return expanded;
}

// ---- APPLY RULES -----------------------------------------------------------
function htmlEncode(s){ return s.replace(/&/g,'&amp;'); }
function htmlDecode(s){ return s.replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&quot;/g,'"'); }

function applyRules(files, rules){
  // Expand with case variants for text fields
  var allRules = expandRulesWithCaseVariants(rules);

  // Also add HTML-encoded variants for URL rules (& -> &amp;)
  // Template might store URLs as &amp; inside HTML attributes
  var extraRules = {};
  Object.entries(allRules).forEach(function(pair){
    var find=pair[0], rep=pair[1];
    if(find.includes('&') && !find.includes('&amp;')){
      // Add encoded version: find &amp; version -> replace with &amp; version of new value
      extraRules[htmlEncode(find)] = htmlEncode(rep);
    }
    if(find.includes('&amp;')){
      // Also add decoded version in case it appears raw
      extraRules[htmlDecode(find)] = htmlDecode(rep);
    }
  });
  Object.assign(allRules, extraRules);

  var modified = {}, hits = 0;
  // Sort by length descending to avoid partial replacements
  var sorted = Object.entries(allRules).sort(function(a,b){return b[0].length-a[0].length;});
  for(var path in files){
    var content = files[path];
    sorted.forEach(function(pair){
      var find=pair[0], rep=pair[1];
      if(!content.includes(find)) return;
      var count = content.split(find).length - 1;
      content = content.split(find).join(rep);
      hits += count;
    });
    modified[path] = content;
  }
  return {modified:modified, hits:hits};
}

function verifyReplacements(files, rules){
  var all = Object.values(files).join('\n');
  return Object.keys(rules).filter(function(f){ return all.includes(f); });
}
</script>


</body></html>
