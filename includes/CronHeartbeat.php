<?php

class CronHeartbeat {
    private PDO $db;
    private static ?CronHeartbeat $instance = null;

    private function __construct(PDO $db) {
        $this->db = $db;
        $this->initTable();
    }

    public static function getInstance(PDO $db): CronHeartbeat {
        if (self::$instance === null) {
            self::$instance = new CronHeartbeat($db);
        }
        return self::$instance;
    }

    private function initTable(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS cron_heartbeats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_name VARCHAR(100) NOT NULL,
                last_run TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                next_expected_run TIMESTAMP NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'running',
                execution_time_ms FLOAT NULL,
                error_message TEXT NULL,
                metadata JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_job_name (job_name),
                INDEX idx_status (status),
                INDEX idx_next_expected (next_expected_run),
                INDEX idx_last_run (last_run)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS cron_execution_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                job_name VARCHAR(100) NOT NULL,
                started_at TIMESTAMP NOT NULL,
                completed_at TIMESTAMP NULL,
                execution_time_ms FLOAT NULL,
                status VARCHAR(20) NOT NULL,
                records_processed INT NULL,
                error_message TEXT NULL,
                metadata JSON NULL,
                INDEX idx_job_name (job_name),
                INDEX idx_started_at (started_at),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS cron_alerts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_name VARCHAR(100) NOT NULL,
                alert_type VARCHAR(50) NOT NULL,
                alert_message TEXT NOT NULL,
                severity VARCHAR(20) NOT NULL DEFAULT 'warning',
                is_resolved TINYINT(1) DEFAULT 0,
                resolved_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_job_name (job_name),
                INDEX idx_alert_type (alert_type),
                INDEX idx_resolved (is_resolved),
                INDEX idx_severity (severity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function startJob(string $jobName, int $intervalMinutes = 5, array $metadata = []): int {
        $nextRun = date('Y-m-d H:i:s', time() + ($intervalMinutes * 60));

        $stmt = $this->db->prepare("
            INSERT INTO cron_execution_logs
            (job_name, started_at, status, metadata)
            VALUES (?, NOW(), 'running', ?)
        ");

        $stmt->execute([
            $jobName,
            json_encode($metadata)
        ]);

        $executionId = (int)$this->db->lastInsertId();

        $stmt = $this->db->prepare("
            INSERT INTO cron_heartbeats
            (job_name, last_run, next_expected_run, status)
            VALUES (?, NOW(), ?, 'running')
            ON DUPLICATE KEY UPDATE
                last_run = NOW(),
                next_expected_run = ?,
                status = 'running',
                error_message = NULL
        ");

        $stmt->execute([$jobName, $nextRun, $nextRun]);

        return $executionId;
    }

    public function endJob(
        int $executionId,
        string $status = 'success',
        ?int $recordsProcessed = null,
        ?string $errorMessage = null,
        array $metadata = []
    ): void {
        $stmt = $this->db->prepare("
            SELECT job_name, started_at FROM cron_execution_logs WHERE id = ?
        ");
        $stmt->execute([$executionId]);
        $execution = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$execution) {
            return;
        }

        $executionTime = (time() - strtotime($execution['started_at'])) * 1000;

        $stmt = $this->db->prepare("
            UPDATE cron_execution_logs
            SET completed_at = NOW(),
                execution_time_ms = ?,
                status = ?,
                records_processed = ?,
                error_message = ?,
                metadata = JSON_MERGE_PATCH(COALESCE(metadata, '{}'), ?)
            WHERE id = ?
        ");

        $stmt->execute([
            $executionTime,
            $status,
            $recordsProcessed,
            $errorMessage,
            json_encode($metadata),
            $executionId
        ]);

        $stmt = $this->db->prepare("
            UPDATE cron_heartbeats
            SET status = ?,
                execution_time_ms = ?,
                error_message = ?
            WHERE job_name = ?
        ");

        $stmt->execute([
            $status,
            $executionTime,
            $errorMessage,
            $execution['job_name']
        ]);

        if ($status === 'failed') {
            $this->createAlert(
                $execution['job_name'],
                'execution_failed',
                "Job failed: " . ($errorMessage ?? 'Unknown error'),
                'error'
            );
        }
    }

    public function heartbeat(string $jobName): void {
        $stmt = $this->db->prepare("
            UPDATE cron_heartbeats
            SET updated_at = NOW()
            WHERE job_name = ?
        ");
        $stmt->execute([$jobName]);
    }

    public function checkMissedJobs(): array {
        $stmt = $this->db->query("
            SELECT
                job_name,
                last_run,
                next_expected_run,
                TIMESTAMPDIFF(MINUTE, next_expected_run, NOW()) as minutes_late
            FROM cron_heartbeats
            WHERE next_expected_run < NOW()
            AND status != 'disabled'
        ");

        $missedJobs = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $missedJobs[] = $row;

            $this->createAlert(
                $row['job_name'],
                'missed_execution',
                "Job is {$row['minutes_late']} minutes late. Expected at {$row['next_expected_run']}",
                $row['minutes_late'] > 60 ? 'critical' : 'warning'
            );
        }

        return $missedJobs;
    }

    public function getJobStats(string $jobName, int $hours = 24): array {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_runs,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_runs,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_runs,
                AVG(execution_time_ms) as avg_execution_time_ms,
                MAX(execution_time_ms) as max_execution_time_ms,
                MIN(execution_time_ms) as min_execution_time_ms,
                SUM(COALESCE(records_processed, 0)) as total_records_processed
            FROM cron_execution_logs
            WHERE job_name = ?
            AND started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");

        $stmt->execute([$jobName, $hours]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function getRecentExecutions(string $jobName, int $limit = 10): array {
        $stmt = $this->db->prepare("
            SELECT
                started_at,
                completed_at,
                execution_time_ms,
                status,
                records_processed,
                error_message
            FROM cron_execution_logs
            WHERE job_name = ?
            ORDER BY started_at DESC
            LIMIT ?
        ");

        $stmt->execute([$jobName, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllJobsStatus(): array {
        $stmt = $this->db->query("
            SELECT
                h.job_name,
                h.last_run,
                h.next_expected_run,
                h.status,
                h.execution_time_ms,
                h.error_message,
                CASE
                    WHEN h.next_expected_run < NOW() THEN 'late'
                    WHEN h.status = 'failed' THEN 'failed'
                    WHEN h.status = 'disabled' THEN 'disabled'
                    ELSE 'ok'
                END as health_status,
                (SELECT COUNT(*) FROM cron_execution_logs
                 WHERE job_name = h.job_name
                 AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 AND status = 'failed') as failures_24h
            FROM cron_heartbeats h
            ORDER BY h.job_name
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createAlert(
        string $jobName,
        string $alertType,
        string $message,
        string $severity = 'warning'
    ): int {
        $stmt = $this->db->prepare("
            SELECT id FROM cron_alerts
            WHERE job_name = ?
            AND alert_type = ?
            AND is_resolved = 0
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$jobName, $alertType]);

        if ($stmt->fetch()) {
            return 0;
        }

        $stmt = $this->db->prepare("
            INSERT INTO cron_alerts
            (job_name, alert_type, alert_message, severity)
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([$jobName, $alertType, $message, $severity]);

        if (file_exists(__DIR__ . '/AlertManager.php')) {
            require_once __DIR__ . '/AlertManager.php';
            $alertManager = AlertManager::getInstance($this->db);
            $alertManager->createAlert(
                "cron_{$alertType}",
                $severity,
                "Cron Job Alert: {$jobName} - {$message}",
                0,
                0,
                ['job_name' => $jobName, 'alert_type' => $alertType]
            );
        }

        return (int)$this->db->lastInsertId();
    }

    public function resolveAlerts(string $jobName, ?string $alertType = null): int {
        if ($alertType) {
            $stmt = $this->db->prepare("
                UPDATE cron_alerts
                SET is_resolved = 1, resolved_at = NOW()
                WHERE job_name = ?
                AND alert_type = ?
                AND is_resolved = 0
            ");
            $stmt->execute([$jobName, $alertType]);
        } else {
            $stmt = $this->db->prepare("
                UPDATE cron_alerts
                SET is_resolved = 1, resolved_at = NOW()
                WHERE job_name = ?
                AND is_resolved = 0
            ");
            $stmt->execute([$jobName]);
        }

        return $stmt->rowCount();
    }

    public function getActiveAlerts(?string $jobName = null): array {
        if ($jobName) {
            $stmt = $this->db->prepare("
                SELECT * FROM cron_alerts
                WHERE job_name = ?
                AND is_resolved = 0
                ORDER BY severity DESC, created_at DESC
            ");
            $stmt->execute([$jobName]);
        } else {
            $stmt = $this->db->query("
                SELECT * FROM cron_alerts
                WHERE is_resolved = 0
                ORDER BY severity DESC, created_at DESC
            ");
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function disableJob(string $jobName): void {
        $stmt = $this->db->prepare("
            UPDATE cron_heartbeats
            SET status = 'disabled'
            WHERE job_name = ?
        ");
        $stmt->execute([$jobName]);
    }

    public function enableJob(string $jobName, int $intervalMinutes = 5): void {
        $nextRun = date('Y-m-d H:i:s', time() + ($intervalMinutes * 60));

        $stmt = $this->db->prepare("
            UPDATE cron_heartbeats
            SET status = 'enabled',
                next_expected_run = ?
            WHERE job_name = ?
        ");
        $stmt->execute([$nextRun, $jobName]);
    }

    public function cleanupOldLogs(int $daysToKeep = 30): int {
        $stmt = $this->db->prepare("
            DELETE FROM cron_execution_logs
            WHERE started_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            LIMIT 10000
        ");
        $stmt->execute([$daysToKeep]);

        return $stmt->rowCount();
    }
}
