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

$msg = $_GET['msg'] ?? '';
$err = '';
$auditLogger = new AuditLogger(db());
$auditLogger->setAdminId($_SESSION['admin_id'] ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['uid'])) {
    if (!csrf_verify()) {
        $err = 'Security validation failed.';
    } else {
        $uid = (int)$_POST['uid'];
        $oldData = db()->prepare("SELECT plan, billing_cycle, plan_expires_at, status, rollover_balance FROM users WHERE id=?");
        $oldData->execute([$uid]);
        $oldUser = $oldData->fetch();
        if (!$oldUser) {
            $err = 'User not found.';
        } else {
            $plan    = $_POST['plan'] ?? 'free';
            $billing = $_POST['billing_cycle'] ?? 'none';
            $expires = $_POST['plan_expires_at'] ?: null;
            $status  = $_POST['status'] ?? 'active';
            $rollover = (int)($_POST['rollover_balance'] ?? 0);

            db()->prepare("UPDATE users SET plan=?,billing_cycle=?,plan_expires_at=?,status=?,rollover_balance=? WHERE id=?")
              ->execute([$plan, $billing, $expires, $status, $rollover, $uid]);

            $auditLogger->logAdminAction('user_updated', 'user', $uid, [
                'old_plan' => $oldUser['plan'] ?? 'unknown',
                'new_plan' => $plan,
                'old_status' => $oldUser['status'] ?? 'unknown',
                'new_status' => $status,
            ]);

            if (!empty($_POST['note'])) {
                $note = mb_substr(trim($_POST['note']), 0, 500);
                db()->prepare("INSERT INTO admin_notes(user_id,note,created_by)VALUES(?,?,'admin')")->execute([$uid, $note]);
            }
            $msg = 'User updated successfully.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_pw_uid'])) {
    if (!csrf_verify()) {
        $err = 'Security validation failed.';
    } else {
        $uid  = (int)$_POST['reset_pw_uid'];
        $pass = trim($_POST['new_password'] ?? '');
        $existsCheck = db()->prepare("SELECT id FROM users WHERE id=?");
        $existsCheck->execute([$uid]);
        if (!$existsCheck->fetch()) {
            $err = 'User not found.';
        } elseif ($uid && strlen($pass) >= 12) {
            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            db()->prepare("UPDATE users SET password=?, reset_token=NULL, reset_token_expires=NULL WHERE id=?")->execute([$hash, $uid]);
            db()->prepare("INSERT INTO admin_notes(user_id,note,created_by)VALUES(?,?,'admin')")->execute([$uid, 'Password reset by admin']);
            $auditLogger->logAdminAction('password_reset', 'user', $uid, ['action' => 'admin_forced_password_reset']);
            header('Location: /admin/users.php?edit=' . $uid . '&msg=' . urlencode('Password reset successfully.')); exit;
        } else {
            $err = 'Password must be at least 12 characters.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['addon_action'])) {
    if (!csrf_verify()) {
        $err = 'Security validation failed.';
    } else {
        $uid    = (int)($_POST['addon_uid'] ?? 0);
        $slug   = $_POST['addon_slug'] ?? '';
        $action = $_POST['addon_action'] ?? '';
        $addonUserCheck = db()->prepare("SELECT id FROM users WHERE id=?");
        $addonUserCheck->execute([$uid]);
        if (!$uid || !$addonUserCheck->fetch()) {
            $err = 'User not found.';
        } elseif ($slug && in_array($action, ['grant', 'revoke'])) {
            db()->prepare("INSERT IGNORE INTO addons(slug,name) VALUES(?,?)")->execute([$slug, ADDON_DATA[$slug]['name'] ?? $slug]);
            $stmt = db()->prepare("SELECT id FROM addons WHERE slug=?");
            $stmt->execute([$slug]);
            $aid = $stmt->fetchColumn();
            if ($action === 'grant') {
                db()->prepare("INSERT IGNORE INTO user_addons(user_id,addon_id,is_active,purchased_at)VALUES(?,?,1,NOW())")->execute([$uid, $aid]);
                db()->prepare("INSERT INTO admin_notes(user_id,note,created_by)VALUES(?,?,'admin')")->execute([$uid, 'Addon granted: ' . $slug]);
                $auditLogger->logAdminAction('addon_granted', 'addon', $slug, ['user_id' => $uid]);
                $msg = "Addon '$slug' granted.";
            } else {
                db()->prepare("DELETE FROM user_addons WHERE user_id=? AND addon_id=?")->execute([$uid, $aid]);
                db()->prepare("INSERT INTO admin_notes(user_id,note,created_by)VALUES(?,?,'admin')")->execute([$uid, 'Addon revoked: ' . $slug]);
                $auditLogger->logAdminAction('addon_revoked', 'addon', $slug, ['user_id' => $uid]);
                $msg = "Addon '$slug' revoked.";
            }
            header('Location: /admin/users.php?edit=' . $uid . '&msg=' . urlencode($msg)); exit;
        }
    }
}

$edit = null;
$edit_notes = [];
if (isset($_GET['edit'])) {
    $es = db()->prepare("SELECT * FROM users WHERE id=?");
    $es->execute([(int)$_GET['edit']]);
    $edit = $es->fetch();
    if ($edit) {
        $ns = db()->prepare("SELECT * FROM admin_notes WHERE user_id=? ORDER BY created_at DESC LIMIT 15");
        $ns->execute([$edit['id']]);
        $edit_notes = $ns->fetchAll();
    }
}

$search = trim($_GET['q'] ?? '');
$planFilter = $_GET['plan'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$conditions = [];
$params = [];

if ($search) {
    $conditions[] = "(u.email LIKE ? OR u.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($planFilter && in_array($planFilter, ['free','pro','platinum','lifetime'])) {
    $conditions[] = "u.plan = ?";
    $params[] = $planFilter;
}
if ($statusFilter && in_array($statusFilter, ['active','suspended'])) {
    $conditions[] = "u.status = ?";
    $params[] = $statusFilter;
}

$where = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";

$users = db()->prepare("SELECT u.*,
    COALESCE(ul_m.mrows,0) as mrows,
    COALESCE(ul_all.trows,0) as trows,
    COALESCE(ul_ap.autopilot_usage,0) as autopilot_usage
    FROM users u
    LEFT JOIN (
        SELECT user_id, SUM(csv_rows) as mrows
        FROM usage_log
        WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) AND deleted_at IS NULL
        GROUP BY user_id
    ) ul_m ON ul_m.user_id = u.id
    LEFT JOIN (
        SELECT user_id, SUM(csv_rows) as trows
        FROM usage_log
        WHERE deleted_at IS NULL
        GROUP BY user_id
    ) ul_all ON ul_all.user_id = u.id
    LEFT JOIN (
        SELECT user_id, SUM(csv_rows) as autopilot_usage
        FROM usage_log
        WHERE job_type='autopilot' AND deleted_at IS NULL
        GROUP BY user_id
    ) ul_ap ON ul_ap.user_id = u.id
    $where ORDER BY u.created_at DESC LIMIT 100");
$users->execute($params);
$ulist = $users->fetchAll();

try {
    $statsStmt = db()->query("SELECT
        COUNT(*) as total,
        SUM(plan='free') as free_count,
        SUM(plan='pro') as pro_count,
        SUM(plan='platinum') as platinum_count,
        SUM(plan='lifetime') as lifetime_count,
        SUM(status='active') as active_count,
        SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as new_this_week
        FROM users WHERE status != 'deleted' OR status IS NULL");
    $stats = $statsStmt->fetch();
} catch(Exception $e) {
    $stats = ['total'=>0,'free_count'=>0,'pro_count'=>0,'platinum_count'=>0,'lifetime_count'=>0,'active_count'=>0,'new_this_week'=>0];
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Users — Admin — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>
.admin-stat{background:var(--dim);border-radius:10px;padding:14px 18px;border:1px solid var(--border);border-top:2px solid var(--_accent,var(--border));text-align:center;}
.admin-stat .sv{font-family:'Syne',sans-serif;font-size:24px;font-weight:900;color:#fff;line-height:1;}
.admin-stat .sl{font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-top:6px;}

.edit-tabs{display:flex;border-bottom:1px solid var(--border);margin-bottom:20px;gap:0;}
.edit-tab{padding:10px 18px;font-family:'JetBrains Mono',monospace;font-size:11px;font-weight:700;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;transition:color .15s,border-color .15s;background:none;border-top:none;border-left:none;border-right:none;}
.edit-tab.active{color:var(--a1);border-bottom-color:var(--a1);}
.edit-panel{display:none;}
.edit-panel.active{display:block;}

.user-row{transition:background .12s;}
.user-row:hover{background:rgba(255,255,255,.02);}

.filter-pill{padding:5px 12px;border-radius:20px;font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;border:1px solid var(--border);background:var(--dim);color:var(--muted);cursor:pointer;text-decoration:none;transition:all .15s;}
.filter-pill.active,.filter-pill:hover{background:rgba(240,165,0,.1);border-color:rgba(240,165,0,.3);color:var(--a1);}

.btn-ok{background:rgba(0,230,118,.12);color:var(--ok);border:1px solid rgba(0,230,118,.3);}
.btn-ok:hover{background:rgba(0,230,118,.2);}
.btn-revoke{background:rgba(255,69,96,.1);color:var(--err);border:1px solid rgba(255,69,96,.3);}
.btn-revoke:hover{background:rgba(255,69,96,.2);}
</style>
</head>
<body>
<div class="admin-wrap">
<?php include '_sidebar.php'; ?>
<div style="padding:24px 32px;max-width:1400px;">

<?php if($msg): ?><div class="suc-box" style="margin-bottom:16px;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="err-box" style="margin-bottom:16px;"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- STATS STRIP -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:12px;margin-bottom:24px;">
  <div class="admin-stat" style="--_accent:var(--a2);">
    <div class="sv"><?= number_format($stats['total']) ?></div>
    <div class="sl">Total Users</div>
  </div>
  <div class="admin-stat" style="--_accent:var(--muted);">
    <div class="sv"><?= number_format($stats['free_count']) ?></div>
    <div class="sl">Free</div>
  </div>
  <div class="admin-stat" style="--_accent:var(--a2);">
    <div class="sv"><?= number_format($stats['pro_count']) ?></div>
    <div class="sl">Pro</div>
  </div>
  <div class="admin-stat" style="--_accent:var(--a1);">
    <div class="sv"><?= number_format($stats['platinum_count']) ?></div>
    <div class="sl">Platinum</div>
  </div>
  <div class="admin-stat" style="--_accent:#c084fc;">
    <div class="sv"><?= number_format($stats['lifetime_count']) ?></div>
    <div class="sl">Lifetime</div>
  </div>
  <div class="admin-stat" style="--_accent:var(--ok);">
    <div class="sv"><?= number_format($stats['active_count']) ?></div>
    <div class="sl">Active</div>
  </div>
  <div class="admin-stat" style="--_accent:#60a5fa;">
    <div class="sv"><?= number_format($stats['new_this_week']) ?></div>
    <div class="sl">New (7d)</div>
  </div>
</div>

<?php if($edit): ?>
<!-- EDIT USER PANEL -->
<div class="card" style="margin-bottom:24px;border-color:rgba(240,165,0,.25);">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;flex-wrap:wrap;gap:10px;">
    <div>
      <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:#fff;">
        <?= htmlspecialchars($edit['name']) ?>
      </div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);">
        #<?= $edit['id'] ?> &bull; <?= htmlspecialchars($edit['email']) ?> &bull; Joined <?= ago($edit['created_at']) ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <span class="plan-pill pp-<?= $edit['plan'] ?>"><?= ucfirst($edit['plan']) ?></span>
      <span class="badge <?= $edit['status']==='active'?'badge-ok':'badge-err' ?>"><?= $edit['status'] ?></span>
      <a href="/admin/users.php<?= $search ? '?q='.urlencode($search) : '' ?>" class="btn" style="background:var(--dim);border:1px solid var(--border);font-size:11px;">Close</a>
    </div>
  </div>

  <!-- USAGE SNAPSHOT -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin:16px 0;padding:14px;background:var(--dim);border-radius:10px;">
    <?php
    try {
        $uSnap = db()->prepare("SELECT COALESCE(SUM(CASE WHEN MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) THEN csv_rows ELSE 0 END),0) as mo_rows, COALESCE(SUM(csv_rows),0) as total_rows, COUNT(*) as total_jobs FROM usage_log WHERE user_id=? AND deleted_at IS NULL");
        $uSnap->execute([$edit['id']]);
        $snap = $uSnap->fetch();
    } catch(Exception $e) { $snap = ['mo_rows'=>0,'total_rows'=>0,'total_jobs'=>0]; }
    ?>
    <div style="text-align:center;">
      <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:900;color:var(--a1);"><?= number_format($snap['mo_rows']) ?></div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:4px;">This Month</div>
    </div>
    <div style="text-align:center;">
      <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:900;color:var(--a2);"><?= number_format($snap['total_rows']) ?></div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:4px;">Total Rows</div>
    </div>
    <div style="text-align:center;">
      <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:900;color:#60a5fa;"><?= number_format($snap['total_jobs']) ?></div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:4px;">Total Jobs</div>
    </div>
    <div style="text-align:center;">
      <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:900;color:#c084fc;"><?= number_format($edit['rollover_balance'] ?? 0) ?></div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:4px;">Rollover</div>
    </div>
  </div>

  <!-- TABS -->
  <div class="edit-tabs">
    <button class="edit-tab active" onclick="switchTab(this,'tab-plan')">Plan & Status</button>
    <button class="edit-tab" onclick="switchTab(this,'tab-addons')">Add-ons</button>
    <button class="edit-tab" onclick="switchTab(this,'tab-password')">Reset Password</button>
    <button class="edit-tab" onclick="switchTab(this,'tab-notes')">Notes (<?= count($edit_notes) ?>)</button>
  </div>

  <!-- TAB: PLAN & STATUS -->
  <div id="tab-plan" class="edit-panel active">
    <form method="POST" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
      <?= csrf_field() ?>
      <input type="hidden" name="uid" value="<?= $edit['id'] ?>">
      <div class="form-field">
        <label class="form-label">Plan</label>
        <select name="plan">
          <?php foreach(['free','pro','platinum','lifetime'] as $p): ?>
          <option value="<?= $p ?>" <?= $edit['plan']===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-field">
        <label class="form-label">Billing Cycle</label>
        <select name="billing_cycle">
          <?php foreach(['none','monthly','annual','lifetime'] as $b): ?>
          <option value="<?= $b ?>" <?= ($edit['billing_cycle']??'')===$b?'selected':'' ?>><?= ucfirst($b) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-field">
        <label class="form-label">Status</label>
        <select name="status">
          <option value="active" <?= $edit['status']==='active'?'selected':'' ?>>Active</option>
          <option value="suspended" <?= $edit['status']==='suspended'?'selected':'' ?>>Suspended</option>
        </select>
      </div>
      <div class="form-field">
        <label class="form-label">Plan Expires At</label>
        <input type="datetime-local" name="plan_expires_at" value="<?= $edit['plan_expires_at']?date('Y-m-d\TH:i',strtotime($edit['plan_expires_at'])):'' ?>">
      </div>
      <div class="form-field">
        <label class="form-label">Rollover Balance</label>
        <input type="number" name="rollover_balance" value="<?= $edit['rollover_balance']??0 ?>" min="0">
      </div>
      <div class="form-field">
        <label class="form-label">Admin Note (optional)</label>
        <input type="text" name="note" placeholder="Internal note..." maxlength="500">
      </div>
      <div style="grid-column:1/-1;display:flex;gap:10px;">
        <button type="submit" class="btn btn-amber">Save Changes</button>
      </div>
    </form>
  </div>

  <!-- TAB: ADDONS -->
  <div id="tab-addons" class="edit-panel">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;">
    <?php foreach(ADDON_DATA as $aSlug => $aData):
      $hasIt = false;
      $stmtCheck = db()->prepare("SELECT id FROM addons WHERE slug=?");
      $stmtCheck->execute([$aSlug]);
      $addonId = $stmtCheck->fetchColumn();
      if ($addonId) {
          $chk = db()->prepare("SELECT COUNT(*) FROM user_addons WHERE user_id=? AND addon_id=? AND is_active=1");
          $chk->execute([$edit['id'], $addonId]);
          $hasIt = (bool)$chk->fetchColumn();
      }
      $planGrants = !in_array($aSlug, ADDON_ALWAYS_SEPARATE) && (bool)(PLAN_DATA[$edit['plan']??'free']['has_addons']??false);
      $ltOnly     = !empty($aData['lifetime_only']);
      $canGrant   = !$ltOnly || ($edit['plan']==='lifetime');
    ?>
    <div style="background:var(--bg);border-radius:10px;padding:14px 16px;border:1px solid <?= $hasIt||$planGrants ? 'rgba(0,230,118,.3)':'var(--border)' ?>;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
        <span style="font-family:'Syne',sans-serif;font-size:12px;font-weight:700;color:#fff;"><?= $aData['icon'] ?> <?= htmlspecialchars($aData['name']) ?></span>
        <?php if($planGrants): ?>
          <span style="font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--ok);border:1px solid rgba(0,230,118,.3);padding:2px 6px;border-radius:4px;">PLAN</span>
        <?php elseif($hasIt): ?>
          <span style="font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--ok);border:1px solid rgba(0,230,118,.3);padding:2px 6px;border-radius:4px;">ACTIVE</span>
        <?php else: ?>
          <span style="font-family:'JetBrains Mono',monospace;font-size:8px;color:var(--muted);border:1px solid var(--border);padding:2px 6px;border-radius:4px;">NONE</span>
        <?php endif; ?>
      </div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-bottom:10px;">
        $<?= $aData['price'] ?><?= $ltOnly ? ' · <span style="color:#c084fc;">Lifetime-only</span>' : '' ?>
      </div>
      <?php if(!$planGrants): ?>
      <?php if($canGrant && !$hasIt): ?>
        <form method="POST" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="addon_uid" value="<?= $edit['id'] ?>">
          <input type="hidden" name="addon_slug" value="<?= $aSlug ?>">
          <input type="hidden" name="addon_action" value="grant">
          <button type="submit" class="btn btn-ok" style="font-size:10px;padding:4px 12px;">Grant</button>
        </form>
      <?php elseif($hasIt): ?>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Revoke <?= htmlspecialchars($aData['name']) ?>?')">
          <?= csrf_field() ?>
          <input type="hidden" name="addon_uid" value="<?= $edit['id'] ?>">
          <input type="hidden" name="addon_slug" value="<?= $aSlug ?>">
          <input type="hidden" name="addon_action" value="revoke">
          <button type="submit" class="btn btn-revoke" style="font-size:10px;padding:4px 12px;">Revoke</button>
        </form>
      <?php else: ?>
        <span style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--err);">Lifetime plan required</span>
      <?php endif; ?>
      <?php else: ?>
        <span style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);">Included in plan</span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
  </div>

  <!-- TAB: RESET PASSWORD -->
  <div id="tab-password" class="edit-panel">
    <div style="max-width:480px;">
      <div class="warn-box" style="margin-bottom:16px;">This immediately changes the user's password. Inform them after resetting.</div>
      <form method="POST" style="display:flex;gap:10px;align-items:flex-end;">
        <?= csrf_field() ?>
        <input type="hidden" name="reset_pw_uid" value="<?= $edit['id'] ?>">
        <div class="form-field" style="flex:1;margin:0;">
          <label class="form-label">New Password (min 12 chars)</label>
          <input type="text" name="new_password" placeholder="Enter new password..." style="font-family:'JetBrains Mono',monospace;">
        </div>
        <button type="submit" class="btn btn-revoke btn-sm"
                onclick="return confirm('Reset password for <?= htmlspecialchars(addslashes($edit['email'])) ?>?')"
                style="padding:10px 18px;white-space:nowrap;">
          Set Password
        </button>
      </form>
    </div>
  </div>

  <!-- TAB: NOTES -->
  <div id="tab-notes" class="edit-panel">
    <?php if($edit_notes): ?>
    <div style="display:grid;gap:8px;">
      <?php foreach($edit_notes as $n): ?>
      <div style="background:var(--bg);border-radius:8px;padding:10px 14px;border:1px solid var(--border);display:flex;justify-content:space-between;gap:16px;">
        <span style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text);"><?= htmlspecialchars($n['note']) ?></span>
        <span style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);white-space:nowrap;"><?= ago($n['created_at']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);padding:24px;text-align:center;background:var(--dim);border-radius:10px;">No admin notes for this user.</div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- SEARCH + FILTER -->
<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:16px;">
    <div style="font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:#fff;">
      All Users <span style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);font-weight:400;">(<?= count($ulist) ?>)</span>
    </div>
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <?php if(isset($_GET['edit'])): ?><input type="hidden" name="edit" value="<?= (int)$_GET['edit'] ?>"><?php endif; ?>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search email or name..." style="width:200px;">
      <select name="plan" style="padding:8px 12px;background:var(--dim);border:1px solid var(--border);border-radius:8px;color:var(--text);">
        <option value="">All Plans</option>
        <?php foreach(['free','pro','platinum','lifetime'] as $p): ?>
        <option value="<?= $p ?>" <?= $planFilter===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="status" style="padding:8px 12px;background:var(--dim);border:1px solid var(--border);border-radius:8px;color:var(--text);">
        <option value="">All Status</option>
        <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
        <option value="suspended" <?= $statusFilter==='suspended'?'selected':'' ?>>Suspended</option>
      </select>
      <button type="submit" class="btn btn-amber btn-sm">Filter</button>
      <?php if($search||$planFilter||$statusFilter): ?><a href="/admin/users.php" class="btn btn-sm" style="background:var(--dim);border:1px solid var(--border);">Clear</a><?php endif; ?>
    </form>
  </div>

  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>#</th>
          <th>User</th>
          <th>Plan</th>
          <th>Cycle</th>
          <th>Expires</th>
          <th>Rows (mo)</th>
          <th>Total Rows</th>
          <th>Autopilot</th>
          <th>Rollover</th>
          <th>Joined</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($ulist as $u): ?>
      <tr class="user-row <?= (isset($_GET['edit']) && (int)$_GET['edit'] === $u['id']) ? 'selected-row' : '' ?>">
        <td style="color:var(--muted);font-size:11px;"><?= $u['id'] ?></td>
        <td>
          <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:#fff;"><?= htmlspecialchars($u['name']) ?></div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);"><?= htmlspecialchars($u['email']) ?></div>
        </td>
        <td><span class="plan-pill pp-<?= $u['plan'] ?>"><?= ucfirst($u['plan']) ?></span></td>
        <td style="color:var(--muted);font-size:11px;"><?= $u['billing_cycle'] ?? '—' ?></td>
        <td style="color:var(--warn);font-size:11px;"><?= $u['plan_expires_at'] ? date('Y-m-d', strtotime($u['plan_expires_at'])) : '—' ?></td>
        <td style="color:var(--a1);font-weight:700;"><?= number_format($u['mrows']) ?></td>
        <td><?= number_format($u['trows']) ?></td>
        <td style="color:#c084fc;font-weight:700;"><?= number_format($u['autopilot_usage'] ?? 0) ?></td>
        <td style="color:var(--ok);"><?= number_format($u['rollover_balance'] ?? 0) ?></td>
        <td style="color:var(--muted);font-size:10px;"><?= ago($u['created_at']) ?></td>
        <td><span class="badge <?= $u['status']==='active'?'badge-ok':'badge-err' ?>"><?= $u['status'] ?></span></td>
        <td>
          <a href="?edit=<?= $u['id'] ?><?= $search ? "&q=".urlencode($search) : '' ?><?= $planFilter ? "&plan=".urlencode($planFilter) : '' ?><?= $statusFilter ? "&status=".urlencode($statusFilter) : '' ?>"
             class="btn btn-ghost btn-sm">Edit</a>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($ulist)): ?>
      <tr><td colspan="12" style="text-align:center;padding:32px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);">No users found matching your search.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<script>
function switchTab(btn, panelId) {
    document.querySelectorAll('.edit-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.edit-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    const panel = document.getElementById(panelId);
    if (panel) panel.classList.add('active');
}

const editRow = document.querySelector('.selected-row');
if (editRow) {
    editRow.style.background = 'rgba(240,165,0,.04)';
    editRow.style.borderLeft = '3px solid var(--a1)';
}
</script>
</body>
</html>
