<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/SystemMonitor.php';
require_once __DIR__ . '/../includes/AlertManager.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

try {
    $db = db();
    $monitor = SystemMonitor::getInstance($db);
    $alertManager = AlertManager::getInstance($db, $monitor);

    // Get comprehensive health status
    $health = $monitor->getHealthStatus();

    // Add additional metrics
    $health['metrics'] = [
        'api' => $monitor->getApiStats(1),
        'errors' => $monitor->getErrorStats(1),
        'database' => $monitor->getDatabaseMetrics(),
        'disk' => $monitor->getDiskUsage(),
        'memory' => $monitor->getMemoryUsage(),
        'queue' => $monitor->getQueueMetrics()
    ];

    // Get active alerts
    $activeAlerts = $alertManager->getActiveAlerts();
    $health['active_alerts'] = [
        'count' => count($activeAlerts),
        'critical' => count(array_filter($activeAlerts, fn($a) => $a['severity'] === 'critical')),
        'warning' => count(array_filter($activeAlerts, fn($a) => $a['severity'] === 'warning')),
    ];

    // Add monitoring-friendly metadata
    $health['monitoring'] = [
        'endpoint_version' => '1.0',
        'response_time_ms' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2),
        'server_time' => date('Y-m-d H:i:s'),
        'server_timezone' => date_default_timezone_get(),
    ];

    // Add uptime tracking
    $health['uptime_seconds'] = (int)$_SERVER['REQUEST_TIME'];

    // Set appropriate HTTP status code
    $statusCode = 200;
    if ($health['status'] === 'unhealthy') {
        $statusCode = 503; // Service Unavailable
    } elseif ($health['status'] === 'degraded') {
        $statusCode = 200; // Still OK, but with warnings
    }

    // Add custom headers for monitoring tools
    header('X-Health-Status: ' . $health['status']);
    header('X-Response-Time: ' . $health['monitoring']['response_time_ms'] . 'ms');
    header('X-Active-Alerts: ' . $health['active_alerts']['count']);

    http_response_code($statusCode);
    echo json_encode($health, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(503);
    echo json_encode([
        'status' => 'unhealthy',
        'timestamp' => date('Y-m-d H:i:s'),
        'error' => 'Health check failed',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
