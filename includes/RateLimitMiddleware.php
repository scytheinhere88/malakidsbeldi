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

    // Use user ID as primary identifier (per-user limits) with IP as fallback.
    // This ensures each user gets their own quota bucket regardless of shared IPs (NAT/proxies).
    // Also enforce a secondary IP-based check to catch unauthenticated/misconfigured requests.
    $identifier = $userId > 0 ? 'user_' . $userId : 'ip_' . $ip;

    $rateCheck = $rateLimiter->check($identifier, $endpoint, $userTier, $userId);
    $rateLimiter->setRateLimitHeaders($rateCheck);

    // Secondary IP-level check — catches shared API keys and coordinated abuse
    if ($rateCheck['allowed'] && $userId > 0) {
        $ipCheck = $rateLimiter->check('ip_' . $ip, $endpoint . '_ip', 'free', null);
        if (!$ipCheck['allowed']) {
            http_response_code(429);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'error' => 'Too many requests from your IP address. Please slow down.',
                'retry_after' => $ipCheck['retry_after'] ?? 60,
            ]);
            exit;
        }
    }

    if (!$rateCheck['allowed']) {
        http_response_code(429);
        header('Content-Type: application/json');

        if ($rateCheck['reason'] === 'ip_blocked') {
            $message = 'Too many requests. Your IP has been temporarily blocked.';
        } elseif ($rateCheck['reason'] === 'rate_limit_exceeded') {
            $limits = EnhancedRateLimiter::TIER_LIMITS[$userTier] ?? EnhancedRateLimiter::TIER_LIMITS['free'];
            $category = strpos($endpoint, 'csv') !== false ? 'api_csv' : (strpos($endpoint, 'zip') !== false ? 'api_zip' : 'default');
            $limit = $limits[$category]['limit'] ?? $limits['default']['limit'];
            $window = $limits[$category]['window'] ?? $limits['default']['window'];
            $message = "Rate limit exceeded for your plan ({$userTier}): {$limit} requests per {$window}s.";
        } else {
            $message = 'Rate limit exceeded. Please slow down.';
        }

        echo json_encode([
            'ok' => false,
            'error' => $message,
            'retry_after' => $rateCheck['retry_after'] ?? 60,
            'attempts' => $rateCheck['attempts'] ?? 0,
            'plan' => $userTier,
            'limit' => $rateCheck['max_attempts'] ?? null,
        ]);
        exit;
    }
}
