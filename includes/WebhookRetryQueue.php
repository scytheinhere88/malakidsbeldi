<?php

class WebhookRetryQueue {
    private $pdo;
    private $maxRetries = 5;
    private $retryDelays = [60, 300, 900, 3600, 7200];

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->ensureTable();
    }

    private function ensureTable() {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS webhook_retry_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                gateway VARCHAR(50) NOT NULL,
                payload TEXT NOT NULL,
                headers TEXT,
                attempt_count INT DEFAULT 0,
                last_error TEXT,
                next_retry_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME,
                INDEX idx_next_retry (next_retry_at, attempt_count),
                INDEX idx_gateway (gateway)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function queue(string $gateway, string $payload, array $headers = []): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO webhook_retry_queue (gateway, payload, headers, next_retry_at)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 MINUTE))
        ");

        $stmt->execute([
            $gateway,
            $payload,
            json_encode($headers)
        ]);

        $queueId = (int)$this->pdo->lastInsertId();

        error_log("Webhook queued for retry: {$gateway} (ID: {$queueId})");

        return $queueId;
    }

    public function processPending(): array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM webhook_retry_queue
            WHERE next_retry_at <= NOW()
            AND attempt_count < ?
            AND processed_at IS NULL
            ORDER BY next_retry_at ASC
            LIMIT 50
        ");

        $stmt->execute([$this->maxRetries]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'max_retries_reached' => 0
        ];

        foreach ($items as $item) {
            $results['processed']++;

            $success = $this->processWebhook($item);

            if ($success) {
                $this->markProcessed($item['id']);
                $results['succeeded']++;
            } else {
                $attemptCount = (int)$item['attempt_count'] + 1;

                if ($attemptCount >= $this->maxRetries) {
                    $this->markFailed($item['id'], 'Max retries reached');
                    $results['max_retries_reached']++;
                } else {
                    $nextRetry = $this->calculateNextRetry($attemptCount);
                    $this->scheduleRetry($item['id'], $attemptCount, $nextRetry);
                    $results['failed']++;
                }
            }
        }

        return $results;
    }

    private function processWebhook(array $item): bool {
        try {
            $gateway = $item['gateway'];
            $payload = $item['payload'];
            $headers = json_decode($item['headers'], true) ?? [];

            if ($gateway === 'gumroad') {
                return $this->processGumroad($payload, $headers);
            } elseif ($gateway === 'lemonsqueezy') {
                return $this->processLemonSqueezy($payload, $headers);
            }

            error_log("Unknown gateway in retry queue: {$gateway}");
            return false;

        } catch (Exception $e) {
            $this->updateError($item['id'], $e->getMessage());
            error_log("Webhook retry failed (ID {$item['id']}): " . $e->getMessage());
            return false;
        }
    }

    private function processGumroad(string $payload, array $headers): bool {
        $_POST = [];
        parse_str($payload, $_POST);
        $_SERVER['HTTP_X_GUMROAD_SIGNATURE'] = $headers['X-Gumroad-Signature'] ?? '';

        ob_start();
        try {
            include __DIR__ . '/../api/gumroad.php';
            $output = ob_get_clean();
            return strpos($output, 'ok') !== false;
        } catch (Exception $e) {
            ob_end_clean();
            throw $e;
        }
    }

    private function processLemonSqueezy(string $payload, array $headers): bool {
        $_SERVER['HTTP_X_SIGNATURE'] = $headers['X-Signature'] ?? '';

        $tempFile = tempnam(sys_get_temp_dir(), 'webhook_');
        file_put_contents($tempFile, $payload);

        ob_start();
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            include __DIR__ . '/../api/lemonsqueezy.php';
            $output = ob_get_clean();
            unlink($tempFile);
            return strpos($output, 'ok') !== false;
        } catch (Exception $e) {
            ob_end_clean();
            unlink($tempFile);
            throw $e;
        }
    }

    private function calculateNextRetry(int $attemptCount): string {
        $delaySeconds = $this->retryDelays[$attemptCount - 1] ?? 7200;
        return date('Y-m-d H:i:s', time() + $delaySeconds);
    }

    private function scheduleRetry(int $id, int $attemptCount, string $nextRetry): void {
        $this->pdo->prepare("
            UPDATE webhook_retry_queue
            SET attempt_count = ?, next_retry_at = ?
            WHERE id = ?
        ")->execute([$attemptCount, $nextRetry, $id]);
    }

    private function markProcessed(int $id): void {
        $this->pdo->prepare("
            UPDATE webhook_retry_queue
            SET processed_at = NOW()
            WHERE id = ?
        ")->execute([$id]);
    }

    private function markFailed(int $id, string $error): void {
        $this->pdo->prepare("
            UPDATE webhook_retry_queue
            SET last_error = ?, processed_at = NOW()
            WHERE id = ?
        ")->execute([$error, $id]);
    }

    private function updateError(int $id, string $error): void {
        $this->pdo->prepare("
            UPDATE webhook_retry_queue
            SET last_error = ?
            WHERE id = ?
        ")->execute([$error, $id]);
    }

    public function cleanupOld(int $days = 30): int {
        $stmt = $this->pdo->prepare("
            DELETE FROM webhook_retry_queue
            WHERE processed_at IS NOT NULL
            AND processed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");

        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
}
