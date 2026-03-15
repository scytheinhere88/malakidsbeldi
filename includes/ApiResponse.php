<?php

class ApiResponse {

    const STATUS_SUCCESS = 'success';
    const STATUS_ERROR   = 'error';

    // ============================================
    // SUCCESS RESPONSES
    // ============================================

    public static function success(array $data = [], string $message = '', int $httpCode = 200): never {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        $payload = ['success' => true, 'status' => self::STATUS_SUCCESS];
        if ($message !== '') $payload['message'] = $message;
        if (!empty($data))   $payload = array_merge($payload, $data);
        echo json_encode($payload);
        exit;
    }

    public static function created(array $data = [], string $message = 'Created successfully'): never {
        self::success($data, $message, 201);
    }

    // ============================================
    // ERROR RESPONSES
    // ============================================

    public static function error(string $message, int $httpCode = 400, string $errorCode = '', array $extra = []): never {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        $payload = [
            'success' => false,
            'status'  => self::STATUS_ERROR,
            'error'   => $message,
        ];
        if ($errorCode !== '') $payload['error_code'] = $errorCode;
        if (!empty($extra))    $payload = array_merge($payload, $extra);
        echo json_encode($payload);
        exit;
    }

    public static function unauthorized(string $message = 'Unauthorized'): never {
        self::error($message, 401, 'UNAUTHORIZED');
    }

    public static function forbidden(string $message = 'Forbidden'): never {
        self::error($message, 403, 'FORBIDDEN');
    }

    public static function notFound(string $message = 'Not found'): never {
        self::error($message, 404, 'NOT_FOUND');
    }

    public static function validationError(array $errors, string $message = 'Validation failed'): never {
        self::error($message, 422, 'VALIDATION_ERROR', ['errors' => $errors]);
    }

    public static function rateLimited(int $retryAfter = 60): never {
        header("Retry-After: {$retryAfter}");
        header("X-RateLimit-Reset: " . (time() + $retryAfter));
        self::error('Too many requests. Please slow down.', 429, 'RATE_LIMITED', ['retry_after' => $retryAfter]);
    }

    public static function serverError(string $message = 'Internal server error', string $errorCode = 'SERVER_ERROR'): never {
        self::error($message, 500, $errorCode);
    }

    public static function serviceUnavailable(string $message = 'Service temporarily unavailable'): never {
        self::error($message, 503, 'SERVICE_UNAVAILABLE');
    }

    // ============================================
    // CSRF / AUTH HELPERS
    // ============================================

    public static function csrfError(): never {
        self::error('CSRF validation failed. Please refresh the page and try again.', 403, 'CSRF_INVALID');
    }

    public static function requirePostMethod(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
        }
    }

    public static function requireGetMethod(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            self::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
        }
    }

    // ============================================
    // JSON INPUT HELPER
    // ============================================

    public static function parseJsonBody(bool $required = false): array {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            if ($required) self::error('Request body is required', 400, 'EMPTY_BODY');
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::error('Invalid JSON in request body', 400, 'INVALID_JSON');
        }
        return $data;
    }

    // ============================================
    // PAGINATED RESPONSE
    // ============================================

    public static function paginated(array $items, int $total, int $page, int $perPage, array $extra = []): never {
        self::success(array_merge([
            'data'        => $items,
            'pagination'  => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'total_pages'  => (int) ceil($total / max(1, $perPage)),
                'has_next'     => ($page * $perPage) < $total,
                'has_prev'     => $page > 1,
            ]
        ], $extra));
    }
}
