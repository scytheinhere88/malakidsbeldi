<?php

class ValidationHelper {

    public static function email(string $email): bool {
        $email = trim($email);
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && strlen($email) <= 255
            && preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $email);
    }

    public static function domain(string $domain): bool {
        $domain = trim($domain);
        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$/i', $domain);
    }

    public static function url(string $url): bool {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && preg_match('/^https?:\/\//i', $url);
    }

    public static function username(string $username): bool {
        return preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $username);
    }

    public static function password(string $password): array {
        $errors = [];

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters';
        }

        if (strlen($password) > 72) {
            $errors[] = 'Password must be less than 72 characters';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    public static function phone(string $phone): bool {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        return strlen($phone) >= 10 && strlen($phone) <= 15;
    }

    public static function integer(mixed $value, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): bool {
        if (!is_numeric($value)) {
            return false;
        }

        $int = (int)$value;
        return $int >= $min && $int <= $max;
    }

    public static function float(mixed $value, float $min = PHP_FLOAT_MIN, float $max = PHP_FLOAT_MAX): bool {
        if (!is_numeric($value)) {
            return false;
        }

        $float = (float)$value;
        return $float >= $min && $float <= $max;
    }

    public static function inArray(mixed $value, array $allowed): bool {
        return in_array($value, $allowed, true);
    }

    public static function length(string $value, int $min, int $max): bool {
        $len = strlen($value);
        return $len >= $min && $len <= $max;
    }

    public static function alphanumeric(string $value): bool {
        return preg_match('/^[a-zA-Z0-9]+$/', $value);
    }

    public static function slug(string $value): bool {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value);
    }

    public static function date(string $value, string $format = 'Y-m-d'): bool {
        $d = DateTime::createFromFormat($format, $value);
        return $d && $d->format($format) === $value;
    }

    public static function filepath(string $path, array $allowedExtensions = []): array {
        $errors = [];

        if (strpos($path, '..') !== false) {
            $errors[] = 'Path traversal detected';
        }

        if (preg_match('/[<>:"|?*]/', $path)) {
            $errors[] = 'Invalid characters in path';
        }

        if (!empty($allowedExtensions)) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExtensions)) {
                $errors[] = 'Invalid file extension';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    public static function json(string $value): bool {
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    public static function uuid(string $value): bool {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }

    public static function ipAddress(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    public static function sanitizeString(string $value, bool $allowHtml = false): string {
        if (!$allowHtml) {
            return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
        }

        return strip_tags(trim($value), '<p><br><strong><em><ul><ol><li><a>');
    }

    public static function sanitizeEmail(string $email): string {
        return strtolower(trim(filter_var($email, FILTER_SANITIZE_EMAIL)));
    }

    public static function sanitizeUrl(string $url): string {
        return filter_var($url, FILTER_SANITIZE_URL);
    }

    public static function sanitizeFilename(string $filename): string {
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        $filename = preg_replace('/_+/', '_', $filename);
        return trim($filename, '_');
    }

    public static function required(mixed $value): bool {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return !empty($value);
        }

        return $value !== null;
    }

    public static function validateRequest(array $rules, array $data): array {
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule => $params) {
                $valid = false;

                switch ($rule) {
                    case 'required':
                        $valid = self::required($value);
                        if (!$valid) $errors[$field][] = "{$field} is required";
                        break;

                    case 'email':
                        $valid = self::email($value);
                        if (!$valid) $errors[$field][] = "{$field} must be a valid email";
                        break;

                    case 'length':
                        $valid = self::length($value, $params['min'] ?? 0, $params['max'] ?? PHP_INT_MAX);
                        if (!$valid) $errors[$field][] = "{$field} length must be between {$params['min']} and {$params['max']}";
                        break;

                    case 'in':
                        $valid = self::inArray($value, $params);
                        if (!$valid) $errors[$field][] = "{$field} must be one of: " . implode(', ', $params);
                        break;

                    case 'integer':
                        $valid = self::integer($value, $params['min'] ?? PHP_INT_MIN, $params['max'] ?? PHP_INT_MAX);
                        if (!$valid) $errors[$field][] = "{$field} must be a valid integer";
                        break;
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    public static function sanitizeArray(array $data, array $rules): array {
        $sanitized = [];

        foreach ($rules as $field => $rule) {
            if (!isset($data[$field])) {
                continue;
            }

            $value = $data[$field];

            switch ($rule) {
                case 'string':
                    $sanitized[$field] = self::sanitizeString($value);
                    break;

                case 'email':
                    $sanitized[$field] = self::sanitizeEmail($value);
                    break;

                case 'url':
                    $sanitized[$field] = self::sanitizeUrl($value);
                    break;

                case 'int':
                    $sanitized[$field] = (int)$value;
                    break;

                case 'float':
                    $sanitized[$field] = (float)$value;
                    break;

                case 'bool':
                    $sanitized[$field] = (bool)$value;
                    break;

                default:
                    $sanitized[$field] = $value;
            }
        }

        return $sanitized;
    }
}
