<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/EnhancedRateLimiter.php';
require_once dirname(__DIR__).'/includes/SystemMonitor.php';
require_once dirname(__DIR__).'/includes/Analytics.php';
require_once dirname(__DIR__).'/includes/EmailSystem.php';
require_once dirname(__DIR__).'/includes/LicenseGenerator.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';

header('Content-Type: application/json');

ss();
if (!isLoggedIn()) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'Not authenticated']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

$pdo     = db();
$monitor = new SystemMonitor($pdo);
$limiter = new EnhancedRateLimiter($pdo, $monitor);

$ip        = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateCheck = $limiter->check($ip, 'license_activation', 'free', $_SESSION['uid']);

if (!$rateCheck['allowed']) {
    http_response_code(429);
    die(json_encode(['success' => false, 'error' => 'Too many activation attempts. Try again in 5 minutes.']));
}

$input      = json_decode(file_get_contents('php://input'), true);
$licenseKey = trim($input['license_key'] ?? '');

if (empty($licenseKey)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'License key is required']));
}

try {
    $userStmt = $pdo->prepare("SELECT id, email, name, plan FROM users WHERE id=?");
    $userStmt->execute([$_SESSION['uid']]);
    $user = $userStmt->fetch();

    if (!$user) {
        http_response_code(404);
        die(json_encode(['success' => false, 'error' => 'User not found']));
    }

    $licenseGen  = new LicenseGenerator($pdo);
    $auditLogger = new AuditLogger($pdo);
    $oldPlan     = $user['plan'] ?? 'free';

    // Delegate to LicenseGenerator — supports both custom PRO-M keys AND Gumroad UUID keys
    $result = $licenseGen->activateLicense($licenseKey, $user['id'], $user['email']);

    if (!$result['success']) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => $result['error'] ?? 'Activation failed']));
    }

    // Fetch updated user plan
    $updatedStmt = $pdo->prepare("SELECT plan, billing_cycle, plan_expires_at FROM users WHERE id=?");
    $updatedStmt->execute([$user['id']]);
    $updated = $updatedStmt->fetch();

    $newPlan = $updated['plan'] ?? $oldPlan;
    $cycle   = $updated['billing_cycle'] ?? 'none';
    $expires = $updated['plan_expires_at'] ?? null;

    // Analytics
    $priceData = [
        'pro'      => ['monthly' => 29, 'annual' => 199, 'addon' => 29],
        'platinum' => ['monthly' => 79, 'annual' => 699],
        'lifetime' => ['lifetime' => 299]
    ];
    $amount = $priceData[$newPlan][$cycle] ?? 0;

    $analytics = new Analytics($pdo);
    $analytics->trackConversion(
        $user['id'],
        'license_activation',
        $oldPlan,
        $newPlan,
        'gumroad',
        $amount,
        ['license_key' => $licenseKey, 'billing_cycle' => $cycle]
    );

    $auditLogger->setUserId($user['id']);
    $auditLogger->logPlanChange($oldPlan, $newPlan, 'license_activation');

    // Send invoice email
    $emailSystem = new EmailSystem($pdo);
    $emailSystem->sendFromTemplate('invoice', $user['email'], $user['name'], [
        'user_name'     => $user['name'],
        'order_id'      => $licenseKey,
        'plan_name'     => ucfirst($newPlan) . ' Plan',
        'amount'        => '$' . number_format($amount, 2),
        'date'          => date('F d, Y'),
        'billing_cycle' => ucfirst($cycle),
        'invoice_url'   => APP_URL . '/dashboard/billing.php'
    ], $user['id'], 3);

    echo json_encode([
        'success'            => true,
        'message'            => $result['message'] ?? 'License activated successfully!',
        'plan'               => $newPlan,
        'cycle'              => $cycle,
        'expires_at'         => $expires,
        'product_name'       => $result['product'] ?? ucfirst($newPlan) . ' Plan',
        'system_license_key' => $result['system_license_key'] ?? null,
    ]);

} catch (Exception $e) {
    error_log("License activation error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'An unexpected error occurred']));
}
