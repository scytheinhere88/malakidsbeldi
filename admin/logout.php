<?php
// LOGOUT: Prevent session recreation
define('IS_LOGOUT', true);
define('SESSION_NAME', 'br_saas');

// Start session manually with correct session name
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    @session_start();
}

// Clear session immediately
$_SESSION = [];

// Delete cookie with correct session name
if (ini_get("session.use_cookies")) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    if (PHP_VERSION_ID >= 70300) {
        setcookie(SESSION_NAME, '', [
            'expires' => time() - 42000,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    } else {
        setcookie(SESSION_NAME, '', time() - 42000, '/', '', $secure, true);
    }
}

// Destroy
@session_destroy();

// Load APP_URL
require_once dirname(__DIR__).'/vendor/autoload.php';
try {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
} catch(Exception $e) {
    // Continue
}

// Redirect
$appUrl = $_ENV['APP_URL'] ?? '';
header('Location: ' . $appUrl . '/admin/login.php');
exit;
