<?php

class RateLimiter {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->ensureTable();
    }

    private function ensureTable(): void {
        $this->db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(255) NOT NULL,
            endpoint VARCHAR(100) NOT NULL,
            attempts INT DEFAULT 1,
            window_start DATETIME NOT NULL,
            last_attempt DATETIME NOT NULL,
            blocked_until DATETIME NULL,
            INDEX idx_identifier_endpoint (identifier, endpoint),
            INDEX idx_blocked (blocked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function check(string $identifier, string $endpoint, int $maxAttempts, int $windowSeconds): array {
        $this->cleanup();

        $stmt = $this->db->prepare("
            SELECT * FROM rate_limits
            WHERE identifier = ? AND endpoint = ?
            AND window_start > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$identifier, $endpoint, $windowSeconds]);
        $row = $stmt->fetch();

        if ($row && $row['blocked_until'] && strtotime($row['blocked_until']) > time()) {
            $remainingSeconds = strtotime($row['blocked_until']) - time();
            return [
                'allowed' => false,
                'reason' => 'blocked',
                'retry_after' => $remainingSeconds,
                'attempts' => (int)$row['attempts']
            ];
        }

        if (!$row || strtotime($row['window_start']) < (time() - $windowSeconds)) {
            $this->db->prepare("
                INSERT INTO rate_limits (identifier, endpoint, attempts, window_start, last_attempt)
                VALUES (?, ?, 1, NOW(), NOW())
            ")->execute([$identifier, $endpoint]);

            return [
                'allowed' => true,
                'attempts' => 1,
                'max_attempts' => $maxAttempts,
                'window_seconds' => $windowSeconds
            ];
        }

        $attempts = (int)$row['attempts'] + 1;

        if ($attempts > $maxAttempts) {
            $blockDuration = min(3600, $windowSeconds * 2);
            $this->db->prepare("
                UPDATE rate_limits
                SET attempts = ?, last_attempt = NOW(), blocked_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
                WHERE id = ?
            ")->execute([$attempts, $blockDuration, $row['id']]);

            return [
                'allowed' => false,
                'reason' => 'rate_limit_exceeded',
                'retry_after' => $blockDuration,
                'attempts' => $attempts
            ];
        }

        $this->db->prepare("
            UPDATE rate_limits SET attempts = ?, last_attempt = NOW() WHERE id = ?
        ")->execute([$attempts, $row['id']]);

        return [
            'allowed' => true,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'window_seconds' => $windowSeconds,
            'remaining' => $maxAttempts - $attempts
        ];
    }

    public function block(string $identifier, string $endpoint, int $seconds): void {
        $stmt = $this->db->prepare("
            SELECT id FROM rate_limits
            WHERE identifier = ? AND endpoint = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$identifier, $endpoint]);
        $row = $stmt->fetch();

        if ($row) {
            $this->db->prepare("
                UPDATE rate_limits
                SET blocked_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
                WHERE id = ?
            ")->execute([$seconds, $row['id']]);
        } else {
            $this->db->prepare("
                INSERT INTO rate_limits (identifier, endpoint, attempts, window_start, last_attempt, blocked_until)
                VALUES (?, ?, 999, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))
            ")->execute([$identifier, $endpoint, $seconds]);
        }
    }

    public function reset(string $identifier, string $endpoint): void {
        $this->db->prepare("
            DELETE FROM rate_limits WHERE identifier = ? AND endpoint = ?
        ")->execute([$identifier, $endpoint]);
    }

    public function setRateLimitHeaders(array $result): void {
        if (headers_sent()) return;
        $limit     = $result['max_attempts'] ?? null;
        $remaining = $result['remaining'] ?? ($result['allowed'] ? ($limit - ($result['attempts'] ?? 0)) : 0);
        $retryAfter = $result['retry_after'] ?? null;

        if ($limit !== null) {
            header('X-RateLimit-Limit: ' . (int)$limit);
        }
        header('X-RateLimit-Remaining: ' . max(0, (int)$remaining));
        if (!$result['allowed'] && $retryAfter !== null) {
            header('Retry-After: ' . (int)$retryAfter);
            header('X-RateLimit-Reset: ' . (time() + (int)$retryAfter));
        }
    }

    private function cleanup(): void {
    }

    public function purgeExpired(): int {
        $stmt = $this->db->exec("
            DELETE FROM rate_limits
            WHERE window_start < DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND (blocked_until IS NULL OR blocked_until < NOW())
        ");
        return (int)$stmt;
    }

    public function getStats(string $endpoint = null): array {
        if ($endpoint) {
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(DISTINCT identifier) as unique_ips,
                    SUM(attempts) as total_attempts,
                    COUNT(*) FILTER (WHERE blocked_until > NOW()) as currently_blocked
                FROM rate_limits
                WHERE endpoint = ? AND window_start > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute([$endpoint]);
        } else {
            $stmt = $this->db->query("
                SELECT
                    COUNT(DISTINCT identifier) as unique_ips,
                    SUM(attempts) as total_attempts,
                    COUNT(*) FILTER (WHERE blocked_until > NOW()) as currently_blocked
                FROM rate_limits
                WHERE window_start > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
        }

        $row = $stmt->fetch();
        return [
            'unique_ips' => (int)($row['unique_ips'] ?? 0),
            'total_attempts' => (int)($row['total_attempts'] ?? 0),
            'currently_blocked' => (int)($row['currently_blocked'] ?? 0)
        ];
    }
}
