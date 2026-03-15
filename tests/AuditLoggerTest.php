<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/AuditLogger.php';

class AuditLoggerTest {
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

    private function makeLogger(): array {
        $pdo    = createTestDb();
        $logger = new AuditLogger($pdo);
        return [$pdo, $logger];
    }

    public function testLog(): void {
        echo "\n--- AuditLogger::log ---\n";

        [$pdo, $logger] = $this->makeLogger();
        $logger->setUserId(42);

        $result = $logger->log('login', 'auth', 'success', [
            'target_type' => 'user',
            'target_id'   => 42,
        ]);

        $this->assert($result === true, 'log() returns true');

        $stmt = $pdo->query("SELECT * FROM audit_logs LIMIT 1");
        $row  = $stmt->fetch();

        $this->assert($row !== false, 'log entry exists in db');
        $this->assert($row['action_type'] === 'login', 'action_type correct');
        $this->assert($row['action_category'] === 'auth', 'action_category correct');
        $this->assert($row['status'] === 'success', 'status correct');
        $this->assert((int)$row['user_id'] === 42, 'user_id correct');
    }

    public function testLogAuth(): void {
        echo "\n--- AuditLogger::logAuth ---\n";

        [$pdo, $logger] = $this->makeLogger();
        $result = $logger->logAuth('login_attempt', 'user@example.com', 'failed', 'bad_password');

        $this->assert($result === true, 'logAuth returns true');

        $stmt = $pdo->query("SELECT * FROM login_attempts LIMIT 1");
        $row  = $stmt->fetch();

        $this->assert($row !== false, 'login_attempt entry exists');
        $this->assert($row['email'] === 'user@example.com', 'email recorded');
        $this->assert($row['status'] === 'failed', 'status is failed');
        $this->assert($row['failure_reason'] === 'bad_password', 'failure_reason correct');
    }

    public function testGetRecentFailedLogins(): void {
        echo "\n--- AuditLogger::getRecentFailedLogins ---\n";

        [$pdo, $logger] = $this->makeLogger();
        $email = 'attacker@test.com';

        $count0 = $logger->getRecentFailedLogins($email, 15);
        $this->assert($count0 === 0, 'starts at 0');

        for ($i = 0; $i < 3; $i++) {
            $logger->logAuth('login_attempt', $email, 'failed', 'bad_password');
        }

        $count3 = $logger->getRecentFailedLogins($email, 15);
        $this->assert($count3 === 3, 'counts 3 failed logins');
    }

    public function testIsIPBlocked(): void {
        echo "\n--- AuditLogger::isIPBlocked ---\n";

        [$pdo, $logger] = $this->makeLogger();
        $email = 'blocked@test.com';

        $this->assert($logger->isIPBlocked($email) === false, 'not blocked at start');

        for ($i = 0; $i < 5; $i++) {
            $logger->logAuth('login_attempt', $email, 'failed', 'bad_password');
        }

        $this->assert($logger->isIPBlocked($email) === true, 'blocked after 5 failures');
    }

    public function testMaskSensitiveData(): void {
        echo "\n--- AuditLogger: mask sensitive data ---\n";

        [$pdo, $logger] = $this->makeLogger();
        $logger->setUserId(1);

        $result = $logger->log('update', 'security', 'success', [
            'request_data' => [
                'password'   => 'secret123',
                'api_key'    => 'sk-abc',
                'email'      => 'user@test.com',
                'username'   => 'testuser',
            ]
        ]);

        $this->assert($result === true, 'log with sensitive data succeeds');

        $stmt = $pdo->query("SELECT request_data FROM audit_logs ORDER BY id DESC LIMIT 1");
        $row  = $stmt->fetch();
        $data = json_decode($row['request_data'], true);

        $this->assert($data['password'] === '***MASKED***', 'password is masked');
        $this->assert($data['api_key'] === '***MASKED***', 'api_key is masked');
        $this->assert($data['email'] === 'user@test.com', 'email is not masked');
        $this->assert($data['username'] === 'testuser', 'username is not masked');
    }

    public function testGetAuditLogs(): void {
        echo "\n--- AuditLogger::getAuditLogs ---\n";

        [$pdo, $logger] = $this->makeLogger();
        $logger->setUserId(10);

        for ($i = 0; $i < 3; $i++) {
            $logger->log('action_' . $i, 'test', 'success');
        }

        $all = $logger->getAuditLogs([], 100, 0);
        $this->assert(count($all) >= 3, 'returns at least 3 logs');

        $filtered = $logger->getAuditLogs(['user_id' => 10], 100, 0);
        $this->assert(count($filtered) >= 3, 'filter by user_id works');

        $none = $logger->getAuditLogs(['user_id' => 999], 100, 0);
        $this->assert(count($none) === 0, 'filter returns empty for unknown user');
    }

    public function run(): void {
        echo "=== AuditLoggerTest ===\n";
        $this->testLog();
        $this->testLogAuth();
        $this->testGetRecentFailedLogins();
        $this->testIsIPBlocked();
        $this->testMaskSensitiveData();
        $this->testGetAuditLogs();
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
