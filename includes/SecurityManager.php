<?php

class SecurityManager {
    private $pdo;
    private $auditLogger;

    const MIN_PASSWORD_LENGTH = 8;
    const MAX_FAILED_ATTEMPTS = 5;
    const BLOCK_DURATION_MINUTES = 15;
    const SESSION_TIMEOUT_MINUTES = 120;
    const PASSWORD_HISTORY_COUNT = 5;

    public function __construct($pdo, $auditLogger = null) {
        $this->pdo = $pdo;
        $this->auditLogger = $auditLogger;
    }

    public function validatePasswordStrength($password) {
        $errors = [];

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = "Password must be at least " . self::MIN_PASSWORD_LENGTH . " characters long";
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter";
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number";
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    public function checkPasswordHistory($userId, $newPassword) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT password_hash FROM password_history
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, self::PASSWORD_HISTORY_COUNT]);
            $history = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($history as $oldHash) {
                if (password_verify($newPassword, $oldHash)) {
                    return false;
                }
            }

            return true;
        } catch (PDOException $e) {
            error_log("Password History Check Error: " . $e->getMessage());
            return true;
        }
    }

    public function addPasswordToHistory($userId, $passwordHash) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO password_history (user_id, password_hash)
                VALUES (?, ?)
            ");
            $stmt->execute([$userId, $passwordHash]);

            $stmt = $this->pdo->prepare("
                DELETE FROM password_history
                WHERE user_id = ?
                AND id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM password_history
                        WHERE user_id = ?
                        ORDER BY created_at DESC
                        LIMIT ?
                    ) AS subquery
                )
            ");
            $stmt->execute([$userId, $userId, self::PASSWORD_HISTORY_COUNT]);

            return true;
        } catch (PDOException $e) {
            error_log("Password History Add Error: " . $e->getMessage());
            return false;
        }
    }

    public function isLoginBlocked($email, $ipAddress) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, blocked_until FROM login_blocks
                WHERE email = ? AND ip_address = ?
                AND blocked_until > NOW()
                ORDER BY blocked_until DESC
                LIMIT 1
            ");
            $stmt->execute([$email, $ipAddress]);
            $block = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($block) {
                if ($this->auditLogger) {
                    $this->auditLogger->logAuth('login_blocked', $email, 'blocked',
                        'Account temporarily blocked due to multiple failed attempts');
                }
                return true;
            }

            return false;
        } catch (PDOException $e) {
            error_log("Login Block Check Error: " . $e->getMessage());
            return false;
        }
    }

    public function recordFailedLogin($email, $ipAddress) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as attempts FROM login_attempts
                WHERE email = ? AND ip_address = ?
                AND status = 'failed'
                AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $stmt->execute([$email, $ipAddress, self::BLOCK_DURATION_MINUTES]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $attempts = $result['attempts'] + 1;

            if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO login_blocks (email, ip_address, blocked_until, failed_attempts)
                    VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)
                ");
                $stmt->execute([$email, $ipAddress, self::BLOCK_DURATION_MINUTES, $attempts]);

                if ($this->auditLogger) {
                    $this->auditLogger->logAuth('login_blocked', $email, 'blocked',
                        "Account blocked after {$attempts} failed login attempts");
                }

                return [
                    'blocked' => true,
                    'attempts' => $attempts,
                    'duration' => self::BLOCK_DURATION_MINUTES
                ];
            }

            return [
                'blocked' => false,
                'attempts' => $attempts,
                'remaining' => self::MAX_FAILED_ATTEMPTS - $attempts
            ];
        } catch (PDOException $e) {
            error_log("Record Failed Login Error: " . $e->getMessage());
            return ['blocked' => false, 'attempts' => 0, 'remaining' => self::MAX_FAILED_ATTEMPTS];
        }
    }

    public function clearFailedLoginAttempts($email, $ipAddress) {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM login_blocks
                WHERE email = ? AND ip_address = ?
            ");
            $stmt->execute([$email, $ipAddress]);
            return true;
        } catch (PDOException $e) {
            error_log("Clear Failed Logins Error: " . $e->getMessage());
            return false;
        }
    }

    public function createSession($userId, $sessionId = null) {
        try {
            if (!$sessionId) {
                $sessionId = bin2hex(random_bytes(32));
            }

            $ipAddress = $this->getClientIP();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

            $stmt = $this->pdo->prepare("
                INSERT INTO sessions (id, user_id, ip_address, user_agent)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                last_activity = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$sessionId, $userId, $ipAddress, $userAgent]);

            return $sessionId;
        } catch (PDOException $e) {
            error_log("Create Session Error: " . $e->getMessage());
            return null;
        }
    }

    public function validateSession($sessionId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT user_id, TIMESTAMPDIFF(MINUTE, last_activity, NOW()) as minutes_idle
                FROM sessions
                WHERE id = ?
            ");
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                return false;
            }

            if ($session['minutes_idle'] > self::SESSION_TIMEOUT_MINUTES) {
                $this->destroySession($sessionId);
                return false;
            }

            $stmt = $this->pdo->prepare("
                UPDATE sessions
                SET last_activity = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$sessionId]);

            return $session['user_id'];
        } catch (PDOException $e) {
            error_log("Validate Session Error: " . $e->getMessage());
            return false;
        }
    }

    public function destroySession($sessionId) {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE id = ?");
            $stmt->execute([$sessionId]);
            return true;
        } catch (PDOException $e) {
            error_log("Destroy Session Error: " . $e->getMessage());
            return false;
        }
    }

    public function destroyAllUserSessions($userId) {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE user_id = ?");
            $stmt->execute([$userId]);

            if ($this->auditLogger) {
                $this->auditLogger->setUserId($userId);
                $this->auditLogger->log('sessions_invalidated', 'security', 'success', [
                    'target_type' => 'user',
                    'target_id' => $userId
                ]);
            }

            return true;
        } catch (PDOException $e) {
            error_log("Destroy All Sessions Error: " . $e->getMessage());
            return false;
        }
    }

    public function isIPWhitelisted($ipAddress) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id FROM admin_ip_whitelist
                WHERE ip_address = ? AND enabled = true
            ");
            $stmt->execute([$ipAddress]);
            return (bool) $stmt->fetch();
        } catch (PDOException $e) {
            error_log("IP Whitelist Check Error: " . $e->getMessage());
            return false;
        }
    }

    public function addIPToWhitelist($ipAddress, $description = '') {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO admin_ip_whitelist (ip_address, description)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE
                enabled = true,
                description = VALUES(description)
            ");
            $stmt->execute([$ipAddress, $description]);
            return true;
        } catch (PDOException $e) {
            error_log("Add IP to Whitelist Error: " . $e->getMessage());
            return false;
        }
    }

    public function removeIPFromWhitelist($ipAddress) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE admin_ip_whitelist
                SET enabled = false
                WHERE ip_address = ?
            ");
            $stmt->execute([$ipAddress]);
            return true;
        } catch (PDOException $e) {
            error_log("Remove IP from Whitelist Error: " . $e->getMessage());
            return false;
        }
    }

    public function lockAccount($userId, $reason = 'Security violation') {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE users
                SET account_locked = true
                WHERE id = ?
            ");
            $stmt->execute([$userId]);

            if ($this->auditLogger) {
                $this->auditLogger->setUserId($userId);
                $this->auditLogger->log('account_locked', 'security', 'success', [
                    'target_type' => 'user',
                    'target_id' => $userId,
                    'request_data' => ['reason' => $reason]
                ]);
            }

            $this->destroyAllUserSessions($userId);

            return true;
        } catch (PDOException $e) {
            error_log("Lock Account Error: " . $e->getMessage());
            return false;
        }
    }

    public function unlockAccount($userId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE users
                SET account_locked = false
                WHERE id = ?
            ");
            $stmt->execute([$userId]);

            if ($this->auditLogger) {
                $this->auditLogger->setAdminId($_SESSION['admin_id'] ?? null);
                $this->auditLogger->log('account_unlocked', 'admin', 'success', [
                    'target_type' => 'user',
                    'target_id' => $userId
                ]);
            }

            return true;
        } catch (PDOException $e) {
            error_log("Unlock Account Error: " . $e->getMessage());
            return false;
        }
    }

    public function sanitizeInput($input) {
        if (is_array($input)) {
            return array_map([$this, 'sanitizeInput'], $input);
        }
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    public function sanitizeOutput($output) {
        if (is_array($output)) {
            return array_map([$this, 'sanitizeOutput'], $output);
        }
        return htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
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

    public function cleanupExpiredSessions() {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM sessions
                WHERE last_activity < DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $stmt->execute([self::SESSION_TIMEOUT_MINUTES]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Cleanup Sessions Error: " . $e->getMessage());
            return 0;
        }
    }

    public function cleanupExpiredBlocks() {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM login_blocks
                WHERE blocked_until < NOW()
            ");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Cleanup Blocks Error: " . $e->getMessage());
            return 0;
        }
    }
}
