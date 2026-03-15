<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/UsageAnalytics.php';
require_once __DIR__ . '/../includes/QueryCache.php';
requireAdmin();

$db = db();

// Check if analytics tables exist
try {
    $tables = $db->query("SHOW TABLES LIKE 'api_usage_tracking'")->fetchAll();
    if (empty($tables)) {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Setup Required</title><link rel="stylesheet" href="/assets/main.css"></head><body>';
        echo '<div class="admin-wrap"><div style="max-width:600px;margin:100px auto;padding:20px;">';
        echo '<h1 style="color:var(--a1);">⚠️ Analytics Setup Required</h1>';
        echo '<p>The analytics tables have not been initialized yet. Please run the setup script first:</p>';
        echo '<a href="/api/fix_analytics_columns.php" class="btn btn-primary" style="margin:20px 0;">Initialize Analytics Tables</a>';
        echo '<p style="color:var(--muted);font-size:14px;">This only needs to be done once. After initialization, reload this page.</p>';
        echo '</div></div></body></html>';
        exit;
    }
} catch (Exception $e) {
    die('Database error: ' . htmlspecialchars($e->getMessage()));
}

$analytics = new UsageAnalytics($db);

try {
    // Cache expensive revenue queries for 5 minutes
    $revenueMetrics = QueryCache::remember('revenue_metrics', function() use ($analytics) {
        return $analytics->getRevenueMetrics();
    }, 300);

    $topUsers = QueryCache::remember('revenue_top_users', function() use ($analytics) {
        return $analytics->getTopUsersByUsage(10);
    }, 300);

    $churnRate = QueryCache::remember('revenue_churn_rate', function() use ($analytics) {
        return $analytics->getChurnRate();
    }, 300);

    $mrrGrowth = QueryCache::remember('revenue_mrr_growth', function() use ($analytics) {
        return $analytics->getMRRGrowth(12);
    }, 300);

    $planDistribution = QueryCache::remember('revenue_plan_distribution', function() use ($analytics) {
        return $analytics->getPlanDistribution();
    }, 300);

    $addonRevenue = QueryCache::remember('revenue_addon_revenue', function() use ($analytics) {
        return $analytics->getAddonRevenue();
    }, 300);
} catch (Exception $e) {
    $errorMsg = htmlspecialchars($e->getMessage());
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Fix Required</title><link rel="stylesheet" href="/assets/main.css"></head><body>';
    echo '<div class="admin-wrap"><div style="max-width:700px;margin:100px auto;padding:20px;">';
    echo '<h1 style="color:var(--a1);">⚠️ Missing Database Columns</h1>';
    echo '<p>Error: <code style="background:var(--dim);padding:4px 8px;border-radius:4px;color:var(--err);">' . $errorMsg . '</code></p>';
    echo '<p>Some required database columns are missing. Click the button below to add them:</p>';
    echo '<a href="/api/fix_analytics_columns.php" class="btn btn-primary" style="margin:20px 0;">Fix Database Columns</a>';
    echo '<p style="color:var(--muted);font-size:14px;margin-top:30px;">After clicking the button above, refresh this page. The fix is safe to run multiple times.</p>';
    echo '</div></div></body></html>';
    exit;
}
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Revenue — Admin — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.admin-wrap{min-height:100vh;}
.chart-container{background:var(--dim);border-radius:12px;padding:20px;margin-bottom:24px;}
.chart-title{font-family:'Syne',sans-serif;font-size:16px;font-weight:700;color:#fff;margin-bottom:16px;}
canvas{max-height:300px;}
</style>
</head><body>
<div class="admin-wrap">
  <?php include '_sidebar.php'; ?>
  <div style="padding:28px 32px;">

    <!-- REVENUE METRICS -->
    <div class="stats-grid" style="margin-bottom:24px;">
      <div class="stat-card">
        <div class="sc-val" style="color:var(--ok);">$<?= number_format($revenueMetrics['mrr'], 2) ?></div>
        <div class="sc-label">💰 MRR</div>
        <div class="sc-sub">Monthly Recurring Revenue</div>
      </div>
      <div class="stat-card">
        <div class="sc-val" style="color:var(--a2);">$<?= number_format($revenueMetrics['arr'], 2) ?></div>
        <div class="sc-label">📊 ARR</div>
        <div class="sc-sub">Annual Recurring Revenue</div>
      </div>
      <div class="stat-card">
        <div class="sc-val" style="color:var(--a1);">$<?= number_format($revenueMetrics['arpu'], 2) ?></div>
        <div class="sc-label">👤 ARPU</div>
        <div class="sc-sub">Average Revenue Per User</div>
      </div>
      <div class="stat-card">
        <div class="sc-val" style="color:var(--purple);"><?= $churnRate ?>%</div>
        <div class="sc-label">📉 Churn Rate</div>
        <div class="sc-sub">Last 30 days</div>
      </div>
    </div>

    <!-- CUSTOMER STATS -->
    <div class="stats-grid" style="margin-bottom:24px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
      <div class="stat-card">
        <div class="sc-val"><?= $revenueMetrics['total_customers'] ?></div>
        <div class="sc-label">Total Customers</div>
      </div>
      <div class="stat-card">
        <div class="sc-val" style="color:var(--ok);"><?= $revenueMetrics['paying_users'] ?></div>
        <div class="sc-label">Paying Users</div>
      </div>
      <div class="stat-card">
        <div class="sc-val" style="color:var(--muted);"><?= $revenueMetrics['free_users'] ?></div>
        <div class="sc-label">Free Users</div>
      </div>
      <div class="stat-card">
        <div class="sc-val" style="color:var(--a1);"><?= $revenueMetrics['conversion_rate'] ?>%</div>
        <div class="sc-label">Conversion Rate</div>
      </div>
    </div>

    <!-- MRR GROWTH CHART -->
    <?php if (!empty($mrrGrowth)): ?>
    <div class="chart-container">
      <div class="chart-title">📈 MRR Growth (Last 12 Months)</div>
      <canvas id="mrrChart"></canvas>
    </div>
    <?php endif; ?>

    <!-- TWO COLUMN LAYOUT -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">

      <!-- PLAN DISTRIBUTION -->
      <div class="card">
        <div class="card-title">📊 Plan Distribution</div>
        <div style="display:flex;flex-direction:column;gap:12px;">
          <?php foreach($planDistribution as $plan): ?>
          <div style="background:var(--dim);border-radius:8px;padding:12px;display:flex;justify-content:space-between;align-items:center;">
            <div>
              <div style="font-weight:600;color:#fff;"><?= ucfirst($plan['plan']) ?></div>
              <div style="font-size:12px;color:var(--muted);"><?= $plan['count'] ?> users</div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:var(--a1);"><?= $plan['count'] ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ADDON REVENUE -->
      <div class="card">
        <div class="card-title">🎁 Addon Revenue</div>
        <?php if (empty($addonRevenue)): ?>
        <p style="color:var(--muted);text-align:center;padding:20px;">No addon purchases yet</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:12px;">
          <?php foreach($addonRevenue as $addon): ?>
          <div style="background:var(--dim);border-radius:8px;padding:12px;display:flex;justify-content:space-between;align-items:center;">
            <div>
              <div style="font-weight:600;color:#fff;"><?= htmlspecialchars($addon['name']) ?></div>
              <div style="font-size:12px;color:var(--muted);"><?= $addon['total_purchases'] ?> purchases</div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:var(--ok);">$<?= number_format($addon['total_revenue'], 2) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- TOP USERS BY USAGE -->
    <div class="card">
      <div class="card-title">🔥 Top Users by Usage (Last 30 Days)</div>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>User</th><th>Plan</th><th>Total Rows</th><th>API Calls</th></tr></thead>
          <tbody>
          <?php foreach($topUsers as $user): ?>
          <tr>
            <td><b style="color:#fff;"><?= htmlspecialchars($user['email']) ?></b></td>
            <td><span class="plan-pill pp-<?= $user['plan'] ?>"><?= ucfirst($user['plan']) ?></span></td>
            <td style="color:var(--a1);font-weight:600;"><?= number_format($user['total_rows']) ?></td>
            <td style="color:var(--muted);"><?= number_format($user['api_calls']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<?php if (!empty($mrrGrowth)): ?>
<script>
const mrrData = <?= json_encode(array_values(array_column($mrrGrowth, 'mrr'))) ?>;
const mrrLabels = <?= json_encode(array_values(array_column($mrrGrowth, 'month'))) ?>;
const payingCustomers = <?= json_encode(array_values(array_column($mrrGrowth, 'paying_customers'))) ?>;

const ctx = document.getElementById('mrrChart').getContext('2d');
new Chart(ctx, {
  type: 'line',
  data: {
    labels: mrrLabels,
    datasets: [{
      label: 'MRR ($)',
      data: mrrData,
      borderColor: '#10b981',
      backgroundColor: 'rgba(16, 185, 129, 0.1)',
      tension: 0.4,
      fill: true
    }, {
      label: 'Paying Customers',
      data: payingCustomers,
      borderColor: '#6366f1',
      backgroundColor: 'rgba(99, 102, 241, 0.1)',
      tension: 0.4,
      fill: true,
      yAxisID: 'y1'
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: true,
    plugins: {
      legend: {
        labels: { color: '#9ca3af' }
      }
    },
    scales: {
      x: {
        ticks: { color: '#9ca3af' },
        grid: { color: 'rgba(255,255,255,0.05)' }
      },
      y: {
        ticks: { color: '#9ca3af' },
        grid: { color: 'rgba(255,255,255,0.05)' },
        position: 'left'
      },
      y1: {
        ticks: { color: '#9ca3af' },
        grid: { display: false },
        position: 'right'
      }
    }
  }
});
</script>
<?php endif; ?>
</body></html>
