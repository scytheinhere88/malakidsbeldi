<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/EmailSystem.php';
require_once __DIR__ . '/../includes/MonitoringMiddleware.php';

header('Content-Type: application/json');

$pdo = db();
$monitor = MonitoringMiddleware::start($pdo, 'cron_expiry_warnings', null);

try {
    $emailSystem = new EmailSystem($pdo);
    $sent = [
        'warnings_7days' => 0,
        'warnings_1day' => 0,
        'expired' => 0
    ];

    $sevenDaysStmt = $pdo->prepare("
        SELECT id, name, email, plan, plan_expires_at
        FROM users
        WHERE plan != 'free'
        AND plan_expires_at IS NOT NULL
        AND plan_expires_at > NOW()
        AND plan_expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)
        AND plan_expires_at > DATE_ADD(NOW(), INTERVAL 1 DAY)
        AND deleted_at IS NULL
        ORDER BY plan_expires_at ASC
        LIMIT 1000
    ");
    $sevenDaysStmt->execute();
    $sevenDaysUsers = $sevenDaysStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sevenDaysUsers as $user) {
        try {
            $pdo->prepare("
                INSERT INTO email_logs (user_id, template_key, sent_at, status)
                VALUES (?, 'plan_expiry_7days', NOW(), 'queued')
            ")->execute([$user['id']]);

            $emailSystem->sendFromTemplate('plan_expiry_7days', $user['email'], $user['name'], [
                'user_name' => $user['name'],
                'plan_name' => ucfirst($user['plan']) . ' Plan',
                'expiry_date' => date('F d, Y', strtotime($user['plan_expires_at']))
            ], $user['id'], 4);

            $sent['warnings_7days']++;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                continue;
            }
            throw $e;
        }
    }

    $oneDayStmt = $pdo->prepare("
        SELECT id, name, email, plan, plan_expires_at
        FROM users
        WHERE plan != 'free'
        AND plan_expires_at IS NOT NULL
        AND plan_expires_at > NOW()
        AND plan_expires_at <= DATE_ADD(NOW(), INTERVAL 1 DAY)
        AND deleted_at IS NULL
        ORDER BY plan_expires_at ASC
        LIMIT 1000
    ");
    $oneDayStmt->execute();
    $oneDayUsers = $oneDayStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($oneDayUsers as $user) {
        try {
            $pdo->prepare("
                INSERT INTO email_logs (user_id, template_key, sent_at, status)
                VALUES (?, 'plan_expiry_1day', NOW(), 'queued')
            ")->execute([$user['id']]);

            $emailSystem->sendFromTemplate('plan_expiry_1day', $user['email'], $user['name'], [
                'user_name' => $user['name'],
                'plan_name' => ucfirst($user['plan']) . ' Plan',
                'expiry_date' => date('F d, Y', strtotime($user['plan_expires_at']))
            ], $user['id'], 3);

            $sent['warnings_1day']++;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                continue;
            }
            throw $e;
        }
    }

    $expiredStmt = $pdo->prepare("
        SELECT id, name, email, plan, plan_expires_at
        FROM users
        WHERE plan != 'free'
        AND plan_expires_at IS NOT NULL
        AND plan_expires_at <= NOW()
        AND deleted_at IS NULL
        ORDER BY plan_expires_at DESC
        LIMIT 1000
    ");
    $expiredStmt->execute();
    $expiredUsers = $expiredStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expiredUsers as $user) {
        try {
            $pdo->prepare("
                INSERT INTO email_logs (user_id, template_key, sent_at, status)
                VALUES (?, 'plan_expired', NOW(), 'queued')
            ")->execute([$user['id']]);

            $emailSystem->sendFromTemplate('plan_expired', $user['email'], $user['name'], [
                'user_name' => $user['name'],
                'plan_name' => ucfirst($user['plan']) . ' Plan'
            ], $user['id'], 4);

            $sent['expired']++;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                continue;
            }
            throw $e;
        }
    }

    $processedEmails = $emailSystem->processQueue(100);

    MonitoringMiddleware::end($monitor, [
        'warnings_queued' => $sent,
        'emails_sent' => $processedEmails,
        'total_users_checked' => [
            '7_days' => count($sevenDaysUsers),
            '1_day' => count($oneDayUsers),
            'expired' => count($expiredUsers)
        ]
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
