<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/EmailSystem.php';

class EmailSystemTest {
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

    private function makeEmailSystem(): EmailSystem {
        $pdo = createTestDb();
        $_ENV['EMAIL_FROM']      = 'noreply@test.com';
        $_ENV['EMAIL_FROM_NAME'] = 'TestApp';
        return new EmailSystem($pdo);
    }

    public function testQueueEmail(): void {
        echo "\n--- EmailSystem::queueEmail ---\n";

        $pdo = createTestDb();
        $_ENV['EMAIL_FROM']      = 'noreply@test.com';
        $_ENV['EMAIL_FROM_NAME'] = 'TestApp';
        $es = new EmailSystem($pdo);

        $result = $es->queueEmail([
            'to_email'  => 'user@example.com',
            'to_name'   => 'Test User',
            'subject'   => 'Hello',
            'body_html' => '<p>Hello world</p>',
        ]);

        $this->assert($result === true, 'queueEmail returns true on success');

        $stmt = $pdo->query("SELECT * FROM email_queue LIMIT 1");
        $row  = $stmt->fetch();

        $this->assert($row !== false, 'email exists in queue');
        $this->assert($row['to_email'] === 'user@example.com', 'to_email correct');
        $this->assert($row['status'] === 'pending', 'status is pending');
        $this->assert($row['attempts'] === 0 || $row['attempts'] === '0', 'attempts = 0');
    }

    public function testSendFromTemplate_MissingTemplate(): void {
        echo "\n--- EmailSystem::sendFromTemplate (missing template) ---\n";

        $pdo = createTestDb();
        $_ENV['EMAIL_FROM']      = 'noreply@test.com';
        $_ENV['EMAIL_FROM_NAME'] = 'TestApp';
        $es = new EmailSystem($pdo);

        $result = $es->sendFromTemplate(
            'nonexistent_template',
            'user@example.com',
            'Test User',
            ['user_name' => 'Test'],
            null
        );

        $this->assert($result === false, 'returns false when template not found');
    }

    public function testSendFromTemplate_WithTemplate(): void {
        echo "\n--- EmailSystem::sendFromTemplate (with template) ---\n";

        $pdo = createTestDb();
        $_ENV['EMAIL_FROM']      = 'noreply@test.com';
        $_ENV['EMAIL_FROM_NAME'] = 'TestApp';

        $pdo->prepare("INSERT INTO email_templates (template_key, subject, body_html, body_text, is_active)
                        VALUES (?, ?, ?, ?, 1)")->execute([
            'welcome',
            'Welcome {{user_name}}!',
            '<p>Hi {{user_name}}, welcome to {{app_name}}</p>',
            'Hi {{user_name}}, welcome!'
        ]);

        $es = new EmailSystem($pdo);

        $result = $es->sendFromTemplate(
            'welcome',
            'user@example.com',
            'Test User',
            ['user_name' => 'John'],
            null
        );

        $this->assert($result === true, 'returns true when template found');

        $stmt = $pdo->query("SELECT * FROM email_queue WHERE template_key='welcome' LIMIT 1");
        $row  = $stmt->fetch();

        $this->assert($row !== false, 'email was queued');
        $this->assert(strpos($row['subject'], 'John') !== false, 'subject contains interpolated name');
        $this->assert(strpos($row['body_html'], 'TestApp') !== false, 'body_html contains app_name');
    }

    public function testXssProtectionInTemplate(): void {
        echo "\n--- EmailSystem: XSS protection ---\n";

        $pdo = createTestDb();
        $_ENV['EMAIL_FROM']      = 'noreply@test.com';
        $_ENV['EMAIL_FROM_NAME'] = 'TestApp';

        $pdo->prepare("INSERT INTO email_templates (template_key, subject, body_html, body_text, is_active)
                        VALUES (?, ?, ?, ?, 1)")->execute([
            'xss_test',
            'Test {{user_name}}',
            '<p>Hello {{user_name}}</p>',
            'Hello {{user_name}}'
        ]);

        $es = new EmailSystem($pdo);
        $es->sendFromTemplate(
            'xss_test',
            'user@example.com',
            'Test',
            ['user_name' => '<script>alert("xss")</script>'],
            null
        );

        $stmt = $pdo->query("SELECT body_html FROM email_queue WHERE template_key='xss_test' LIMIT 1");
        $row  = $stmt->fetch();

        $this->assert($row !== false, 'email was queued');
        $this->assert(strpos($row['body_html'], '<script>') === false, 'raw <script> tag is escaped');
        $this->assert(strpos($row['body_html'], '&lt;script&gt;') !== false, 'XSS is properly HTML-encoded');
    }

    public function testUnsubscribeHonoured(): void {
        echo "\n--- EmailSystem: unsubscribe respected ---\n";

        $pdo = createTestDb();
        $_ENV['EMAIL_FROM']      = 'noreply@test.com';
        $_ENV['EMAIL_FROM_NAME'] = 'TestApp';

        $pdo->exec("INSERT INTO users (name, email, password) VALUES ('Bob', 'bob@example.com', 'hash')");
        $userId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO email_preferences (user_id, unsubscribed_at) VALUES (?, datetime('now'))")
            ->execute([$userId]);

        $pdo->prepare("INSERT INTO email_templates (template_key, subject, body_html, is_active)
                        VALUES (?, ?, ?, 1)")->execute([
            'welcome',
            'Welcome {{user_name}}',
            '<p>Hi {{user_name}}</p>'
        ]);

        $es = new EmailSystem($pdo);
        $result = $es->sendFromTemplate('welcome', 'bob@example.com', 'Bob', ['user_name' => 'Bob'], $userId);

        $this->assert($result === false, 'returns false for unsubscribed user');

        $stmt = $pdo->query("SELECT COUNT(*) as c FROM email_queue WHERE to_email = 'bob@example.com'");
        $count = (int)$stmt->fetch()['c'];
        $this->assert($count === 0, 'no email queued for unsubscribed user');
    }

    public function testGetQueueStats(): void {
        echo "\n--- EmailSystem::getQueueStats ---\n";

        $pdo = createTestDb();
        $_ENV['EMAIL_FROM']      = 'noreply@test.com';
        $_ENV['EMAIL_FROM_NAME'] = 'TestApp';
        $es = new EmailSystem($pdo);

        $es->queueEmail(['to_email' => 'a@a.com', 'subject' => 'S1', 'body_html' => 'H1']);
        $es->queueEmail(['to_email' => 'b@b.com', 'subject' => 'S2', 'body_html' => 'H2']);

        $stats = $es->getQueueStats();

        $this->assert(is_array($stats), 'getQueueStats returns array');
        $pendingRow = array_filter($stats, fn($r) => $r['status'] === 'pending');
        $this->assert(!empty($pendingRow), 'pending status row exists');
        $pendingRow = array_values($pendingRow)[0];
        $this->assert((int)$pendingRow['count'] === 2, 'pending count = 2');
    }

    public function run(): void {
        echo "=== EmailSystemTest ===\n";
        $this->testQueueEmail();
        $this->testSendFromTemplate_MissingTemplate();
        $this->testSendFromTemplate_WithTemplate();
        $this->testXssProtectionInTemplate();
        $this->testUnsubscribeHonoured();
        $this->testGetQueueStats();
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
