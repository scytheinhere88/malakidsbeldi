<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/Analytics.php';
require_once dirname(__DIR__).'/includes/MonitoringMiddleware.php';
require_once dirname(__DIR__).'/includes/EmailSystem.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';
require_once dirname(__DIR__).'/includes/WebhookRetryQueue.php';

header('Content-Type: application/json');

if (empty(LEMONSQUEEZY_WEBHOOK_SECRET) || LEMONSQUEEZY_WEBHOOK_SECRET === 'your-lemonsqueezy-webhook-secret') {
    error_log('CRITICAL: LEMONSQUEEZY_WEBHOOK_SECRET not configured or using default value');
    http_response_code(503);
    die(json_encode([
        'success' => false,
        'error' => 'Webhook system not configured',
        'message' => 'Payment processing is temporarily unavailable'
    ]));
}

$payload = @file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

if (!$signature) {
    http_response_code(400);
    die(json_encode(['error' => 'Missing signature']));
}

$hash = hash_hmac('sha256', $payload, LEMONSQUEEZY_WEBHOOK_SECRET);
if (!hash_equals($hash, $signature)) {
    error_log('LemonSqueezy webhook: Invalid signature. Expected: ' . substr($hash, 0, 10) . '... Got: ' . substr($signature, 0, 10) . '...');
    $auditLogger = new AuditLogger(db());
    $auditLogger->log('webhook_failed', 'security', 'blocked', [
        'target_type' => 'webhook',
        'target_id' => 'lemonsqueezy',
        'error_message' => 'Invalid signature - potential security breach'
    ]);
    http_response_code(401);
    die(json_encode(['error' => 'Invalid signature']));
}

$data = json_decode($payload, true);
if (!$data) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid JSON']));
}

$event = $data['meta']['event_name'] ?? '';
$attributes = $data['data']['attributes'] ?? [];

$pdo = db();
$analytics = new Analytics($pdo);
$monitor = MonitoringMiddleware::start($pdo, 'webhook_lemonsqueezy', null);
$auditLogger = new AuditLogger($pdo);

try {
    switch ($event) {
        case 'order_created':
            $email = $attributes['user_email'] ?? '';
            $licenseKey = $attributes['first_order_item']['license_key'] ?? '';
            $variantId = $attributes['first_order_item']['variant_id'] ?? '';
            $orderId = $attributes['order_number'] ?? '';
            $status = $attributes['status'] ?? 'pending';

            if ($status !== 'paid') {
                break;
            }

            $user = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $user->execute([$email]);
            $userData = $user->fetch();

            if (!$userData) {
                http_response_code(404);
                die(json_encode(['error' => 'User not found']));
            }

            $userId = $userData['id'];

            $currentPlanStmt = $pdo->prepare("SELECT plan FROM users WHERE id = ?");
            $currentPlanStmt->execute([$userId]);
            $oldPlan = $currentPlanStmt->fetchColumn() ?: 'free';

            $plan = 'pro';
            $billingCycle = 'monthly';
            $expiresAt = null;

            // Check if this is an addon purchase first
            $matchedAddon = null;
            foreach (ADDON_DATA as $aSlug => $aData) {
                if ((string)($aData['lemon_variant'] ?? '') === (string)$variantId) {
                    $matchedAddon = $aSlug;
                    break;
                }
            }

            if ($matchedAddon !== null) {
                // Addon purchase flow
                $slugsToGrant = !empty(ADDON_DATA[$matchedAddon]['includes'])
                    ? ADDON_DATA[$matchedAddon]['includes']
                    : [$matchedAddon];

                $pdo->beginTransaction();
                try {
                    foreach ($slugsToGrant as $grantSlug) {
                        $addonInfo = ADDON_DATA[$grantSlug] ?? null;
                        if (!$addonInfo) continue;

                        $pdo->prepare("INSERT IGNORE INTO addons (slug, name, price) VALUES (?,?,?)")
                            ->execute([$grantSlug, $addonInfo['name'], $addonInfo['price']]);

                        $addonRow = $pdo->prepare("SELECT id FROM addons WHERE slug = ?");
                        $addonRow->execute([$grantSlug]);
                        $addonId = $addonRow->fetchColumn();
                        if (!$addonId) continue;

                        $pdo->prepare("
                            INSERT INTO user_addons (user_id, addon_id, order_id, is_active, purchased_at)
                            VALUES (?, ?, ?, 1, NOW())
                            ON DUPLICATE KEY UPDATE is_active = 1, order_id = ?
                        ")->execute([$userId, $addonId, $orderId, $orderId]);

                        $auditLogger->setUserId($userId);
                        $auditLogger->log('addon_purchased', 'payment', 'success', [
                            'target_type'  => 'addon',
                            'target_id'    => $grantSlug,
                            'request_data' => ['order_id' => $orderId, 'gateway' => 'lemonsqueezy', 'variant_id' => $variantId]
                        ]);
                    }
                    $pdo->commit();
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("LemonSqueezy addon webhook transaction failed: " . $e->getMessage());
                    throw $e;
                }
                break;
            }

            // Plan purchase flow
            foreach (PLAN_DATA as $planKey => $planInfo) {
                if ($planInfo['lemon_monthly'] == $variantId) {
                    $plan = $planKey;
                    $billingCycle = 'monthly';
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
                    break;
                }
                if ($planInfo['lemon_annual'] == $variantId) {
                    $plan = $planKey;
                    $billingCycle = 'annual';
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+365 days'));
                    break;
                }
                if ($planInfo['lemon_lifetime'] == $variantId) {
                    $plan = $planKey;
                    $billingCycle = 'lifetime';
                    $expiresAt = null;
                    break;
                }
            }

            // Use transaction to ensure consistency
            $pdo->beginTransaction();

            try {
                $pdo->prepare("
                    UPDATE users
                    SET plan = ?,
                        plan_expires_at = ?,
                        billing_cycle = ?,
                        lemonsqueezy_order_id = ?
                    WHERE id = ?
                ")->execute([$plan, $expiresAt, $billingCycle, $orderId, $userId]);

                $pdo->prepare("
                    INSERT INTO licenses (user_id, license_key, plan, billing_cycle, lemonsqueezy_order_id, status, activated_at, expires_at)
                    VALUES (?, ?, ?, ?, ?, 'active', NOW(), ?)
                    ON DUPLICATE KEY UPDATE status = 'active', expires_at = ?
                ")->execute([$userId, $licenseKey, $plan, $billingCycle, $orderId, $expiresAt, $expiresAt]);

                $pdo->commit();

                $planPrice = PLAN_DATA[$plan]['pm'] ?? PLAN_DATA[$plan]['pa'] ?? PLAN_DATA[$plan]['pl'] ?? 0;
                $analytics->trackConversion(
                    $userId,
                    'plan_purchase',
                    $oldPlan,
                    $plan,
                    'lemonsqueezy',
                    $planPrice,
                    ['order_id' => $orderId, 'variant_id' => $variantId, 'billing_cycle' => $billingCycle]
                );

                $auditLogger->setUserId($userId);
                $auditLogger->logPlanChange($oldPlan, $plan, 'lemonsqueezy_purchase');
                $auditLogger->logPayment($orderId, $planPrice, 'success', [
                    'plan' => $plan,
                    'cycle' => $billingCycle,
                    'variant_id' => $variantId,
                    'gateway' => 'lemonsqueezy'
                ]);

                $userStmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
                $userStmt->execute([$userId]);
                $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);

                if ($userInfo) {
                    $emailSystem = new EmailSystem($pdo);

                    $emailSystem->sendFromTemplate('license_delivery', $userInfo['email'], $userInfo['name'], [
                        'user_name'    => $userInfo['name'],
                        'product_name' => ucfirst($plan) . ' Plan',
                        'license_key'  => $licenseKey,
                        'plan_name'    => ucfirst($plan) . ' Plan',
                        'login_url'    => APP_URL . '/auth/login.php',
                        'billing_url'  => APP_URL . '/dashboard/billing.php',
                        'is_new_user'  => false
                    ], $userId, 2);

                    $emailSystem->sendFromTemplate('invoice', $userInfo['email'], $userInfo['name'], [
                        'user_name'    => $userInfo['name'],
                        'order_id'     => $orderId,
                        'plan_name'    => ucfirst($plan) . ' Plan',
                        'amount'       => '$' . number_format($planPrice, 2),
                        'date'         => date('F d, Y'),
                        'billing_cycle'=> ucfirst($billingCycle),
                        'invoice_url'  => APP_URL . '/dashboard/billing.php'
                    ], $userId, 3, ['order_id' => $orderId, 'amount' => $planPrice]);
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Webhook transaction failed: " . $e->getMessage());
                throw $e;
            }

            break;

        case 'subscription_created':
        case 'subscription_updated':
            $email = $attributes['user_email'] ?? '';
            $licenseKey = $attributes['first_subscription_item']['license_key'] ?? '';
            $variantId = $attributes['variant_id'] ?? '';
            $status = $attributes['status'] ?? '';
            $orderId = $attributes['order_number'] ?? '';

            if (!in_array($status, ['active', 'on_trial'])) {
                break;
            }

            $user = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $user->execute([$email]);
            $userData = $user->fetch();

            if (!$userData) {
                break;
            }

            $userId = $userData['id'];

            $plan = 'pro';
            $billingCycle = 'monthly';
            $renewsAt = $attributes['renews_at'] ?? null;
            $expiresAt = $renewsAt ? date('Y-m-d H:i:s', strtotime($renewsAt)) : null;

            foreach (PLAN_DATA as $planKey => $planInfo) {
                if ($planInfo['lemon_monthly'] == $variantId) {
                    $plan = $planKey;
                    $billingCycle = 'monthly';
                    break;
                }
                if ($planInfo['lemon_annual'] == $variantId) {
                    $plan = $planKey;
                    $billingCycle = 'annual';
                    break;
                }
            }

            $pdo->prepare("
                UPDATE users
                SET plan = ?,
                    plan_expires_at = ?,
                    billing_cycle = ?,
                    lemonsqueezy_order_id = ?
                WHERE id = ?
            ")->execute([$plan, $expiresAt, $billingCycle, $orderId, $userId]);

            $pdo->prepare("
                INSERT INTO licenses (user_id, license_key, plan, billing_cycle, lemonsqueezy_order_id, status, activated_at, expires_at)
                VALUES (?, ?, ?, ?, ?, 'active', NOW(), ?)
                ON DUPLICATE KEY UPDATE status = 'active', expires_at = ?
            ")->execute([$userId, $licenseKey, $plan, $billingCycle, $orderId, $expiresAt, $expiresAt]);

            break;

        case 'subscription_cancelled':
        case 'subscription_expired':
            $licenseKey   = $attributes['first_subscription_item']['license_key'] ?? '';
            $cancelReason = $event === 'subscription_expired' ? 'expired' : 'cancelled';

            $pdo->prepare("UPDATE licenses SET status = ? WHERE license_key = ?")
                ->execute([$cancelReason, $licenseKey]);

            $licenseStmt = $pdo->prepare("SELECT user_id FROM licenses WHERE license_key = ?");
            $licenseStmt->execute([$licenseKey]);
            $licenseData = $licenseStmt->fetch();

            if ($licenseData) {
                $userInfoStmt = $pdo->prepare("SELECT name, email, plan FROM users WHERE id = ?");
                $userInfoStmt->execute([$licenseData['user_id']]);
                $cancelledUser = $userInfoStmt->fetch(PDO::FETCH_ASSOC);

                $oldPlan = $cancelledUser['plan'] ?? 'pro';

                $pdo->prepare("UPDATE users SET plan = 'free', billing_cycle = 'none', plan_expires_at = NULL WHERE id = ?")
                    ->execute([$licenseData['user_id']]);

                $auditLogger->setUserId($licenseData['user_id']);
                $auditLogger->logPlanChange($oldPlan, 'free', 'lemonsqueezy_' . $cancelReason);

                if ($cancelledUser) {
                    $emailSystem = new EmailSystem($pdo);
                    try {
                        $emailSystem->sendFromTemplate('plan_expired', $cancelledUser['email'], $cancelledUser['name'], [
                            'user_name'   => $cancelledUser['name'],
                            'plan_name'   => ucfirst($oldPlan) . ' Plan',
                            'expiry_date' => date('F d, Y'),
                            'reason'      => $cancelReason
                        ], $licenseData['user_id'], 2);
                    } catch (Exception $emailEx) {
                        error_log("LemonSqueezy {$cancelReason}: failed to send email - " . $emailEx->getMessage());
                    }
                }

                error_log("LemonSqueezy {$cancelReason}: downgraded user #{$licenseData['user_id']} from {$oldPlan} to free");
            }

            break;

        case 'order_refunded':
            $refundEmail   = $attributes['user_email'] ?? '';
            $refundOrderId = $attributes['identifier'] ?? ($attributes['order_number'] ?? '');
            $refundItems   = $attributes['first_order_item'] ?? [];
            $refundLicense = $refundItems['license_key'] ?? '';

            if ($refundLicense) {
                $pdo->prepare("UPDATE licenses SET status = 'revoked', revoked_at = NOW() WHERE license_key = ?")
                    ->execute([$refundLicense]);
            }

            if ($refundEmail) {
                $refundUserStmt = $pdo->prepare("SELECT id, name, plan FROM users WHERE email = ? LIMIT 1");
                $refundUserStmt->execute([$refundEmail]);
                $refundUser = $refundUserStmt->fetch(PDO::FETCH_ASSOC);

                if ($refundUser) {
                    $refundOldPlan = $refundUser['plan'];

                    $pdo->prepare("UPDATE users SET plan = 'free', billing_cycle = 'none', plan_expires_at = NULL WHERE id = ?")
                        ->execute([$refundUser['id']]);

                    $auditLogger->setUserId($refundUser['id']);
                    $auditLogger->logPlanChange($refundOldPlan, 'free', 'lemonsqueezy_refund');
                    $auditLogger->log('license_revoked', 'payment', 'success', [
                        'target_type'   => 'license',
                        'target_id'     => $refundLicense,
                        'error_message' => 'Revoked due to refund',
                        'request_data'  => ['order_id' => $refundOrderId, 'email' => $refundEmail]
                    ]);

                    try {
                        $emailSystem = new EmailSystem($pdo);
                        $emailSystem->sendFromTemplate('plan_expired', $refundUser['email'], $refundUser['name'], [
                            'user_name'   => $refundUser['name'],
                            'plan_name'   => ucfirst($refundOldPlan) . ' Plan',
                            'expiry_date' => date('F d, Y'),
                            'reason'      => 'refund'
                        ], $refundUser['id'], 2);
                    } catch (Exception $emailEx) {
                        error_log("LemonSqueezy refund: failed to send email - " . $emailEx->getMessage());
                    }

                    error_log("LemonSqueezy refund: downgraded user #{$refundUser['id']} from {$refundOldPlan} to free");
                }
            }
            break;
    }

    $monitor->end(200);
    echo json_encode(['success' => true, 'event' => $event]);

} catch (Exception $e) {
    $retryQueue = new WebhookRetryQueue($pdo);

    $retryQueue->queue('lemonsqueezy', $payload, [
        'X-Signature' => $signature
    ]);

    if ($event === 'order_created' && isset($userId)) {
        $monitor->logPaymentFailure((int)$userId, 'lemonsqueezy', [
            'order_id' => $orderId ?? 'unknown',
            'error_message' => $e->getMessage(),
            'event' => $event
        ]);
    }

    $monitor->handleError($e, 'high', $userId ?? null);
    $monitor->end(500, $e->getMessage());

    error_log("[LemonSqueezy Webhook] Error (queued for retry): " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'queued_for_retry' => true]);
}
