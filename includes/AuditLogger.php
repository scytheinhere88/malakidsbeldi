<?php

class AuditLogger {
    private $pdo;
    private $userId = null;
    private $adminId = null;
    private $ipAddress;
    private $userAgent;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->ipAddress = $this->getClientIP();
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }

    public function setUserId($userId) {
        $this->userId = $userId;
    }

    public function setAdminId($adminId) {
        $this->adminId = $adminId;
    }

    public function log($actionType, $actionCategory, $status = 'success', $options = []) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_logs (
                    user_id, admin_id, action_type, action_category,
                    target_type, target_id, ip_address, user_agent,
                    request_data, response_data, status, error_message
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $requestData = isset($options['request_data'])
                ? $this->maskSensitiveData($options['request_data'])
                : null;

            $responseData = isset($options['response_data'])
                ? $this->maskSensitiveData($options['response_data'])
                : null;

            $stmt->execute([
                $this->userId,
                $this->adminId,
                $actionType,
                $actionCategory,
                $options['target_type'] ?? null,
                $options['target_id'] ?? null,
                $this->ipAddress,
                $this->userAgent,
                $requestData ? json_encode($requestData) : null,
                $responseData ? json_encode($responseData) : null,
                $status,
                $options['error_message'] ?? null
            ]);

            return true;
        } catch (PDOException $e) {
            error_log("AuditLogger Error: " . $e->getMessage());
            return false;
        }
    }

    public function logAuth($actionType, $email, $status, $failureReason = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO login_attempts (email, ip_address, user_agent, status, failure_reason)
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $email,
                $this->ipAddress,
                $this->userAgent,
                $status,
                $failureReason
            ]);

            $this->log('login_attempt', 'auth', $status, [
                'target_type' => 'user',
                'target_id' => $email,
                'error_message' => $failureReason
            ]);

            return true;
        } catch (PDOException $e) {
            error_log("AuditLogger Auth Error: " . $e->getMessage());
            return false;
        }
    }

    public function logAPI($endpoint, $method, $statusCode, $executionTime, $options = []) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO api_logs (
                    user_id, endpoint, method, request_body, response_body,
                    status_code, execution_time, ip_address
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $requestBody = isset($options['request_body'])
                ? json_encode($this->maskSensitiveData($options['request_body']))
                : null;

            $responseBody = isset($options['response_body'])
                ? json_encode($this->maskSensitiveData($options['response_body']))
                : null;

            $stmt->execute([
                $this->userId,
                $endpoint,
                $method,
                $requestBody,
                $responseBody,
                $statusCode,
                $executionTime,
                $this->ipAddress
            ]);

            return true;
        } catch (PDOException $e) {
            error_log("AuditLogger API Error: " . $e->getMessage());
            return false;
        }
    }

    public function logDataExport($exportType, $status, $filePath = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO data_export_logs (user_id, export_type, file_path, status, ip_address)
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $this->userId,
                $exportType,
                $filePath,
                $status,
                $this->ipAddress
            ]);

            $this->log('data_export', 'data', $status, [
                'target_type' => 'export',
                'target_id' => $exportType
            ]);

            return true;
        } catch (PDOException $e) {
            error_log("AuditLogger Export Error: " . $e->getMessage());
            return false;
        }
    }

    public function logPayment($transactionId, $amount, $status, $options = []) {
        $this->log('payment', 'payment', $status, [
            'target_type' => 'transaction',
            'target_id' => $transactionId,
            'request_data' => [
                'amount' => $amount,
                'currency' => $options['currency'] ?? 'USD',
                'plan' => $options['plan'] ?? null
            ],
            'error_message' => $options['error_message'] ?? null
        ]);
    }

    public function logAdminAction($actionType, $targetType, $targetId, $changes = []) {
        $this->log($actionType, 'admin', 'success', [
            'target_type' => $targetType,
            'target_id' => $targetId,
            'request_data' => $changes
        ]);
    }

    public function logPlanChange($oldPlan, $newPlan, $reason = 'user_action') {
        $this->log('plan_change', 'billing', 'success', [
            'target_type' => 'plan',
            'target_id' => $this->userId,
            'request_data' => [
                'old_plan' => $oldPlan,
                'new_plan' => $newPlan,
                'reason' => $reason
            ]
        ]);
    }

    public function getRecentFailedLogins($email, $minutes = 15) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count
                FROM login_attempts
                WHERE email = ?
                AND status = 'failed'
                AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $stmt->execute([$email, $minutes]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'] ?? 0;
        } catch (PDOException $e) {
            error_log("AuditLogger Failed Login Count Error: " . $e->getMessage());
            return 0;
        }
    }

    public function isIPBlocked($email) {
        $failedAttempts = $this->getRecentFailedLogins($email, 15);
        return $failedAttempts >= 5;
    }

    public function getAuditLogs($filters = [], $limit = 100, $offset = 0) {
        try {
            $where = [];
            $params = [];

            if (!empty($filters['user_id'])) {
                $where[] = "user_id = ?";
                $params[] = $filters['user_id'];
            }

            if (!empty($filters['admin_id'])) {
                $where[] = "admin_id = ?";
                $params[] = $filters['admin_id'];
            }

            if (!empty($filters['action_category'])) {
                $where[] = "action_category = ?";
                $params[] = $filters['action_category'];
            }

            if (!empty($filters['action_type'])) {
                $where[] = "action_type = ?";
                $params[] = $filters['action_type'];
            }

            if (!empty($filters['status'])) {
                $where[] = "status = ?";
                $params[] = $filters['status'];
            }

            if (!empty($filters['date_from'])) {
                $where[] = "created_at >= ?";
                $params[] = $filters['date_from'];
            }

            if (!empty($filters['date_to'])) {
                $where[] = "created_at <= ?";
                $params[] = $filters['date_to'];
            }

            $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

            $sql = "SELECT * FROM audit_logs $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("AuditLogger Get Logs Error: " . $e->getMessage());
            return [];
        }
    }

    private function maskSensitiveData($data) {
        if (!is_array($data)) {
            return $data;
        }

        $sensitiveKeys = ['password', 'token', 'secret', 'api_key', 'private_key', 'credit_card', 'cvv', 'ssn'];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (strpos($lowerKey, $sensitiveKey) !== false) {
                    $data[$key] = '***MASKED***';
                    break;
                }
            }

            if (is_array($value)) {
                $data[$key] = $this->maskSensitiveData($value);
            }
        }

        return $data;
    }

    private function getClientIP() {
        $ipKeys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];

        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
