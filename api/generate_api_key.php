<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/RateLimiter.php';
startSession();

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$pdo = db();
$uid = $_SESSION['uid'];

$_rl = new RateLimiter($pdo);
$_rlCheck = $_rl->check('api_key_gen_' . $uid, 'api_key_generate', 3, 3600);
if (!$_rlCheck['allowed']) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many requests. You can only generate 3 API keys per hour.']);
    exit;
}

try {
    // Check if user has Lifetime plan
    $stmt = $pdo->prepare("SELECT plan FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || $user['plan'] !== 'lifetime') {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'API access is only available for Lifetime plan users. Please upgrade your plan.'
        ]);
        exit;
    }

    $apiKey = 'brk_' . bin2hex(random_bytes(32));

    $stmt = $pdo->prepare("UPDATE users SET api_key = ? WHERE id = ?");
    $stmt->execute([$apiKey, $uid]);

    require_once __DIR__ . '/../includes/AuditLogger.php';
    $audit = new AuditLogger($pdo);
    $audit->setUserId($uid);
    $audit->log('api_key_generated', 'security', 'success');

    echo json_encode([
        'ok' => true,
        'message' => 'API key generated successfully'
    ]);

} catch (Exception $e) {
    error_log("API key generation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to generate API key. Please try again.'
    ]);
}
