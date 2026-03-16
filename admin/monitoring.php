<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/SystemMonitor.php';
require_once __DIR__ . '/../includes/AlertManager.php';
require_once __DIR__ . '/../includes/EnhancedRateLimiter.php';

requireAdmin();

$db = db();

// Check if monitoring tables exist - check for system_metrics instead
try {
    $tables = $db->query("SHOW TABLES LIKE 'system_metrics'")->fetchAll();
    if (empty($tables)) {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Setup Required</title><link rel="stylesheet" href="/assets/main.css"></head><body>';
        echo '<div class="admin-wrap"><div style="max-width:600px;margin:100px auto;padding:20px;">';
        echo '<h1 style="color:var(--a1);">⚠️ Monitoring Setup Required</h1>';
        echo '<p>The monitoring tables have not been initialized yet. Please run the setup script first:</p>';
        echo '<a href="/api/init_monitoring.php" class="btn btn-primary" style="margin:20px 0;">Initialize Monitoring Tables</a>';
        echo '<p style="color:var(--muted);font-size:14px;">This only needs to be done once. After initialization, reload this page.</p>';
        echo '</div></div></body></html>';
        exit;
    }
} catch (Exception $e) {
    die('Database error: ' . htmlspecialchars($e->getMessage()));
}

try {
    $monitor = SystemMonitor::getInstance($db);
    $alertManager = AlertManager::getInstance($db, $monitor);
    $rateLimiter = new EnhancedRateLimiter($db, $monitor);

    // Get time range from query params
    $timeRange = $_GET['range'] ?? '24';
    $timeRange = in_array($timeRange, ['1', '6', '24', '168']) ? (int)$timeRange : 24;

    // Get all metrics
    $apiStats = $monitor->getApiStats($timeRange);
    $errorStats = $monitor->getErrorStats($timeRange);
    $dbMetrics = $monitor->getDatabaseMetrics();
    $diskMetrics = $monitor->getDiskUsage();
    $memoryMetrics = $monitor->getMemoryUsage();
    $queueMetrics = $monitor->getQueueMetrics();
    $paymentFailures = $monitor->getPaymentFailures($timeRange);
    $healthStatus = $monitor->getHealthStatus();

    // Get alerts
    $activeAlerts = $alertManager->getActiveAlerts();
    $alertStats = $alertManager->getAlertStats($timeRange);

    // Get rate limit stats
    $rateLimitStats = $rateLimiter->getStats(null, $timeRange);
    $blockedIPs = $rateLimiter->getBlockedIPs();

    // Get API key usage stats
    $apiKeyStats = $monitor->getApiKeyUsageStats($timeRange);
} catch (Exception $e) {
    $errorMsg = htmlspecialchars($e->getMessage());
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Monitoring Error</title><link rel="stylesheet" href="/assets/main.css"></head><body>';
    echo '<div class="admin-wrap"><div style="max-width:800px;margin:100px auto;padding:20px;">';
    echo '<h1 style="color:var(--err);">⚠️ Monitoring System Error</h1>';
    echo '<p>Error: <code style="background:var(--dim);padding:4px 8px;border-radius:4px;color:var(--err);">' . $errorMsg . '</code></p>';
    echo '<div style="margin:20px 0;display:flex;gap:12px;">';
    echo '<a href="/api/test_monitoring.php" class="btn btn-primary">Test Monitoring Setup</a>';
    echo '<a href="/api/init_monitoring.php" class="btn btn-ghost">Reinitialize Tables</a>';
    echo '</div>';
    echo '<p style="color:var(--muted);font-size:14px;">Run the test first to see detailed diagnostics.</p>';
    echo '</div></div></body></html>';
    exit;
}

// Get slowest endpoints
$slowestEndpoints = $monitor->getSlowestEndpoints(10, $timeRange);
$recentErrors = $monitor->getRecentErrors(20);

$title = "System Monitoring";
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
        .admin-topbar{background:rgba(255,69,96,.08);border-bottom:1px solid rgba(255,69,96,.2);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;}
        .admin-title{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;}
        .monitoring-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.5rem;margin-bottom:2rem;}
        .metric-card{background:var(--dim);border-radius:12px;padding:1.5rem;border:1px solid var(--border);}
        .metric-card h3{font-size:0.75rem;font-weight:600;color:var(--muted);margin:0 0 0.5rem 0;text-transform:uppercase;letter-spacing:1px;font-family:'JetBrains Mono',monospace;}
        .metric-value{font-size:2rem;font-weight:700;color:#fff;margin:0;font-family:'Syne',sans-serif;}
        .metric-label{font-size:0.875rem;color:var(--muted);margin-top:0.25rem;font-family:'JetBrains Mono',monospace;}
        .metric-card.critical{border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.08);}
        .metric-card.warning{border-color:rgba(245,158,11,.3);background:rgba(245,158,11,.08);}
        .metric-card.success{border-color:rgba(16,185,129,.3);background:rgba(16,185,129,.08);}
        .health-status{display:inline-flex;align-items:center;gap:0.5rem;padding:0.5rem 1rem;border-radius:9999px;font-weight:600;font-size:0.875rem;}
        .health-status.healthy{background:rgba(16,185,129,.15);color:#10b981;border:1px solid rgba(16,185,129,.3);}
        .health-status.degraded{background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.3);}
        .health-status.unhealthy{background:rgba(239,68,68,.15);color:#ef4444;border:1px solid rgba(239,68,68,.3);}
        .alert-item{background:var(--dim);border-left:4px solid var(--border);padding:1rem;margin-bottom:0.75rem;border-radius:6px;}
        .alert-item.critical{border-left-color:#ef4444;background:rgba(239,68,68,.08);}
        .alert-item.warning{border-left-color:#f59e0b;background:rgba(245,158,11,.08);}
        .alert-item.info{border-left-color:#3b82f6;background:rgba(59,130,246,.08);}
        .alert-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:0.5rem;}
        .alert-type{font-weight:600;font-size:0.875rem;text-transform:uppercase;font-family:'JetBrains Mono',monospace;}
        .alert-time{font-size:0.75rem;color:var(--muted);font-family:'JetBrains Mono',monospace;}
        .alert-message{font-size:0.875rem;color:var(--text);}
        .mon-data-table{width:100%;border-collapse:collapse;background:var(--dim);border-radius:8px;overflow:hidden;}
        .mon-data-table th{background:rgba(255,255,255,.03);padding:0.75rem 1rem;text-align:left;font-size:0.75rem;font-weight:600;color:var(--muted);border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:1px;font-family:'JetBrains Mono',monospace;}
        .mon-data-table td{padding:0.75rem 1rem;font-size:0.875rem;color:var(--text);border-bottom:1px solid var(--border);}
        .mon-data-table tr:last-child td{border-bottom:none;}
        .mon-badge{display:inline-block;padding:0.25rem 0.75rem;border-radius:9999px;font-size:0.75rem;font-weight:600;font-family:'JetBrains Mono',monospace;}
        .mon-badge.critical{background:rgba(239,68,68,.15);color:#ef4444;border:1px solid rgba(239,68,68,.3);}
        .mon-badge.warning{background:rgba(245,158,11,.15);color:#f59e0b;border:1px solid rgba(245,158,11,.3);}
        .mon-badge.success{background:rgba(16,185,129,.15);color:#10b981;border:1px solid rgba(16,185,129,.3);}
        .mon-badge.info{background:rgba(59,130,246,.15);color:#3b82f6;border:1px solid rgba(59,130,246,.3);}
        .time-range-selector{margin-bottom:1.5rem;display:flex;gap:8px;}
        .time-range-selector a{display:inline-block;padding:0.5rem 1rem;background:var(--dim);border:1px solid var(--border);border-radius:6px;color:var(--text);text-decoration:none;font-size:0.875rem;font-weight:500;font-family:'JetBrains Mono',monospace;}
        .time-range-selector a:hover{background:rgba(255,255,255,.05);}
        .time-range-selector a.active{background:var(--a1);color:#fff;border-color:var(--a1);}
        .section-title{font-size:1.25rem;font-weight:700;color:#fff;margin:2rem 0 1rem 0;font-family:'Syne',sans-serif;}
    </style>
</head>
<body>
<div class="admin-wrap">
    <?php include '_sidebar.php'; ?>
    <div style="max-width:1400px;margin:0 auto;padding:2rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;">
            <div>
                <h1 style="font-size:2rem;font-weight:700;margin:0;color:#fff;font-family:'Syne',sans-serif;">System Monitoring & Uptime</h1>
                <p style="color:var(--muted);margin:0.5rem 0 0 0;font-family:'JetBrains Mono',monospace;font-size:0.875rem;">Real-time system health, performance metrics & uptime monitoring</p>
            </div>
            <div style="display:flex;gap:1rem;align-items:center;">
                <button onclick="restartAPI()" id="restartBtn" class="btn btn-primary">
                    🔄 Restart API
                </button>
                <span class="health-status <?= $healthStatus['status'] ?>">
                    <?php if ($healthStatus['status'] === 'healthy'): ?>
                        ✅ System Healthy
                    <?php elseif ($healthStatus['status'] === 'degraded'): ?>
                        ⚠️ Degraded Performance
                    <?php else: ?>
                        🚨 System Issues
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <!-- Uptime Monitoring Setup -->
        <div style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.3);border-radius:12px;padding:1.5rem;margin-bottom:2rem;">
            <h2 style="color:#3b82f6;font-size:1.125rem;margin:0 0 0.75rem 0;font-family:'Syne',sans-serif;font-weight:700;">🚨 External Uptime Monitoring Setup</h2>
            <p style="color:var(--muted);font-size:0.875rem;line-height:1.6;margin:0 0 1rem 0;">Set up external monitoring with <strong>UptimeRobot</strong>, <strong>Better Uptime</strong>, or <strong>Pingdom</strong> to achieve 99.9%+ uptime tracking.</p>
            <p style="color:var(--muted);font-size:0.875rem;margin:0 0 0.5rem 0;"><strong>Monitor this endpoint:</strong></p>
            <div style="background:#000;border:1px solid var(--border);border-radius:8px;padding:1rem;font-family:'JetBrains Mono',monospace;font-size:0.875rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1rem;">
                <code id="health-endpoint" style="color:#10b981;"><?= APP_URL ?>/api/health.php</code>
                <button onclick="copyEndpoint()" class="btn btn-sm" style="background:var(--a1);color:#000;">Copy URL</button>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:1rem;">
                <div style="background:#1a1a1a;border:1px solid var(--border);border-radius:8px;padding:1.25rem;">
                    <strong style="color:#10b981;display:block;font-size:1rem;margin-bottom:0.5rem;font-family:'Syne',sans-serif;">UptimeRobot</strong>
                    <span style="font-size:0.75rem;color:var(--muted);display:block;margin-bottom:0.75rem;font-family:'JetBrains Mono',monospace;">Free • 50 monitors • 5-min intervals</span>
                    <a href="https://uptimerobot.com/" target="_blank" style="color:var(--a1);text-decoration:none;font-size:0.875rem;font-weight:600;">Sign Up →</a>
                </div>
                <div style="background:#1a1a1a;border:1px solid var(--border);border-radius:8px;padding:1.25rem;">
                    <strong style="color:#3b82f6;display:block;font-size:1rem;margin-bottom:0.5rem;font-family:'Syne',sans-serif;">Better Uptime</strong>
                    <span style="font-size:0.75rem;color:var(--muted);display:block;margin-bottom:0.75rem;font-family:'JetBrains Mono',monospace;">Free • 10 monitors • 30-sec intervals</span>
                    <a href="https://betteruptime.com/" target="_blank" style="color:var(--a1);text-decoration:none;font-size:0.875rem;font-weight:600;">Sign Up →</a>
                </div>
                <div style="background:#1a1a1a;border:1px solid var(--border);border-radius:8px;padding:1.25rem;">
                    <strong style="color:#f59e0b;display:block;font-size:1rem;margin-bottom:0.5rem;font-family:'Syne',sans-serif;">Pingdom</strong>
                    <span style="font-size:0.75rem;color:var(--muted);display:block;margin-bottom:0.75rem;font-family:'JetBrains Mono',monospace;">$10/mo • 1-min intervals • 50+ locations</span>
                    <a href="https://www.pingdom.com/" target="_blank" style="color:var(--a1);text-decoration:none;font-size:0.875rem;font-weight:600;">14-day Trial →</a>
                </div>
            </div>
        </div>

        <div class="time-range-selector">
            <a href="?range=1" class="<?= $timeRange === 1 ? 'active' : '' ?>">Last Hour</a>
            <a href="?range=6" class="<?= $timeRange === 6 ? 'active' : '' ?>">Last 6 Hours</a>
            <a href="?range=24" class="<?= $timeRange === 24 ? 'active' : '' ?>">Last 24 Hours</a>
            <a href="?range=168" class="<?= $timeRange === 168 ? 'active' : '' ?>">Last 7 Days</a>
        </div>

        <!-- API Metrics -->
        <h2 class="section-title">API Performance</h2>
        <div class="monitoring-grid">
            <div class="metric-card">
                <h3>Total API Calls</h3>
                <p class="metric-value"><?= number_format($apiStats['total_calls']) ?></p>
                <p class="metric-label">in last <?= $timeRange ?> hours</p>
            </div>

            <div class="metric-card <?= $apiStats['avg_response_time'] > 1000 ? 'warning' : 'success' ?>">
                <h3>Avg Response Time</h3>
                <p class="metric-value"><?= round($apiStats['avg_response_time']) ?>ms</p>
                <p class="metric-label">Max: <?= $apiStats['max_response_time'] ?>ms</p>
            </div>

            <div class="metric-card <?= $apiStats['error_rate'] > 5 ? 'critical' : ($apiStats['error_rate'] > 2 ? 'warning' : 'success') ?>">
                <h3>Error Rate</h3>
                <p class="metric-value"><?= $apiStats['error_rate'] ?>%</p>
                <p class="metric-label"><?= $apiStats['server_errors'] + $apiStats['client_errors'] ?> errors</p>
            </div>

            <div class="metric-card">
                <h3>Success Rate</h3>
                <p class="metric-value"><?= $apiStats['total_calls'] > 0 ? round(($apiStats['successful_calls'] / $apiStats['total_calls']) * 100, 1) : 0 ?>%</p>
                <p class="metric-label"><?= number_format($apiStats['successful_calls']) ?> successful</p>
            </div>

            <div class="metric-card">
                <h3>Unique IPs</h3>
                <p class="metric-value"><?= number_format($apiStats['unique_ips']) ?></p>
                <p class="metric-label"><?= number_format($apiStats['unique_users']) ?> unique users</p>
            </div>
        </div>

        <!-- System Resources -->
        <h2 class="section-title">System Resources</h2>
        <div class="monitoring-grid">
            <div class="metric-card <?= $diskMetrics['disk_usage_percent'] > 90 ? 'critical' : ($diskMetrics['disk_usage_percent'] > 80 ? 'warning' : '') ?>">
                <h3>Disk Usage</h3>
                <p class="metric-value"><?= $diskMetrics['disk_usage_percent'] ?>%</p>
                <p class="metric-label"><?= $diskMetrics['disk_used_gb'] ?>GB / <?= $diskMetrics['disk_total_gb'] ?>GB</p>
            </div>

            <div class="metric-card">
                <h3>Database Size</h3>
                <p class="metric-value"><?= $dbMetrics['database_size_mb'] ?>MB</p>
                <p class="metric-label"><?= $dbMetrics['table_count'] ?> tables</p>
            </div>

            <div class="metric-card">
                <h3>DB Connections</h3>
                <p class="metric-value"><?= $dbMetrics['active_connections'] ?></p>
                <p class="metric-label">Active connections</p>
            </div>

            <div class="metric-card">
                <h3>Memory Usage</h3>
                <p class="metric-value"><?= $memoryMetrics['memory_current_mb'] ?>MB</p>
                <p class="metric-label">Peak: <?= $memoryMetrics['memory_peak_mb'] ?>MB</p>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (count($activeAlerts) > 0): ?>
        <h2 class="section-title">🚨 Active Alerts (<?= count($activeAlerts) ?>)</h2>
        <div style="margin-bottom: 2rem;">
            <?php foreach ($activeAlerts as $alert): ?>
            <div class="alert-item <?= $alert['severity'] ?>">
                <div class="alert-header">
                    <span class="alert-type"><?= htmlspecialchars($alert['alert_type']) ?></span>
                    <span class="alert-time"><?= ago($alert['created_at']) ?></span>
                </div>
                <div class="alert-message"><?= htmlspecialchars($alert['message']) ?></div>
                <?php if ($alert['metric_value']): ?>
                <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.5rem;">
                    Value: <?= $alert['metric_value'] ?> / Threshold: <?= $alert['threshold_value'] ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Rate Limiting Stats -->
        <h2 class="section-title">Rate Limiting</h2>
        <div class="monitoring-grid">
            <div class="metric-card">
                <h3>Unique IPs Tracked</h3>
                <p class="metric-value"><?= number_format($rateLimitStats['unique_ips'] ?? 0) ?></p>
                <p class="metric-label">Total attempts: <?= number_format($rateLimitStats['total_attempts'] ?? 0) ?></p>
            </div>

            <div class="metric-card <?= ($rateLimitStats['currently_blocked'] ?? 0) > 10 ? 'warning' : '' ?>">
                <h3>Blocked IPs</h3>
                <p class="metric-value"><?= $rateLimitStats['currently_blocked'] ?? 0 ?></p>
                <p class="metric-label">Currently blocked</p>
            </div>

            <div class="metric-card">
                <h3>Burst Usage</h3>
                <p class="metric-value"><?= number_format($rateLimitStats['total_burst_used'] ?? 0) ?></p>
                <p class="metric-label">Total burst requests</p>
            </div>

            <div class="metric-card">
                <h3>Blocked IPs (Permanent)</h3>
                <p class="metric-value"><?= count($blockedIPs) ?></p>
                <p class="metric-label">Manual + Automatic</p>
            </div>
        </div>

        <!-- API Key Usage -->
        <h2 class="section-title">API Access Monitoring</h2>
        <div class="monitoring-grid">
            <div class="metric-card">
                <h3>Total API Calls</h3>
                <p class="metric-value"><?= number_format($apiKeyStats['total_api_calls']) ?></p>
                <p class="metric-label">via API keys</p>
            </div>

            <div class="metric-card">
                <h3>Active API Users</h3>
                <p class="metric-value"><?= number_format($apiKeyStats['unique_api_users']) ?></p>
                <p class="metric-label">unique users</p>
            </div>

            <div class="metric-card <?= $apiKeyStats['api_error_rate'] > 5 ? 'warning' : 'success' ?>">
                <h3>API Error Rate</h3>
                <p class="metric-value"><?= $apiKeyStats['api_error_rate'] ?>%</p>
                <p class="metric-label">errors in API calls</p>
            </div>

            <div class="metric-card">
                <h3>Avg Response Time</h3>
                <p class="metric-value"><?= $apiKeyStats['avg_response_time'] ?>ms</p>
                <p class="metric-label">API endpoints</p>
            </div>
        </div>

        <!-- Top API Users -->
        <?php if (count($apiKeyStats['top_api_users']) > 0): ?>
        <h3 style="font-size:1.125rem;font-weight:600;color:#fff;margin:2rem 0 1rem 0;font-family:'Syne',sans-serif;">Top API Users</h3>
        <table class="mon-data-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Plan</th>
                    <th>API Calls</th>
                    <th>Avg Response</th>
                    <th>Errors</th>
                    <th>Last Call</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($apiKeyStats['top_api_users'] as $user): ?>
                <tr>
                    <td>
                        <a href="/admin/users.php?id=<?= $user['id'] ?>" style="color:var(--a1);text-decoration:none;">
                            <?= htmlspecialchars($user['email']) ?>
                        </a>
                    </td>
                    <td><span class="mon-badge info"><?= strtoupper($user['plan']) ?></span></td>
                    <td><strong><?= number_format($user['api_calls']) ?></strong></td>
                    <td><?= round($user['avg_response_time']) ?>ms</td>
                    <td>
                        <?php if ($user['errors'] > 0): ?>
                            <span class="mon-badge warning"><?= $user['errors'] ?></span>
                        <?php else: ?>
                            <span style="color:var(--muted);">0</span>
                        <?php endif; ?>
                    </td>
                    <td><?= ago($user['last_call']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- API Endpoints Usage -->
        <?php if (count($apiKeyStats['api_endpoints']) > 0): ?>
        <h3 style="font-size:1.125rem;font-weight:600;color:#fff;margin:2rem 0 1rem 0;font-family:'Syne',sans-serif;">API Endpoints Usage</h3>
        <table class="mon-data-table">
            <thead>
                <tr>
                    <th>Endpoint</th>
                    <th>Calls</th>
                    <th>Avg Response</th>
                    <th>Errors</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($apiKeyStats['api_endpoints'] as $endpoint): ?>
                <tr>
                    <td><code><?= htmlspecialchars($endpoint['endpoint']) ?></code></td>
                    <td><?= number_format($endpoint['call_count']) ?></td>
                    <td><?= round($endpoint['avg_response_time']) ?>ms</td>
                    <td>
                        <?php if ($endpoint['errors'] > 0): ?>
                            <span class="mon-badge warning"><?= $endpoint['errors'] ?></span>
                        <?php else: ?>
                            <span style="color:var(--muted);">0</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $errorRate = $endpoint['call_count'] > 0
                            ? ($endpoint['errors'] / $endpoint['call_count']) * 100
                            : 0;
                        ?>
                        <?php if ($errorRate > 10): ?>
                            <span class="mon-badge critical">Critical</span>
                        <?php elseif ($errorRate > 5): ?>
                            <span class="mon-badge warning">Warning</span>
                        <?php else: ?>
                            <span class="mon-badge success">Good</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Payment Failures -->
        <?php if (count($paymentFailures) > 0): ?>
        <h2 class="section-title">Payment Failures</h2>
        <table class="mon-data-table">
            <thead>
                <tr>
                    <th>Gateway</th>
                    <th>Failures</th>
                    <th>Affected Users</th>
                    <th>Failed Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($paymentFailures as $failure): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($failure['payment_gateway']) ?></strong></td>
                    <td><?= $failure['gateway_failures'] ?></td>
                    <td><?= $failure['affected_users'] ?></td>
                    <td>$<?= number_format($failure['total_failed_amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Slowest Endpoints -->
        <h2 class="section-title">Slowest Endpoints</h2>
        <table class="mon-data-table">
            <thead>
                <tr>
                    <th>Endpoint</th>
                    <th>Calls</th>
                    <th>Avg Response</th>
                    <th>Max Response</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($slowestEndpoints as $endpoint): ?>
                <tr>
                    <td><code><?= htmlspecialchars($endpoint['endpoint']) ?></code></td>
                    <td><?= number_format($endpoint['call_count']) ?></td>
                    <td><?= round($endpoint['avg_response_time']) ?>ms</td>
                    <td><?= $endpoint['max_response_time'] ?>ms</td>
                    <td>
                        <?php if ($endpoint['avg_response_time'] > 2000): ?>
                            <span class="mon-badge critical">Critical</span>
                        <?php elseif ($endpoint['avg_response_time'] > 1000): ?>
                            <span class="mon-badge warning">Slow</span>
                        <?php else: ?>
                            <span class="mon-badge success">Good</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Recent Errors -->
        <h2 class="section-title">Recent Errors</h2>
        <table class="mon-data-table">
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
                <?php foreach ($recentErrors as $error): ?>
                <tr>
                    <td><?= ago($error['created_at']) ?></td>
                    <td><code><?= htmlspecialchars($error['error_type']) ?></code></td>
                    <td><span class="mon-badge <?= $error['severity'] ?>"><?= $error['severity'] ?></span></td>
                    <td><?= htmlspecialchars(substr($error['error_message'], 0, 100)) ?></td>
                    <td><?= $error['error_file'] ? basename($error['error_file']) . ':' . $error['error_line'] : '-' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top: 2rem; text-align: center;">
            <a href="index.php" style="color: #3b82f6; text-decoration: none; font-weight: 500;">← Back to Admin Dashboard</a>
        </div>
    </div>

    <script>
        // Auto-refresh every 30 seconds
        setTimeout(() => {
            location.reload();
        }, 30000);

        // Copy endpoint function
        function copyEndpoint() {
            const endpoint = document.getElementById('health-endpoint').textContent;
            navigator.clipboard.writeText(endpoint).then(() => {
                const btn = event.target;
                const orig = btn.textContent;
                btn.textContent = '✓ Copied!';
                btn.style.background = '#10b981';
                setTimeout(() => {
                    btn.textContent = orig;
                    btn.style.background = '';
                }, 2000);
            });
        }

        // Restart API function
        async function restartAPI() {
            const btn = document.getElementById('restartBtn');
            const originalHTML = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = '⏳ Restarting...';
            btn.style.opacity = '0.7';

            try {
                const response = await fetch('/api/restart_trigger.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ action: 'restart' })
                });

                const contentType = response.headers.get('content-type');
                let data;

                if (contentType && contentType.includes('application/json')) {
                    data = await response.json();
                } else {
                    const text = await response.text();
                    console.error('Non-JSON response:', text);
                    throw new Error('Server returned non-JSON response. Check console for details.');
                }

                if (!response.ok) {
                    throw new Error(data.error || `HTTP ${response.status}: ${response.statusText}`);
                }

                if (data.success) {
                    btn.innerHTML = '✅ Restarted!';
                    btn.style.background = '#10b981';

                    if (data.actions && data.actions.length > 0) {
                        console.log('Restart actions:', data.actions);
                    }

                    if (data.warnings && data.warnings.length > 0) {
                        console.warn('Restart warnings:', data.warnings);
                    }

                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    console.error('Restart failed:', data.error);
                    alert('Restart failed: ' + (data.error || 'Unknown error'));

                    btn.innerHTML = '❌ Failed';
                    btn.style.background = '#ef4444';

                    setTimeout(() => {
                        btn.innerHTML = originalHTML;
                        btn.style.background = '';
                        btn.disabled = false;
                        btn.style.opacity = '1';
                    }, 3000);
                }
            } catch (error) {
                console.error('Restart error:', error);
                alert('Restart error: ' + error.message);

                btn.innerHTML = '❌ Error';
                btn.style.background = '#ef4444';

                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                    btn.style.background = '';
                    btn.disabled = false;
                    btn.style.opacity = '1';
                }, 3000);
            }
        }
    </script>
</body>
</html>
