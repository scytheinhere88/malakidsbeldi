<?php

class Analytics {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->ensureTables();
    }

    private function ensureTables(): void {
        $this->db->exec("CREATE TABLE IF NOT EXISTS analytics_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            session_id VARCHAR(64) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            event_category VARCHAR(50) NOT NULL,
            event_data JSON NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            referrer VARCHAR(500) NULL,
            created_at DATETIME DEFAULT NOW(),
            INDEX idx_user_id (user_id),
            INDEX idx_event_type (event_type),
            INDEX idx_created_at (created_at),
            INDEX idx_session_id (session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->exec("CREATE TABLE IF NOT EXISTS analytics_api_calls (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            endpoint VARCHAR(100) NOT NULL,
            method VARCHAR(10) NOT NULL,
            status_code INT NOT NULL,
            response_time_ms INT NOT NULL,
            error_message TEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME DEFAULT NOW(),
            INDEX idx_user_id (user_id),
            INDEX idx_endpoint (endpoint),
            INDEX idx_status (status_code),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->exec("CREATE TABLE IF NOT EXISTS analytics_conversions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            conversion_type VARCHAR(50) NOT NULL,
            from_plan VARCHAR(20) NOT NULL,
            to_plan VARCHAR(20) NOT NULL,
            payment_gateway VARCHAR(50) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            metadata JSON NULL,
            created_at DATETIME DEFAULT NOW(),
            INDEX idx_user_id (user_id),
            INDEX idx_conversion_type (conversion_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function trackEvent(
        string $eventType,
        string $category,
        ?int $userId = null,
        ?array $data = null
    ): void {
        try {
            $sessionId = session_id() ?: 'unknown';
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $referrer = $_SERVER['HTTP_REFERER'] ?? null;

            $stmt = $this->db->prepare("
                INSERT INTO analytics_events
                (user_id, session_id, event_type, event_category, event_data, ip_address, user_agent, referrer, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $userId,
                $sessionId,
                $eventType,
                $category,
                $data ? json_encode($data) : null,
                $ip,
                $userAgent,
                $referrer
            ]);
        } catch (Exception $e) {
            error_log("Analytics tracking error: " . $e->getMessage());
        }
    }

    public function trackApiCall(
        string $endpoint,
        string $method,
        int $statusCode,
        int $responseTimeMs,
        ?int $userId = null,
        ?string $errorMessage = null
    ): void {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;

            $stmt = $this->db->prepare("
                INSERT INTO analytics_api_calls
                (user_id, endpoint, method, status_code, response_time_ms, error_message, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $userId,
                $endpoint,
                $method,
                $statusCode,
                $responseTimeMs,
                $errorMessage,
                $ip
            ]);
        } catch (Exception $e) {
            error_log("Analytics API tracking error: " . $e->getMessage());
        }
    }

    public function trackConversion(
        int $userId,
        string $conversionType,
        string $fromPlan,
        string $toPlan,
        string $paymentGateway,
        float $amount,
        ?array $metadata = null
    ): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO analytics_conversions
                (user_id, conversion_type, from_plan, to_plan, payment_gateway, amount, metadata, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $userId,
                $conversionType,
                $fromPlan,
                $toPlan,
                $paymentGateway,
                $amount,
                $metadata ? json_encode($metadata) : null
            ]);
        } catch (Exception $e) {
            error_log("Analytics conversion tracking error: " . $e->getMessage());
        }
    }

    public function getConversionFunnel(int $days = 30): array {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(DISTINCT CASE WHEN event_type = 'page_view' AND event_category = 'landing' THEN session_id END) as visitors,
                COUNT(DISTINCT CASE WHEN event_type = 'registration_started' THEN session_id END) as registration_started,
                COUNT(DISTINCT CASE WHEN event_type = 'registration_completed' THEN user_id END) as registrations,
                COUNT(DISTINCT CASE WHEN event_type = 'plan_view' THEN user_id END) as viewed_pricing,
                COUNT(DISTINCT c.user_id) as conversions,
                COALESCE(SUM(c.amount), 0) as total_revenue
            FROM analytics_events e
            LEFT JOIN analytics_conversions c ON c.user_id = e.user_id
                AND c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            WHERE e.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$days, $days]);
        $row = $stmt->fetch();

        $visitors = (int)($row['visitors'] ?? 0);
        $regStarted = (int)($row['registration_started'] ?? 0);
        $registrations = (int)($row['registrations'] ?? 0);
        $viewedPricing = (int)($row['viewed_pricing'] ?? 0);
        $conversions = (int)($row['conversions'] ?? 0);
        $revenue = (float)($row['total_revenue'] ?? 0);

        return [
            'visitors' => $visitors,
            'registration_started' => $regStarted,
            'registrations' => $registrations,
            'viewed_pricing' => $viewedPricing,
            'conversions' => $conversions,
            'total_revenue' => $revenue,
            'conversion_rates' => [
                'visitor_to_signup' => $visitors > 0 ? round(($regStarted / $visitors) * 100, 2) : 0,
                'signup_to_register' => $regStarted > 0 ? round(($registrations / $regStarted) * 100, 2) : 0,
                'register_to_paid' => $registrations > 0 ? round(($conversions / $registrations) * 100, 2) : 0,
                'overall' => $visitors > 0 ? round(($conversions / $visitors) * 100, 2) : 0,
            ]
        ];
    }

    public function getApiPerformance(int $days = 7): array {
        $stmt = $this->db->prepare("
            SELECT
                endpoint,
                COUNT(*) as total_calls,
                AVG(response_time_ms) as avg_response_time,
                MIN(response_time_ms) as min_response_time,
                MAX(response_time_ms) as max_response_time,
                SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as error_count,
                ROUND((SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as error_rate
            FROM analytics_api_calls
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY endpoint
            ORDER BY total_calls DESC
        ");
        $stmt->execute([$days]);
        return $stmt->fetchAll();
    }

    public function getSlowQueries(int $thresholdMs = 1000, int $limit = 20): array {
        $stmt = $this->db->prepare("
            SELECT
                endpoint,
                response_time_ms,
                status_code,
                error_message,
                created_at
            FROM analytics_api_calls
            WHERE response_time_ms >= ?
            ORDER BY response_time_ms DESC
            LIMIT ?
        ");
        $stmt->execute([$thresholdMs, $limit]);
        return $stmt->fetchAll();
    }

    public function getRevenueStats(int $days = 30): array {
        $stmt = $this->db->prepare("
            SELECT
                DATE(created_at) as date,
                COUNT(*) as conversions,
                SUM(amount) as revenue,
                AVG(amount) as avg_order_value
            FROM analytics_conversions
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ");
        $stmt->execute([$days]);
        $daily = $stmt->fetchAll();

        $stmt = $this->db->prepare("
            SELECT
                payment_gateway,
                COUNT(*) as conversions,
                SUM(amount) as revenue
            FROM analytics_conversions
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY payment_gateway
        ");
        $stmt->execute([$days]);
        $byGateway = $stmt->fetchAll();

        $stmt = $this->db->prepare("
            SELECT
                to_plan as plan,
                COUNT(*) as conversions,
                SUM(amount) as revenue
            FROM analytics_conversions
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY to_plan
            ORDER BY revenue DESC
        ");
        $stmt->execute([$days]);
        $byPlan = $stmt->fetchAll();

        return [
            'daily' => $daily,
            'by_gateway' => $byGateway,
            'by_plan' => $byPlan,
            'total_revenue' => array_sum(array_column($daily, 'revenue')),
            'total_conversions' => array_sum(array_column($daily, 'conversions')),
        ];
    }

    public function getUserRetention(int $days = 90): array {
        $stmt = $this->db->prepare("
            SELECT
                DATE(u.created_at) as cohort_date,
                COUNT(DISTINCT u.id) as users,
                COUNT(DISTINCT CASE
                    WHEN ul.created_at >= DATE_ADD(u.created_at, INTERVAL 7 DAY)
                    THEN u.id END) as retained_7d,
                COUNT(DISTINCT CASE
                    WHEN ul.created_at >= DATE_ADD(u.created_at, INTERVAL 30 DAY)
                    THEN u.id END) as retained_30d
            FROM users u
            LEFT JOIN usage_log ul ON ul.user_id = u.id
            WHERE u.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(u.created_at)
            ORDER BY cohort_date DESC
        ");
        $stmt->execute([$days]);
        return $stmt->fetchAll();
    }

    public function cleanup(int $daysToKeep = 90): int {
        $stmt = $this->db->prepare("
            DELETE FROM analytics_events
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$daysToKeep]);
        $eventsDeleted = $stmt->rowCount();

        $stmt = $this->db->prepare("
            DELETE FROM analytics_api_calls
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$daysToKeep]);
        $apiCallsDeleted = $stmt->rowCount();

        return $eventsDeleted + $apiCallsDeleted;
    }
}
