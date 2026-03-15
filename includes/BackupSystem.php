<?php

class BackupSystem {
    private $pdo;
    private $backupDir;
    private $lockFile;
    private $encryptionKey;
    private $chunkSize = 1000;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->backupDir = __DIR__ . '/../backups';
        $this->lockFile = $this->backupDir . '/.backup.lock';
        $this->encryptionKey = $this->getOrCreateEncryptionKey();

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    private function getOrCreateEncryptionKey() {
        // SECURE: Use environment variable for encryption key
        $key = $_ENV['BACKUP_ENCRYPTION_KEY'] ?? null;

        if ($key) {
            // Validate key length (must be 64 hex chars = 32 bytes)
            if (strlen($key) !== 64 || !ctype_xdigit($key)) {
                throw new Exception('BACKUP_ENCRYPTION_KEY must be 64 hexadecimal characters');
            }
            return $key;
        }

        // FALLBACK: For backward compatibility with old key file
        $keyFile = $this->backupDir . '/.encryption.key';
        if (file_exists($keyFile)) {
            error_log('[WARNING] Using legacy encryption key file. Please migrate to BACKUP_ENCRYPTION_KEY environment variable');
            return file_get_contents($keyFile);
        }

        // Generate new key and save to .env for user to configure
        $newKey = bin2hex(random_bytes(32));
        $envExample = __DIR__ . '/../.env.example';
        $envFile = __DIR__ . '/../.env';

        // Add to .env file with instructions
        $instruction = "\n# ============================================\n";
        $instruction .= "# BACKUP ENCRYPTION KEY (KEEP SECRET!)\n";
        $instruction .= "# Generated: " . date('Y-m-d H:i:s') . "\n";
        $instruction .= "# IMPORTANT: Store this key securely!\n";
        $instruction .= "# ============================================\n";
        $instruction .= "BACKUP_ENCRYPTION_KEY={$newKey}\n";

        if (file_exists($envFile) && is_writable($envFile)) {
            file_put_contents($envFile, $instruction, FILE_APPEND);
        }

        throw new Exception('Backup encryption key generated. Please restart the application to load BACKUP_ENCRYPTION_KEY from .env file.');
    }

    private function encryptData($data) {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', hex2bin($this->encryptionKey), OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    private function decryptData($data) {
        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'aes-256-cbc', hex2bin($this->encryptionKey), OPENSSL_RAW_DATA, $iv);
    }

    private function acquireLock() {
        if (file_exists($this->lockFile)) {
            $lockTime = filemtime($this->lockFile);
            if (time() - $lockTime < 3600) {
                throw new Exception('Another backup is already running. Please wait.');
            }
            unlink($this->lockFile);
        }

        file_put_contents($this->lockFile, date('Y-m-d H:i:s'));
    }

    private function releaseLock() {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
    }

    private function checkDiskSpace($estimatedSize = null) {
        $freeSpace = disk_free_space($this->backupDir);

        if ($freeSpace === false) {
            throw new Exception('Cannot determine available disk space');
        }

        if ($estimatedSize === null) {
            $stmt = $this->pdo->query("
                SELECT SUM(data_length + index_length) as size
                FROM information_schema.TABLES
                WHERE table_schema = DATABASE()
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $estimatedSize = ($result['size'] ?? 0) * 1.5;
        }

        $minSpace = max($estimatedSize, 104857600);

        if ($freeSpace < $minSpace) {
            $freeMB = round($freeSpace / 1024 / 1024, 2);
            $requiredMB = round($minSpace / 1024 / 1024, 2);
            throw new Exception("Insufficient disk space. Available: {$freeMB}MB, Required: ~{$requiredMB}MB");
        }

        return true;
    }

    private function detectBackupFormat($filePath) {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        if ($extension === 'sql') {
            return 'sql';
        } elseif ($extension === 'zip') {
            return 'zip';
        }

        throw new Exception('Unsupported backup format. Only .sql and .zip files are supported.');
    }

    public function createBackup($scheduleId = null, $backupType = 'manual', $createdBy = null, $tables = null, $skipLock = false) {
        if (!$skipLock) {
            $this->acquireLock();
        }

        $logId = $this->createBackupLog($scheduleId, $backupType, $createdBy);

        $this->updateBackupLog($logId, ['status' => 'running', 'started_at' => date('Y-m-d H:i:s')]);

        try {
            $this->checkDiskSpace();
            $timestamp = date('Y-m-d_His');
            $sqlFilename = "backup_{$backupType}_{$timestamp}.sql";
            $zipFilename = "backup_{$backupType}_{$timestamp}.zip";
            $sqlFilepath = $this->backupDir . '/' . $sqlFilename;
            $zipFilepath = $this->backupDir . '/' . $zipFilename;

            $tablesToBackup = $tables ?? $this->getAllTables();

            $tempSqlFile = $this->generateBackupSQL($tablesToBackup);

            rename($tempSqlFile, $sqlFilepath);

            $zip = new ZipArchive();
            if ($zip->open($zipFilepath, ZipArchive::CREATE) !== true) {
                throw new Exception('Cannot create ZIP file');
            }

            $zip->addFile($sqlFilepath, $sqlFilename);

            $checksum = hash_file('sha256', $sqlFilepath);
            $zip->addFromString('checksum.txt', $checksum);

            $metadata = json_encode([
                'created_at' => date('Y-m-d H:i:s'),
                'backup_type' => $backupType,
                'tables' => $tablesToBackup,
                'checksum' => $checksum
            ], JSON_PRETTY_PRINT);
            $zip->addFromString('metadata.json', $metadata);

            $zip->close();

            unlink($sqlFilepath);

            $fileSize = filesize($zipFilepath);
            $rowCount = $this->countBackupRows($tablesToBackup);
            $duration = round(microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? time()), 2);

            $this->updateBackupLog($logId, [
                'status' => 'completed',
                'file_path' => $zipFilepath,
                'file_size' => $fileSize,
                'tables_backed_up' => json_encode($tablesToBackup),
                'rows_backed_up' => $rowCount,
                'completed_at' => date('Y-m-d H:i:s')
            ]);

            $this->logBackupMetrics($logId, [
                'duration_seconds' => $duration,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2),
                'tables_count' => count($tablesToBackup),
                'rows_count' => $rowCount,
                'backup_type' => $backupType,
                'compression_ratio' => $this->calculateCompressionRatio($zipFilepath)
            ]);

            if ($scheduleId) {
                $this->updateScheduleLastRun($scheduleId);
            }

            $this->cleanupOldBackups($scheduleId);

            if (!$skipLock) {
                $this->releaseLock();
            }

            return [
                'success' => true,
                'backup_id' => $logId,
                'file_path' => $zipFilepath,
                'file_size' => $fileSize,
                'tables' => count($tablesToBackup),
                'rows' => $rowCount
            ];

        } catch (Exception $e) {
            if (!$skipLock) {
                $this->releaseLock();
            }

            $this->updateBackupLog($logId, [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => date('Y-m-d H:i:s')
            ]);

            if (isset($sqlFilepath) && file_exists($sqlFilepath)) {
                unlink($sqlFilepath);
            }
            if (isset($zipFilepath) && file_exists($zipFilepath)) {
                unlink($zipFilepath);
            }

            throw $e;
        }
    }

    private function getAllTables() {
        $stmt = $this->pdo->query("SHOW TABLES");
        $tables = [];

        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        return $tables;
    }

    private function generateBackupSQL($tables) {
        $tempFile = tempnam(sys_get_temp_dir(), 'backup_');
        $handle = fopen($tempFile, 'w');

        if (!$handle) {
            throw new Exception('Cannot create temporary backup file');
        }

        fwrite($handle, "-- Database Backup\n");
        fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Tables: " . implode(', ', $tables) . "\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            fwrite($handle, "-- Table: {$table}\n");
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");

            try {
                $createStmt = $this->pdo->query("SHOW CREATE TABLE `{$table}`");
                $createRow = $createStmt->fetch(PDO::FETCH_NUM);
                fwrite($handle, $createRow[1] . ";\n\n");
            } catch (PDOException $e) {
                fclose($handle);
                unlink($tempFile);
                throw new Exception("Failed to get table structure for {$table}: " . $e->getMessage());
            }

            $countStmt = $this->pdo->query("SELECT COUNT(*) as cnt FROM `{$table}`");
            $totalRows = $countStmt->fetch(PDO::FETCH_ASSOC)['cnt'];

            if ($totalRows > 0) {
                $offset = 0;

                while ($offset < $totalRows) {
                    $dataStmt = $this->pdo->prepare("SELECT * FROM `{$table}` LIMIT :limit OFFSET :offset");
                    $dataStmt->bindValue(':limit', $this->chunkSize, PDO::PARAM_INT);
                    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                    $dataStmt->execute();

                    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($rows)) {
                        break;
                    }

                    $columns = array_keys($rows[0]);
                    $columnList = '`' . implode('`, `', $columns) . '`';

                    $values = [];
                    foreach ($rows as $row) {
                        $escapedValues = array_map(function($value) {
                            if ($value === null) {
                                return 'NULL';
                            }
                            return $this->pdo->quote($value);
                        }, array_values($row));

                        $values[] = '(' . implode(', ', $escapedValues) . ')';
                    }

                    if (!empty($values)) {
                        fwrite($handle, "INSERT INTO `{$table}` ({$columnList}) VALUES\n");
                        fwrite($handle, implode(",\n", $values) . ";\n\n");
                    }

                    unset($rows);
                    unset($values);

                    $offset += $this->chunkSize;
                }
            }
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        return $tempFile;
    }

    private function countBackupRows($tables) {
        $total = 0;

        foreach ($tables as $table) {
            $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM `{$table}`");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $total += $row['count'];
        }

        return $total;
    }

    private function createBackupLog($scheduleId, $backupType, $createdBy) {
        $stmt = $this->pdo->prepare("
            INSERT INTO backup_logs
            (schedule_id, backup_type, status, created_by)
            VALUES (:schedule_id, :backup_type, 'pending', :created_by)
        ");

        $stmt->execute([
            'schedule_id' => $scheduleId,
            'backup_type' => $backupType,
            'created_by' => $createdBy
        ]);

        return $this->pdo->lastInsertId();
    }

    private function updateBackupLog($logId, $data) {
        $sets = [];
        $params = ['id' => $logId];

        foreach ($data as $key => $value) {
            $sets[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        $sql = "UPDATE backup_logs SET " . implode(', ', $sets) . " WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    private function updateScheduleLastRun($scheduleId) {
        $stmt = $this->pdo->prepare("
            SELECT frequency FROM backup_schedules WHERE id = :id
        ");
        $stmt->execute(['id' => $scheduleId]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$schedule) return;

        $intervals = [
            'hourly' => '1 HOUR',
            'daily' => '1 DAY',
            'weekly' => '1 WEEK',
            'monthly' => '1 MONTH'
        ];

        $interval = $intervals[$schedule['frequency']] ?? '1 DAY';

        $updateStmt = $this->pdo->prepare("
            UPDATE backup_schedules
            SET last_run = NOW(),
                next_run = DATE_ADD(NOW(), INTERVAL {$interval})
            WHERE id = :id
        ");

        return $updateStmt->execute(['id' => $scheduleId]);
    }

    private function cleanupOldBackups($scheduleId) {
        if (!$scheduleId) return;

        $stmt = $this->pdo->prepare("
            SELECT retention_days FROM backup_schedules WHERE id = :id
        ");
        $stmt->execute(['id' => $scheduleId]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$schedule) return;

        $retentionDays = $schedule['retention_days'];

        $oldBackupsStmt = $this->pdo->prepare("
            SELECT file_path FROM backup_logs
            WHERE schedule_id = :schedule_id
            AND status = 'completed'
            AND created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
        ");

        $oldBackupsStmt->execute([
            'schedule_id' => $scheduleId,
            'days' => $retentionDays
        ]);

        $oldBackups = $oldBackupsStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($oldBackups as $filePath) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        $deleteStmt = $this->pdo->prepare("
            DELETE FROM backup_logs
            WHERE schedule_id = :schedule_id
            AND status = 'completed'
            AND created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
        ");

        $deleteStmt->execute([
            'schedule_id' => $scheduleId,
            'days' => $retentionDays
        ]);
    }

    public function getBackupLogs($limit = 50, $offset = 0) {
        $stmt = $this->pdo->prepare("
            SELECT bl.*, bs.frequency, bs.retention_days
            FROM backup_logs bl
            LEFT JOIN backup_schedules bs ON bl.schedule_id = bs.id
            ORDER BY bl.created_at DESC
            LIMIT :limit OFFSET :offset
        ");

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getBackupStats() {
        $stmt = $this->pdo->query("
            SELECT
                COUNT(*) as total_backups,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(file_size) as total_size,
                MAX(created_at) as last_backup
            FROM backup_logs
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function logRestoreOperation($backupId, $status, $preRestoreBackupId = null, $error = null) {
        try {
            $logStmt = $this->pdo->prepare("
                INSERT INTO backup_recovery_log
                (backup_id, pre_restore_backup_id, status, error_message, created_at)
                VALUES (:backup_id, :pre_restore_backup_id, :status, :error, NOW())
            ");

            $logStmt->execute([
                'backup_id' => $backupId,
                'pre_restore_backup_id' => $preRestoreBackupId,
                'status' => $status,
                'error' => $error
            ]);
        } catch (PDOException $e) {
        }
    }

    public function restoreBackup($backupId, $dryRun = false) {
        $this->acquireLock();

        try {
            $stmt = $this->pdo->prepare("
                SELECT file_path FROM backup_logs WHERE id = :id AND status = 'completed'
            ");
            $stmt->execute(['id' => $backupId]);
            $backup = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$backup || !file_exists($backup['file_path'])) {
                throw new Exception('Backup file not found');
            }

            $backupFormat = $this->detectBackupFormat($backup['file_path']);

            if (!$dryRun) {
                $preRestoreBackup = $this->createBackup(null, 'pre_restore', null, null, true);

                if (!$preRestoreBackup['success']) {
                    throw new Exception('Failed to create pre-restore backup');
                }
            }

            $tempDir = sys_get_temp_dir() . '/backup_restore_' . uniqid();
            mkdir($tempDir, 0755, true);

            try {
                if ($backupFormat === 'zip') {
                    $zip = new ZipArchive();
                    if ($zip->open($backup['file_path']) !== true) {
                        throw new Exception('Cannot open backup ZIP file');
                    }

                    $zip->extractTo($tempDir);
                    $zip->close();

                    $sqlFiles = glob($tempDir . '/*.sql');
                    if (empty($sqlFiles)) {
                        throw new Exception('No SQL file found in backup');
                    }

                    $sqlFile = $sqlFiles[0];

                    $checksumFile = $tempDir . '/checksum.txt';
                    if (file_exists($checksumFile)) {
                        $expectedChecksum = trim(file_get_contents($checksumFile));
                        $actualChecksum = hash_file('sha256', $sqlFile);

                        if ($expectedChecksum !== $actualChecksum) {
                            throw new Exception('Backup file integrity check failed! File may be corrupted.');
                        }
                    }
                } else {
                    $sqlFile = $backup['file_path'];
                }

                if ($dryRun) {
                    $this->cleanupTempDir($tempDir);
                    $this->releaseLock();

                    return [
                        'success' => true,
                        'dry_run' => true,
                        'format' => $backupFormat,
                        'message' => 'Backup validation successful. Ready to restore.'
                    ];
                }

                $handle = fopen($sqlFile, 'r');
                if (!$handle) {
                    throw new Exception('Cannot read SQL file');
                }

                $this->pdo->beginTransaction();

                try {
                    $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');

                    $query = '';
                    $lineNumber = 0;

                    while (($line = fgets($handle)) !== false) {
                        $lineNumber++;

                        if (trim($line) == '' || strpos($line, '--') === 0) {
                            continue;
                        }

                        $query .= $line;

                        if (substr(trim($query), -1) == ';') {
                            try {
                                $this->pdo->exec($query);
                            } catch (PDOException $e) {
                                throw new Exception("SQL execution error at line {$lineNumber}: " . $e->getMessage());
                            }
                            $query = '';
                        }
                    }

                    fclose($handle);

                    $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');

                    $this->pdo->commit();

                } catch (Exception $e) {
                    $this->pdo->rollBack();

                    if (isset($handle) && is_resource($handle)) {
                        fclose($handle);
                    }

                    throw new Exception('Restore failed and rolled back: ' . $e->getMessage());
                }

                $this->cleanupTempDir($tempDir);

                $this->logRestoreOperation($backupId, 'success', $preRestoreBackup['backup_id'] ?? null);

                $this->releaseLock();

                return [
                    'success' => true,
                    'pre_restore_backup_id' => $preRestoreBackup['backup_id'] ?? null,
                    'format' => $backupFormat
                ];

            } catch (Exception $e) {
                if (isset($tempDir) && is_dir($tempDir)) {
                    $this->cleanupTempDir($tempDir);
                }

                $this->logRestoreOperation($backupId, 'failed', $preRestoreBackup['backup_id'] ?? null, $e->getMessage());

                throw $e;
            }

        } catch (Exception $e) {
            $this->logRestoreOperation($backupId, 'failed', null, $e->getMessage());
            $this->releaseLock();
            throw $e;
        }
    }

    public function getRecoveryHistory($limit = 20) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    brl.*,
                    bl.backup_type,
                    bl.created_at as backup_created_at,
                    pb.backup_type as pre_restore_type
                FROM backup_recovery_log brl
                INNER JOIN backup_logs bl ON brl.backup_id = bl.id
                LEFT JOIN backup_logs pb ON brl.pre_restore_backup_id = pb.id
                ORDER BY brl.created_at DESC
                LIMIT :limit
            ");

            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getBackupHealth() {
        $stmt = $this->pdo->query("
            SELECT
                COUNT(*) as total_backups,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(file_size) as total_size,
                AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as avg_duration_seconds
            FROM backup_logs
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $successRate = $result['total_backups'] > 0
            ? round(($result['completed'] / $result['total_backups']) * 100, 2)
            : 0;

        return array_merge($result, [
            'success_rate' => $successRate,
            'health_status' => $successRate >= 95 ? 'healthy' : ($successRate >= 80 ? 'warning' : 'critical')
        ]);
    }

    private function cleanupTempDir($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->cleanupTempDir($path) : unlink($path);
        }

        rmdir($dir);
    }

    public function downloadBackup($backupId, $userId, $isAdmin = false) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT bl.*, u.email
                FROM backup_logs bl
                LEFT JOIN users u ON bl.created_by = u.id
                WHERE bl.id = :id AND bl.status = 'completed'
            ");
            $stmt->execute(['id' => $backupId]);
            $backup = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$backup) {
                throw new Exception('Backup not found or not completed');
            }

            if (!$isAdmin && $backup['created_by'] != $userId) {
                throw new Exception('Unauthorized access to backup file');
            }

            if (!file_exists($backup['file_path'])) {
                throw new Exception('Backup file not found on disk');
            }

            // SECURITY: Validate file path is within backup directory to prevent path traversal
            $backupDir = realpath(__DIR__ . '/../backups');
            $filePath = realpath($backup['file_path']);

            if ($filePath === false || strpos($filePath, $backupDir) !== 0) {
                error_log("SECURITY ALERT: Path traversal attempt detected - File: {$backup['file_path']}, Real: {$filePath}, User: {$userId}");
                throw new Exception('Invalid backup file path');
            }

            // Validate file is readable
            if (!is_readable($filePath)) {
                throw new Exception('Backup file not readable');
            }

            $checksum = hash_file('sha256', $filePath);

            $this->logBackupDownload($backupId, $userId);

            return [
                'success' => true,
                'file_path' => $filePath,
                'filename' => basename($filePath),
                'file_size' => filesize($filePath),
                'checksum' => $checksum,
                'backup_type' => $backup['backup_type'],
                'created_at' => $backup['created_at']
            ];

        } catch (Exception $e) {
            throw $e;
        }
    }

    private function logBackupDownload($backupId, $userId) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO backup_downloads (backup_id, user_id, downloaded_at, ip_address)
                VALUES (:backup_id, :user_id, NOW(), :ip)
            ");

            $stmt->execute([
                'backup_id' => $backupId,
                'user_id' => $userId,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        } catch (PDOException $e) {
        }
    }

    public function verifyBackupIntegrity($backupId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT file_path FROM backup_logs WHERE id = :id AND status = 'completed'
            ");
            $stmt->execute(['id' => $backupId]);
            $backup = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$backup || !file_exists($backup['file_path'])) {
                return [
                    'valid' => false,
                    'error' => 'Backup file not found'
                ];
            }

            $backupFormat = $this->detectBackupFormat($backup['file_path']);

            if ($backupFormat === 'zip') {
                $zip = new ZipArchive();
                if ($zip->open($backup['file_path']) !== true) {
                    return [
                        'valid' => false,
                        'error' => 'Cannot open ZIP file'
                    ];
                }

                $checksumFile = $zip->getFromName('checksum.txt');
                $zip->close();

                if ($checksumFile === false) {
                    return [
                        'valid' => false,
                        'error' => 'No checksum file found in backup'
                    ];
                }

                $tempDir = sys_get_temp_dir() . '/verify_' . uniqid();
                mkdir($tempDir, 0755, true);

                $zip->open($backup['file_path']);
                $zip->extractTo($tempDir);
                $zip->close();

                $sqlFiles = glob($tempDir . '/*.sql');
                if (empty($sqlFiles)) {
                    $this->cleanupTempDir($tempDir);
                    return [
                        'valid' => false,
                        'error' => 'No SQL file found in backup'
                    ];
                }

                $actualChecksum = hash_file('sha256', $sqlFiles[0]);
                $expectedChecksum = trim($checksumFile);

                $this->cleanupTempDir($tempDir);

                if ($actualChecksum !== $expectedChecksum) {
                    return [
                        'valid' => false,
                        'error' => 'Checksum mismatch - file may be corrupted',
                        'expected' => $expectedChecksum,
                        'actual' => $actualChecksum
                    ];
                }

                return [
                    'valid' => true,
                    'checksum' => $actualChecksum,
                    'format' => 'zip'
                ];

            } else {
                $checksum = hash_file('sha256', $backup['file_path']);
                return [
                    'valid' => true,
                    'checksum' => $checksum,
                    'format' => 'sql'
                ];
            }

        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getDownloadHistory($backupId = null, $limit = 50) {
        try {
            if ($backupId) {
                $stmt = $this->pdo->prepare("
                    SELECT bd.*, u.email, bl.backup_type
                    FROM backup_downloads bd
                    INNER JOIN users u ON bd.user_id = u.id
                    INNER JOIN backup_logs bl ON bd.backup_id = bl.id
                    WHERE bd.backup_id = :backup_id
                    ORDER BY bd.downloaded_at DESC
                    LIMIT :limit
                ");
                $stmt->bindValue(':backup_id', $backupId, PDO::PARAM_INT);
            } else {
                $stmt = $this->pdo->prepare("
                    SELECT bd.*, u.email, bl.backup_type
                    FROM backup_downloads bd
                    INNER JOIN users u ON bd.user_id = u.id
                    INNER JOIN backup_logs bl ON bd.backup_id = bl.id
                    ORDER BY bd.downloaded_at DESC
                    LIMIT :limit
                ");
            }

            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    public function encryptBackup($backupId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT file_path FROM backup_logs WHERE id = :id AND status = 'completed'
            ");
            $stmt->execute(['id' => $backupId]);
            $backup = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$backup || !file_exists($backup['file_path'])) {
                throw new Exception('Backup file not found');
            }

            $originalPath = $backup['file_path'];
            $encryptedPath = $originalPath . '.encrypted';

            $data = file_get_contents($originalPath);
            $encrypted = $this->encryptData($data);
            file_put_contents($encryptedPath, $encrypted);

            $checksum = hash_file('sha256', $encryptedPath);

            $this->pdo->prepare("
                UPDATE backup_logs
                SET file_path = :new_path,
                    is_encrypted = 1,
                    encryption_checksum = :checksum
                WHERE id = :id
            ")->execute([
                'new_path' => $encryptedPath,
                'checksum' => $checksum,
                'id' => $backupId
            ]);

            unlink($originalPath);

            return [
                'success' => true,
                'encrypted_path' => $encryptedPath,
                'checksum' => $checksum
            ];

        } catch (Exception $e) {
            throw $e;
        }
    }

    public function decryptBackup($backupId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT file_path, is_encrypted FROM backup_logs WHERE id = :id AND status = 'completed'
            ");
            $stmt->execute(['id' => $backupId]);
            $backup = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$backup || !file_exists($backup['file_path'])) {
                throw new Exception('Backup file not found');
            }

            if (!$backup['is_encrypted']) {
                throw new Exception('Backup is not encrypted');
            }

            $encryptedPath = $backup['file_path'];
            $decryptedPath = str_replace('.encrypted', '', $encryptedPath);

            $encrypted = file_get_contents($encryptedPath);
            $decrypted = $this->decryptData($encrypted);
            file_put_contents($decryptedPath, $decrypted);

            $this->pdo->prepare("
                UPDATE backup_logs
                SET file_path = :new_path,
                    is_encrypted = 0,
                    encryption_checksum = NULL
                WHERE id = :id
            ")->execute([
                'new_path' => $decryptedPath,
                'id' => $backupId
            ]);

            unlink($encryptedPath);

            return [
                'success' => true,
                'decrypted_path' => $decryptedPath
            ];

        } catch (Exception $e) {
            throw $e;
        }
    }

    private function logBackupMetrics($backupId, array $metrics) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO backup_metrics
                (backup_id, duration_seconds, file_size_mb, tables_count, rows_count, backup_type, compression_ratio, created_at)
                VALUES (:backup_id, :duration, :size, :tables, :rows, :type, :ratio, NOW())
            ");

            $stmt->execute([
                'backup_id' => $backupId,
                'duration' => $metrics['duration_seconds'],
                'size' => $metrics['file_size_mb'],
                'tables' => $metrics['tables_count'],
                'rows' => $metrics['rows_count'],
                'type' => $metrics['backup_type'],
                'ratio' => $metrics['compression_ratio']
            ]);
        } catch (PDOException $e) {
            error_log("Failed to log backup metrics: " . $e->getMessage());
        }
    }

    private function calculateCompressionRatio($zipPath) {
        try {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return 0;
            }

            $uncompressedSize = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $uncompressedSize += $stat['size'];
            }

            $zip->close();

            $compressedSize = filesize($zipPath);

            if ($uncompressedSize > 0) {
                return round((1 - ($compressedSize / $uncompressedSize)) * 100, 2);
            }

            return 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    public function getBackupPerformanceMetrics($days = 30) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT
                    DATE(created_at) as date,
                    COUNT(*) as backup_count,
                    AVG(duration_seconds) as avg_duration,
                    AVG(file_size_mb) as avg_size,
                    AVG(compression_ratio) as avg_compression,
                    SUM(rows_count) as total_rows
                FROM backup_metrics
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY DATE(created_at)
                ORDER BY date DESC
            ");

            $stmt->execute(['days' => $days]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
}
