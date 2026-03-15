<?php
/**
 * Autopilot Queue Status
 * Get real-time status of a queue job
 */
require_once dirname(__DIR__).'/config.php';
requireLogin();

header('Content-Type: application/json');

$jobId = $_GET['job_id'] ?? '';

if (empty($jobId)) {
    echo json_encode(['ok'=>false,'msg'=>'No job_id provided']);
    exit;
}

$pdo = db();
$user = currentUser();
$userId = $user['id'] ?? null;

try {
    // Get job details
    $stmt = $pdo->prepare("
        SELECT id, user_id, total_domains, processed_domains, status, result_data, created_at, completed_at
        FROM autopilot_jobs
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$jobId, $userId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        echo json_encode(['ok'=>false,'msg'=>'Job not found']);
        exit;
    }

    $progress = $job['total_domains'] > 0
        ? round(($job['processed_domains'] / $job['total_domains']) * 100, 1)
        : 0;

    // If completed, return final data
    if ($job['status'] === 'completed') {
        $resultData = json_decode($job['result_data'], true) ?? [];

        echo json_encode([
            'ok' => true,
            'status' => 'completed',
            'progress' => 100,
            'total' => $job['total_domains'],
            'processed' => $job['processed_domains'],
            'data' => $resultData,
            'completed_at' => $job['completed_at']
        ]);
        exit;
    }

    // Get queue stats
    $stmt = $pdo->prepare("
        SELECT
            status,
            COUNT(*) as count
        FROM autopilot_queue
        WHERE job_id = ?
        GROUP BY status
    ");
    $stmt->execute([$jobId]);
    $queueStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = [
        'pending' => 0,
        'processing' => 0,
        'completed' => 0,
        'failed' => 0
    ];

    foreach ($queueStats as $stat) {
        $stats[$stat['status']] = (int)$stat['count'];
    }

    echo json_encode([
        'ok' => true,
        'status' => $job['status'],
        'progress' => $progress,
        'total' => $job['total_domains'],
        'processed' => $job['processed_domains'],
        'queue_stats' => $stats,
        'created_at' => $job['created_at']
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => 'Status check error: ' . $e->getMessage()
    ]);
}
