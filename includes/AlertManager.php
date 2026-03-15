<?php

class AlertManager {
    private PDO $db;
    private SystemMonitor $monitor;
    private static ?AlertManager $instance = null;

    // Alert thresholds
    const THRESHOLDS = [
        'error_rate' => ['warning' => 5, 'critical' => 10], // percentage
        'response_time' => ['warning' => 1000, 'critical' => 3000], // milliseconds
        'disk_usage' => ['warning' => 80, 'critical' => 90], // percentage
        'payment_failures' => ['warning' => 5, 'critical' => 10], // count per hour
        'api_errors' => ['warning' => 20, 'critical' => 50], // count per hour
        'database_size' => ['warning' => 1000, 'critical' => 2000], // MB
    ];

    public function __construct(PDO $db, SystemMonitor $monitor) {
        $this->db = $db;
        $this->monitor = $monitor;
        $this->ensureTable();
    }

    public static function getInstance(PDO $db, SystemMonitor $monitor): self {
        if (self::$instance === null) {
            self::$instance = new self($db, $monitor);
        }
        return self::$instance;
    }

    private function ensureTable(): void {
        $this->db->exec("CREATE TABLE IF NOT EXISTS alert_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            alert_type VARCHAR(50) NOT NULL,
            severity ENUM('info', 'warning', 'critical') NOT NULL,
            message TEXT NOT NULL,
            metric_value DECIMAL(10,2),
            threshold_value DECIMAL(10,2),
            metadata JSON,
            resolved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_type_created (alert_type, created_at),
            INDEX idx_severity_resolved (severity, resolved_at),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->exec("CREATE TABLE IF NOT EXISTS alert_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            alert_id INT NOT NULL,
            notification_type ENUM('email', 'webhook', 'log') NOT NULL,
            recipient VARCHAR(255) NOT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
            error_message TEXT NULL,
            FOREIGN KEY (alert_id) REFERENCES alert_logs(id) ON DELETE CASCADE,
            INDEX idx_alert_status (alert_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ============================================
    // ALERT CREATION
    // ============================================

    public function createAlert(string $type, string $severity, string $message, float $metricValue = null, float $thresholdValue = null, array $metadata = []): int {
        // Check if similar alert exists in last 15 minutes (avoid spam)
        $stmt = $this->db->prepare("
            SELECT id FROM alert_logs
            WHERE alert_type = ?
            AND severity = ?
            AND resolved_at IS NULL
            AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            LIMIT 1
        ");
        $stmt->execute([$type, $severity]);

        if ($stmt->fetch()) {
            return 0; // Alert already exists, skip
        }

        // Create new alert
        $stmt = $this->db->prepare("
            INSERT INTO alert_logs (alert_type, severity, message, metric_value, threshold_value, metadata)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $type,
            $severity,
            $message,
            $metricValue,
            $thresholdValue,
            json_encode($metadata)
        ]);

        $alertId = (int)$this->db->lastInsertId();

        // Send notifications for critical and warning alerts
        if ($severity === 'critical' || $severity === 'warning') {
            $this->sendAlertNotifications($alertId, $type, $severity, $message, $metricValue, $thresholdValue);
        }

        return $alertId;
    }

    public function resolveAlert(int $alertId): void {
        $this->db->prepare("
            UPDATE alert_logs
            SET resolved_at = NOW()
            WHERE id = ?
        ")->execute([$alertId]);
    }

    public function resolveAlertsByType(string $type): void {
        $this->db->prepare("
            UPDATE alert_logs
            SET resolved_at = NOW()
            WHERE alert_type = ? AND resolved_at IS NULL
        ")->execute([$type]);
    }

    // ============================================
    // AUTOMATIC MONITORING
    // ============================================

    public function checkAllThresholds(): array {
        $alerts = [];

        // Check API error rate
        $apiStats = $this->monitor->getApiStats(1);
        if ($apiStats['error_rate'] >= self::THRESHOLDS['error_rate']['critical']) {
            $alerts[] = $this->createAlert(
                'api_error_rate',
                'critical',
                "API error rate is critically high: {$apiStats['error_rate']}%",
                $apiStats['error_rate'],
                self::THRESHOLDS['error_rate']['critical']
            );
        } else if ($apiStats['error_rate'] >= self::THRESHOLDS['error_rate']['warning']) {
            $alerts[] = $this->createAlert(
                'api_error_rate',
                'warning',
                "API error rate is elevated: {$apiStats['error_rate']}%",
                $apiStats['error_rate'],
                self::THRESHOLDS['error_rate']['warning']
            );
        }

        // Check API response time
        if ($apiStats['avg_response_time'] >= self::THRESHOLDS['response_time']['critical']) {
            $alerts[] = $this->createAlert(
                'api_response_time',
                'critical',
                "API response time is critically slow: {$apiStats['avg_response_time']}ms",
                $apiStats['avg_response_time'],
                self::THRESHOLDS['response_time']['critical']
            );
        } else if ($apiStats['avg_response_time'] >= self::THRESHOLDS['response_time']['warning']) {
            $alerts[] = $this->createAlert(
                'api_response_time',
                'warning',
                "API response time is slow: {$apiStats['avg_response_time']}ms",
                $apiStats['avg_response_time'],
                self::THRESHOLDS['response_time']['warning']
            );
        }

        // Check disk usage
        $disk = $this->monitor->getDiskUsage();
        if ($disk['disk_usage_percent'] >= self::THRESHOLDS['disk_usage']['critical']) {
            $alerts[] = $this->createAlert(
                'disk_usage',
                'critical',
                "Disk usage is critically high: {$disk['disk_usage_percent']}%",
                $disk['disk_usage_percent'],
                self::THRESHOLDS['disk_usage']['critical']
            );
        } else if ($disk['disk_usage_percent'] >= self::THRESHOLDS['disk_usage']['warning']) {
            $alerts[] = $this->createAlert(
                'disk_usage',
                'warning',
                "Disk usage is high: {$disk['disk_usage_percent']}%",
                $disk['disk_usage_percent'],
                self::THRESHOLDS['disk_usage']['warning']
            );
        }

        // Check payment failures
        $paymentFailures = $this->monitor->getPaymentFailures(1);
        $totalFailures = array_sum(array_column($paymentFailures, 'gateway_failures'));
        if ($totalFailures >= self::THRESHOLDS['payment_failures']['critical']) {
            $alerts[] = $this->createAlert(
                'payment_failures',
                'critical',
                "Payment failures are critically high: {$totalFailures} failures in the last hour",
                $totalFailures,
                self::THRESHOLDS['payment_failures']['critical'],
                ['failures_by_gateway' => $paymentFailures]
            );
        } else if ($totalFailures >= self::THRESHOLDS['payment_failures']['warning']) {
            $alerts[] = $this->createAlert(
                'payment_failures',
                'warning',
                "Payment failures are elevated: {$totalFailures} failures in the last hour",
                $totalFailures,
                self::THRESHOLDS['payment_failures']['warning'],
                ['failures_by_gateway' => $paymentFailures]
            );
        }

        // Check error logs
        $errorStats = $this->monitor->getErrorStats(1);
        if ($errorStats['critical_errors'] > 0) {
            $alerts[] = $this->createAlert(
                'critical_errors',
                'critical',
                "Critical errors detected: {$errorStats['critical_errors']} in the last hour",
                $errorStats['critical_errors'],
                1
            );
        }

        $totalErrors = $errorStats['total_errors'];
        if ($totalErrors >= self::THRESHOLDS['api_errors']['critical']) {
            $alerts[] = $this->createAlert(
                'error_count',
                'critical',
                "Error count is critically high: {$totalErrors} errors in the last hour",
                $totalErrors,
                self::THRESHOLDS['api_errors']['critical']
            );
        } else if ($totalErrors >= self::THRESHOLDS['api_errors']['warning']) {
            $alerts[] = $this->createAlert(
                'error_count',
                'warning',
                "Error count is elevated: {$totalErrors} errors in the last hour",
                $totalErrors,
                self::THRESHOLDS['api_errors']['warning']
            );
        }

        // Check database size
        $dbMetrics = $this->monitor->getDatabaseMetrics();
        if ($dbMetrics['database_size_mb'] >= self::THRESHOLDS['database_size']['critical']) {
            $alerts[] = $this->createAlert(
                'database_size',
                'critical',
                "Database size is critically large: {$dbMetrics['database_size_mb']}MB",
                $dbMetrics['database_size_mb'],
                self::THRESHOLDS['database_size']['critical']
            );
        } else if ($dbMetrics['database_size_mb'] >= self::THRESHOLDS['database_size']['warning']) {
            $alerts[] = $this->createAlert(
                'database_size',
                'warning',
                "Database size is large: {$dbMetrics['database_size_mb']}MB",
                $dbMetrics['database_size_mb'],
                self::THRESHOLDS['database_size']['warning']
            );
        }

        return array_filter($alerts); // Remove zeros (skipped duplicates)
    }

    // ============================================
    // NOTIFICATIONS
    // ============================================

    private function sendAlertNotifications(int $alertId, string $type, string $severity, string $message, ?float $metricValue, ?float $thresholdValue): void {
        // Log to system error log for immediate visibility
        error_log("[ALERT-{$severity}] {$type}: {$message}");

        // Log notification
        $this->db->prepare("
            INSERT INTO alert_notifications (alert_id, notification_type, recipient, status)
            VALUES (?, 'log', 'system', 'sent')
        ")->execute([$alertId]);

        // Send email notification
        $emailSent = $this->sendEmailAlert($alertId, $type, $severity, $message, $metricValue, $thresholdValue);
        if ($emailSent) {
            $this->db->prepare("
                INSERT INTO alert_notifications (alert_id, notification_type, recipient, status)
                VALUES (?, 'email', ?, 'sent')
            ")->execute([$alertId, $_ENV['ADMIN_EMAIL'] ?? 'admin@localhost']);
        }

        // Send webhook notification (Slack, Discord, etc.)
        $webhookSent = $this->sendWebhookAlert($alertId, $type, $severity, $message, $metricValue, $thresholdValue);
        if ($webhookSent) {
            $this->db->prepare("
                INSERT INTO alert_notifications (alert_id, notification_type, recipient, status)
                VALUES (?, 'webhook', ?, 'sent')
            ")->execute([$alertId, $_ENV['ALERT_WEBHOOK_URL'] ?? 'none']);
        }
    }

    // ============================================
    // EMAIL NOTIFICATION
    // ============================================

    private function sendEmailAlert(int $alertId, string $type, string $severity, string $message, ?float $metricValue, ?float $thresholdValue): bool {
        try {
            // Check if email system is configured
            if (empty($_ENV['ADMIN_EMAIL'])) {
                return false;
            }

            require_once __DIR__ . '/EmailSystem.php';
            $emailSystem = new EmailSystem($this->db);

            // Determine template based on severity
            $templateKey = $severity === 'critical' ? 'critical_alert' : 'warning_alert';

            // Prepare email variables
            $variables = [
                'alert_type' => ucwords(str_replace('_', ' ', $type)),
                'alert_message' => $message,
                'alert_time' => date('Y-m-d H:i:s'),
                'metric_value' => $metricValue !== null ? number_format($metricValue, 2) : 'N/A',
                'threshold_value' => $thresholdValue !== null ? number_format($thresholdValue, 2) : 'N/A'
            ];

            // Send email to admin
            $result = $emailSystem->sendFromTemplate(
                $templateKey,
                $_ENV['ADMIN_EMAIL'],
                'System Administrator',
                $variables,
                null,
                1 // Highest priority
            );

            return $result !== false;

        } catch (Exception $e) {
            error_log("[AlertManager] Email notification failed: " . $e->getMessage());

            // Log failed notification
            $this->db->prepare("
                INSERT INTO alert_notifications (alert_id, notification_type, recipient, status, error_message)
                VALUES (?, 'email', ?, 'failed', ?)
            ")->execute([$alertId, $_ENV['ADMIN_EMAIL'] ?? 'unknown', $e->getMessage()]);

            return false;
        }
    }

    // ============================================
    // WEBHOOK NOTIFICATION (Slack, Discord, etc.)
    // ============================================

    private function sendWebhookAlert(int $alertId, string $type, string $severity, string $message, ?float $metricValue, ?float $thresholdValue): bool {
        try {
            // Check if webhook is configured
            if (empty($_ENV['ALERT_WEBHOOK_URL'])) {
                return false;
            }

            $webhookUrl = $_ENV['ALERT_WEBHOOK_URL'];
            $webhookType = $_ENV['ALERT_WEBHOOK_TYPE'] ?? 'slack'; // slack, discord, generic

            // Prepare payload based on webhook type
            $payload = $this->prepareWebhookPayload($webhookType, $type, $severity, $message, $metricValue, $thresholdValue);

            // Send webhook
            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                return true;
            }

            throw new Exception("Webhook returned HTTP {$httpCode}: {$response}");

        } catch (Exception $e) {
            error_log("[AlertManager] Webhook notification failed: " . $e->getMessage());

            // Log failed notification
            $this->db->prepare("
                INSERT INTO alert_notifications (alert_id, notification_type, recipient, status, error_message)
                VALUES (?, 'webhook', ?, 'failed', ?)
            ")->execute([$alertId, $_ENV['ALERT_WEBHOOK_URL'] ?? 'unknown', $e->getMessage()]);

            return false;
        }
    }

    private function prepareWebhookPayload(string $type, string $alertType, string $severity, string $message, ?float $metricValue, ?float $thresholdValue): array {
        $color = $severity === 'critical' ? '#dc2626' : ($severity === 'warning' ? '#f59e0b' : '#3b82f6');
        $emoji = $severity === 'critical' ? '🚨' : ($severity === 'warning' ? '⚠️' : 'ℹ️');

        if ($type === 'slack') {
            return [
                'text' => "{$emoji} *{$severity} Alert*",
                'attachments' => [
                    [
                        'color' => $color,
                        'fields' => [
                            [
                                'title' => 'Alert Type',
                                'value' => ucwords(str_replace('_', ' ', $alertType)),
                                'short' => true
                            ],
                            [
                                'title' => 'Severity',
                                'value' => strtoupper($severity),
                                'short' => true
                            ],
                            [
                                'title' => 'Message',
                                'value' => $message,
                                'short' => false
                            ],
                            [
                                'title' => 'Metric Value',
                                'value' => $metricValue !== null ? number_format($metricValue, 2) : 'N/A',
                                'short' => true
                            ],
                            [
                                'title' => 'Threshold',
                                'value' => $thresholdValue !== null ? number_format($thresholdValue, 2) : 'N/A',
                                'short' => true
                            ]
                        ],
                        'footer' => 'Alert System',
                        'ts' => time()
                    ]
                ]
            ];
        } elseif ($type === 'discord') {
            return [
                'embeds' => [
                    [
                        'title' => "{$emoji} {$severity} Alert: " . ucwords(str_replace('_', ' ', $alertType)),
                        'description' => $message,
                        'color' => hexdec(substr($color, 1)),
                        'fields' => [
                            [
                                'name' => 'Severity',
                                'value' => strtoupper($severity),
                                'inline' => true
                            ],
                            [
                                'name' => 'Metric Value',
                                'value' => $metricValue !== null ? number_format($metricValue, 2) : 'N/A',
                                'inline' => true
                            ],
                            [
                                'name' => 'Threshold',
                                'value' => $thresholdValue !== null ? number_format($thresholdValue, 2) : 'N/A',
                                'inline' => true
                            ]
                        ],
                        'timestamp' => date('c'),
                        'footer' => [
                            'text' => 'Alert System'
                        ]
                    ]
                ]
            ];
        } else {
            // Generic webhook payload
            return [
                'alert_type' => $alertType,
                'severity' => $severity,
                'message' => $message,
                'metric_value' => $metricValue,
                'threshold_value' => $thresholdValue,
                'timestamp' => date('c')
            ];
        }
    }

    // ============================================
    // ALERT RETRIEVAL
    // ============================================

    public function getActiveAlerts(string $severity = null): array {
        $sql = "SELECT * FROM alert_logs WHERE resolved_at IS NULL";
        $params = [];

        if ($severity) {
            $sql .= " AND severity = ?";
            $params[] = $severity;
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getAlertHistory(int $hours = 24, string $severity = null): array {
        $sql = "SELECT * FROM alert_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)";
        $params = [$hours];

        if ($severity) {
            $sql .= " AND severity = ?";
            $params[] = $severity;
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getAlertStats(int $hours = 24): array {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_alerts,
                COUNT(CASE WHEN severity = 'critical' THEN 1 END) as critical_alerts,
                COUNT(CASE WHEN severity = 'warning' THEN 1 END) as warning_alerts,
                COUNT(CASE WHEN severity = 'info' THEN 1 END) as info_alerts,
                COUNT(CASE WHEN resolved_at IS NOT NULL THEN 1 END) as resolved_alerts,
                COUNT(CASE WHEN resolved_at IS NULL THEN 1 END) as active_alerts
            FROM alert_logs
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$hours]);
        return $stmt->fetch() ?: [];
    }

    // ============================================
    // CLEANUP
    // ============================================

    public function cleanup(int $days = 30): void {
        // Delete old resolved alerts
        $this->db->prepare("
            DELETE FROM alert_logs
            WHERE resolved_at IS NOT NULL
            AND resolved_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ")->execute([$days]);

        // Auto-resolve old unresolved alerts (assume fixed)
        $this->db->prepare("
            UPDATE alert_logs
            SET resolved_at = NOW()
            WHERE resolved_at IS NULL
            AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ")->execute();
    }
}
