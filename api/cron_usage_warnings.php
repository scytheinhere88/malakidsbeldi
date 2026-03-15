<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/EmailSystem.php';
require_once __DIR__ . '/../includes/MonitoringMiddleware.php';

header('Content-Type: application/json');

$pdo = db();
$monitor = MonitoringMiddleware::start($pdo, 'cron_usage_warnings', null);

try {
    $emailSystem = new EmailSystem($pdo);
    $sent = [
        'warnings_80percent' => 0,
        'warnings_90percent' => 0,
        'limit_reached' => 0
    ];

    // Get current month's usage for all users
    $currentMonth = date('Y-m-01');
    $usageStmt = $pdo->prepare("
        SELECT
            u.id,
            u.name,
            u.email,
            u.plan,
            COALESCE(SUM(ul.rows_processed), 0) as total_rows
        FROM users u
        LEFT JOIN usage_log ul ON ul.user_id = u.id
            AND ul.created_at >= :current_month
        WHERE u.deleted_at IS NULL
        AND u.plan != 'lifetime'
        GROUP BY u.id
        ORDER BY u.id ASC
        LIMIT 5000
    ");
    $usageStmt->execute(['current_month' => $currentMonth]);
    $users = $usageStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as $user) {
        $planLimits = PLAN_DATA[$user['plan']] ?? PLAN_DATA['free'];
        $maxRows = $planLimits['mr'];
        $usedRows = (int)$user['total_rows'];

        if ($maxRows <= 0) continue; // Unlimited plan

        $usagePercent = ($usedRows / $maxRows) * 100;

        // 80% warning
        if ($usagePercent >= 80 && $usagePercent < 90) {
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) as count FROM email_logs
                WHERE user_id = :user_id
                AND template_key = 'usage_warning_80'
                AND sent_at > :current_month
            ");
            $checkStmt->execute([
                'user_id' => $user['id'],
                'current_month' => $currentMonth
            ]);
            $alreadySent = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($alreadySent['count'] == 0) {
                $emailSystem->sendFromTemplate('usage_warning_80', $user['email'], $user['name'], [
                    'user_name' => $user['name'],
                    'used_rows' => number_format($usedRows),
                    'max_rows' => number_format($maxRows),
                    'percent' => round($usagePercent, 1),
                    'remaining_rows' => number_format($maxRows - $usedRows),
                    'upgrade_url' => APP_URL . '/dashboard/billing.php'
                ], $user['id'], 4);

                $sent['warnings_80percent']++;
            }
        }

        // 90% warning
        if ($usagePercent >= 90 && $usagePercent < 100) {
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) as count FROM email_logs
                WHERE user_id = :user_id
                AND template_key = 'usage_warning_90'
                AND sent_at > :current_month
            ");
            $checkStmt->execute([
                'user_id' => $user['id'],
                'current_month' => $currentMonth
            ]);
            $alreadySent = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($alreadySent['count'] == 0) {
                $emailSystem->sendFromTemplate('usage_warning_90', $user['email'], $user['name'], [
                    'user_name' => $user['name'],
                    'used_rows' => number_format($usedRows),
                    'max_rows' => number_format($maxRows),
                    'percent' => round($usagePercent, 1),
                    'remaining_rows' => number_format($maxRows - $usedRows),
                    'upgrade_url' => APP_URL . '/dashboard/billing.php'
                ], $user['id'], 3);

                $sent['warnings_90percent']++;
            }
        }

        // Limit reached
        if ($usagePercent >= 100) {
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) as count FROM email_logs
                WHERE user_id = :user_id
                AND template_key = 'usage_limit_reached'
                AND sent_at > :current_month
            ");
            $checkStmt->execute([
                'user_id' => $user['id'],
                'current_month' => $currentMonth
            ]);
            $alreadySent = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($alreadySent['count'] == 0) {
                $emailSystem->sendFromTemplate('usage_limit_reached', $user['email'], $user['name'], [
                    'user_name' => $user['name'],
                    'used_rows' => number_format($usedRows),
                    'max_rows' => number_format($maxRows),
                    'upgrade_url' => APP_URL . '/dashboard/billing.php'
                ], $user['id'], 2);

                $sent['limit_reached']++;
            }
        }
    }

    $processedEmails = $emailSystem->processQueue(100);

    MonitoringMiddleware::end($monitor, [
        'warnings_queued' => $sent,
        'emails_sent' => $processedEmails,
        'total_users_checked' => count($users)
    ]);

    echo json_encode([
        'success' => true,
        'warnings_queued' => $sent,
        'emails_processed' => $processedEmails,
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
