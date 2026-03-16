<?php

class SecurityHeaders {

    private static function isHttps(): bool {
        return (defined('FORCE_HTTPS') && FORCE_HTTPS === true)
            || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    }

    public static function apply() {
        if (headers_sent()) {
            return;
        }

        header("X-Content-Type-Options: nosniff");

        header("X-Frame-Options: SAMEORIGIN");

        header("X-XSS-Protection: 1; mode=block");

        header("Referrer-Policy: strict-origin-when-cross-origin");

        header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

        if (self::isHttps()) {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
        }

        $csp = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "img-src 'self' data: https:",
            "font-src 'self' data: https://fonts.gstatic.com",
            "connect-src 'self' https:",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'"
        ];

        header("Content-Security-Policy: " . implode("; ", $csp));

        header("X-Permitted-Cross-Domain-Policies: none");

        if (session_status() === PHP_SESSION_ACTIVE) {
            $https = self::isHttps();
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', $https ? 1 : 0);
            ini_set('session.cookie_samesite', 'Lax');
            ini_set('session.use_strict_mode', 1);
        }
    }

    public static function applyApiHeaders() {
        if (headers_sent()) {
            return;
        }

        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: DENY");
        header("Content-Type: application/json; charset=utf-8");

        if (self::isHttps()) {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
        }

        $allowedOrigin = defined('APP_URL') ? APP_URL : (defined('CORS_ORIGIN') ? CORS_ORIGIN : '');
        if (!empty($allowedOrigin)) {
            header("Access-Control-Allow-Origin: " . $allowedOrigin);
        }
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-Key");
        header("Access-Control-Max-Age: 86400");
        header("Vary: Origin");

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    public static function preventClickjacking() {
        header("X-Frame-Options: DENY");
        header("Content-Security-Policy: frame-ancestors 'none'");
    }

    public static function setNoCache() {
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");
        header("Expires: 0");
    }
}
