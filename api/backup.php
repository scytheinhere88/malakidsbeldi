<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/BackupSystem.php';
require_once __DIR__ . '/../includes/RateLimiter.php';

startSession();

header('Content-Type: application/json');

if (!isAdmin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Rate limit: 10 backups per hour for admin
$rateLimiter = new RateLimiter(db());
$identifier = 'admin_backup_' . ($_SESSION['uid'] ?? 'unknown');
if (!$rateLimiter->check($identifier, 10, 3600)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Rate limit exceeded. Max 10 backups per hour.']);
    exit;
}

$action = $_GET['action'] ?? 'create';

try {
    $backupSystem = new BackupSystem(db());

    switch ($action) {
        case 'create':
            $backupType = $_POST['backup_type'] ?? 'manual';
            $tables = isset($_POST['tables']) ? json_decode($_POST['tables'], true) : null;

            $result = $backupSystem->createBackup(null, $backupType, $_SESSION['uid'] ?? null, $tables);

            echo json_encode([
                'success' => true,
                'backup' => $result,
                'message' => 'Backup created successfully'
            ]);
            break;

        case 'list':
            $limit = (int)($_GET['limit'] ?? 50);
            $offset = (int)($_GET['offset'] ?? 0);

            $backups = $backupSystem->getBackupLogs($limit, $offset);

            echo json_encode([
                'success' => true,
                'backups' => $backups,
                'count' => count($backups)
            ]);
            break;

        case 'stats':
            $stats = $backupSystem->getBackupStats();

            echo json_encode([
                'success' => true,
                'stats' => $stats
            ]);
            break;

        case 'restore':
            $backupId = (int)($_POST['backup_id'] ?? 0);
            $dryRun = isset($_POST['dry_run']) && $_POST['dry_run'] === '1';

            if (!$backupId) {
                throw new Exception('Backup ID required');
            }

            $result = $backupSystem->restoreBackup($backupId, $dryRun);

            if ($dryRun) {
                echo json_encode([
                    'success' => true,
                    'message' => $result['message'],
                    'dry_run' => true,
                    'format' => $result['format']
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'message' => 'Backup restored successfully',
                    'pre_restore_backup_id' => $result['pre_restore_backup_id'],
                    'format' => $result['format']
                ]);
            }
            break;

        case 'download':
            $backupId = (int)($_GET['id'] ?? 0);

            if (!$backupId) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Backup ID required']);
                exit;
            }

            $stmt = db()->prepare("
                SELECT file_path, backup_type, created_at
                FROM backup_logs
                WHERE id = :id AND status = 'completed'
            ");
            $stmt->execute(['id' => $backupId]);
            $backup = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$backup || !file_exists($backup['file_path'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Backup file not found']);
                exit;
            }

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="backup_' . $backup['backup_type'] . '_' . date('Y-m-d_His', strtotime($backup['created_at'])) . '.zip"');
            header('Content-Length: ' . filesize($backup['file_path']));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: public');

            readfile($backup['file_path']);
            exit;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
