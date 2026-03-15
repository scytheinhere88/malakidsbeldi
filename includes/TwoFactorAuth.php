<?php

class TwoFactorAuth {
    private $pdo;
    private $userId;

    public function __construct($pdo, $userId = null) {
        $this->pdo = $pdo;
        $this->userId = $userId;
    }

    public function setUserId($userId) {
        $this->userId = $userId;
    }

    public function generateSecret() {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $secret;
    }

    public function generateBackupCodes($count = 10) {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = '';
            for ($j = 0; $j < 8; $j++) {
                $code .= random_int(0, 9);
            }
            $codes[] = $code;
        }
        return $codes;
    }

    public function enableTwoFactor($secret, $backupCodes) {
        try {
            $hashedBackupCodes = array_map(function($code) {
                return password_hash($code, PASSWORD_DEFAULT);
            }, $backupCodes);

            $stmt = $this->pdo->prepare("
                INSERT INTO two_factor_auth (user_id, secret, backup_codes, enabled)
                VALUES (?, ?, ?, true)
                ON DUPLICATE KEY UPDATE
                secret = VALUES(secret),
                backup_codes = VALUES(backup_codes),
                enabled = true,
                updated_at = CURRENT_TIMESTAMP
            ");

            $stmt->execute([
                $this->userId,
                $secret,
                json_encode($hashedBackupCodes)
            ]);

            return true;
        } catch (PDOException $e) {
            error_log("2FA Enable Error: " . $e->getMessage());
            return false;
        }
    }

    public function disableTwoFactor() {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE two_factor_auth
                SET enabled = false
                WHERE user_id = ?
            ");
            $stmt->execute([$this->userId]);
            return true;
        } catch (PDOException $e) {
            error_log("2FA Disable Error: " . $e->getMessage());
            return false;
        }
    }

    public function isEnabled() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT enabled FROM two_factor_auth
                WHERE user_id = ?
            ");
            $stmt->execute([$this->userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result && $result['enabled'];
        } catch (PDOException $e) {
            error_log("2FA Check Error: " . $e->getMessage());
            return false;
        }
    }

    public function getSecret() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT secret FROM two_factor_auth
                WHERE user_id = ? AND enabled = true
            ");
            $stmt->execute([$this->userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['secret'] : null;
        } catch (PDOException $e) {
            error_log("2FA Get Secret Error: " . $e->getMessage());
            return null;
        }
    }

    public function verifyTOTP($code, $secret = null) {
        if ($secret === null) {
            $secret = $this->getSecret();
        }

        if (!$secret) {
            return false;
        }

        $code = trim($code);

        $timeSlice = floor(time() / 30);

        for ($i = -2; $i <= 2; $i++) {
            $generated = $this->generateTOTP($secret, $timeSlice + $i);
            if ($generated === $code) {
                return true;
            }
        }

        return false;
    }

    public function verifyBackupCode($code) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT backup_codes FROM two_factor_auth
                WHERE user_id = ? AND enabled = true
            ");
            $stmt->execute([$this->userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                return false;
            }

            $backupCodes = json_decode($result['backup_codes'], true);

            foreach ($backupCodes as $index => $hashedCode) {
                if (password_verify($code, $hashedCode)) {
                    unset($backupCodes[$index]);

                    $stmt = $this->pdo->prepare("
                        UPDATE two_factor_auth
                        SET backup_codes = ?
                        WHERE user_id = ?
                    ");
                    $stmt->execute([json_encode(array_values($backupCodes)), $this->userId]);

                    return true;
                }
            }

            return false;
        } catch (PDOException $e) {
            error_log("2FA Verify Backup Code Error: " . $e->getMessage());
            return false;
        }
    }

    public function getQRCodeURL($email, $secret = null, $issuer = 'BulkReplace') {
        if ($secret === null) {
            $secret = $this->getSecret();
        }

        if (!$secret) {
            return null;
        }

        $encodedIssuer = rawurlencode($issuer);
        $encodedEmail = rawurlencode($email);

        return "otpauth://totp/{$encodedIssuer}:{$encodedEmail}?secret={$secret}&issuer={$encodedIssuer}";
    }

    private function generateTOTP($secret, $timeSlice) {
        $key = $this->base32Decode($secret);

        if ($key === false || $key === '') {
            error_log("TOTP: base32Decode failed for secret: " . $secret);
            return '';
        }

        // Use pack 'N2' for compatibility (big-endian 64-bit)
        $time = pack('N*', 0, $timeSlice);

        $hash = hash_hmac('sha1', $time, $key, true);

        if ($hash === false || strlen($hash) < 20) {
            error_log("TOTP: HMAC failed");
            return '';
        }

        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;

        $code = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;

        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode($secret) {
        if (empty($secret)) {
            error_log("base32Decode: Empty secret");
            return false;
        }

        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32charsFlipped = array_flip(str_split($base32chars));

        // Remove any padding
        $secret = rtrim($secret, '=');

        // Validate all characters
        $secretChars = str_split($secret);
        foreach ($secretChars as $char) {
            if (!isset($base32charsFlipped[$char])) {
                error_log("base32Decode: Invalid character: " . $char);
                return false;
            }
        }

        $binaryString = '';

        // Process in groups of 8 characters
        for ($i = 0; $i < strlen($secret); $i += 8) {
            $chunk = substr($secret, $i, 8);
            $chunkLen = strlen($chunk);

            // Convert each character to 5-bit binary
            $bits = '';
            for ($j = 0; $j < $chunkLen; $j++) {
                $bits .= str_pad(decbin($base32charsFlipped[$chunk[$j]]), 5, '0', STR_PAD_LEFT);
            }

            // Convert 8-bit chunks to bytes
            $chunks = str_split($bits, 8);
            foreach ($chunks as $binChunk) {
                if (strlen($binChunk) == 8) {
                    $binaryString .= chr(bindec($binChunk));
                }
            }
        }

        if (empty($binaryString)) {
            error_log("base32Decode: Result is empty");
            return false;
        }

        return $binaryString;
    }

    public function getRemainingBackupCodes() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT backup_codes FROM two_factor_auth
                WHERE user_id = ? AND enabled = true
            ");
            $stmt->execute([$this->userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                $codes = json_decode($result['backup_codes'], true);
                return count($codes);
            }

            return 0;
        } catch (PDOException $e) {
            error_log("2FA Get Backup Codes Count Error: " . $e->getMessage());
            return 0;
        }
    }

    public function regenerateBackupCodes($newBackupCodes) {
        try {
            $hashedBackupCodes = array_map(function($code) {
                return password_hash($code, PASSWORD_DEFAULT);
            }, $newBackupCodes);

            $stmt = $this->pdo->prepare("
                UPDATE two_factor_auth
                SET backup_codes = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ? AND enabled = true
            ");

            $stmt->execute([
                json_encode($hashedBackupCodes),
                $this->userId
            ]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("2FA Regenerate Backup Codes Error: " . $e->getMessage());
            return false;
        }
    }
}
