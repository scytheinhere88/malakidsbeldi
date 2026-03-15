<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';
requireLogin();

$user = currentUser();
if (!$user) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

$uid = (int)$user['id'];
$pdo = db();
$auditLogger = new AuditLogger($pdo);
$auditLogger->setUserId($uid);

$export = [
    'exported_at' => date('c'),
    'account'     => [],
    'usage_log'   => [],
    'audit_log'   => [],
    'addons'      => [],
];

$s = $pdo->prepare("SELECT id, name, email, plan, billing_cycle, plan_expires_at, rollover_balance, created_at, status FROM users WHERE id = ?");
$s->execute([$uid]);
$export['account'] = $s->fetch() ?: [];

$s = $pdo->prepare("SELECT job_type, job_name, csv_rows, files_updated, created_at FROM usage_log WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1000");
$s->execute([$uid]);
$export['usage_log'] = $s->fetchAll();

$s = $pdo->prepare("SELECT action, action_category, status, created_at FROM audit_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 500");
$s->execute([$uid]);
$export['audit_log'] = $s->fetchAll();

$s = $pdo->prepare("SELECT addon_slug, purchased_at, is_active FROM user_addons WHERE user_id = ?");
$s->execute([$uid]);
$export['addons'] = $s->fetchAll();

$auditLogger->log('gdpr_data_export', 'security', 'success');

$filename = 'bulkreplace_data_export_' . date('Ymd_His') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
