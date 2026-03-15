<?php

class ErrorCodes {

    const AUTH_INVALID_CREDENTIALS = 'AUTH_001';
    const AUTH_ACCOUNT_LOCKED = 'AUTH_002';
    const AUTH_SESSION_EXPIRED = 'AUTH_003';
    const AUTH_INVALID_TOKEN = 'AUTH_004';
    const AUTH_2FA_REQUIRED = 'AUTH_005';
    const AUTH_2FA_INVALID = 'AUTH_006';
    const AUTH_PASSWORD_WEAK = 'AUTH_007';
    const AUTH_EMAIL_EXISTS = 'AUTH_008';
    const AUTH_USERNAME_EXISTS = 'AUTH_009';
    const AUTH_RATE_LIMITED = 'AUTH_010';

    const VALIDATION_REQUIRED_FIELD = 'VAL_001';
    const VALIDATION_INVALID_EMAIL = 'VAL_002';
    const VALIDATION_INVALID_FORMAT = 'VAL_003';
    const VALIDATION_LENGTH_MIN = 'VAL_004';
    const VALIDATION_LENGTH_MAX = 'VAL_005';
    const VALIDATION_INVALID_TYPE = 'VAL_006';
    const VALIDATION_OUT_OF_RANGE = 'VAL_007';

    const BILLING_INSUFFICIENT_CREDITS = 'BILL_001';
    const BILLING_PLAN_LIMIT_REACHED = 'BILL_002';
    const BILLING_PAYMENT_FAILED = 'BILL_003';
    const BILLING_INVALID_PROMO = 'BILL_004';
    const BILLING_PROMO_EXPIRED = 'BILL_005';
    const BILLING_SUBSCRIPTION_CANCELLED = 'BILL_006';
    const BILLING_UPGRADE_REQUIRED = 'BILL_007';

    const USAGE_LIMIT_EXCEEDED = 'USAGE_001';
    const USAGE_RATE_LIMITED = 'USAGE_002';
    const USAGE_QUOTA_EXCEEDED = 'USAGE_003';
    const USAGE_CONCURRENT_LIMIT = 'USAGE_004';

    const FILE_NOT_FOUND = 'FILE_001';
    const FILE_TOO_LARGE = 'FILE_002';
    const FILE_INVALID_TYPE = 'FILE_003';
    const FILE_UPLOAD_FAILED = 'FILE_004';
    const FILE_CORRUPTED = 'FILE_005';
    const FILE_PATH_TRAVERSAL = 'FILE_006';

    const DATA_NOT_FOUND = 'DATA_001';
    const DATA_ALREADY_EXISTS = 'DATA_002';
    const DATA_INVALID_STATE = 'DATA_003';
    const DATA_EXPORT_PENDING = 'DATA_004';
    const DATA_EXPORT_FAILED = 'DATA_005';

    const BACKUP_CREATION_FAILED = 'BACKUP_001';
    const BACKUP_RESTORE_FAILED = 'BACKUP_002';
    const BACKUP_NOT_FOUND = 'BACKUP_003';
    const BACKUP_CORRUPTED = 'BACKUP_004';
    const BACKUP_DISK_SPACE = 'BACKUP_005';
    const BACKUP_IN_PROGRESS = 'BACKUP_006';

    const WEBHOOK_VERIFICATION_FAILED = 'WEBHOOK_001';
    const WEBHOOK_INVALID_PAYLOAD = 'WEBHOOK_002';
    const WEBHOOK_PROCESSING_FAILED = 'WEBHOOK_003';

    const API_INVALID_KEY = 'API_001';
    const API_KEY_EXPIRED = 'API_002';
    const API_RATE_LIMITED = 'API_003';
    const API_EXTERNAL_SERVICE_ERROR = 'API_004';

    const SERVER_INTERNAL_ERROR = 'SERVER_001';
    const SERVER_DATABASE_ERROR = 'SERVER_002';
    const SERVER_TIMEOUT = 'SERVER_003';
    const SERVER_MAINTENANCE = 'SERVER_004';

    const SECURITY_CSRF_INVALID = 'SEC_001';
    const SECURITY_PERMISSION_DENIED = 'SEC_002';
    const SECURITY_SUSPICIOUS_ACTIVITY = 'SEC_003';
    const SECURITY_IP_BLOCKED = 'SEC_004';

    private static $messages = [
        'AUTH_001' => 'Invalid email or password',
        'AUTH_002' => 'Account is locked due to security reasons',
        'AUTH_003' => 'Your session has expired. Please log in again',
        'AUTH_004' => 'Invalid or expired authentication token',
        'AUTH_005' => 'Two-factor authentication is required',
        'AUTH_006' => 'Invalid two-factor authentication code',
        'AUTH_007' => 'Password does not meet security requirements',
        'AUTH_008' => 'An account with this email already exists',
        'AUTH_009' => 'This username is already taken',
        'AUTH_010' => 'Too many login attempts. Please try again later',

        'VAL_001' => 'Required field is missing',
        'VAL_002' => 'Invalid email address format',
        'VAL_003' => 'Invalid format for this field',
        'VAL_004' => 'Value is too short',
        'VAL_005' => 'Value is too long',
        'VAL_006' => 'Invalid data type',
        'VAL_007' => 'Value is out of allowed range',

        'BILL_001' => 'Insufficient credits to complete this operation',
        'BILL_002' => 'You have reached your plan limit',
        'BILL_003' => 'Payment processing failed',
        'BILL_004' => 'Invalid promo code',
        'BILL_005' => 'This promo code has expired',
        'BILL_006' => 'Your subscription has been cancelled',
        'BILL_007' => 'Please upgrade your plan to access this feature',

        'USAGE_001' => 'Usage limit exceeded for your plan',
        'USAGE_002' => 'Rate limit exceeded. Please slow down',
        'USAGE_003' => 'Monthly quota exceeded',
        'USAGE_004' => 'Maximum concurrent operations reached',

        'FILE_001' => 'File not found',
        'FILE_002' => 'File size exceeds maximum allowed',
        'FILE_003' => 'Invalid file type',
        'FILE_004' => 'File upload failed',
        'FILE_005' => 'File is corrupted or unreadable',
        'FILE_006' => 'Invalid file path detected',

        'DATA_001' => 'Requested resource not found',
        'DATA_002' => 'Resource already exists',
        'DATA_003' => 'Resource is in an invalid state',
        'DATA_004' => 'A data export is already in progress',
        'DATA_005' => 'Data export failed',

        'BACKUP_001' => 'Backup creation failed',
        'BACKUP_002' => 'Backup restore failed',
        'BACKUP_003' => 'Backup file not found',
        'BACKUP_004' => 'Backup file is corrupted',
        'BACKUP_005' => 'Insufficient disk space for backup',
        'BACKUP_006' => 'Another backup is already in progress',

        'WEBHOOK_001' => 'Webhook signature verification failed',
        'WEBHOOK_002' => 'Invalid webhook payload',
        'WEBHOOK_003' => 'Webhook processing failed',

        'API_001' => 'Invalid API key',
        'API_002' => 'API key has expired',
        'API_003' => 'API rate limit exceeded',
        'API_004' => 'External service error',

        'SERVER_001' => 'Internal server error',
        'SERVER_002' => 'Database connection error',
        'SERVER_003' => 'Request timeout',
        'SERVER_004' => 'System is under maintenance',

        'SEC_001' => 'CSRF token validation failed',
        'SEC_002' => 'Permission denied',
        'SEC_003' => 'Suspicious activity detected',
        'SEC_004' => 'Your IP address has been blocked',
    ];

    public static function format(string $code, ?string $customMessage = null): array {
        $message = $customMessage ?? self::$messages[$code] ?? 'An error occurred';

        return [
            'error' => true,
            'code' => $code,
            'message' => $message,
            'timestamp' => date('c')
        ];
    }

    public static function json(string $code, ?string $customMessage = null, int $httpCode = 400): void {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode(self::format($code, $customMessage));
        exit;
    }

    public static function getMessage(string $code): string {
        return self::$messages[$code] ?? 'Unknown error';
    }

    public static function exists(string $code): bool {
        return isset(self::$messages[$code]);
    }

    public static function logError(string $code, string $context, array $metadata = []): void {
        error_log(sprintf(
            '[%s] %s - Context: %s - Metadata: %s',
            $code,
            self::getMessage($code),
            $context,
            json_encode($metadata)
        ));
    }
}

class AppException extends Exception {
    private $errorCode;
    private $metadata;

    public function __construct(string $errorCode, ?string $customMessage = null, array $metadata = []) {
        $this->errorCode = $errorCode;
        $this->metadata = $metadata;

        $message = $customMessage ?? ErrorCodes::getMessage($errorCode);
        parent::__construct($message);
    }

    public function getErrorCode(): string {
        return $this->errorCode;
    }

    public function getMetadata(): array {
        return $this->metadata;
    }

    public function toArray(): array {
        return [
            'error' => true,
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'metadata' => $this->metadata,
            'timestamp' => date('c')
        ];
    }

    public function toJson(): string {
        return json_encode($this->toArray());
    }
}
