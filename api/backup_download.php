<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/BackupSystem.php';
require_once __DIR__ . '/../includes/RateLimiter.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$_rl = new RateLimiter(db());
$_rlId = 'backup_dl_' . ($_SESSION['user_id'] ?? $_SERVER['REMOTE_ADDR']);
$_rlCheck = $_rl->check($_rlId, 'backup_download', 20, 3600);
if (!$_rlCheck['allowed']) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again later.']);
    exit;
}

$isAdmin = ($_SESSION['is_admin'] ?? 0) == 1;
$action = $_GET['action'] ?? 'download';
$backupId = $_GET['backup_id'] ?? null;

if (!$backupId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Backup ID required']);
    exit;
}

try {
    $backupSystem = new BackupSystem($pdo);

    switch ($action) {
        case 'download':
            $result = $backupSystem->downloadBackup($backupId, $_SESSION['user_id'], $isAdmin);

            if (!$result['success']) {
                throw new Exception('Download preparation failed');
            }

            // Sanitize filename to prevent header injection
            $safeFilename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $result['filename']);

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
            header('Content-Length: ' . (int)$result['file_size']);
            header('X-Checksum-SHA256: ' . $result['checksum']);
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');

            readfile($result['file_path']);
            exit;

        case 'verify':
            $result = $backupSystem->verifyBackupIntegrity($backupId);
            echo json_encode($result);
            break;

        case 'encrypt':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Admin access required']);
                exit;
            }

            $result = $backupSystem->encryptBackup($backupId);
            echo json_encode($result);
            break;

        case 'decrypt':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Admin access required']);
                exit;
            }

            $result = $backupSystem->decryptBackup($backupId);
            echo json_encode($result);
            break;

        case 'download_history':
            $result = $backupSystem->getDownloadHistory($backupId);
            echo json_encode(['success' => true, 'downloads' => $result]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }

} catch (Exception $e) {
    error_log("Backup download error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Request failed. Please try again.']);
}
