<?php
$appEnv = $_ENV['APP_ENV'] ?? 'production';
define('APP_ENV', $appEnv);
define('DEBUG_MODE', $appEnv === 'development');

ini_set('display_errors', DEBUG_MODE ? 1 : 0);
ini_set('display_startup_errors', DEBUG_MODE ? 1 : 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

if (DEBUG_MODE) {
    error_reporting(E_ALL);
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $message = date('Y-m-d H:i:s') . " | Error [$errno]: $errstr in $errfile on line $errline\n";
    error_log($message, 3, __DIR__ . '/php_errors.log');
    return false;
});

set_exception_handler(function($exception) {
    $message = date('Y-m-d H:i:s') . " | Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine() . "\n";
    $message .= "Stack trace:\n" . $exception->getTraceAsString() . "\n\n";
    error_log($message, 3, __DIR__ . '/php_errors.log');

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    if (DEBUG_MODE) {
        echo '<!DOCTYPE html><html><head><title>Exception</title><style>body{font-family:monospace;padding:40px;background:#0a0a0a;color:#eee;}h1{color:#ff4560;}pre{background:#111;padding:20px;border-radius:8px;overflow:auto;color:#0f0;}.stack{color:#999;}.file{color:#6366f1;}</style></head><body>';
        echo '<h1>💥 Exception: ' . htmlspecialchars($exception->getMessage()) . '</h1>';
        echo '<p class="file"><strong>File:</strong> ' . htmlspecialchars($exception->getFile()) . ':' . $exception->getLine() . '</p>';
        echo '<h2>Stack Trace:</h2>';
        echo '<pre class="stack">' . htmlspecialchars($exception->getTraceAsString()) . '</pre>';
        echo '<p style="margin-top:30px;color:#666;">Environment: <strong style="color:#f59e0b;">DEVELOPMENT</strong></p>';
        echo '</body></html>';
    } else {
        echo '<!DOCTYPE html><html><head><title>Error</title><style>body{font-family:monospace;padding:40px;background:#0a0a0a;color:#999;}h1{color:#ff4560;}.error-box{background:#1a1a1a;padding:20px;border-radius:8px;border-left:4px solid #ff4560;margin:20px 0;}</style></head><body>';
        echo '<h1>Application Error</h1>';
        echo '<div class="error-box"><p>We encountered an unexpected error. Our team has been notified.</p></div>';
        echo '<p>Please try again later or contact support if the problem persists.</p>';
        echo '</body></html>';
    }
    exit;
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $message = date('Y-m-d H:i:s') . " | Fatal Error: {$error['message']} in {$error['file']} on line {$error['line']}\n\n";
        error_log($message, 3, __DIR__ . '/php_errors.log');

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }

        if (DEBUG_MODE) {
            echo '<!DOCTYPE html><html><head><title>Fatal Error</title><style>body{font-family:monospace;padding:40px;background:#0a0a0a;color:#eee;}h1{color:#dc2626;}pre{background:#111;padding:20px;border-radius:8px;overflow:auto;color:#f87171;}.file{color:#6366f1;}</style></head><body>';
            echo '<h1>⛔ Fatal Error</h1>';
            echo '<pre>' . htmlspecialchars($error['message']) . '</pre>';
            echo '<p class="file"><strong>File:</strong> ' . htmlspecialchars($error['file']) . ':' . $error['line'] . '</p>';
            echo '<p style="margin-top:30px;color:#666;">Environment: <strong style="color:#f59e0b;">DEVELOPMENT</strong></p>';
            echo '</body></html>';
        } else {
            echo '<!DOCTYPE html><html><head><title>Fatal Error</title><style>body{font-family:monospace;padding:40px;background:#0a0a0a;color:#999;}h1{color:#dc2626;}.error-box{background:#1a1a1a;padding:20px;border-radius:8px;border-left:4px solid #dc2626;margin:20px 0;}</style></head><body>';
            echo '<h1>Fatal Error</h1>';
            echo '<div class="error-box"><p>A critical error occurred. Our team has been notified.</p></div>';
            echo '<p>Please try again later or contact support if the problem persists.</p>';
            echo '</body></html>';
        }
    }
});
