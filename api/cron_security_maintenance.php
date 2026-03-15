<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/SecurityManager.php';

requireCronAuth();

$securityManager = new SecurityManager(db());

$expiredSessions = $securityManager->cleanupExpiredSessions();
$expiredBlocks = $securityManager->cleanupExpiredBlocks();

try {
    $stmt = db()->prepare("DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    $oldLoginAttempts = $stmt->rowCount();
} catch (Exception $e) {
    error_log("Failed to cleanup login attempts: " . $e->getMessage());
    $oldLoginAttempts = 0;
}

try {
    $stmt = db()->prepare("DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $stmt->execute();
    $oldAuditLogs = $stmt->rowCount();
} catch (Exception $e) {
    error_log("Failed to cleanup audit logs: " . $e->getMessage());
    $oldAuditLogs = 0;
}

try {
    $stmt = db()->prepare("DELETE FROM password_history WHERE created_at < DATE_SUB(NOW(), INTERVAL 365 DAY)");
    $stmt->execute();
    $oldPasswordHistory = $stmt->rowCount();
} catch (Exception $e) {
    error_log("Failed to cleanup password history: " . $e->getMessage());
    $oldPasswordHistory = 0;
}

$result = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'cleaned' => [
        'expired_sessions' => $expiredSessions,
        'expired_blocks' => $expiredBlocks,
        'old_login_attempts' => $oldLoginAttempts,
        'old_audit_logs' => $oldAuditLogs,
        'old_password_history' => $oldPasswordHistory
    ],
    'total_cleaned' => $expiredSessions + $expiredBlocks + $oldLoginAttempts + $oldAuditLogs + $oldPasswordHistory
];

header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);
