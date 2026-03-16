<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/UsageAnalytics.php';
requireLogin();

$user = currentUser();
$db   = db();

$analytics = new UsageAnalytics($db);

try {
    $usageOverTime   = $analytics->getUserUsageOverTime($user['id'], 30);
    $usagePercentage = $analytics->getUserUsagePercentage($user['id']);

    $lifetimeStmt = $db->prepare("
        SELECT
            COUNT(*) as total_jobs,
            COALESCE(SUM(csv_rows),0) as total_rows,
            COALESCE(SUM(files_updated),0) as total_files,
            MAX(created_at) as last_job_at
        FROM usage_log
        WHERE user_id=?
    ");
    $lifetimeStmt->execute([$user['id']]);
    $lifetime = $lifetimeStmt->fetch(PDO::FETCH_ASSOC);

    $typeStmt = $db->prepare("
        SELECT
            COALESCE(job_type, 'unknown') as job_type,
            COUNT(*) as count,
            COALESCE(SUM(csv_rows), 0) as rows
        FROM usage_log
        WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY COALESCE(job_type, 'unknown')
        ORDER BY count DESC
    ");
    $typeStmt->execute([$user['id']]);
    $jobTypes = $typeStmt->fetchAll(PDO::FETCH_ASSOC);

    $recentStmt = $db->prepare("
        SELECT job_type, csv_rows, files_updated, created_at
        FROM usage_log
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $recentStmt->execute([$user['id']]);
    $recentJobs = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die('Analytics error: ' . htmlspecialchars($e->getMessage()));
}

$usageByDate = [];
foreach ($usageOverTime as $row) {
    $usageByDate[$row['date']] = (int)$row['rows_used'];
}

$chartLabels = [];
$chartRows   = [];
for ($i = 29; $i >= 0; $i--) {
    $date          = date('Y-m-d', strtotime("-$i days"));
    $label         = date('M j', strtotime($date));
    $chartLabels[] = $label;
    $chartRows[]   = $usageByDate[$date] ?? 0;
}

$totalRowsLast30 = array_sum($chartRows);
$activeDays      = count(array_filter($chartRows, fn($v) => $v > 0));
$peakDay         = !empty($chartRows) ? max($chartRows) : 0;
$avgPerActiveDay = $activeDays > 0 ? round($totalRowsLast30 / $activeDays, 0) : 0;

$typeColors = [
    'csv'      => '#f0a500',
    'zip'      => '#00d4aa',
    'copy'     => '#60a5fa',
    'autopilot'=> '#c084fc',
    'scraper'  => '#fb923c',
    'unknown'  => '#6b7280',
];
$typeLabels = [
    'csv'      => 'CSV Replace',
    'zip'      => 'ZIP Manager',
    'copy'     => 'Copy & Rename',
    'autopilot'=> 'Autopilot',
    'scraper'  => 'Scraper',
    'unknown'  => 'Other',
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Analytics — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.analytics-stat{background:var(--dim);border-radius:12px;padding:18px 20px;border:1px solid var(--border);position:relative;overflow:hidden;}
.analytics-stat::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--_accent,var(--a1));}
.analytics-stat .as-val{font-family:'Syne',sans-serif;font-size:28px;font-weight:900;color:#fff;line-height:1;margin:8px 0 4px;}
.analytics-stat .as-label{font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);}
.analytics-stat .as-sub{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:4px;}

.chart-container{position:relative;width:100%;}
.chart-header{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:20px;}
.chart-legend{display:flex;gap:16px;flex-wrap:wrap;}
.legend-item{display:flex;align-items:center;gap:6px;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);}
.legend-dot{width:8px;height:8px;border-radius:50%;}

.job-type-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.04);}
.job-type-row:last-child{border-bottom:none;}
.jt-bar-wrap{flex:1;height:6px;background:var(--dim);border-radius:3px;overflow:hidden;}
.jt-bar{height:100%;border-radius:3px;transition:width .4s ease;}
.jt-name{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text);min-width:110px;}
.jt-count{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);text-align:right;min-width:60px;}

.quota-mini{background:linear-gradient(135deg,rgba(240,165,0,.08),rgba(240,165,0,.03));border:1px solid rgba(240,165,0,.2);border-radius:12px;padding:18px 20px;}
.quota-bar-wrap{height:8px;background:var(--dim);border-radius:4px;overflow:hidden;margin:10px 0 6px;}
.quota-bar-fill{height:100%;border-radius:4px;transition:width .5s ease;}

.recent-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.04);}
.recent-row:last-child{border-bottom:none;}
.recent-type-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.recent-info{flex:1;min-width:0;}
.recent-type-label{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text);}
.recent-meta{font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-top:2px;}
.recent-rows{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:var(--a1);text-align:right;}
</style>
</head>
<body>
<div class="dash-layout">
<?php include __DIR__.'/_sidebar.php'; ?>
<div class="dash-main">
<div class="dash-topbar">
  <div class="dash-page-title">Usage Analytics</div>
</div>
<div class="dash-content">

<!-- TOP STATS GRID -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px;">
  <div class="analytics-stat" style="--_accent:var(--a1);">
    <div class="as-label">Rows (30d)</div>
    <div class="as-val"><?= number_format($totalRowsLast30) ?></div>
    <div class="as-sub">across <?= $activeDays ?> active day<?= $activeDays !== 1 ? 's' : '' ?></div>
  </div>
  <div class="analytics-stat" style="--_accent:var(--a2);">
    <div class="as-label">Total Jobs (30d)</div>
    <div class="as-val"><?= number_format(array_sum(array_column($jobTypes, 'count'))) ?></div>
    <div class="as-sub"><?= count($jobTypes) ?> job type<?= count($jobTypes) !== 1 ? 's' : '' ?></div>
  </div>
  <div class="analytics-stat" style="--_accent:#60a5fa;">
    <div class="as-label">Peak Day</div>
    <div class="as-val"><?= number_format($peakDay) ?></div>
    <div class="as-sub">rows in a single day</div>
  </div>
  <div class="analytics-stat" style="--_accent:#c084fc;">
    <div class="as-label">Avg / Active Day</div>
    <div class="as-val"><?= number_format($avgPerActiveDay) ?></div>
    <div class="as-sub">rows per active day</div>
  </div>
</div>

<!-- MAIN GRID: Chart + Quota + Donut -->
<div style="display:grid;grid-template-columns:1fr 300px;gap:20px;margin-bottom:24px;align-items:start;">

  <!-- USAGE CHART -->
  <div class="card" style="margin-bottom:0;">
    <div class="chart-header">
      <div>
        <div class="card-title" style="margin-bottom:2px;">Daily Usage — Last 30 Days</div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);">Rows processed per day</div>
      </div>
      <div class="chart-legend">
        <div class="legend-item">
          <div class="legend-dot" style="background:var(--a1);"></div>
          Rows Used
        </div>
      </div>
    </div>
    <div class="chart-container">
      <canvas id="usageChart" height="100"></canvas>
    </div>
  </div>

  <!-- RIGHT COLUMN -->
  <div style="display:flex;flex-direction:column;gap:16px;">

    <!-- QUOTA MINI -->
    <div class="quota-mini">
      <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--a1);margin-bottom:4px;">This Month's Quota</div>
      <?php if($usagePercentage['unlimited']): ?>
      <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);">Unlimited usage</div>
      <div class="quota-bar-wrap"><div class="quota-bar-fill" style="width:20%;background:var(--a2);"></div></div>
      <?php else: ?>
      <div style="font-family:'Syne',sans-serif;font-size:22px;font-weight:900;color:#fff;">
        <?= number_format($usagePercentage['used']) ?><span style="font-size:13px;font-weight:400;color:var(--muted);">/ <?= number_format($usagePercentage['total']) ?></span>
      </div>
      <?php
        $pct = min(100, $usagePercentage['percentage']);
        $barCol = $pct >= 90 ? 'var(--err)' : ($pct >= 70 ? 'var(--a1)' : 'var(--a2)');
      ?>
      <div class="quota-bar-wrap">
        <div class="quota-bar-fill" style="width:<?= $pct ?>%;background:<?= $barCol ?>;"></div>
      </div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:<?= $barCol ?>;"><?= $usagePercentage['percentage'] ?>% used</div>
      <?php endif; ?>
    </div>

    <!-- JOB TYPE DONUT -->
    <div class="card" style="margin-bottom:0;padding:16px;">
      <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--text);margin-bottom:14px;">Jobs by Type (30d)</div>
      <?php if(!empty($jobTypes)): ?>
      <div style="position:relative;width:140px;height:140px;margin:0 auto 16px;">
        <canvas id="donutChart"></canvas>
        <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;">
          <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:900;color:#fff;"><?= array_sum(array_column($jobTypes,'count')) ?></div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;">jobs</div>
        </div>
      </div>
      <div>
        <?php
        $maxJobCount = max(array_column($jobTypes, 'count') ?: [1]);
        foreach ($jobTypes as $jt):
            $slug  = $jt['job_type'];
            $color = $typeColors[$slug] ?? '#6b7280';
            $label = $typeLabels[$slug] ?? ucfirst($slug);
            $pct   = round(($jt['count'] / $maxJobCount) * 100);
        ?>
        <div class="job-type-row">
          <div class="jt-name"><?= htmlspecialchars($label) ?></div>
          <div class="jt-bar-wrap">
            <div class="jt-bar" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div>
          </div>
          <div class="jt-count"><?= number_format($jt['count']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div style="text-align:center;padding:24px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);">No jobs in last 30 days</div>
      <?php endif; ?>
    </div>

  </div>
</div>

<!-- BOTTOM GRID: Lifetime Stats + Recent Jobs -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">

  <!-- ALL-TIME STATS -->
  <div class="card" style="margin-bottom:0;background:linear-gradient(135deg,rgba(0,212,170,.07),rgba(0,212,170,.02));border:1px solid rgba(0,212,170,.18);">
    <div class="card-title" style="color:var(--a2);margin-bottom:16px;">All-Time Statistics</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <div style="text-align:center;padding:14px;background:var(--dim);border-radius:10px;">
        <div style="font-family:'Syne',sans-serif;font-size:26px;font-weight:900;color:var(--a2);"><?= number_format($lifetime['total_rows']) ?></div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-top:4px;">Total Rows</div>
      </div>
      <div style="text-align:center;padding:14px;background:var(--dim);border-radius:10px;">
        <div style="font-family:'Syne',sans-serif;font-size:26px;font-weight:900;color:var(--a1);"><?= number_format($lifetime['total_jobs']) ?></div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-top:4px;">Total Jobs</div>
      </div>
      <div style="text-align:center;padding:14px;background:var(--dim);border-radius:10px;">
        <div style="font-family:'Syne',sans-serif;font-size:26px;font-weight:900;color:var(--ok);"><?= number_format($lifetime['total_files']) ?></div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-top:4px;">Files Updated</div>
      </div>
      <div style="text-align:center;padding:14px;background:var(--dim);border-radius:10px;">
        <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#c084fc;margin-top:6px;">
          <?= $lifetime['last_job_at'] ? date('M j, Y', strtotime($lifetime['last_job_at'])) : '—' ?>
        </div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-top:4px;">Last Job</div>
      </div>
    </div>
  </div>

  <!-- RECENT JOBS -->
  <div class="card" style="margin-bottom:0;">
    <div class="card-title" style="margin-bottom:16px;">Recent Jobs</div>
    <?php if(!empty($recentJobs)): ?>
    <?php foreach($recentJobs as $job):
      $jSlug  = $job['job_type'] ?? 'unknown';
      $jColor = $typeColors[$jSlug] ?? '#6b7280';
      $jLabel = $typeLabels[$jSlug] ?? ucfirst($jSlug);
    ?>
    <div class="recent-row">
      <div class="recent-type-dot" style="background:<?= $jColor ?>;"></div>
      <div class="recent-info">
        <div class="recent-type-label"><?= htmlspecialchars($jLabel) ?></div>
        <div class="recent-meta"><?= date('M j, g:i A', strtotime($job['created_at'])) ?> &bull; <?= number_format($job['files_updated'] ?? 0) ?> files</div>
      </div>
      <div class="recent-rows"><?= number_format($job['csv_rows']) ?></div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div style="text-align:center;padding:32px 16px;">
      <div style="font-size:32px;margin-bottom:10px;">◎</div>
      <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:var(--text);margin-bottom:6px;">No jobs yet</div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);">Run your first job to see analytics</div>
      <a href="/tool/" class="btn btn-amber" style="display:inline-block;margin-top:14px;">Start a Job</a>
    </div>
    <?php endif; ?>
  </div>

</div>

</div>
</div>
</div>

<script>
Chart.defaults.color             = 'rgba(200,200,232,0.6)';
Chart.defaults.borderColor       = 'rgba(255,255,255,0.05)';
Chart.defaults.font.family       = "'JetBrains Mono', monospace";
Chart.defaults.font.size         = 10;

const chartLabels = <?= json_encode($chartLabels) ?>;
const chartRows   = <?= json_encode($chartRows) ?>;

new Chart(document.getElementById('usageChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: chartLabels,
        datasets: [
            {
                type: 'line',
                label: 'Rows',
                data: chartRows,
                borderColor: '#f0a500',
                backgroundColor: 'transparent',
                borderWidth: 2,
                tension: 0.4,
                pointRadius: chartRows.map(v => v > 0 ? 4 : 0),
                pointHoverRadius: 6,
                pointBackgroundColor: '#f0a500',
                pointBorderColor: '#0e0e20',
                pointBorderWidth: 2,
                order: 1,
                yAxisID: 'y'
            },
            {
                type: 'bar',
                label: 'Daily',
                data: chartRows,
                backgroundColor: chartRows.map(v => v > 0 ? 'rgba(240,165,0,0.15)' : 'rgba(255,255,255,0.03)'),
                borderColor: chartRows.map(v => v > 0 ? 'rgba(240,165,0,0.4)' : 'rgba(255,255,255,0.06)'),
                borderWidth: 1,
                borderRadius: 4,
                order: 2,
                yAxisID: 'y'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(14,14,32,0.97)',
                titleColor: '#f0a500',
                bodyColor: '#c8c8e8',
                borderColor: 'rgba(240,165,0,0.2)',
                borderWidth: 1,
                padding: 12,
                cornerRadius: 8,
                displayColors: false,
                callbacks: {
                    label: ctx => ctx.datasetIndex === 0
                        ? (ctx.parsed.y > 0 ? 'Rows: ' + ctx.parsed.y.toLocaleString() : 'No activity')
                        : null,
                    filter: item => item.datasetIndex === 0
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(255,255,255,0.04)' },
                ticks: {
                    callback: v => v.toLocaleString(),
                    color: 'rgba(200,200,232,0.5)',
                    maxTicksLimit: 5
                }
            },
            x: {
                grid: { display: false },
                ticks: {
                    color: 'rgba(200,200,232,0.45)',
                    maxRotation: 0,
                    autoSkip: true,
                    maxTicksLimit: 10
                }
            }
        }
    }
});

<?php if(!empty($jobTypes)): ?>
const donutColors = <?= json_encode(array_map(fn($jt) => $typeColors[$jt['job_type']] ?? '#6b7280', $jobTypes)) ?>;
const donutLabels = <?= json_encode(array_map(fn($jt) => $typeLabels[$jt['job_type']] ?? ucfirst($jt['job_type']), $jobTypes)) ?>;
const donutData   = <?= json_encode(array_column($jobTypes, 'count')) ?>;

new Chart(document.getElementById('donutChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: donutLabels,
        datasets: [{
            data: donutData,
            backgroundColor: donutColors.map(c => c + '99'),
            borderColor: donutColors,
            borderWidth: 1.5,
            hoverOffset: 4
        }]
    },
    options: {
        responsive: true,
        cutout: '72%',
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(14,14,32,0.97)',
                titleColor: '#fff',
                bodyColor: '#c8c8e8',
                borderColor: 'rgba(255,255,255,0.1)',
                borderWidth: 1,
                padding: 10,
                cornerRadius: 8,
                callbacks: {
                    label: ctx => ' ' + ctx.parsed + ' jobs'
                }
            }
        }
    }
});
<?php endif; ?>
</script>
<script src="/assets/toast.js"></script>
</body>
</html>
