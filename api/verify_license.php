<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/LicenseGenerator.php';
require_once __DIR__ . '/../includes/EnhancedRateLimiter.php';
require_once __DIR__ . '/../includes/SystemMonitor.php';

startSession();

header('Content-Type: application/json');

$pdo = db();
$monitor = new SystemMonitor($pdo);
$rateLimiter = new EnhancedRateLimiter($pdo, $monitor);

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userId = $_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0;

$rateCheck = $rateLimiter->check($ip, 'license_verify', 'free', $userId);

if (!$rateCheck['allowed']) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Too many verification attempts. Please try again later.',
        'retry_after' => $rateCheck['retry_after'] ?? 60
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $licenseKey = trim($input['license_key'] ?? '');

    if (empty($licenseKey)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'License key is required'
        ]);
        exit;
    }

    $licenseGen = new LicenseGenerator($pdo);

    try {
        $result = $licenseGen->verifyLicense($licenseKey);

        if ($result['valid']) {
            echo json_encode([
                'success' => true,
                'valid' => true,
                'license' => [
                    'product_name' => $result['license']['product_name'],
                    'product_type' => $result['license']['product_type'],
                    'status' => $result['license']['status'],
                    'created_at' => $result['license']['created_at'],
                    'expires_at' => $result['license']['expires_at'],
                    'activated_at' => $result['license']['activated_at']
                ]
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'valid' => false,
                'error' => $result['error']
            ]);
        }
    } catch (Exception $e) {
        error_log("License verification error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Verification failed'
        ]);
    }
} elseif ($method === 'PUT') {
    $userId = $_SESSION['uid'] ?? $_SESSION['user_id'] ?? null;

    if (!$userId) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized'
        ]);
        exit;
    }

    $userEmail = $_SESSION['email'] ?? '';

    $input = json_decode(file_get_contents('php://input'), true);
    $licenseKey = trim($input['license_key'] ?? '');

    if (empty($licenseKey)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'License key is required'
        ]);
        exit;
    }

    $licenseGen = new LicenseGenerator($pdo);

    try {
        $result = $licenseGen->activateLicense($licenseKey, $userId, $userEmail);

        if ($result['success']) {
            require_once __DIR__ . '/../includes/AuditLogger.php';
            $auditLogger = new AuditLogger($pdo);
            $auditLogger->log($userId, 'license_activated', 'license', null, [
                'license_key' => $licenseKey,
                'product' => $result['product']
            ]);

            echo json_encode([
                'success' => true,
                'message' => $result['message'],
                'product' => $result['product'],
                'system_license_key' => $result['system_license_key'] ?? null
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $result['error']
            ]);
        }
    } catch (Exception $e) {
        error_log("License activation error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Activation failed: ' . $e->getMessage()
        ]);
    }
} elseif ($method === 'GET') {
    $userId = $_SESSION['uid'] ?? $_SESSION['user_id'] ?? null;

    if (!$userId) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized'
        ]);
        exit;
    }
    $licenseGen = new LicenseGenerator($pdo);

    try {
        $licenses = $licenseGen->getLicensesByUserId($userId);

        echo json_encode([
            'success' => true,
            'licenses' => $licenses
        ]);
    } catch (Exception $e) {
        error_log("License retrieval error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to retrieve licenses'
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
}
