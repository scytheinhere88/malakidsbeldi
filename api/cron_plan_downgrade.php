<?php
/**
 * Cron Job: Automated Plan Downgrade Handler
 *
 * Purpose: Process expired grace periods and downgrade users automatically
 * Schedule: Run daily at 02:00 AM
 *
 * Setup:
 * - Via cPanel Cron Jobs: php /path/to/api/cron_plan_downgrade.php
 * - Via command line: 0 2 * * * /usr/bin/php /path/to/api/cron_plan_downgrade.php
 *
 * Security: This file should be protected from web access
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/PlanManagement.php';
require_once dirname(__DIR__) . '/includes/Analytics.php';
require_once dirname(__DIR__) . '/includes/EmailSystem.php';
require_once dirname(__DIR__) . '/includes/AuditLogger.php';

requireCronAuth();
requireCronLock('cron_plan_downgrade');

set_time_limit(300);
ini_set('memory_limit', '256M');

$startTime = microtime(true);
$log = [];

function logMessage(string $message, string $level = 'info'): void {
    global $log;
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[{$timestamp}] [{$level}] {$message}";
    $log[] = $entry;
    error_log("CRON_PLAN_DOWNGRADE: {$entry}");
    if (php_sapi_name() === 'cli') {
        echo $entry . PHP_EOL;
    }
}

try {
    logMessage("Starting automated plan downgrade process");

    $db = db();
    $planMgmt = new PlanManagement($db);
    $analytics = new Analytics($db);
    $auditLogger = new AuditLogger($db);

    $usersToCheck = $db->query("
        SELECT id, username, email, plan, plan_expires_at, plan_status
        FROM users
        WHERE plan != 'free'
        AND (plan_expires_at <= NOW() OR plan_status = 'grace_period')
    ")->fetchAll(PDO::FETCH_ASSOC);

    logMessage("Found " . count($usersToCheck) . " users with expired plans or in grace period");

    $stats = [
        'checked' => count($usersToCheck),
        'grace_started' => 0,
        'downgraded' => 0,
        'errors' => 0
    ];

    foreach ($usersToCheck as $user) {
        try {
            $graceInfo = $planMgmt->checkGracePeriod($user['id']);

            if ($graceInfo && $graceInfo['active']) {
                if ($graceInfo['days_remaining'] <= 0) {
                    logMessage("Executing downgrade for user #{$user['id']} ({$user['email']})");

                    if ($planMgmt->executeDowngrade(
                        $user['id'],
                        $graceInfo['original_plan'],
                        $graceInfo['target_plan']
                    )) {
                        $stats['downgraded']++;
                        logMessage("Successfully downgraded user #{$user['id']} from {$graceInfo['original_plan']} to {$graceInfo['target_plan']}", 'success');

                        $auditLogger->log(
                            'plan_downgrade',
                            null,
                            'downgraded',
                            'user',
                            $user['id'],
                            [
                                'from_plan' => $graceInfo['original_plan'],
                                'to_plan' => $graceInfo['target_plan'],
                                'reason' => 'grace_period_expired',
                                'automated' => true
                            ]
                        );
                    } else {
                        $stats['errors']++;
                        logMessage("Failed to downgrade user #{$user['id']}", 'error');
                    }
                } else {
                    logMessage("User #{$user['id']} still in grace period ({$graceInfo['days_remaining']} days remaining)");
                }
            } else {
                if ($user['plan_expires_at'] && strtotime($user['plan_expires_at']) <= time()) {
                    logMessage("Starting grace period for user #{$user['id']} ({$user['email']})");

                    if ($planMgmt->startGracePeriod($user['id'], $user['plan'], 'free')) {
                        $stats['grace_started']++;
                        logMessage("Grace period started for user #{$user['id']}", 'success');

                        $analytics->trackEvent('grace_period_started', 'billing', $user['id'], [
                            'original_plan' => $user['plan'],
                            'target_plan' => 'free',
                            'grace_days' => PlanManagement::GRACE_PERIOD_DAYS
                        ]);

                        // Send email notification about grace period
                        $emailSystem = new EmailSystem($db);
                        $emailSystem->sendFromTemplate('plan_expiry_1day', $user['email'], $user['name'], [
                            'user_name' => $user['name'],
                            'plan_name' => ucfirst($user['plan']) . ' Plan',
                            'expiry_date' => date('F d, Y', strtotime($user['plan_expires_at']))
                        ], $user['id'], 3);
                    } else {
                        $stats['errors']++;
                        logMessage("Failed to start grace period for user #{$user['id']}", 'error');
                    }
                }
            }

        } catch (Exception $e) {
            $stats['errors']++;
            logMessage("Error processing user #{$user['id']}: " . $e->getMessage(), 'error');
        }
    }

    $processedCount = $planMgmt->processExpiredGracePeriods();

    $executionTime = round(microtime(true) - $startTime, 2);

    logMessage("=== SUMMARY ===");
    logMessage("Users checked: {$stats['checked']}");
    logMessage("Grace periods started: {$stats['grace_started']}");
    logMessage("Users downgraded: {$stats['downgraded']}");
    logMessage("Errors: {$stats['errors']}");
    logMessage("Execution time: {$executionTime}s");

    $analytics->trackEvent('cron_plan_downgrade', 'system', null, [
        'checked' => $stats['checked'],
        'grace_started' => $stats['grace_started'],
        'downgraded' => $stats['downgraded'],
        'errors' => $stats['errors'],
        'execution_time' => $executionTime
    ]);

    $result = [
        'success' => true,
        'stats' => $stats,
        'execution_time' => $executionTime,
        'log' => $log
    ];

    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode($result, JSON_PRETTY_PRINT);
    }

} catch (Exception $e) {
    logMessage("CRITICAL ERROR: " . $e->getMessage(), 'error');
    logMessage("Stack trace: " . $e->getTraceAsString(), 'error');

    $result = [
        'success' => false,
        'error' => $e->getMessage(),
        'log' => $log
    ];

    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode($result, JSON_PRETTY_PRINT);
    }

    exit(1);
}
