<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/SystemMonitor.php';
require_once __DIR__ . '/../includes/CronHeartbeat.php';
require_once __DIR__ . '/../includes/AdvancedAlertManager.php';

requireAdmin();

$db = db();
$monitor = SystemMonitor::getInstance($db);
$heartbeat = CronHeartbeat::getInstance($db);
$alertManager = AdvancedAlertManager::getInstance($db);

$health = $monitor->getHealthStatus();
$dbMetrics = $monitor->getDatabaseMetrics();
$diskMetrics = $monitor->getDiskUsage();
$memoryMetrics = $monitor->getMemoryUsage();
$apiStats = $monitor->getApiStats(24);
$cronJobs = $heartbeat->getAllJobsStatus();
$activeAlerts = $alertManager->getActiveAlerts();
$alertStats = $alertManager->getAlertStats(24);

if (file_exists(__DIR__ . '/../includes/QueryCache.php')) {
    require_once __DIR__ . '/../includes/QueryCache.php';
    $cacheStats = QueryCache::getStats();
}

$title = "System Health Dashboard";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?> - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/main.css">
    <style>
        .admin-wrap{min-height:100vh;background:var(--bg);}
        .dashboard-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1.5rem;margin:1.5rem 0;}
        .metric-card{background:var(--dim);border-radius:12px;padding:1.5rem;border:1px solid var(--border);transition:transform 0.2s,box-shadow 0.2s;}
        .metric-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,0.3);}
        .metric-card h3{margin:0 0 1rem 0;color:var(--muted);font-size:0.875rem;text-transform:uppercase;letter-spacing:0.05em;font-weight:600;font-family:'JetBrains Mono',monospace;}
        .metric-value{font-size:2.5rem;font-weight:700;margin:0.5rem 0;color:#10b981;font-family:'Syne',sans-serif;}
        .metric-value.warning{color:#f59e0b;}
        .metric-value.danger{color:#ef4444;}
        .metric-label{color:var(--muted);font-size:0.875rem;font-family:'JetBrains Mono',monospace;}
        .status-badge{display:inline-block;padding:0.25rem 0.75rem;border-radius:9999px;font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;font-family:'JetBrains Mono',monospace;}
        .status-ok{background:rgba(16,185,129,.2);color:#10b981;}
        .status-warning{background:rgba(245,158,11,.2);color:#f59e0b;}
        .status-critical{background:rgba(239,68,68,.2);color:#ef4444;}
        .progress-bar{width:100%;height:8px;background:#1a1a1a;border-radius:9999px;overflow:hidden;margin:0.5rem 0;}
        .progress-fill{height:100%;background:#10b981;transition:width 0.3s ease;border-radius:9999px;}
        .progress-fill.warning{background:#f59e0b;}
        .progress-fill.danger{background:#ef4444;}
        .cron-table{width:100%;border-collapse:collapse;background:var(--dim);border-radius:8px;overflow:hidden;}
        .cron-table th{background:rgba(255,255,255,.03);padding:0.75rem;text-align:left;color:var(--muted);font-weight:600;text-transform:uppercase;font-size:0.75rem;letter-spacing:0.05em;font-family:'JetBrains Mono',monospace;border-bottom:1px solid var(--border);}
        .cron-table td{padding:0.75rem;border-top:1px solid var(--border);font-size:0.875rem;color:var(--text);}
        .cron-table tr:hover{background:rgba(255,255,255,.02);}
        .alert-item{background:var(--dim);border-left:4px solid;padding:1rem;margin:0.5rem 0;border-radius:6px;}
        .alert-item.critical{border-left-color:#ef4444;background:rgba(239,68,68,.1);}
        .alert-item.high{border-left-color:#f59e0b;background:rgba(245,158,11,.1);}
        .alert-item.medium{border-left-color:#3b82f6;background:rgba(59,130,246,.1);}
        .chart-container{background:var(--dim);border-radius:8px;padding:1.5rem;margin:1rem 0;border:1px solid var(--border);}
        .section-title{font-size:1.25rem;font-weight:700;color:#fff;margin:2rem 0 1rem 0;font-family:'Syne',sans-serif;}
    </style>
</head>
<body>
<div class="admin-wrap">
    <?php include '_sidebar.php'; ?>
    <div style="max-width:1400px;margin:0 auto;padding:2rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;">
            <div>
                <h1 style="font-size:2rem;font-weight:700;margin:0;color:#fff;font-family:'Syne',sans-serif;">⚙️ System Health Dashboard</h1>
                <p style="color:var(--muted);margin:0.5rem 0 0 0;font-family:'JetBrains Mono',monospace;font-size:0.875rem;">Real-time system monitoring and diagnostics</p>
            </div>
            <span class="status-badge status-<?= $health['status'] === 'healthy' ? 'ok' : ($health['status'] === 'degraded' ? 'warning' : 'critical') ?>">
                <?= strtoupper($health['status']) ?>
            </span>
        </div>

        <div class="dashboard-grid">
            <div class="metric-card">
                <h3>💾 Database Size</h3>
                <div class="metric-value"><?= $dbMetrics['database_size_mb'] ?></div>
                <div class="metric-label">MB</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width:<?= min(100, ($dbMetrics['database_size_mb'] / 1000) * 100) ?>%"></div>
                </div>
            </div>

            <div class="metric-card">
                <h3>💿 Disk Usage</h3>
                <div class="metric-value <?= $diskMetrics['disk_usage_percent'] > 80 ? 'danger' : ($diskMetrics['disk_usage_percent'] > 60 ? 'warning' : '') ?>">
                    <?= $diskMetrics['disk_usage_percent'] ?>%
                </div>
                <div class="metric-label">
                    <?= round($diskMetrics['disk_used_gb'], 1) ?>GB / <?= round($diskMetrics['disk_total_gb'], 1) ?>GB
                </div>
                <div class="progress-bar">
                    <div class="progress-fill <?= $diskMetrics['disk_usage_percent'] > 80 ? 'danger' : ($diskMetrics['disk_usage_percent'] > 60 ? 'warning' : '') ?>"
                         style="width:<?= $diskMetrics['disk_usage_percent'] ?>%"></div>
                </div>
            </div>

            <div class="metric-card">
                <h3>🧠 Memory Usage</h3>
                <div class="metric-value"><?= round($memoryMetrics['memory_current_mb'], 1) ?></div>
                <div class="metric-label">MB (Peak: <?= round($memoryMetrics['memory_peak_mb'], 1) ?>MB)</div>
            </div>

            <div class="metric-card">
                <h3>📡 API Calls (24h)</h3>
                <div class="metric-value"><?= number_format($apiStats['total_calls']) ?></div>
                <div class="metric-label">Avg: <?= round($apiStats['avg_response_time'] ?? 0, 1) ?>ms</div>
            </div>

            <?php if (isset($cacheStats)): ?>
            <div class="metric-card">
                <h3>⚡ Cache Hit Rate</h3>
                <div class="metric-value <?= $cacheStats['hit_rate'] < 50 ? 'warning' : '' ?>">
                    <?= $cacheStats['hit_rate'] ?>%
                </div>
                <div class="metric-label">
                    <?= $cacheStats['hits'] ?> hits / <?= $cacheStats['misses'] ?> misses
                </div>
                <div class="progress-bar">
                    <div class="progress-fill <?= $cacheStats['hit_rate'] < 50 ? 'warning' : '' ?>"
                         style="width:<?= $cacheStats['hit_rate'] ?>%"></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="metric-card">
                <h3>🚨 Active Alerts</h3>
                <div class="metric-value <?= count($activeAlerts) > 0 ? 'danger' : '' ?>">
                    <?= count($activeAlerts) ?>
                </div>
                <div class="metric-label">
                    Critical: <?= $alertStats['critical'] ?? 0 ?> | High: <?= $alertStats['high'] ?? 0 ?>
                </div>
            </div>

            <div class="metric-card">
                <h3>⏱️ Avg Response Time</h3>
                <div class="metric-value <?= ($apiStats['avg_response_time'] ?? 0) > 500 ? 'warning' : '' ?>">
                    <?= round($apiStats['avg_response_time'] ?? 0) ?>
                </div>
                <div class="metric-label">milliseconds</div>
            </div>

            <div class="metric-card">
                <h3>📊 Table Count</h3>
                <div class="metric-value"><?= $dbMetrics['table_count'] ?></div>
                <div class="metric-label">database tables</div>
            </div>
        </div>

        <?php if (!empty($activeAlerts)): ?>
        <div class="chart-container">
            <h2 class="section-title">🚨 Active Alerts</h2>
            <?php foreach ($activeAlerts as $alert): ?>
            <div class="alert-item <?= $alert['severity'] ?>">
                <div style="display:flex;justify-content:space-between;align-items:start;">
                    <div>
                        <strong style="font-size:1.1rem;color:#fff;"><?= htmlspecialchars($alert['title']) ?></strong>
                        <p style="margin:0.5rem 0;color:var(--muted);font-size:0.875rem;"><?= htmlspecialchars($alert['message']) ?></p>
                        <small style="color:var(--muted);font-size:0.75rem;">
                            Created: <?= ago($alert['created_at']) ?>
                            <?php if ($alert['current_value']): ?>
                            | Value: <?= $alert['current_value'] ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <span class="status-badge status-<?= $alert['severity'] === 'critical' ? 'critical' : ($alert['severity'] === 'high' ? 'warning' : 'ok') ?>">
                        <?= strtoupper($alert['severity']) ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="chart-container">
            <h2 class="section-title">🤖 Cron Jobs Status</h2>
            <table class="cron-table">
                <thead>
                    <tr>
                        <th>Job Name</th>
                        <th>Status</th>
                        <th>Last Run</th>
                        <th>Next Expected</th>
                        <th>Exec Time</th>
                        <th>Health</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cronJobs as $job): ?>
                    <tr>
                        <td><code style="background:rgba(0,0,0,.3);padding:0.25rem 0.5rem;border-radius:4px;font-family:'JetBrains Mono',monospace;"><?= htmlspecialchars($job['job_name']) ?></code></td>
                        <td><?= htmlspecialchars($job['status']) ?></td>
                        <td><?= $job['last_run'] ? ago($job['last_run']) : 'Never' ?></td>
                        <td><?= $job['next_expected_run'] ? date('H:i', strtotime($job['next_expected_run'])) : '-' ?></td>
                        <td><?= $job['execution_time_ms'] ? round($job['execution_time_ms']) . 'ms' : '-' ?></td>
                        <td>
                            <span class="status-badge status-<?= $job['health_status'] === 'ok' ? 'ok' : ($job['health_status'] === 'late' ? 'warning' : 'critical') ?>">
                                <?= strtoupper($job['health_status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="chart-container">
            <h2 class="section-title">✅ Health Checks</h2>
            <?php foreach ($health['checks'] as $checkName => $check): ?>
            <div class="alert-item <?= $check['status'] === 'ok' ? '' : ($check['status'] === 'warning' ? 'medium' : 'critical') ?>" style="margin-bottom:0.5rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <strong style="color:#fff;"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $checkName))) ?></strong>
                        <p style="margin:0.25rem 0 0 0;color:var(--muted);font-size:0.875rem;">
                            <?= htmlspecialchars($check['message']) ?>
                        </p>
                    </div>
                    <span class="status-badge status-<?= $check['status'] === 'ok' ? 'ok' : ($check['status'] === 'warning' ? 'warning' : 'critical') ?>">
                        <?= strtoupper($check['status']) ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:2rem;text-align:center;color:var(--muted);font-size:0.875rem;">
            <p>Last updated: <?= date('Y-m-d H:i:s') ?> | Auto-refresh every 60s</p>
        </div>
    </div>
</div>

<script>
    setTimeout(() => location.reload(), 60000);
</script>
</body>
</html>
