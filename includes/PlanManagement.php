<?php

class PlanManagement {
    private PDO $db;

    const GRACE_PERIOD_DAYS = 7;
    const PLAN_HIERARCHY = ['free' => 0, 'starter' => 1, 'pro' => 2, 'agency' => 3];

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->ensureGraceTable();
    }

    private function ensureGraceTable(): void {
        $this->db->exec("CREATE TABLE IF NOT EXISTS grace_periods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            original_plan VARCHAR(20) NOT NULL,
            target_plan VARCHAR(20) NOT NULL,
            grace_started_at DATETIME NOT NULL,
            grace_ends_at DATETIME NOT NULL,
            downgrade_executed BOOLEAN DEFAULT FALSE,
            created_at DATETIME DEFAULT NOW(),
            INDEX idx_user_id (user_id),
            INDEX idx_grace_ends (grace_ends_at),
            INDEX idx_executed (downgrade_executed)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->exec("CREATE TABLE IF NOT EXISTS plan_changes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            from_plan VARCHAR(20) NOT NULL,
            to_plan VARCHAR(20) NOT NULL,
            change_type VARCHAR(20) NOT NULL,
            prorated_amount DECIMAL(10,2) DEFAULT 0,
            days_remaining INT DEFAULT 0,
            reason VARCHAR(100) DEFAULT NULL,
            created_at DATETIME DEFAULT NOW(),
            INDEX idx_user_id (user_id),
            INDEX idx_change_type (change_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function startGracePeriod(int $userId, string $originalPlan, string $targetPlan): bool {
        try {
            $existing = $this->db->prepare("
                SELECT id FROM grace_periods
                WHERE user_id = ? AND downgrade_executed = FALSE
            ");
            $existing->execute([$userId]);

            if ($existing->fetch()) {
                return false;
            }

            $graceEnds = date('Y-m-d H:i:s', strtotime('+' . self::GRACE_PERIOD_DAYS . ' days'));

            $stmt = $this->db->prepare("
                INSERT INTO grace_periods
                (user_id, original_plan, target_plan, grace_started_at, grace_ends_at, created_at)
                VALUES (?, ?, ?, NOW(), ?, NOW())
            ");

            $stmt->execute([$userId, $originalPlan, $targetPlan, $graceEnds]);

            $this->db->prepare("
                UPDATE users
                SET plan_status = 'grace_period',
                    plan_expires_at = ?
                WHERE id = ?
            ")->execute([$graceEnds, $userId]);

            require_once __DIR__ . '/AuditLogger.php';
            $auditLogger = new AuditLogger($this->db);
            $auditLogger->setUserId($userId);
            $auditLogger->log('grace_period_started', 'billing', 'success', [
                'target_type' => 'plan',
                'target_id' => $userId,
                'request_data' => [
                    'original_plan' => $originalPlan,
                    'target_plan' => $targetPlan,
                    'grace_days' => self::GRACE_PERIOD_DAYS,
                    'grace_ends_at' => $graceEnds
                ]
            ]);

            return true;
        } catch (Exception $e) {
            error_log("Grace period error: " . $e->getMessage());
            return false;
        }
    }

    public function checkGracePeriod(int $userId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM grace_periods
            WHERE user_id = ? AND downgrade_executed = FALSE
            ORDER BY grace_ends_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $grace = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$grace) return null;

        $now = time();
        $endsAt = strtotime($grace['grace_ends_at']);
        $daysRemaining = max(0, ceil(($endsAt - $now) / 86400));

        return [
            'active' => true,
            'original_plan' => $grace['original_plan'],
            'target_plan' => $grace['target_plan'],
            'days_remaining' => $daysRemaining,
            'grace_ends_at' => $grace['grace_ends_at']
        ];
    }

    public function cancelGracePeriod(int $userId): bool {
        try {
            $this->db->prepare("
                UPDATE grace_periods
                SET downgrade_executed = TRUE
                WHERE user_id = ? AND downgrade_executed = FALSE
            ")->execute([$userId]);

            $this->db->prepare("
                UPDATE users
                SET plan_status = 'active'
                WHERE id = ?
            ")->execute([$userId]);

            require_once __DIR__ . '/Analytics.php';
            $analytics = new Analytics($this->db);
            $analytics->trackEvent('grace_period_cancelled', 'billing', $userId, [
                'reason' => 'user_upgraded'
            ]);

            require_once __DIR__ . '/AuditLogger.php';
            $auditLogger = new AuditLogger($this->db);
            $auditLogger->setUserId($userId);
            $auditLogger->log('grace_period_cancelled', 'billing', 'success', [
                'target_type' => 'plan',
                'target_id' => $userId,
                'request_data' => [
                    'reason' => 'user_upgraded'
                ]
            ]);

            return true;
        } catch (Exception $e) {
            error_log("Cancel grace period error: " . $e->getMessage());
            return false;
        }
    }

    public function processExpiredGracePeriods(): int {
        try {
            $stmt = $this->db->prepare("
                SELECT g.*, u.email, u.username
                FROM grace_periods g
                INNER JOIN users u ON u.id = g.user_id
                WHERE g.grace_ends_at <= NOW()
                AND g.downgrade_executed = FALSE
            ");
            $stmt->execute();
            $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $count = 0;
            foreach ($expired as $grace) {
                if ($this->executeDowngrade($grace['user_id'], $grace['original_plan'], $grace['target_plan'])) {
                    $this->db->prepare("
                        UPDATE grace_periods
                        SET downgrade_executed = TRUE
                        WHERE id = ?
                    ")->execute([$grace['id']]);

                    $count++;
                }
            }

            return $count;
        } catch (Exception $e) {
            error_log("Process expired grace periods error: " . $e->getMessage());
            return 0;
        }
    }

    public function executeDowngrade(int $userId, string $fromPlan, string $toPlan): bool {
        try {
            $this->db->prepare("
                UPDATE users
                SET plan = ?,
                    plan_status = 'active',
                    plan_expires_at = NULL
                WHERE id = ?
            ")->execute([$toPlan, $userId]);

            $this->logPlanChange($userId, $fromPlan, $toPlan, 'downgrade', 0, 0, 'grace_period_expired');

            require_once __DIR__ . '/Analytics.php';
            $analytics = new Analytics($this->db);
            $analytics->trackEvent('plan_downgraded', 'billing', $userId, [
                'from_plan' => $fromPlan,
                'to_plan' => $toPlan,
                'reason' => 'grace_period_expired'
            ]);

            // Send email notification about downgrade
            require_once __DIR__ . '/EmailSystem.php';
            $userStmt = $this->db->prepare("SELECT name, email FROM users WHERE id = ?");
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $emailSystem = new EmailSystem($this->db);
                $emailSystem->sendFromTemplate('plan_expired', $user['email'], $user['name'], [
                    'user_name' => $user['name'],
                    'plan_name' => ucfirst($fromPlan) . ' Plan'
                ], $userId, 4);
            }

            return true;
        } catch (Exception $e) {
            error_log("Execute downgrade error: " . $e->getMessage());
            return false;
        }
    }

    public function calculateProration(int $userId, string $fromPlan, string $toPlan): array {
        try {
            $user = $this->db->prepare("SELECT plan_expires_at FROM users WHERE id = ?");
            $user->execute([$userId]);
            $userData = $user->fetch(PDO::FETCH_ASSOC);

            if (!$userData || !$userData['plan_expires_at']) {
                return ['prorated_amount' => 0, 'days_remaining' => 0];
            }

            $expiresAt = strtotime($userData['plan_expires_at']);
            $now = time();
            $daysRemaining = max(0, ceil(($expiresAt - $now) / 86400));

            $planPrices = [
                'free' => 0,
                'starter' => 9,
                'pro' => 29,
                'agency' => 99
            ];

            $fromPrice = $planPrices[$fromPlan] ?? 0;
            $toPrice = $planPrices[$toPlan] ?? 0;

            if ($fromPrice === 0) {
                return ['prorated_amount' => 0, 'days_remaining' => $daysRemaining];
            }

            $dailyRate = $fromPrice / 30;
            $unusedCredit = $dailyRate * $daysRemaining;

            $isUpgrade = self::PLAN_HIERARCHY[$toPlan] > self::PLAN_HIERARCHY[$fromPlan];

            if ($isUpgrade) {
                $proratedAmount = max(0, $toPrice - $unusedCredit);
            } else {
                $proratedAmount = $unusedCredit;
            }

            return [
                'prorated_amount' => round($proratedAmount, 2),
                'days_remaining' => $daysRemaining,
                'unused_credit' => round($unusedCredit, 2),
                'is_upgrade' => $isUpgrade
            ];
        } catch (Exception $e) {
            error_log("Calculate proration error: " . $e->getMessage());
            return ['prorated_amount' => 0, 'days_remaining' => 0];
        }
    }

    public function upgradePlan(int $userId, string $fromPlan, string $toPlan, float $paidAmount): bool {
        try {
            $this->cancelGracePeriod($userId);

            $proration = $this->calculateProration($userId, $fromPlan, $toPlan);

            $this->db->prepare("
                UPDATE users
                SET plan = ?,
                    plan_status = 'active',
                    plan_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
                WHERE id = ?
            ")->execute([$toPlan, $userId]);

            $this->logPlanChange(
                $userId,
                $fromPlan,
                $toPlan,
                'upgrade',
                $proration['prorated_amount'],
                $proration['days_remaining'],
                'user_initiated'
            );

            require_once __DIR__ . '/Analytics.php';
            $analytics = new Analytics($this->db);
            $analytics->trackEvent('plan_upgraded', 'billing', $userId, [
                'from_plan' => $fromPlan,
                'to_plan' => $toPlan,
                'prorated_amount' => $proration['prorated_amount'],
                'days_remaining' => $proration['days_remaining']
            ]);

            require_once __DIR__ . '/QueryCache.php';
            invalidateUserCache($userId);

            return true;
        } catch (Exception $e) {
            error_log("Upgrade plan error: " . $e->getMessage());
            return false;
        }
    }

    public function downgradePlan(int $userId, string $fromPlan, string $toPlan): bool {
        try {
            $proration = $this->calculateProration($userId, $fromPlan, $toPlan);

            if ($proration['days_remaining'] > 0) {
                $this->startGracePeriod($userId, $fromPlan, $toPlan);
                return true;
            }

            return $this->executeDowngrade($userId, $fromPlan, $toPlan);
        } catch (Exception $e) {
            error_log("Downgrade plan error: " . $e->getMessage());
            return false;
        }
    }

    private function logPlanChange(
        int $userId,
        string $fromPlan,
        string $toPlan,
        string $changeType,
        float $proratedAmount,
        int $daysRemaining,
        ?string $reason
    ): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO plan_changes
                (user_id, from_plan, to_plan, change_type, prorated_amount, days_remaining, reason, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $userId,
                $fromPlan,
                $toPlan,
                $changeType,
                $proratedAmount,
                $daysRemaining,
                $reason
            ]);
        } catch (Exception $e) {
            error_log("Log plan change error: " . $e->getMessage());
        }
    }

    public function getPlanHistory(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM plan_changes
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function canUpgrade(int $userId, string $targetPlan): array {
        $user = $this->db->prepare("SELECT plan, plan_status FROM users WHERE id = ?");
        $user->execute([$userId]);
        $userData = $user->fetch(PDO::FETCH_ASSOC);

        if (!$userData) {
            return ['allowed' => false, 'reason' => 'User not found'];
        }

        $currentPierarchy = self::PLAN_HIERARCHY[$userData['plan']] ?? 0;
        $targetHierarchy = self::PLAN_HIERARCHY[$targetPlan] ?? 0;

        if ($targetHierarchy <= $currentHierarchy) {
            return ['allowed' => false, 'reason' => 'Target plan is not higher than current plan'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    public function canDowngrade(int $userId, string $targetPlan): array {
        $user = $this->db->prepare("SELECT plan, plan_status FROM users WHERE id = ?");
        $user->execute([$userId]);
        $userData = $user->fetch(PDO::FETCH_ASSOC);

        if (!$userData) {
            return ['allowed' => false, 'reason' => 'User not found'];
        }

        $currentHierarchy = self::PLAN_HIERARCHY[$userData['plan']] ?? 0;
        $targetHierarchy = self::PLAN_HIERARCHY[$targetPlan] ?? 0;

        if ($targetHierarchy >= $currentHierarchy) {
            return ['allowed' => false, 'reason' => 'Target plan is not lower than current plan'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    public function getUserPlanStatus(int $userId): array {
        $user = $this->db->prepare("
            SELECT plan, plan_status, plan_expires_at, created_at
            FROM users WHERE id = ?
        ");
        $user->execute([$userId]);
        $userData = $user->fetch(PDO::FETCH_ASSOC);

        if (!$userData) {
            return ['error' => 'User not found'];
        }

        $grace = $this->checkGracePeriod($userId);

        $daysUntilExpiry = null;
        if ($userData['plan_expires_at']) {
            $expiresAt = strtotime($userData['plan_expires_at']);
            $daysUntilExpiry = max(0, ceil(($expiresAt - time()) / 86400));
        }

        return [
            'plan' => $userData['plan'],
            'plan_status' => $userData['plan_status'],
            'plan_expires_at' => $userData['plan_expires_at'],
            'days_until_expiry' => $daysUntilExpiry,
            'in_grace_period' => $grace !== null,
            'grace_details' => $grace,
            'can_upgrade' => true,
            'can_downgrade' => $userData['plan'] !== 'free'
        ];
    }
}
