<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/EnhancedRateLimiter.php';
require_once __DIR__ . '/../includes/SystemMonitor.php';
require_once __DIR__ . '/../includes/CSRFMiddleware.php';

session_start();

header('Content-Type: application/json');

CSRFMiddleware::require();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'request';

$pdo = db();
$monitor = new SystemMonitor($pdo);
$rateLimiter = new EnhancedRateLimiter($pdo, $monitor);

$user = currentUser();
$userTier = $user['plan'] ?? 'free';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

$rateLimitCheck = $rateLimiter->check($ip, 'data_export', $userTier, $userId);
$rateLimiter->setRateLimitHeaders($rateLimitCheck);

if (!$rateLimitCheck['allowed']) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Rate limit exceeded for data export',
        'message' => $rateLimitCheck['message'] ?? 'Too many export requests',
        'retry_after' => $rateLimitCheck['retry_after'] ?? 60
    ]);
    exit;
}

try {
    switch ($action) {
        case 'request':
            $hourlyCheck = $pdo->prepare("
                SELECT COUNT(*) as count FROM data_exports
                WHERE user_id = :user_id
                AND requested_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $hourlyCheck->execute(['user_id' => $userId]);
            $hourlyExports = $hourlyCheck->fetch(PDO::FETCH_ASSOC);

            $hourlyLimit = ($userTier === 'lifetime' || $userTier === 'platinum') ? 10 : 5;

            if ($hourlyExports['count'] >= $hourlyLimit) {
                throw new Exception("Export limit exceeded: Maximum {$hourlyLimit} exports per hour");
            }

            $format = $_POST['format'] ?? 'zip';
            $includes = $_POST['includes'] ?? ['profile', 'usage', 'analytics', 'invoices'];

            if (!in_array($format, ['json', 'csv', 'zip'])) {
                throw new Exception('Invalid format');
            }

            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) as count FROM data_exports
                WHERE user_id = :user_id
                AND status IN ('pending', 'processing')
            ");
            $checkStmt->execute(['user_id' => $userId]);
            $pending = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($pending['count'] > 0) {
                throw new Exception('You already have a pending export request');
            }

            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

            $stmt = $pdo->prepare("
                INSERT INTO data_exports
                (user_id, request_type, format, download_token, expires_at, includes)
                VALUES (:user_id, 'gdpr', :format, :token, :expires_at, :includes)
            ");

            $stmt->execute([
                'user_id' => $userId,
                'format' => $format,
                'token' => $token,
                'expires_at' => $expiresAt,
                'includes' => json_encode($includes)
            ]);

            $exportId = $pdo->lastInsertId();

            $processExport = processDataExport($pdo, $exportId, $userId, $format, $includes);

            if ($processExport['success']) {
                require_once __DIR__ . '/../includes/AuditLogger.php';
                $auditLogger = new AuditLogger($pdo);
                $auditLogger->log(
                    'data_export',
                    $userId,
                    'requested',
                    'user',
                    $userId,
                    [
                        'export_id' => $exportId,
                        'format' => $format,
                        'includes' => $includes,
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]
                );

                echo json_encode([
                    'success' => true,
                    'export_id' => $exportId,
                    'download_token' => $token,
                    'expires_at' => $expiresAt,
                    'message' => 'Your data export is ready for download'
                ]);
            } else {
                throw new Exception($processExport['error'] ?? 'Export processing failed');
            }
            break;

        case 'status':
            $exportId = $_GET['export_id'] ?? 0;

            $stmt = $pdo->prepare("
                SELECT id, status, format, file_size, download_token, expires_at, completed_at, error_message
                FROM data_exports
                WHERE id = :id AND user_id = :user_id
            ");
            $stmt->execute(['id' => $exportId, 'user_id' => $userId]);
            $export = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$export) {
                throw new Exception('Export not found');
            }

            echo json_encode(['success' => true, 'export' => $export]);
            break;

        case 'download':
            $token = $_GET['token'] ?? '';

            $stmt = $pdo->prepare("
                SELECT * FROM data_exports
                WHERE download_token = :token
                AND user_id = :user_id
                AND status = 'completed'
                AND expires_at > NOW()
                AND download_count < max_downloads
            ");
            $stmt->execute(['token' => $token, 'user_id' => $userId]);
            $export = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$export) {
                throw new Exception('Export not found or expired');
            }

            if (!file_exists($export['file_path'])) {
                throw new Exception('Export file not found');
            }

            $updateStmt = $pdo->prepare("
                UPDATE data_exports
                SET download_count = download_count + 1,
                    downloaded_at = NOW()
                WHERE id = :id
            ");
            $updateStmt->execute(['id' => $export['id']]);

            $filename = basename($export['file_path']);

            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($export['file_path']));
            readfile($export['file_path']);
            exit;

        case 'list':
            $stmt = $pdo->prepare("
                SELECT id, request_type, status, format, file_size, expires_at, requested_at, completed_at
                FROM data_exports
                WHERE user_id = :user_id
                ORDER BY requested_at DESC
                LIMIT 10
            ");
            $stmt->execute(['user_id' => $userId]);
            $exports = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'exports' => $exports]);
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function processDataExport($pdo, $exportId, $userId, $format, $includes) {
    try {
        $updateStmt = $pdo->prepare("
            UPDATE data_exports SET status = 'processing' WHERE id = :id
        ");
        $updateStmt->execute(['id' => $exportId]);

        $data = collectUserData($pdo, $userId, $includes);

        $exportDir = __DIR__ . '/../exports';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $timestamp = date('Y-m-d_His');
        $filename = "export_{$userId}_{$timestamp}";

        if ($format === 'json') {
            $filepath = $exportDir . '/' . $filename . '.json';
            file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT));
        } elseif ($format === 'csv') {
            $filepath = $exportDir . '/' . $filename . '.zip';
            createCSVZip($data, $filepath);
        } else {
            $filepath = $exportDir . '/' . $filename . '.zip';
            createJSONZip($data, $filepath);
        }

        $fileSize = filesize($filepath);

        $completeStmt = $pdo->prepare("
            UPDATE data_exports
            SET status = 'completed',
                file_path = :file_path,
                file_size = :file_size,
                completed_at = NOW()
            WHERE id = :id
        ");

        $completeStmt->execute([
            'id' => $exportId,
            'file_path' => $filepath,
            'file_size' => $fileSize
        ]);

        return ['success' => true, 'file_path' => $filepath];

    } catch (Exception $e) {
        $errorStmt = $pdo->prepare("
            UPDATE data_exports
            SET status = 'failed', error_message = :error
            WHERE id = :id
        ");
        $errorStmt->execute(['id' => $exportId, 'error' => $e->getMessage()]);

        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function collectUserData($pdo, $userId, $includes) {
    $data = [];

    if (in_array('profile', $includes)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $data['profile'] = $stmt->fetch(PDO::FETCH_ASSOC);
        unset($data['profile']['password']);
    }

    if (in_array('usage', $includes)) {
        $stmt = $pdo->prepare("
            SELECT * FROM usage_logs
            WHERE user_id = :user_id
            ORDER BY created_at DESC
            LIMIT 1000
        ");
        $stmt->execute(['user_id' => $userId]);
        $data['usage_logs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (in_array('analytics', $includes)) {
        $stmt = $pdo->prepare("
            SELECT * FROM analytics_events
            WHERE user_id = :user_id
            ORDER BY created_at DESC
            LIMIT 1000
        ");
        $stmt->execute(['user_id' => $userId]);
        $data['analytics'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (in_array('invoices', $includes)) {
        $stmt = $pdo->prepare("
            SELECT * FROM users WHERE id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $data['subscription'] = [
            'plan' => $user['plan'] ?? 'free',
            'plan_expiry' => $user['plan_expiry'] ?? null,
            'created_at' => $user['created_at'] ?? null
        ];
    }

    return $data;
}

function createJSONZip($data, $filepath) {
    $zip = new ZipArchive();

    if ($zip->open($filepath, ZipArchive::CREATE) !== TRUE) {
        throw new Exception('Could not create ZIP file');
    }

    foreach ($data as $key => $value) {
        $zip->addFromString($key . '.json', json_encode($value, JSON_PRETTY_PRINT));
    }

    $zip->close();
}

function createCSVZip($data, $filepath) {
    $zip = new ZipArchive();

    if ($zip->open($filepath, ZipArchive::CREATE) !== TRUE) {
        throw new Exception('Could not create ZIP file');
    }

    foreach ($data as $key => $value) {
        if (is_array($value) && !empty($value)) {
            $csv = arrayToCSV($value);
            $zip->addFromString($key . '.csv', $csv);
        }
    }

    $zip->close();
}

function arrayToCSV($array) {
    if (empty($array)) return '';

    $first = reset($array);
    if (!is_array($first)) {
        $array = [$array];
        $first = $array[0];
    }

    $output = fopen('php://temp', 'r+');

    fputcsv($output, array_keys($first));

    foreach ($array as $row) {
        fputcsv($output, $row);
    }

    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);

    return $csv;
}
