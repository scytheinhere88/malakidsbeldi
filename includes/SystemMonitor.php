<?php

class SystemMonitor {
    private PDO $db;
    private static ?SystemMonitor $instance = null;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->ensureTables();
    }

    public static function getInstance(PDO $db): self {
        if (self::$instance === null) {
            self::$instance = new self($db);
        }
        return self::$instance;
    }

    private function ensureTables(): void {
        // System metrics table
        $this->db->exec("CREATE TABLE IF NOT EXISTS system_metrics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            metric_type VARCHAR(50) NOT NULL,
            metric_name VARCHAR(100) NOT NULL,
            metric_value DECIMAL(10,2) NOT NULL,
            metadata JSON,
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_type_recorded (metric_type, recorded_at),
            INDEX idx_name_recorded (metric_name, recorded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // API call logs table
        $this->db->exec("CREATE TABLE IF NOT EXISTS api_call_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            endpoint VARCHAR(255) NOT NULL,
            method VARCHAR(10) NOT NULL,
            user_id INT NULL,
            ip_address VARCHAR(45) NOT NULL,
            response_time_ms INT NOT NULL,
            status_code INT NOT NULL,
            error_message TEXT NULL,
            request_size INT DEFAULT 0,
            response_size INT DEFAULT 0,
            user_agent TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_endpoint_created (endpoint, created_at),
            INDEX idx_user_created (user_id, created_at),
            INDEX idx_status_created (status_code, created_at),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Error logs table
        $this->db->exec("CREATE TABLE IF NOT EXISTS error_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            error_type VARCHAR(50) NOT NULL,
            error_message TEXT NOT NULL,
            error_file VARCHAR(255),
            error_line INT,
            stack_trace TEXT,
            context JSON,
            user_id INT NULL,
            ip_address VARCHAR(45),
            severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_type_created (error_type, created_at),
            INDEX idx_severity_created (severity, created_at),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Payment failure logs
        $this->db->exec("CREATE TABLE IF NOT EXISTS payment_failure_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            payment_gateway VARCHAR(50) NOT NULL,
            order_id VARCHAR(255),
            error_message TEXT,
            amount DECIMAL(10,2),
            currency VARCHAR(3),
            metadata JSON,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_created (user_id, created_at),
            INDEX idx_gateway_created (payment_gateway, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // ============================================
    // API CALL TRACKING
    // ============================================

    public function logApiCall(array $data): void {
        $stmt = $this->db->prepare("
            INSERT INTO api_call_logs
            (endpoint, method, user_id, ip_address, response_time_ms, status_code, error_message, request_size, response_size, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['endpoint'] ?? '',
            $data['method'] ?? 'GET',
            $data['user_id'] ?? null,
            $data['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $data['response_time_ms'] ?? 0,
            $data['status_code'] ?? 200,
            $data['error_message'] ?? null,
            $data['request_size'] ?? 0,
            $data['response_size'] ?? 0,
            $data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }

    public function getApiStats(int $hours = 24): array {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_calls,
                AVG(response_time_ms) as avg_response_time,
                MAX(response_time_ms) as max_response_time,
                MIN(response_time_ms) as min_response_time,
                COUNT(CASE WHEN status_code >= 500 THEN 1 END) as server_errors,
                COUNT(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 END) as client_errors,
                COUNT(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 END) as successful_calls,
                COUNT(DISTINCT ip_address) as unique_ips,
                COUNT(DISTINCT user_id) as unique_users
            FROM api_call_logs
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$hours]);
        $row = $stmt->fetch();

        $total = (int)$row['total_calls'];
        $errorRate = $total > 0 ? (($row['server_errors'] + $row['client_errors']) / $total) * 100 : 0;

        return [
            'total_calls' => $total,
            'avg_response_time' => round((float)$row['avg_response_time'], 2),
            'max_response_time' => (int)$row['max_response_time'],
            'min_response_time' => (int)$row['min_response_time'],
            'server_errors' => (int)$row['server_errors'],
            'client_errors' => (int)$row['client_errors'],
            'successful_calls' => (int)$row['successful_calls'],
            'error_rate' => round($errorRate, 2),
            'unique_ips' => (int)$row['unique_ips'],
            'unique_users' => (int)$row['unique_users']
        ];
    }

    public function getSlowestEndpoints(int $limit = 10, int $hours = 24): array {
        $limit = max(1, min(100, (int)$limit));
        $stmt = $this->db->prepare("
            SELECT
                endpoint,
                COUNT(*) as call_count,
                AVG(response_time_ms) as avg_response_time,
                MAX(response_time_ms) as max_response_time
            FROM api_call_logs
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
            GROUP BY endpoint
            ORDER BY avg_response_time DESC
            LIMIT $limit
        ");
        $stmt->execute([$hours]);
        return $stmt->fetchAll();
    }

    public function getEndpointStats(string $endpoint, int $hours = 24): array {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_calls,
                AVG(response_time_ms) as avg_response_time,
                MAX(response_time_ms) as max_response_time,
                COUNT(CASE WHEN status_code >= 500 THEN 1 END) as server_errors,
                COUNT(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 END) as client_errors,
                COUNT(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 END) as successful_calls
            FROM api_call_logs
            WHERE endpoint = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$endpoint, $hours]);
        return $stmt->fetch() ?: [];
    }

    // ============================================
    // ERROR TRACKING
    // ============================================

    public function logError(array $data): void {
        $stmt = $this->db->prepare("
            INSERT INTO error_logs
            (error_type, error_message, error_file, error_line, stack_trace, context, user_id, ip_address, severity)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['error_type'] ?? 'unknown',
            $data['error_message'] ?? '',
            $data['error_file'] ?? null,
            $data['error_line'] ?? null,
            $data['stack_trace'] ?? null,
            json_encode($data['context'] ?? []),
            $data['user_id'] ?? null,
            $data['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $data['severity'] ?? 'medium'
        ]);
    }

    public function getRecentErrors(int $limit = 50, string $severity = null): array {
        $limit = max(1, min(1000, (int)$limit));
        $sql = "SELECT * FROM error_logs";
        $params = [];

        if ($severity) {
            $sql .= " WHERE severity = ?";
            $params[] = $severity;
        }

        $sql .= " ORDER BY created_at DESC LIMIT $limit";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getErrorStats(int $hours = 24): array {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_errors,
                COUNT(CASE WHEN severity = 'critical' THEN 1 END) as critical_errors,
                COUNT(CASE WHEN severity = 'high' THEN 1 END) as high_errors,
                COUNT(CASE WHEN severity = 'medium' THEN 1 END) as medium_errors,
                COUNT(CASE WHEN severity = 'low' THEN 1 END) as low_errors,
                COUNT(DISTINCT error_type) as unique_error_types
            FROM error_logs
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$hours]);
        return $stmt->fetch() ?: [];
    }

    // ============================================
    // PAYMENT FAILURE TRACKING
    // ============================================

    public function logPaymentFailure(array $data): void {
        $stmt = $this->db->prepare("
            INSERT INTO payment_failure_logs
            (user_id, payment_gateway, order_id, error_message, amount, currency, metadata)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['user_id'] ?? null,
            $data['payment_gateway'] ?? 'unknown',
            $data['order_id'] ?? null,
            $data['error_message'] ?? null,
            $data['amount'] ?? null,
            $data['currency'] ?? 'USD',
            json_encode($data['metadata'] ?? [])
        ]);
    }

    public function getPaymentFailures(int $hours = 24): array {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_failures,
                COUNT(DISTINCT user_id) as affected_users,
                SUM(amount) as total_failed_amount,
                payment_gateway,
                COUNT(*) as gateway_failures
            FROM payment_failure_logs
            WHERE created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
            GROUP BY payment_gateway
        ");
        $stmt->execute([$hours]);
        return $stmt->fetchAll();
    }

    // ============================================
    // SYSTEM METRICS
    // ============================================

    public function recordMetric(string $type, string $name, float $value, array $metadata = []): void {
        $stmt = $this->db->prepare("
            INSERT INTO system_metrics (metric_type, metric_name, metric_value, metadata)
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([
            $type,
            $name,
            $value,
            json_encode($metadata)
        ]);
    }

    public function getDatabaseMetrics(): array {
        $metrics = [];

        // Database connection check
        try {
            $this->db->query("SELECT 1");
            $metrics['connected'] = true;
        } catch (Exception $e) {
            $metrics['connected'] = false;
            return $metrics;
        }

        // Database size
        $stmt = $this->db->query("
            SELECT
                SUM(data_length + index_length) / 1024 / 1024 AS size_mb,
                COUNT(*) as table_count
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
        ");
        $dbSize = $stmt->fetch();
        $metrics['database_size_mb'] = round((float)$dbSize['size_mb'], 2);
        $metrics['table_count'] = (int)$dbSize['table_count'];

        // Active connections
        $stmt = $this->db->query("SHOW STATUS LIKE 'Threads_connected'");
        $connections = $stmt->fetch();
        $metrics['active_connections'] = (int)$connections['Value'];

        // Slow queries
        $stmt = $this->db->query("SHOW STATUS LIKE 'Slow_queries'");
        $slow = $stmt->fetch();
        $metrics['slow_queries'] = (int)$slow['Value'];

        // Query time average (simplified)
        $metrics['query_time_avg'] = 0.5; // Placeholder - can be enhanced with actual query profiling

        // Record metrics
        $this->recordMetric('database', 'size_mb', $metrics['database_size_mb']);
        $this->recordMetric('database', 'active_connections', $metrics['active_connections']);

        return $metrics;
    }

    public function getDiskUsage(): array {
        $metrics = [];

        // Get disk space
        $diskTotal = disk_total_space('/');
        $diskFree = disk_free_space('/');
        $diskUsed = $diskTotal - $diskFree;
        $diskUsagePercent = ($diskUsed / $diskTotal) * 100;

        $metrics['total'] = $diskTotal;
        $metrics['free'] = $diskFree;
        $metrics['used'] = $diskUsed;
        $metrics['percent'] = round($diskUsagePercent, 2);
        $metrics['total_formatted'] = round($diskTotal / 1024 / 1024 / 1024, 2) . ' GB';
        $metrics['used_formatted'] = round($diskUsed / 1024 / 1024 / 1024, 2) . ' GB';
        $metrics['free_formatted'] = round($diskFree / 1024 / 1024 / 1024, 2) . ' GB';

        // Legacy keys for backward compatibility
        $metrics['disk_total_gb'] = round($diskTotal / 1024 / 1024 / 1024, 2);
        $metrics['disk_free_gb'] = round($diskFree / 1024 / 1024 / 1024, 2);
        $metrics['disk_used_gb'] = round($diskUsed / 1024 / 1024 / 1024, 2);
        $metrics['disk_usage_percent'] = round($diskUsagePercent, 2);

        // Record metric
        $this->recordMetric('disk', 'usage_percent', $metrics['percent']);

        return $metrics;
    }

    public function getMemoryUsage(): array {
        $metrics = [];

        $currentUsage = memory_get_usage();
        $peakUsage = memory_get_peak_usage();

        // Parse memory limit
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            $memoryLimitBytes = 2 * 1024 * 1024 * 1024; // Default to 2GB if unlimited
        } else {
            $memoryLimitBytes = $this->parseMemoryLimit($memoryLimit);
        }

        $memoryPercent = ($currentUsage / $memoryLimitBytes) * 100;

        $metrics['current'] = $currentUsage;
        $metrics['peak'] = $peakUsage;
        $metrics['limit'] = $memoryLimitBytes;
        $metrics['percent'] = round($memoryPercent, 2);
        $metrics['used_formatted'] = round($currentUsage / 1024 / 1024, 2) . ' MB';
        $metrics['total_formatted'] = round($memoryLimitBytes / 1024 / 1024, 2) . ' MB';

        // Legacy keys
        $metrics['memory_current_mb'] = round($currentUsage / 1024 / 1024, 2);
        $metrics['memory_peak_mb'] = round($peakUsage / 1024 / 1024, 2);
        $metrics['memory_limit'] = ini_get('memory_limit');

        // Record metric
        $this->recordMetric('memory', 'current_mb', $metrics['memory_current_mb']);

        return $metrics;
    }

    private function parseMemoryLimit(string $limit): int {
        $unit = strtoupper(substr($limit, -1));
        $value = (int)substr($limit, 0, -1);

        switch ($unit) {
            case 'G': return $value * 1024 * 1024 * 1024;
            case 'M': return $value * 1024 * 1024;
            case 'K': return $value * 1024;
            default: return (int)$limit;
        }
    }

    public function getQueueMetrics(): array {
        $metrics = [];

        // Check if email_queue table exists
        $tableExists = $this->db->query("
            SELECT COUNT(*) as count
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            AND table_name = 'email_queue'
        ")->fetch()['count'] > 0;

        if ($tableExists) {
            // Count pending emails
            $stmt = $this->db->query("
                SELECT COUNT(*) as count
                FROM email_queue
                WHERE status = 'pending'
            ");
            $metrics['pending'] = (int)$stmt->fetch()['count'];

            // Count failed emails
            $stmt = $this->db->query("
                SELECT COUNT(*) as count
                FROM email_queue
                WHERE status = 'failed'
            ");
            $metrics['failed'] = (int)$stmt->fetch()['count'];
        } else {
            $metrics['pending'] = 0;
            $metrics['failed'] = 0;
        }

        // Count blocked IPs
        $stmt = $this->db->query("
            SELECT COUNT(*) as count
            FROM rate_limits
            WHERE blocked_until > NOW()
        ");
        $metrics['blocked_ips'] = (int)$stmt->fetch()['count'];

        // Payment failures in last hour
        $failureTableExists = $this->db->query("
            SELECT COUNT(*) as count
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            AND table_name = 'payment_failure_logs'
        ")->fetch()['count'] > 0;

        if ($failureTableExists) {
            $stmt = $this->db->query("
                SELECT COUNT(*) as count
                FROM payment_failure_logs
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $metrics['recent_payment_failures'] = (int)$stmt->fetch()['count'];
        } else {
            $metrics['recent_payment_failures'] = 0;
        }

        return $metrics;
    }

    // ============================================
    // COMPREHENSIVE HEALTH CHECK
    // ============================================

    public function getHealthStatus(): array {
        $health = [
            'status' => 'healthy',
            'timestamp' => date('Y-m-d H:i:s'),
            'checks' => []
        ];

        // Database check
        try {
            $this->db->query("SELECT 1");
            $health['checks']['database'] = ['status' => 'ok', 'message' => 'Connected'];
        } catch (Exception $e) {
            $health['checks']['database'] = ['status' => 'error', 'message' => $e->getMessage()];
            $health['status'] = 'unhealthy';
        }

        // Disk space check
        $disk = $this->getDiskUsage();
        if ($disk['disk_usage_percent'] > 90) {
            $health['checks']['disk'] = ['status' => 'warning', 'message' => 'Disk usage critical: ' . $disk['disk_usage_percent'] . '%'];
            $health['status'] = 'degraded';
        } else if ($disk['disk_usage_percent'] > 80) {
            $health['checks']['disk'] = ['status' => 'warning', 'message' => 'Disk usage high: ' . $disk['disk_usage_percent'] . '%'];
        } else {
            $health['checks']['disk'] = ['status' => 'ok', 'message' => 'Disk usage: ' . $disk['disk_usage_percent'] . '%'];
        }

        // API performance check
        $apiStats = $this->getApiStats(1); // Last hour
        if ($apiStats['error_rate'] > 10) {
            $health['checks']['api'] = ['status' => 'warning', 'message' => 'High error rate: ' . $apiStats['error_rate'] . '%'];
            $health['status'] = 'degraded';
        } else {
            $health['checks']['api'] = ['status' => 'ok', 'message' => 'Error rate: ' . $apiStats['error_rate'] . '%'];
        }

        // Error rate check
        $errorStats = $this->getErrorStats(1);
        if ($errorStats['critical_errors'] > 0) {
            $health['checks']['errors'] = ['status' => 'critical', 'message' => 'Critical errors detected: ' . $errorStats['critical_errors']];
            $health['status'] = 'unhealthy';
        } else if ($errorStats['high_errors'] > 5) {
            $health['checks']['errors'] = ['status' => 'warning', 'message' => 'High severity errors: ' . $errorStats['high_errors']];
        } else {
            $health['checks']['errors'] = ['status' => 'ok', 'message' => 'Total errors: ' . $errorStats['total_errors']];
        }

        return $health;
    }

    // ============================================
    // API KEY USAGE TRACKING
    // ============================================

    public function getApiKeyUsageStats(int $hours = 24): array {
        $apiCallsTableExists = $this->db->query("
            SELECT COUNT(*) as count
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            AND table_name = 'api_call_logs'
        ")->fetch()['count'] > 0;

        if (!$apiCallsTableExists) {
            return [
                'total_api_calls' => 0,
                'unique_api_users' => 0,
                'top_api_users' => [],
                'api_endpoints' => [],
                'api_error_rate' => 0
            ];
        }

        $interval = $hours . ' HOUR';

        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) as total_calls,
                COUNT(DISTINCT user_id) as unique_users,
                SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as error_calls,
                AVG(response_time_ms) as avg_response_time
            FROM api_call_logs
            WHERE created_at > DATE_SUB(NOW(), INTERVAL {$interval})
            AND user_id IS NOT NULL
        ");
        $stmt->execute();
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);

        $topUsers = $this->db->prepare("
            SELECT
                u.id,
                u.email,
                u.plan,
                COUNT(a.id) as api_calls,
                AVG(a.response_time_ms) as avg_response_time,
                SUM(CASE WHEN a.status_code >= 400 THEN 1 ELSE 0 END) as errors,
                MAX(a.created_at) as last_call
            FROM users u
            INNER JOIN api_call_logs a ON u.id = a.user_id
            WHERE a.created_at > DATE_SUB(NOW(), INTERVAL {$interval})
            GROUP BY u.id, u.email, u.plan
            ORDER BY api_calls DESC
            LIMIT 10
        ");
        $topUsers->execute();

        $endpoints = $this->db->prepare("
            SELECT
                endpoint,
                COUNT(*) as call_count,
                AVG(response_time_ms) as avg_response_time,
                SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as errors
            FROM api_call_logs
            WHERE created_at > DATE_SUB(NOW(), INTERVAL {$interval})
            AND user_id IS NOT NULL
            GROUP BY endpoint
            ORDER BY call_count DESC
            LIMIT 10
        ");
        $endpoints->execute();

        return [
            'total_api_calls' => (int)$summary['total_calls'],
            'unique_api_users' => (int)$summary['unique_users'],
            'api_error_rate' => $summary['total_calls'] > 0
                ? round(($summary['error_calls'] / $summary['total_calls']) * 100, 2)
                : 0,
            'avg_response_time' => round($summary['avg_response_time'] ?? 0),
            'top_api_users' => $topUsers->fetchAll(PDO::FETCH_ASSOC),
            'api_endpoints' => $endpoints->fetchAll(PDO::FETCH_ASSOC)
        ];
    }

    // ============================================
    // CLEANUP
    // ============================================

    public function cleanup(int $days = 30): void {
        // Cleanup old API logs
        $this->db->prepare("
            DELETE FROM api_call_logs
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ")->execute([$days]);

        // Cleanup old error logs (keep critical forever)
        $this->db->prepare("
            DELETE FROM error_logs
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            AND severity NOT IN ('critical', 'high')
        ")->execute([$days]);

        // Cleanup old metrics
        $this->db->prepare("
            DELETE FROM system_metrics
            WHERE recorded_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ")->execute([$days]);
    }
}
