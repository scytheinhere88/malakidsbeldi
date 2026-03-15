<?php
require_once dirname(__DIR__).'/config.php';
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
    // Cache expensive queries for 5 minutes (300 seconds)
    $revenueMetrics = QueryCache::remember('analytics_revenue_metrics', function() use ($analytics) {
        return $analytics->getRevenueMetrics();
    }, 300);

    $topUsers = QueryCache::remember('analytics_top_users', function() use ($analytics) {
        return $analytics->getTopUsersByUsage(10);
    }, 300);

    $planDistribution = QueryCache::remember('analytics_plan_distribution', function() use ($analytics) {
        return $analytics->getPlanDistribution();
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
<title>Analytics — Admin — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>.admin-wrap{min-height:100vh;}</style>
</head><body>
<div class="admin-wrap">
  <?php include '_sidebar.php'; ?>
  <div style="padding:28px 32px;">

    <!-- REVENUE OVERVIEW -->
    <div class="stats-grid" style="margin-bottom:24px;">
      <div class="stat-card"><div class="sc-val" style="color:var(--ok);">$<?= number_format($revenueMetrics['mrr'], 2) ?></div><div class="sc-label">MRR</div><div class="sc-sub">Monthly Recurring Revenue</div></div>
      <div class="stat-card"><div class="sc-val" style="color:var(--a2);">$<?= number_format($revenueMetrics['arr'], 2) ?></div><div class="sc-label">ARR</div><div class="sc-sub">Annual Recurring Revenue</div></div>
      <div class="stat-card"><div class="sc-val" style="color:var(--a1);">$<?= number_format($revenueMetrics['arpu'], 2) ?></div><div class="sc-label">ARPU</div><div class="sc-sub">Average Revenue Per User</div></div>
      <div class="stat-card"><div class="sc-val"><?= $revenueMetrics['paying_users'] ?></div><div class="sc-label">Paying Customers</div><div class="sc-sub"><?= $revenueMetrics['conversion_rate'] ?>% conversion</div></div>
    </div>

    <!-- PLAN BREAKDOWN -->
    <div class="card" style="margin-bottom:24px;">
      <div class="card-title">📊 Plan Distribution</div>
      <div style="display:flex;gap:16px;flex-wrap:wrap;">
        <?php foreach($planDistribution as $plan): ?>
        <div style="background:var(--dim);border-radius:10px;padding:14px 20px;text-align:center;min-width:120px;">
          <div style="font-family:'Syne',sans-serif;font-size:26px;font-weight:800;color:var(--a1);"><?= $plan['count'] ?></div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-top:4px;"><?= ucfirst($plan['plan']) ?></div>
        </div>
        <?php endforeach; ?>
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
</body></html>
