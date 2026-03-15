<?php

class MonitoringMiddleware {
    private PDO $db;
    private SystemMonitor $monitor;
    private EnhancedRateLimiter $rateLimiter;
    private AlertManager $alertManager;
    private float $startTime;
    private int $requestSize;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->monitor = SystemMonitor::getInstance($db);
        $this->alertManager = AlertManager::getInstance($db, $this->monitor);
        $this->rateLimiter = new EnhancedRateLimiter($db, $this->monitor);
        $this->startTime = microtime(true);
        $this->requestSize = strlen(file_get_contents('php://input'));
    }

    // ============================================
    // REQUEST TRACKING
    // ============================================

    public function trackRequest(string $endpoint, string $method = 'GET', ?int $userId = null): void {
        // This is called at the start of a request
        // Store in session for later use
        $_SESSION['_monitoring_start'] = $this->startTime;
        $_SESSION['_monitoring_endpoint'] = $endpoint;
        $_SESSION['_monitoring_method'] = $method;
        $_SESSION['_monitoring_user_id'] = $userId;
        $_SESSION['_monitoring_request_size'] = $this->requestSize;
    }

    public function finishRequest(int $statusCode = 200, ?string $errorMessage = null): void {
        $endTime = microtime(true);
        $startTime = $_SESSION['_monitoring_start'] ?? $endTime;
        $responseTimeMs = (int)(($endTime - $startTime) * 1000);

        $endpoint = $_SESSION['_monitoring_endpoint'] ?? 'unknown';
        $method = $_SESSION['_monitoring_method'] ?? 'GET';
        $userId = $_SESSION['_monitoring_user_id'] ?? null;
        $requestSize = $_SESSION['_monitoring_request_size'] ?? 0;

        // Calculate response size
        $responseSize = ob_get_length() ?: 0;

        // Log API call
        $this->monitor->logApiCall([
            'endpoint' => $endpoint,
            'method' => $method,
            'user_id' => $userId,
            'ip_address' => $this->getClientIP(),
            'response_time_ms' => $responseTimeMs,
            'status_code' => $statusCode,
            'error_message' => $errorMessage,
            'request_size' => $requestSize,
            'response_size' => $responseSize,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

        // Log error if status code indicates failure
        if ($statusCode >= 500) {
            $this->monitor->logError([
                'error_type' => 'http_error',
                'error_message' => $errorMessage ?? "HTTP {$statusCode} error",
                'severity' => 'high',
                'user_id' => $userId,
                'ip_address' => $this->getClientIP(),
                'context' => [
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'status_code' => $statusCode,
                    'response_time_ms' => $responseTimeMs
                ]
            ]);
        }

        // Clean up session
        unset($_SESSION['_monitoring_start'], $_SESSION['_monitoring_endpoint'],
              $_SESSION['_monitoring_method'], $_SESSION['_monitoring_user_id'],
              $_SESSION['_monitoring_request_size']);
    }

    // ============================================
    // RATE LIMITING
    // ============================================

    public function checkRateLimit(string $endpoint, string $tier = 'free', ?int $userId = null): array {
        $identifier = $this->getRateLimitIdentifier($userId);
        $result = $this->rateLimiter->check($identifier, $endpoint, $tier, $userId);

        // Set rate limit headers
        $this->rateLimiter->setRateLimitHeaders($result);

        return $result;
    }

    public function enforceRateLimit(string $endpoint, string $tier = 'free', ?int $userId = null): void {
        $result = $this->checkRateLimit($endpoint, $tier, $userId);

        if (!$result['allowed']) {
            http_response_code(429); // Too Many Requests
            header('Content-Type: application/json');

            echo json_encode([
                'error' => 'Rate limit exceeded',
                'message' => $result['message'] ?? 'Too many requests. Please try again later.',
                'retry_after' => $result['retry_after'] ?? 60,
                'tier' => $tier,
                'limit' => $result['max_attempts'] ?? 0
            ]);

            exit;
        }
    }

    private function getRateLimitIdentifier(?int $userId): string {
        // Use user ID if available, otherwise use IP
        if ($userId) {
            return "user_{$userId}";
        }
        return $this->getClientIP();
    }

    // ============================================
    // ERROR HANDLING
    // ============================================

    public function handleError(Throwable $e, string $severity = 'medium', ?int $userId = null): void {
        $this->monitor->logError([
            'error_type' => get_class($e),
            'error_message' => $e->getMessage(),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine(),
            'stack_trace' => $e->getTraceAsString(),
            'severity' => $severity,
            'user_id' => $userId,
            'ip_address' => $this->getClientIP(),
            'context' => [
                'endpoint' => $_SESSION['_monitoring_endpoint'] ?? 'unknown',
                'method' => $_SESSION['_monitoring_method'] ?? 'unknown',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]
        ]);

        // Create alert for critical errors
        if ($severity === 'critical' || $severity === 'high') {
            $this->alertManager->createAlert(
                'application_error',
                $severity === 'critical' ? 'critical' : 'warning',
                "Application error: " . $e->getMessage(),
                null,
                null,
                [
                    'error_type' => get_class($e),
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine()
                ]
            );
        }
    }

    // ============================================
    // PAYMENT FAILURE TRACKING
    // ============================================

    public function logPaymentFailure(int $userId, string $gateway, array $details): void {
        $this->monitor->logPaymentFailure([
            'user_id' => $userId,
            'payment_gateway' => $gateway,
            'order_id' => $details['order_id'] ?? null,
            'error_message' => $details['error_message'] ?? null,
            'amount' => $details['amount'] ?? null,
            'currency' => $details['currency'] ?? 'USD',
            'metadata' => $details
        ]);

        // Create alert if too many failures
        $failures = $this->monitor->getPaymentFailures(1);
        $totalFailures = array_sum(array_column($failures, 'gateway_failures'));

        if ($totalFailures >= 5) {
            $this->alertManager->createAlert(
                'payment_failures',
                $totalFailures >= 10 ? 'critical' : 'warning',
                "Payment failures detected: {$totalFailures} in the last hour",
                $totalFailures,
                5
            );
        }
    }

    // ============================================
    // AUTOMATIC MONITORING
    // ============================================

    public function runHealthChecks(): array {
        // This should be called periodically (e.g., via cron)
        return $this->alertManager->checkAllThresholds();
    }

    // ============================================
    // UTILITIES
    // ============================================

    private function getClientIP(): string {
        // Check for IP in various headers (for proxies/load balancers)
        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Standard proxy header
            'HTTP_X_REAL_IP',            // Nginx proxy
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'                // Fallback
        ];

        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated IPs (take first one)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    public function getUserTier(?int $userId): string {
        if (!$userId) {
            return 'free';
        }

        $stmt = $this->db->prepare("SELECT plan FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row['plan'] ?? 'free';
    }

    // ============================================
    // WRAPPER FUNCTIONS FOR EASY INTEGRATION
    // ============================================

    public static function start(PDO $db, string $endpoint, ?int $userId = null): self {
        $middleware = new self($db);
        $middleware->trackRequest($endpoint, $_SERVER['REQUEST_METHOD'] ?? 'GET', $userId);
        return $middleware;
    }

    public function end(int $statusCode = 200, ?string $errorMessage = null): void {
        $this->finishRequest($statusCode, $errorMessage);
    }

    public function withRateLimit(string $endpoint, ?int $userId = null): self {
        $tier = $this->getUserTier($userId);
        $this->enforceRateLimit($endpoint, $tier, $userId);
        return $this;
    }
}
