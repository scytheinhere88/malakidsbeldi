<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/SystemMonitor.php';
require_once __DIR__ . '/../includes/AlertManager.php';
require_once __DIR__ . '/../includes/EnhancedRateLimiter.php';
requireAdmin();

$db = db();

try {
    $tables = $db->query("SHOW TABLES LIKE 'system_metrics'")->fetchAll();
    if (empty($tables)) {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Setup Required</title><link rel="stylesheet" href="/assets/main.css"></head><body>';
        echo '<div class="admin-wrap">';
        include '_sidebar.php';
        echo '<div style="display:flex;align-items:center;justify-content:center;min-height:80vh;padding:40px;"><div style="max-width:500px;text-align:center;">';
        echo '<div style="font-size:48px;margin-bottom:16px;">⚙</div>';
        echo '<div style="font-family:\'Syne\',sans-serif;font-size:22px;font-weight:800;color:#fff;margin-bottom:10px;">Monitoring Setup Required</div>';
        echo '<div style="font-family:\'JetBrains Mono\',monospace;font-size:11px;color:var(--muted);margin-bottom:24px;line-height:1.8;">The monitoring tables have not been initialized yet. Run the setup script to get started.</div>';
        echo '<a href="/api/init_monitoring.php" class="btn btn-amber" style="margin-right:10px;">Initialize Monitoring</a>';
        echo '</div></div></div></body></html>';
        exit;
    }
} catch (Exception $e) {
    die('Database error: ' . htmlspecialchars($e->getMessage()));
}

try {
    $monitor      = SystemMonitor::getInstance($db);
    $alertManager = AlertManager::getInstance($db, $monitor);
    $rateLimiter  = new EnhancedRateLimiter($db, $monitor);

    $timeRange = $_GET['range'] ?? '24';
    $timeRange = in_array($timeRange, ['1','6','24','168']) ? (int)$timeRange : 24;

    $apiStats      = $monitor->getApiStats($timeRange);
    $errorStats    = $monitor->getErrorStats($timeRange);
    $dbMetrics     = $monitor->getDatabaseMetrics();
    $diskMetrics   = $monitor->getDiskUsage();
    $memoryMetrics = $monitor->getMemoryUsage();
    $queueMetrics  = $monitor->getQueueMetrics();
    $healthStatus  = $monitor->getHealthStatus();

    $activeAlerts  = $alertManager->getActiveAlerts();
    $alertStats    = $alertManager->getAlertStats($timeRange);

    $rateLimitStats = $rateLimiter->getStats(null, $timeRange);
    $blockedIPs     = $rateLimiter->getBlockedIPs();
    $apiKeyStats    = $monitor->getApiKeyUsageStats($timeRange);
    $slowestEndpoints = $monitor->getSlowestEndpoints(10, $timeRange);
    $recentErrors   = $monitor->getRecentErrors(20);
} catch (Exception $e) {
    $errorMsg = htmlspecialchars($e->getMessage());
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Monitoring Error</title><link rel="stylesheet" href="/assets/main.css"></head><body>';
    echo '<div class="admin-wrap">';
    include '_sidebar.php';
    echo '<div style="padding:40px;"><div class="err-box">Monitoring system error: ' . $errorMsg . '</div>';
    echo '<div style="margin:20px 0;display:flex;gap:12px;"><a href="/api/test_monitoring.php" class="btn btn-amber">Test Setup</a><a href="/api/init_monitoring.php" class="btn">Reinitialize</a></div></div></div></body></html>';
    exit;
}

$statusColorMap = [
    'healthy'   => 'var(--a2)',
    'degraded'  => 'var(--a1)',
    'unhealthy' => 'var(--err)',
];
$statusBg = [
    'healthy'   => 'rgba(0,212,170,.08)',
    'degraded'  => 'rgba(240,165,0,.08)',
    'unhealthy' => 'rgba(255,69,96,.08)',
];
$statusBorder = [
    'healthy'   => 'rgba(0,212,170,.25)',
    'degraded'  => 'rgba(240,165,0,.25)',
    'unhealthy' => 'rgba(255,69,96,.25)',
];
$statusLabel = [
    'healthy'   => 'System Healthy',
    'degraded'  => 'Degraded Performance',
    'unhealthy' => 'System Issues',
];
$hs = $healthStatus['status'];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Monitoring — Admin — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/main.css">
<style>
.mon-stat{background:var(--dim);border-radius:12px;padding:18px 20px;border:1px solid var(--border);position:relative;overflow:hidden;}
.mon-stat::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--_accent,var(--border));}
.mon-stat.critical{--_accent:var(--err);background:rgba(255,69,96,.06);border-color:rgba(255,69,96,.2);}
.mon-stat.warning{--_accent:var(--a1);background:rgba(240,165,0,.06);border-color:rgba(240,165,0,.2);}
.mon-stat.ok{--_accent:var(--a2);background:rgba(0,212,170,.06);border-color:rgba(0,212,170,.2);}
.ms-val{font-family:'Syne',sans-serif;font-size:28px;font-weight:900;color:#fff;line-height:1;margin:8px 0 4px;}
.ms-label{font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);}
.ms-sub{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:4px;}

.range-tabs{display:flex;gap:6px;flex-wrap:wrap;}
.range-tab{padding:6px 14px;border-radius:8px;font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;border:1px solid var(--border);background:var(--dim);color:var(--muted);text-decoration:none;transition:all .15s;}
.range-tab.active,.range-tab:hover{background:rgba(240,165,0,.1);border-color:rgba(240,165,0,.3);color:var(--a1);}

.alert-item{padding:14px 18px;border-radius:10px;margin-bottom:10px;border-left:3px solid;display:flex;align-items:flex-start;gap:14px;}
.alert-item.critical{background:rgba(255,69,96,.06);border-left-color:var(--err);}
.alert-item.warning{background:rgba(240,165,0,.06);border-left-color:var(--a1);}
.alert-item.info{background:rgba(96,165,250,.06);border-left-color:#60a5fa;}

.refresh-ring{width:28px;height:28px;position:relative;}
.refresh-ring svg{transform:rotate(-90deg);}
.refresh-arc{stroke-dasharray:75.4;stroke-dashoffset:0;transition:stroke-dashoffset 1s linear;}

.endpoint-bar{height:4px;background:var(--dim);border-radius:2px;overflow:hidden;margin-top:4px;}
.endpoint-bar-fill{height:100%;border-radius:2px;}

.health-badge{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;font-family:'Syne',sans-serif;font-size:13px;font-weight:700;}
.health-dot{width:8px;height:8px;border-radius:50%;}
</style>
</head>
<body>
<div class="admin-wrap">
<?php include '_sidebar.php'; ?>
<div style="padding:24px 32px;max-width:1400px;">

<!-- HEADER -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:14px;">
  <div>
    <div style="font-family:'Syne',sans-serif;font-size:24px;font-weight:900;color:#fff;">System Monitoring</div>
    <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:4px;">
      Real-time health, performance & uptime tracking — auto-refreshes every 30s
    </div>
  </div>
  <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
    <div style="display:flex;align-items:center;gap:8px;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);">
      <div class="refresh-ring">
        <svg width="28" height="28" viewBox="0 0 28 28">
          <circle cx="14" cy="14" r="12" stroke="var(--dim)" stroke-width="2" fill="none"/>
          <circle id="refreshArc" cx="14" cy="14" r="12" stroke="var(--a2)" stroke-width="2" fill="none" class="refresh-arc"/>
        </svg>
      </div>
      <span id="refreshCountdown">30s</span>
    </div>
    <div class="health-badge" style="background:<?= $statusBg[$hs] ?>;border:1px solid <?= $statusBorder[$hs] ?>;">
      <div class="health-dot" style="background:<?= $statusColorMap[$hs] ?>;box-shadow:0 0 6px <?= $statusColorMap[$hs] ?>;"></div>
      <span style="color:<?= $statusColorMap[$hs] ?>;"><?= $statusLabel[$hs] ?></span>
    </div>
    <button onclick="restartAPI()" id="restartBtn" class="btn" style="background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.3);color:#60a5fa;font-size:11px;">
      Restart API
    </button>
  </div>
</div>

<!-- TIME RANGE -->
<div class="range-tabs" style="margin-bottom:24px;">
  <a href="?range=1" class="range-tab <?= $timeRange===1?'active':'' ?>">Last Hour</a>
  <a href="?range=6" class="range-tab <?= $timeRange===6?'active':'' ?>">6 Hours</a>
  <a href="?range=24" class="range-tab <?= $timeRange===24?'active':'' ?>">24 Hours</a>
  <a href="?range=168" class="range-tab <?= $timeRange===168?'active':'' ?>">7 Days</a>
  <div style="margin-left:auto;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);display:flex;align-items:center;">
    Showing last <?= $timeRange < 24 ? $timeRange.'h' : ($timeRange===168?'7d':'24h') ?>
  </div>
</div>

<?php if(!empty($activeAlerts)): ?>
<!-- ACTIVE ALERTS -->
<div style="margin-bottom:24px;">
  <div style="font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--err);margin-bottom:12px;">
    Active Alerts (<?= count($activeAlerts) ?>)
  </div>
  <?php foreach($activeAlerts as $alert): ?>
  <div class="alert-item <?= $alert['severity'] ?>">
    <div style="font-size:18px;flex-shrink:0;">
      <?= $alert['severity']==='critical' ? '▲' : ($alert['severity']==='warning' ? '◆' : 'ℹ') ?>
    </div>
    <div style="flex:1;">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:<?= $alert['severity']==='critical'?'var(--err)':($alert['severity']==='warning'?'var(--a1)':'#60a5fa') ?>;">
          <?= htmlspecialchars($alert['alert_type']) ?>
        </div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);"><?= ago($alert['created_at']) ?></div>
      </div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text);margin-top:4px;"><?= htmlspecialchars($alert['message']) ?></div>
      <?php if($alert['metric_value']): ?>
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-top:4px;">
        Value: <?= $alert['metric_value'] ?> / Threshold: <?= $alert['threshold_value'] ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- API PERFORMANCE -->
<div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:12px;font-size:11px;">API Performance</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:24px;">
  <div class="mon-stat <?= $apiStats['total_calls'] > 0 ? 'ok' : '' ?>" style="--_accent:var(--a2);">
    <div class="ms-label">Total Calls</div>
    <div class="ms-val"><?= number_format($apiStats['total_calls']) ?></div>
    <div class="ms-sub"><?= $apiStats['unique_ips'] ?> unique IPs</div>
  </div>

  <div class="mon-stat <?= $apiStats['avg_response_time'] > 1000 ? 'warning' : 'ok' ?>">
    <div class="ms-label">Avg Response</div>
    <div class="ms-val"><?= round($apiStats['avg_response_time']) ?><span style="font-size:14px;">ms</span></div>
    <div class="ms-sub">Max: <?= $apiStats['max_response_time'] ?>ms</div>
  </div>

  <div class="mon-stat <?= $apiStats['error_rate'] > 5 ? 'critical' : ($apiStats['error_rate'] > 2 ? 'warning' : 'ok') ?>">
    <div class="ms-label">Error Rate</div>
    <div class="ms-val"><?= $apiStats['error_rate'] ?><span style="font-size:14px;">%</span></div>
    <div class="ms-sub"><?= $apiStats['server_errors'] + $apiStats['client_errors'] ?> errors total</div>
  </div>

  <div class="mon-stat ok" style="--_accent:var(--a2);">
    <div class="ms-label">Success Rate</div>
    <div class="ms-val"><?= $apiStats['total_calls'] > 0 ? round(($apiStats['successful_calls']/$apiStats['total_calls'])*100,1) : 0 ?><span style="font-size:14px;">%</span></div>
    <div class="ms-sub"><?= number_format($apiStats['successful_calls']) ?> successful</div>
  </div>

  <div class="mon-stat" style="--_accent:#60a5fa;">
    <div class="ms-label">Unique Users</div>
    <div class="ms-val"><?= number_format($apiStats['unique_users']) ?></div>
    <div class="ms-sub">via API / session</div>
  </div>
</div>

<!-- SYSTEM RESOURCES -->
<div style="font-family:'Syne',sans-serif;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:12px;">System Resources</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:24px;">
  <?php $diskPct = $diskMetrics['disk_usage_percent']; ?>
  <div class="mon-stat <?= $diskPct > 90 ? 'critical' : ($diskPct > 80 ? 'warning' : '') ?>">
    <div class="ms-label">Disk Usage</div>
    <div class="ms-val"><?= $diskPct ?><span style="font-size:14px;">%</span></div>
    <div class="ms-sub"><?= $diskMetrics['disk_used_gb'] ?>GB / <?= $diskMetrics['disk_total_gb'] ?>GB</div>
    <div style="height:4px;background:var(--dim);border-radius:2px;margin-top:8px;overflow:hidden;">
      <div style="height:100%;border-radius:2px;width:<?= $diskPct ?>%;background:<?= $diskPct>90?'var(--err)':($diskPct>80?'var(--a1)':'var(--a2)') ?>;"></div>
    </div>
  </div>

  <div class="mon-stat">
    <div class="ms-label">Database Size</div>
    <div class="ms-val"><?= $dbMetrics['database_size_mb'] ?><span style="font-size:14px;">MB</span></div>
    <div class="ms-sub"><?= $dbMetrics['table_count'] ?> tables</div>
  </div>

  <div class="mon-stat">
    <div class="ms-label">DB Connections</div>
    <div class="ms-val"><?= $dbMetrics['active_connections'] ?></div>
    <div class="ms-sub">Active connections</div>
  </div>

  <div class="mon-stat">
    <div class="ms-label">Memory</div>
    <div class="ms-val"><?= $memoryMetrics['memory_current_mb'] ?><span style="font-size:14px;">MB</span></div>
    <div class="ms-sub">Peak: <?= $memoryMetrics['memory_peak_mb'] ?>MB</div>
  </div>
</div>

<!-- RATE LIMITING -->
<div style="font-family:'Syne',sans-serif;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:12px;">Rate Limiting</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:24px;">
  <div class="mon-stat">
    <div class="ms-label">IPs Tracked</div>
    <div class="ms-val"><?= number_format($rateLimitStats['unique_ips'] ?? 0) ?></div>
    <div class="ms-sub"><?= number_format($rateLimitStats['total_attempts'] ?? 0) ?> attempts</div>
  </div>
  <div class="mon-stat <?= ($rateLimitStats['currently_blocked'] ?? 0) > 10 ? 'warning' : '' ?>">
    <div class="ms-label">Blocked IPs</div>
    <div class="ms-val"><?= $rateLimitStats['currently_blocked'] ?? 0 ?></div>
    <div class="ms-sub">+ <?= count($blockedIPs) ?> permanent</div>
  </div>
  <div class="mon-stat">
    <div class="ms-label">Burst Used</div>
    <div class="ms-val"><?= number_format($rateLimitStats['total_burst_used'] ?? 0) ?></div>
    <div class="ms-sub">Total burst requests</div>
  </div>
  <div class="mon-stat" style="--_accent:var(--a2);">
    <div class="ms-label">API Key Calls</div>
    <div class="ms-val"><?= number_format($apiKeyStats['total_api_calls']) ?></div>
    <div class="ms-sub"><?= $apiKeyStats['unique_api_users'] ?> unique API users</div>
  </div>
</div>

<!-- 2-COLUMN: SLOWEST + TOP USERS -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">

  <!-- SLOWEST ENDPOINTS -->
  <div class="card" style="margin-bottom:0;">
    <div style="font-family:'Syne',sans-serif;font-size:15px;font-weight:800;color:#fff;margin-bottom:14px;">Slowest Endpoints</div>
    <?php if(!empty($slowestEndpoints)): ?>
    <?php $maxTime = max(array_column($slowestEndpoints, 'avg_response_time') ?: [1]); ?>
    <?php foreach($slowestEndpoints as $ep): ?>
    <?php
      $epPct = min(100, round(($ep['avg_response_time']/$maxTime)*100));
      $epCol = $ep['avg_response_time'] > 2000 ? 'var(--err)' : ($ep['avg_response_time'] > 1000 ? 'var(--a1)' : 'var(--a2)');
    ?>
    <div style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,.04);">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;" title="<?= htmlspecialchars($ep['endpoint']) ?>">
          <?= htmlspecialchars(basename($ep['endpoint'])) ?>
        </div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;color:<?= $epCol ?>;white-space:nowrap;"><?= round($ep['avg_response_time']) ?>ms</div>
      </div>
      <div class="endpoint-bar">
        <div class="endpoint-bar-fill" style="width:<?= $epPct ?>%;background:<?= $epCol ?>;"></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);padding:20px;text-align:center;">No data yet</div>
    <?php endif; ?>
  </div>

  <!-- TOP API USERS -->
  <div class="card" style="margin-bottom:0;">
    <div style="font-family:'Syne',sans-serif;font-size:15px;font-weight:800;color:#fff;margin-bottom:14px;">Top API Users</div>
    <?php if(!empty($apiKeyStats['top_api_users'])): ?>
    <?php foreach($apiKeyStats['top_api_users'] as $u): ?>
    <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.04);">
      <div style="flex:1;min-width:0;">
        <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--a1);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
          <a href="/admin/users.php?edit=<?= $u['id'] ?>" style="color:var(--a1);text-decoration:none;"><?= htmlspecialchars($u['email']) ?></a>
        </div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-top:2px;">
          <?= round($u['avg_response_time']) ?>ms avg &bull; <?= $u['errors'] > 0 ? '<span style="color:var(--err);">'.$u['errors'].' errors</span>' : '0 errors' ?>
        </div>
      </div>
      <div style="text-align:right;">
        <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;"><?= number_format($u['api_calls']) ?></div>
        <span style="font-family:'JetBrains Mono',monospace;font-size:8px;background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.3);color:#60a5fa;padding:1px 6px;border-radius:4px;"><?= strtoupper($u['plan']) ?></span>
      </div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);padding:20px;text-align:center;">No API key usage recorded</div>
    <?php endif; ?>
  </div>
</div>

<!-- RECENT ERRORS -->
<div class="card" style="margin-bottom:24px;">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
    <div style="font-family:'Syne',sans-serif;font-size:15px;font-weight:800;color:#fff;">Recent Errors</div>
    <span style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);"><?= count($recentErrors) ?> recent</span>
  </div>
  <?php if(!empty($recentErrors)): ?>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Time</th>
          <th>Type</th>
          <th>Severity</th>
          <th>Message</th>
          <th>File</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($recentErrors as $e): ?>
        <tr>
          <td style="font-size:11px;white-space:nowrap;"><?= ago($e['created_at']) ?></td>
          <td><code style="font-size:10px;background:var(--dim);padding:2px 6px;border-radius:4px;"><?= htmlspecialchars($e['error_type']) ?></code></td>
          <td>
            <span class="badge <?= $e['severity']==='critical'?'badge-err':($e['severity']==='warning'?'badge-warn':'') ?>"><?= $e['severity'] ?></span>
          </td>
          <td style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);max-width:400px;">
            <?= htmlspecialchars(substr($e['error_message'], 0, 100)) ?>
          </td>
          <td style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);">
            <?= $e['error_file'] ? basename($e['error_file']) . ':' . $e['error_line'] : '—' ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div style="text-align:center;padding:32px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--a2);">
    ◎ No recent errors recorded
  </div>
  <?php endif; ?>
</div>

<!-- UPTIME SETUP -->
<div style="background:rgba(96,165,250,.06);border:1px solid rgba(96,165,250,.2);border-radius:14px;padding:20px 24px;margin-bottom:24px;">
  <div style="font-family:'Syne',sans-serif;font-size:15px;font-weight:800;color:#60a5fa;margin-bottom:8px;">External Uptime Monitoring</div>
  <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-bottom:14px;line-height:1.7;">
    Connect a free service to get alerts when your app goes offline. Monitor:
    <span style="font-family:'JetBrains Mono',monospace;background:var(--bg);padding:3px 8px;border-radius:6px;color:var(--a2);margin:0 4px;" id="healthUrl"><?= APP_URL ?>/api/health.php</span>
    <button onclick="copyHealthUrl()" class="btn" style="font-size:9px;padding:3px 10px;background:var(--dim);border:1px solid var(--border);">Copy</button>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
    <div style="background:var(--dim);border-radius:8px;padding:14px;">
      <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--a2);margin-bottom:4px;">UptimeRobot</div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-bottom:8px;">Free &bull; 50 monitors &bull; 5-min intervals</div>
      <a href="https://uptimerobot.com/" target="_blank" style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--a1);text-decoration:none;font-weight:700;">Sign Up →</a>
    </div>
    <div style="background:var(--dim);border-radius:8px;padding:14px;">
      <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:#60a5fa;margin-bottom:4px;">Better Uptime</div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-bottom:8px;">Free &bull; 10 monitors &bull; 30-sec intervals</div>
      <a href="https://betteruptime.com/" target="_blank" style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--a1);text-decoration:none;font-weight:700;">Sign Up →</a>
    </div>
    <div style="background:var(--dim);border-radius:8px;padding:14px;">
      <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--a1);margin-bottom:4px;">Pingdom</div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-bottom:8px;">$10/mo &bull; 1-min &bull; 50+ locations</div>
      <a href="https://www.pingdom.com/" target="_blank" style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--a1);text-decoration:none;font-weight:700;">14-day Trial →</a>
    </div>
  </div>
</div>

</div>

<script>
let countdown = 30;
const countdownEl  = document.getElementById('refreshCountdown');
const arcEl        = document.getElementById('refreshArc');
const circumference = 75.4;

function updateCountdown() {
    countdown--;
    if (countdownEl) countdownEl.textContent = countdown + 's';
    if (arcEl) arcEl.style.strokeDashoffset = circumference * (1 - countdown / 30);
    if (countdown <= 0) location.reload();
    else setTimeout(updateCountdown, 1000);
}
setTimeout(updateCountdown, 1000);

function copyHealthUrl() {
    const url = document.getElementById('healthUrl').textContent.trim();
    navigator.clipboard.writeText(url).then(() => {
        const btn = event.target;
        const orig = btn.textContent;
        btn.textContent = 'Copied!';
        btn.style.color = 'var(--a2)';
        setTimeout(() => { btn.textContent = orig; btn.style.color = ''; }, 2000);
    });
}

async function restartAPI() {
    const btn = document.getElementById('restartBtn');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Restarting...';

    try {
        const res = await fetch('/api/restart_trigger.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ action: 'restart' })
        });
        const data = await res.json();
        if (data.success) {
            btn.innerHTML = 'Restarted!';
            btn.style.color = 'var(--a2)';
            setTimeout(() => location.reload(), 1500);
        } else {
            alert('Restart failed: ' + (data.error || 'Unknown error'));
            btn.innerHTML = orig;
            btn.disabled = false;
        }
    } catch(e) {
        alert('Network error: ' + e.message);
        btn.innerHTML = orig;
        btn.disabled = false;
    }
}
</script>
</body>
</html>
