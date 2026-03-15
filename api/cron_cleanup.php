<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/SoftDelete.php';
require_once __DIR__ . '/../includes/MonitoringMiddleware.php';

header('Content-Type: application/json');

requireCronLock('cron_cleanup');

$pdo = db();
$monitor = MonitoringMiddleware::start($pdo, 'cron_cleanup', null);

try {
    $softDelete = new SoftDelete($pdo);

    $cleanupResults = [
        'users' => $softDelete->cleanupOldDeleted('users', 90),
        'analytics_events' => $softDelete->cleanupOldDeleted('analytics_events', 90),
        'usage_logs' => $softDelete->cleanupOldDeleted('usage_logs', 90),
        'api_keys' => $softDelete->cleanupOldDeleted('api_keys', 90)
    ];

    $expiredExportsStmt = $pdo->query("
        SELECT file_path FROM data_exports
        WHERE status = 'completed'
        AND expires_at < NOW()
    ");
    $expiredExports = $expiredExportsStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($expiredExports as $filePath) {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    $deleteExportsStmt = $pdo->prepare("
        DELETE FROM data_exports
        WHERE status = 'completed' AND expires_at < NOW()
    ");
    $deleteExportsStmt->execute();
    $cleanupResults['expired_exports'] = $deleteExportsStmt->rowCount();

    $oldEmailsStmt = $pdo->prepare("
        DELETE FROM email_queue
        WHERE status IN ('sent', 'cancelled')
        AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $oldEmailsStmt->execute();
    $cleanupResults['old_email_queue'] = $oldEmailsStmt->rowCount();

    $oldEmailLogsStmt = $pdo->prepare("
        DELETE FROM email_logs
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
    ");
    $oldEmailLogsStmt->execute();
    $cleanupResults['old_email_logs'] = $oldEmailLogsStmt->rowCount();

    MonitoringMiddleware::end($monitor, [
        'cleanup_results' => $cleanupResults,
        'total_records_deleted' => array_sum($cleanupResults)
    ]);

    echo json_encode([
        'success' => true,
        'cleanup_results' => $cleanupResults,
        'total_records_deleted' => array_sum($cleanupResults),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    MonitoringMiddleware::recordError($monitor, $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
