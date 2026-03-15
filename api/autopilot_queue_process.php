<?php
/**
 * Autopilot Queue Processor
 * Processes domains in chunks to avoid timeout/memory issues
 * Can handle unlimited domains through progressive processing
 */
require_once dirname(__DIR__).'/config.php';
requireLogin();
require_once __DIR__.'/scraper.php';
require_once __DIR__.'/ai_parser.php';
require_once dirname(__DIR__).'/includes/EnhancedRateLimiter.php';
require_once dirname(__DIR__).'/includes/SystemMonitor.php';

header('Content-Type: application/json');
require_csrf();

$_rlUser  = currentUser();
$_rlTier  = $_rlUser['plan'] ?? 'free';
$_rlIp    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$_rlCheck = (new EnhancedRateLimiter(db(), new SystemMonitor(db())))->check($_rlIp, 'api_scraper', $_rlTier, $_rlUser['id'] ?? null);
if (!$_rlCheck['allowed']) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'msg' => 'Rate limit exceeded. Please wait before retrying.']);
    exit;
}

// Increase execution time and memory for large batches
set_time_limit(300); // 5 minutes max per chunk
ini_set('memory_limit', '512M');

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$jobId = $body['job_id'] ?? '';
$chunkSize = (int)($body['chunk_size'] ?? 10); // Reduced to 10 for better stability

if (empty($jobId)) {
    echo json_encode(['ok'=>false,'msg'=>'No job_id provided']);
    exit;
}

$pdo = db();
$user = currentUser();
$userId = $user['id'] ?? null;

try {
    // Get job details
    $stmt = $pdo->prepare("SELECT * FROM autopilot_jobs WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$jobId, $userId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        http_response_code(404);
        echo json_encode(['ok'=>false,'msg'=>'Job not found or does not belong to your account']);
        exit;
    }

    // If already completed, return final data immediately
    if ($job['status'] === 'completed') {
        $finalData = (!empty($job['result_data']) ? json_decode($job['result_data'], true) : null) ?? [];
        echo json_encode([
            'ok'        => true,
            'completed' => true,
            'progress'  => 100,
            'total'     => $job['total_domains'],
            'processed' => $job['total_domains'],
            'data'      => $finalData
        ]);
        exit;
    }

    // Claim a chunk atomically to prevent double-processing when concurrent requests occur
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        SELECT id, domain
        FROM autopilot_queue
        WHERE job_id = ? AND status = 'pending'
        ORDER BY created_at ASC
        LIMIT ?
        FOR UPDATE SKIP LOCKED
    ");
    $stmt->execute([$jobId, $chunkSize]);
    $pendingItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($pendingItems)) {
        $claimIds = array_column($pendingItems, 'id');
        $placeholders = implode(',', array_fill(0, count($claimIds), '?'));
        $claimStmt = $pdo->prepare("UPDATE autopilot_queue SET status = 'processing' WHERE id IN ($placeholders) AND status = 'pending'");
        $claimStmt->execute($claimIds);
    }
    $pdo->commit();

    if (empty($pendingItems)) {
        // All done! Mark job as completed
        $stmt = $pdo->prepare("
            UPDATE autopilot_jobs
            SET status = 'completed', completed_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$jobId]);

        // Get final aggregated results
        $stmt = $pdo->prepare("
            SELECT result_data
            FROM autopilot_queue
            WHERE job_id = ? AND status = 'completed'
        ");
        $stmt->execute([$jobId]);
        $allResults = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $finalData = [];
        foreach ($allResults as $jsonData) {
            if (empty($jsonData)) continue;
            $data = json_decode($jsonData, true);
            if (is_array($data) && isset($data['namalink'])) {
                $finalData[$data['namalink']] = $data;
            }
        }

        // Store final aggregated data
        $stmt = $pdo->prepare("UPDATE autopilot_jobs SET result_data = ? WHERE id = ?");
        $stmt->execute([json_encode($finalData), $jobId]);

        echo json_encode([
            'ok' => true,
            'completed' => true,
            'progress' => 100,
            'total' => $job['total_domains'],
            'processed' => $job['total_domains'],
            'data' => $finalData
        ]);
        exit;
    }

    // Initialize scraper and AI parser
    $monitor = new SystemMonitor($pdo);
    $rateLimiter = new EnhancedRateLimiter($pdo, $monitor);
    $scraper = new DataScraper($pdo, $rateLimiter);
    $aiParser = new AIDomainParser();

    $keywordHint = $job['keyword_hint'] ?? '';
    $userHints = $job['user_hints'] ?? '';

    // Collect domains for batch AI parsing
    $domains = array_column($pendingItems, 'domain');
    $aiParsed = $aiParser->isAvailable() ? $aiParser->parseBatch($domains, $keywordHint) : [];

    $processed = 0;
    $errors = [];

    // Process each domain in chunk with timeout protection
    foreach ($pendingItems as $item) {
        $queueId = $item['id'];
        $domain = strtolower(trim($item['domain']));

        try {
            // Set per-domain timeout
            $startTime = time();
            $timeoutSeconds = 30; // 30s max per domain

            $parsed = $aiParsed[$domain] ?? DataScraper::parseDomain($domain, $keywordHint);

            // Check timeout before scraping
            if (time() - $startTime > $timeoutSeconds) {
                throw new Exception('Domain parsing timeout');
            }

            $scraped = $scraper->getData($parsed);
            $slug = preg_replace('/\.[a-z.]+$/', '', $domain);

            // Determine data source quality
            $parseSource = 'fallback';
            if (!empty($scraped['alamat']) || !empty($scraped['daerah'])) {
                $parseSource = 'csv';
            } elseif ($parsed['parse_source'] === 'ai') {
                $parseSource = 'ai';
            }

            // Quality validation - ensure we have real data before using it
            $hasQualityData = (
                !empty($scraped['alamat']) ||
                !empty($scraped['daerah']) ||
                (!empty($parsed['location_display']) && $parsed['parse_source'] === 'ai')
            );

            // Build result with quality checks
            $resultData = [
                'namalink'        => $domain,
                'namalinkurl'     => 'https://'.$domain,
                'daerah'          => $scraped['daerah']          ?? $parsed['location_display'] ?? '',
                'daerah_short'    => $scraped['daerah_short']    ?? $parsed['location_display'] ?? '',
                'provinsi'        => $scraped['provinsi']        ?? $parsed['province']         ?? '',
                'email'           => $scraped['email']           ?? ($slug.'@gmail.com'),
                'alamat'          => $scraped['alamat']          ?? '',
                'kodepos'         => $scraped['kodepos']         ?? '',
                'embedmap'        => $scraped['embedmap']        ?? '',
                'linkmaps'        => $scraped['linkmaps']        ?? '',
                'institution'     => $parsed['institution']      ?? '',
                'institution_full'=> $parsed['institution_full'] ?? $parsed['institution'] ?? '',
                'notelp'          => $scraped['notelp']          ?? $scraped['phone'] ?? '',
                'keyword'         => $parsed['keyword']          ?? '',
                'parse_source'    => $parseSource,
                'has_quality_data' => $hasQualityData, // Flag for frontend validation
            ];

            // Mark as completed with result
            $stmt = $pdo->prepare("
                UPDATE autopilot_queue
                SET status = 'completed', result_data = ?, processed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([json_encode($resultData), $queueId]);

            $processed++;

        } catch (Exception $e) {
            // Mark as failed
            $stmt = $pdo->prepare("
                UPDATE autopilot_queue
                SET status = 'failed', error_message = ?, processed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $queueId]);

            $errors[] = ['domain' => $domain, 'error' => $e->getMessage()];
            error_log("Autopilot queue error for {$domain}: " . $e->getMessage());
        }
    }

    // Update job progress
    $stmt = $pdo->prepare("
        UPDATE autopilot_jobs
        SET processed_domains = processed_domains + ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$processed, $jobId]);

    // Get updated progress
    $stmt = $pdo->prepare("SELECT processed_domains, total_domains FROM autopilot_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $progress = $stmt->fetch(PDO::FETCH_ASSOC);

    $progressPercent = $progress['total_domains'] > 0
        ? round(($progress['processed_domains'] / $progress['total_domains']) * 100, 1)
        : 0;

    echo json_encode([
        'ok' => true,
        'completed' => false,
        'progress' => $progressPercent,
        'total' => $progress['total_domains'],
        'processed' => $progress['processed_domains'],
        'chunk_processed' => $processed,
        'errors' => $errors
    ]);

} catch (Exception $e) {
    error_log("Autopilot queue process error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => 'Processing error. Please try again.'
    ]);
}
