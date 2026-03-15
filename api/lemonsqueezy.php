<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/Analytics.php';
require_once dirname(__DIR__).'/includes/MonitoringMiddleware.php';
require_once dirname(__DIR__).'/includes/EmailSystem.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';

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
                    $emailSystem->sendFromTemplate('invoice', $userInfo['email'], $userInfo['name'], [
                        'user_name' => $userInfo['name'],
                        'order_id' => $orderId,
                        'plan_name' => ucfirst($plan) . ' Plan',
                        'amount' => '$' . number_format($planPrice, 2),
                        'date' => date('F d, Y'),
                        'invoice_url' => APP_URL . '/dashboard/billing.php'
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
            $licenseKey = $attributes['first_subscription_item']['license_key'] ?? '';

            $pdo->prepare("UPDATE licenses SET status = 'cancelled' WHERE license_key = ?")->execute([$licenseKey]);

            $license = $pdo->prepare("SELECT user_id FROM licenses WHERE license_key = ?");
            $license->execute([$licenseKey]);
            $licenseData = $license->fetch();

            if ($licenseData) {
                $pdo->prepare("UPDATE users SET plan = 'free', billing_cycle = 'none' WHERE id = ?")->execute([$licenseData['user_id']]);
            }

            break;

        // ── One-time addon purchase ───────────────────────────────────────
        case 'order_created':
            $orderStatus = $attributes['status'] ?? '';
            if ($orderStatus !== 'paid') break;

            $email     = $attributes['user_email'] ?? '';
            $variantId = (string)($attributes['first_order_item']['variant_id'] ?? '');
            $orderId   = (string)($attributes['identifier'] ?? $attributes['order_number'] ?? '');

            if (!$email || !$variantId) break;

            $userRow = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $userRow->execute([$email]);
            $userData = $userRow->fetch();
            if (!$userData) break;

            $userId = $userData['id'];

            // Match variant to an addon
            $matchedAddon = null;
            foreach (ADDON_DATA as $aSlug => $aData) {
                if (($aData['lemon_variant'] ?? '') === $variantId) {
                    $matchedAddon = $aSlug;
                    break;
                }
            }

            // Also check plan lifetime variants (in case it's a lifetime plan)
            if (!$matchedAddon) {
                foreach (PLAN_DATA as $planKey => $planInfo) {
                    if (($planInfo['lemon_lifetime'] ?? '') === $variantId) {
                        // It's a lifetime plan purchase — handle as plan upgrade
                        $pdo->prepare("UPDATE users SET plan = ?, billing_cycle = 'lifetime' WHERE id = ?")
                            ->execute([$planKey, $userId]);
                        break;
                    }
                }
                break;
            }

            // Expand bundle → individual addon slugs
            $slugsToGrant = !empty(ADDON_DATA[$matchedAddon]['includes'])
                ? ADDON_DATA[$matchedAddon]['includes']
                : [$matchedAddon];

            // Ensure addons table rows exist, then upsert user_addons
            foreach ($slugsToGrant as $grantSlug) {
                $addonInfo = ADDON_DATA[$grantSlug] ?? null;
                if (!$addonInfo) continue;

                // Ensure addon exists in addons table
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
                    'target_type' => 'addon',
                    'target_id' => $grantSlug,
                    'request_data' => [
                        'order_id' => $orderId,
                        'gateway' => 'lemonsqueezy',
                        'variant_id' => $variantId
                    ]
                ]);
            }

            break;
    }

    $monitor->end(200);
    echo json_encode(['success' => true, 'event' => $event]);

} catch (Exception $e) {
    require_once __DIR__ . '/../includes/WebhookRetryQueue.php';
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
