<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/MonitoringMiddleware.php';
require_once dirname(__DIR__).'/includes/EmailSystem.php';
require_once dirname(__DIR__).'/includes/Analytics.php';
require_once dirname(__DIR__).'/includes/LicenseGenerator.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';
require_once dirname(__DIR__).'/includes/WebhookRetryQueue.php';

// ============================================
// GUMROAD WEBHOOK SECURITY
// ============================================
// Verify signature to prevent fake payment requests
// Documentation: https://help.gumroad.com/article/76-webhook-security

if (empty(GUMROAD_WEBHOOK_SECRET) || GUMROAD_WEBHOOK_SECRET === 'your-gumroad-webhook-secret-here') {
    error_log('CRITICAL: GUMROAD_WEBHOOK_SECRET not configured or using default value');
    http_response_code(503);
    header('Content-Type: application/json');
    die(json_encode([
        'success' => false,
        'error' => 'Webhook system not configured',
        'message' => 'Payment processing is temporarily unavailable'
    ]));
}

$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_GUMROAD_SIGNATURE'] ?? '';

if (empty($signature)) {
    error_log('Gumroad webhook: Missing signature header');
    http_response_code(403);
    exit('Missing signature');
}

$expectedSignature = hash_hmac('sha256', $rawBody, GUMROAD_WEBHOOK_SECRET);

if (!hash_equals($expectedSignature, $signature)) {
    error_log('Gumroad webhook: Invalid signature');
    $auditLogger = new AuditLogger(db());
    $auditLogger->log('webhook_failed', 'security', 'blocked', [
        'target_type' => 'webhook',
        'target_id' => 'gumroad',
        'error_message' => 'Invalid signature - potential security breach'
    ]);
    http_response_code(403);
    exit('Invalid signature');
}

// ============================================
// PROCESS WEBHOOK DATA
// ============================================
$data = $_POST;
$sale_id       = $data['sale_id'] ?? '';
$email         = strtolower(trim($data['email'] ?? ''));
$product_id    = $data['product_id'] ?? '';
$product_name  = $data['product_name'] ?? '';
$license_key   = $data['license_key'] ?? '';
$refunded      = filter_var($data['refunded'] ?? false, FILTER_VALIDATE_BOOLEAN);
$chargebacked  = filter_var($data['chargebacked'] ?? false, FILTER_VALIDATE_BOOLEAN);

// ============================================
// REFUND / CHARGEBACK HANDLER
// ============================================
if ($refunded || $chargebacked) {
    $pdo = db();
    $auditLogger = new AuditLogger($pdo);
    $emailSystem = new EmailSystem($pdo);
    $reason = $chargebacked ? 'chargeback' : 'refund';

    try {
        // Revoke license
        if ($license_key) {
            $pdo->prepare("UPDATE licenses SET status = 'revoked', revoked_at = NOW() WHERE gumroad_license = ? OR license_key = ?")
                ->execute([$license_key, $license_key]);
        }

        // Find user and downgrade to free
        $userStmt = $pdo->prepare("SELECT id, name, plan FROM users WHERE email = ? LIMIT 1");
        $userStmt->execute([$email]);
        $refundedUser = $userStmt->fetch();

        if ($refundedUser) {
            $oldPlan = $refundedUser['plan'];
            $pdo->prepare("UPDATE users SET plan = 'free', billing_cycle = 'none', plan_expires_at = NULL WHERE id = ?")
                ->execute([$refundedUser['id']]);

            $auditLogger->setUserId($refundedUser['id']);
            $auditLogger->logPlanChange($oldPlan, 'free', 'gumroad_' . $reason);
            $auditLogger->log('license_revoked', 'payment', 'success', [
                'target_type'  => 'license',
                'target_id'    => $license_key,
                'error_message'=> "Revoked due to {$reason}",
                'request_data' => ['sale_id' => $sale_id, 'email' => $email]
            ]);

            try {
                $emailSystem->sendFromTemplate('plan_expired', $email, $refundedUser['name'], [
                    'user_name'   => $refundedUser['name'],
                    'plan_name'   => ucfirst($oldPlan) . ' Plan',
                    'expiry_date' => date('F d, Y'),
                    'reason'      => $reason
                ], $refundedUser['id'], 2);
            } catch (Exception $emailEx) {
                error_log("Gumroad refund: failed to send revocation email - " . $emailEx->getMessage());
            }

            error_log("Gumroad {$reason}: downgraded user {$email} from {$oldPlan} to free, license revoked");
        } else {
            error_log("Gumroad {$reason}: user not found for email {$email}");
        }
    } catch (Exception $e) {
        error_log("Gumroad {$reason} handler error: " . $e->getMessage());
    }

    http_response_code(200);
    exit('ok');
}

if (!$email || (!$product_id && !$product_name) || !$license_key) {
    http_response_code(400);
    exit('Missing required fields');
}

// Use product_name if available (more reliable), fallback to product_id
$product_identifier = $product_name ?: $product_id;

// ============================================
// DETECT PLAN FROM PRODUCT ID
// ============================================
$plan = 'free';
$cycle = 'none';
$months = 1;
$amount = 0;

// Resolve product using centralized map from config.php
$resolved = resolveGumroadProduct($product_identifier)
    ?? resolveGumroadProduct($product_id)
    ?? resolveGumroadProduct($product_name);

$addonSlug = null;
if ($resolved) {
    $plan      = $resolved['plan'];
    $cycle     = $resolved['cycle'];
    $months    = $resolved['months'];
    $addonSlug = $resolved['addon'] ?? null;
}

if ($plan === 'free') {
    error_log("Gumroad webhook: Unknown product: {$product_identifier} (product_id: {$product_id}, product_name: {$product_name})");
    http_response_code(200);
    exit('ok');
}

$expires = $cycle === 'lifetime' ? null : date('Y-m-d H:i:s', strtotime("+{$months} months"));

$priceData = [
    'pro'      => ['monthly' => 29, 'annual' => 199, 'addon' => 29],
    'platinum' => ['monthly' => 79, 'annual' => 699],
    'lifetime' => ['lifetime' => 299]
];
$amount = $priceData[$plan][$cycle] ?? (float)str_replace(',', '', $data['price'] ?? '0');

// ============================================
// FIND OR CREATE USER
// ============================================
$pdo = db();
$monitor = MonitoringMiddleware::start($pdo, 'webhook_gumroad', null);
$emailSystem = new EmailSystem($pdo);
$analytics = new Analytics($pdo);
$licenseGen = new LicenseGenerator($pdo);
$auditLogger = new AuditLogger($pdo);

try {
    // Duplicate sale detection — idempotency guard
    if ($sale_id) {
        $dupCheck = $pdo->prepare("SELECT id FROM users WHERE gumroad_sale_id = ? LIMIT 1");
        $dupCheck->execute([$sale_id]);
        if ($dupCheck->fetch()) {
            error_log("Gumroad webhook: Duplicate sale_id {$sale_id} — already processed, skipping");
            $monitor->end(200);
            http_response_code(200);
            exit('ok');
        }
    }

    $us = $pdo->prepare("SELECT id, name, plan as old_plan FROM users WHERE email=?");
    $us->execute([$email]);
    $u = $us->fetch();

    $userId = null;
    $userName = $data['full_name'] ?? 'New User';
    $isNewUser = false;
    $oldPlan = 'free';

    // Generate custom license key
    $existingLicense = $licenseGen->checkExistingLicense($sale_id);
    $customLicenseKey = $existingLicense;

    if (!$existingLicense) {
        $customLicenseKey = $licenseGen->generateLicense($product_id, $sale_id, $email);

        $licenseGen->saveLicense(
            $customLicenseKey,
            $product_id,
            $sale_id,
            $email,
            [
                'product_name' => $product_name,
                'full_name' => $userName,
                'gumroad_license' => $license_key,
                'price' => $data['price'] ?? null,
                'currency' => $data['currency'] ?? 'USD'
            ]
        );

        error_log("Gumroad webhook: Generated license {$customLicenseKey} for sale {$sale_id}");
    } else {
        error_log("Gumroad webhook: Using existing license {$customLicenseKey} for sale {$sale_id}");
    }

    // Use transaction for atomic user/license update
    $pdo->beginTransaction();

    try {
        if ($u) {
            // Update existing user
            $userId = $u['id'];
            $userName = $u['name'];
            $oldPlan = $u['old_plan'] ?? 'free';
            $pdo->prepare("UPDATE users SET plan=?, billing_cycle=?, plan_expires_at=?, gumroad_license=?, gumroad_sale_id=? WHERE id=?")
                ->execute([$plan, $cycle, $expires, $license_key, $sale_id, $userId]);
            error_log("Gumroad webhook: Updated user {$email} to {$plan} plan");

            $auditLogger->setUserId($userId);
            $auditLogger->logPlanChange($oldPlan, $plan, 'gumroad_purchase');
            $auditLogger->logPayment($sale_id, $amount ?? 0, 'success', [
                'plan' => $plan,
                'cycle' => $cycle,
                'product' => $product_identifier,
                'license_key' => $license_key
            ]);
        } else {
            // Auto-create account
            $isNewUser = true;
            $pass = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users(name, email, password, plan, billing_cycle, plan_expires_at, gumroad_license, gumroad_sale_id, created_at) VALUES(?,?,?,?,?,?,?,?,NOW())")
                ->execute([$userName, $email, $pass, $plan, $cycle, $expires, $license_key, $sale_id]);
            $userId = (int)$pdo->lastInsertId();
            error_log("Gumroad webhook: Created new user {$email} with {$plan} plan");

            $auditLogger->setUserId($userId);
            $auditLogger->log('user_created_from_webhook', 'payment', 'success', [
                'target_type' => 'user',
                'target_id' => $userId,
                'request_data' => [
                    'email' => $email,
                    'plan' => $plan,
                    'sale_id' => $sale_id,
                    'gateway' => 'gumroad'
                ]
            ]);
            $auditLogger->logPayment($sale_id, $amount ?? 0, 'success', [
                'plan' => $plan,
                'cycle' => $cycle,
                'product' => $product_identifier,
                'is_new_user' => true
            ]);
        }

        // Activate addon if purchased
        if ($addonSlug && $userId) {
            try {
                // Get all slugs this addon unlocks (bundles unlock multiple)
                require_once dirname(__DIR__).'/config.php';
                $unlockedSlugs = getAddonSlugs($addonSlug);

                foreach ($unlockedSlugs as $slug) {
                    $checkAddon = $pdo->prepare("SELECT id FROM user_addons WHERE user_id=? AND addon_slug=?");
                    $checkAddon->execute([$userId, $slug]);

                    if (!$checkAddon->fetch()) {
                        $pdo->prepare("INSERT INTO user_addons(user_id, addon_slug, purchased_at, gumroad_sale_id) VALUES(?,?,NOW(),?)")
                            ->execute([$userId, $slug, $sale_id]);
                        error_log("Gumroad webhook: Activated addon '{$slug}' for user {$email}");

                        $auditLogger->log('addon_purchased', 'payment', 'success', [
                            'target_type' => 'addon',
                            'target_id' => $slug,
                            'request_data' => [
                                'sale_id' => $sale_id,
                                'gateway' => 'gumroad',
                                'product' => $product_identifier
                            ]
                        ]);
                    }
                }
            } catch (Exception $addonEx) {
                error_log("Gumroad webhook: Failed to activate addon - " . $addonEx->getMessage());
            }
        }

        // Auto-activate license if user was created/updated successfully
        if ($userId && $customLicenseKey) {
            try {
                $activationResult = $licenseGen->activateLicense($customLicenseKey, $userId, $email);
                if ($activationResult['success']) {
                    error_log("Gumroad webhook: Auto-activated license {$customLicenseKey} for user {$userId}");
                } else {
                    error_log("Gumroad webhook: License activation warning - " . ($activationResult['error'] ?? 'unknown'));
                }
            } catch (Exception $licEx) {
                error_log("Gumroad webhook: License activation failed - " . $licEx->getMessage());
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Gumroad webhook transaction failed: " . $e->getMessage());

        $auditLogger->log('webhook_transaction_failed', 'payment', 'error', [
            'target_type' => 'webhook',
            'target_id' => 'gumroad',
            'error_message' => $e->getMessage(),
            'request_data' => [
                'sale_id' => $sale_id,
                'email' => $email,
                'product' => $product_identifier
            ]
        ]);

        http_response_code(500);
        die(json_encode(['success' => false, 'error' => 'Database transaction failed']));
    }

    $analytics->trackConversion(
        $userId,
        'plan_purchase',
        $oldPlan,
        $plan,
        'gumroad',
        $amount,
        [
            'sale_id' => $sale_id,
            'product' => $product_identifier,
            'billing_cycle' => $cycle,
            'license_key' => $license_key
        ]
    );

    if ($isNewUser) {
        // Generate a one-time set-password token so new user can access their account
        $resetToken = bin2hex(random_bytes(32));
        $tokenExpiry = date('Y-m-d H:i:s', strtotime('+72 hours'));

        try {
            $pdo->prepare("
                INSERT INTO password_resets (email, token, expires_at, created_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at), created_at = NOW()
            ")->execute([$email, $resetToken, $tokenExpiry]);
        } catch (Exception $tokenEx) {
            error_log("Gumroad webhook: Failed to create reset token for new user - " . $tokenEx->getMessage());
        }

        $setPasswordUrl = APP_URL . '/auth/reset_password.php?token=' . $resetToken . '&email=' . urlencode($email);

        $emailSystem->sendFromTemplate('welcome', $email, $userName, [
            'user_name'        => $userName,
            'plan_name'        => ucfirst($plan) . ' Plan',
            'set_password_url' => $setPasswordUrl,
            'login_url'        => APP_URL . '/auth/login.php'
        ], $userId, 1);
    }

    $emailSystem->sendFromTemplate('license_delivery', $email, $userName, [
        'user_name' => $userName,
        'product_name' => $product_name,
        'license_key' => $customLicenseKey,
        'plan_name' => ucfirst($plan) . ' Plan',
        'login_url' => APP_URL . '/auth/login.php',
        'billing_url' => APP_URL . '/dashboard/billing.php',
        'is_new_user' => $isNewUser
    ], $userId, 3);

    $emailSystem->sendFromTemplate('invoice', $email, $userName, [
        'user_name' => $userName,
        'order_id' => $sale_id,
        'plan_name' => ucfirst($plan) . ' Plan',
        'amount' => '$' . number_format($amount, 2),
        'date' => date('F d, Y'),
        'billing_cycle' => ucfirst($cycle),
        'invoice_url' => APP_URL . '/dashboard/billing.php'
    ], $userId, 3, ['sale_id' => $sale_id, 'amount' => $amount, 'gateway' => 'gumroad']);

    $monitor->end(200);
    http_response_code(200);
    exit('ok');

} catch (Exception $e) {
    $retryQueue = new WebhookRetryQueue($pdo);

    $retryQueue->queue('gumroad', $rawBody, [
        'X-Gumroad-Signature' => $signature
    ]);

    if (isset($userId)) {
        $monitor->logPaymentFailure($userId, 'gumroad', [
            'sale_id' => $sale_id,
            'error_message' => $e->getMessage(),
            'product' => $product_identifier
        ]);
    }

    $monitor->handleError($e, 'high', $userId ?? null);
    $monitor->end(500, $e->getMessage());

    error_log("Gumroad webhook error (queued for retry): " . $e->getMessage());
    http_response_code(500);
    exit('Internal error');
}
