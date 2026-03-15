<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/ApiResponse.php';
require_once dirname(__DIR__).'/includes/EnhancedRateLimiter.php';
require_once dirname(__DIR__).'/includes/SystemMonitor.php';
require_once dirname(__DIR__).'/includes/Analytics.php';
require_once dirname(__DIR__).'/includes/EmailSystem.php';
require_once dirname(__DIR__).'/includes/LicenseGenerator.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';

ss();
if (!isLoggedIn()) {
    ApiResponse::unauthorized();
}

ApiResponse::requirePostMethod();

$pdo     = db();
$monitor = new SystemMonitor($pdo);
$limiter = new EnhancedRateLimiter($pdo, $monitor);

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

$planStmt = $pdo->prepare("SELECT plan FROM users WHERE id=? LIMIT 1");
$planStmt->execute([$_SESSION['uid']]);
$planRow  = $planStmt->fetch();
$userTier = in_array($planRow['plan'] ?? 'free', ['free','pro','platinum','lifetime']) ? ($planRow['plan'] ?? 'free') : 'free';

$rateCheck = $limiter->check($ip, 'license_activation', $userTier, $_SESSION['uid']);

if (!$rateCheck['allowed']) {
    ApiResponse::rateLimited($rateCheck['retry_after'] ?? 300);
}

$input      = ApiResponse::parseJsonBody(true);
$licenseKey = trim($input['license_key'] ?? '');

if (empty($licenseKey)) {
    ApiResponse::validationError(['license_key' => 'License key is required']);
}

try {
    $userStmt = $pdo->prepare("SELECT id, email, name, plan FROM users WHERE id=?");
    $userStmt->execute([$_SESSION['uid']]);
    $user = $userStmt->fetch();

    if (!$user) {
        ApiResponse::notFound('User not found');
    }

    $licenseGen  = new LicenseGenerator($pdo);
    $auditLogger = new AuditLogger($pdo);
    $oldPlan     = $user['plan'] ?? 'free';

    // Delegate to LicenseGenerator — supports both custom PRO-M keys AND Gumroad UUID keys
    $result = $licenseGen->activateLicense($licenseKey, $user['id'], $user['email']);

    if (!$result['success']) {
        ApiResponse::error($result['error'] ?? 'Activation failed', 400, 'ACTIVATION_FAILED');
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

    ApiResponse::success([
        'message'            => $result['message'] ?? 'License activated successfully!',
        'plan'               => $newPlan,
        'cycle'              => $cycle,
        'expires_at'         => $expires,
        'product_name'       => $result['product'] ?? ucfirst($newPlan) . ' Plan',
        'system_license_key' => $result['system_license_key'] ?? null,
    ]);

} catch (Exception $e) {
    error_log("License activation error: " . $e->getMessage());
    ApiResponse::serverError('An unexpected error occurred');
}
