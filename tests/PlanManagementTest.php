<?php

require_once __DIR__ . '/bootstrap.php';

if (!defined('PLAN_DATA')) {
    define('PLAN_DATA', [
        'free'     => ['name'=>'Free',     'limit'=>20,   'rollover'=>false, 'pm'=>0,    'pa'=>0,     'pl'=>0,     'color'=>'#454568', 'badge'=>'', 'has_addons'=>false],
        'pro'      => ['name'=>'Pro',      'limit'=>500,  'rollover'=>true,  'pm'=>19.9, 'pa'=>190.9, 'pl'=>0,     'color'=>'#f0a500', 'badge'=>'Popular', 'has_addons'=>false],
        'platinum' => ['name'=>'Platinum', 'limit'=>1500, 'rollover'=>true,  'pm'=>69.9, 'pa'=>671.9, 'pl'=>0,     'color'=>'#00d4aa', 'badge'=>'Best Value', 'has_addons'=>true],
        'lifetime' => ['name'=>'Lifetime', 'limit'=>-1,   'rollover'=>true,  'pm'=>0,    'pa'=>0,     'pl'=>469.9, 'color'=>'#c084fc', 'badge'=>'Forever', 'has_addons'=>true],
    ]);
}

require_once __DIR__ . '/../includes/AuditLogger.php';
require_once __DIR__ . '/../includes/Analytics.php';
require_once __DIR__ . '/../includes/EmailSystem.php';
require_once __DIR__ . '/../includes/PlanManagement.php';

class PlanManagementTest {
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

    private function makePlanManagement(): array {
        $pdo = createTestDb();

        $pdo->exec("CREATE TABLE IF NOT EXISTS analytics_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_type TEXT NOT NULL,
            event_category TEXT NOT NULL,
            user_id INTEGER NULL,
            properties TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS email_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            template_key TEXT UNIQUE NOT NULL,
            subject TEXT NOT NULL,
            body_html TEXT NOT NULL,
            body_text TEXT DEFAULT '',
            is_active INTEGER DEFAULT 1
        )");

        $pm = new PlanManagement($pdo);
        return [$pdo, $pm];
    }

    private function createUser(PDO $pdo, string $plan = 'pro', ?string $expiresAt = null): int {
        $pdo->prepare("INSERT INTO users (name, email, password, plan, plan_status, plan_expires_at) VALUES (?, ?, ?, ?, 'active', ?)")
            ->execute(['Test User', 'test' . uniqid() . '@test.com', 'hash', $plan, $expiresAt]);
        return (int)$pdo->lastInsertId();
    }

    public function testPlanHierarchy(): void {
        echo "\n--- PlanManagement: plan hierarchy ---\n";

        $hierarchy = PlanManagement::PLAN_HIERARCHY;
        $this->assert($hierarchy['free'] < $hierarchy['pro'], 'free < pro');
        $this->assert($hierarchy['pro'] < $hierarchy['platinum'], 'pro < platinum');
        $this->assert($hierarchy['platinum'] < $hierarchy['lifetime'], 'platinum < lifetime');
    }

    public function testCanUpgrade(): void {
        echo "\n--- PlanManagement::canUpgrade ---\n";

        [$pdo, $pm] = $this->makePlanManagement();
        $userId = $this->createUser($pdo, 'free');

        $result = $pm->canUpgrade($userId, 'pro');
        $this->assert($result['allowed'] === true, 'free user can upgrade to pro');

        $result2 = $pm->canUpgrade($userId, 'free');
        $this->assert($result2['allowed'] === false, 'free user cannot upgrade to free');
    }

    public function testCanDowngrade(): void {
        echo "\n--- PlanManagement::canDowngrade ---\n";

        [$pdo, $pm] = $this->makePlanManagement();
        $userId = $this->createUser($pdo, 'pro');

        $result = $pm->canDowngrade($userId, 'free');
        $this->assert($result['allowed'] === true, 'pro user can downgrade to free');

        $result2 = $pm->canDowngrade($userId, 'platinum');
        $this->assert($result2['allowed'] === false, 'pro user cannot downgrade to platinum');
    }

    public function testGracePeriod(): void {
        echo "\n--- PlanManagement: grace period ---\n";

        [$pdo, $pm] = $this->makePlanManagement();
        $userId = $this->createUser($pdo, 'pro');

        $started = $pm->startGracePeriod($userId, 'pro', 'free');
        $this->assert($started === true, 'grace period started');

        $grace = $pm->checkGracePeriod($userId);
        $this->assert($grace !== null, 'checkGracePeriod returns data');
        $this->assert($grace['active'] === true, 'grace is active');
        $this->assert($grace['original_plan'] === 'pro', 'original plan is pro');
        $this->assert($grace['target_plan'] === 'free', 'target plan is free');
        $this->assert($grace['days_remaining'] >= 6, 'days remaining >= 6');

        $duplicate = $pm->startGracePeriod($userId, 'pro', 'free');
        $this->assert($duplicate === false, 'cannot start duplicate grace period');
    }

    public function testCancelGracePeriod(): void {
        echo "\n--- PlanManagement::cancelGracePeriod ---\n";

        [$pdo, $pm] = $this->makePlanManagement();
        $userId = $this->createUser($pdo, 'pro');

        $pm->startGracePeriod($userId, 'pro', 'free');
        $cancelled = $pm->cancelGracePeriod($userId);

        $this->assert($cancelled === true, 'grace period cancelled');

        $grace = $pm->checkGracePeriod($userId);
        $this->assert($grace === null, 'no active grace period after cancel');
    }

    public function testExecuteDowngrade(): void {
        echo "\n--- PlanManagement::executeDowngrade ---\n";

        [$pdo, $pm] = $this->makePlanManagement();
        $userId = $this->createUser($pdo, 'pro');

        $pdo->prepare("INSERT INTO user_addons (user_id, addon_slug) VALUES (?, 'csv-generator-pro')")
            ->execute([$userId]);

        $result = $pm->executeDowngrade($userId, 'pro', 'free');
        $this->assert($result === true, 'downgrade executed successfully');

        $stmt = $pdo->prepare("SELECT plan, plan_status FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $this->assert($user['plan'] === 'free', 'user plan is now free');
        $this->assert($user['plan_status'] === 'active', 'plan_status is active');

        $addonStmt = $pdo->prepare("SELECT COUNT(*) as c FROM user_addons WHERE user_id = ?");
        $addonStmt->execute([$userId]);
        $count = (int)$addonStmt->fetch()['c'];
        $this->assert($count === 0, 'addons revoked on free downgrade');
    }

    public function testGetPlanHistory(): void {
        echo "\n--- PlanManagement::getPlanHistory ---\n";

        [$pdo, $pm] = $this->makePlanManagement();
        $userId = $this->createUser($pdo, 'free');

        $history = $pm->getPlanHistory($userId);
        $this->assert(is_array($history), 'getPlanHistory returns array');
        $this->assert(count($history) === 0, 'empty history for new user');

        $pm->executeDowngrade($userId, 'pro', 'free');

        $history2 = $pm->getPlanHistory($userId);
        $this->assert(count($history2) >= 1, 'history has entries after downgrade');
    }

    public function testGetUserPlanStatus(): void {
        echo "\n--- PlanManagement::getUserPlanStatus ---\n";

        [$pdo, $pm] = $this->makePlanManagement();
        $userId = $this->createUser($pdo, 'platinum');

        $status = $pm->getUserPlanStatus($userId);
        $this->assert($status['plan'] === 'platinum', 'plan is platinum');
        $this->assert($status['plan_status'] === 'active', 'plan_status active');
        $this->assert($status['in_grace_period'] === false, 'not in grace period');
        $this->assert($status['can_downgrade'] === true, 'can downgrade');
    }

    public function run(): void {
        echo "=== PlanManagementTest ===\n";
        $this->testPlanHierarchy();
        $this->testCanUpgrade();
        $this->testCanDowngrade();
        $this->testGracePeriod();
        $this->testCancelGracePeriod();
        $this->testExecuteDowngrade();
        $this->testGetPlanHistory();
        $this->testGetUserPlanStatus();
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
