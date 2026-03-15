<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';
requireAdmin();

$auditLogger = new AuditLogger(db());

$page = max(1, intval($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$filters = [];
if (!empty($_GET['category'])) {
    $filters['action_category'] = $_GET['category'];
}
if (!empty($_GET['status'])) {
    $filters['status'] = $_GET['status'];
}
if (!empty($_GET['user_id'])) {
    $filters['user_id'] = intval($_GET['user_id']);
}
if (!empty($_GET['date_from'])) {
    $filters['date_from'] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $filters['date_to'] = $_GET['date_to'];
}

$logs = $auditLogger->getAuditLogs($filters, $limit, $offset);

$stmt = db()->prepare("SELECT COUNT(*) as total FROM audit_logs");
$stmt->execute();
$totalLogs = $stmt->fetch()['total'];
$totalPages = ceil($totalLogs / $limit);

$pageTitle = 'Audit Logs';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> — Admin</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/main.css">
    <style>
        .log-entry {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
        }
        .log-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .log-action {
            font-weight: 600;
            color: #fff;
        }
        .log-status {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-success { background: rgba(34, 197, 94, 0.2); color: #22c55e; }
        .status-failed { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .status-blocked { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .log-meta {
            font-size: 12px;
            color: var(--muted);
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        .filter-bar {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .filter-bar select, .filter-bar input {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--bg);
            color: var(--text);
            font-size: 13px;
        }
        .pagination {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-top: 20px;
        }
        .pagination a {
            padding: 8px 12px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text);
            text-decoration: none;
        }
        .pagination a:hover {
            background: var(--border);
        }
        .pagination .active {
            background: var(--a1);
            border-color: var(--a1);
            color: #000;
        }
    </style>
</head>
<body>
<?php include '_sidebar.php'; ?>
<div class="content">
    <div class="content-header">
        <div>
            <h1><?= $pageTitle ?></h1>
            <p style="color:var(--muted);">System activity and security logs</p>
        </div>
    </div>

    <form method="GET" class="filter-bar">
        <select name="category">
            <option value="">All Categories</option>
            <option value="auth" <?= ($_GET['category']??'')==='auth'?'selected':'' ?>>Authentication</option>
            <option value="admin" <?= ($_GET['category']??'')==='admin'?'selected':'' ?>>Admin Actions</option>
            <option value="payment" <?= ($_GET['category']??'')==='payment'?'selected':'' ?>>Payments</option>
            <option value="data" <?= ($_GET['category']??'')==='data'?'selected':'' ?>>Data Export</option>
            <option value="api" <?= ($_GET['category']??'')==='api'?'selected':'' ?>>API</option>
            <option value="security" <?= ($_GET['category']??'')==='security'?'selected':'' ?>>Security</option>
        </select>

        <select name="status">
            <option value="">All Status</option>
            <option value="success" <?= ($_GET['status']??'')==='success'?'selected':'' ?>>Success</option>
            <option value="failed" <?= ($_GET['status']??'')==='failed'?'selected':'' ?>>Failed</option>
            <option value="blocked" <?= ($_GET['status']??'')==='blocked'?'selected':'' ?>>Blocked</option>
            <option value="error" <?= ($_GET['status']??'')==='error'?'selected':'' ?>>Error</option>
        </select>

        <input type="number" name="user_id" placeholder="User ID" value="<?= htmlspecialchars($_GET['user_id']??'') ?>">
        <input type="date" name="date_from" placeholder="From" value="<?= htmlspecialchars($_GET['date_from']??'') ?>">
        <input type="date" name="date_to" placeholder="To" value="<?= htmlspecialchars($_GET['date_to']??'') ?>">

        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="audit_logs.php" class="btn btn-secondary">Reset</a>
    </form>

    <div style="margin-bottom:12px;font-size:13px;color:var(--muted);">
        Showing <?= count($logs) ?> of <?= number_format($totalLogs) ?> logs
    </div>

    <?php if (empty($logs)): ?>
        <div class="card" style="text-align:center;padding:60px;">
            <div style="font-size:48px;margin-bottom:16px;">📋</div>
            <div style="font-size:18px;font-weight:600;margin-bottom:8px;">No Logs Found</div>
            <p style="color:var(--muted);">No audit logs match your filters.</p>
        </div>
    <?php else: ?>
        <?php foreach ($logs as $log): ?>
            <div class="log-entry">
                <div class="log-header">
                    <div class="log-action">
                        <?= htmlspecialchars($log['action_type']) ?>
                    </div>
                    <span class="log-status status-<?= htmlspecialchars($log['status']) ?>">
                        <?= htmlspecialchars($log['status']) ?>
                    </span>
                </div>
                <div class="log-meta">
                    <span>📅 <?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></span>
                    <?php if ($log['user_id']): ?>
                        <span>👤 User #<?= htmlspecialchars($log['user_id']) ?></span>
                    <?php endif; ?>
                    <?php if ($log['admin_id']): ?>
                        <span>🛡 Admin #<?= htmlspecialchars($log['admin_id']) ?></span>
                    <?php endif; ?>
                    <span>📂 <?= htmlspecialchars($log['action_category']) ?></span>
                    <span>🌐 <?= htmlspecialchars($log['ip_address']) ?></span>
                    <?php if ($log['target_type']): ?>
                        <span>🎯 <?= htmlspecialchars($log['target_type']) ?>: <?= htmlspecialchars($log['target_id']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($log['error_message']): ?>
                    <div style="margin-top:8px;padding:8px;background:rgba(239,68,68,0.1);border-left:2px solid #ef4444;font-size:12px;color:#ef4444;">
                        <strong>Error:</strong> <?= htmlspecialchars($log['error_message']) ?>
                    </div>
                <?php endif; ?>
                <?php if ($log['request_data']): ?>
                    <details style="margin-top:8px;font-size:12px;">
                        <summary style="cursor:pointer;color:var(--muted);">Request Data</summary>
                        <pre style="margin-top:8px;padding:8px;background:var(--bg);border-radius:4px;overflow-x:auto;"><?= htmlspecialchars($log['request_data']) ?></pre>
                    </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?><?= http_build_query(array_diff_key($_GET, ['page'=>''])) ? '&'.http_build_query(array_diff_key($_GET, ['page'=>''])) : '' ?>">← Previous</a>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <a href="?page=<?= $i ?><?= http_build_query(array_diff_key($_GET, ['page'=>''])) ? '&'.http_build_query(array_diff_key($_GET, ['page'=>''])) : '' ?>"
                       class="<?= $i === $page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?><?= http_build_query(array_diff_key($_GET, ['page'=>''])) ? '&'.http_build_query(array_diff_key($_GET, ['page'=>''])) : '' ?>">Next →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
