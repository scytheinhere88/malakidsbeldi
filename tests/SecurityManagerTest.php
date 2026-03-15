<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/SecurityManager.php';
require_once __DIR__ . '/../includes/AuditLogger.php';

class SecurityManagerTest {
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    private function assert(bool $condition, string $label): void {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$label}\n";
        } else {
            $this->failed++;
            $this->failures[] = $label;
            echo "  [FAIL] {$label}\n";
        }
    }

    private function makeSm(): SecurityManager {
        $pdo = createTestDb();
        return new SecurityManager($pdo);
    }

    public function testPasswordStrength_Valid(): void {
        echo "\n--- SecurityManager: valid password ---\n";
        $sm     = $this->makeSm();
        $result = $sm->validatePasswordStrength('StrongPass1!');
        $this->assert($result['valid'] === true, 'StrongPass1! is valid');
        $this->assert(empty($result['errors']), 'no errors for strong password');
    }

    public function testPasswordStrength_TooShort(): void {
        echo "\n--- SecurityManager: too short ---\n";
        $sm     = $this->makeSm();
        $result = $sm->validatePasswordStrength('Ab1!');
        $this->assert($result['valid'] === false, 'short password is invalid');
        $this->assert(!empty($result['errors']), 'errors returned for short password');
    }

    public function testPasswordStrength_NoUppercase(): void {
        echo "\n--- SecurityManager: missing uppercase ---\n";
        $sm     = $this->makeSm();
        $result = $sm->validatePasswordStrength('password1!');
        $this->assert($result['valid'] === false, 'no uppercase = invalid');
    }

    public function testPasswordStrength_NoNumber(): void {
        echo "\n--- SecurityManager: missing number ---\n";
        $sm     = $this->makeSm();
        $result = $sm->validatePasswordStrength('Password!');
        $this->assert($result['valid'] === false, 'no number = invalid');
    }

    public function testPasswordStrength_NoSpecial(): void {
        echo "\n--- SecurityManager: missing special char ---\n";
        $sm     = $this->makeSm();
        $result = $sm->validatePasswordStrength('Password1');
        $this->assert($result['valid'] === false, 'no special = invalid');
    }

    public function testPasswordHistory(): void {
        echo "\n--- SecurityManager: password history ---\n";

        $pdo = createTestDb();
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            password_hash TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        $sm       = new SecurityManager($pdo);
        $userId   = 1;
        $password = 'OldPassword1!';
        $hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);

        $pdo->prepare("INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)")
            ->execute([$userId, $hash]);

        $canUse = $sm->checkPasswordHistory($userId, $password);
        $this->assert($canUse === false, 'cannot reuse recent password');

        $canUseNew = $sm->checkPasswordHistory($userId, 'BrandNew1!');
        $this->assert($canUseNew === true, 'new password is allowed');
    }

    public function testAccountLockout(): void {
        echo "\n--- SecurityManager: account lockout ---\n";

        $pdo    = createTestDb();
        $sm     = new SecurityManager($pdo);
        $email  = 'victim@test.com';

        for ($i = 0; $i < 5; $i++) {
            $pdo->prepare("INSERT INTO login_attempts (email, status, failure_reason, created_at)
                           VALUES (?, 'failed', 'bad_password', datetime('now'))")
                ->execute([$email]);
        }

        $auditLogger = new AuditLogger($pdo);
        $locked = $auditLogger->isIPBlocked($email);
        $this->assert($locked === true, 'account is locked after 5 failed attempts');
    }

    public function testNotLockedBelowThreshold(): void {
        echo "\n--- SecurityManager: not locked under threshold ---\n";

        $pdo    = createTestDb();
        $sm     = new SecurityManager($pdo);
        $email  = 'safe@test.com';

        for ($i = 0; $i < 3; $i++) {
            $pdo->prepare("INSERT INTO login_attempts (email, status, failure_reason, created_at)
                           VALUES (?, 'failed', 'bad_password', datetime('now'))")
                ->execute([$email]);
        }

        $auditLogger = new AuditLogger($pdo);
        $locked = $auditLogger->isIPBlocked($email);
        $this->assert($locked === false, 'account is not locked with only 3 failures');
    }

    public function run(): void {
        echo "=== SecurityManagerTest ===\n";
        $this->testPasswordStrength_Valid();
        $this->testPasswordStrength_TooShort();
        $this->testPasswordStrength_NoUppercase();
        $this->testPasswordStrength_NoNumber();
        $this->testPasswordStrength_NoSpecial();
        $this->testPasswordHistory();
        $this->testAccountLockout();
        $this->testNotLockedBelowThreshold();
        $this->printSummary();
    }

    private function printSummary(): void {
        echo "\nPassed: {$this->passed} | Failed: {$this->failed}\n";
        if (!empty($this->failures)) {
            echo "Failures:\n";
            foreach ($this->failures as $f) echo "  - {$f}\n";
        }
    }

    public function getFailed(): int { return $this->failed; }
}
