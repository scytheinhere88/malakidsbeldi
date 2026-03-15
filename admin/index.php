<?php
require_once dirname(__DIR__).'/config.php';
requireAdmin();
// Stats
$total_users=(int)db()->query("SELECT COUNT(*) FROM users")->fetchColumn();
$paid_users=(int)db()->query("SELECT COUNT(*) FROM users WHERE plan!='free'")->fetchColumn();
$total_rows=(int)db()->query("SELECT COALESCE(SUM(csv_rows),0) FROM usage_log WHERE MONTH(created_at)=MONTH(NOW())")->fetchColumn();
$new_today=(int)db()->query("SELECT COUNT(*) FROM users WHERE DATE(created_at)=CURDATE()")->fetchColumn();
// Recent users
$users=db()->query("SELECT u.*, (SELECT COALESCE(SUM(csv_rows),0) FROM usage_log WHERE user_id=u.id AND MONTH(created_at)=MONTH(NOW())) as month_rows FROM users u ORDER BY u.created_at DESC LIMIT 20")->fetchAll();
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Admin — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>.admin-wrap{min-height:100vh;}.admin-topbar{background:rgba(255,69,96,.08);border-bottom:1px solid rgba(255,69,96,.2);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;}.admin-title{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;}</style>
</head><body>
<div class="admin-wrap">
  <?php include '_sidebar.php'; ?>
  <div style="padding:28px 32px;">

    <!-- OVERVIEW STATS -->
    <div class="stats-grid" style="margin-bottom:24px;">
      <div class="stat-card"><div class="sc-val" style="color:var(--a1);"><?= $total_users ?></div><div class="sc-label">Total Users</div></div>
      <div class="stat-card"><div class="sc-val" style="color:var(--ok);"><?= $paid_users ?></div><div class="sc-label">Paid Users</div><div class="sc-sub"><?= $total_users>0?round($paid_users/$total_users*100).'% conversion':'-' ?></div></div>
      <div class="stat-card"><div class="sc-val" style="color:var(--a2);"><?= number_format($total_rows) ?></div><div class="sc-label">Rows This Month</div></div>
      <div class="stat-card"><div class="sc-val"><?= $new_today ?></div><div class="sc-label">New Today</div></div>
    </div>

    <!-- PLAN BREAKDOWN -->
    <?php
    $plan_counts=db()->query("SELECT plan,COUNT(*) as cnt FROM users GROUP BY plan")->fetchAll();
    $pc=[];foreach($plan_counts as $r){$pc[$r['plan']]=$r['cnt'];}
    ?>
    <div class="card" style="margin-bottom:24px;">
      <div class="card-title">📊 Plan Distribution</div>
      <div style="display:flex;gap:16px;flex-wrap:wrap;">
        <?php foreach(['free'=>'var(--muted)','pro'=>'var(--a1)','platinum'=>'var(--a2)','lifetime'=>'var(--purple)'] as $p=>$c): ?>
        <div style="background:var(--dim);border-radius:10px;padding:14px 20px;text-align:center;min-width:100px;">
          <div style="font-family:'Syne',sans-serif;font-size:26px;font-weight:800;color:<?= $c ?>;"><?= $pc[$p]??0 ?></div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-top:4px;"><?= ucfirst($p) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- RECENT USERS TABLE -->
    <div class="card">
      <div class="card-title" style="justify-content:space-between;"><span>👥 Recent Users</span><a href="/admin/users.php" class="btn btn-ghost btn-sm">View All</a></div>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Name</th><th>Email</th><th>Plan</th><th>Billing</th><th>Rows/mo</th><th>Joined</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach($users as $u): ?>
          <tr>
            <td><b style="color:#fff;"><?= htmlspecialchars($u['name']) ?></b></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><span class="plan-pill pp-<?= $u['plan'] ?>"><?= ucfirst($u['plan']) ?></span></td>
            <td style="color:var(--muted);"><?= $u['billing_cycle']??'—' ?></td>
            <td style="color:var(--a1);"><?= number_format($u['month_rows']) ?></td>
            <td style="color:var(--muted);"><?= ago($u['created_at']) ?></td>
            <td><span class="badge <?= $u['status']==='active'?'badge-ok':'badge-err' ?>"><?= $u['status'] ?></span></td>
            <td><a href="/admin/users.php?edit=<?= $u['id'] ?>" class="btn btn-ghost btn-sm">Edit</a></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
</body></html>
