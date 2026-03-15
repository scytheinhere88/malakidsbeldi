<?php

define('TEST_MODE', true);
define('APP_NAME', 'BulkReplace');
define('APP_URL', 'https://bulkreplacetool.com');
define('APP_SALT', 'test-salt-32-characters-long-pad');

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

function createTestDb(): PDO {
    $pdo = new PDO(
        'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'bulk_bulkreplacetool') . ';charset=utf8mb4',
        $_ENV['DB_USER'] ?? 'root',
        $_ENV['DB_PASS'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $prefix = 'test_' . substr(md5(uniqid('', true)), 0, 8) . '_';

    $pdo->exec("
        CREATE TEMPORARY TABLE {$prefix}users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            plan VARCHAR(50) DEFAULT 'free',
            billing_cycle VARCHAR(50) DEFAULT 'none',
            plan_expires_at DATETIME NULL,
            plan_status VARCHAR(50) DEFAULT 'active',
            rollover_balance INT DEFAULT 0,
            gumroad_license VARCHAR(255) NULL,
            gumroad_sale_id VARCHAR(255) NULL,
            subscription_cancelled_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=MEMORY
    ");

    $pdo->exec("
        CREATE TEMPORARY TABLE {$prefix}email_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            to_email VARCHAR(255) NOT NULL,
            to_name VARCHAR(255) DEFAULT '',
            from_email VARCHAR(255) NOT NULL,
            from_name VARCHAR(255) NOT NULL,
            reply_to VARCHAR(255) NULL,
            subject TEXT NOT NULL,
            body_html MEDIUMTEXT NOT NULL,
            body_text MEDIUMTEXT DEFAULT '',
            template_key VARCHAR(100) NULL,
            priority INT DEFAULT 5,
            status VARCHAR(50) DEFAULT 'pending',
            attempts INT DEFAULT 0,
            max_attempts INT DEFAULT 3,
            scheduled_at DATETIME NULL,
            sent_at DATETIME NULL,
            failed_at DATETIME NULL,
            error_message TEXT NULL,
            metadata TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=MEMORY
    ");

    $pdo->exec("
        CREATE TEMPORARY TABLE {$prefix}email_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            queue_id INT NULL,
            user_id INT NULL,
            to_email VARCHAR(255) NOT NULL,
            subject TEXT NOT NULL,
            template_key VARCHAR(100) NULL,
            status VARCHAR(50) NOT NULL,
            sent_at DATETIME NULL,
            metadata TEXT NULL
        ) ENGINE=MEMORY
    ");

    $pdo->exec("
        CREATE TEMPORARY TABLE {$prefix}email_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNIQUE NOT NULL,
            unsubscribed_at DATETIME NULL,
            welcome_emails TINYINT DEFAULT 1,
            invoice_emails TINYINT DEFAULT 1,
            plan_expiry_warnings TINYINT DEFAULT 1
        ) ENGINE=MEMORY
    ");

    $pdo->exec("
        CREATE TEMPORARY TABLE {$prefix}email_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_key VARCHAR(100) UNIQUE NOT NULL,
            subject TEXT NOT NULL,
            body_html MEDIUMTEXT NOT NULL,
            body_text MEDIUMTEXT DEFAULT '',
            is_active TINYINT DEFAULT 1
        ) ENGINE=MEMORY
    ");

    $pdo->exec("
        CREATE TEMPORARY TABLE {$prefix}grace_periods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            original_plan VARCHAR(50) NOT NULL,
            target_plan VARCHAR(50) NOT NULL,
            grace_started_at DATETIME NOT NULL,
            grace_ends_at DATETIME NOT NULL,
            downgrade_executed TINYINT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=MEMORY
    ");

    $pdo->exec("
        CREATE TEMPORARY TABLE {$prefix}plan_changes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            from_plan VARCHAR(50) NOT NULL,
            to_plan VARCHAR(50) NOT NULL,
            change_type VARCHAR(50) NOT NULL,
            prorated_amount DECIMAL(10,2) DEFAULT 0,
            days_remaining INT DEFAULT 0,
            reason VARCHAR(100) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=MEMORY
    ");

    $pdo->exec("
        CREATE TEMPORARY TABLE {$prefix}audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            admin_id INT NULL,
            action_type VARCHAR(100) NOT NULL,
            action_category VARCHAR(50) NOT NULL,
            target_type VARCHAR(50) NULL,
            target_id VARCHAR(255) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            request_data TEXT NULL,
            response_data TEXT NULL,
            status VARCHAR(50) DEFAULT 'success',
            error_message TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=MEMORY
    ");

    $pdo->exec("
        CREATE TEMPORARY TABLE {$prefix}login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            status VARCHAR(50) NOT NULL,
            failure_reason VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=MEMORY
    ");

    $pdo->exec("
        CREATE TEMPORARY TABLE {$prefix}password_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=MEMORY
    ");

    $pdo->exec("
        CREATE TEMPORARY TABLE {$prefix}advanced_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            alert_key VARCHAR(100) UNIQUE NOT NULL,
            alert_type VARCHAR(50) NOT NULL,
            severity VARCHAR(20) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            current_value DECIMAL(20,2) NULL,
            threshold_value DECIMAL(20,2) NULL,
            metadata JSON NULL,
            status VARCHAR(20) DEFAULT 'active',
            escalation_level INT DEFAULT 0,
            acknowledged_by INT NULL,
            acknowledged_at DATETIME NULL,
            resolved_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=MEMORY
    ");

    $pdo->exec("
        CREATE TEMPORARY TABLE {$prefix}alert_notification_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            alert_id INT NOT NULL,
            channel VARCHAR(20) NOT NULL,
            recipient VARCHAR(255) NOT NULL,
            sent_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL,
            error_message TEXT NULL
        ) ENGINE=MEMORY
    ");

    $pdo->exec("
        CREATE TEMPORARY TABLE {$prefix}alert_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rule_name VARCHAR(100) UNIQUE NOT NULL,
            metric_name VARCHAR(100) NOT NULL,
            condition_type VARCHAR(20) NOT NULL,
            threshold_value DECIMAL(20,2) NOT NULL,
            severity VARCHAR(20) NOT NULL,
            notification_channels JSON NOT NULL,
            is_active TINYINT DEFAULT 1,
            cooldown_minutes INT DEFAULT 30,
            last_triggered DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=MEMORY
    ");

    $pdo->exec("
        CREATE TEMPORARY TABLE {$prefix}alert_escalation_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            alert_type VARCHAR(50) NOT NULL,
            severity VARCHAR(20) NOT NULL,
            minutes_until_escalation INT NOT NULL,
            escalate_to_severity VARCHAR(20) NOT NULL,
            notify_channels JSON NOT NULL,
            is_active TINYINT DEFAULT 1
        ) ENGINE=MEMORY
    ");

    $pdo->exec("
        CREATE TEMPORARY TABLE {$prefix}user_addons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            addon_id INT NULL,
            addon_slug VARCHAR(100) NOT NULL,
            is_active TINYINT DEFAULT 1,
            purchased_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            gumroad_sale_id VARCHAR(255) NULL
        ) ENGINE=MEMORY
    ");

    $pdo->exec("
        CREATE TEMPORARY TABLE {$prefix}analytics_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(100) NOT NULL,
            event_category VARCHAR(50) NOT NULL,
            user_id INT NULL,
            properties JSON NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=MEMORY
    ");

    $tableMap = [
        'users'                  => "{$prefix}users",
        'email_queue'            => "{$prefix}email_queue",
        'email_logs'             => "{$prefix}email_logs",
        'email_preferences'      => "{$prefix}email_preferences",
        'email_templates'        => "{$prefix}email_templates",
        'grace_periods'          => "{$prefix}grace_periods",
        'plan_changes'           => "{$prefix}plan_changes",
        'audit_logs'             => "{$prefix}audit_logs",
        'login_attempts'         => "{$prefix}login_attempts",
        'password_history'       => "{$prefix}password_history",
        'advanced_alerts'        => "{$prefix}advanced_alerts",
        'alert_notification_logs'=> "{$prefix}alert_notification_logs",
        'alert_rules'            => "{$prefix}alert_rules",
        'alert_escalation_rules' => "{$prefix}alert_escalation_rules",
            'user_addons'            => "{$prefix}user_addons",
        'analytics_events'       => "{$prefix}analytics_events",
    ];

    return new class($pdo, $tableMap) extends PDO {
        private PDO $real;
        private array $map;

        public function __construct(PDO $real, array $map) {
            $this->real = $real;
            $this->map  = $map;
        }

        private function rewrite(string $sql): string {
            foreach ($this->map as $table => $alias) {
                $sql = preg_replace('/\b' . preg_quote($table, '/') . '\b/', $alias, $sql);
            }
            return $sql;
        }

        public function prepare($sql, $options = []): \PDOStatement|false {
            return $this->real->prepare($this->rewrite($sql), $options);
        }

        public function exec($sql): int|false {
            return $this->real->exec($this->rewrite($sql));
        }

        public function query($sql, $fetchMode = null, ...$fetchModeArgs): \PDOStatement|false {
            return $this->real->query($this->rewrite($sql), $fetchMode, ...$fetchModeArgs);
        }

        public function lastInsertId($name = null): string|false {
            return $this->real->lastInsertId($name);
        }

        public function beginTransaction(): bool { return $this->real->beginTransaction(); }
        public function commit(): bool           { return $this->real->commit(); }
        public function rollBack(): bool         { return $this->real->rollBack(); }
        public function inTransaction(): bool    { return $this->real->inTransaction(); }

        public function getAttribute($attribute): mixed {
            return $this->real->getAttribute($attribute);
        }
        public function setAttribute($attribute, $value): bool {
            return $this->real->setAttribute($attribute, $value);
        }
        public function errorCode(): ?string { return $this->real->errorCode(); }
        public function errorInfo(): array   { return $this->real->errorInfo(); }
    };
}
