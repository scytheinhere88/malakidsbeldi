<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/BackupSystem.php';
require_once __DIR__ . '/../includes/MonitoringMiddleware.php';
require_once __DIR__ . '/../includes/CronHeartbeat.php';

header('Content-Type: application/json');

$pdo = db();
$monitor = MonitoringMiddleware::start($pdo, 'cron_backup', null);
$heartbeat = CronHeartbeat::getInstance($pdo);
$executionId = $heartbeat->startJob('backup', 1440);

try {
    $backupSystem = new BackupSystem($pdo);

    $schedulesStmt = $pdo->query("
        SELECT * FROM backup_schedules
        WHERE is_active = 1
        AND (next_run IS NULL OR next_run <= NOW())
        ORDER BY next_run ASC
    ");

    $schedules = $schedulesStmt->fetchAll(PDO::FETCH_ASSOC);
    $backupsCreated = [];

    foreach ($schedules as $schedule) {
        $tables = $schedule['tables_included'] ? json_decode($schedule['tables_included'], true) : null;

        try {
            $result = $backupSystem->createBackup(
                $schedule['id'],
                $schedule['backup_type'],
                null,
                $tables
            );

            if (!$result['success']) {
                throw new Exception('Backup creation failed: ' . ($result['error'] ?? 'Unknown error'));
            }

            if (!file_exists($result['file_path'])) {
                throw new Exception('Backup file not found after creation');
            }

            $actualSize = filesize($result['file_path']);
            if ($actualSize < 100) {
                throw new Exception('Backup file too small, likely corrupted');
            }

            $zipVerified = false;
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($result['file_path']) === true) {
                    if ($zip->getFromName('checksum.txt')) {
                        $zipVerified = true;
                    }
                    $zip->close();
                }
            }

            if (!$zipVerified) {
                error_log("WARNING: Backup {$result['backup_id']} created but ZIP verification failed");
            }

            $backupsCreated[] = [
                'schedule_id' => $schedule['id'],
                'type' => $schedule['backup_type'],
                'frequency' => $schedule['frequency'],
                'result' => $result,
                'verified' => $zipVerified,
                'actual_size' => $actualSize
            ];

        } catch (Exception $e) {
            error_log("BACKUP FAILED for schedule {$schedule['id']}: " . $e->getMessage());

            $pdo->prepare("
                INSERT INTO system_alerts (alert_type, severity, title, message, metadata, created_at)
                VALUES ('backup_failed', 'high', 'Backup Failed', ?, ?, NOW())
            ")->execute([
                'Scheduled backup failed: ' . $e->getMessage(),
                json_encode([
                    'schedule_id' => $schedule['id'],
                    'backup_type' => $schedule['backup_type'],
                    'error' => $e->getMessage()
                ])
            ]);

            $backupsCreated[] = [
                'schedule_id' => $schedule['id'],
                'type' => $schedule['backup_type'],
                'frequency' => $schedule['frequency'],
                'result' => ['success' => false, 'error' => $e->getMessage()],
                'verified' => false
            ];
        }
    }

    $stats = $backupSystem->getBackupStats();

    MonitoringMiddleware::end($monitor, [
        'schedules_processed' => count($schedules),
        'backups_created' => count($backupsCreated),
        'backup_stats' => $stats
    ]);

    echo json_encode([
        'success' => true,
        'schedules_processed' => count($schedules),
        'backups_created' => $backupsCreated,
        'stats' => $stats,
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
