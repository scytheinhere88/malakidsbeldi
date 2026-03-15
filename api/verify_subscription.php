<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/RateLimiter.php';

header('Content-Type: application/json');

startSession();
requireLogin();

$user = currentUser();
if (!$user) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'Not authenticated']));
}

$_rl = new RateLimiter(db());
$_rlCheck = $_rl->check('verifysub_' . $user['id'], 'verify_subscription', 30, 60);
if (!$_rlCheck['allowed']) {
    http_response_code(429);
    die(json_encode(['success' => false, 'error' => 'Too many requests. Please slow down.']));
}

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            plan,
            billing_cycle,
            plan_expires_at,
            gumroad_sale_id,
            subscription_cancelled_at,
            email,
            created_at
        FROM users
        WHERE id = ?
    ");
    $stmt->execute([$user['id']]);
    $userData = $stmt->fetch();

    if (!$userData) {
        throw new Exception('Subscription data unavailable');
    }

    $hasSaleId = !empty($userData['gumroad_sale_id']);

    $response = [
        'success'            => true,
        'plan'               => $userData['plan'],
        'billing_cycle'      => $userData['billing_cycle'],
        'expires_at'         => $userData['plan_expires_at'],
        'email'              => $userData['email'],
        'is_active'          => true,
        'has_payment_record' => $hasSaleId,
        'cancelled_at'       => $userData['subscription_cancelled_at'],
        'message'            => 'Subscription verified'
    ];

    if ($userData['plan'] !== 'free' && $userData['plan_expires_at']) {
        $expiresTimestamp = strtotime($userData['plan_expires_at']);
        $now = time();

        if ($expiresTimestamp < $now) {
            $response['is_active'] = false;
            $response['message'] = 'Subscription expired';

            $pdo->prepare("UPDATE users SET plan='free', billing_cycle='none', plan_expires_at=NULL WHERE id=?")
                ->execute([$user['id']]);

            $response['plan']          = 'free';
            $response['billing_cycle'] = 'none';
            $response['expires_at']    = null;
        } else {
            $daysRemaining = ceil(($expiresTimestamp - $now) / 86400);
            $response['days_remaining'] = $daysRemaining;
        }
    }

    if ($userData['plan'] === 'lifetime') {
        $response['is_active']      = true;
        $response['message']        = 'Lifetime subscription active';
        $response['days_remaining'] = 'unlimited';
    }

    echo json_encode($response);

} catch (Exception $e) {
    error_log('Subscription verification error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to verify subscription',
        'details' => null
    ]);
}
