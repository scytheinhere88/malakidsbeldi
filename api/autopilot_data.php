<?php
/**
 * Autopilot Domain Data Fetcher - Queue System
 * Creates a queue job for batch processing without timeout issues
 * Supports unlimited domains through progressive chunked processing
 */
require_once dirname(__DIR__).'/config.php';
requireLogin();

// Force create autopilot tables if not exist
try {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS autopilot_jobs (
        id VARCHAR(36) PRIMARY KEY,
        user_id INT NOT NULL,
        total_domains INT NOT NULL DEFAULT 0,
        processed_domains INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        keyword_hint TEXT,
        user_hints TEXT,
        result_data JSON,
        error_log JSON,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        INDEX idx_user_id (user_id),
        INDEX idx_status (status),
        INDEX idx_created_at (created_at DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS autopilot_queue (
        id VARCHAR(36) PRIMARY KEY,
        job_id VARCHAR(36) NOT NULL,
        domain VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        result_data JSON,
        error_message TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL,
        INDEX idx_job_id (job_id),
        INDEX idx_status (status),
        INDEX idx_job_status (job_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    error_log("Autopilot tables creation: " . $e->getMessage());
}

header('Content-Type: application/json');
require_csrf();
$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$domains     = $body['domains'] ?? [];
$keywordHint = trim($body['keyword_hint'] ?? '');
$userHints   = trim($body['user_hints']   ?? '');  // User-provided detection hints

if (empty($domains)) {
    echo json_encode(['ok'=>false,'msg'=>'No domains']);
    exit;
}

// Remove duplicates and clean
$domains = array_values(array_unique(array_map('trim', array_map('strtolower', $domains))));
$domains = array_filter($domains, function($d) {
    return strlen($d) >= 3
        && strpos($d, '.') !== false
        && !preg_match('/[^a-z0-9.\-]/', $d)
        && !in_array($d, ['localhost', '127.0.0.1', '0.0.0.0']);
});
$domains = array_values($domains);

$pdo  = db();
$user = currentUser();
$userId = $user['id'] ?? null;

try {
    // Generate unique job ID (MySQL doesn't support RETURNING like PostgreSQL)
    $jobId = uniqid('job_', true);

    // Create new job
    $stmt = $pdo->prepare("
        INSERT INTO autopilot_jobs (id, user_id, total_domains, keyword_hint, user_hints, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([$jobId, $userId, count($domains), $keywordHint, $userHints]);

    if (!$jobId) {
        throw new Exception('Failed to create job');
    }

    // Insert all domains into queue
    $stmt = $pdo->prepare("
        INSERT INTO autopilot_queue (id, job_id, domain, status)
        VALUES (?, ?, ?, 'pending')
    ");

    foreach ($domains as $domain) {
        if (!empty($domain)) {
            $queueId = uniqid('queue_', true);
            $stmt->execute([$queueId, $jobId, $domain]);
        }
    }

    // Update job status to processing
    $stmt = $pdo->prepare("UPDATE autopilot_jobs SET status = 'processing' WHERE id = ?");
    $stmt->execute([$jobId]);

    echo json_encode([
        'ok' => true,
        'job_id' => $jobId,
        'total_domains' => count($domains),
        'message' => 'Queue job created successfully'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => 'Failed to create queue job: ' . $e->getMessage()
    ]);
    error_log("Autopilot queue creation error: " . $e->getMessage());
}
