<?php
require_once dirname(__DIR__).'/config.php';
startSession();
requireLogin();
$user=currentUser();
$quota=getUserQuota($user['id']);
$plan=getPlan($user['plan']);
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>BulkReplace Tool</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>
.tool-wrap{max-width:1100px;margin:0 auto;padding:28px 24px;}
.tool-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px;}
.panel{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:28px;margin-bottom:20px;display:none;}
.panel.visible{display:block;animation:fadeUp .25s ease;}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:none;}}
.steps-nav{display:flex;gap:0;margin-bottom:28px;background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;padding:6px;}
.step-tab{flex:1;display:flex;align-items:center;justify-content:center;gap:8px;padding:10px 8px;font-family:'JetBrains Mono',monospace;font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);border-radius:10px;cursor:pointer;transition:all .2s;user-select:none;position:relative;}
.step-tab.active{background:linear-gradient(135deg,rgba(240,165,0,.15),rgba(240,165,0,.05));color:var(--a1);border:1px solid rgba(240,165,0,.2);}
.step-tab.done{color:var(--ok);}
.step-tab.done::after{content:"✓";position:absolute;top:6px;right:8px;font-size:9px;}
.step-num{width:20px;height:20px;border-radius:50%;background:var(--dim);border:1px solid var(--border2);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;}
.step-tab.active .step-num{background:var(--a1);border-color:var(--a1);color:#000;}
.step-tab.done .step-num{background:var(--ok);border-color:var(--ok);color:#000;}
.drop-zone{border:2px dashed var(--border2);border-radius:14px;padding:40px 24px;text-align:center;cursor:pointer;transition:all .2s;position:relative;}
.drop-zone:hover,.drop-zone.drag-over{border-color:rgba(240,165,0,.4);background:rgba(240,165,0,.02);}
.picker-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
@media(max-width:600px){.picker-grid{grid-template-columns:1fr;}}
.picker-card{background:var(--dim);border:1px solid var(--border2);border-radius:14px;padding:24px;text-align:center;cursor:pointer;transition:all .2s;position:relative;}
.picker-card:hover{border-color:rgba(240,165,0,.3);}
.picker-card.selected{border-color:var(--a1);background:rgba(240,165,0,.06);}
.ext-wrap{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;}
.ext-chip{display:flex;align-items:center;gap:6px;background:rgba(240,165,0,.08);border:1px solid rgba(240,165,0,.2);border-radius:7px;padding:5px 12px;font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--a1);cursor:pointer;user-select:none;transition:all .15s;}
.ext-chip.off{background:var(--dim);border-color:var(--border);color:var(--muted);}
.pmap{display:flex;flex-direction:column;gap:8px;max-height:280px;overflow-y:auto;}
.pmap-row{display:grid;grid-template-columns:1fr 28px 1fr;align-items:center;gap:8px;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:8px;padding:8px 12px;font-family:'JetBrains Mono',monospace;font-size:11px;}
.pmap-key{color:var(--warn);background:rgba(255,215,64,.07);border:1px solid rgba(255,215,64,.15);padding:3px 8px;border-radius:5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.pmap-val{color:var(--a2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.prog-track{background:rgba(255,255,255,.05);border-radius:100px;height:6px;overflow:hidden;}
.prog-bar{height:100%;border-radius:100px;background:linear-gradient(90deg,var(--a1),var(--a2));transition:width .3s;}
.prog-bar.done{background:linear-gradient(90deg,var(--ok),var(--a2));}
.log-box{background:#04040e;border:1px solid var(--border);border-radius:12px;padding:14px 16px;max-height:360px;overflow-y:auto;font-family:'JetBrains Mono',monospace;font-size:11px;line-height:1.9;}
.log-line{display:flex;gap:8px;align-items:baseline;}
.log-ts{color:var(--muted);font-size:9px;flex-shrink:0;}
.stats-run{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:20px;}
@media(max-width:600px){.stats-run{grid-template-columns:repeat(3,1fr);}}
.sr-box{background:var(--dim);border:1px solid var(--border);border-radius:10px;padding:12px;text-align:center;}
.sr-val{font-family:'Syne',sans-serif;font-size:24px;font-weight:800;color:#fff;}
.sr-label{font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-top:4px;}
@media(max-width:700px){.step-label{display:none;}}
</style>
</head>
<body>
<div id="toast-wrap"></div>
<div class="tool-wrap">
  <!-- HEADER -->
  <div class="tool-header">
    <div style="display:flex;align-items:center;gap:14px;">
      <a href="/dashboard/" style="color:var(--muted);text-decoration:none;font-family:'JetBrains Mono',monospace;font-size:11px;">← Dashboard</a>
      <div style="font-family:'Syne',sans-serif;font-size:22px;font-weight:800;color:#fff;">⚡ BulkReplace Tool</div>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
      <?php if(!$quota['unlimited']): ?>
      <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);">
        Quota: <b style="color:<?= $quota['remaining']<50?'var(--err)':'var(--a1)' ?>;"><?= number_format($quota['remaining']) ?> rows</b> remaining
        <?php if($quota['rollover']>0): ?><span style="color:var(--ok);"> (+<?= $quota['rollover'] ?> rollover)</span><?php endif; ?>
      </div>
      <?php if($user['plan']==='free'): ?>
      <a href="/landing/pricing.php" class="btn btn-amber btn-sm">⚡ Upgrade</a>
      <?php endif; ?>
      <?php else: ?>
      <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--purple);">♾️ Unlimited Quota</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- STEPS NAV -->
  <div class="steps-nav">
    <div class="step-tab active" id="tab-1" onclick="goStep(1)"><div class="step-num">1</div><span class="step-label">Load CSV</span></div>
    <div class="step-tab" id="tab-2" onclick="goStep(2)"><div class="step-num">2</div><span class="step-label">Pick Folder</span></div>
    <div class="step-tab" id="tab-3" onclick="goStep(3)"><div class="step-num">3</div><span class="step-label">Configure</span></div>
    <div class="step-tab" id="tab-4" onclick="goStep(4)"><div class="step-num">4</div><span class="step-label">Run</span></div>
  </div>

  <!-- STEP 1: CSV -->
  <div class="panel visible" id="panel-1">
    <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:#fff;margin-bottom:6px;">📄 Load CSV</div>
    <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-bottom:20px;">Drag & drop your CSV or click to browse. Column headers become placeholders.</div>

    <div style="background:rgba(0,150,255,.08);border:1px solid rgba(0,150,255,.2);border-radius:10px;padding:14px 18px;margin-bottom:20px;">
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;color:rgba(100,180,255,1);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:8px;">💡 CSV Generator Placeholders</div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:rgba(150,200,255,1);line-height:1.7;">
        CSV generated from <b>CSV Generator Pro</b> uses safe placeholders like <code style="background:rgba(0,0,0,.3);padding:2px 6px;border-radius:4px;">{{'{{'}}fieldname123{{'}}'}}</code><br>
        <b>✓ Safe for bulk replace!</b> Double curly braces with random numbers (100-999) ensure no conflicts with your content.
      </div>
    </div>
    <div class="drop-zone" id="csv-drop" onclick="document.getElementById('csv-fi').click()">
      <input type="file" id="csv-fi" accept=".csv" style="display:none">
      <div style="font-size:42px;margin-bottom:12px;">📄</div>
      <div style="font-family:'Syne',sans-serif;font-size:16px;font-weight:700;color:#fff;margin-bottom:6px;">Drop CSV here</div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);">or click to browse — .csv only</div>
    </div>
    <div class="hidden" id="csv-preview" style="margin-top:20px;">
      <hr class="divider">
      <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-bottom:10px;display:flex;gap:16px;flex-wrap:wrap;">
        <span>Rows: <b style="color:var(--a1);" id="csv-rc">0</b></span>
        <span>Cols: <b style="color:var(--a1);" id="csv-cc">0</b></span>
        <span>File: <b style="color:var(--a1);" id="csv-fn">—</b></span>
      </div>
      <div style="overflow-x:auto;border:1px solid var(--border);border-radius:10px;max-height:180px;overflow-y:auto;"><table id="csv-tbl" style="width:100%;border-collapse:collapse;font-family:'JetBrains Mono',monospace;font-size:11px;"></table></div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;">
        <button class="btn btn-amber" onclick="goStep(2)">Next: Pick Folder →</button>
        <button class="btn btn-ghost btn-sm" onclick="resetCSV()">✕ Clear</button>
      </div>
    </div>
  </div>

  <!-- STEP 2: FOLDER -->
  <div class="panel" id="panel-2">
    <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:#fff;margin-bottom:6px;">📁 Select Folder</div>
    <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-bottom:20px;">Choose your base folder containing all subfolders. Nothing is uploaded to any server.</div>
    <div class="picker-grid">
      <div class="picker-card" id="pk-modern" onclick="pickModern()">
        <div style="position:absolute;top:10px;right:10px;"><span class="badge badge-ok" style="font-size:8px;">Recommended</span></div>
        <div style="font-size:36px;margin-bottom:12px;">🗂️</div>
        <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:6px;">Pick Folder</div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:1.7;">Chrome/Edge 86+<br>Edit files in place</div>
      </div>
      <div class="picker-card" id="pk-fallback" style="position:relative;">
        <input type="file" id="fallback-fi" webkitdirectory multiple style="position:absolute;inset:0;opacity:0;cursor:pointer;" onchange="pickFallback(this)">
        <div style="font-size:36px;margin-bottom:12px;">📂</div>
        <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:6px;">Browse Folder</div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:1.7;">All browsers<br>Downloads as ZIP</div>
      </div>
    </div>
    <div class="hidden" id="folder-info" style="background:var(--dim);border:1px solid rgba(0,230,118,.2);border-radius:10px;padding:14px 18px;margin-top:16px;display:flex;align-items:center;gap:12px;">
      <div style="font-size:22px;">✅</div>
      <div style="flex:1;"><div id="fp-display" style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--ok);"></div><div id="fm-display" style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:3px;"></div></div>
      <button class="btn btn-ghost btn-sm" onclick="resetFolder()">✕</button>
    </div>
    <div style="display:flex;gap:10px;margin-top:20px;">
      <button class="btn btn-ghost" onclick="goStep(1)">← Back</button>
      <button class="btn btn-amber" id="btn-s3" onclick="goStep(3)" disabled>Next: Configure →</button>
    </div>
  </div>

  <!-- STEP 3: CONFIGURE -->
  <div class="panel" id="panel-3">
    <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:#fff;margin-bottom:6px;">⚙️ Configure</div>
    <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-bottom:20px;">Set folder column and file extensions.</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
      <div class="form-field"><label class="form-label">Folder Name Column</label><select id="fc-sel"></select></div>
      <div class="form-field"><label class="form-label">Output Mode</label>
        <select id="out-mode">
          <option value="auto">Auto (In-Place if available)</option>
          <option value="zip">Always Download ZIP</option>
        </select>
      </div>
    </div>
    <div class="form-field">
      <label class="form-label">File Extensions</label>
      <div class="ext-wrap" id="ext-wrap"></div>
      <div style="display:flex;gap:8px;margin-top:8px;"><input type="text" id="ext-in" placeholder=".xml" style="max-width:120px;" onkeydown="if(event.key==='Enter')addExt()"><button class="btn btn-ghost btn-sm" onclick="addExt()">+ Add</button></div>
    </div>
    <hr class="divider">
    <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:10px;">Replacement Preview — Row 1</div>
    <div class="info-box">Column headers → replacement values. Each row maps to one subfolder.</div>
    <div class="pmap" id="pmap"></div>
    <div style="display:flex;gap:10px;margin-top:20px;">
      <button class="btn btn-ghost" onclick="goStep(2)">← Back</button>
      <button class="btn btn-amber" onclick="goStep(4)">Run →</button>
    </div>
  </div>

  <!-- STEP 4: RUN -->
  <div class="panel" id="panel-4">
    <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:#fff;margin-bottom:16px;">⚡ Processing</div>
    <div id="run-ready">
      <div class="warn-box" id="run-summary">Ready to process.</div>
      <div style="display:flex;gap:10px;margin-top:16px;">
        <button class="btn btn-ghost" onclick="goStep(3)">← Back</button>
        <button class="btn btn-amber" onclick="startRun()">⚡ Run Now</button>
      </div>
    </div>
    <div id="run-prog" class="hidden">
      <div style="margin-bottom:20px;">
        <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-bottom:8px;display:flex;justify-content:space-between;"><span>Processing...</span><b id="prog-pct">0%</b></div>
        <div class="prog-track"><div class="prog-bar" id="prog-bar"></div></div>
      </div>
      <div class="stats-run">
        <div class="sr-box"><div class="sr-val" style="color:var(--a1);" id="s-f">0</div><div class="sr-label">Folders</div></div>
        <div class="sr-box"><div class="sr-val" style="color:var(--ok);" id="s-u">0</div><div class="sr-label">Updated</div></div>
        <div class="sr-box"><div class="sr-val" id="s-s">0</div><div class="sr-label">Skipped</div></div>
        <div class="sr-box"><div class="sr-val" style="color:var(--warn);" id="s-m">0</div><div class="sr-label">Missing</div></div>
        <div class="sr-box"><div class="sr-val" style="color:var(--err);" id="s-e">0</div><div class="sr-label">Errors</div></div>
      </div>
      <div class="log-box" id="log-box"></div>
    </div>
    <div id="run-done" class="hidden">
      <div style="background:linear-gradient(135deg,rgba(0,230,118,.07),rgba(0,212,170,.04));border:1px solid rgba(0,230,118,.2);border-radius:16px;padding:32px;text-align:center;margin-bottom:20px;">
        <div style="font-size:52px;margin-bottom:12px;" id="done-icon">✅</div>
        <div style="font-family:'Syne',sans-serif;font-size:26px;font-weight:800;color:var(--ok);margin-bottom:8px;" id="done-title">All Done!</div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);line-height:1.8;" id="done-sub"></div>
      </div>
      <div class="stats-run">
        <div class="sr-box"><div class="sr-val" style="color:var(--a1);" id="ds-f">0</div><div class="sr-label">Folders</div></div>
        <div class="sr-box"><div class="sr-val" style="color:var(--ok);" id="ds-u">0</div><div class="sr-label">Updated</div></div>
        <div class="sr-box"><div class="sr-val" id="ds-s">0</div><div class="sr-label">Skipped</div></div>
        <div class="sr-box"><div class="sr-val" style="color:var(--warn);" id="ds-m">0</div><div class="sr-label">Missing</div></div>
        <div class="sr-box"><div class="sr-val" style="color:var(--err);" id="ds-e">0</div><div class="sr-label">Errors</div></div>
      </div>
      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
        <button class="btn btn-teal hidden" id="btn-dl" onclick="downloadZip()">📦 Download ZIP</button>
        <button class="btn btn-ghost" onclick="resetAll()">🔄 New Job</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script>
// ── STATE ──────────────────────────────────────────────
const QUOTA_LIMIT = <?= $quota['unlimited']?'Infinity':$quota['remaining'] ?>;
const PLAN = '<?= $user['plan'] ?>';
let csvData=[],csvHeaders=[],csvFilename='';
let folderHandle=null,fallbackFiles=null,folderMode=null,folderName='';
let extensions=['.html','.js','.css','.php','.txt'];
let zipResult=null,isRunning=false,currentStep=1;
const stats={f:0,u:0,s:0,m:0,e:0};

// ── STEPS ──────────────────────────────────────────────
function goStep(n){
  if(n===currentStep)return;
  if(n>1&&!csvData.length){toast('Load a CSV first','err');return;}
  if(n>2&&!folderHandle&&!fallbackFiles){toast('Select a folder first','err');return;}
  if(n===3)buildConfig();
  if(n===4)buildSummary();
  document.querySelectorAll('.panel').forEach(p=>p.classList.remove('visible'));
  document.getElementById('panel-'+n).classList.add('visible');
  document.querySelectorAll('.step-tab').forEach((t,i)=>{
    t.classList.remove('active','done');
    if(i+1<n)t.classList.add('done');
    if(i+1===n)t.classList.add('active');
  });
  currentStep=n;
}

// ── CSV ────────────────────────────────────────────────
document.getElementById('csv-fi').addEventListener('change',e=>{if(e.target.files[0])loadCSV(e.target.files[0]);});
const drop=document.getElementById('csv-drop');
drop.addEventListener('dragover',e=>{e.preventDefault();drop.classList.add('drag-over');});
drop.addEventListener('dragleave',()=>drop.classList.remove('drag-over'));
drop.addEventListener('drop',e=>{e.preventDefault();drop.classList.remove('drag-over');const f=e.dataTransfer.files[0];if(f&&f.name.endsWith('.csv'))loadCSV(f);else toast('Drop a .csv file','err');});

function loadCSV(file){csvFilename=file.name;const r=new FileReader();r.onload=e=>parseCSV(e.target.result);r.readAsText(file);}
function parseCSV(text){
  const lines=text.replace(/\r\n/g,'\n').replace(/\r/g,'\n').split('\n').filter(l=>l.trim());
  if(lines.length<2){toast('CSV needs headers + 1 row','err');return;}
  function pl(line){const res=[];let cur='',inQ=false;for(const ch of line){if(ch==='"'){inQ=!inQ;}else if(ch===','&&!inQ){res.push(cur.trim());cur='';}else cur+=ch;}res.push(cur.trim());return res;}
  csvHeaders=pl(lines[0]);csvData=[];
  for(let i=1;i<lines.length;i++){if(!lines[i].trim())continue;const v=pl(lines[i]);const row={};csvHeaders.forEach((h,j)=>row[h]=v[j]??'');csvData.push(row);}
  // Quota check
  if(QUOTA_LIMIT!==Infinity&&csvData.length>QUOTA_LIMIT){
    toast(`CSV has ${csvData.length} rows but only ${QUOTA_LIMIT} remaining. Upgrade plan.`,'warn');
  }
  renderCSVPreview();toast(`Loaded ${csvData.length} rows, ${csvHeaders.length} cols`,'ok');
}
function renderCSVPreview(){
  document.getElementById('csv-rc').textContent=csvData.length;
  document.getElementById('csv-cc').textContent=csvHeaders.length;
  document.getElementById('csv-fn').textContent=csvFilename;
  const tbl=document.getElementById('csv-tbl');
  tbl.innerHTML=`<thead style="background:rgba(240,165,0,.06);position:sticky;top:0;"><tr>${csvHeaders.map(h=>`<th style="padding:8px 12px;text-align:left;color:var(--a1);font-size:9px;text-transform:uppercase;letter-spacing:1.5px;border-bottom:1px solid var(--border);white-space:nowrap;">${esc(h)}</th>`).join('')}</tr></thead><tbody>${csvData.slice(0,8).map(r=>`<tr>${csvHeaders.map(h=>`<td style="padding:7px 12px;color:var(--text);border-bottom:1px solid rgba(30,30,58,.5);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(r[h])}">${esc(r[h])}</td>`).join('')}</tr>`).join('')}</tbody>`;
  document.getElementById('csv-preview').classList.remove('hidden');
}
function resetCSV(){csvData=[];csvHeaders=[];csvFilename='';document.getElementById('csv-preview').classList.add('hidden');document.getElementById('csv-fi').value='';}

// ── FOLDER ─────────────────────────────────────────────
async function pickModern(){
  if(!window.showDirectoryPicker){toast('Use Browse Folder instead','warn');return;}
  try{
    folderHandle=await window.showDirectoryPicker({mode:'readwrite'});
    folderMode='modern';folderName=folderHandle.name;
    let c=0;for await(const[,e]of folderHandle){if(e.kind==='directory')c++;}
    document.getElementById('pk-modern').classList.add('selected');
    document.getElementById('pk-fallback').classList.remove('selected');
    showFolderInfo(`${c} subfolders · Edit In Place available`);
    document.getElementById('btn-s3').disabled=false;
    toast(`Folder: ${folderName}`,'ok');
  }catch(e){if(e.name!=='AbortError')toast(e.message,'err');}
}
function pickFallback(inp){
  if(!inp.files||!inp.files.length)return;
  fallbackFiles=inp.files;folderMode='fallback';
  folderName=(fallbackFiles[0].webkitRelativePath||'').split('/')[0]||'folder';
  document.getElementById('pk-fallback').classList.add('selected');
  document.getElementById('pk-modern').classList.remove('selected');
  showFolderInfo(`${fallbackFiles.length} files loaded · ZIP output`);
  document.getElementById('btn-s3').disabled=false;
  toast(`${fallbackFiles.length} files loaded`,'ok');
}
function showFolderInfo(meta){
  const fi=document.getElementById('folder-info');
  fi.classList.remove('hidden');fi.style.display='flex';
  document.getElementById('fp-display').textContent=folderName;
  document.getElementById('fm-display').textContent=meta;
}
function resetFolder(){
  folderHandle=null;fallbackFiles=null;folderMode=null;folderName='';
  const fi=document.getElementById('folder-info');fi.classList.add('hidden');fi.style.display='none';
  document.getElementById('pk-modern').classList.remove('selected');
  document.getElementById('pk-fallback').classList.remove('selected');
  document.getElementById('fallback-fi').value='';
  document.getElementById('btn-s3').disabled=true;
}

// ── CONFIG ─────────────────────────────────────────────
function buildConfig(){
  const sel=document.getElementById('fc-sel');
  sel.innerHTML=csvHeaders.map(h=>`<option value="${esc(h)}">${esc(h)}</option>`).join('');
  renderExts();buildPmap();
  sel.onchange=buildPmap;
}
function renderExts(){
  const def=['.html','.js','.css','.php','.txt','.xml','.json'];
  const all=[...new Set([...extensions,...def])];
  document.getElementById('ext-wrap').innerHTML=all.map(e=>`<div class="ext-chip ${extensions.includes(e)?'':'off'}" onclick="toggleExt('${e}',this)">${esc(e)} <span>${extensions.includes(e)?'✓':'+'}</span></div>`).join('');
}
function toggleExt(ext,el){
  if(extensions.includes(ext)){extensions=extensions.filter(e=>e!==ext);el.classList.add('off');el.querySelector('span').textContent='+';}
  else{extensions.push(ext);el.classList.remove('off');el.querySelector('span').textContent='✓';}
}
function addExt(){let v=document.getElementById('ext-in').value.trim().toLowerCase();if(!v)return;if(!v.startsWith('.'))v='.'+v;if(!extensions.includes(v)){extensions.push(v);renderExts();}document.getElementById('ext-in').value='';}
function buildPmap(){
  const fc=document.getElementById('fc-sel').value;const row=csvData[0]||{};
  document.getElementById('pmap').innerHTML=csvHeaders.map(h=>`<div class="pmap-row"><div class="pmap-key">${esc(h)}</div><div style="color:var(--muted);text-align:center;font-size:16px;">→</div><div class="pmap-val ${h===fc?'folder-col':''}" style="${h===fc?'color:var(--a1);font-weight:700;':''}">${esc(row[h]||'')} ${h===fc?'<span style="color:var(--a1);font-size:9px;">[FOLDER]</span>':''}</div></div>`).join('');
}

// ── RUN SUMMARY ────────────────────────────────────────
function buildSummary(){
  const fc=document.getElementById('fc-sel')?.value||csvHeaders[0];
  const om=document.getElementById('out-mode')?.value||'auto';
  const out=(om==='zip'||folderMode==='fallback')?'Download ZIP':'Edit In Place';
  document.getElementById('run-summary').innerHTML=`📊 <b>${csvData.length}</b> rows · Folder col: <b>${esc(fc)}</b><br>📁 Base: <b>${esc(folderName)}</b><br>🔧 Extensions: <b>${extensions.join(', ')}</b><br>💾 Output: <b>${out}</b>`;
  // Quota warning
  if(QUOTA_LIMIT!==Infinity&&csvData.length>QUOTA_LIMIT){
    document.getElementById('run-summary').innerHTML+=`<br><span style="color:var(--err);">⚠ ${csvData.length} rows exceeds your remaining quota of ${QUOTA_LIMIT}. <a href="/landing/pricing.php" style="color:var(--a1);">Upgrade</a></span>`;
  }
}

// ── MAIN RUN ───────────────────────────────────────────
async function startRun(){
  if(isRunning)return;
  // Quota check before running
  if(QUOTA_LIMIT!==Infinity&&csvData.length>QUOTA_LIMIT){
    toast(`Quota exceeded: ${csvData.length} rows > ${QUOTA_LIMIT} remaining`,'err');return;
  }
  isRunning=true;zipResult=null;
  const fc=document.getElementById('fc-sel').value;
  const om=document.getElementById('out-mode').value;
  Object.keys(stats).forEach(k=>stats[k]=0);updateStats();
  document.getElementById('run-ready').classList.add('hidden');
  document.getElementById('run-prog').classList.remove('hidden');
  document.getElementById('run-done').classList.add('hidden');
  document.getElementById('log-box').innerHTML='';
  const useZip=om==='zip'||folderMode==='fallback';
  try{
    if(folderMode==='modern'&&!useZip)await runInPlace(fc);
    else await runZip(fc);
  }catch(e){log('err','⛔ '+e.message);toast(e.message,'err');}
  // Log usage to server
  await logUsage(csvData.length,stats.u);
  isRunning=false;
  showDone(useZip&&zipResult!==null);
}

async function runInPlace(fc){
  const tot=csvData.length;
  for(let i=0;i<csvData.length;i++){
    const row=csvData[i];const name=(row[fc]||'').trim();if(!name)continue;
    stats.f++;log('folder','📁 '+name);
    let sub;try{sub=await folderHandle.getDirectoryHandle(name);}
    catch(e){log('warn','  ⚠ Not found: '+name);stats.m++;updS();prog(i+1,tot);continue;}
    await walkPlace(sub,buildRep(row),name);
    prog(i+1,tot);await tick();
  }
}
async function runZip(fc){
  zipResult=new JSZip();const tot=csvData.length;let fm={};
  if(folderMode==='fallback')for(const f of fallbackFiles)fm[f.webkitRelativePath]=f;
  for(let i=0;i<csvData.length;i++){
    const row=csvData[i];const name=(row[fc]||'').trim();if(!name)continue;
    stats.f++;log('folder','📁 '+name);const rep=buildRep(row);
    if(folderMode==='modern'){
      let sub;try{sub=await folderHandle.getDirectoryHandle(name);}
      catch(e){log('warn','  ⚠ Not found: '+name);stats.m++;updS();prog(i+1,tot);continue;}
      await walkZip(sub,rep,zipResult,folderName+'/'+name);
    }else{
      const pref=folderName+'/'+name+'/';
      const matched=Object.keys(fm).filter(p=>p.startsWith(pref));
      if(!matched.length){log('warn','  ⚠ No files: '+name);stats.m++;updS();prog(i+1,tot);continue;}
      for(const rp of matched){
        const ext='.'+rp.split('.').pop().toLowerCase();
        if(!extensions.includes(ext)){zipResult.file(rp,await readBuf(fm[rp]));continue;}
        const c=await readTxt(fm[rp]);const[mod,cnt]=applyRep(c,rep);
        zipResult.file(rp,mod);
        if(cnt>0){log('ok','  ✓ '+rp.split('/').pop()+' ('+cnt+'x)');stats.u++;}
        else{log('skip','  · '+rp.split('/').pop());stats.s++;}
      }
    }
    updS();prog(i+1,tot);await tick();
  }
}
async function walkPlace(dh,rep,rb){
  for await(const[name,h]of dh){
    if(h.kind==='file'){
      const ext='.'+name.split('.').pop().toLowerCase();if(!extensions.includes(ext))continue;
      try{const c=await(await h.getFile()).text();const[mod,cnt]=applyRep(c,rep);
        if(cnt>0){const w=await h.createWritable();await w.write(mod);await w.close();log('ok','  ✓ '+name+' ('+cnt+'x)');stats.u++;}
        else{log('skip','  · '+name);stats.s++;}
      }catch(e){log('err','  ✗ '+name+': '+e.message);stats.e++;}
      updS();
    }else if(h.kind==='directory'){await walkPlace(h,rep,rb+'/'+name);}
  }
}
async function walkZip(dh,rep,zip,zp){
  for await(const[name,h]of dh){
    const np=zp+'/'+name;
    if(h.kind==='file'){
      const ext='.'+name.split('.').pop().toLowerCase();const f=await h.getFile();
      if(!extensions.includes(ext)){zip.file(np,await f.arrayBuffer());continue;}
      try{const c=await f.text();const[mod,cnt]=applyRep(c,rep);zip.file(np,mod);
        if(cnt>0){log('ok','  ✓ '+name+' ('+cnt+'x)');stats.u++;}
        else{log('skip','  · '+name);stats.s++;}
      }catch(e){log('err','  ✗ '+name+': '+e.message);stats.e++;}
      updS();
    }else if(h.kind==='directory'){await walkZip(h,rep,zip,np);}
  }
}
function buildRep(row){const m={};for(const[k,v]of Object.entries(row)){if(k)m[k]=v??'';}return m;}
function applyRep(c,rep){let r=c;let t=0;for(const[ph,v]of Object.entries(rep)){if(!ph)continue;const p=r.split(ph);if(p.length>1){t+=p.length-1;r=p.join(v);}}return[r,t];}
function readTxt(f){return new Promise((res,rej)=>{const r=new FileReader();r.onload=e=>res(e.target.result);r.onerror=()=>rej(new Error('Read failed'));r.readAsText(f);});}
function readBuf(f){return new Promise((res,rej)=>{const r=new FileReader();r.onload=e=>res(e.target.result);r.onerror=()=>rej(new Error('Read failed'));r.readAsArrayBuffer(f);});}
async function downloadZip(){
  const btn=document.getElementById('btn-dl');btn.innerHTML='<span class="spinner"></span> Compressing...';btn.disabled=true;
  try{const blob=await zipResult.generateAsync({type:'blob',compression:'DEFLATE',compressionOptions:{level:6}});
    const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=folderName+'_replaced.zip';a.click();
    toast('ZIP downloaded!','ok');
  }catch(e){toast('ZIP error: '+e.message,'err');}
  btn.innerHTML='📦 Download ZIP';btn.disabled=false;
}

// Usage logging to server
async function logUsage(rows,files,jobType='bulk_replace'){
  try{
    const fd=new FormData();fd.append('action','log');fd.append('csv_rows',rows);fd.append('files_updated',files);
    fd.append('job_type',jobType);fd.append('job_name',csvFilename||'');
    fd.append('csrf_token','<?= csrf_token() ?>');
    await fetch('/api/usage.php',{method:'POST',body:fd});
  }catch(e){}
}

// ── UI HELPERS ─────────────────────────────────────────
function prog(d,t){const p=t>0?Math.round(d/t*100):0;document.getElementById('prog-bar').style.width=p+'%';document.getElementById('prog-pct').textContent=p+'%';}
function updS(){['f','u','s','m','e'].forEach(k=>document.getElementById('s-'+k).textContent=stats[k]);}
function updateStats(){updS();}
function log(type,msg){
  const box=document.getElementById('log-box');
  const ts=new Date().toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  const d=document.createElement('div');d.className='log-line';
  const colors={folder:'var(--a1)',ok:'var(--ok)',skip:'var(--muted)',err:'var(--err)',warn:'var(--warn)'};
  d.innerHTML=`<span class="log-ts">${ts}</span><span style="color:${colors[type]||'var(--text)'};">${esc(msg)}</span>`;
  box.appendChild(d);box.scrollTop=box.scrollHeight;
}
function showDone(hasZip){
  ['f','u','s','m','e'].forEach(k=>document.getElementById('ds-'+k).textContent=stats[k]);
  const bar=document.getElementById('prog-bar');bar.style.width='100%';bar.classList.add('done');
  document.getElementById('prog-pct').textContent='100%';
  document.getElementById('run-prog').classList.add('hidden');
  document.getElementById('run-done').classList.remove('hidden');
  const icon=document.getElementById('done-icon'),title=document.getElementById('done-title'),sub=document.getElementById('done-sub');
  if(stats.e>0){icon.textContent='⚠️';title.textContent='Done with warnings';sub.textContent=`${stats.u} files updated · ${stats.e} errors`;}
  else{icon.textContent='✅';title.textContent='All Done!';sub.textContent=`${stats.u} files updated across ${stats.f} folders`;}
  const dlBtn=document.getElementById('btn-dl');
  if(hasZip&&zipResult){dlBtn.classList.remove('hidden');}
  else{dlBtn.classList.add('hidden');if(!hasZip)sub.textContent+='\n✓ Files modified in place on your PC';}
  toast(`Done! ${stats.u} files updated`,stats.e>0?'warn':'ok');
}
function resetAll(){
  resetCSV();resetFolder();Object.keys(stats).forEach(k=>stats[k]=0);zipResult=null;
  document.getElementById('run-ready').classList.remove('hidden');
  document.getElementById('run-prog').classList.add('hidden');
  document.getElementById('run-done').classList.add('hidden');
  document.getElementById('log-box').innerHTML='';
  document.getElementById('prog-bar').classList.remove('done');
  goStep(1);
}
function tick(){return new Promise(r=>setTimeout(r,0));}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function toast(msg,type='ok',dur=3500){const w=document.getElementById('toast-wrap');const t=document.createElement('div');t.className='toast '+type;t.innerHTML=`<span>${type==='ok'?'✅':type==='err'?'❌':'⚠️'}</span><span>${esc(msg)}</span>`;w.appendChild(t);setTimeout(()=>{t.style.cssText+='opacity:0;transform:translateX(30px);transition:all .3s';setTimeout(()=>t.remove(),300);},dur);}
window.addEventListener('load',()=>{if(!window.showDirectoryPicker){const c=document.getElementById('pk-modern');c.innerHTML='<div style="font-size:32px;margin-bottom:12px;">🚫</div><div style="font-family:\'Syne\',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:6px;">Not Available</div><div style="font-family:\'JetBrains Mono\',monospace;font-size:10px;color:var(--muted);">Requires Chrome/Edge 86+</div>';c.style.cssText+='opacity:.5;cursor:not-allowed;';c.onclick=()=>toast('Use Browse Folder','warn');}});
</script>
</body></html>
