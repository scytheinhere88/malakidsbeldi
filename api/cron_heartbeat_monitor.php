<?php
/*
  # Cron Heartbeat Monitor

  Checks all cron jobs for missed executions and alerts if any job is late.

  Run via cron every 5 minutes
*/

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/CronHeartbeat.php';

requireCronAuth();
requireCronLock('cron_heartbeat_monitor');

header('Content-Type: application/json');

try {
    $db = db();
    $heartbeat = CronHeartbeat::getInstance($db);

    $executionId = $heartbeat->startJob('heartbeat_monitor', 5);

    $missedJobs = $heartbeat->checkMissedJobs();

    $allJobs = $heartbeat->getAllJobsStatus();

    $criticalJobs = array_filter($allJobs, fn($j) => $j['health_status'] === 'late' || $j['health_status'] === 'failed');
    $healthyJobs = array_filter($allJobs, fn($j) => $j['health_status'] === 'ok');

    $heartbeat->endJob($executionId, 'success', count($missedJobs), null, [
        'missed_jobs_count' => count($missedJobs),
        'critical_jobs_count' => count($criticalJobs),
        'healthy_jobs_count' => count($healthyJobs)
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Heartbeat monitor completed',
        'summary' => [
            'total_jobs' => count($allJobs),
            'healthy' => count($healthyJobs),
            'critical' => count($criticalJobs),
            'missed' => count($missedJobs)
        ],
        'missed_jobs' => $missedJobs,
        'critical_jobs' => array_values($criticalJobs),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    if (isset($executionId)) {
        $heartbeat->endJob($executionId, 'failed', null, $e->getMessage());
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
