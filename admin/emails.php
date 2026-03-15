<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/EmailSystem.php';
startSession();
if(!isAdmin()){header('Location:'.APP_URL);exit;}

$emailSystem = new EmailSystem(db());
$queueStats = $emailSystem->getQueueStats();

$recentEmailsStmt = db()->query("
  SELECT el.*, u.name as user_name, u.email as user_email
  FROM email_logs el
  LEFT JOIN users u ON el.user_id = u.id
  ORDER BY el.sent_at DESC
  LIMIT 100
");
$recentEmails = $recentEmailsStmt->fetchAll(PDO::FETCH_ASSOC);

$templatesStmt = db()->query("SELECT * FROM email_templates ORDER BY template_key ASC");
$templates = $templatesStmt->fetchAll(PDO::FETCH_ASSOC);

$totalSentStmt = db()->query("SELECT COUNT(*) as count FROM email_logs WHERE sent_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
$totalSent = $totalSentStmt->fetch(PDO::FETCH_ASSOC)['count'];

$failedStmt = db()->query("SELECT COUNT(*) as count FROM email_queue WHERE status = 'failed'");
$totalFailed = $failedStmt->fetch(PDO::FETCH_ASSOC)['count'];
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Email System — Admin</title>
<link rel="stylesheet" href="/assets/main.css">
<style>
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;}
.stat-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px;}
.stat-value{font-size:32px;font-weight:800;color:#fff;margin:8px 0;}
.stat-label{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;}
.email-list{background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;}
.email-item{padding:16px;border-bottom:1px solid var(--border);}
.email-item:last-child{border-bottom:none;}
.status-badge{padding:4px 12px;border-radius:6px;font-size:11px;font-weight:700;text-transform:uppercase;}
.status-sent{background:#10b98144;color:#10b981;}
.status-pending{background:#f59e0b44;color:#f59e0b;}
.status-failed{background:#ef444444;color:#ef4444;}
.template-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:12px;}
</style>
</head><body>
<?php include '_sidebar.php'; ?>
<div class="main-content">
<div class="top-bar"><h1>Email System</h1></div>
<div class="content-area">

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-label">Sent (30d)</div>
    <div class="stat-value"><?= number_format($totalSent) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Queue Pending</div>
    <div class="stat-value" style="color:#f59e0b;">
      <?php
      $pending = 0;
      foreach($queueStats as $stat) {
        if($stat['status'] === 'pending') $pending = $stat['count'];
      }
      echo number_format($pending);
      ?>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Failed</div>
    <div class="stat-value" style="color:#ef4444;"><?= number_format($totalFailed) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Templates</div>
    <div class="stat-value"><?= count($templates) ?></div>
  </div>
</div>

<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:24px;">
  <h3 style="margin:0 0 16px 0;">Queue Management</h3>
  <div style="display:flex;gap:12px;">
    <button onclick="processQueue()" class="btn btn-amber">Process Queue Now</button>
    <button onclick="retryFailed()" class="btn" style="border:1px solid var(--border);">Retry Failed Emails</button>
  </div>
</div>

<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:24px;">
  <h3 style="margin:0 0 16px 0;">Queue Status</h3>
  <table style="width:100%;border-collapse:collapse;">
    <thead>
      <tr style="border-bottom:1px solid var(--border);text-align:left;">
        <th style="padding:8px;">Status</th>
        <th style="padding:8px;">Count</th>
        <th style="padding:8px;">Oldest</th>
        <th style="padding:8px;">Newest</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($queueStats as $stat): ?>
      <tr style="border-bottom:1px solid var(--border);">
        <td style="padding:8px;">
          <span class="status-badge status-<?= $stat['status'] ?>"><?= ucfirst($stat['status']) ?></span>
        </td>
        <td style="padding:8px;"><?= number_format($stat['count']) ?></td>
        <td style="padding:8px;"><?= $stat['oldest'] ? date('M d, H:i', strtotime($stat['oldest'])) : '-' ?></td>
        <td style="padding:8px;"><?= $stat['newest'] ? date('M d, H:i', strtotime($stat['newest'])) : '-' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:24px;">
  <h3 style="margin:0 0 16px 0;">Email Templates</h3>
  <?php foreach($templates as $tpl): ?>
  <div class="template-card">
    <div style="display:flex;justify-content:space-between;align-items:start;">
      <div>
        <div style="font-weight:700;margin-bottom:4px;"><?= htmlspecialchars($tpl['template_name']) ?></div>
        <div style="font-size:11px;color:var(--muted);font-family:monospace;"><?= htmlspecialchars($tpl['template_key']) ?></div>
        <div style="font-size:12px;color:var(--muted);margin-top:8px;">Subject: <?= htmlspecialchars($tpl['subject']) ?></div>
      </div>
      <span class="status-badge status-<?= $tpl['is_active'] ? 'sent' : 'failed' ?>">
        <?= $tpl['is_active'] ? 'Active' : 'Inactive' ?>
      </span>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="email-list">
  <div style="padding:20px;border-bottom:1px solid var(--border);"><h3 style="margin:0;">Recent Emails</h3></div>
  <?php foreach($recentEmails as $email): ?>
  <div class="email-item">
    <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
      <div style="font-weight:700;"><?= htmlspecialchars($email['subject']) ?></div>
      <span class="status-badge status-<?= $email['status'] ?>"><?= ucfirst($email['status']) ?></span>
    </div>
    <div style="font-size:12px;color:var(--muted);">
      To: <?= htmlspecialchars($email['to_email']) ?>
      <?php if($email['user_name']): ?>
      (<?= htmlspecialchars($email['user_name']) ?>)
      <?php endif; ?>
      • Template: <?= htmlspecialchars($email['template_key']) ?>
      • <?= date('M d, Y H:i:s', strtotime($email['sent_at'])) ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

</div>
</div>

<script>
async function processQueue() {
  try {
    const res = await fetch('/api/cron_email_queue.php');
    const data = await res.json();

    if(data.success) {
      alert(`Processed ${data.total_processed} emails\nSent: ${data.emails_sent}\nFailed: ${data.emails_failed}`);
      location.reload();
    } else {
      alert('Error: ' + data.error);
    }
  } catch(err) {
    alert('Failed to process queue: ' + err.message);
  }
}

async function retryFailed() {
  if(!confirm('Retry all failed emails?')) return;

  try {
    const res = await fetch('/api/email_retry.php', {method: 'POST'});
    const data = await res.json();

    if(data.success) {
      alert(`${data.retried} emails queued for retry`);
      location.reload();
    } else {
      alert('Error: ' + data.error);
    }
  } catch(err) {
    alert('Failed to retry emails: ' + err.message);
  }
}
</script>
</body></html>
