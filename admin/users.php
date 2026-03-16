<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';
requireAdmin();

try {
    db()->exec("CREATE TABLE IF NOT EXISTS admin_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        note TEXT NOT NULL,
        created_by VARCHAR(100) DEFAULT 'admin',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db()->exec("CREATE TABLE IF NOT EXISTS usage_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        csv_rows INT DEFAULT 0,
        files_updated INT DEFAULT 0,
        job_type VARCHAR(50) DEFAULT 'bulk_replace',
        job_name VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        deleted_at TIMESTAMP NULL,
        INDEX idx_user_month (user_id, created_at),
        INDEX idx_deleted (deleted_at),
        INDEX idx_job_type (job_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $checkCol = db()->query("SHOW COLUMNS FROM usage_log LIKE 'job_type'")->fetch();
    if (!$checkCol) {
        db()->exec("ALTER TABLE usage_log ADD COLUMN job_type VARCHAR(50) DEFAULT 'bulk_replace' AFTER files_updated");
        db()->exec("ALTER TABLE usage_log ADD INDEX idx_job_type (job_type)");
    }
} catch (Exception $e) {
    error_log("Failed to create tables: " . $e->getMessage());
}

$msg=$_GET['msg']??'';$err='';
$auditLogger = new AuditLogger(db());
$auditLogger->setAdminId($_SESSION['admin_id'] ?? 1);

// Handle edit form submit
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['uid'])){
  $uid=(int)$_POST['uid'];
  $plan=$_POST['plan']??'free';
  $billing=$_POST['billing_cycle']??'none';
  $expires=$_POST['plan_expires_at']?:null;
  $status=$_POST['status']??'active';
  $rollover=(int)($_POST['rollover_balance']??0);

  $oldData = db()->prepare("SELECT plan, billing_cycle, plan_expires_at, status, rollover_balance FROM users WHERE id=?");
  $oldData->execute([$uid]);
  $oldUser = $oldData->fetch();

  db()->prepare("UPDATE users SET plan=?,billing_cycle=?,plan_expires_at=?,status=?,rollover_balance=? WHERE id=?")
    ->execute([$plan,$billing,$expires,$status,$rollover,$uid]);

  $auditLogger->logAdminAction('user_updated', 'user', $uid, [
    'old_plan' => $oldUser['plan'] ?? 'unknown',
    'new_plan' => $plan,
    'old_billing' => $oldUser['billing_cycle'] ?? 'unknown',
    'new_billing' => $billing,
    'old_status' => $oldUser['status'] ?? 'unknown',
    'new_status' => $status,
    'old_rollover' => $oldUser['rollover_balance'] ?? 0,
    'new_rollover' => $rollover
  ]);

  // Admin note
  if(!empty($_POST['note'])){
    db()->prepare("INSERT INTO admin_notes(user_id,note,created_by)VALUES(?,?,'admin')")->execute([$uid,$_POST['note']]);
  }
  $msg='User updated successfully.';
}

// Handle admin password reset
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['reset_pw_uid'])){
  $uid  = (int)$_POST['reset_pw_uid'];
  $pass = trim($_POST['new_password'] ?? '');
  if($uid && strlen($pass) >= 6){
    $hash = password_hash($pass, PASSWORD_BCRYPT);
    db()->prepare("UPDATE users SET password=?, reset_token=NULL, reset_token_expires=NULL WHERE id=?")
      ->execute([$hash, $uid]);
    db()->prepare("INSERT INTO admin_notes(user_id,note,created_by)VALUES(?,?,'admin')")
      ->execute([$uid, 'Password reset by admin']);

    $auditLogger->logAdminAction('password_reset', 'user', $uid, [
      'action' => 'admin_forced_password_reset'
    ]);

    header('Location: /admin/users.php?edit='.$uid.'&msg='.urlencode('Password reset successfully.')); exit;
  } else {
    $err = 'Password must be at least 6 characters.';
  }
}

// Handle addon grant/revoke
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['addon_action'])){
  $uid     = (int)($_POST['addon_uid'] ?? 0);
  $slug    = $_POST['addon_slug'] ?? '';
  $action  = $_POST['addon_action'] ?? '';
  if($uid && $slug && in_array($action,['grant','revoke'])){
    // Ensure addon row exists
    db()->prepare("INSERT IGNORE INTO addons(slug,name) VALUES(?,?)")
      ->execute([$slug, ADDON_DATA[$slug]['name'] ?? $slug]);
    $stmt = db()->prepare("SELECT id FROM addons WHERE slug=?");
    $stmt->execute([$slug]);
    $aid = $stmt->fetchColumn();
    if($action==='grant'){
      db()->prepare("INSERT IGNORE INTO user_addons(user_id,addon_id,is_active,purchased_at)VALUES(?,?,1,NOW())")
        ->execute([$uid,$aid]);
      db()->prepare("INSERT INTO admin_notes(user_id,note,created_by)VALUES(?,'Addon granted: ".$slug."','admin')")
        ->execute([$uid]);

      $auditLogger->logAdminAction('addon_granted', 'addon', $slug, [
        'user_id' => $uid,
        'action' => 'manual_grant'
      ]);

      $msg = "Addon '{$slug}' granted to user #{$uid}.";
    } else {
      db()->prepare("DELETE FROM user_addons WHERE user_id=? AND addon_id=?")
        ->execute([$uid,$aid]);
      db()->prepare("INSERT INTO admin_notes(user_id,note,created_by)VALUES(?,'Addon revoked: ".$slug."','admin')")
        ->execute([$uid]);

      $auditLogger->logAdminAction('addon_revoked', 'addon', $slug, [
        'user_id' => $uid,
        'action' => 'manual_revoke'
      ]);

      $msg = "Addon '{$slug}' revoked from user #{$uid}.";
    }
  }
  header('Location: /admin/users.php?edit='.$uid.'&msg='.urlencode($msg)); exit;
}

// Edit mode
$edit=null;$edit_notes=[];
if(isset($_GET['edit'])){
  $es=db()->prepare("SELECT * FROM users WHERE id=?");$es->execute([(int)$_GET['edit']]);$edit=$es->fetch();
  if($edit){$ns=db()->prepare("SELECT * FROM admin_notes WHERE user_id=? ORDER BY created_at DESC LIMIT 10");$ns->execute([$edit['id']]);$edit_notes=$ns->fetchAll();}
}

// All users with search
$search=trim($_GET['q']??'');
$where=$search?"WHERE u.email LIKE ? OR u.name LIKE ?":'';
$params=$search?["%$search%","%$search%"]:[];
$users=db()->prepare("SELECT u.*,
    (SELECT COALESCE(SUM(total_domains),0) FROM csv_gen_analytics WHERE user_id=u.id AND MONTH(created_at)=MONTH(NOW())) as mrows,
    (SELECT COALESCE(SUM(total_domains),0) FROM csv_gen_analytics WHERE user_id=u.id) as trows,
    (SELECT COALESCE(SUM(csv_rows),0) FROM usage_log WHERE user_id=u.id AND job_type='autopilot') as autopilot_usage
    FROM users u $where ORDER BY u.created_at DESC LIMIT 50");
$users->execute($params);$ulist=$users->fetchAll();
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Users — Admin — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>
.admin-topbar{background:rgba(255,69,96,.08);border-bottom:1px solid rgba(255,69,96,.2);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;}
.btn-ok{background:rgba(0,230,118,.12);color:var(--ok);border:1px solid rgba(0,230,118,.3);}
.btn-ok:hover{background:rgba(0,230,118,.2);}
.btn-danger{background:rgba(255,69,96,.1);color:var(--err);border:1px solid rgba(255,69,96,.3);}
.btn-danger:hover{background:rgba(255,69,96,.2);}
</style>
</head><body>
<div class="admin-wrap">
<?php include '_sidebar.php'; ?>
<div style="padding:28px 32px;">
  <?php if($msg): ?><div class="info-box">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="err-box">⚠ <?= htmlspecialchars($err) ?></div><?php endif; ?>

  <?php if($edit): ?>
  <!-- EDIT USER PANEL -->
  <div class="card" style="margin-bottom:24px;border-color:rgba(240,165,0,.2);">
    <div class="card-title">✏️ Edit User: <?= htmlspecialchars($edit['name']) ?> (<?= htmlspecialchars($edit['email']) ?>)</div>
    <form method="POST" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
      <input type="hidden" name="uid" value="<?= $edit['id'] ?>">
      <div class="form-field"><label class="form-label">Plan</label>
        <select name="plan"><?php foreach(['free','pro','platinum','lifetime'] as $p): ?><option value="<?= $p ?>" <?= $edit['plan']===$p?'selected':'' ?>><?= ucfirst($p) ?></option><?php endforeach; ?></select>
      </div>
      <div class="form-field"><label class="form-label">Billing Cycle</label>
        <select name="billing_cycle"><?php foreach(['none','monthly','annual','lifetime'] as $b): ?><option value="<?= $b ?>" <?= ($edit['billing_cycle']??'')===$b?'selected':'' ?>><?= ucfirst($b) ?></option><?php endforeach; ?></select>
      </div>
      <div class="form-field"><label class="form-label">Plan Expires At</label><input type="datetime-local" name="plan_expires_at" value="<?= $edit['plan_expires_at']?date('Y-m-d\TH:i',strtotime($edit['plan_expires_at'])):'' ?>"></div>
      <div class="form-field"><label class="form-label">Rollover Balance</label><input type="text" name="rollover_balance" value="<?= $edit['rollover_balance']??0 ?>"></div>
      <div class="form-field"><label class="form-label">Status</label>
        <select name="status"><option value="active" <?= $edit['status']==='active'?'selected':'' ?>>Active</option><option value="suspended" <?= $edit['status']==='suspended'?'selected':'' ?>>Suspended</option></select>
      </div>
      <div class="form-field"><label class="form-label">Admin Note (optional)</label><input type="text" name="note" placeholder="Internal note..."></div>
      <div style="grid-column:1/-1;display:flex;gap:10px;">
        <button type="submit" class="btn btn-amber">Save Changes</button>
        <a href="/admin/users.php" class="btn btn-ghost">Cancel</a>
      </div>
    </form>
    <!-- ADDON MANAGEMENT -->
    <div class="divider"></div>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">
      <span style="font-family:'Syne',sans-serif;font-size:15px;font-weight:800;color:#fff;">🤖 Addon Access Control</span>
      <span style="font-family:'JetBrains Mono',monospace;font-size:9px;background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.3);color:#f0a500;padding:2px 8px;border-radius:5px;letter-spacing:.5px;">ADMIN ONLY</span>
      <span style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);">Grant or revoke addon access for this user</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-bottom:6px;">
    <?php
    foreach(ADDON_DATA as $aSlug => $aData):
      // Check if user has this addon
      $hasIt = false;
      $stmtCheck = db()->prepare("SELECT id FROM addons WHERE slug=?");
      $stmtCheck->execute([$aSlug]);
      $addonId = $stmtCheck->fetchColumn();
      if($addonId){
        $chk = db()->prepare("SELECT COUNT(*) FROM user_addons WHERE user_id=? AND addon_id=? AND is_active=1");
        $chk->execute([$edit['id'],$addonId]);
        $hasIt = (bool)$chk->fetchColumn();
      }
      // Check if plan already grants it (autopilot is always sold separately)
      $planGrants = !in_array($aSlug, ADDON_ALWAYS_SEPARATE) && (bool)(PLAN_DATA[$edit['plan']??'free']['has_addons']??false);
      // lifetime_only check
      $ltOnly = !empty($aData['lifetime_only']);
      $canGrant = !$ltOnly || ($edit['plan']==='lifetime');
    ?>
    <div style="background:var(--dim);border:1px solid <?= $hasIt||$planGrants ? 'rgba(0,230,118,.3)':'var(--border)' ?>;border-radius:10px;padding:12px 14px;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
        <span style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:#fff;">
          <?= $aData['icon'] ?> <?= htmlspecialchars($aData['name']) ?>
        </span>
        <?php if($planGrants): ?>
          <span style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--ok);border:1px solid rgba(0,230,118,.3);padding:2px 7px;border-radius:5px;">PLAN</span>
        <?php elseif($hasIt): ?>
          <span style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--ok);border:1px solid rgba(0,230,118,.3);padding:2px 7px;border-radius:5px;">ACTIVE</span>
        <?php else: ?>
          <span style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);border:1px solid var(--border);padding:2px 7px;border-radius:5px;">NONE</span>
        <?php endif; ?>
      </div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-bottom:10px;line-height:1.5;">
        $<?= $aData['price'] ?>
        <?php if($ltOnly): ?>· <span style="color:#c084fc;">Lifetime-only</span><?php endif; ?>
      </div>
      <?php if(!$planGrants): ?>
      <div style="display:flex;gap:6px;">
        <?php if($canGrant && !$hasIt): ?>
          <form method="POST">
            <input type="hidden" name="addon_uid" value="<?= $edit['id'] ?>">
            <input type="hidden" name="addon_slug" value="<?= $aSlug ?>">
            <input type="hidden" name="addon_action" value="grant">
            <button type="submit" class="btn btn-ok btn-sm" style="font-size:10px;padding:4px 10px;">Grant</button>
          </form>
        <?php elseif($hasIt): ?>
          <form method="POST" onsubmit="return confirm('Revoke <?= htmlspecialchars($aData['name']) ?> from this user?')">
            <input type="hidden" name="addon_uid" value="<?= $edit['id'] ?>">
            <input type="hidden" name="addon_slug" value="<?= $aSlug ?>">
            <input type="hidden" name="addon_action" value="revoke">
            <button type="submit" class="btn btn-danger btn-sm" style="font-size:10px;padding:4px 10px;">Revoke</button>
          </form>
        <?php else: ?>
          <span style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--err);">Lifetime plan required</span>
        <?php endif; ?>
      </div>
      <?php else: ?>
        <span style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);">Included in plan</span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- RESET PASSWORD -->
    <div class="divider"></div>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
      <span style="font-family:'Syne',sans-serif;font-size:15px;font-weight:800;color:#fff;">🔑 Reset User Password</span>
      <span style="font-family:'JetBrains Mono',monospace;font-size:9px;background:rgba(255,69,96,.1);border:1px solid rgba(255,69,96,.3);color:var(--err);padding:2px 8px;border-radius:5px;letter-spacing:.5px;">ADMIN ONLY</span>
    </div>
    <form method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <input type="hidden" name="reset_pw_uid" value="<?= $edit['id'] ?>">
      <div class="form-field" style="flex:1;min-width:200px;margin:0;">
        <label class="form-label">New Password (min 6 chars)</label>
        <input type="text" name="new_password" placeholder="Enter new password..." style="font-family:'JetBrains Mono',monospace;">
      </div>
      <button type="submit" class="btn btn-danger btn-sm"
              onclick="return confirm('Reset password for <?= htmlspecialchars($edit['email']) ?>?')"
              style="padding:10px 18px;margin-bottom:1px;">
        🔑 Set Password
      </button>
    </form>
    <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:6px;">
      ⚠ This immediately changes the user's password. Make sure to inform them.
    </div>

    <?php if($edit_notes): ?>
    <div class="divider"></div>
    <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-bottom:10px;">ADMIN NOTES</div>
    <?php foreach($edit_notes as $n): ?>
    <div style="background:var(--dim);border-radius:8px;padding:10px 14px;margin-bottom:8px;font-family:'JetBrains Mono',monospace;font-size:11px;">
      <span style="color:var(--muted);"><?= ago($n['created_at']) ?> — </span><?= htmlspecialchars($n['note']) ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- SEARCH + TABLE -->
  <div class="card">
    <div class="card-title" style="justify-content:space-between;">
      <span>👥 All Users (<?= count($ulist) ?>)</span>
      <form method="GET" style="display:flex;gap:8px;">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search email or name..." style="width:220px;">
        <button type="submit" class="btn btn-ghost btn-sm">Search</button>
        <?php if($search): ?><a href="/admin/users.php" class="btn btn-ghost btn-sm">Clear</a><?php endif; ?>
      </form>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Plan</th><th>Cycle</th><th>Expires</th><th>Rows/mo</th><th>Total Rows</th><th>Autopilot</th><th>Rollover</th><th>Joined</th><th>Status</th><th>Act</th></tr></thead>
        <tbody>
        <?php foreach($ulist as $u): ?>
        <tr>
          <td style="color:var(--muted);"><?= $u['id'] ?></td>
          <td><b style="color:#fff;"><?= htmlspecialchars($u['name']) ?></b></td>
          <td style="color:var(--muted);"><?= htmlspecialchars($u['email']) ?></td>
          <td><span class="plan-pill pp-<?= $u['plan'] ?>"><?= ucfirst($u['plan']) ?></span></td>
          <td style="color:var(--muted);"><?= $u['billing_cycle']??'—' ?></td>
          <td style="color:var(--warn);font-size:11px;"><?= $u['plan_expires_at']?date('Y-m-d',strtotime($u['plan_expires_at'])):'—' ?></td>
          <td style="color:var(--a1);"><?= number_format($u['mrows']) ?></td>
          <td><?= number_format($u['trows']) ?></td>
          <td style="color:#c084fc;font-weight:700;"><?= number_format($u['autopilot_usage']??0) ?></td>
          <td style="color:var(--ok);"><?= number_format($u['rollover_balance']??0) ?></td>
          <td style="color:var(--muted);font-size:10px;"><?= ago($u['created_at']) ?></td>
          <td><span class="badge <?= $u['status']==='active'?'badge-ok':'badge-err' ?>"><?= $u['status'] ?></span></td>
          <td><a href="?edit=<?= $u['id'] ?><?= $search?"&q=".urlencode($search):'' ?>" class="btn btn-ghost btn-sm">Edit</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body></html>
