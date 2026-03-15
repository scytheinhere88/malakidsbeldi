<?php
require_once __DIR__ . '/EnhancedRateLimiter.php';
require_once __DIR__ . '/SystemMonitor.php';

function checkApiRateLimit(string $endpoint, int $maxAttempts = 60, int $windowSeconds = 60): void {
    $pdo = db();
    $monitor = new SystemMonitor($pdo);
    $rateLimiter = new EnhancedRateLimiter($pdo, $monitor);

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userId = $_SESSION['uid'] ?? 0;

    // Get user tier for dynamic limits
    $userTier = 'free';
    if ($userId > 0) {
        try {
            $stmt = $pdo->prepare("SELECT plan FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            $userTier = $user['plan'] ?? 'free';
        } catch (Exception $e) {
            $userTier = 'free';
        }
    }

    // Bypass rate limiting for cron jobs and internal calls
    $isCronJob = ($_SERVER['HTTP_X_CRON_KEY'] ?? '') === (defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : '');
    $isInternalCall = isset($_SERVER['HTTP_X_INTERNAL_API']);

    if ($isCronJob || $isInternalCall) {
        return;
    }

    $rateCheck = $rateLimiter->check($ip, $endpoint, $userTier, $userId);
    $rateLimiter->setRateLimitHeaders($rateCheck);

    if (!$rateCheck['allowed']) {
        http_response_code(429);
        header('Content-Type: application/json');

        $message = $rateCheck['reason'] === 'ip_blocked'
            ? 'Too many requests. Your IP has been temporarily blocked.'
            : 'Rate limit exceeded. Please slow down.';

        echo json_encode([
            'ok' => false,
            'error' => $message,
            'retry_after' => $rateCheck['retry_after'] ?? 60,
            'attempts' => $rateCheck['attempts'] ?? 0,
            'tier' => $userTier
        ]);
        exit;
    }
}
