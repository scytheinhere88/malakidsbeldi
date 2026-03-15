<?php
// LOGOUT: No session recreation allowed!
define('IS_LOGOUT', true);

// Load session config first to get correct session name
define('SESSION_NAME', 'br_saas');

// Start session manually with correct session name
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    @session_start();
}

// Save user ID for optional logging (before clearing session)
$userId = $_SESSION['uid'] ?? null;
$sessionId = $_SESSION['session_id'] ?? null;

// IMMEDIATELY clear and destroy session - don't wait
$_SESSION = [];

// Delete session cookie with correct session name
if (ini_get("session.use_cookies")) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    // Use array syntax for PHP 7.3+, otherwise use old syntax
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

// Destroy session
@session_destroy();

// Optional: Try to log logout (after session destroyed, so it won't affect logout)
if($userId){
    try {
        require_once dirname(__DIR__).'/error_handler.php';
        require_once dirname(__DIR__).'/vendor/autoload.php';
        $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
        $dotenv->load();
        require_once dirname(__DIR__).'/config.php';
        require_once dirname(__DIR__).'/includes/AuditLogger.php';
        $db = db();
        $auditLogger = new AuditLogger($db);
        $auditLogger->setUserId($userId);
        $auditLogger->logAuth('logout', 'user_id_'.$userId, 'success', 'User logged out');
    } catch(Exception $e) {
        error_log("Logout audit log error: " . $e->getMessage());
    }
}

// Optional: Invalidate session in DB
if($sessionId){
    try{
        require_once dirname(__DIR__).'/includes/SecurityManager.php';
        require_once dirname(__DIR__).'/includes/AuditLogger.php';
        $securityManager = new SecurityManager(db(), new AuditLogger(db()));
        $securityManager->invalidateSession($sessionId);
    }catch(Exception $e){
        error_log("Logout session cleanup error: " . $e->getMessage());
    }
}

// Always redirect to login - this MUST happen
header('Location: /auth/login.php?msg=logged_out');
exit;
