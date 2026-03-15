<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/PerformanceIndexes.php';
requireAdmin();

$results = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_indexes') {
    $results = addPerformanceIndexes(db());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Setup Indexes — <?= APP_NAME ?></title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>
.admin-container{max-width:900px;margin:40px auto;padding:0 20px;}
.section{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:24px;margin-bottom:20px;}
.section-title{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:#fff;margin-bottom:16px;}
table{width:100%;border-collapse:collapse;}
table th{text-align:left;padding:10px;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);border-bottom:1px solid var(--border);}
table td{padding:12px 10px;border-bottom:1px solid rgba(255,255,255,.03);font-size:13px;}
.badge{display:inline-block;padding:4px 10px;border-radius:12px;font-size:10px;font-weight:700;}
.badge-success{background:rgba(0,230,118,.15);color:#00e676;}
.badge-warning{background:rgba(255,193,7,.15);color:#ffc107;}
.badge-error{background:rgba(255,82,82,.15);color:#ff5252;}
</style>
</head>
<body>
<div class="admin-container">
    <div class="section">
        <div class="section-title">⚡ Performance Indexes Setup</div>
        <p style="color:var(--muted);margin-bottom:20px;">
            This will create database indexes to improve query performance for users, usage logs, and caching.
        </p>

        <?php if (empty($results)): ?>
        <form method="POST">
            <input type="hidden" name="action" value="create_indexes">
            <button type="submit" class="btn btn-amber">Create All Indexes</button>
        </form>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Index</th>
                    <th>Status</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $result): ?>
                <tr>
                    <td><code style="font-size:11px;color:var(--a1);"><?= htmlspecialchars($result['index']) ?></code></td>
                    <td>
                        <?php if ($result['status'] === 'created'): ?>
                            <span class="badge badge-success">CREATED</span>
                        <?php elseif ($result['status'] === 'exists'): ?>
                            <span class="badge badge-warning">EXISTS</span>
                        <?php else: ?>
                            <span class="badge badge-error">FAILED</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:11px;color:var(--muted);">
                        <?= $result['error'] ? htmlspecialchars($result['error']) : '-' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="margin-top:20px;">
            <a href="/admin/analytics.php" class="btn btn-amber">Go to Analytics</a>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
