<?php
require_once dirname(__DIR__).'/config.php';

requireAdmin();

header('Content-Type: application/json');

$accessToken = defined('GUMROAD_ACCESS_TOKEN') ? GUMROAD_ACCESS_TOKEN : '';
$pingToken   = defined('GUMROAD_PING_TOKEN') ? GUMROAD_PING_TOKEN : '';

if (empty($accessToken)) {
    http_response_code(400);
    die(json_encode([
        'success' => false,
        'error'   => 'GUMROAD_ACCESS_TOKEN not configured. Add it to your .env file first.',
        'hint'    => 'Go to Gumroad Settings → Advanced → Applications → Generate access token'
    ]));
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

$postUrl = rtrim(APP_URL, '/') . '/api/gumroad.php' . (!empty($pingToken) ? '?token=' . urlencode($pingToken) : '');

$resourceNames = ['sale', 'refund', 'dispute', 'dispute_won', 'cancellation', 'subscription_updated', 'subscription_ended', 'subscription_restarted'];

function gumroadRequest(string $method, string $endpoint, array $params = []): array {
    $accessToken = defined('GUMROAD_ACCESS_TOKEN') ? GUMROAD_ACCESS_TOKEN : '';
    $url = 'https://api.gumroad.com/v2/' . ltrim($endpoint, '/');

    $params['access_token'] = $accessToken;

    $ch = curl_init();

    if ($method === 'GET') {
        $url .= '?' . http_build_query($params);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    } elseif ($method === 'DELETE') {
        $url .= '?' . http_build_query($params);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => 'CURL error: ' . $curlError, 'http_code' => 0];
    }

    $decoded = json_decode($response, true);
    if ($decoded === null) {
        return ['success' => false, 'error' => 'Invalid JSON response', 'http_code' => $httpCode, 'raw' => $response];
    }

    $decoded['http_code'] = $httpCode;
    return $decoded;
}

// ============================================
// ACTION: LIST — show all active subscriptions
// ============================================
if ($action === 'list') {
    $results = [];
    foreach ($resourceNames as $name) {
        $res = gumroadRequest('GET', 'resource_subscriptions', ['resource_name' => $name]);
        $results[$name] = [
            'subscriptions' => $res['resource_subscriptions'] ?? [],
            'error'         => $res['success'] === false ? ($res['message'] ?? $res['error'] ?? 'unknown') : null
        ];
    }

    echo json_encode([
        'success'          => true,
        'post_url'         => $postUrl,
        'resource_results' => $results
    ], JSON_PRETTY_PRINT);
    exit;
}

// ============================================
// ACTION: SETUP — subscribe to all events
// ============================================
if ($action === 'setup') {
    $results = [];
    foreach ($resourceNames as $name) {
        $res = gumroadRequest('PUT', 'resource_subscriptions', [
            'resource_name' => $name,
            'post_url'      => $postUrl
        ]);

        $results[$name] = [
            'success'      => $res['success'] ?? false,
            'subscription' => $res['resource_subscription'] ?? null,
            'error'        => ($res['success'] === false) ? ($res['message'] ?? $res['error'] ?? 'unknown') : null
        ];

        error_log("Gumroad resource_subscription setup [{$name}]: " . ($res['success'] ? 'OK' : ($res['message'] ?? 'failed')));
    }

    $successCount = count(array_filter($results, fn($r) => $r['success']));

    echo json_encode([
        'success'      => $successCount > 0,
        'message'      => "Subscribed to {$successCount}/" . count($resourceNames) . " resource events",
        'post_url'     => $postUrl,
        'results'      => $results
    ], JSON_PRETTY_PRINT);
    exit;
}

// ============================================
// ACTION: DELETE — unsubscribe a specific resource
// ============================================
if ($action === 'delete') {
    $subscriptionId = $_GET['id'] ?? $_POST['id'] ?? '';
    if (empty($subscriptionId)) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => 'subscription id is required']));
    }

    $res = gumroadRequest('DELETE', 'resource_subscriptions/' . $subscriptionId);

    echo json_encode([
        'success' => $res['success'] ?? false,
        'message' => $res['message'] ?? ($res['error'] ?? 'unknown'),
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => "Unknown action '{$action}'. Use: list, setup, delete"]);
