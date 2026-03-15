<?php
/*
  # Advanced Database Cleanup Job

  Automated cleanup for:
  1. Old audit logs (>90 days)
  2. Old email logs (>60 days, successful only)
  3. Old API call logs (>30 days)
  4. Old system metrics (>30 days)
  5. Orphaned records
  6. Temporary data
  7. Soft-deleted records (>30 days)
  8. Failed login attempts (>7 days)

  Run via cron: 0 2 * * * (2 AM daily)
*/

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/AuditLogger.php';

requireCronLock('cron_advanced_cleanup');

requireCronAuth();

header('Content-Type: application/json');

$startTime = microtime(true);
$stats = [
    'audit_logs_deleted' => 0,
    'email_logs_deleted' => 0,
    'api_logs_deleted' => 0,
    'metrics_deleted' => 0,
    'login_attempts_deleted' => 0,
    'soft_deleted_purged' => 0,
    'orphaned_cleaned' => 0,
    'temp_data_deleted' => 0,
];

try {
    $db = db();

    // ============================================
    // 1. CLEANUP OLD AUDIT LOGS (>90 days)
    // ============================================
    $stmt = $db->prepare("
        DELETE FROM audit_logs
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
        AND action_category NOT IN ('payment', 'admin')
        LIMIT 10000
    ");
    $stmt->execute();
    $stats['audit_logs_deleted'] = $stmt->rowCount();

    // ============================================
    // 2. CLEANUP OLD EMAIL LOGS (>60 days, successful)
    // ============================================
    $stmt = $db->prepare("
        DELETE FROM email_logs
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
        AND status = 'sent'
        LIMIT 10000
    ");
    $stmt->execute();
    $stats['email_logs_deleted'] = $stmt->rowCount();

    // ============================================
    // 3. CLEANUP OLD API CALL LOGS (>30 days)
    // ============================================
    $stmt = $db->prepare("
        DELETE FROM api_call_logs
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        LIMIT 10000
    ");
    $stmt->execute();
    $stats['api_logs_deleted'] = $stmt->rowCount();

    // ============================================
    // 4. CLEANUP OLD SYSTEM METRICS (>30 days)
    // ============================================
    $stmt = $db->prepare("
        DELETE FROM system_metrics
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND metric_category NOT IN ('uptime', 'revenue')
        LIMIT 10000
    ");
    $stmt->execute();
    $stats['metrics_deleted'] = $stmt->rowCount();

    // ============================================
    // 5. CLEANUP OLD LOGIN ATTEMPTS (>7 days)
    // ============================================
    $stmt = $db->prepare("
        DELETE FROM login_attempts
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        LIMIT 10000
    ");
    $stmt->execute();
    $stats['login_attempts_deleted'] = $stmt->rowCount();

    // ============================================
    // 6. PURGE SOFT-DELETED RECORDS (>30 days)
    // ============================================

    // Users
    $stmt = $db->prepare("
        DELETE FROM users
        WHERE deleted_at IS NOT NULL
        AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        LIMIT 100
    ");
    $stmt->execute();
    $stats['soft_deleted_purged'] += $stmt->rowCount();

    // Usage log
    $stmt = $db->prepare("
        DELETE FROM usage_log
        WHERE deleted_at IS NOT NULL
        AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        LIMIT 1000
    ");
    $stmt->execute();
    $stats['soft_deleted_purged'] += $stmt->rowCount();

    // ============================================
    // 7. CLEANUP ORPHANED RECORDS
    // ============================================

    // Orphaned user_addons (user doesn't exist)
    $stmt = $db->prepare("
        DELETE ua FROM user_addons ua
        LEFT JOIN users u ON ua.user_id = u.id
        WHERE u.id IS NULL
        LIMIT 1000
    ");
    $stmt->execute();
    $stats['orphaned_cleaned'] += $stmt->rowCount();

    // Orphaned usage_log (user doesn't exist)
    $stmt = $db->prepare("
        DELETE ul FROM usage_log ul
        LEFT JOIN users u ON ul.user_id = u.id
        WHERE u.id IS NULL
        LIMIT 1000
    ");
    $stmt->execute();
    $stats['orphaned_cleaned'] += $stmt->rowCount();

    // ============================================
    // 8. CLEANUP RATE LIMIT ENTRIES (>7 days)
    // ============================================
    $stmt = $db->prepare("
        DELETE FROM enhanced_rate_limits
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        LIMIT 10000
    ");
    $stmt->execute();
    $stats['temp_data_deleted'] = $stmt->rowCount();

    // Cleanup basic rate_limits table (window-based)
    $rlDeleted = $db->exec("
        DELETE FROM rate_limits
        WHERE window_start < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        AND (blocked_until IS NULL OR blocked_until < NOW())
    ");
    $stats['temp_data_deleted'] += (int)$rlDeleted;

    // ============================================
    // 9. OPTIMIZE TABLES AFTER CLEANUP
    // ============================================
    $tablesToOptimize = [
        'audit_logs',
        'email_logs',
        'api_call_logs',
        'system_metrics',
        'login_attempts',
        'users',
        'usage_log'
    ];

    foreach ($tablesToOptimize as $table) {
        try {
            $db->exec("OPTIMIZE TABLE {$table}");
        } catch (PDOException $e) {
            // Ignore optimize errors (not critical)
        }
    }

    // ============================================
    // 10. LOG CLEANUP STATS
    // ============================================
    $totalDeleted = array_sum($stats);
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    // Log to audit
    AuditLogger::log(
        null,
        null,
        'database_cleanup',
        'system',
        null,
        null,
        null,
        null,
        $stats,
        'success'
    );

    // Log to system metrics
    if (file_exists(__DIR__ . '/../includes/SystemMonitor.php')) {
        require_once __DIR__ . '/../includes/SystemMonitor.php';
        $monitor = SystemMonitor::getInstance($db);
        $monitor->recordMetric('cleanup', 'records_deleted', $totalDeleted, $stats);
        $monitor->recordMetric('cleanup', 'execution_time_ms', $executionTime);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Database cleanup completed successfully',
        'stats' => $stats,
        'total_deleted' => $totalDeleted,
        'execution_time_ms' => $executionTime,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    error_log("cron_advanced_cleanup error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    echo json_encode([
        'success' => false,
        'error' => 'Cleanup failed. Check server logs.',
        'stats' => $stats
    ], JSON_PRETTY_PRINT);

    // Log error
    if (file_exists(__DIR__ . '/../includes/AuditLogger.php')) {
        AuditLogger::log(
            null,
            null,
            'database_cleanup',
            'system',
            null,
            null,
            null,
            null,
            ['error' => $e->getMessage()],
            'error',
            $e->getMessage()
        );
    }
}
