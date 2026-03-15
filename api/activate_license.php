<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/EnhancedRateLimiter.php';
require_once dirname(__DIR__).'/includes/SystemMonitor.php';
require_once dirname(__DIR__).'/includes/Analytics.php';
require_once dirname(__DIR__).'/includes/EmailSystem.php';

header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'Not authenticated']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

$pdo = db();
$monitor = new SystemMonitor($pdo);
$limiter = new EnhancedRateLimiter($pdo, $monitor);

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateCheck = $limiter->check($ip, 'license_activation', 'free', $_SESSION['user_id']);

if (!$rateCheck['allowed']) {
    http_response_code(429);
    die(json_encode(['success' => false, 'error' => 'Too many activation attempts. Try again in 5 minutes.']));
}

$input = json_decode(file_get_contents('php://input'), true);
$licenseKey = trim($input['license_key'] ?? '');
$productId = trim($input['product_id'] ?? '');

if (empty($licenseKey)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'License key is required']));
}

if (empty($productId)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Product ID is required']));
}

try {
    $userStmt = $pdo->prepare("SELECT id, email, name, plan FROM users WHERE id=?");
    $userStmt->execute([$_SESSION['user_id']]);
    $user = $userStmt->fetch();

    if (!$user) {
        http_response_code(404);
        die(json_encode(['success' => false, 'error' => 'User not found']));
    }

    // Check if license exists in our database
    $licenseStmt = $pdo->prepare("
        SELECT l.*, p.product_name, p.product_type, p.billing_cycle, p.plan_level
        FROM licenses l
        LEFT JOIN product_mappings p ON l.product_slug = p.product_slug
        WHERE l.license_key = ?
    ");
    $licenseStmt->execute([$licenseKey]);
    $license = $licenseStmt->fetch();

    if (!$license) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'Invalid license key']));
    }

    // Check if license is already activated by another user
    if ($license['user_id'] && $license['user_id'] != $user['id']) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'This license key is already in use by another account']));
    }

    // Check if license is revoked
    if ($license['status'] === 'revoked') {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'This license has been revoked']));
    }

    // Check if license is expired
    if ($license['status'] === 'expired') {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'This license has expired']));
    }

    $productName = $license['product_name'] ?? 'Unknown Product';
    $email = strtolower(trim($license['email'] ?? ''));
    $saleId = $license['sale_id'] ?? '';
    $productSlug = $license['product_slug'] ?? '';

    $productMap = [
        'pro-monthly-plan' => ['plan' => 'pro', 'cycle' => 'monthly', 'months' => 1],
        'platinum-monthly-plan' => ['plan' => 'platinum', 'cycle' => 'monthly', 'months' => 1],
        'pro-yearly-plan' => ['plan' => 'pro', 'cycle' => 'annual', 'months' => 12],
        'platinum-yearly-plan' => ['plan' => 'platinum', 'cycle' => 'annual', 'months' => 12],
        'lifetime-access-plan' => ['plan' => 'lifetime', 'cycle' => 'lifetime', 'months' => 9999],
        'csv-generator-addon' => ['plan' => 'pro', 'cycle' => 'addon', 'months' => 1, 'addon' => 'csv-generator-pro'],
        'zip-manager-addon' => ['plan' => 'pro', 'cycle' => 'addon', 'months' => 1, 'addon' => 'zip-manager'],
        'copy-rename-addon' => ['plan' => 'pro', 'cycle' => 'addon', 'months' => 1, 'addon' => 'copy-rename'],
        'ai-autopilot-bundle' => ['plan' => 'pro', 'cycle' => 'addon', 'months' => 1, 'addon' => 'autopilot'],
        'all-in-one-bundle' => ['plan' => 'platinum', 'cycle' => 'addon', 'months' => 3, 'addon' => 'premium-bundle']
    ];

    $plan = 'free';
    $cycle = 'none';
    $months = 1;
    $addonSlug = null;

    if (isset($productMap[$productSlug])) {
        $config = $productMap[$productSlug];
        $plan = $config['plan'];
        $cycle = $config['cycle'];
        $months = $config['months'];
        $addonSlug = $config['addon'] ?? null;
    } else {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'Unknown product. Please contact support.']));
    }

    $expires = $cycle === 'lifetime' ? null : date('Y-m-d H:i:s', strtotime("+{$months} months"));

    $pdo->beginTransaction();
    try {
        $oldPlan = $user['plan'] ?? 'free';

        // Update user plan
        $updateStmt = $pdo->prepare("
            UPDATE users
            SET plan=?, billing_cycle=?, plan_expires_at=?, gumroad_license=?, gumroad_sale_id=?
            WHERE id=?
        ");
        $updateStmt->execute([$plan, $cycle, $expires, $licenseKey, $saleId, $user['id']]);

        // Update license status to active
        $updateLicenseStmt = $pdo->prepare("
            UPDATE licenses
            SET status='active', user_id=?, activated_at=NOW()
            WHERE license_key=?
        ");
        $updateLicenseStmt->execute([$user['id'], $licenseKey]);

        if ($addonSlug) {
            require_once dirname(__DIR__).'/config.php';
            $unlockedSlugs = getAddonSlugs($addonSlug);

            foreach ($unlockedSlugs as $slug) {
                $checkAddon = $pdo->prepare("SELECT id FROM user_addons WHERE user_id=? AND addon_slug=?");
                $checkAddon->execute([$user['id'], $slug]);

                if (!$checkAddon->fetch()) {
                    $pdo->prepare("INSERT INTO user_addons(user_id, addon_slug, purchased_at, gumroad_sale_id) VALUES(?,?,NOW(),?)")
                        ->execute([$user['id'], $slug, $saleId]);
                }
            }
        }

        $pdo->commit();

        $analytics = new Analytics($pdo);
        $priceData = [
            'pro' => ['monthly' => 29, 'annual' => 199, 'addon' => 29],
            'platinum' => ['monthly' => 79, 'annual' => 699],
            'lifetime' => ['lifetime' => 299]
        ];
        $amount = $priceData[$plan][$cycle] ?? 0;

        $analytics->trackConversion(
            $user['id'],
            'license_activation',
            $oldPlan,
            $plan,
            'gumroad',
            $amount,
            [
                'sale_id' => $saleId,
                'product_id' => $productId,
                'billing_cycle' => $cycle,
                'license_key' => $licenseKey
            ]
        );

        $emailSystem = new EmailSystem($pdo);
        $emailSystem->sendFromTemplate('invoice', $user['email'], $user['name'], [
            'user_name' => $user['name'],
            'order_id' => $saleId,
            'plan_name' => ucfirst($plan) . ' Plan',
            'amount' => '$' . number_format($amount, 2),
            'date' => date('F d, Y'),
            'billing_cycle' => ucfirst($cycle),
            'invoice_url' => APP_URL . '/dashboard/billing.php'
        ], $user['id'], 3, ['sale_id' => $saleId, 'amount' => $amount, 'gateway' => 'gumroad']);

        error_log("License activated: user={$user['id']}, plan={$plan}, license={$licenseKey}");

        echo json_encode([
            'success' => true,
            'message' => 'License activated successfully!',
            'plan' => $plan,
            'cycle' => $cycle,
            'expires_at' => $expires,
            'product_name' => $productName
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("License activation transaction failed: " . $e->getMessage());
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => 'Failed to activate license. Please try again.']));
    }

} catch (Exception $e) {
    error_log("License activation error: " . $e->getMessage());
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'An unexpected error occurred']));
}
