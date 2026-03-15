<?php
require_once dirname(__DIR__).'/config.php';
startSession();
requireLogin();
try {
    $user=currentUser();
    if(!$user){
        session_destroy();
        header('Location: /auth/login.php?error=session_expired');
        exit;
    }
    $plan=getPlan($user['plan'] ?? 'free');
    $quota=getUserQuota($user['id']);
} catch(Exception $e) {
    error_log('Dashboard Error: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html><head><title>Error</title><style>body{font-family:monospace;padding:40px;background:#0a0a0a;color:#999;}h1{color:#ff4560;}.error-box{background:#1a1a1a;padding:20px;border-radius:8px;border-left:4px solid #ff4560;margin:20px 0;}</style></head><body>';
    echo '<h1>Dashboard Error</h1>';
    echo '<div class="error-box"><p>An error occurred while loading your dashboard.</p></div>';
    echo '<p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><a href="/dashboard/" style="color:#00d4aa;">Try Again</a> or <a href="'.SUPPORT_TELEGRAM_URL.'" style="color:#00d4aa;">Contact Support</a></p>';
    echo '</body></html>';
    exit;
}
// Recent usage
try {
    $recent=db()->prepare("SELECT * FROM usage_log WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
    $recent->execute([$user['id']]);
    $jobs=$recent->fetchAll();
} catch(Exception $e) {
    $jobs = [];
}
// This month totals
try {
    $mstats=db()->prepare("SELECT COUNT(*) as jobs, COALESCE(SUM(csv_rows),0) as rows, COALESCE(SUM(files_updated),0) as files FROM usage_log WHERE user_id=? AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())");
    $mstats->execute([$user['id']]);
    $ms=$mstats->fetch();
} catch(Exception $e) {
    $ms = ['jobs'=>0, 'rows'=>0, 'files'=>0];
}
// Lifetime total stats (all time)
try {
    $lifetimeStats=db()->prepare("SELECT COUNT(*) as total_jobs, COALESCE(SUM(csv_rows),0) as total_rows, COALESCE(SUM(files_updated),0) as total_files, COALESCE(SUM(CASE WHEN job_type='autopilot' THEN csv_rows ELSE 0 END),0) as autopilot_rows FROM usage_log WHERE user_id=?");
    $lifetimeStats->execute([$user['id']]);
    $lifetime=$lifetimeStats->fetch();
} catch(Exception $e) {
    $lifetime = ['total_jobs'=>0, 'total_rows'=>0, 'total_files'=>0, 'autopilot_rows'=>0];
}
// Quota pct
$total_limit = $quota['limit'] + $quota['rollover'];
$qpct=$quota['unlimited']?0:($total_limit>0?min(100,round($quota['used']/$total_limit*100)):100);
$qclass=$qpct>=90?'danger':($qpct>=70?'warn':'');
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Dashboard — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<link rel="stylesheet" href="/assets/loading.css"></head><body>
<div id="toast-wrap"></div>
<div class="dash-layout">
<?php include __DIR__.'/_sidebar.php'; ?>
<div class="dash-main">
  <div class="dash-topbar">
    <div class="dash-page-title">Dashboard</div>
    <div style="display:flex;align-items:center;gap:12px;">
      <?php if($user['plan']==='free'): ?>
      <button onclick="showLicenseModal()" class="btn btn-ghost btn-sm" style="border:1px dashed rgba(240,165,0,.3);color:var(--a1);">🔑 Activate License</button>
      <a href="/landing/pricing.php" class="btn btn-amber btn-sm">⚡ Upgrade Plan</a>
      <?php endif; ?>
      <a href="/tool/" class="btn btn-teal btn-sm">🚀 Open Tool</a>
    </div>
  </div>
  <div class="dash-content">

    <!-- WELCOME -->
    <div style="background:linear-gradient(135deg,rgba(240,165,0,.08),rgba(240,165,0,.03));border:1px solid rgba(240,165,0,.15);border-radius:16px;padding:24px 28px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
      <div>
        <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:#fff;">Welcome back, <?= htmlspecialchars($user['name']) ?>! 👋</div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-top:4px;">
          Plan: <span class="plan-pill pp-<?= $user['plan'] ?>"><?= ucfirst($user['plan']) ?></span>
          <?php if($user['plan_expires_at']&&$user['plan']!=='lifetime'): ?>
          &nbsp; Expires: <b style="color:var(--warn);"><?= date('M j, Y',strtotime($user['plan_expires_at'])) ?></b>
          <?php endif; ?>
        </div>
      </div>
      <a href="/tool/" class="btn btn-amber">🚀 Start New Job</a>
    </div>

    <!-- STATS -->
    <div class="stats-grid">
      <div class="stat-card" style="border-top:2px solid rgba(240,165,0,.4);">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px;">
          <div style="width:36px;height:36px;background:rgba(240,165,0,.1);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;">⚡</div>
          <?php if(!$quota['unlimited'] && $qpct>=70): ?>
          <span class="badge <?= $qpct>=90?'badge-red':'badge-warn' ?>" style="font-size:8px;"><?= $qpct ?>%</span>
          <?php elseif($quota['unlimited']): ?>
          <span class="badge badge-purple" style="font-size:8px;">∞</span>
          <?php endif; ?>
        </div>
        <div class="sc-val" style="color:var(--a1);"><?= $quota['unlimited']?'∞':number_format($quota['remaining']) ?></div>
        <div class="sc-label">Rows Remaining</div>
        <div class="sc-sub"><?= $quota['unlimited'] ? 'No monthly limit' : 'of '.number_format($total_limit).' total' ?></div>
      </div>
      <div class="stat-card" style="border-top:2px solid rgba(0,212,170,.3);">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px;">
          <div style="width:36px;height:36px;background:rgba(0,212,170,.1);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;">🗂️</div>
          <?php if($ms['jobs']>0): ?>
          <span class="badge badge-teal" style="font-size:8px;">Active</span>
          <?php endif; ?>
        </div>
        <div class="sc-val" style="color:var(--a2);"><?= number_format($ms['jobs']) ?></div>
        <div class="sc-label">Jobs This Month</div>
        <div class="sc-sub"><?= number_format($ms['rows']) ?> rows processed</div>
      </div>
      <div class="stat-card" style="border-top:2px solid rgba(0,230,118,.3);">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px;">
          <div style="width:36px;height:36px;background:rgba(0,230,118,.1);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;">📄</div>
        </div>
        <div class="sc-val" style="color:var(--ok);"><?= number_format($ms['files']) ?></div>
        <div class="sc-label">Files Updated</div>
        <div class="sc-sub">This month</div>
      </div>
      <div class="stat-card" style="border-top:2px solid rgba(192,132,252,.3);">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px;">
          <div style="width:36px;height:36px;background:rgba(192,132,252,.1);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;">🔄</div>
          <?php if($quota['rollover']>0): ?><span class="badge badge-purple" style="font-size:8px;">Bonus</span><?php endif; ?>
        </div>
        <div class="sc-val" style="color:var(--purple);"><?= number_format($quota['rollover']) ?></div>
        <div class="sc-label">Rollover Balance</div>
        <div class="sc-sub"><?= ($user['plan'] !== 'free') ? 'Carries to next month' : 'Upgrade to unlock' ?></div>
      </div>
    </div>

    <!-- LIFETIME USAGE STATS (Only for lifetime plan users) -->
    <?php if($user['plan'] === 'lifetime'): ?>
    <div class="card" style="margin-top:24px;margin-bottom:24px;background:linear-gradient(135deg,rgba(0,212,170,.08),rgba(0,212,170,.03));border:1px solid rgba(0,212,170,.2);">
      <div class="card-title" style="color:var(--a2);">♾️ Lifetime Total Usage</div>
      <div class="stats-grid" style="margin-top:16px;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));">
        <div style="text-align:center;padding:16px;">
          <div style="font-size:32px;font-weight:900;color:var(--a2);font-family:'Syne',sans-serif;"><?= number_format($lifetime['total_rows']) ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:1.5px;">Total Rows Processed</div>
        </div>
        <div style="text-align:center;padding:16px;">
          <div style="font-size:32px;font-weight:900;color:var(--a1);font-family:'Syne',sans-serif;"><?= number_format($lifetime['total_jobs']) ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:1.5px;">Total Jobs</div>
        </div>
        <div style="text-align:center;padding:16px;">
          <div style="font-size:32px;font-weight:900;color:var(--ok);font-family:'Syne',sans-serif;"><?= number_format($lifetime['total_files']) ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:1.5px;">Total Files Updated</div>
        </div>
        <div style="text-align:center;padding:16px;">
          <div style="font-size:32px;font-weight:900;color:#c084fc;font-family:'Syne',sans-serif;"><?= number_format($lifetime['autopilot_rows']) ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:1.5px;">Autopilot Usage</div>
        </div>
        <div style="text-align:center;padding:16px;">
          <div style="font-size:32px;font-weight:900;color:#8b5cf6;font-family:'Syne',sans-serif;">∞</div>
          <div style="font-size:11px;color:var(--muted);margin-top:4px;font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:1.5px;">No Limits Ever</div>
        </div>
      </div>
      <div style="text-align:center;margin-top:16px;padding:12px;background:rgba(0,0,0,.2);border-radius:8px;">
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:rgba(200,200,232,.6);">
          <span style="color:var(--a2);font-weight:700;">🎉 Lifetime Access</span> • Unlimited usage • No monthly quotas • No expiration
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- QUOTA BAR -->
    <?php if(!$quota['unlimited']): ?>
    <?php
      $resetDate = date('M j', strtotime('first day of next month'));
      $daysLeft = (int)((strtotime('first day of next month') - time()) / 86400);
    ?>
    <div class="card" style="margin-bottom:24px;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
        <div class="card-title" style="margin-bottom:0;">📊 Monthly Quota</div>
        <div style="display:flex;align-items:center;gap:8px;">
          <span style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);">Resets <?= $resetDate ?> · <?= $daysLeft ?> day<?= $daysLeft!=1?'s':'' ?> left</span>
          <span class="badge <?= $qpct>=90?'badge-red':($qpct>=70?'badge-warn':'badge-teal') ?>"><?= $qpct ?>% used</span>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;">
        <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:12px;text-align:center;">
          <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:var(--a1);"><?= number_format($quota['used']) ?></div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:3px;">Used</div>
        </div>
        <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:12px;text-align:center;">
          <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:var(--ok);"><?= number_format($quota['remaining']) ?></div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:3px;">Remaining</div>
        </div>
        <div style="background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:10px;padding:12px;text-align:center;">
          <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:var(--text);"><?= number_format($total_limit) ?></div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:3px;">Total Limit</div>
        </div>
      </div>

      <div style="position:relative;">
        <div style="background:rgba(255,255,255,.05);border-radius:100px;height:10px;overflow:hidden;position:relative;">
          <div class="quota-fill <?= $qclass ?>" style="width:<?= $qpct ?>%;height:100%;border-radius:100px;position:relative;">
            <div style="position:absolute;right:0;top:50%;transform:translateY(-50%);width:12px;height:12px;border-radius:50%;background:inherit;box-shadow:0 0 8px currentColor;"></div>
          </div>
        </div>
        <?php if($quota['rollover'] > 0): ?>
        <?php $rolloverPct = min(100, round($quota['rollover']/$total_limit*100)); ?>
        <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--a2);margin-top:5px;display:flex;align-items:center;gap:6px;">
          <span style="display:inline-block;width:8px;height:3px;background:var(--a2);border-radius:2px;"></span>
          Includes <?= number_format($quota['rollover']) ?> rollover rows from last month
        </div>
        <?php endif; ?>
      </div>

      <?php if($user['plan']==='free'): ?>
      <div class="warn-box" style="margin-top:14px;margin-bottom:0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <span>Free plan: <?= number_format($quota['remaining']) ?> of 20 rows left this month.</span>
        <a href="/landing/pricing.php" class="btn btn-amber btn-sm">Upgrade to Pro →</a>
      </div>
      <?php elseif($qpct>=90): ?>
      <div class="err-box" style="margin-top:14px;margin-bottom:0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <span>⚠ Critical: Only <?= number_format($quota['remaining']) ?> rows remaining!</span>
        <a href="/dashboard/billing.php" class="btn btn-amber btn-sm">Upgrade Now →</a>
      </div>
      <?php elseif($qpct>=70): ?>
      <div class="warn-box" style="margin-top:14px;margin-bottom:0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <span>You've used <?= $qpct ?>% of your quota this month.</span>
        <a href="/dashboard/billing.php" style="color:var(--a1);font-family:'JetBrains Mono',monospace;font-size:10px;">Consider upgrading →</a>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- RECENT JOBS -->
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
        <div class="card-title" style="margin-bottom:0;">📋 Recent Jobs</div>
        <?php if(!empty($jobs)): ?>
        <a href="/tool/" class="btn btn-ghost btn-sm">+ New Job</a>
        <?php endif; ?>
      </div>
      <?php if(empty($jobs)): ?>
      <div style="text-align:center;padding:48px 20px;">
        <div style="font-size:40px;margin-bottom:12px;opacity:.5;">🚀</div>
        <div style="font-family:'Syne',sans-serif;font-size:16px;font-weight:700;color:#fff;margin-bottom:8px;">No jobs yet</div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-bottom:20px;line-height:1.8;">Upload a CSV and start replacing content in bulk across multiple files.</div>
        <a href="/tool/" class="btn btn-amber">🚀 Run Your First Job →</a>
      </div>
      <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Time</th>
              <th>Type</th>
              <th>Rows</th>
              <th>Files</th>
              <th>Job Name</th>
            </tr>
          </thead>
          <tbody>
          <?php
          $typeConfig = [
            'csv_generator' => ['label'=>'CSV Gen',     'color'=>'#3b82f6', 'bg'=>'rgba(59,130,246,.1)',  'icon'=>'📊'],
            'zip_manager'   => ['label'=>'ZIP Mgr',     'color'=>'#f59e0b', 'bg'=>'rgba(245,158,11,.1)',  'icon'=>'🗜️'],
            'copy_rename'   => ['label'=>'Copy/Rename', 'color'=>'#10b981', 'bg'=>'rgba(16,185,129,.1)',  'icon'=>'📋'],
            'autopilot'     => ['label'=>'Autopilot',   'color'=>'#c084fc', 'bg'=>'rgba(192,132,252,.1)', 'icon'=>'🤖'],
          ];
          $defaultType = ['label'=>'Bulk Replace', 'color'=>'var(--a2)', 'bg'=>'rgba(0,212,170,.08)', 'icon'=>'⚡'];
          foreach($jobs as $j):
            $jtype = $j['job_type'] ?? 'bulk_replace';
            $tc = $typeConfig[$jtype] ?? $defaultType;
          ?>
          <tr>
            <td style="color:var(--muted);white-space:nowrap;"><?= ago($j['created_at']) ?></td>
            <td>
              <span style="display:inline-flex;align-items:center;gap:5px;background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>;border-radius:6px;padding:3px 8px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;">
                <?= $tc['icon'] ?> <?= $tc['label'] ?>
              </span>
            </td>
            <td>
              <span style="color:var(--a1);font-weight:700;"><?= number_format($j['csv_rows']) ?></span>
              <span style="color:var(--muted);font-size:10px;"> rows</span>
            </td>
            <td>
              <span style="color:var(--ok);font-weight:700;"><?= number_format($j['files_updated']) ?></span>
              <span style="color:var(--muted);font-size:10px;"> files</span>
            </td>
            <td style="color:var(--text);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($j['job_name']??'Untitled Job') ?>">
              <?= htmlspecialchars($j['job_name']??'Untitled Job') ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="padding:12px 0 0;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);text-align:right;">
        Showing last <?= count($jobs) ?> jobs · <a href="/dashboard/analytics.php" style="color:var(--a1);">View all analytics →</a>
      </div>
      <?php endif; ?>
    </div>

    <!-- QUICK ACTIONS + SUPPORT -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:24px;">
      <div style="background:var(--card);border:1px solid rgba(240,165,0,.15);border-radius:16px;padding:24px;">
        <div style="font-size:28px;margin-bottom:10px;">⚡</div>
        <div style="font-family:'Syne',sans-serif;font-size:15px;font-weight:700;color:#fff;margin-bottom:6px;">Quick Actions</div>
        <div style="display:flex;flex-direction:column;gap:6px;margin-top:12px;">
          <a href="/tool/" style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;background:rgba(240,165,0,.06);border:1px solid rgba(240,165,0,.15);font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--a1);text-decoration:none;transition:all .15s;" onmouseover="this.style.background='rgba(240,165,0,.1)'" onmouseout="this.style.background='rgba(240,165,0,.06)'">→ Open Bulk Replace Tool</a>
          <?php if(hasAddonAccess($user['id'], 'csv-generator-pro')): ?>
          <a href="/dashboard/csv_generator.php" style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.15);font-family:'JetBrains Mono',monospace;font-size:11px;color:#3b82f6;text-decoration:none;transition:all .15s;" onmouseover="this.style.background='rgba(59,130,246,.1)'" onmouseout="this.style.background='rgba(59,130,246,.06)'">→ CSV Generator</a>
          <?php endif; ?>
          <a href="/dashboard/analytics.php" style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;background:rgba(0,212,170,.05);border:1px solid rgba(0,212,170,.15);font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--a2);text-decoration:none;transition:all .15s;" onmouseover="this.style.background='rgba(0,212,170,.09)'" onmouseout="this.style.background='rgba(0,212,170,.05)'">→ View Analytics</a>
        </div>
      </div>
      <div style="background:var(--card);border:1px solid rgba(0,212,170,.15);border-radius:16px;padding:24px;display:flex;flex-direction:column;justify-content:space-between;">
        <div>
          <div style="font-size:28px;margin-bottom:10px;">💬</div>
          <div style="font-family:'Syne',sans-serif;font-size:15px;font-weight:700;color:#fff;margin-bottom:6px;">Need help?</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);line-height:1.8;">Direct support via Telegram. We usually respond within a few hours.</div>
        </div>
        <a href="<?= SUPPORT_TELEGRAM_URL ?>" target="_blank" class="btn btn-teal btn-sm" style="margin-top:16px;justify-content:center;">💬 Chat on Telegram</a>
      </div>
    </div>
    <style>@media(max-width:600px){.quick-support-grid{grid-template-columns:1fr!important;}}</style>

  </div>
</div>
</div>

<!-- LICENSE ACTIVATION MODAL (Free Users Only) -->
<?php if($user['plan'] === 'free'): ?>
<div id="license-modal" class="modal-overlay" style="display:none;">
  <div class="modal-box" style="max-width:580px;animation:modalFadeIn .3s ease-out;">
    <div style="position:relative;padding:32px;">

      <!-- Close button -->
      <button onclick="closeLicenseModal()" style="position:absolute;top:16px;right:16px;background:rgba(255,255,255,.05);border:1px solid var(--border);width:32px;height:32px;border-radius:8px;color:var(--muted);font-size:18px;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;" onmouseover="this.style.background='rgba(255,255,255,.1)';this.style.color='#fff';" onmouseout="this.style.background='rgba(255,255,255,.05)';this.style.color='var(--muted)';">×</button>

      <!-- Header -->
      <div style="text-align:center;margin-bottom:28px;">
        <div style="display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;background:linear-gradient(135deg,rgba(240,165,0,.15),rgba(192,132,252,.08));border:2px solid rgba(240,165,0,.3);border-radius:16px;margin-bottom:16px;">
          <span style="font-size:32px;">🔑</span>
        </div>
        <h2 style="font-family:'Syne',sans-serif;font-size:24px;font-weight:900;color:#fff;margin:0 0 8px;">Unlock Premium Features</h2>
        <p style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);margin:0;">Already purchased? Activate your license instantly</p>
      </div>

      <!-- License Key Input -->
      <div style="background:var(--dim);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:20px;">
        <label style="font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);display:block;margin-bottom:10px;">License Key</label>
        <input type="text" id="modal-license-key" placeholder="XXXX-XXXX-XXXX-XXXX" style="width:100%;background:var(--card);border:1px solid var(--border);border-radius:8px;padding:12px 16px;color:#fff;font-family:'JetBrains Mono',monospace;font-size:14px;letter-spacing:1px;margin-bottom:12px;" onkeypress="if(event.key==='Enter')activateLicenseFromModal()">

        <div id="modal-product-type-wrap">
        <label style="font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);display:block;margin-bottom:10px;">Product Type <span id="modal-auto-detect-badge" style="display:none;background:rgba(0,212,170,.15);color:var(--a2);padding:2px 8px;border-radius:4px;font-size:9px;margin-left:6px;">AUTO DETECTED</span></label>
        <select id="modal-product-type" style="width:100%;background:var(--card);border:1px solid var(--border);border-radius:8px;padding:12px 16px;color:#fff;font-family:'JetBrains Mono',monospace;font-size:13px;">
          <option value="">-- Select Product --</option>
          <optgroup label="Subscription Plans">
            <option value="pro-monthly">Pro Monthly</option>
            <option value="pro-yearly">Pro Yearly</option>
            <option value="platinum-monthly">Platinum Monthly</option>
            <option value="platinum-yearly">Platinum Yearly</option>
          </optgroup>
          <optgroup label="Lifetime & Bundles">
            <option value="lifetime">Lifetime Plan</option>
            <option value="premium-bundle">Premium Bundle (All Add-ons)</option>
            <option value="autopilot">Autopilot Bundle</option>
          </optgroup>
          <optgroup label="Add-ons">
            <option value="csv-generator-pro">CSV Generator</option>
            <option value="zip-manager">ZIP Manager</option>
            <option value="copy-rename">Copy & Rename</option>
          </optgroup>
        </select>
        </div>
        <div id="modal-gumroad-hint" style="display:none;margin-top:8px;padding:8px 12px;background:rgba(0,212,170,.06);border:1px solid rgba(0,212,170,.2);border-radius:8px;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--a2);">
          Gumroad key detected — product will be auto-detected. No need to select.
        </div>

        <div id="modal-activation-msg" style="margin-top:12px;"></div>
      </div>

      <!-- CTA Buttons -->
      <div style="display:flex;gap:12px;margin-bottom:20px;">
        <button onclick="activateLicenseFromModal()" class="btn btn-amber" style="flex:1;justify-content:center;">
          <span id="modal-activate-text">🔓 Activate License</span>
        </button>
        <button onclick="closeLicenseModal()" class="btn btn-ghost" style="flex:1;justify-content:center;">Continue Free</button>
      </div>

      <!-- Info Box -->
      <div style="background:rgba(0,212,170,.06);border:1px dashed rgba(0,212,170,.25);border-radius:10px;padding:14px;">
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:1.8;">
          <strong style="color:var(--a2);">💡 How to activate</strong><br>
          • Paste your Gumroad license key (format: <code style="color:var(--a1);">XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX</code>)<br>
          • Works for: Pro, Platinum, Lifetime, and all add-ons<br>
          • Product is auto-detected from Gumroad key — no manual selection needed<br>
          • <a href="/landing/pricing.php" style="color:var(--a1);">View all plans & pricing →</a>
        </div>
      </div>

    </div>
  </div>
</div>

<style>
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.85);
  backdrop-filter: blur(8px);
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.modal-box {
  background: var(--card);
  border: 1px solid rgba(240, 165, 0, 0.2);
  border-radius: 20px;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
  position: relative;
}
@keyframes modalFadeIn {
  from {
    opacity: 0;
    transform: translateY(-20px) scale(0.95);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}
</style>

<script>
// License Modal Functions
function showLicenseModal() {
  document.getElementById('license-modal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function closeLicenseModal() {
  document.getElementById('license-modal').style.display = 'none';
  document.body.style.overflow = '';
  localStorage.setItem('license_modal_dismissed', Date.now());
}

function isGumroadKeyFormat(key) {
  return /^[A-F0-9]{8}-[A-F0-9]{8}-[A-F0-9]{8}-[A-F0-9]{8}$/i.test(key);
}

document.getElementById('modal-license-key').addEventListener('input', function() {
  const key = this.value.trim();
  const isGumroad = isGumroadKeyFormat(key);
  const productWrap = document.getElementById('modal-product-type-wrap');
  const gumroadHint = document.getElementById('modal-gumroad-hint');
  const autoBadge = document.getElementById('modal-auto-detect-badge');

  if (isGumroad) {
    productWrap.style.opacity = '0.4';
    productWrap.style.pointerEvents = 'none';
    gumroadHint.style.display = 'block';
    autoBadge.style.display = 'inline';
    document.getElementById('modal-product-type').value = '';
  } else {
    productWrap.style.opacity = '1';
    productWrap.style.pointerEvents = '';
    gumroadHint.style.display = 'none';
    autoBadge.style.display = 'none';
  }
});

async function activateLicenseFromModal() {
  const key = document.getElementById('modal-license-key').value.trim();
  const product = document.getElementById('modal-product-type').value;
  const btn = document.querySelector('#license-modal .btn-amber');
  const btnText = document.getElementById('modal-activate-text');
  const msgDiv = document.getElementById('modal-activation-msg');
  const isGumroad = isGumroadKeyFormat(key);

  if (!key) {
    msgDiv.innerHTML = '<div class="err-box" style="font-size:11px;">Please enter your license key</div>';
    return;
  }
  if (!isGumroad && !product) {
    msgDiv.innerHTML = '<div class="err-box" style="font-size:11px;">Please select a product type</div>';
    return;
  }

  btn.disabled = true;
  btnText.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite;">⏳</span> Activating...';
  msgDiv.innerHTML = '';

  try {
    const res = await fetch('/api/activate_license.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ license_key: key, ...(product ? { product_slug: product } : {}) })
    });
    const result = await res.json();

    if (result.success) {
      msgDiv.innerHTML = `<div class="info-box" style="background:rgba(0,212,170,.08);border-color:rgba(0,212,170,.3);font-size:11px;">✅ ${result.message || 'License activated successfully!'}<br><span style="font-size:10px;">Reloading page...</span></div>`;
      setTimeout(() => location.reload(), 2000);
    } else {
      msgDiv.innerHTML = `<div class="err-box" style="font-size:11px;">⚠ ${result.error || 'Activation failed. Please check your license key.'}</div>`;
      btn.disabled = false;
      btnText.textContent = '🔓 Activate License';
    }
  } catch (error) {
    msgDiv.innerHTML = '<div class="err-box" style="font-size:11px;">⚠ Network error. Please try again.</div>';
    btn.disabled = false;
    btnText.textContent = '🔓 Activate License';
  }
}

// Auto-show modal for free users (once per 7 days)
document.addEventListener('DOMContentLoaded', function() {
  const dismissed = localStorage.getItem('license_modal_dismissed');
  const sevenDays = 7 * 24 * 60 * 60 * 1000;

  if (!dismissed || (Date.now() - parseInt(dismissed)) > sevenDays) {
    // Show modal after 2 seconds delay
    setTimeout(() => showLicenseModal(), 2000);
  }
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeLicenseModal();
});

// Close modal on outside click
document.getElementById('license-modal')?.addEventListener('click', function(e) {
  if (e.target === this) closeLicenseModal();
});
</script>
<?php endif; ?>

<script>function toast(m,t='ok'){const w=document.getElementById('toast-wrap');const el=document.createElement('div');el.className='toast '+t;el.innerHTML=`<span>${t==='ok'?'✅':t==='err'?'❌':'⚠️'}</span><span>${m}</span>`;w.appendChild(el);setTimeout(()=>{el.style.cssText+='opacity:0;transform:translateX(30px);transition:all .3s';setTimeout(()=>el.remove(),300);},3500);}</script>
</body></html>
