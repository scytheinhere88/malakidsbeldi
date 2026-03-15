<?php
require_once dirname(__DIR__).'/config.php';
requireAdmin();

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Security validation failed. Please try again.';
    } elseif (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'create') {
            $code = strtoupper(trim($_POST['code'] ?? ''));
            $discount = intval($_POST['discount_percent'] ?? 0);
            $validFrom = $_POST['valid_from'] ?? date('Y-m-d H:i:s');
            $validUntil = $_POST['valid_until'] ?? date('Y-m-d H:i:s', strtotime('+30 days'));
            $maxUses = !empty($_POST['max_uses']) ? intval($_POST['max_uses']) : null;

            if ($code && $discount > 0 && $discount <= 100) {
                try {
                    $stmt = $db->prepare("INSERT INTO promo_codes (code, discount_percent, valid_from, valid_until, max_uses) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$code, $discount, $validFrom, $validUntil, $maxUses]);
                    $success = "Promo code created successfully!";
                } catch (Exception $e) {
                    error_log("Promo code create error: " . $e->getMessage());
                    $error = "Failed to create promo code. A code with that name may already exist.";
                }
            } else {
                $error = "Invalid promo code data.";
            }
        } elseif ($action === 'fix_time') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE promo_codes SET valid_from = DATE_SUB(NOW(), INTERVAL 1 HOUR), updated_at = NOW() WHERE id = ?");
                $stmt->execute([$id]);
                $success = "Promo code time fixed! It should now be active.";
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE promo_codes SET is_active = NOT is_active, updated_at = now() WHERE id = ?");
                $stmt->execute([$id]);
                $success = "Promo code status updated!";
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare("DELETE FROM promo_codes WHERE id = ?");
                $stmt->execute([$id]);
                $success = "Promo code deleted!";
            }
        }
    }
}

$promos = $db->query("SELECT * FROM promo_codes ORDER BY created_at DESC")->fetchAll();
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Promo Codes — Admin — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>
.admin-wrap{min-height:100vh;}
.admin-topbar{background:rgba(255,69,96,.08);border-bottom:1px solid rgba(255,69,96,.2);padding:16px 32px;display:flex;align-items:center;justify-content:space-between;}
.admin-title{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:#fff;display:flex;align-items:center;gap:10px;}
.promo-form{background:var(--dim);border-radius:12px;padding:20px;margin-bottom:24px;}
.form-grid{display:grid;gap:16px;}
.form-row{display:grid;grid-template-columns:2fr 1fr;gap:12px;}
.form-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}
.form-label{display:block;font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:8px;}
.promo-badge{display:inline-block;padding:4px 12px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;}
.promo-badge.active{background:rgba(16,185,129,0.15);color:#10b981;border:1px solid rgba(16,185,129,0.3);}
.promo-badge.expired{background:rgba(239,68,68,0.15);color:#ef4444;border:1px solid rgba(239,68,68,0.3);}
.promo-badge.inactive{background:var(--dim);color:var(--muted);border:1px solid var(--border);}
</style>
</head><body>
<div class="admin-wrap">
  <?php include '_sidebar.php'; ?>
  <div style="padding:28px 32px;">

    <?php if (isset($error)): ?>
    <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:8px;padding:14px;margin-bottom:20px;color:#ef4444;">
      ⚠ <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if (isset($success)): ?>
    <div style="background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);border-radius:8px;padding:14px;margin-bottom:20px;color:#10b981;">
      ✅ <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <!-- CREATE PROMO -->
    <div class="card" style="margin-bottom:24px;">
      <div class="card-title">Create New Promo Code</div>
      <form method="POST" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-row">
          <div>
            <label class="form-label">Promo Code</label>
            <input type="text" name="code" placeholder="BULKREPLACE2026" required style="width:100%;text-transform:uppercase;">
          </div>
          <div>
            <label class="form-label">Discount %</label>
            <input type="number" name="discount_percent" placeholder="20" min="1" max="100" required style="width:100%;">
          </div>
        </div>
        <div class="form-row-3">
          <div>
            <label class="form-label">Valid From</label>
            <input type="datetime-local" name="valid_from" value="<?= date('Y-m-d\TH:i') ?>" required style="width:100%;">
          </div>
          <div>
            <label class="form-label">Valid Until</label>
            <input type="datetime-local" name="valid_until" value="<?= date('Y-m-d\TH:i', strtotime('+30 days')) ?>" required style="width:100%;">
          </div>
          <div>
            <label class="form-label">Max Uses (optional)</label>
            <input type="number" name="max_uses" placeholder="Unlimited" min="1" style="width:100%;">
          </div>
        </div>
        <button type="submit" class="btn btn-primary">Create Promo Code</button>
      </form>
    </div>

    <!-- PROMO LIST -->
    <div class="card">
      <div class="card-title">All Promo Codes</div>
      <?php if (empty($promos)): ?>
      <p style="text-align:center;padding:40px;color:var(--muted);font-family:'JetBrains Mono',monospace;font-size:11px;">
        No promo codes found. Create one above!
      </p>
      <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Code</th>
              <th>Discount</th>
              <th>Valid Period</th>
              <th>Usage</th>
              <th>Status</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($promos as $promo): ?>
              <?php
              $now = time();
              $validFrom = strtotime($promo['valid_from']);
              $validUntil = strtotime($promo['valid_until']);
              $isExpired = $now > $validUntil;
              $isActive = $promo['is_active'] && !$isExpired && $now >= $validFrom;
              ?>
              <tr>
                <td>
                  <div style="font-family:'JetBrains Mono',monospace;font-size:13px;font-weight:700;color:#fff;">
                    <?= htmlspecialchars($promo['code']) ?>
                  </div>
                </td>
                <td>
                  <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:var(--a1);">
                    <?= $promo['discount_percent'] ?>%
                  </div>
                </td>
                <td>
                  <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text);">
                    <?= date('M j, Y', $validFrom) ?><br>
                    <span style="color:var(--muted);">to <?= date('M j, Y', $validUntil) ?></span>
                  </div>
                </td>
                <td>
                  <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text);">
                    <?= number_format($promo['current_uses']) ?> / <?= $promo['max_uses'] ? number_format($promo['max_uses']) : '∞' ?>
                  </div>
                </td>
                <td>
                  <?php if ($isExpired): ?>
                    <span class="promo-badge expired">Expired</span>
                  <?php elseif ($isActive): ?>
                    <span class="promo-badge active">Active</span>
                  <?php else: ?>
                    <span class="promo-badge inactive">Inactive</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;">
                  <div style="display:inline-flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
                    <?php if (!$isActive && !$isExpired && $now < $validFrom): ?>
                    <form method="POST" style="display:inline-flex;" title="Fix timezone issue - set valid_from to current time">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="fix_time">
                      <input type="hidden" name="id" value="<?= $promo['id'] ?>">
                      <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--a1);">Fix Time</button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" style="display:inline-flex;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= $promo['id'] ?>">
                      <button type="submit" class="btn btn-ghost btn-sm"><?= $promo['is_active'] ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <form method="POST" style="display:inline-flex;" onsubmit="return confirm('Delete this promo code?')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= $promo['id'] ?>">
                      <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--err);">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>
</body></html>
