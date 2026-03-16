<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/UsageAnalytics.php';
requireLogin();

$user = currentUser();
$db = db();

// Check if analytics tables exist
try {
    $tables = $db->query("SHOW TABLES LIKE 'api_usage_tracking'")->fetchAll();
    if (empty($tables)) {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Setup Required</title>';
        echo '<link rel="stylesheet" href="/assets/main.css"></head>';
        echo '<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;">';
        echo '<div style="max-width:500px;text-align:center;padding:40px;background:var(--card);border-radius:12px;">';
        echo '<h1 style="color:#f0a500;margin-bottom:20px;">⚠️ Analytics Not Set Up</h1>';
        echo '<p style="margin-bottom:30px;">Analytics tracking has not been initialized yet. Please contact your administrator.</p>';
        echo '<a href="/dashboard/" style="display:inline-block;padding:12px 24px;background:#f0a500;color:#000;text-decoration:none;border-radius:8px;font-weight:700;">← Back to Dashboard</a>';
        echo '</div></body></html>';
        exit;
    }
} catch (Exception $e) {
    die('Database error: ' . htmlspecialchars($e->getMessage()));
}

$analytics = new UsageAnalytics($db);

try {
    $usageOverTime = $analytics->getUserUsageOverTime($user['id'], 30);
    $apiBreakdown = $analytics->getUserAPIBreakdown($user['id'], 30);
    $usagePercentage = $analytics->getUserUsagePercentage($user['id']);

    // Get lifetime total usage stats (all time)
    $lifetimeStats = $db->prepare("
        SELECT
            COUNT(*) as total_jobs,
            COALESCE(SUM(csv_rows),0) as total_rows,
            COALESCE(SUM(files_updated),0) as total_files
        FROM usage_log
        WHERE user_id=?
    ");
    $lifetimeStats->execute([$user['id']]);
    $lifetime = $lifetimeStats->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die('Analytics error: ' . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Analytics — BulkReplace</title>
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="stylesheet" href="/assets/main.css">
    <link rel="stylesheet" href="/assets/loading.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
<div id="toast-wrap"></div>
<div class="dash-layout">
<?php include __DIR__.'/_sidebar.php'; ?>
<div class="dash-main">
<div class="dash-topbar">
<h1 class="dash-page-title">📊 Usage Analytics</h1>
</div>
<div class="dash-content">

<!-- Stats Grid -->
<div class="stats-grid">
<div class="stat-card">
<div class="sc-label">Current Month Usage</div>
<div class="sc-val"><?= $usagePercentage['unlimited'] ? '∞' : number_format($usagePercentage['used']) ?></div>
<div class="sc-sub">
<?php if (!$usagePercentage['unlimited']): ?>
of <?= number_format($usagePercentage['total']) ?> rows
<?php else: ?>
Unlimited Usage
<?php endif; ?>
</div>
</div>

<div class="stat-card">
<div class="sc-label">API Calls (30d)</div>
<div class="sc-val" style="color:var(--ok);"><?= number_format(array_sum(array_column($apiBreakdown, 'total_calls'))) ?></div>
<div class="sc-sub">Across all endpoints</div>
</div>

<div class="stat-card">
<div class="sc-label">Success Rate</div>
<div class="sc-val" style="color:var(--a1);">
<?php
$totalCalls = array_sum(array_column($apiBreakdown, 'total_calls'));
$successCalls = array_sum(array_column($apiBreakdown, 'successful_calls'));
$successRate = $totalCalls > 0 ? ($successCalls / $totalCalls) * 100 : 100;
echo number_format($successRate, 1);
?>%
</div>
<div class="sc-sub"><?= number_format($successCalls) ?> successful</div>
</div>

<div class="stat-card">
<div class="sc-label">Avg Response</div>
<div class="sc-val" style="color:var(--a2);">
<?php
$avgTime = !empty($apiBreakdown)
    ? array_sum(array_column($apiBreakdown, 'avg_response_time')) / count($apiBreakdown)
    : 0;
echo number_format($avgTime, 0);
?>ms
</div>
<div class="sc-sub">Last 30 days</div>
</div>
</div>

<!-- Lifetime Total Usage Stats -->
<div class="card" style="margin-top:24px;margin-bottom:24px;background:linear-gradient(135deg,rgba(0,212,170,.08),rgba(0,212,170,.03));border:1px solid rgba(0,212,170,.2);">
<div class="card-title" style="color:var(--a2);">♾️ All-Time Usage Statistics</div>
<div class="stats-grid" style="margin-top:16px;">
<div style="text-align:center;padding:16px;">
<div style="font-size:32px;font-weight:900;color:var(--a2);font-family:'Syne',sans-serif;"><?= number_format($lifetime['total_rows']) ?></div>
<div style="font-size:11px;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:1.5px;">Total Rows</div>
</div>
<div style="text-align:center;padding:16px;">
<div style="font-size:32px;font-weight:900;color:var(--a1);font-family:'Syne',sans-serif;"><?= number_format($lifetime['total_jobs']) ?></div>
<div style="font-size:11px;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:1.5px;">Total Jobs</div>
</div>
<div style="text-align:center;padding:16px;">
<div style="font-size:32px;font-weight:900;color:var(--ok);font-family:'Syne',sans-serif;"><?= number_format($lifetime['total_files']) ?></div>
<div style="font-size:11px;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:1.5px;">Total Files</div>
</div>
<div style="text-align:center;padding:16px;">
<div style="font-size:32px;font-weight:900;color:#8b5cf6;font-family:'Syne',sans-serif;"><?= number_format(array_sum(array_column($apiBreakdown, 'total_calls'))) ?></div>
<div style="font-size:11px;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:1.5px;">API Calls (30d)</div>
</div>
</div>
</div>

<!-- Chart -->
<div class="card" style="margin-bottom:24px;">
<div class="card-title">📈 Usage Over Time (Last 30 Days)</div>
<canvas id="usageChart" height="80"></canvas>
</div>

<!-- API Breakdown -->
<div class="card">
<div class="card-title">🔧 API Calls Breakdown</div>
<?php if (!empty($apiBreakdown)): ?>
<div class="table-wrap">
<table class="data-table">
<thead>
<tr>
<th>Endpoint</th>
<th>Total Calls</th>
<th>Success Rate</th>
<th>Avg Response</th>
</tr>
</thead>
<tbody>
<?php foreach ($apiBreakdown as $api): ?>
<tr>
<td><b><?= htmlspecialchars($api['endpoint']) ?></b></td>
<td><?= number_format($api['total_calls']) ?></td>
<td>
<span class="badge badge-ok">
<?= number_format(($api['successful_calls'] / max($api['total_calls'], 1)) * 100, 1) ?>%
</span>
</td>
<td style="color:var(--a2);"><?= number_format($api['avg_response_time'], 0) ?>ms</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div style="text-align:center;padding:40px;color:var(--muted);font-family:'JetBrains Mono',monospace;font-size:12px;">
No API calls recorded in the last 30 days
</div>
<?php endif; ?>
</div>

</div>
</div>
</div>

<script>
const usageData = <?= json_encode($usageOverTime) ?>;
const ctx = document.getElementById('usageChart').getContext('2d');

Chart.defaults.color = 'rgba(200,200,232,0.7)';
Chart.defaults.borderColor = 'rgba(30,30,56,0.3)';
Chart.defaults.font.family = "'JetBrains Mono', monospace";
Chart.defaults.font.size = 11;

new Chart(ctx, {
    type: 'line',
    data: {
        labels: usageData.map(d => {
            const date = new Date(d.date);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }),
        datasets: [{
            label: 'Rows Used',
            data: usageData.map(d => d.rows_used),
            borderColor: '#f0a500',
            backgroundColor: 'rgba(240,165,0,0.08)',
            borderWidth: 2.5,
            fill: true,
            tension: 0.35,
            pointRadius: 4,
            pointHoverRadius: 7,
            pointBackgroundColor: '#f0a500',
            pointBorderColor: 'var(--card)',
            pointBorderWidth: 2,
            pointHoverBackgroundColor: '#f0a500',
            pointHoverBorderColor: '#fff',
            pointHoverBorderWidth: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        interaction: {
            mode: 'index',
            intersect: false
        },
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                backgroundColor: 'rgba(14,14,32,0.95)',
                titleColor: '#f0a500',
                bodyColor: '#c8c8e8',
                borderColor: 'rgba(240,165,0,0.2)',
                borderWidth: 1,
                padding: 12,
                cornerRadius: 8,
                titleFont: { size: 12, weight: 700 },
                bodyFont: { size: 11 },
                displayColors: false,
                callbacks: {
                    label: function(context) {
                        return 'Rows: ' + context.parsed.y.toLocaleString();
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return value.toLocaleString();
                    },
                    color: 'rgba(200,200,232,0.6)',
                    font: { size: 10 }
                },
                grid: {
                    color: 'rgba(30,30,56,0.5)',
                    drawBorder: false
                }
            },
            x: {
                ticks: {
                    color: 'rgba(200,200,232,0.6)',
                    font: { size: 10 }
                },
                grid: {
                    display: false
                }
            }
        }
    }
});
</script>
<script src="/assets/toast.js"></script>
</body>
</html>
