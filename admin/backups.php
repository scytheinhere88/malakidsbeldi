<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/BackupSystem.php';
startSession();
if(!isAdmin()){header('Location:'.APP_URL);exit;}

$backupSystem = new BackupSystem(db());
$stats = $backupSystem->getBackupStats();
$health = $backupSystem->getBackupHealth();
$backups = $backupSystem->getBackupLogs(50);
$recoveryHistory = $backupSystem->getRecoveryHistory(10);
$schedulesStmt = db()->query("SELECT * FROM backup_schedules ORDER BY is_active DESC, next_run ASC");
$schedules = $schedulesStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Backup Management — Admin</title>
<link rel="stylesheet" href="/assets/main.css">
<style>
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px;transition:all .25s;position:relative;overflow:hidden;}
.stat-card::before{content:'';position:absolute;top:0;left:0;width:3px;height:100%;opacity:0;transition:opacity .25s;}
.stat-card:hover{border-color:var(--border2);transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.3);}
.stat-card:hover::before{opacity:1;}
.stat-card:nth-child(1)::before{background:var(--a1);}
.stat-card:nth-child(2)::before{background:var(--ok);}
.stat-card:nth-child(3)::before{background:var(--err);}
.stat-card:nth-child(4)::before{background:var(--a2);}
.stat-value{font-size:32px;font-weight:800;color:#fff;margin:8px 0;transition:color .25s;}
.stat-card:hover .stat-value{color:var(--a1);}
.stat-label{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;}
.backup-list{background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;}
.backup-item{padding:16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;transition:all .15s;}
.backup-item:last-child{border-bottom:none;}
.backup-item:hover{background:rgba(255,255,255,.02);transform:translateX(2px);}
.status-badge{padding:4px 12px;border-radius:6px;font-size:11px;font-weight:700;text-transform:uppercase;transition:all .2s;}
.status-completed{background:#10b98144;color:#10b981;border:1px solid #10b98155;}
.status-failed{background:#ef444444;color:#ef4444;border:1px solid #ef444455;}
.status-running{background:#f59e0b44;color:#f59e0b;border:1px solid #f59e0b55;}
.btn-download:hover{background:var(--a1);transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.3);}
</style>
</head><body>
<?php include '_sidebar.php'; ?>
<div class="main-content">
<div class="top-bar"><h1>Backup Management</h1></div>
<div class="content-area">

<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:24px;">
  <h3 style="margin:0 0 12px 0;">System Health (7d)</h3>
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
    <div style="width:16px;height:16px;border-radius:50%;background:<?= $health['health_status'] === 'healthy' ? '#10b981' : ($health['health_status'] === 'warning' ? '#f59e0b' : '#ef4444') ?>;"></div>
    <span style="font-size:18px;font-weight:700;">
      <?= ucfirst($health['health_status']) ?> - <?= $health['success_rate'] ?>% Success Rate
    </span>
  </div>
  <div style="font-size:12px;color:var(--muted);">
    <?= number_format($health['completed'] ?? 0) ?> successful / <?= number_format($health['failed'] ?? 0) ?> failed
    <?php if($health['avg_duration_seconds']): ?>
    • Avg duration: <?= round($health['avg_duration_seconds']) ?>s
    <?php endif; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-label">Total Backups (30d)</div>
    <div class="stat-value"><?= number_format($stats['total_backups'] ?? 0) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Successful</div>
    <div class="stat-value" style="color:#10b981;"><?= number_format($stats['successful'] ?? 0) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Failed</div>
    <div class="stat-value" style="color:#ef4444;"><?= number_format($stats['failed'] ?? 0) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Size</div>
    <div class="stat-value"><?= formatBytes($stats['total_size'] ?? 0) ?></div>
  </div>
</div>

<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:24px;">
  <h3 style="margin:0 0 16px 0;">Create Manual Backup</h3>
  <button onclick="createBackup()" class="btn btn-amber">Create Backup Now</button>
</div>

<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:24px;">
  <h3 style="margin:0 0 16px 0;">Backup Schedules</h3>
  <table style="width:100%;border-collapse:collapse;">
    <thead>
      <tr style="border-bottom:1px solid var(--border);text-align:left;">
        <th style="padding:8px;">Type</th>
        <th style="padding:8px;">Frequency</th>
        <th style="padding:8px;">Next Run</th>
        <th style="padding:8px;">Retention</th>
        <th style="padding:8px;">Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($schedules as $sched): ?>
      <tr style="border-bottom:1px solid var(--border);">
        <td style="padding:8px;"><?= ucfirst($sched['backup_type']) ?></td>
        <td style="padding:8px;"><?= ucfirst($sched['frequency']) ?></td>
        <td style="padding:8px;"><?= $sched['next_run'] ? date('M d, Y H:i', strtotime($sched['next_run'])) : '-' ?></td>
        <td style="padding:8px;"><?= $sched['retention_days'] ?> days</td>
        <td style="padding:8px;">
          <span class="status-badge status-<?= $sched['is_active'] ? 'completed' : 'failed' ?>">
            <?= $sched['is_active'] ? 'Active' : 'Inactive' ?>
          </span>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="backup-list">
  <div style="padding:20px;border-bottom:1px solid var(--border);"><h3 style="margin:0;">Recent Backups</h3></div>
  <?php foreach($backups as $backup): ?>
  <div class="backup-item">
    <div>
      <div style="font-weight:700;margin-bottom:4px;">
        <?= ucfirst($backup['backup_type']) ?> Backup
        <?php if($backup['schedule_id']): ?>
        <span style="font-size:10px;color:var(--muted);">(Automated)</span>
        <?php endif; ?>
      </div>
      <div style="font-size:12px;color:var(--muted);">
        <?= date('M d, Y H:i:s', strtotime($backup['created_at'])) ?>
        <?php if($backup['file_size']): ?>
        • <?= formatBytes($backup['file_size']) ?>
        <?php endif; ?>
        <?php if($backup['rows_backed_up']): ?>
        • <?= number_format($backup['rows_backed_up']) ?> rows
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <span class="status-badge status-<?= $backup['status'] ?>"><?= ucfirst($backup['status']) ?></span>
      <?php if($backup['status'] === 'completed'): ?>
      <button onclick="verifyIntegrity(<?= $backup['id'] ?>)"
         class="btn-verify"
         title="Verify Integrity (SHA-256)"
         style="background:#8b5cf6;color:#fff;padding:6px 12px;border-radius:6px;font-size:11px;font-weight:700;transition:all .2s;border:none;cursor:pointer;">
        🔒 Verify
      </button>
      <button onclick="validateRestore(<?= $backup['id'] ?>)"
         class="btn-validate"
         title="Validate Backup"
         style="background:var(--a1);color:#000;padding:6px 12px;border-radius:6px;font-size:11px;font-weight:700;transition:all .2s;border:none;cursor:pointer;">
        ✓ Validate
      </button>
      <button onclick="restoreBackup(<?= $backup['id'] ?>)"
         class="btn-restore"
         title="Restore Backup"
         style="background:var(--ok);color:#000;padding:6px 12px;border-radius:6px;font-size:11px;font-weight:700;transition:all .2s;border:none;cursor:pointer;">
        ↻ Restore
      </button>
      <button onclick="downloadBackup(<?= $backup['id'] ?>)"
         class="btn-download"
         title="Download Backup"
         style="background:var(--a2);color:#000;padding:6px 12px;border-radius:6px;font-size:11px;font-weight:700;transition:all .2s;border:none;cursor:pointer;">
        ⬇ Download
      </button>
      <?php if(!($backup['is_encrypted'] ?? 0)): ?>
      <button onclick="encryptBackup(<?= $backup['id'] ?>)"
         class="btn-encrypt"
         title="Encrypt Backup"
         style="background:#f59e0b;color:#000;padding:6px 12px;border-radius:6px;font-size:11px;font-weight:700;transition:all .2s;border:none;cursor:pointer;">
        🔐 Encrypt
      </button>
      <?php else: ?>
      <span style="font-size:10px;color:#10b981;font-weight:700;">🔐 ENCRYPTED</span>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

</div>
</div>

<script src="/assets/toast.js"></script>
<script>
async function createBackup() {
  if(!confirm('Create a manual backup now?')) return;

  const btn = event.target;
  const originalText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.15);border-top-color:#000;border-radius:50%;animation:spin .6s linear infinite;"></span> Creating...';

  try {
    const formData = new FormData();
    formData.append('backup_type', 'manual');

    const res = await fetch('/api/backup.php?action=create', {
      method: 'POST',
      body: formData
    });

    const data = await res.json();

    if(data.success) {
      toast.success('Backup created successfully!');
      setTimeout(() => location.reload(), 1000);
    } else {
      toast.error(data.error || 'Failed to create backup');
      btn.disabled = false;
      btn.innerHTML = originalText;
    }
  } catch(err) {
    toast.error('Failed to create backup: ' + err.message);
    btn.disabled = false;
    btn.innerHTML = originalText;
  }
}

async function validateRestore(backupId) {
  const btn = event.target;
  const originalText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.15);border-top-color:#000;border-radius:50%;animation:spin .6s linear infinite;"></span>';

  try {
    const formData = new FormData();
    formData.append('backup_id', backupId);
    formData.append('dry_run', '1');

    const res = await fetch('/api/backup.php?action=restore', {
      method: 'POST',
      body: formData
    });

    const data = await res.json();

    if(data.success) {
      toast.success(data.message || 'Backup validation successful! Ready to restore.');
    } else {
      toast.error(data.error || 'Validation failed');
    }
  } catch(err) {
    toast.error('Validation failed: ' + err.message);
  } finally {
    btn.disabled = false;
    btn.innerHTML = originalText;
  }
}

async function restoreBackup(backupId) {
  if(!confirm('⚠️ WARNING: This will restore the database to this backup point. A safety backup will be created first. Continue?')) return;

  const btn = event.target;
  const originalText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.15);border-top-color:#000;border-radius:50%;animation:spin .6s linear infinite;"></span> Restoring...';

  try {
    const formData = new FormData();
    formData.append('backup_id', backupId);

    const res = await fetch('/api/backup.php?action=restore', {
      method: 'POST',
      body: formData
    });

    const data = await res.json();

    if(data.success) {
      toast.success('Backup restored successfully!');
      setTimeout(() => location.reload(), 2000);
    } else {
      toast.error(data.error || 'Failed to restore backup');
      btn.disabled = false;
      btn.innerHTML = originalText;
    }
  } catch(err) {
    toast.error('Failed to restore backup: ' + err.message);
    btn.disabled = false;
    btn.innerHTML = originalText;
  }
}

async function verifyIntegrity(backupId) {
  const btn = event.target;
  const originalText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.15);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;"></span>';

  try {
    const res = await fetch(`/api/backup_download.php?action=verify&backup_id=${backupId}`);
    const data = await res.json();

    if(data.valid) {
      toast.success(`✓ Integrity verified! SHA-256: ${data.checksum.substring(0, 16)}...`);
    } else {
      toast.error(`✗ Integrity check failed: ${data.error}`);
    }
  } catch(err) {
    toast.error('Verification failed: ' + err.message);
  } finally {
    btn.disabled = false;
    btn.innerHTML = originalText;
  }
}

async function downloadBackup(backupId) {
  const btn = event.target;
  const originalText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.15);border-top-color:#000;border-radius:50%;animation:spin .6s linear infinite;"></span>';

  try {
    window.location.href = `/api/backup_download.php?action=download&backup_id=${backupId}`;
    toast.success('Download started!');
    setTimeout(() => {
      btn.disabled = false;
      btn.innerHTML = originalText;
    }, 2000);
  } catch(err) {
    toast.error('Download failed: ' + err.message);
    btn.disabled = false;
    btn.innerHTML = originalText;
  }
}

async function encryptBackup(backupId) {
  if(!confirm('Encrypt this backup? This will use AES-256 encryption.')) return;

  const btn = event.target;
  const originalText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.15);border-top-color:#000;border-radius:50%;animation:spin .6s linear infinite;"></span>';

  try {
    const res = await fetch(`/api/backup_download.php?action=encrypt&backup_id=${backupId}`);
    const data = await res.json();

    if(data.success) {
      toast.success('Backup encrypted successfully with AES-256!');
      setTimeout(() => location.reload(), 1500);
    } else {
      toast.error(data.error || 'Encryption failed');
      btn.disabled = false;
      btn.innerHTML = originalText;
    }
  } catch(err) {
    toast.error('Encryption failed: ' + err.message);
    btn.disabled = false;
    btn.innerHTML = originalText;
  }
}
</script>
<style>
@keyframes spin{to{transform:rotate(360deg);}}
</style>
</body></html>
<?php
function formatBytes($bytes) {
    if ($bytes == 0) return '0 B';
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}
?>
