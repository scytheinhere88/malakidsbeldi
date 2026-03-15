<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/WebhookRetryQueue.php';
require_once __DIR__ . '/../includes/MonitoringMiddleware.php';

requireCronAuth();

header('Content-Type: application/json');

$pdo = db();
$monitor = MonitoringMiddleware::start($pdo, 'cron_webhook_retry', null);

try {
    $retryQueue = new WebhookRetryQueue($pdo);

    $results = $retryQueue->processPending();

    $cleanedUp = $retryQueue->cleanupOld(30);

    MonitoringMiddleware::end($monitor, array_merge($results, [
        'cleaned_up' => $cleanedUp
    ]));

    echo json_encode([
        'success' => true,
        'retry_results' => $results,
        'cleaned_up' => $cleanedUp,
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
