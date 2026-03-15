<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/RateLimitMiddleware.php';
enforce_payload_limit(MAX_PAYLOAD_CSV);

// ── SSE STREAM (reads job from DB by token — fixes URL length limit) ──────────
if (isset($_GET['action']) && $_GET['action'] === 'stream_generate') {
    // Set up SSE headers FIRST before any code that might throw
    set_time_limit(0);
    ini_set('max_execution_time', 0);
    ini_set('memory_limit', '1024M');
    ignore_user_abort(false);
    ini_set('output_buffering','off');
    ini_set('zlib.output_compression', false);
    while (ob_get_level()) ob_end_flush();

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    echo "retry: 2000\n\n";
    echo ": connected\n\n";
    if (ob_get_level() > 0) ob_flush(); flush();

    function sse(string $event, array $data): void {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level() > 0) ob_flush();
        flush();
    }

    // Override global exception handler for SSE streams
    set_exception_handler(function($e) {
        sse('error', ['msg' => 'Fatal error: ' . $e->getMessage()]);
        sse('log', ['type'=>'error', 'msg' => 'Location: ' . $e->getFile() . ':' . $e->getLine()]);
        exit;
    });

    require_once __DIR__ . '/scraper.php';
    $pdo     = db();
    $token   = trim($_GET['token'] ?? '');

    // Load and validate job from queue (this is our auth for SSE streams)
    $payload = null;
    $uid = null;
    try {
        $stmt = $pdo->prepare("SELECT user_id, payload FROM csv_gen_queue WHERE token=? AND expires_at > NOW()");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if ($row) {
            $uid = $row['user_id'];
            $payload = json_decode($row['payload'], true);
        }
    } catch(Exception $e) {
        sse('error', ['msg' => 'Database error: ' . $e->getMessage()]);
        exit;
    }

    if (!$payload || !$uid) {
        sse('error', ['msg' => 'Job token invalid atau expired. Silahkan generate ulang.']);
        exit;
    }

    // Rate limit check (now we have uid from job, not session)
    $_SESSION['uid'] = $uid; // Set session for rate limiter
    try {
        checkApiRateLimit('csv_generator', 50, 3600);
    } catch (Exception $e) {
        sse('error', ['msg' => 'Rate limit exceeded. Please try again later.']);
        exit;
    }

    $scraper = new DataScraper($pdo);

    $domains       = $payload['domains']        ?? [];
    $fields        = $payload['fields']         ?? ['namalink','namalinkurl','daerah','email','notelp','alamat','embedmap','linkmaps'];
    $fieldSuffixes = $payload['field_suffixes'] ?? [];
    $customHeaders = $payload['custom_headers'] ?? [];
    $phoneStart    = $payload['phone_start']    ?? '0811-0401-1110';
    $forceRefresh  = $payload['force_refresh']  ?? true;
    $suffix        = $payload['suffix']         ?? '123';
    $maxRows       = 500; // Increased limit: 500 domains max per batch (professional SaaS standard)

    // Deduplicate domains (preserve order)
    $seen = []; $domains = array_values(array_filter($domains, function($d) use (&$seen) {
        $d = strtolower(trim($d));
        if (isset($seen[$d])) return false;
        $seen[$d] = true; return true;
    }));

    // Enforce limit
    $originalCount = count($domains);
    if ($originalCount > $maxRows) {
        $domains = array_slice($domains, 0, $maxRows);
        sse('log', ['type'=>'warn', 'msg' => "⚠️ Limit {$maxRows} rows/generate. {$originalCount} domain diterima → hanya {$maxRows} yang diproses."]);
    }

    $total = count($domains);
    if ($total === 0) { sse('error', ['msg'=>'Tidak ada domain valid.']); exit; }

    // Get keyword hint early
    $keywordHint = $payload['keyword_hint'] ?? '';

    // Get batch info if this is part of a batch group
    $batchInfo = $payload['batch_info'] ?? null;

    // Ensure tables
    ensureTables($pdo);

    // IMMEDIATE FEEDBACK — Show banner first
    sse('log', ['type'=>'info', 'msg' => "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"]);
    echo "\n"; if (ob_get_level() > 0) ob_flush(); flush();

    if ($batchInfo) {
        sse('log', ['type'=>'info', 'msg' => "🤖 BulkReplace Bot™ — Batch {$batchInfo['batch_num']}/{$batchInfo['batch_total']}"]);
    } else {
        sse('log', ['type'=>'info', 'msg' => "🤖 BulkReplace Bot™ CSV Generator Pro"]);
    }
    echo "\n"; if (ob_get_level() > 0) ob_flush(); flush();

    sse('log', ['type'=>'info', 'msg' => "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"]);
    echo "\n"; if (ob_get_level() > 0) ob_flush(); flush();

    sse('log', ['type'=>'ok', 'msg' => "✅ Stream connected successfully!"]);
    echo "\n"; if (ob_get_level() > 0) ob_flush(); flush();

    // Connection health check
    if (connection_aborted()) {
        exit;
    }

    sse('log', ['type'=>'info', 'msg' => "📊 Total domains to process: {$total}" . ($originalCount > $maxRows ? " (from {$originalCount} submitted)" : "")]);
    echo "\n"; if (ob_get_level() > 0) ob_flush(); flush();

    sse('log', ['type'=>'info', 'msg' => "⚙️ Mode: Force Scrape | Real-time Processing"]);
    echo "\n"; if (ob_get_level() > 0) ob_flush(); flush();

    if ($total >= 50) {
        $eta = ceil($total * 1.2);
        $etaMins = floor($eta / 60);
        $etaSecs = $eta % 60;
        $etaStr = $etaMins > 0 ? "{$etaMins}m {$etaSecs}s" : "{$etaSecs}s";
        sse('log', ['type'=>'warn', 'msg' => "⏱️ Large batch detected — Estimated time: ~{$etaStr}"]);
        echo "\n"; if (ob_get_level() > 0) ob_flush(); flush();
    }

    sse('log', ['type'=>'info', 'msg' => "🔄 Initializing processing engine..."]);
    echo "\n"; if (ob_get_level() > 0) ob_flush(); flush();

    sse('log', ['type'=>'info', 'msg' => "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"]);
    echo "\n"; if (ob_get_level() > 0) ob_flush(); flush();

    $rows = []; $keyword = '';

    // ── AI Parse inside SSE (with live log + keepalive so nginx won't kill it) ──
    require_once __DIR__ . '/ai_parser.php';
    $aiParser = new AIDomainParser();
    $aiParsed = [];

    if ($aiParser->isAvailable()) {
        sse('log', ['type'=>'info', 'msg' => "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"]);
        sse('log', ['type'=>'ok', 'msg' => "🤖 BulkReplace Bot™ AI Intelligence Engine"]);
        sse('log', ['type'=>'info', 'msg' => "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"]);
        echo "\n"; if (ob_get_level() > 0) ob_flush(); flush();

        if ($keywordHint) {
            sse('log', ['type'=>'info', 'msg' => "  💡 Keyword Hint: \"{$keywordHint}\" — Smart Mode Enabled"]);
            echo "\n"; if (ob_get_level() > 0) ob_flush(); flush();
        }
        sse('log', ['type'=>'info', 'msg' => "  📊 Preparing to analyze {$total} domains..."]);
        sse('log', ['type'=>'ok', 'msg' => "  ⚡ Calling AI API (batch processing enabled)..."]);
        if ($total > 50) {
            sse('log', ['type'=>'info', 'msg' => "  ⏳ Large batch detected - AI processing may take 30-90 seconds..."]);
            sse('log', ['type'=>'info', 'msg' => "  🔄 Keep this window open, connection will auto-reconnect if needed"]);
        }
        echo "\n"; if (ob_get_level() > 0) ob_flush(); flush();

        // Force immediate flush before AI call
        usleep(100000); // 100ms delay to ensure messages reach browser

        // Live per-domain progress callback
        $progressCb = function($current, $total_domains, $mode, $parsed = null) {
            if ($mode === 'chunk_start') {
                // Keepalive before processing next chunk
                echo ": chunk-processing\n\n"; if(ob_get_level()>0) ob_flush(); flush();
                return;
            }

            if ($mode === 'ai_domain' && $parsed) {
                // Log setiap domain yang selesai di-parse
                $inst = $parsed['institution'] ?? '—';
                $loc = $parsed['location_display'] ?? '—';
                sse('log', ['type'=>'info', 'msg' => "  🤖 [{$current}/{$total_domains}] {$parsed['full_domain']} → {$inst} · {$loc}"]);
            }
            // Keepalive to prevent timeout
            echo ": keepalive-ai\n\n"; if(ob_get_level()>0) ob_flush(); flush();
        };

        $parseStart = microtime(true);

        // Check connection before heavy AI processing
        if (connection_aborted()) {
            exit;
        }

        $aiParsed = $aiParser->parseBatch($domains, $keywordHint, $progressCb);
        $parseDuration = round((microtime(true) - $parseStart), 2);
        $aiCount  = count(array_filter($aiParsed, fn($p) => ($p['parse_source']??'') === 'ai'));

        sse('log', ['type'=>'ok', 'msg' => "  ✅ AI Intelligence: Successfully parsed {$aiCount}/{$total} domains"]);
        sse('log', ['type'=>'ok', 'msg' => "  🎯 Location detection: " . ($aiCount > 0 ? 'ACCURATE' : 'FALLBACK MODE')]);
    } else {
        sse('log', ['type'=>'info', 'msg' => '🔍 Regex Parser Mode — Activate OpenAI API key for AI intelligence']);
    }
    // ─────────────────────────────────────────────────────────────────────

    sse('log', ['type'=>'info', 'msg' => "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"]);
    sse('log', ['type'=>'ok', 'msg' => "⚡ PARALLEL SCRAPING ENABLED — 10x Faster!"]);
    sse('log', ['type'=>'info', 'msg' => "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"]);
    echo "\n"; if (ob_get_level() > 0) ob_flush(); flush();

    // Prepare parsed list for batch processing
    $parsedList = [];
    foreach ($domains as $i => $domain) {
        $_dkey   = strtolower(trim(preg_replace('#^https?://#','', $domain)));
        $parsed  = $aiParsed[$_dkey] ?? DataScraper::parseDomain($domain, $keywordHint);
        $keyword = $keyword ?: $parsed['keyword'];
        $parsedList[$i] = $parsed;
    }

    // Parallel batch scraping with live progress
    $scrapeStart = microtime(true);
    $scrapedCount = 0;

    $scrapeProgress = function($idx, $status) use (&$scrapedCount, $total, $parsedList) {
        $scrapedCount++;
        $parsed = $parsedList[$idx];
        $num = $idx + 1;

        if ($status === 'cache_hit') {
            sse('log', ['type'=>'ok', 'msg' => "⚡ [{$num}/{$total}] {$parsed['full_domain']} — CACHE HIT!"]);
        } else {
            $src_tag = isset($parsed['parse_source']) && $parsed['parse_source']==='ai' ? '🤖' : '🔍';
            sse('log', ['type'=>'info', 'msg' => "🔄 [{$num}/{$total}] {$src_tag} {$parsed['full_domain']} → {$parsed['institution']} · {$parsed['location_display']}"]);
        }

        sse('progress', ['done' => $scrapedCount, 'total' => $total, 'pct' => round($scrapedCount/$total*100)]);
        echo ": keepalive\n\n"; if(ob_get_level()>0) ob_flush(); flush();
    };

    $allData = $scraper->getDataBatch($parsedList, (bool)$forceRefresh, $scrapeProgress);
    $scrapeDuration = round((microtime(true) - $scrapeStart), 2);

    sse('log', ['type'=>'ok', 'msg' => "✅ Scraped {$total} domains in {$scrapeDuration}s — BLAZING FAST!"]);
    echo "\n"; if (ob_get_level() > 0) ob_flush(); flush();

    // Build rows from scraped data
    sse('log', ['type'=>'info', 'msg' => "📝 Building CSV file..."]);
    echo "\n"; if (ob_get_level() > 0) ob_flush(); flush();

    try {
    foreach ($parsedList as $i => $parsed) {
        $data = $allData[$i];


        // Build row — full professional fields
        $row = [];
        foreach ($fields as $field) {
            switch ($field) {
                // ── Core ──────────────────────────────────────────
                case 'namalink':        $row[] = $parsed['full_domain'];                           break;
                case 'namalinkurl':     $row[] = 'https://' . $parsed['full_domain'];              break;
                // ── Location ──────────────────────────────────────
                case 'daerah':         $row[] = $data['daerah']          ?? $parsed['location_display']; break;
                case 'daerahshort':    $row[] = $data['daerah_short']    ?? $parsed['location_display']; break;
                case 'provinsi':       $row[] = $data['provinsi']        ?? ($parsed['province'] ?? ''); break;
                case 'level':          $row[] = $data['level']           ?? ($parsed['location_level'] ?? ''); break;
                // ── Institution ───────────────────────────────────
                case 'namainstansi':   $row[] = $data['institution_full'] ?? ($parsed['institution_full'] ?? $parsed['institution'] ?? ''); break;
                case 'singkataninstansi': $row[] = $data['institution']  ?? ($parsed['institution'] ?? ''); break;
                // ── Contact ───────────────────────────────────────
                case 'email':          $row[] = $data['email']           ?? $parsed['email_domain'];  break;
                case 'notelp':         $row[] = phone_inc($phoneStart, $i);                            break;
                // ── Address ───────────────────────────────────────
                case 'alamat':         $row[] = $data['alamat']          ?? '';                        break;
                case 'kodepos': { $z=($data['kodepos']??''); if(!$z){$h2=crc32($parsed['location_slug']??'id');$pv=$parsed['province']??'';$zb=str_contains($pv,'Jakarta')?10000:(str_contains($pv,'Jawa Barat')?40000:(str_contains($pv,'Jawa Tengah')?50000:(str_contains($pv,'Jawa Timur')?60000:30000)));$z=$zb+abs($h2)%999;} $row[]=(string)$z; break; }
                // ── Maps ──────────────────────────────────────────
                case 'embedmap':       $row[] = $data['embedmap']        ?? '';                        break;
                case 'linkmaps':       $row[] = $data['linkmaps']        ?? '';                        break;
                // ── Meta ──────────────────────────────────────────
                case 'rating':         $row[] = $data['rating']          ?? '';                        break;
                case 'placename':      $row[] = $data['place_name']      ?? ($parsed['institution_full'] ?? $parsed['institution'] ?? ''); break;
                default:               $row[] = '';
            }
        }
        $rows[] = $row;
    }

    // Build headers (with custom header support)
    $headers = [];
    foreach ($fields as $field) {
        if (isset($customHeaders[$field]) && !empty($customHeaders[$field])) {
            $headers[] = $customHeaders[$field];
        } else {
            $sfx = $fieldSuffixes[$field] ?? $suffix;
            $headers[] = $field . $keyword . $sfx;
        }
    }

    // Build CSV (BOM + CRLF)
    $csvLines = [implode(',', array_map('cesc', $headers))];
    foreach ($rows as $row) $csvLines[] = implode(',', array_map('cesc', $row));
    $csv = "\xEF\xBB\xBF" . implode("\r\n", $csvLines);

    // Save history
    try {
        $pdo->prepare("INSERT INTO csv_gen_history (user_id,keyword,domains,fields,field_suffixes,phone_start,row_count,created_at) VALUES (?,?,?,?,?,?,?,NOW())")
            ->execute([$uid, $keyword, json_encode($domains), json_encode($fields),
                       json_encode($fieldSuffixes), $phoneStart, count($rows)]);
    } catch(Exception $e) {}

    // Track analytics (PERMANENT - never deleted by user)
    try {
        $jobToken = $token ?? bin2hex(random_bytes(16));
        $totalProcessingTime = isset($scrapeStart) ? round((microtime(true) - $scrapeStart) * 1000) : 0;
        $successCount = count($rows);
        $failedCount = $total - $successCount;
        $aiUsed = isset($aiParsed) && !empty($aiParsed);
        $googleUsed = defined('GOOGLE_PLACES_API_KEY') && GOOGLE_PLACES_API_KEY !== 'YOUR_GOOGLE_PLACES_API_KEY';

        $stmt = $pdo->prepare("INSERT INTO csv_gen_analytics
            (user_id, job_id, status, total_domains, success_count, failed_count, fields_used, keyword, processing_time_ms, ai_used, google_api_used, export_format, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
        $stmt->execute([
            $uid,
            $jobToken,
            $failedCount === 0 ? 'success' : ($successCount > 0 ? 'partial' : 'failed'),
            $total,
            $successCount,
            $failedCount,
            json_encode($fields),
            $keyword,
            $totalProcessingTime,
            $aiUsed ? 1 : 0,
            $googleUsed ? 1 : 0,
            'csv'
        ]);
        $analyticsId = $pdo->lastInsertId();

        // Track failed domains for retry feature
        if ($failedCount > 0 && isset($allData)) {
            $failedDomains = [];
            foreach ($parsedList as $i => $parsed) {
                $data = $allData[$i] ?? [];
                if (empty($data['institution_full']) && empty($data['email'])) {
                    $failedDomains[] = $parsed['full_domain'];
                }
            }
            foreach ($failedDomains as $failedDomain) {
                try {
                    $pdo->prepare("INSERT INTO csv_failed_jobs (user_id, analytics_id, domain, error_type, error_message, can_retry) VALUES (?,?,?,?,?,?)")
                        ->execute([$uid, $analyticsId, $failedDomain, 'scrape_failed', 'No data retrieved', 1]);
                } catch(Exception $e) {}
            }
        }
    } catch(Exception $e) {}

    // Track general analytics
    try {
        require_once dirname(__DIR__).'/includes/Analytics.php';
        $analytics = new Analytics($pdo);
        $analytics->trackEvent('csv_generated', 'feature_usage', $uid, [
            'domain_count' => count($domains),
            'field_count' => count($fields),
            'row_count' => count($rows),
            'keyword' => $keyword,
            'has_ai_parser' => class_exists('AIDomainParser'),
            'force_refresh' => (bool)$forceRefresh
        ]);
    } catch(Exception $e) {}

    // Log to usage_log for Recent Jobs tracking
    try {
        $pdo->prepare("INSERT INTO usage_log(user_id,csv_rows,files_updated,job_type,job_name,created_at)VALUES(?,?,?,?,?,NOW())")
            ->execute([$uid, count($rows), 0, 'csv_generator', 'CSV: ' . count($domains) . ' domains']);
    } catch(Exception $e) {}

    // Cleanup token
    try { $pdo->prepare("DELETE FROM csv_gen_queue WHERE token=?")->execute([$token]); } catch(Exception $e) {}

        sse('log',  ['type'=>'done', 'msg' => "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"]);
        sse('log',  ['type'=>'done', 'msg' => "✅ BulkReplace Bot™ — CSV Generation Complete!"]);
        sse('log',  ['type'=>'done', 'msg' => "  📊 Total Rows: {$total} | Fields: " . count($fields) . " | Keyword: {$keyword}"]);
        sse('log',  ['type'=>'done', 'msg' => "  🎯 AI Intelligence: " . ($aiCount ?? 0) . " domains analyzed"]);
        sse('log',  ['type'=>'done', 'msg' => "  💾 File Size: " . number_format(strlen($csv)) . " bytes"]);
        sse('log',  ['type'=>'done', 'msg' => "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"]);

        // Ensure all log messages are flushed
        echo "\n"; if (ob_get_level() > 0) ob_flush(); flush();
        usleep(100000); // 100ms delay to ensure messages reach browser

        // Send BOTH events to support both single-batch (done) and multi-batch (complete) flows
        sse('done', ['csv'=>$csv, 'headers'=>$headers, 'rows'=>count($rows), 'keyword'=>$keyword]);
        sse('complete', ['csv_data'=>$csv, 'headers'=>$headers, 'rows'=>count($rows), 'keyword'=>$keyword]);

        // Final flush
        echo "\n"; if (ob_get_level() > 0) ob_flush(); flush();

    } catch (Exception $e) {
        sse('error', ['msg' => 'CSV generation error: ' . $e->getMessage()]);
    }
    exit;
}

// ── JSON API ──────────────────────────────────────────────────────────────────
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

$pdo = db();
$uid = $_SESSION['uid'];
require_once __DIR__ . '/scraper.php';
ensureTables($pdo);

// ── GET ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'history') {
        try {
            $stmt = $pdo->prepare("SELECT id,keyword,domains,fields,phone_start,row_count,created_at FROM csv_gen_history WHERE user_id=? ORDER BY created_at DESC LIMIT 30");
            $stmt->execute([$uid]);
            echo json_encode(['ok'=>true,'history'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e) { echo json_encode(['ok'=>true,'history'=>[]]); }
        exit;
    }

    if ($action === 'cache_stats') {
        try {
            $scraper = new DataScraper($pdo);
            $s = $scraper->getCacheStats();
            require_once __DIR__ . '/ai_parser.php';
            $aiP = new AIDomainParser();
            $googleActive = defined('GOOGLE_PLACES_API_KEY') && GOOGLE_PLACES_API_KEY !== 'YOUR_GOOGLE_PLACES_API_KEY';
            echo json_encode([
                'ok'               => true,
                'cached_locations' => $s['locations'],
                'total_hits'       => $s['hits'],
                'ai_active'        => $aiP->isAvailable(),
                'google_active'    => $googleActive,
            ]);
        } catch(Exception $e) { echo json_encode(['ok'=>true,'cached_locations'=>0,'total_hits'=>0,'ai_active'=>false,'google_active'=>false]); }
        exit;
    }

    if ($action === 'analytics') {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*) as total_jobs,
                    SUM(total_domains) as total_domains_processed,
                    SUM(success_count) as total_success,
                    SUM(failed_count) as total_failed,
                    ROUND(AVG(success_count / total_domains * 100), 2) as avg_success_rate,
                    SUM(ai_used) as ai_usage_count,
                    SUM(google_api_used) as google_usage_count,
                    AVG(processing_time_ms) as avg_processing_time
                FROM csv_gen_analytics
                WHERE user_id = ?
            ");
            $stmt->execute([$uid]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt2 = $pdo->prepare("SELECT status, COUNT(*) as count FROM csv_gen_analytics WHERE user_id=? GROUP BY status");
            $stmt2->execute([$uid]);
            $statusBreakdown = [];
            while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                $statusBreakdown[$row['status']] = (int)$row['count'];
            }

            $stmt3 = $pdo->prepare("SELECT created_at, total_domains, success_count, failed_count, keyword, processing_time_ms FROM csv_gen_analytics WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
            $stmt3->execute([$uid]);
            $recentJobs = $stmt3->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'ok' => true,
                'stats' => $stats,
                'status_breakdown' => $statusBreakdown,
                'recent_jobs' => $recentJobs
            ]);
        } catch(Exception $e) {
            echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'failed_jobs') {
        try {
            $stmt = $pdo->prepare("
                SELECT f.domain, f.error_type, f.error_message, f.can_retry, f.retry_count, f.created_at
                FROM csv_failed_jobs f
                WHERE f.user_id = ? AND f.can_retry = 1
                ORDER BY f.created_at DESC
                LIMIT 100
            ");
            $stmt->execute([$uid]);
            echo json_encode(['ok'=>true, 'failed_jobs'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch(Exception $e) {
            echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
        }
        exit;
    }

    exit(json_encode(['ok'=>false,'msg'=>'Bad request']));
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Disable output buffering for POST requests to ensure clean JSON response
    while (ob_get_level()) ob_end_clean();

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    // Check cache batch
    if ($action === 'check_cache') {
        $scraper = new DataScraper($pdo);
        $domainsToCheck = $body['domains'] ?? [];

        // OPTIMIZATION: Skip AI parsing for large batches (>50) to prevent timeout
        // AI parsing will happen later in SSE stream with proper batching
        $aiParsedCheck = [];
        if (count($domainsToCheck) <= 50) {
            require_once __DIR__ . '/ai_parser.php';
            $aiP = new AIDomainParser();
            $aiParsedCheck = $aiP->isAvailable() ? $aiP->parseBatch($domainsToCheck) : [];
        }

        $parsed = array_map(function($d) use ($aiParsedCheck) {
            return $aiParsedCheck[strtolower(trim($d))] ?? DataScraper::parseDomain($d);
        }, $domainsToCheck);
        echo json_encode(['ok'=>true,'cache'=>$scraper->checkCacheBatch($parsed)]);
        exit;
    }

    // Queue job — stores full payload in DB, returns short token for SSE
    if ($action === 'queue_job') {
        // Ensure fast response for queue_job (just saves to DB, no heavy processing)
        set_time_limit(30);
        ini_set('max_execution_time', '30');

        try {
            $domains = $body['domains'] ?? [];
            if (empty($domains)) { echo json_encode(['ok'=>false,'msg'=>'No domains']); exit; }

        // Dedup on server side too (fast operation)
        $seen = []; $domains = array_values(array_filter($domains, function($d) use (&$seen) {
            $d = strtolower(trim($d)); if (isset($seen[$d])) return false; $seen[$d]=true; return true;
        }));

        $totalDomains = count($domains);
        $batchSize = 50; // Process 50 domains per batch (proven stable)

        // DEBUG LOG
        error_log("queue_job: Received $totalDomains domains");

        // AUTO-BATCH SYSTEM: Split large jobs into manageable batches
        if ($totalDomains > $batchSize) {
            error_log("queue_job: Auto-batching into chunks of $batchSize");
            try {
                ensureTables($pdo);

                // Create parent batch group
                $batchGroupId = bin2hex(random_bytes(12));
                $batches = array_chunk($domains, $batchSize);
                $batchCount = count($batches);

                // OPTIMIZATION: Prepare bulk insert for all batches (10x faster for large jobs)
                $tokens = [];
                $insertData = [];

                foreach ($batches as $i => $batchDomains) {
                    $token = bin2hex(random_bytes(16));
                    $payload = json_encode([
                        'domains'        => $batchDomains,
                        'fields'         => $body['fields']         ?? [],
                        'field_suffixes' => $body['field_suffixes'] ?? [],
                        'custom_headers' => $body['custom_headers'] ?? [],
                        'phone_start'    => $body['phone_start']    ?? '0811-0401-1110',
                        'force_refresh'  => $body['force_refresh']  ?? true,
                        'suffix'         => $body['suffix']         ?? '123',
                        'keyword_hint'   => trim($body['keyword_hint'] ?? ''),
                        'batch_info'     => [
                            'group_id'    => $batchGroupId,
                            'batch_num'   => $i + 1,
                            'batch_total' => $batchCount,
                            'batch_size'  => count($batchDomains),
                        ],
                    ]);

                    $tokens[] = $token;
                    $insertData[] = [$token, $uid, $payload];
                }

                // Bulk insert all batches at once (much faster than individual inserts)
                $placeholders = implode(',', array_fill(0, count($insertData), '(?,?,?,DATE_ADD(NOW(), INTERVAL 30 MINUTE))'));
                $values = [];
                foreach ($insertData as $row) {
                    $values = array_merge($values, $row);
                }
                $pdo->prepare("INSERT INTO csv_gen_queue (token, user_id, payload, expires_at) VALUES $placeholders")
                    ->execute($values);

                error_log("queue_job: Successfully created $batchCount batches");
                echo json_encode([
                    'ok' => true,
                    'batched' => true,
                    'batch_group_id' => $batchGroupId,
                    'tokens' => $tokens,
                    'count' => $totalDomains,
                    'batch_count' => $batchCount,
                    'batch_size' => $batchSize,
                ]);
            } catch(Exception $e) {
                error_log("queue_job: Batch creation failed: " . $e->getMessage());
                echo json_encode(['ok'=>false,'msg'=>'Batch queue error: '.$e->getMessage()]);
            }
            exit;
        }

        // SINGLE BATCH: Small job, process normally
        $token = bin2hex(random_bytes(16));
        $payload = json_encode([
            'domains'        => $domains,
            'fields'         => $body['fields']         ?? [],
            'field_suffixes' => $body['field_suffixes'] ?? [],
            'custom_headers' => $body['custom_headers'] ?? [],
            'phone_start'    => $body['phone_start']    ?? '0811-0401-1110',
            'force_refresh'  => $body['force_refresh']  ?? true,
            'suffix'         => $body['suffix']         ?? '123',
            'keyword_hint'   => trim($body['keyword_hint'] ?? ''),
        ]);

        try {
            ensureTables($pdo);
            $pdo->prepare("INSERT INTO csv_gen_queue (token, user_id, payload, expires_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 15 MINUTE))")
                ->execute([$token, $uid, $payload]);
            echo json_encode(['ok'=>true, 'token'=>$token, 'count'=>count($domains), 'batched'=>false]);
        } catch(Exception $e) {
            error_log('Queue job error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            echo json_encode(['ok'=>false,'msg'=>'Queue error: '.$e->getMessage(),'trace'=>$e->getFile().':'.$e->getLine()]);
        }

        } catch(Exception $e) {
            // Outer catch for entire queue_job handler
            error_log('Queue job fatal error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            echo json_encode(['ok'=>false,'msg'=>'Fatal queue error: '.$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Bad request']);
    exit;
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $action = $_GET['action'] ?? '';

    if ($action === 'clear_history') {
        try {
            $pdo->prepare("DELETE FROM csv_gen_history WHERE user_id=?")->execute([$uid]);
            echo json_encode(['ok'=>true, 'msg'=>'History cleared successfully']);
        } catch(Exception $e) {
            echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
        }
        exit;
    }

    if ($action === 'clear_failed') {
        try {
            $pdo->prepare("DELETE FROM csv_failed_jobs WHERE user_id=?")->execute([$uid]);
            echo json_encode(['ok'=>true, 'msg'=>'Failed jobs cleared successfully']);
        } catch(Exception $e) {
            echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Bad request']);
    exit;
}

// Global exception handler for any uncaught errors in POST
set_exception_handler(function($e) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'msg' => 'Server error: ' . $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]);
    }
});

// ── HELPERS ───────────────────────────────────────────────────────────────────
function ensureTables(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS csv_gen_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            keyword VARCHAR(100),
            domains JSON,
            fields JSON,
            field_suffixes JSON,
            phone_start VARCHAR(20),
            row_count INT DEFAULT 0,
            created_at DATETIME DEFAULT NOW(),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS csv_gen_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(64) NOT NULL,
            user_id INT NOT NULL,
            payload LONGTEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT NOW(),
            UNIQUE KEY uk_token (token),
            INDEX idx_user_exp (user_id, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS csv_gen_analytics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            job_id VARCHAR(64),
            status ENUM('success','failed','partial') DEFAULT 'success',
            total_domains INT DEFAULT 0,
            success_count INT DEFAULT 0,
            failed_count INT DEFAULT 0,
            fields_used JSON,
            keyword VARCHAR(255),
            processing_time_ms INT DEFAULT 0,
            ai_used BOOLEAN DEFAULT FALSE,
            google_api_used BOOLEAN DEFAULT FALSE,
            filters_applied JSON,
            export_format VARCHAR(20) DEFAULT 'csv',
            created_at DATETIME DEFAULT NOW(),
            INDEX idx_user_date (user_id, created_at),
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS csv_failed_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            analytics_id INT,
            domain VARCHAR(255) NOT NULL,
            error_type VARCHAR(50),
            error_message TEXT,
            can_retry BOOLEAN DEFAULT TRUE,
            retry_count INT DEFAULT 0,
            created_at DATETIME DEFAULT NOW(),
            INDEX idx_user_retry (user_id, can_retry),
            INDEX idx_analytics (analytics_id),
            FOREIGN KEY (analytics_id) REFERENCES csv_gen_analytics(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Cleanup expired tokens
        $pdo->exec("DELETE FROM csv_gen_queue WHERE expires_at < NOW()");
    } catch(Exception $e) {}
}

function cesc(string $v): string {
    // Normalize: strip carriage returns, trim
    $v = str_replace(["\r\n","\r"], ' ', $v);
    $v = trim($v);
    // Always quote if contains comma, quote, newline, semicolon, or leading/trailing space
    if ($v === '') return '""';
    if (strpos($v,',')!==false || strpos($v,'"')!==false ||
        strpos($v,"\n")!==false || strpos($v,';')!==false ||
        strpos($v,"\t")!==false) {
        return '"' . str_replace('"','""',$v) . '"';
    }
    return $v;
}
function phone_inc(string $base, int $idx): string {
    if (preg_match('/^(.*-)(\d+)$/', $base, $m)) return $m[1].((int)$m[2]+$idx*10);
    return $base;
}
