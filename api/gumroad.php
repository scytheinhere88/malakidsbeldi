<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/MonitoringMiddleware.php';
require_once dirname(__DIR__).'/includes/EmailSystem.php';
require_once dirname(__DIR__).'/includes/Analytics.php';
require_once dirname(__DIR__).'/includes/LicenseGenerator.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';
require_once dirname(__DIR__).'/includes/WebhookRetryQueue.php';

// ============================================
// GUMROAD PING / WEBHOOK SECURITY
// Gumroad sekarang pakai "Ping" bukan webhook.
// Ping mengirim POST data yang sama dengan webhook lama,
// tapi TANPA signature header.
//
// Token priority (most secure first):
//   1. X-Gumroad-Signature header (HMAC — webhook mode)
//   2. X-Ping-Token header (preferred — not logged)
//   3. ?token= query string (legacy — still supported but logs warning)
// ============================================

$configToken   = defined('GUMROAD_PING_TOKEN') ? GUMROAD_PING_TOKEN : '';
$signature     = $_SERVER['HTTP_X_GUMROAD_SIGNATURE'] ?? '';
$webhookSecret = defined('GUMROAD_WEBHOOK_SECRET') ? GUMROAD_WEBHOOK_SECRET : '';

// Prefer header-based token over query string to avoid server log exposure
$pingTokenHeader = $_SERVER['HTTP_X_PING_TOKEN'] ?? '';
$pingTokenQuery  = $_GET['token'] ?? '';

if (!empty($pingTokenHeader)) {
    $pingToken = $pingTokenHeader;
} elseif (!empty($pingTokenQuery)) {
    // Query string tokens are permanently exposed in server access logs — reject entirely
    error_log('Gumroad: Token passed via query string is not accepted. Use X-Ping-Token header instead.');
    http_response_code(403);
    exit('Unauthorized');
} else {
    $pingToken = '';
}

$hasWebhookSecret = !empty($webhookSecret);
$hasSignature     = !empty($signature);
$hasPingConfig    = !empty($configToken);
$hasPingToken     = !empty($pingToken);

// SECURITY: If webhook secret is configured, ALWAYS require HMAC signature.
// Do not allow fallback to ping token when HMAC is configured — that would allow
// an attacker to bypass HMAC by simply omitting the signature header.
if ($hasWebhookSecret) {
    if (!$hasSignature) {
        error_log('Gumroad: HMAC secret is configured but no X-Gumroad-Signature header received.');
        http_response_code(403);
        exit('Unauthorized');
    }
    $rawBody = file_get_contents('php://input');
    $expected = hash_hmac('sha256', $rawBody, $webhookSecret);
    if (!hash_equals($expected, $signature)) {
        error_log('Gumroad webhook: Invalid HMAC signature');
        http_response_code(403);
        exit('Invalid signature');
    }
} elseif ($hasPingConfig) {
    if (!$hasPingToken) {
        error_log('Gumroad: Ping token configured but no token received in request.');
        http_response_code(403);
        exit('Unauthorized');
    }
    if (!hash_equals($configToken, $pingToken)) {
        error_log('Gumroad Ping: Invalid token');
        http_response_code(403);
        exit('Invalid token');
    }
} else {
    error_log('Gumroad: No auth method configured. Set GUMROAD_WEBHOOK_SECRET (preferred) or GUMROAD_PING_TOKEN in .env');
    http_response_code(403);
    exit('Unauthorized');
}

// ============================================
// PROCESS DATA
// Gumroad Ping & resource_subscriptions send POST fields
// ============================================
$data          = $_POST;
$sale_id       = $data['sale_id'] ?? '';
$email         = strtolower(trim($data['email'] ?? ($data['user_email'] ?? '')));
$product_id    = $data['product_id'] ?? '';
$product_name  = $data['product_name'] ?? '';
$license_key   = $data['license_key'] ?? '';
$refunded      = filter_var($data['refunded'] ?? false, FILTER_VALIDATE_BOOLEAN);
$chargebacked  = filter_var($data['chargebacked'] ?? false, FILTER_VALIDATE_BOOLEAN);

// resource_subscriptions specific fields
$resourceName    = $data['resource_name'] ?? '';
$subscriptionId  = $data['subscription_id'] ?? '';
$cancelledAt     = $data['cancelled_at'] ?? null;
$endedAt         = $data['ended_at'] ?? null;
$endedReason     = $data['ended_reason'] ?? null;

// ============================================
// SUBSCRIPTION CANCELLATION HANDLER
// Triggered by resource_subscriptions: "cancellation"
// Handles both: cancelled flag (boolean) AND cancelled_at timestamp
// ============================================
$isCancellation = (!empty($data['cancelled']) && filter_var($data['cancelled'], FILTER_VALIDATE_BOOLEAN))
    || (!empty($cancelledAt))
    || $resourceName === 'cancellation';

if ($isCancellation) {
    $pdo         = db();
    $auditLogger = new AuditLogger($pdo);
    $emailSystem = new EmailSystem($pdo);

    try {
        $cancelEmail = $email ?: strtolower(trim($data['user_email'] ?? ''));
        $userStmt = $pdo->prepare("SELECT id, name, plan FROM users WHERE email = ? LIMIT 1");
        $userStmt->execute([$cancelEmail]);
        $cancelledUser = $userStmt->fetch();

        if ($cancelledUser) {
            $oldPlan = $cancelledUser['plan'];
            $cancelEffectiveAt = $cancelledAt ? date('Y-m-d H:i:s', strtotime($cancelledAt)) : null;

            if ($cancelEffectiveAt && strtotime($cancelEffectiveAt) > time()) {
                // Future cancellation — keep plan until end date, just mark it
                $pdo->prepare("UPDATE users SET subscription_cancelled_at=? WHERE id=?")
                    ->execute([$cancelEffectiveAt, $cancelledUser['id']]);
                error_log("Gumroad cancellation: User {$cancelEmail} subscription will end at {$cancelEffectiveAt}");
            } else {
                // Immediate — downgrade now + revoke addons
                $pdo->beginTransaction();
                try {
                    if ($license_key) {
                        $pdo->prepare("UPDATE licenses SET status='revoked', revoked_at=NOW() WHERE gumroad_license=? OR license_key=?")
                            ->execute([$license_key, $license_key]);
                    }
                    $pdo->prepare("UPDATE users SET plan='free', billing_cycle='none', plan_expires_at=NULL, gumroad_license=NULL, subscription_cancelled_at=NOW() WHERE id=?")
                        ->execute([$cancelledUser['id']]);

                    // Revoke addons tied to this sale_id
                    if ($sale_id) {
                        $pdo->prepare("DELETE FROM user_addons WHERE user_id=? AND gumroad_sale_id=?")
                            ->execute([$cancelledUser['id'], $sale_id]);
                    }

                    $pdo->commit();
                } catch (Exception $txEx) {
                    $pdo->rollBack();
                    throw $txEx;
                }

                $auditLogger->setUserId($cancelledUser['id']);
                $auditLogger->logPlanChange($oldPlan, 'free', 'gumroad_cancellation');

                try {
                    $emailSystem->sendFromTemplate('plan_expired', $cancelEmail, $cancelledUser['name'], [
                        'user_name'   => $cancelledUser['name'],
                        'plan_name'   => ucfirst($oldPlan) . ' Plan',
                        'expiry_date' => date('F d, Y'),
                        'reason'      => 'cancellation'
                    ], $cancelledUser['id'], 2);
                } catch (Exception $emailEx) {
                    error_log("Gumroad cancellation: failed to send email - " . $emailEx->getMessage());
                }

                error_log("Gumroad cancellation: Downgraded {$cancelEmail} from {$oldPlan} to free, addons revoked");
            }
        } else {
            error_log("Gumroad cancellation: User not found for email {$cancelEmail}");
        }
    } catch (Exception $e) {
        error_log("Gumroad cancellation handler error: " . $e->getMessage());
    }

    http_response_code(200);
    exit('ok');
}

// ============================================
// SUBSCRIPTION ENDED HANDLER
// Triggered by resource_subscriptions: "subscription_ended"
// Reason: failed_payment, cancelled, fixed_subscription_period_ended
// ============================================
if (!empty($endedAt) || $resourceName === 'subscription_ended') {
    $pdo         = db();
    $auditLogger = new AuditLogger($pdo);
    $emailSystem = new EmailSystem($pdo);

    try {
        $endEmail = $email ?: strtolower(trim($data['user_email'] ?? ''));
        $userStmt = $pdo->prepare("SELECT id, name, plan FROM users WHERE email = ? LIMIT 1");
        $userStmt->execute([$endEmail]);
        $endedUser = $userStmt->fetch();

        if ($endedUser) {
            $oldPlan = $endedUser['plan'];

            $pdo->beginTransaction();
            try {
                if ($license_key) {
                    $pdo->prepare("UPDATE licenses SET status='revoked', revoked_at=NOW() WHERE gumroad_license=? OR license_key=?")
                        ->execute([$license_key, $license_key]);
                }
                $pdo->prepare("UPDATE users SET plan='free', billing_cycle='none', plan_expires_at=NULL WHERE id=?")
                    ->execute([$endedUser['id']]);
                $pdo->commit();
            } catch (Exception $txEx) {
                $pdo->rollBack();
                throw $txEx;
            }

            $auditLogger->setUserId($endedUser['id']);
            $auditLogger->logPlanChange($oldPlan, 'free', 'gumroad_subscription_ended_' . ($endedReason ?? 'unknown'));
            error_log("Gumroad subscription_ended: Downgraded {$endEmail} from {$oldPlan} to free, reason: " . ($endedReason ?? 'unknown'));

            try {
                $emailSystem->sendFromTemplate('plan_expired', $endEmail, $endedUser['name'], [
                    'user_name'   => $endedUser['name'],
                    'plan_name'   => ucfirst($oldPlan) . ' Plan',
                    'expiry_date' => date('F d, Y'),
                    'reason'      => $endedReason ?? 'subscription_ended'
                ], $endedUser['id'], 2);
            } catch (Exception $emailEx) {
                error_log("Gumroad subscription_ended: Failed to send email - " . $emailEx->getMessage());
            }
        } else {
            error_log("Gumroad subscription_ended: User not found for email {$endEmail}");
        }
    } catch (Exception $e) {
        error_log("Gumroad subscription_ended handler error: " . $e->getMessage());
    }

    http_response_code(200);
    exit('ok');
}

// ============================================
// SUBSCRIPTION RENEWAL HANDLER
// Triggered by resource_subscriptions: "subscription_updated"
// Extends plan_expires_at on successful renewal
// ============================================
if ($resourceName === 'subscription_updated' && empty($cancelledAt) && empty($endedAt)) {
    $pdo         = db();
    $auditLogger = new AuditLogger($pdo);
    $emailSystem = new EmailSystem($pdo);

    try {
        $renewEmail = $email ?: strtolower(trim($data['user_email'] ?? ''));
        $userStmt = $pdo->prepare("SELECT id, name, plan, billing_cycle, plan_expires_at FROM users WHERE email = ? LIMIT 1");
        $userStmt->execute([$renewEmail]);
        $renewedUser = $userStmt->fetch();

        if ($renewedUser && $renewedUser['plan'] !== 'free' && $renewedUser['billing_cycle'] !== 'lifetime') {
            $currentExpiry = $renewedUser['plan_expires_at'] ? strtotime($renewedUser['plan_expires_at']) : time();
            $baseTime = max($currentExpiry, time());

            $cycle = $renewedUser['billing_cycle'];
            $extensionMonths = ($cycle === 'annual' || $cycle === 'yearly') ? 12 : 1;
            $newExpiry = date('Y-m-d H:i:s', strtotime("+{$extensionMonths} months", $baseTime));

            $pdo->prepare("UPDATE users SET plan_expires_at=?, subscription_cancelled_at=NULL WHERE id=?")
                ->execute([$newExpiry, $renewedUser['id']]);

            if ($license_key) {
                $pdo->prepare("UPDATE licenses SET status='active', expires_at=? WHERE gumroad_license=? OR license_key=?")
                    ->execute([$newExpiry, $license_key, $license_key]);
            }

            $auditLogger->setUserId($renewedUser['id']);
            $auditLogger->log('subscription_renewed', 'payment', 'success', [
                'target_type'  => 'user',
                'target_id'    => $renewedUser['id'],
                'request_data' => ['sale_id' => $sale_id, 'new_expiry' => $newExpiry, 'plan' => $renewedUser['plan']]
            ]);

            error_log("Gumroad renewal: Extended {$renewEmail} plan to {$newExpiry}");
        } else {
            error_log("Gumroad renewal: User not found or no action needed for {$renewEmail}");
        }
    } catch (Exception $e) {
        error_log("Gumroad renewal handler error: " . $e->getMessage());
    }

    http_response_code(200);
    exit('ok');
}

// ============================================
// REFUND / CHARGEBACK HANDLER
// ============================================
if ($refunded || $chargebacked) {
    $pdo         = db();
    $auditLogger = new AuditLogger($pdo);
    $emailSystem = new EmailSystem($pdo);
    $reason      = $chargebacked ? 'chargeback' : 'refund';

    try {
        $userStmt = $pdo->prepare("SELECT id, name, plan FROM users WHERE email = ? LIMIT 1");
        $userStmt->execute([$email]);
        $refundedUser = $userStmt->fetch();

        if ($refundedUser) {
            $oldPlan = $refundedUser['plan'];

            $pdo->beginTransaction();
            try {
                if ($license_key) {
                    $pdo->prepare("UPDATE licenses SET status = 'revoked', revoked_at = NOW() WHERE gumroad_license = ? OR license_key = ?")
                        ->execute([$license_key, $license_key]);
                }
                $pdo->prepare("UPDATE users SET plan = 'free', billing_cycle = 'none', plan_expires_at = NULL, gumroad_license = NULL, gumroad_sale_id = NULL WHERE id = ?")
                    ->execute([$refundedUser['id']]);

                // Revoke addons tied to this sale_id
                if ($sale_id) {
                    $pdo->prepare("DELETE FROM user_addons WHERE user_id = ? AND gumroad_sale_id = ?")
                        ->execute([$refundedUser['id'], $sale_id]);
                }

                // If no sale_id, revoke all addons for user (full plan refund)
                if (!$sale_id && $oldPlan !== 'free') {
                    $pdo->prepare("DELETE FROM user_addons WHERE user_id = ?")
                        ->execute([$refundedUser['id']]);
                }

                $pdo->commit();
            } catch (Exception $txEx) {
                $pdo->rollBack();
                throw $txEx;
            }

            $auditLogger->setUserId($refundedUser['id']);
            $auditLogger->logPlanChange($oldPlan, 'free', 'gumroad_' . $reason);
            $auditLogger->log('license_revoked', 'payment', 'success', [
                'target_type'   => 'license',
                'target_id'     => $license_key,
                'error_message' => "Revoked due to {$reason}",
                'request_data'  => ['sale_id' => $sale_id, 'email' => $email]
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

            error_log("Gumroad {$reason}: downgraded user {$email} from {$oldPlan} to free, addons revoked");
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
    error_log("Gumroad Ping: Missing required fields — email={$email} product_id={$product_id} product_name={$product_name} license_key=" . substr($license_key, 0, 8));
    http_response_code(400);
    exit('Missing required fields');
}

// Use product_name if available (more reliable), fallback to product_id
$product_identifier = $product_name ?: $product_id;

// ============================================
// DETECT PLAN FROM PRODUCT
// ============================================
$plan      = 'free';
$cycle     = 'none';
$months    = 1;
$amount    = 0;
$addonSlug = null;

$resolved = resolveGumroadProduct($product_identifier)
    ?? resolveGumroadProduct($product_id)
    ?? resolveGumroadProduct($product_name);

if ($resolved) {
    $plan      = $resolved['plan'];
    $cycle     = $resolved['cycle'];
    $months    = $resolved['months'];
    $addonSlug = $resolved['addon'] ?? null;
}

if ($plan === 'free') {
    error_log("Gumroad Ping: Unknown product '{$product_identifier}' (product_id: {$product_id}) — ignoring");
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
$pdo         = db();
$rawBody     = $rawBody ?? http_build_query($data);
$monitor     = MonitoringMiddleware::start($pdo, 'webhook_gumroad', null);
$emailSystem = new EmailSystem($pdo);
$analytics   = new Analytics($pdo);
$licenseGen  = new LicenseGenerator($pdo);
$auditLogger = new AuditLogger($pdo);

try {
    // Idempotency guard — atomic check + lock inside main transaction to prevent duplicate processing
    if ($sale_id) {
        $dupCheck = $pdo->prepare("SELECT id FROM users WHERE gumroad_sale_id = ? LIMIT 1");
        $dupCheck->execute([$sale_id]);
        if ($dupCheck->fetch()) {
            error_log("Gumroad Ping: Duplicate sale_id {$sale_id} — already processed, skipping");
            $monitor->end(200);
            http_response_code(200);
            exit('ok');
        }
    }

    $us = $pdo->prepare("SELECT id, name, plan as old_plan FROM users WHERE email=?");
    $us->execute([$email]);
    $u = $us->fetch();

    $userId    = null;
    $userName  = $data['full_name'] ?? 'New User';
    $isNewUser = false;
    $oldPlan   = 'free';

    // Generate or reuse system license key
    $existingLicense  = $licenseGen->checkExistingLicense($sale_id);
    $customLicenseKey = $existingLicense;

    if (!$existingLicense) {
        $customLicenseKey = $licenseGen->generateLicense($product_id, $sale_id, $email);
        $licenseGen->saveLicense(
            $customLicenseKey,
            $product_id,
            $sale_id,
            $email,
            [
                'product_name'    => $product_name,
                'full_name'       => $userName,
                'gumroad_license' => $license_key,
                'price'           => $data['price'] ?? null,
                'currency'        => $data['currency'] ?? 'USD'
            ]
        );
        error_log("Gumroad Ping: Generated license {$customLicenseKey} for sale {$sale_id}");
    } else {
        error_log("Gumroad Ping: Reusing existing license {$customLicenseKey} for sale {$sale_id}");
    }

    $pdo->beginTransaction();

    try {
        // Double-check inside transaction with row lock to prevent race conditions
        if ($sale_id) {
            $dupLock = $pdo->prepare("SELECT id FROM users WHERE gumroad_sale_id = ? LIMIT 1 FOR UPDATE");
            $dupLock->execute([$sale_id]);
            if ($dupLock->fetch()) {
                $pdo->rollBack();
                error_log("Gumroad Ping: Duplicate sale_id {$sale_id} caught inside transaction — skipping");
                $monitor->end(200);
                http_response_code(200);
                exit('ok');
            }
        }

        if ($u) {
            $userId   = $u['id'];
            $userName = $u['name'];
            $oldPlan  = $u['old_plan'] ?? 'free';
            $pdo->prepare("UPDATE users SET plan=?, billing_cycle=?, plan_expires_at=?, gumroad_license=?, gumroad_sale_id=? WHERE id=?")
                ->execute([$plan, $cycle, $expires, $license_key, $sale_id, $userId]);
            error_log("Gumroad Ping: Updated existing user {$email} to {$plan} plan");

            $auditLogger->setUserId($userId);
            $auditLogger->logPlanChange($oldPlan, $plan, 'gumroad_ping_purchase');
            $auditLogger->logPayment($sale_id, $amount, 'success', [
                'plan'        => $plan,
                'cycle'       => $cycle,
                'product'     => $product_identifier,
                'license_key' => $license_key
            ]);
        } else {
            $isNewUser = true;
            $pass      = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users(name, email, password, plan, billing_cycle, plan_expires_at, gumroad_license, gumroad_sale_id, created_at) VALUES(?,?,?,?,?,?,?,?,NOW())")
                ->execute([$userName, $email, $pass, $plan, $cycle, $expires, $license_key, $sale_id]);
            $userId = (int)$pdo->lastInsertId();
            error_log("Gumroad Ping: Created new user {$email} with {$plan} plan (id={$userId})");

            $auditLogger->setUserId($userId);
            $auditLogger->log('user_created_from_ping', 'payment', 'success', [
                'target_type'  => 'user',
                'target_id'    => $userId,
                'request_data' => ['email' => $email, 'plan' => $plan, 'sale_id' => $sale_id]
            ]);
            $auditLogger->logPayment($sale_id, $amount, 'success', [
                'plan'         => $plan,
                'cycle'        => $cycle,
                'product'      => $product_identifier,
                'is_new_user'  => true
            ]);
        }

        // Activate addons if this is an addon/bundle purchase
        if ($addonSlug && $userId) {
            try {
                $unlockedSlugs = getAddonSlugs($addonSlug);
                foreach ($unlockedSlugs as $slug) {
                    $checkAddon = $pdo->prepare("SELECT id FROM user_addons WHERE user_id=? AND addon_slug=?");
                    $checkAddon->execute([$userId, $slug]);
                    if (!$checkAddon->fetch()) {
                        $pdo->prepare("INSERT INTO user_addons(user_id, addon_slug, purchased_at, gumroad_sale_id) VALUES(?,?,NOW(),?)")
                            ->execute([$userId, $slug, $sale_id]);
                        error_log("Gumroad Ping: Activated addon '{$slug}' for user {$email}");

                        $auditLogger->log('addon_purchased', 'payment', 'success', [
                            'target_type'  => 'addon',
                            'target_id'    => $slug,
                            'request_data' => ['sale_id' => $sale_id, 'product' => $product_identifier]
                        ]);
                    }
                }
            } catch (Exception $addonEx) {
                error_log("Gumroad Ping: Failed to activate addon - " . $addonEx->getMessage());
            }
        }

        // Auto-activate system license
        if ($userId && $customLicenseKey) {
            try {
                $activationResult = $licenseGen->activateLicense($customLicenseKey, $userId, $email);
                if ($activationResult['success']) {
                    error_log("Gumroad Ping: Auto-activated license {$customLicenseKey} for user {$userId}");
                } else {
                    error_log("Gumroad Ping: License activation warning - " . ($activationResult['error'] ?? 'unknown'));
                }
            } catch (Exception $licEx) {
                error_log("Gumroad Ping: License activation failed - " . $licEx->getMessage());
            }
        }

        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Gumroad Ping transaction failed: " . $e->getMessage());
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => 'Database transaction failed']));
    }

    $analytics->trackConversion(
        $userId,
        'plan_purchase',
        $oldPlan,
        $plan,
        'gumroad_ping',
        $amount,
        ['sale_id' => $sale_id, 'product' => $product_identifier, 'billing_cycle' => $cycle, 'license_key' => $license_key]
    );

    // New user: kirim email set password + welcome
    if ($isNewUser) {
        $resetToken  = bin2hex(random_bytes(32));
        $tokenExpiry = date('Y-m-d H:i:s', strtotime('+72 hours'));

        try {
            $pdo->prepare("
                INSERT INTO password_resets (email, token, expires_at, created_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at), created_at = NOW()
            ")->execute([$email, $resetToken, $tokenExpiry]);
        } catch (Exception $tokenEx) {
            error_log("Gumroad Ping: Failed to create reset token - " . $tokenEx->getMessage());
        }

        $setPasswordUrl = APP_URL . '/auth/reset_password.php?token=' . $resetToken . '&email=' . urlencode($email);

        $emailSystem->sendFromTemplate('welcome', $email, $userName, [
            'user_name'        => $userName,
            'plan_name'        => ucfirst($plan) . ' Plan',
            'set_password_url' => $setPasswordUrl,
            'login_url'        => APP_URL . '/auth/login.php'
        ], $userId, 1);
    }

    // Kirim system license key ke customer
    $emailSystem->sendFromTemplate('license_delivery', $email, $userName, [
        'user_name'    => $userName,
        'product_name' => $product_name,
        'license_key'  => $customLicenseKey,
        'plan_name'    => ucfirst($plan) . ' Plan',
        'login_url'    => APP_URL . '/auth/login.php',
        'billing_url'  => APP_URL . '/dashboard/billing.php',
        'is_new_user'  => $isNewUser
    ], $userId, 3);

    $emailSystem->sendFromTemplate('invoice', $email, $userName, [
        'user_name'     => $userName,
        'order_id'      => $sale_id,
        'plan_name'     => ucfirst($plan) . ' Plan',
        'amount'        => '$' . number_format($amount, 2),
        'date'          => date('F d, Y'),
        'billing_cycle' => ucfirst($cycle),
        'invoice_url'   => APP_URL . '/dashboard/billing.php'
    ], $userId, 3, ['sale_id' => $sale_id, 'amount' => $amount, 'gateway' => 'gumroad']);

    $monitor->end(200);
    http_response_code(200);
    exit('ok');

} catch (Exception $e) {
    $retryQueue = new WebhookRetryQueue($pdo);
    $retryQueue->queue('gumroad', $rawBody ?? '', []);

    if (isset($userId)) {
        $monitor->logPaymentFailure($userId, 'gumroad', [
            'sale_id'       => $sale_id,
            'error_message' => $e->getMessage(),
            'product'       => $product_identifier
        ]);
    }

    $monitor->handleError($e, 'high', $userId ?? null);
    $monitor->end(500, $e->getMessage());

    error_log("Gumroad Ping error (queued for retry): " . $e->getMessage());
    http_response_code(500);
    exit('Internal error');
}
