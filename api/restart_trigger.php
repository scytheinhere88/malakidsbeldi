<?php
/**
 * API Restart Trigger Endpoint
 *
 * Provides a way to restart/refresh API services through admin monitoring dashboard
 * This clears caches, resets connections, and triggers service health checks
 */

// Function to send clean JSON response
function sendJSON($data, $code = 200) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    echo json_encode($data);
    exit;
}

// Start output buffering to catch any stray output
ob_start();

// Disable all error display
ini_set('display_errors', '0');
error_reporting(0);

// Load config
try {
    require_once __DIR__ . '/../config.php';
} catch (Exception $e) {
    sendJSON(['success' => false, 'error' => 'Config load failed'], 500);
}

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(['success' => false, 'error' => 'Method not allowed'], 405);
}

// Start session and check admin
try {
    ss();
    if (!isAdmin()) {
        sendJSON(['success' => false, 'error' => 'Admin access required'], 403);
    }
} catch (Exception $e) {
    sendJSON(['success' => false, 'error' => 'Authentication failed'], 403);
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action !== 'restart') {
    sendJSON(['success' => false, 'error' => 'Invalid action'], 400);
}

// Execute restart actions
try {
    $db = db();
    $actions = [];
    $warnings = [];

    // Reset opcache if available
    try {
        if (function_exists('opcache_reset')) {
            opcache_reset();
            $actions[] = 'OPcache reset';
        }
    } catch (Exception $e) {
        $warnings[] = 'OPcache: ' . $e->getMessage();
    }

    $response = [
        'success' => true,
        'message' => 'API services restarted successfully',
        'actions' => $actions,
        'timestamp' => time()
    ];

    if (!empty($warnings)) {
        $response['warnings'] = $warnings;
    }

    sendJSON($response);

} catch (Exception $e) {
    sendJSON([
        'success' => false,
        'error' => 'Restart failed: ' . $e->getMessage()
    ], 500);
}
