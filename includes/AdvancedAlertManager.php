<?php

class AdvancedAlertManager {
    private PDO $db;
    private static ?AdvancedAlertManager $instance = null;

    const SEVERITY_LOW = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH = 'high';
    const SEVERITY_CRITICAL = 'critical';

    const CHANNEL_EMAIL = 'email';
    const CHANNEL_WEBHOOK = 'webhook';
    const CHANNEL_SMS = 'sms';

    private function __construct(PDO $db) {
        $this->db = $db;
        $this->initTables();
    }

    public static function getInstance(PDO $db): AdvancedAlertManager {
        if (self::$instance === null) {
            self::$instance = new AdvancedAlertManager($db);
        }
        return self::$instance;
    }

    private function initTables(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS advanced_alerts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                alert_key VARCHAR(100) NOT NULL,
                alert_type VARCHAR(50) NOT NULL,
                severity VARCHAR(20) NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                current_value DECIMAL(20,2) NULL,
                threshold_value DECIMAL(20,2) NULL,
                metadata JSON NULL,
                status VARCHAR(20) DEFAULT 'active',
                escalation_level INT DEFAULT 0,
                acknowledged_by INT NULL,
                acknowledged_at TIMESTAMP NULL,
                resolved_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_alert_key (alert_key),
                INDEX idx_status (status),
                INDEX idx_severity (severity),
                INDEX idx_created_at (created_at),
                INDEX idx_escalation (escalation_level)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS alert_notification_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                alert_id INT NOT NULL,
                channel VARCHAR(20) NOT NULL,
                recipient VARCHAR(255) NOT NULL,
                sent_at TIMESTAMP NOT NULL,
                status VARCHAR(20) NOT NULL,
                error_message TEXT NULL,
                INDEX idx_alert_id (alert_id),
                INDEX idx_channel (channel),
                INDEX idx_sent_at (sent_at),
                FOREIGN KEY (alert_id) REFERENCES advanced_alerts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS alert_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rule_name VARCHAR(100) NOT NULL,
                metric_name VARCHAR(100) NOT NULL,
                condition_type VARCHAR(20) NOT NULL,
                threshold_value DECIMAL(20,2) NOT NULL,
                severity VARCHAR(20) NOT NULL,
                notification_channels JSON NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                cooldown_minutes INT DEFAULT 30,
                last_triggered TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_rule_name (rule_name),
                INDEX idx_metric_name (metric_name),
                INDEX idx_is_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS alert_escalation_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                alert_type VARCHAR(50) NOT NULL,
                severity VARCHAR(20) NOT NULL,
                minutes_until_escalation INT NOT NULL,
                escalate_to_severity VARCHAR(20) NOT NULL,
                notify_channels JSON NOT NULL,
                is_active TINYINT(1) DEFAULT 1,
                INDEX idx_alert_type (alert_type),
                INDEX idx_severity (severity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->initDefaultRules();
    }

    private function initDefaultRules(): void {
        $defaultRules = [
            [
                'rule_name' => 'high_error_rate',
                'metric_name' => 'error_rate',
                'condition_type' => 'greater_than',
                'threshold_value' => 5.0,
                'severity' => self::SEVERITY_HIGH,
                'notification_channels' => json_encode([self::CHANNEL_EMAIL, self::CHANNEL_WEBHOOK]),
                'cooldown_minutes' => 30
            ],
            [
                'rule_name' => 'disk_space_critical',
                'metric_name' => 'disk_usage_percent',
                'condition_type' => 'greater_than',
                'threshold_value' => 90.0,
                'severity' => self::SEVERITY_CRITICAL,
                'notification_channels' => json_encode([self::CHANNEL_EMAIL, self::CHANNEL_WEBHOOK]),
                'cooldown_minutes' => 15
            ],
            [
                'rule_name' => 'high_response_time',
                'metric_name' => 'avg_response_time',
                'condition_type' => 'greater_than',
                'threshold_value' => 2000.0,
                'severity' => self::SEVERITY_MEDIUM,
                'notification_channels' => json_encode([self::CHANNEL_EMAIL]),
                'cooldown_minutes' => 60
            ],
            [
                'rule_name' => 'database_slow_queries',
                'metric_name' => 'slow_query_count',
                'condition_type' => 'greater_than',
                'threshold_value' => 10.0,
                'severity' => self::SEVERITY_MEDIUM,
                'notification_channels' => json_encode([self::CHANNEL_EMAIL]),
                'cooldown_minutes' => 60
            ]
        ];

        $stmt = $this->db->prepare("
            INSERT IGNORE INTO alert_rules
            (rule_name, metric_name, condition_type, threshold_value, severity, notification_channels, cooldown_minutes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($defaultRules as $rule) {
            $stmt->execute([
                $rule['rule_name'],
                $rule['metric_name'],
                $rule['condition_type'],
                $rule['threshold_value'],
                $rule['severity'],
                $rule['notification_channels'],
                $rule['cooldown_minutes']
            ]);
        }

        $defaultEscalations = [
            [
                'alert_type' => 'system',
                'severity' => self::SEVERITY_MEDIUM,
                'minutes_until_escalation' => 60,
                'escalate_to_severity' => self::SEVERITY_HIGH,
                'notify_channels' => json_encode([self::CHANNEL_EMAIL, self::CHANNEL_WEBHOOK])
            ],
            [
                'alert_type' => 'system',
                'severity' => self::SEVERITY_HIGH,
                'minutes_until_escalation' => 30,
                'escalate_to_severity' => self::SEVERITY_CRITICAL,
                'notify_channels' => json_encode([self::CHANNEL_EMAIL, self::CHANNEL_WEBHOOK])
            ]
        ];

        $stmt = $this->db->prepare("
            INSERT IGNORE INTO alert_escalation_rules
            (alert_type, severity, minutes_until_escalation, escalate_to_severity, notify_channels)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($defaultEscalations as $esc) {
            $stmt->execute([
                $esc['alert_type'],
                $esc['severity'],
                $esc['minutes_until_escalation'],
                $esc['escalate_to_severity'],
                $esc['notify_channels']
            ]);
        }
    }

    public function createAlert(
        string $alertKey,
        string $alertType,
        string $severity,
        string $title,
        string $message,
        ?float $currentValue = null,
        ?float $thresholdValue = null,
        array $metadata = []
    ): int {
        $stmt = $this->db->prepare("
            SELECT id FROM advanced_alerts
            WHERE alert_key = ?
            AND status = 'active'
        ");
        $stmt->execute([$alertKey]);

        if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->db->prepare("
                UPDATE advanced_alerts
                SET current_value = ?,
                    metadata = ?,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $currentValue,
                json_encode($metadata),
                $existing['id']
            ]);

            return $existing['id'];
        }

        $stmt = $this->db->prepare("
            INSERT INTO advanced_alerts
            (alert_key, alert_type, severity, title, message, current_value, threshold_value, metadata)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $alertKey,
            $alertType,
            $severity,
            $title,
            $message,
            $currentValue,
            $thresholdValue,
            json_encode($metadata)
        ]);

        $alertId = (int)$this->db->lastInsertId();

        $this->sendNotifications($alertId, $severity);

        return $alertId;
    }

    private function sendNotifications(int $alertId, string $severity): void {
        $stmt = $this->db->prepare("SELECT * FROM advanced_alerts WHERE id = ?");
        $stmt->execute([$alertId]);
        $alert = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$alert) return;

        $channels = $this->getNotificationChannels($severity);

        foreach ($channels as $channel) {
            $this->sendNotification($alertId, $channel, $alert);
        }
    }

    private function getNotificationChannels(string $severity): array {
        switch ($severity) {
            case self::SEVERITY_CRITICAL:
                return [self::CHANNEL_EMAIL, self::CHANNEL_WEBHOOK];
            case self::SEVERITY_HIGH:
                return [self::CHANNEL_EMAIL, self::CHANNEL_WEBHOOK];
            case self::SEVERITY_MEDIUM:
                return [self::CHANNEL_EMAIL];
            default:
                return [self::CHANNEL_WEBHOOK];
        }
    }

    private function sendNotification(int $alertId, string $channel, array $alert): void {
        $status = 'sent';
        $errorMessage = null;

        try {
            switch ($channel) {
                case self::CHANNEL_EMAIL:
                    $this->sendEmailNotification($alert);
                    break;
                case self::CHANNEL_WEBHOOK:
                    $this->sendWebhookNotification($alert);
                    break;
            }
        } catch (Exception $e) {
            $status = 'failed';
            $errorMessage = $e->getMessage();
        }

        $recipient = $this->getRecipient($channel);

        $stmt = $this->db->prepare("
            INSERT INTO alert_notification_logs
            (alert_id, channel, recipient, sent_at, status, error_message)
            VALUES (?, ?, ?, NOW(), ?, ?)
        ");

        $stmt->execute([$alertId, $channel, $recipient, $status, $errorMessage]);
    }

    private function sendEmailNotification(array $alert): void {
        if (!defined('ADMIN_EMAIL') || !ADMIN_EMAIL) {
            throw new Exception('ADMIN_EMAIL not configured');
        }

        if (file_exists(__DIR__ . '/EmailSystem.php')) {
            require_once __DIR__ . '/EmailSystem.php';
            $emailSystem = new EmailSystem($this->db);

            $emailSystem->queueEmail(
                ADMIN_EMAIL,
                "🚨 Alert: {$alert['title']}",
                "alert_notification",
                [
                    'severity' => $alert['severity'],
                    'title' => $alert['title'],
                    'message' => $alert['message'],
                    'current_value' => $alert['current_value'],
                    'threshold_value' => $alert['threshold_value'],
                    'created_at' => $alert['created_at']
                ]
            );
        }
    }

    private function sendWebhookNotification(array $alert): void {
        if (!defined('ALERT_WEBHOOK_URL') || !ALERT_WEBHOOK_URL || ALERT_WEBHOOK_URL === 'your_slack_or_discord_webhook') {
            return;
        }

        $payload = [
            'severity' => $alert['severity'],
            'title' => $alert['title'],
            'message' => $alert['message'],
            'current_value' => $alert['current_value'],
            'threshold_value' => $alert['threshold_value'],
            'created_at' => $alert['created_at']
        ];

        $ch = curl_init(ALERT_WEBHOOK_URL);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("Webhook failed with status {$httpCode}");
        }
    }

    private function getRecipient(string $channel): string {
        switch ($channel) {
            case self::CHANNEL_EMAIL:
                return ADMIN_EMAIL ?? 'admin@example.com';
            case self::CHANNEL_WEBHOOK:
                return ALERT_WEBHOOK_URL ?? 'not_configured';
            default:
                return 'unknown';
        }
    }

    public function checkEscalations(): int {
        $stmt = $this->db->query("
            SELECT
                a.*,
                e.escalate_to_severity,
                e.notify_channels
            FROM advanced_alerts a
            JOIN alert_escalation_rules e
                ON a.alert_type = e.alert_type
                AND a.severity = e.severity
            WHERE a.status = 'active'
            AND a.acknowledged_at IS NULL
            AND TIMESTAMPDIFF(MINUTE, a.created_at, NOW()) >= e.minutes_until_escalation
            AND e.is_active = 1
        ");

        $escalated = 0;
        while ($alert = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->escalateAlert($alert['id'], $alert['escalate_to_severity']);
            $escalated++;
        }

        return $escalated;
    }

    private function escalateAlert(int $alertId, string $newSeverity): void {
        $stmt = $this->db->prepare("
            UPDATE advanced_alerts
            SET severity = ?,
                escalation_level = escalation_level + 1
            WHERE id = ?
        ");
        $stmt->execute([$newSeverity, $alertId]);

        $this->sendNotifications($alertId, $newSeverity);
    }

    public function acknowledgeAlert(int $alertId, int $userId): void {
        $stmt = $this->db->prepare("
            UPDATE advanced_alerts
            SET acknowledged_by = ?,
                acknowledged_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$userId, $alertId]);
    }

    public function resolveAlert(int $alertId): void {
        $stmt = $this->db->prepare("
            UPDATE advanced_alerts
            SET status = 'resolved',
                resolved_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$alertId]);
    }

    public function getActiveAlerts(?string $severity = null): array {
        if ($severity) {
            $stmt = $this->db->prepare("
                SELECT * FROM advanced_alerts
                WHERE status = 'active'
                AND severity = ?
                ORDER BY
                    FIELD(severity, 'critical', 'high', 'medium', 'low'),
                    created_at DESC
            ");
            $stmt->execute([$severity]);
        } else {
            $stmt = $this->db->query("
                SELECT * FROM advanced_alerts
                WHERE status = 'active'
                ORDER BY
                    FIELD(severity, 'critical', 'high', 'medium', 'low'),
                    created_at DESC
            ");
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAlertStats(int $hours = 24): array {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_alerts,
                SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical,
                SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high,
                SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) as medium,
                SUM(CASE WHEN severity = 'low' THEN 1 ELSE 0 END) as low,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
                AVG(TIMESTAMPDIFF(MINUTE, created_at, COALESCE(resolved_at, NOW()))) as avg_resolution_time_minutes
            FROM advanced_alerts
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");

        $stmt->execute([$hours]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
