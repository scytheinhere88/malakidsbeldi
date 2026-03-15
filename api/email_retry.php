<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/EmailSystem.php';
require_once __DIR__ . '/../includes/RateLimiter.php';

startSession();

header('Content-Type: application/json');

if (!isAdmin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Rate limit: 5 retries per hour for admin
$rateLimiter = new RateLimiter(db());
$identifier = 'admin_email_retry_' . ($_SESSION['uid'] ?? 'unknown');
if (!$rateLimiter->check($identifier, 5, 3600)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Rate limit exceeded. Max 5 email retries per hour.']);
    exit;
}

try {
    $emailSystem = new EmailSystem(db());
    $retried = $emailSystem->retryFailed(100);

    echo json_encode([
        'success' => true,
        'retried' => $retried,
        'message' => "{$retried} failed emails have been queued for retry"
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
