<?php

class CSRFMiddleware {
    private static array $exemptPaths = [
        '/api/gumroad.php',
        '/api/lemonsqueezy.php',
        '/api/health.php',
        '/api/cron_',
    ];

    public static function verify(): bool {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return true;
        }

        foreach (self::$exemptPaths as $path) {
            if (strpos($_SERVER['REQUEST_URI'], $path) !== false) {
                return true;
            }
        }

        return csrf_verify();
    }

    public static function require(): void {
        if (!self::verify()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'CSRF token validation failed',
                'message' => 'Invalid or missing CSRF token. Please refresh the page and try again.'
            ]);
            exit;
        }
    }

    public static function addExemptPath(string $path): void {
        if (!in_array($path, self::$exemptPaths)) {
            self::$exemptPaths[] = $path;
        }
    }
}
