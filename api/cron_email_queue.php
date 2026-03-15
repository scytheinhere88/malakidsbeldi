<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

try {
    $pdo = db();

    if (!class_exists('EmailSystem')) {
        require_once __DIR__ . '/../includes/EmailSystem.php';
    }

    if (!class_exists('MonitoringMiddleware')) {
        require_once __DIR__ . '/../includes/MonitoringMiddleware.php';
    }

    if (!class_exists('SystemMonitor')) {
        require_once __DIR__ . '/../includes/SystemMonitor.php';
    }

    if (!class_exists('AlertManager')) {
        require_once __DIR__ . '/../includes/AlertManager.php';
    }

    if (!class_exists('EnhancedRateLimiter')) {
        require_once __DIR__ . '/../includes/EnhancedRateLimiter.php';
    }

    $monitor = MonitoringMiddleware::start($pdo, 'cron_email_queue', null);

    $emailSystem = new EmailSystem($pdo);

    $result = $emailSystem->processQueue(50);

    $stats = $emailSystem->getQueueStats();

    $monitor->end(200);

    echo json_encode([
        'success' => true,
        'emails_sent' => $result['sent'],
        'emails_failed' => $result['failed'],
        'total_processed' => $result['total'],
        'queue_stats' => $stats,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    if (isset($monitor)) {
        try {
            $monitor->end(500, $e->getMessage());
        } catch (Exception $ignored) {
        }
    }

    error_log("Email queue error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    error_log("Stack trace: " . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Email queue processing failed',
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
}
