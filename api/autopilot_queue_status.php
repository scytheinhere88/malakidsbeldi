<?php
/**
 * Autopilot Queue Status
 * Get real-time status of a queue job
 */
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/EnhancedRateLimiter.php';
require_once dirname(__DIR__).'/includes/SystemMonitor.php';
requireLogin();

header('Content-Type: application/json');

$_rlUser  = currentUser();
$_rlTier  = $_rlUser['plan'] ?? 'free';
$_rlIp    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$_rlCheck = (new EnhancedRateLimiter(db(), new SystemMonitor(db())))->check($_rlIp, 'default', $_rlTier, $_rlUser['id'] ?? null);
if (!$_rlCheck['allowed']) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'msg' => 'Rate limit exceeded. Please wait before retrying.']);
    exit;
}

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
        $resultData = (!empty($job['result_data']) ? json_decode($job['result_data'], true) : null) ?? [];

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
        'pending'    => 0,
        'processing' => 0,
        'completed'  => 0,
        'failed'     => 0
    ];

    foreach ($queueStats as $stat) {
        $stats[$stat['status']] = (int)$stat['count'];
    }

    // Auto-recover domains stuck in 'processing' state for > 5 minutes
    $pdo->prepare("
        UPDATE autopilot_queue
        SET status = 'pending'
        WHERE job_id = ?
          AND status = 'processing'
          AND processed_at IS NULL
          AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ")->execute([$jobId]);

    // If all queue items are done but job status is not completed, fix it
    if ($stats['pending'] === 0 && $stats['processing'] === 0 && $job['total_domains'] > 0) {
        $doneCount = $stats['completed'] + $stats['failed'];
        if ($doneCount >= $job['total_domains']) {
            $finalStmt = $pdo->prepare("SELECT result_data FROM autopilot_queue WHERE job_id = ? AND status = 'completed'");
            $finalStmt->execute([$jobId]);
            $allResults = $finalStmt->fetchAll(PDO::FETCH_COLUMN);

            $finalData = [];
            foreach ($allResults as $jsonData) {
                if (empty($jsonData)) continue;
                $data = json_decode($jsonData, true);
                if (is_array($data) && isset($data['namalink'])) {
                    $finalData[$data['namalink']] = $data;
                }
            }

            $pdo->prepare("
                UPDATE autopilot_jobs
                SET status = 'completed', completed_at = NOW(), result_data = ?, processed_domains = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([json_encode($finalData), $doneCount, $jobId]);

            echo json_encode([
                'ok'          => true,
                'status'      => 'completed',
                'progress'    => 100,
                'total'       => $job['total_domains'],
                'processed'   => $doneCount,
                'queue_stats' => $stats,
                'data'        => $finalData,
                'created_at'  => $job['created_at']
            ]);
            exit;
        }
    }

    echo json_encode([
        'ok'          => true,
        'status'      => $job['status'],
        'progress'    => $progress,
        'total'       => $job['total_domains'],
        'processed'   => $job['processed_domains'],
        'queue_stats' => $stats,
        'created_at'  => $job['created_at']
    ]);

} catch (Exception $e) {
    error_log("Autopilot queue status error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => 'Status check failed. Please try again.'
    ]);
}
