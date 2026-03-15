<?php
session_start();
set_time_limit(0);
require_once __DIR__.'/../config.php';
enforce_payload_limit(MAX_PAYLOAD_ZIP);
require_once __DIR__.'/../includes/EnhancedRateLimiter.php';
require_once __DIR__.'/../includes/SystemMonitor.php';
if(!isLoggedIn()){ http_response_code(401); echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit; }
ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M');

// Rate limiting with bypass for legitimate operations
$monitor = new SystemMonitor($pdo);
$rateLimiter = new EnhancedRateLimiter($pdo, $monitor);
$user = currentUser();
$userTier = $user['plan'] ?? 'free';
$userId = $user['id'] ?? null;
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// Bypass rate limiting for internal/background operations
$isCronJob = ($_SERVER['HTTP_X_CRON_KEY'] ?? '') === (defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : '');
$isInternalCall = isset($_SERVER['HTTP_X_INTERNAL_API']);

if (!$isCronJob && !$isInternalCall) {
    $rateLimitCheck = $rateLimiter->check($clientIp, 'zip_process', $userTier, $userId);
    $rateLimiter->setRateLimitHeaders($rateLimitCheck);

    if (!$rateLimitCheck['allowed']) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'msg' => $rateLimitCheck['message'] ?? 'Rate limit exceeded',
            'retry_after' => $rateLimitCheck['retry_after'] ?? 60
        ]);
        exit;
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// CSRF protection for all state-mutating POST actions
// Exempt read-only actions like 'check' and 'scan_zips' that don't modify files
$csrfExemptActions = ['check', 'scan_zips'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, $csrfExemptActions, true)) {
    if (!csrf_verify()) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'CSRF validation failed. Please refresh the page.']);
        exit;
    }
}

// ── CHECK ENVIRONMENT ──
if ($action === 'check') {
    header('Content-Type: application/json');
    $base = normPath($_POST['base'] ?? '');
    echo json_encode([
        'ok'          => true,
        'ziparchive'  => class_exists('ZipArchive'),
        'php_version' => PHP_VERSION,
        'max_exec'    => ini_get('max_execution_time'),
        'memory'      => ini_get('memory_limit'),
        'base_exists' => $base ? is_dir($base) : null,
        'base_readable'=> $base ? is_readable($base) : null,
        'os'          => PHP_OS,
    ]);
    exit;
}

// ── SCAN ZIPS (for UNZIP tab) ──
if ($action === 'scan_zips') {
    header('Content-Type: application/json');
    $src = normPath($_POST['src'] ?? '');
    if (!$src) { echo json_encode(['ok'=>false,'msg'=>'No path provided']); exit; }
    if (!is_dir($src)) { echo json_encode(['ok'=>false,'msg'=>"Folder not found: $src"]); exit; }

    $zips = [];
    $totalBytes = 0;
    foreach (new DirectoryIterator($src) as $item) {
        if ($item->isDot() || !$item->isFile()) continue;
        if (strtolower($item->getExtension()) !== 'zip') continue;
        $bytes = $item->getSize();
        $totalBytes += $bytes;
        $zips[] = ['name' => $item->getFilename(), 'size' => formatBytes($bytes)];
    }
    usort($zips, fn($a,$b) => strcmp($a['name'],$b['name']));
    echo json_encode(['ok'=>true,'zips'=>$zips,'total_size'=>formatBytes($totalBytes)]);
    exit;
}

// ── SCAN FOLDERS ──
if ($action === 'scan') {
    header('Content-Type: application/json');
    $base = normPath($_POST['base'] ?? '');
    if (!$base) { echo json_encode(['ok'=>false,'msg'=>'No path provided']); exit; }
    if (!is_dir($base)) { echo json_encode(['ok'=>false,'msg'=>"Folder not found: $base"]); exit; }

    $folders = [];
    $totalBytes = 0;

    foreach (new DirectoryIterator($base) as $item) {
        if ($item->isDot() || !$item->isDir()) continue;
        $path  = $item->getPathname();
        $name  = $item->getFilename();
        $count = countFiles($path);
        $bytes = dirSize($path);
        $totalBytes += $bytes;
        $folders[] = [
            'name'  => $name,
            'files' => $count,
            'size'  => formatBytes($bytes),
        ];
    }

    usort($folders, fn($a,$b) => strcmp($a['name'],$b['name']));
    echo json_encode(['ok'=>true,'folders'=>$folders,'total_size'=>formatBytes($totalBytes)]);
    exit;
}

// ── STORE JOB ──
if ($action === 'store') {
    header('Content-Type: application/json');
    $payload = json_decode($_POST['payload'] ?? '{}', true);
    if (!$payload) { echo json_encode(['ok'=>false,'msg'=>'Invalid payload']); exit; }
    $jobId = uniqid('zip_', true);
    $_SESSION[$jobId] = $payload;
    echo json_encode(['ok'=>true,'job_id'=>$jobId]);
    exit;
}

// ── RUN — SSE STREAMING ──
if ($action === 'run') {
    $jobId   = $_GET['job_id'] ?? '';
    $payload = $_SESSION[$jobId] ?? null;

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    if (ob_get_level()) { ob_end_clean(); }
    ob_implicit_flush(true);

    if (!$payload) {
        sse(['type'=>'error','msg'=>'Job not found or session expired. Please retry.']);
        exit;
    }

    // Check ZipArchive available
    if (!class_exists('ZipArchive')) {
        sse(['type'=>'error','msg'=>'PHP ZipArchive extension not enabled. Enable php_zip in php.ini']);
        exit;
    }

    $jobType = $payload['type'] ?? 'zip';

    // ── UNZIP JOB ──
    if ($jobType === 'unzip') {
        $src       = normPath($payload['src']       ?? '');
        $out       = normPath($payload['out']       ?? '');
        $subfolder = $payload['subfolder'] ?? true;
        $overwrite = $payload['overwrite'] ?? true;
        $deletezip = $payload['deletezip'] ?? false;
        $flat      = $payload['flat']      ?? false;

        if (!is_dir($src)) { sse(['type'=>'error','msg'=>"Source folder not found: $src"]); exit; }

        if (!is_dir($out)) {
            if (!mkdir($out, 0755, true)) { sse(['type'=>'error','msg'=>"Cannot create output: $out"]); exit; }
            sse(['type'=>'mkdir','path'=>$out]);
        }

        $startTime = microtime(true);

        // Collect all zip files
        $zips = [];
        foreach (new DirectoryIterator($src) as $item) {
            if ($item->isDot() || !$item->isFile()) continue;
            if (strtolower($item->getExtension()) !== 'zip') continue;
            $zips[] = ['name'=>$item->getFilename(), 'path'=>$item->getPathname(), 'base'=>$item->getBasename('.zip')];
        }
        usort($zips, fn($a,$b) => strcmp($a['name'],$b['name']));

        sse(['type'=>'total','count'=>count($zips),'out'=>$out]);

        $stats = ['ok'=>0,'skip'=>0,'err'=>0];
        $done  = 0;

        foreach ($zips as $zipInfo) {
            sse(['type'=>'start','name'=>$zipInfo['name'],'files'=>null]);

            $zipPath = $zipInfo['path'];
            $baseName = $zipInfo['base'];

            // Determine destination
            if ($flat) {
                $destDir = $out;
            } else if ($subfolder) {
                $destDir = $out . DIRECTORY_SEPARATOR . $baseName;
            } else {
                $destDir = $out;
            }

            // Create dest dir
            if (!is_dir($destDir)) {
                if (!mkdir($destDir, 0755, true)) {
                    $done++;
                    sse(['type'=>'err','error'=>"Cannot create dir: $destDir",'done'=>$done]);
                    $stats['err']++;
                    continue;
                }
            }

            $t   = microtime(true);
            $zip = new ZipArchive();
            $res = $zip->open($zipPath);

            if ($res !== true) {
                $done++;
                sse(['type'=>'err','error'=>"Cannot open zip (code $res)",'done'=>$done]);
                $stats['err']++;
                continue;
            }

            $fileCount = $zip->numFiles;

            // Extract — overwrite or not
            $extracted = true;
            if ($overwrite) {
                $extracted = $zip->extractTo($destDir);
            } else {
                // Extract only files that don't exist
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry    = $zip->getNameIndex($i);
                    $destFile = $destDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
                    if (!file_exists($destFile)) {
                        $zip->extractTo($destDir, [$entry]);
                    }
                }
            }

            $zip->close();
            $ms = round((microtime(true) - $t) * 1000);
            $done++;

            if ($extracted !== false) {
                $stats['ok']++;
                $deleted = false;
                if ($deletezip) {
                    @unlink($zipPath);
                    $deleted = true;
                }
                sse(['type'=>'ok','dest'=>$baseName,'files'=>$fileCount,'ms'=>$ms,'deleted'=>$deleted,'done'=>$done]);
            } else {
                $stats['err']++;
                sse(['type'=>'err','error'=>'extractTo() failed','done'=>$done]);
            }
        }

        $elapsed = round(microtime(true) - $startTime, 2);
        unset($_SESSION[$jobId]);
        sse(array_merge(['type'=>'done','elapsed'=>$elapsed], $stats));
        exit;
    }

    // ── ZIP JOB (existing) ──
    $base       = normPath($payload['base']       ?? '');
    $out        = normPath($payload['out']        ?? '');
    $mode       = $payload['mode']       ?? 'contents';
    $overwrite  = $payload['overwrite']  ?? true;
    $skipempty  = $payload['skipempty']  ?? true;
    $incHidden  = $payload['hidden']     ?? true;

    if (!is_dir($base)) {
        sse(['type'=>'error','msg'=>"Source folder not found: $base"]);
        exit;
    }

    // Create output dir
    if (!is_dir($out)) {
        if (!mkdir($out, 0755, true)) {
            sse(['type'=>'error','msg'=>"Cannot create output folder: $out"]);
            exit;
        }
        sse(['type'=>'mkdir','path'=>$out]);
    }

    $startTime = microtime(true);

    // Get top-level folders
    $folders = [];
    foreach (new DirectoryIterator($base) as $item) {
        if ($item->isDot() || !$item->isDir()) continue;
        $folders[] = ['name'=>$item->getFilename(), 'path'=>$item->getPathname()];
    }
    usort($folders, fn($a,$b) => strcmp($a['name'],$b['name']));

    sse(['type'=>'total','count'=>count($folders),'out'=>$out]);

    $stats = ['ok'=>0,'skip'=>0,'err'=>0];
    $done  = 0;

    foreach ($folders as $folder) {
        $name    = $folder['name'];
        $srcPath = $folder['path'];
        $zipFile = $out . DIRECTORY_SEPARATOR . $name . '.zip';

        $fileCount = countFiles($srcPath);
        sse(['type'=>'start','folder'=>$name,'files'=>$fileCount]);

        if ($skipempty && $fileCount === 0) {
            $done++;
            sse(['type'=>'skip','reason'=>'Empty folder','done'=>$done]);
            $stats['skip']++;
            continue;
        }

        if (file_exists($zipFile)) {
            if (!$overwrite) {
                $done++;
                sse(['type'=>'skip','reason'=>'ZIP already exists (overwrite off)','done'=>$done]);
                $stats['skip']++;
                continue;
            }
            @unlink($zipFile);
        }

        $t      = microtime(true);
        $result = zipFolder($srcPath, $zipFile, $mode, $incHidden);
        $ms     = round((microtime(true) - $t) * 1000);
        $done++;

        if ($result['ok']) {
            $stats['ok']++;
            $zipSize = file_exists($zipFile) ? filesize($zipFile) : 0;
            sse([
                'type'  => 'ok',
                'zip'   => $name.'.zip',
                'files' => $result['files'],
                'size'  => formatBytes($zipSize),
                'ms'    => $ms,
                'done'  => $done,
            ]);
        } else {
            $stats['err']++;
            sse(['type'=>'err','error'=>$result['error'],'done'=>$done]);
        }
    }

    $elapsed = round(microtime(true) - $startTime, 2);
    unset($_SESSION[$jobId]);
    sse(array_merge(['type'=>'done','elapsed'=>$elapsed], $stats));
    exit;
}

// ── HELPERS ──

function sse($data) {
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}

/**
 * Normalize path: convert slashes to OS separator, handle Windows drive letters
 * SECURITY: Validates path to prevent directory traversal attacks
 */
function normPath($p) {
    $p = trim($p);
    if (empty($p)) return '';

    // Decode URL encoding to catch encoded traversal like %2e%2e or %2F
    $decoded = urldecode($p);
    if (strpos($decoded, '..') !== false || strpos($p, '..') !== false) {
        error_log("SECURITY: Path traversal attempt detected: {$p}");
        http_response_code(400);
        sse(['type'=>'error','msg'=>'Invalid path: directory traversal not allowed']);
        exit;
    }

    // Block null bytes and other dangerous characters
    if (strpos($p, "\0") !== false || preg_match('/[<>"|?*]/', $p)) {
        error_log("SECURITY: Invalid characters in path: {$p}");
        http_response_code(400);
        sse(['type'=>'error','msg'=>'Invalid path: contains forbidden characters']);
        exit;
    }

    $p = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p);
    $p = rtrim($p, DIRECTORY_SEPARATOR);

    $allowedBasePaths = [
        '/home/bulkreplacetool.com/public_html',
        '/home/bulkreplacetool.com/templates',
        realpath(__DIR__ . '/..')
    ];

    // For existing paths, enforce realpath boundary check
    $realPath = realpath($p);
    if ($realPath !== false) {
        $isAllowed = false;
        foreach ($allowedBasePaths as $basePath) {
            if ($basePath && strpos($realPath . DIRECTORY_SEPARATOR, $basePath . DIRECTORY_SEPARATOR) === 0) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            error_log("SECURITY: Path outside allowed directories: {$realPath}");
            http_response_code(403);
            sse(['type'=>'error','msg'=>'Access denied: path outside allowed directories']);
            exit;
        }

        return $realPath;
    }

    // For non-existing paths (e.g. output dir to be created), verify parent is within allowed dirs
    $parent = dirname($p);
    $realParent = realpath($parent);
    if ($realParent !== false) {
        $isAllowed = false;
        foreach ($allowedBasePaths as $basePath) {
            if ($basePath && strpos($realParent . DIRECTORY_SEPARATOR, $basePath . DIRECTORY_SEPARATOR) === 0) {
                $isAllowed = true;
                break;
            }
        }
        if (!$isAllowed) {
            error_log("SECURITY: Parent path outside allowed directories: {$realParent}");
            http_response_code(403);
            sse(['type'=>'error','msg'=>'Access denied: path outside allowed directories']);
            exit;
        }
    }

    return $p;
}

/**
 * ZIP a folder — the CORRECT way:
 * We add files using RELATIVE paths from inside $srcPath
 * This mirrors 7-Zip's `Push-Location $folder; 7z a zip.zip *` approach
 * Guarantees no path corruption regardless of depth
 */
function zipFolder($srcPath, $destZip, $mode, $incHidden) {
    $zip = new ZipArchive();
    $res = $zip->open($destZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($res !== true) {
        return ['ok'=>false,'error'=>"ZipArchive::open failed (code $res)"];
    }

    $fileCount = 0;
    $errors    = [];

    try {
        // RecursiveIterator — handles nested folders and long paths
        $flags = RecursiveDirectoryIterator::SKIP_DOTS;
        if (!$incHidden) {
            // We'll filter manually below
        }

        $dirIter  = new RecursiveDirectoryIterator($srcPath, $flags);
        $iterator = new RecursiveIteratorIterator($dirIter, RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $fileInfo) {
            $realPath = $fileInfo->getPathname();
            $relPath  = substr($realPath, strlen($srcPath) + 1);

            // Normalize to forward slashes for zip compatibility
            $relPath = str_replace('\\', '/', $relPath);

            // Skip hidden files/folders if disabled
            if (!$incHidden) {
                $parts = explode('/', $relPath);
                $skip  = false;
                foreach ($parts as $part) {
                    if (strlen($part) > 0 && $part[0] === '.') { $skip = true; break; }
                }
                if ($skip) continue;
            }

            if ($mode === 'folder') {
                // Wrap with parent folder name
                $entryPath = basename($srcPath) . '/' . $relPath;
            } else {
                // Contents only — file directly at root
                $entryPath = $relPath;
            }

            if ($fileInfo->isDir()) {
                // Add directory entry (important for empty dirs)
                $zip->addEmptyDir($entryPath . '/');
            } elseif ($fileInfo->isFile()) {
                // Add file with local name (no absolute path leaked)
                if (!$zip->addFile($realPath, $entryPath)) {
                    $errors[] = "Failed to add: $relPath";
                } else {
                    $fileCount++;
                }
            }
        }
    } catch (Exception $e) {
        $zip->close();
        return ['ok'=>false,'error'=>$e->getMessage()];
    }

    $zip->close();

    if (!empty($errors) && $fileCount === 0) {
        return ['ok'=>false,'error'=>implode('; ', $errors)];
    }

    return ['ok'=>true,'files'=>$fileCount];
}

function countFiles($dir) {
    $count = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $f) { if ($f->isFile()) $count++; }
    } catch (Exception $e) {}
    return $count;
}

function dirSize($dir) {
    $size = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $f) { if ($f->isFile()) $size += $f->getSize(); }
    } catch (Exception $e) {}
    return $size;
}

function formatBytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes/1073741824,2).' GB';
    if ($bytes >= 1048576)    return round($bytes/1048576,2).' MB';
    if ($bytes >= 1024)       return round($bytes/1024,2).' KB';
    return $bytes.' B';
}
