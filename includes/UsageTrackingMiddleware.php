<?php
/**
 * UsageTrackingMiddleware.php
 *
 * Middleware to automatically track API usage for analytics
 * Tracks response time, success/failure, and endpoint usage
 */

class UsageTrackingMiddleware {
    private PDO $db;
    private float $startTime;
    private ?int $userId;
    private string $endpoint;

    public function __construct(PDO $db, ?int $userId = null) {
        $this->db = $db;
        $this->userId = $userId;
        $this->startTime = microtime(true);

        $this->endpoint = $_SERVER['REQUEST_URI'] ?? 'unknown';
        $this->endpoint = parse_url($this->endpoint, PHP_URL_PATH) ?? $this->endpoint;
    }

    /**
     * Start tracking request
     */
    public static function start(PDO $db, ?int $userId = null): self {
        return new self($db, $userId);
    }

    /**
     * End tracking and log to database
     */
    public function end(bool $success = true, ?string $errorMessage = null): void {
        $responseTime = (microtime(true) - $this->startTime) * 1000;

        if ($this->userId === null) {
            return;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO api_usage_tracking
                (user_id, endpoint, response_time_ms, success, error_message, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $this->userId,
                $this->endpoint,
                round($responseTime, 2),
                $success ? 1 : 0,
                $errorMessage
            ]);
        } catch (PDOException $e) {
        }
    }

    /**
     * Wrapper to execute code with automatic tracking
     */
    public static function track(PDO $db, ?int $userId, callable $callback): mixed {
        $tracker = self::start($db, $userId);

        try {
            $result = $callback();
            $tracker->end(true);
            return $result;
        } catch (Exception $e) {
            $tracker->end(false, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create database table if not exists
     */
    public static function createTable(PDO $db): void {
        $db->exec("
            CREATE TABLE IF NOT EXISTS api_usage_tracking (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                endpoint VARCHAR(255) NOT NULL,
                response_time_ms DECIMAL(10,2) NOT NULL,
                success TINYINT(1) NOT NULL DEFAULT 1,
                error_message TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_created (user_id, created_at),
                INDEX idx_endpoint_created (endpoint, created_at),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
