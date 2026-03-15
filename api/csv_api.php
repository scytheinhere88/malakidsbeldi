<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/RateLimitMiddleware.php';
require_once __DIR__ . '/../includes/SystemMonitor.php';

$requestStartTime = microtime(true);
header('Content-Type: application/json');

$allowedOrigin = defined('APP_URL') ? APP_URL : 'https://bulkreplacetool.com';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Access-Control-Allow-Methods: POST, GET');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    header('Vary: Origin');
    http_response_code(200);
    exit;
}

header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Vary: Origin');

function apiError($msg, $code = 400) {
    global $requestStartTime, $pdo, $uid;

    // Log API call before exiting
    if (isset($pdo) && isset($requestStartTime)) {
        try {
            $monitor = SystemMonitor::getInstance($pdo);
            $monitor->logApiCall([
                'endpoint' => '/api/csv_api.php',
                'method' => $_SERVER['REQUEST_METHOD'],
                'user_id' => $uid ?? null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'response_time_ms' => round((microtime(true) - $requestStartTime) * 1000),
                'status_code' => $code,
                'error_message' => $msg,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {
            // Silent fail on logging error
        }
    }

    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

if (empty($apiKey)) {
    apiError('Missing API key. Provide X-API-Key header', 401);
}

$pdo = db();

try {
    $stmt = $pdo->prepare("SELECT id, plan FROM users WHERE api_key = ? AND is_active = 1");
    $stmt->execute([$apiKey]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        apiError('Invalid API key', 401);
    }

    // API Access is LIFETIME PLAN ONLY (exclusive benefit!)
    if ($user['plan'] !== 'lifetime') {
        apiError('API access is only available for Lifetime plan users. Upgrade your plan to access the API.', 403);
    }

    $uid = $user['id'];
    $_SESSION['uid'] = $uid;

    checkApiRateLimit('csv_api', 100, 3600);

} catch (Exception $e) {
    error_log("CSV API auth error: " . $e->getMessage());
    apiError('Authentication failed', 401);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    if (!$body) {
        apiError('Invalid JSON body');
    }

    $domains = $body['domains'] ?? [];
    $fields = $body['fields'] ?? ['namalink', 'namalinkurl', 'daerah', 'email', 'notelp'];
    $fieldSuffixes = $body['field_suffixes'] ?? [];
    $customHeaders = $body['custom_headers'] ?? [];
    $phoneStart = $body['phone_start'] ?? '0811-0401-1110';
    $keywordHint = $body['keyword_hint'] ?? '';
    $webhookUrl = $body['webhook_url'] ?? '';
    $format = $body['format'] ?? 'csv';

    if (empty($domains)) {
        apiError('domains array is required');
    }

    if (!is_array($domains)) {
        apiError('domains must be an array');
    }

    if (count($domains) > 500) {
        apiError('Maximum 500 domains per request');
    }

    if (!in_array($format, ['csv', 'json'])) {
        apiError('format must be csv or json');
    }

    require_once __DIR__ . '/csv_generator.php';
    ensureTables($pdo);

    $token = bin2hex(random_bytes(16));
    $jobId = 'api_' . bin2hex(random_bytes(8));

    $payload = json_encode([
        'domains' => $domains,
        'fields' => $fields,
        'field_suffixes' => $fieldSuffixes,
        'custom_headers' => $customHeaders,
        'phone_start' => $phoneStart,
        'force_refresh' => true,
        'suffix' => '123',
        'keyword_hint' => $keywordHint,
        'api_mode' => true,
        'job_id' => $jobId,
        'webhook_url' => $webhookUrl,
        'format' => $format
    ]);

    try {
        $pdo->prepare("INSERT INTO csv_gen_queue (token, user_id, payload, expires_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 30 MINUTE))")
            ->execute([$token, $uid, $payload]);

        $statusUrl = APP_URL . '/api/csv_api.php?job_id=' . $jobId;
        $streamUrl = APP_URL . '/api/csv_generator.php?action=stream_generate&token=' . $token;

        // Log successful API call
        try {
            $monitor = SystemMonitor::getInstance($pdo);
            $monitor->logApiCall([
                'endpoint' => '/api/csv_api.php',
                'method' => 'POST',
                'user_id' => $uid,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'response_time_ms' => round((microtime(true) - $requestStartTime) * 1000),
                'status_code' => 200,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {
            // Silent fail on logging error
        }

        echo json_encode([
            'ok' => true,
            'job_id' => $jobId,
            'status_url' => $statusUrl,
            'stream_url' => $streamUrl,
            'message' => 'Job queued successfully',
            'domains_count' => count($domains),
            'estimated_time_seconds' => count($domains) * 1.2
        ]);

    } catch (Exception $e) {
        error_log("CSV API queue job error: " . $e->getMessage());
        apiError('Failed to queue job. Please try again.', 500);
    }

    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $jobId = $_GET['job_id'] ?? '';

    if (empty($jobId)) {
        apiError('job_id parameter required');
    }

    try {
        $stmt = $pdo->prepare("
            SELECT status, total_domains, success_count, failed_count, processing_time_ms, created_at
            FROM csv_gen_analytics
            WHERE job_id = ? AND user_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$jobId, $uid]);
        $analytics = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$analytics) {
            // Log successful API call
            try {
                $monitor = SystemMonitor::getInstance($pdo);
                $monitor->logApiCall([
                    'endpoint' => '/api/csv_api.php',
                    'method' => 'GET',
                    'user_id' => $uid,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'response_time_ms' => round((microtime(true) - $requestStartTime) * 1000),
                    'status_code' => 200,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
            } catch (Exception $e) {
                // Silent fail on logging error
            }

            echo json_encode([
                'ok' => true,
                'status' => 'pending',
                'message' => 'Job is queued or processing'
            ]);
            exit;
        }

        // Log successful API call
        try {
            $monitor = SystemMonitor::getInstance($pdo);
            $monitor->logApiCall([
                'endpoint' => '/api/csv_api.php',
                'method' => 'GET',
                'user_id' => $uid,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'response_time_ms' => round((microtime(true) - $requestStartTime) * 1000),
                'status_code' => 200,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {
            // Silent fail on logging error
        }

        echo json_encode([
            'ok' => true,
            'status' => $analytics['status'],
            'total_domains' => (int)$analytics['total_domains'],
            'success_count' => (int)$analytics['success_count'],
            'failed_count' => (int)$analytics['failed_count'],
            'processing_time_ms' => (int)$analytics['processing_time_ms'],
            'completed_at' => $analytics['created_at']
        ]);

    } catch (Exception $e) {
        error_log("CSV API job status error: " . $e->getMessage());
        apiError('Failed to get job status. Please try again.', 500);
    }

    exit;
}

apiError('Method not allowed. Use POST to create job or GET to check status', 405);
