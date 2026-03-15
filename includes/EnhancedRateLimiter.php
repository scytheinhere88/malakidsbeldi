<?php

class EnhancedRateLimiter {
    private PDO $db;
    private SystemMonitor $monitor;

    // Tier-based limits (requests per window)
    const TIER_LIMITS = [
        'free' => [
            'default' => ['limit' => 60, 'window' => 60],      // 60 req/min
            'api_scraper' => ['limit' => 10, 'window' => 60],   // 10 req/min for heavy endpoints
            'api_csv' => ['limit' => 5, 'window' => 60],        // 5 req/min
            'api_zip' => ['limit' => 5, 'window' => 60],        // 5 req/min
        ],
        'pro' => [
            'default' => ['limit' => 300, 'window' => 60],      // 300 req/min
            'api_scraper' => ['limit' => 50, 'window' => 60],   // 50 req/min
            'api_csv' => ['limit' => 30, 'window' => 60],       // 30 req/min
            'api_zip' => ['limit' => 30, 'window' => 60],       // 30 req/min
        ],
        'platinum' => [
            'default' => ['limit' => 600, 'window' => 60],      // 600 req/min
            'api_scraper' => ['limit' => 100, 'window' => 60],  // 100 req/min
            'api_csv' => ['limit' => 60, 'window' => 60],       // 60 req/min
            'api_zip' => ['limit' => 60, 'window' => 60],       // 60 req/min
        ],
        'lifetime' => [
            'default' => ['limit' => 1000, 'window' => 60],     // 1000 req/min
            'api_scraper' => ['limit' => 200, 'window' => 60],  // 200 req/min
            'api_csv' => ['limit' => 100, 'window' => 60],      // 100 req/min
            'api_zip' => ['limit' => 100, 'window' => 60],      // 100 req/min
        ],
    ];

    // Burst allowance (extra requests allowed in short bursts)
    const BURST_ALLOWANCE = 1.5; // 50% extra for bursts

    // IP-based blocking thresholds
    const SUSPICIOUS_ACTIVITY_THRESHOLD = 1000; // requests per hour
    const AUTO_BLOCK_DURATION = 3600; // 1 hour

    public function __construct(PDO $db, SystemMonitor $monitor) {
        $this->db = $db;
        $this->monitor = $monitor;
        $this->ensureTables();
    }

    private function ensureTables(): void {
        $this->db->exec("CREATE TABLE IF NOT EXISTS enhanced_rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(255) NOT NULL,
            endpoint VARCHAR(100) NOT NULL,
            tier VARCHAR(20) NOT NULL DEFAULT 'free',
            attempts INT DEFAULT 1,
            burst_used INT DEFAULT 0,
            window_start DATETIME NOT NULL,
            last_attempt DATETIME NOT NULL,
            blocked_until DATETIME NULL,
            block_reason VARCHAR(255),
            INDEX idx_identifier_endpoint (identifier, endpoint),
            INDEX idx_blocked (blocked_until),
            INDEX idx_tier_endpoint (tier, endpoint)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->exec("CREATE TABLE IF NOT EXISTS ip_blocks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            blocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            blocked_until DATETIME NOT NULL,
            block_type ENUM('manual', 'automatic') DEFAULT 'automatic',
            unblocked_at DATETIME NULL,
            INDEX idx_ip_blocked (ip_address, blocked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ============================================
    // RATE LIMITING WITH TIERS
    // ============================================

    public function check(string $identifier, string $endpoint, string $tier = 'free', ?int $userId = null): array {
        $this->cleanup();
        $this->checkSuspiciousActivity($identifier);

        // Check if IP is blocked
        $blockCheck = $this->isBlocked($identifier);
        if ($blockCheck['blocked']) {
            return [
                'allowed' => false,
                'reason' => 'ip_blocked',
                'message' => $blockCheck['reason'],
                'retry_after' => $blockCheck['retry_after'],
                'headers' => $this->generateHeaders(0, 0, $blockCheck['retry_after'])
            ];
        }

        // Get tier limits
        $limits = $this->getTierLimits($tier, $endpoint);
        $maxAttempts = $limits['limit'];
        $windowSeconds = $limits['window'];
        $burstLimit = (int)($maxAttempts * self::BURST_ALLOWANCE);

        // Get current rate limit record
        $stmt = $this->db->prepare("
            SELECT * FROM enhanced_rate_limits
            WHERE identifier = ? AND endpoint = ?
            AND window_start > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$identifier, $endpoint, $windowSeconds]);
        $row = $stmt->fetch();

        // Check if blocked
        if ($row && $row['blocked_until'] && strtotime($row['blocked_until']) > time()) {
            $remainingSeconds = strtotime($row['blocked_until']) - time();
            return [
                'allowed' => false,
                'reason' => 'rate_limit_exceeded',
                'message' => $row['block_reason'] ?? 'Rate limit exceeded',
                'retry_after' => $remainingSeconds,
                'attempts' => (int)$row['attempts'],
                'headers' => $this->generateHeaders(0, $maxAttempts, $remainingSeconds)
            ];
        }

        // New window
        if (!$row || strtotime($row['window_start']) < (time() - $windowSeconds)) {
            $this->db->prepare("
                INSERT INTO enhanced_rate_limits (identifier, endpoint, tier, attempts, burst_used, window_start, last_attempt)
                VALUES (?, ?, ?, 1, 0, NOW(), NOW())
            ")->execute([$identifier, $endpoint, $tier]);

            $remaining = $maxAttempts - 1;
            return [
                'allowed' => true,
                'attempts' => 1,
                'max_attempts' => $maxAttempts,
                'remaining' => $remaining,
                'window_seconds' => $windowSeconds,
                'tier' => $tier,
                'headers' => $this->generateHeaders($remaining, $maxAttempts, $windowSeconds)
            ];
        }

        // Within window
        $attempts = (int)$row['attempts'] + 1;
        $burstUsed = (int)$row['burst_used'];

        // Check if exceeded limit
        if ($attempts > $maxAttempts) {
            // Check if burst allowance available
            if ($attempts <= $burstLimit) {
                $burstUsed++;
                $this->db->prepare("
                    UPDATE enhanced_rate_limits
                    SET attempts = ?, burst_used = ?, last_attempt = NOW()
                    WHERE id = ?
                ")->execute([$attempts, $burstUsed, $row['id']]);

                $remaining = $burstLimit - $attempts;
                return [
                    'allowed' => true,
                    'attempts' => $attempts,
                    'max_attempts' => $maxAttempts,
                    'remaining' => $remaining,
                    'burst_used' => $burstUsed,
                    'using_burst' => true,
                    'tier' => $tier,
                    'headers' => $this->generateHeaders($remaining, $burstLimit, $windowSeconds)
                ];
            }

            // Exceeded burst limit, block
            $blockDuration = $this->calculateBlockDuration($attempts, $maxAttempts);
            $blockReason = "Rate limit exceeded for tier '{$tier}': {$attempts}/{$maxAttempts} requests in {$windowSeconds}s";

            $this->db->prepare("
                UPDATE enhanced_rate_limits
                SET attempts = ?, last_attempt = NOW(), blocked_until = DATE_ADD(NOW(), INTERVAL ? SECOND), block_reason = ?
                WHERE id = ?
            ")->execute([$attempts, $blockDuration, $blockReason, $row['id']]);

            // Log to monitoring
            $this->monitor->logError([
                'error_type' => 'rate_limit_exceeded',
                'error_message' => $blockReason,
                'severity' => 'medium',
                'user_id' => $userId,
                'ip_address' => $identifier,
                'context' => [
                    'endpoint' => $endpoint,
                    'tier' => $tier,
                    'attempts' => $attempts,
                    'limit' => $maxAttempts
                ]
            ]);

            return [
                'allowed' => false,
                'reason' => 'rate_limit_exceeded',
                'message' => $blockReason,
                'retry_after' => $blockDuration,
                'attempts' => $attempts,
                'headers' => $this->generateHeaders(0, $maxAttempts, $blockDuration)
            ];
        }

        // Update attempts
        $this->db->prepare("
            UPDATE enhanced_rate_limits SET attempts = ?, last_attempt = NOW() WHERE id = ?
        ")->execute([$attempts, $row['id']]);

        $remaining = $maxAttempts - $attempts;
        return [
            'allowed' => true,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'remaining' => $remaining,
            'window_seconds' => $windowSeconds,
            'tier' => $tier,
            'headers' => $this->generateHeaders($remaining, $maxAttempts, $windowSeconds)
        ];
    }

    // ============================================
    // TIER LIMITS
    // ============================================

    private function getTierLimits(string $tier, string $endpoint): array {
        // Normalize endpoint to category
        $category = $this->categorizeEndpoint($endpoint);

        // Get tier limits
        $tierLimits = self::TIER_LIMITS[$tier] ?? self::TIER_LIMITS['free'];

        // Return specific category limits or default
        return $tierLimits[$category] ?? $tierLimits['default'];
    }

    private function categorizeEndpoint(string $endpoint): string {
        if (strpos($endpoint, 'scraper') !== false) return 'api_scraper';
        if (strpos($endpoint, 'csv') !== false) return 'api_csv';
        if (strpos($endpoint, 'zip') !== false) return 'api_zip';
        return 'default';
    }

    // ============================================
    // RATE LIMIT HEADERS (RFC 6585)
    // ============================================

    private function generateHeaders(int $remaining, int $limit, int $reset): array {
        return [
            'X-RateLimit-Limit' => (string)$limit,
            'X-RateLimit-Remaining' => (string)max(0, $remaining),
            'X-RateLimit-Reset' => (string)(time() + $reset),
            'Retry-After' => (string)$reset
        ];
    }

    public function setRateLimitHeaders(array $result): void {
        if (isset($result['headers'])) {
            foreach ($result['headers'] as $header => $value) {
                header("{$header}: {$value}");
            }
        }
    }

    // ============================================
    // IP BLOCKING
    // ============================================

    public function blockIP(string $ip, string $reason, int $duration = 3600, string $type = 'manual'): void {
        $this->db->prepare("
            INSERT INTO ip_blocks (ip_address, reason, blocked_until, block_type)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)
        ")->execute([$ip, $reason, $duration, $type]);

        // Log to monitoring
        $this->monitor->logError([
            'error_type' => 'ip_blocked',
            'error_message' => "IP blocked: {$ip} - {$reason}",
            'severity' => 'high',
            'ip_address' => $ip,
            'context' => ['reason' => $reason, 'duration' => $duration, 'type' => $type]
        ]);
    }

    public function unblockIP(string $ip): void {
        $this->db->prepare("
            UPDATE ip_blocks
            SET unblocked_at = NOW()
            WHERE ip_address = ? AND unblocked_at IS NULL
        ")->execute([$ip]);
    }

    public function isBlocked(string $ip): array {
        $stmt = $this->db->prepare("
            SELECT * FROM ip_blocks
            WHERE ip_address = ?
            AND blocked_until > NOW()
            AND unblocked_at IS NULL
            ORDER BY blocked_at DESC
            LIMIT 1
        ");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();

        if ($row) {
            $retryAfter = strtotime($row['blocked_until']) - time();
            return [
                'blocked' => true,
                'reason' => $row['reason'],
                'retry_after' => $retryAfter,
                'blocked_at' => $row['blocked_at'],
                'type' => $row['block_type']
            ];
        }

        return ['blocked' => false];
    }

    // ============================================
    // SUSPICIOUS ACTIVITY DETECTION
    // ============================================

    private function checkSuspiciousActivity(string $ip): void {
        // Count requests from this IP in last hour
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as request_count
            FROM enhanced_rate_limits
            WHERE identifier = ?
            AND window_start > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        $requestCount = (int)$row['request_count'];

        if ($requestCount >= self::SUSPICIOUS_ACTIVITY_THRESHOLD) {
            // Auto-block suspicious IP
            $this->blockIP(
                $ip,
                "Suspicious activity detected: {$requestCount} requests in 1 hour",
                self::AUTO_BLOCK_DURATION,
                'automatic'
            );

            // Create alert
            if (class_exists('AlertManager')) {
                $alertManager = AlertManager::getInstance($this->db, $this->monitor);
                $alertManager->createAlert(
                    'suspicious_activity',
                    'warning',
                    "IP {$ip} auto-blocked for suspicious activity: {$requestCount} requests/hour",
                    $requestCount,
                    self::SUSPICIOUS_ACTIVITY_THRESHOLD,
                    ['ip' => $ip, 'requests' => $requestCount]
                );
            }
        }
    }

    // ============================================
    // BLOCK DURATION CALCULATION
    // ============================================

    private function calculateBlockDuration(int $attempts, int $limit): int {
        // Progressive blocking
        $overLimit = $attempts - $limit;

        if ($overLimit <= 10) return 60;        // 1 minute
        if ($overLimit <= 50) return 300;       // 5 minutes
        if ($overLimit <= 100) return 900;      // 15 minutes
        if ($overLimit <= 500) return 3600;     // 1 hour
        return 7200;                             // 2 hours
    }

    // ============================================
    // STATISTICS
    // ============================================

    public function getStats(string $tier = null, int $hours = 24): array {
        $sql = "SELECT
            COUNT(DISTINCT identifier) as unique_ips,
            COUNT(*) as total_windows,
            SUM(attempts) as total_attempts,
            AVG(attempts) as avg_attempts_per_window,
            SUM(burst_used) as total_burst_used,
            COUNT(CASE WHEN blocked_until > NOW() THEN 1 END) as currently_blocked
        FROM enhanced_rate_limits
        WHERE window_start > DATE_SUB(NOW(), INTERVAL ? HOUR)";

        $params = [$hours];

        if ($tier) {
            $sql .= " AND tier = ?";
            $params[] = $tier;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: [];
    }

    public function getBlockedIPs(): array {
        $stmt = $this->db->query("
            SELECT *
            FROM ip_blocks
            WHERE blocked_until > NOW()
            AND unblocked_at IS NULL
            ORDER BY blocked_at DESC
        ");
        return $stmt->fetchAll();
    }

    // ============================================
    // CLEANUP
    // ============================================

    private function cleanup(): void {
        if (rand(0, 100) > 95) {
            // Cleanup old rate limit records
            $this->db->exec("
                DELETE FROM enhanced_rate_limits
                WHERE window_start < DATE_SUB(NOW(), INTERVAL 24 HOUR)
                AND (blocked_until IS NULL OR blocked_until < NOW())
            ");

            // Cleanup old block records
            $this->db->exec("
                DELETE FROM ip_blocks
                WHERE blocked_until < DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
        }
    }

    public function reset(string $identifier, string $endpoint = null): void {
        if ($endpoint) {
            $this->db->prepare("
                DELETE FROM enhanced_rate_limits WHERE identifier = ? AND endpoint = ?
            ")->execute([$identifier, $endpoint]);
        } else {
            $this->db->prepare("
                DELETE FROM enhanced_rate_limits WHERE identifier = ?
            ")->execute([$identifier]);
        }
    }
}
