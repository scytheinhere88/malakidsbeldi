<?php

$totalFailed = 0;

$dbAvailable = !empty(PDO::getAvailableDrivers());

require_once __DIR__ . '/ApiResponseTest.php';

$suites = [new ApiResponseTest()];

if ($dbAvailable) {
    require_once __DIR__ . '/EmailSystemTest.php';
    require_once __DIR__ . '/SecurityManagerTest.php';
    require_once __DIR__ . '/PlanManagementTest.php';
    require_once __DIR__ . '/AuditLoggerTest.php';

    $suites[] = new EmailSystemTest();
    $suites[] = new SecurityManagerTest();
    $suites[] = new PlanManagementTest();
    $suites[] = new AuditLoggerTest();
} else {
    echo "\n[SKIP] Database-dependent test suites skipped (no PDO driver available)\n";
    echo "       To run all tests: ensure PHP has pdo_mysql extension installed.\n";
    echo "       Example: php -d extension=pdo_mysql tests/run_tests.php\n\n";
}

foreach ($suites as $suite) {
    $suite->run();
    $totalFailed += $suite->getFailed();
}

echo "\n";
echo str_repeat('=', 50) . "\n";
echo "TOTAL FAILED: {$totalFailed}\n";
echo str_repeat('=', 50) . "\n";

exit($totalFailed > 0 ? 1 : 0);
