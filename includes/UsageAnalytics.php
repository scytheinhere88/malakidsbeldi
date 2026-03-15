<?php
/**
 * UsageAnalytics.php
 *
 * Comprehensive usage analytics tracking system
 * Tracks API calls, revenue metrics, user behavior, and churn analytics
 */

class UsageAnalytics {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Track API call
     */
    public function trackAPICall(int $userId, string $endpoint, int $responseTime, bool $success = true): void {
        $stmt = $this->db->prepare("
            INSERT INTO api_usage_tracking
            (user_id, endpoint, response_time_ms, success, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $endpoint, $responseTime, $success ? 1 : 0]);
    }

    /**
     * Get user's usage over time (last 30 days)
     */
    public function getUserUsageOverTime(int $userId, int $days = 30): array {
        $stmt = $this->db->prepare("
            SELECT
                DATE(created_at) as date,
                COALESCE(SUM(csv_rows), 0) as rows_used,
                COALESCE(COUNT(*), 0) as api_calls
            FROM usage_log
            WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->execute([$userId, $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get user's API calls breakdown
     */
    public function getUserAPIBreakdown(int $userId, int $days = 30): array {
        $stmt = $this->db->prepare("
            SELECT
                endpoint,
                COUNT(*) as total_calls,
                AVG(response_time_ms) as avg_response_time,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_calls,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_calls
            FROM api_usage_tracking
            WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY endpoint
            ORDER BY total_calls DESC
        ");
        $stmt->execute([$userId, $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get current month usage percentage
     */
    public function getUserUsagePercentage(int $userId): array {
        $quota = getUserQuota($userId, false);
        $percentage = $quota['unlimited'] ? 0 : ($quota['used'] / max($quota['limit'] + $quota['rollover'], 1)) * 100;

        return [
            'used' => $quota['used'],
            'total' => $quota['unlimited'] ? 'Unlimited' : ($quota['limit'] + $quota['rollover']),
            'percentage' => round($percentage, 1),
            'unlimited' => $quota['unlimited']
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // ADMIN ANALYTICS
    // ═══════════════════════════════════════════════════════════

    /**
     * Get MRR (Monthly Recurring Revenue)
     */
    public function getMRR(): float {
        $stmt = $this->db->query("
            SELECT SUM(
                CASE
                    WHEN billing_cycle = 'monthly' THEN price
                    WHEN billing_cycle = 'annual' THEN price / 12
                    ELSE 0
                END
            ) as mrr
            FROM users
            WHERE plan != 'free'
            AND plan != 'lifetime'
            AND subscription_status = 'active'
        ");
        return (float)($stmt->fetch()['mrr'] ?? 0);
    }

    /**
     * Get ARR (Annual Recurring Revenue)
     */
    public function getARR(): float {
        return $this->getMRR() * 12;
    }

    /**
     * Get churn rate (last 30 days)
     */
    public function getChurnRate(): float {
        $stmt = $this->db->query("
            SELECT
                COUNT(DISTINCT CASE WHEN created_at <= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN id END) as active_start,
                COUNT(DISTINCT CASE WHEN subscription_status = 'cancelled' AND subscription_ends_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN id END) as churned
            FROM users
            WHERE plan != 'free'
        ");
        $data = $stmt->fetch();

        if (empty($data['active_start']) || $data['active_start'] == 0) {
            return 0;
        }

        return round(($data['churned'] / $data['active_start']) * 100, 2);
    }

    /**
     * Get revenue metrics
     */
    public function getRevenueMetrics(): array {
        $mrr = $this->getMRR();
        $arr = $this->getARR();

        // Get revenue by plan
        $stmt = $this->db->query("
            SELECT
                plan,
                billing_cycle,
                COUNT(*) as count,
                SUM(price) as total_revenue
            FROM users
            WHERE plan != 'free' AND subscription_status = 'active'
            GROUP BY plan, billing_cycle
        ");
        $revenueByPlan = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get total customers
        $stmt = $this->db->query("
            SELECT
                COUNT(*) as total,
                COUNT(CASE WHEN plan = 'free' THEN 1 END) as free_users,
                COUNT(CASE WHEN plan != 'free' THEN 1 END) as paying_users
            FROM users
        ");
        $customerStats = $stmt->fetch();

        // Calculate ARPU (Average Revenue Per User)
        $arpu = $customerStats['paying_users'] > 0
            ? $mrr / $customerStats['paying_users']
            : 0;

        return [
            'mrr' => round($mrr, 2),
            'arr' => round($arr, 2),
            'arpu' => round($arpu, 2),
            'total_customers' => (int)$customerStats['total'],
            'free_users' => (int)$customerStats['free_users'],
            'paying_users' => (int)$customerStats['paying_users'],
            'conversion_rate' => $customerStats['total'] > 0
                ? round(($customerStats['paying_users'] / $customerStats['total']) * 100, 2)
                : 0,
            'revenue_by_plan' => $revenueByPlan
        ];
    }

    /**
     * Get top users by usage (last 30 days)
     */
    public function getTopUsersByUsage(int $limit = 10): array {
        $limit = (int)$limit;
        $stmt = $this->db->prepare("
            SELECT
                u.id,
                u.email,
                u.plan,
                COALESCE(SUM(ul.csv_rows), 0) as total_rows,
                COALESCE(COUNT(ul.id), 0) as api_calls
            FROM users u
            LEFT JOIN usage_log ul ON u.id = ul.user_id
                AND ul.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY u.id, u.email, u.plan
            HAVING total_rows > 0
            ORDER BY total_rows DESC
            LIMIT $limit
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get MRR growth over time (last 12 months)
     */
    public function getMRRGrowth(int $months = 12): array {
        $stmt = $this->db->prepare("
            SELECT
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(DISTINCT CASE WHEN plan != 'free' THEN id END) as paying_customers,
                SUM(
                    CASE
                        WHEN billing_cycle = 'monthly' THEN price
                        WHEN billing_cycle = 'annual' THEN price / 12
                        ELSE 0
                    END
                ) as mrr
            FROM users
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ");
        $stmt->execute([$months]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get plan distribution
     */
    public function getPlanDistribution(): array {
        $stmt = $this->db->query("
            SELECT
                plan,
                COUNT(*) as count,
                ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM users)), 2) as percentage
            FROM users
            GROUP BY plan
            ORDER BY count DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get addon revenue
     */
    public function getAddonRevenue(): array {
        $stmt = $this->db->query("
            SELECT
                a.name,
                a.slug,
                COUNT(ua.id) as total_purchases,
                SUM(ua.price) as total_revenue
            FROM addons a
            LEFT JOIN user_addons ua ON a.id = ua.addon_id
            WHERE ua.is_active = 1
            GROUP BY a.id, a.name, a.slug
            ORDER BY total_revenue DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get system health metrics
     */
    public function getSystemHealth(): array {
        // Average API response time (last hour)
        $stmt = $this->db->query("
            SELECT
                AVG(response_time_ms) as avg_response_time,
                MAX(response_time_ms) as max_response_time,
                COUNT(*) as total_calls,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_calls
            FROM api_usage_tracking
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $apiStats = $stmt->fetch();

        // Database query performance
        $stmt = $this->db->query("
            SELECT
                AVG(execution_time) as avg_query_time,
                MAX(execution_time) as max_query_time
            FROM query_performance_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $dbStats = $stmt->fetch();

        return [
            'api' => [
                'avg_response_time' => round($apiStats['avg_response_time'] ?? 0, 2),
                'max_response_time' => round($apiStats['max_response_time'] ?? 0, 2),
                'total_calls' => (int)($apiStats['total_calls'] ?? 0),
                'failed_calls' => (int)($apiStats['failed_calls'] ?? 0),
                'error_rate' => $apiStats['total_calls'] > 0
                    ? round(($apiStats['failed_calls'] / $apiStats['total_calls']) * 100, 2)
                    : 0
            ],
            'database' => [
                'avg_query_time' => round($dbStats['avg_query_time'] ?? 0, 2),
                'max_query_time' => round($dbStats['max_query_time'] ?? 0, 2)
            ]
        ];
    }
}
