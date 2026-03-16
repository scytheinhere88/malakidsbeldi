<?php
/**
 * CRON Monitoring Health Checks
 *
 * Run this script every 5 minutes via cron:
 * */5 * * * * php /path/to/api/cron_monitoring.php
 *
 * This script:
 * - Checks all system thresholds
 * - Creates alerts when thresholds are exceeded
 * - Cleans up old logs and metrics
 * - Sends notifications (when configured)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/SystemMonitor.php';
require_once __DIR__ . '/../includes/AlertManager.php';

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from command line');
}

echo "[" . date('Y-m-d H:i:s') . "] Starting monitoring health checks...\n";

try {
    $db = db();
    $monitor = SystemMonitor::getInstance($db);
    $alertManager = AlertManager::getInstance($db, $monitor);

    // ============================================
    // 1. RUN HEALTH CHECKS
    // ============================================
    echo "Running health checks...\n";
    $alerts = $alertManager->checkAllThresholds();

    if (count($alerts) > 0) {
        echo "  ⚠️  Created " . count($alerts) . " new alerts\n";
        foreach ($alerts as $alertId) {
            if ($alertId > 0) {
                $stmt = $db->prepare("SELECT * FROM alert_logs WHERE id = ?");
                $stmt->execute([$alertId]);
                $alert = $stmt->fetch();
                echo "    - [{$alert['severity']}] {$alert['alert_type']}: {$alert['message']}\n";
            }
        }
    } else {
        echo "  ✅ All metrics within normal thresholds\n";
    }

    // ============================================
    // 2. CHECK OVERALL SYSTEM HEALTH
    // ============================================
    echo "\nChecking system health...\n";
    $health = $monitor->getHealthStatus();
    echo "  Status: {$health['status']}\n";

    foreach ($health['checks'] as $checkName => $check) {
        $icon = $check['status'] === 'ok' ? '✅' : ($check['status'] === 'warning' ? '⚠️' : '🚨');
        echo "  {$icon} {$checkName}: {$check['message']}\n";
    }

    // ============================================
    // 3. CLEANUP OLD DATA
    // ============================================
    echo "\nCleaning up old data...\n";

    // Cleanup old monitoring data (keep 30 days)
    $monitor->cleanup(30);
    echo "  ✅ Cleaned up old monitoring logs\n";

    // Cleanup old alerts (keep 30 days)
    $alertManager->cleanup(30);
    echo "  ✅ Cleaned up old alerts\n";

    // ============================================
    // 4. GENERATE STATS
    // ============================================
    echo "\nSystem Statistics (Last 24 Hours):\n";

    $apiStats = $monitor->getApiStats(24);
    echo "  API Calls: " . number_format($apiStats['total_calls']) . "\n";
    echo "  Error Rate: {$apiStats['error_rate']}%\n";
    echo "  Avg Response Time: {$apiStats['avg_response_time']}ms\n";

    $errorStats = $monitor->getErrorStats(24);
    echo "  Total Errors: {$errorStats['total_errors']}\n";
    echo "  Critical Errors: {$errorStats['critical_errors']}\n";

    $diskMetrics = $monitor->getDiskUsage();
    echo "  Disk Usage: {$diskMetrics['disk_usage_percent']}%\n";

    $dbMetrics = $monitor->getDatabaseMetrics();
    echo "  Database Size: {$dbMetrics['database_size_mb']}MB\n";

    // ============================================
    // 5. CHECK FOR CRITICAL ALERTS
    // ============================================
    $activeAlerts = $alertManager->getActiveAlerts('critical');
    if (count($activeAlerts) > 0) {
        echo "\n🚨 CRITICAL ALERTS ACTIVE:\n";
        foreach ($activeAlerts as $alert) {
            echo "  - {$alert['alert_type']}: {$alert['message']}\n";
        }
    }

    echo "\n[" . date('Y-m-d H:i:s') . "] Monitoring health checks completed successfully!\n";
    exit(0);

} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
