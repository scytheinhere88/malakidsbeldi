<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ApiResponse.php';
require_once __DIR__ . '/../includes/LicenseGenerator.php';
require_once __DIR__ . '/../includes/EnhancedRateLimiter.php';
require_once __DIR__ . '/../includes/SystemMonitor.php';

startSession();

$pdo = db();
$monitor = new SystemMonitor($pdo);
$rateLimiter = new EnhancedRateLimiter($pdo, $monitor);

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userId = $_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0;

$rateCheck = $rateLimiter->check($ip, 'license_verify', 'free', $userId);

if (!$rateCheck['allowed']) {
    ApiResponse::rateLimited($rateCheck['retry_after'] ?? 60);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input      = ApiResponse::parseJsonBody(true);
    $licenseKey = trim($input['license_key'] ?? '');

    if (empty($licenseKey)) {
        ApiResponse::validationError(['license_key' => 'License key is required']);
    }

    $licenseGen = new LicenseGenerator($pdo);

    try {
        $result = $licenseGen->verifyLicense($licenseKey);

        if ($result['valid']) {
            ApiResponse::success([
                'valid'   => true,
                'license' => [
                    'product_name' => $result['license']['product_name'],
                    'product_type' => $result['license']['product_type'],
                    'status'       => $result['license']['status'],
                    'created_at'   => $result['license']['created_at'],
                    'expires_at'   => $result['license']['expires_at'],
                    'activated_at' => $result['license']['activated_at']
                ]
            ]);
        } else {
            ApiResponse::error($result['error'], 400, 'INVALID_LICENSE', ['valid' => false]);
        }
    } catch (Exception $e) {
        error_log("License verification error: " . $e->getMessage());
        ApiResponse::serverError('Verification failed');
    }

} elseif ($method === 'PUT') {
    $userId = $_SESSION['uid'] ?? $_SESSION['user_id'] ?? null;

    if (!$userId) {
        ApiResponse::unauthorized();
    }

    $userEmail  = $_SESSION['email'] ?? '';
    $input      = ApiResponse::parseJsonBody(true);
    $licenseKey = trim($input['license_key'] ?? '');

    if (empty($licenseKey)) {
        ApiResponse::validationError(['license_key' => 'License key is required']);
    }

    $licenseGen = new LicenseGenerator($pdo);

    try {
        $result = $licenseGen->activateLicense($licenseKey, $userId, $userEmail);

        if ($result['success']) {
            require_once __DIR__ . '/../includes/AuditLogger.php';
            $auditLogger = new AuditLogger($pdo);
            $auditLogger->setUserId($userId);
            $auditLogger->log('license_activated', 'license', 'success', [
                'target_type' => 'license',
                'request_data' => ['license_key' => $licenseKey, 'product' => $result['product']]
            ]);

            ApiResponse::success([
                'message'            => $result['message'],
                'product'            => $result['product'],
                'system_license_key' => $result['system_license_key'] ?? null,
            ]);
        } else {
            ApiResponse::error($result['error'], 400, 'ACTIVATION_FAILED');
        }
    } catch (Exception $e) {
        error_log("License activation error: " . $e->getMessage());
        ApiResponse::serverError('Activation failed');
    }

} elseif ($method === 'GET') {
    $userId = $_SESSION['uid'] ?? $_SESSION['user_id'] ?? null;

    if (!$userId) {
        ApiResponse::unauthorized();
    }

    $licenseGen = new LicenseGenerator($pdo);

    try {
        $licenses = $licenseGen->getLicensesByUserId($userId);
        ApiResponse::success(['licenses' => $licenses]);
    } catch (Exception $e) {
        error_log("License retrieval error: " . $e->getMessage());
        ApiResponse::serverError('Failed to retrieve licenses');
    }

} else {
    ApiResponse::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
}
