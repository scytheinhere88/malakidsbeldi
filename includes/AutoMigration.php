<?php

class AutoMigration
{
    private $conn;

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    public function runMigrations()
    {
        try {
            $this->ensureLicensesTable();
            $this->ensureProductMappingsTable();
            $this->seedProductMappings();
            $this->ensureAutopilotTables();
            $this->ensureUsersSecurityColumns();
            $this->ensurePasswordHistoryTable();
            $this->ensureEmailSystemTables();
            return true;
        } catch (Exception $e) {
            error_log("AutoMigration error: " . $e->getMessage());
            return false;
        }
    }

    private function ensureLicensesTable()
    {
        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS licenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                license_key VARCHAR(100) UNIQUE NOT NULL,
                product_id VARCHAR(100) NOT NULL,
                product_slug VARCHAR(50) NOT NULL,
                sale_id VARCHAR(100) NOT NULL,
                email VARCHAR(255) NOT NULL,
                user_id INT NULL,
                status ENUM('active', 'inactive', 'revoked', 'expired') DEFAULT 'inactive',
                activated_at DATETIME NULL,
                expires_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                metadata JSON NULL,
                INDEX idx_license_key (license_key),
                INDEX idx_email (email),
                INDEX idx_user_id (user_id),
                INDEX idx_product_id (product_id),
                INDEX idx_sale_id (sale_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $checkColumn = $this->conn->query("SHOW COLUMNS FROM licenses LIKE 'product_id'");
        if ($checkColumn->rowCount() === 0) {
            $this->conn->exec("ALTER TABLE licenses ADD COLUMN product_id VARCHAR(100) NOT NULL AFTER license_key");
            $this->conn->exec("ALTER TABLE licenses ADD INDEX idx_product_id (product_id)");
        }

        $checkColumn2 = $this->conn->query("SHOW COLUMNS FROM licenses LIKE 'product_slug'");
        if ($checkColumn2->rowCount() === 0) {
            $this->conn->exec("ALTER TABLE licenses ADD COLUMN product_slug VARCHAR(50) NOT NULL AFTER product_id");
        }

        $checkColumn3 = $this->conn->query("SHOW COLUMNS FROM licenses LIKE 'metadata'");
        if ($checkColumn3->rowCount() === 0) {
            $this->conn->exec("ALTER TABLE licenses ADD COLUMN metadata JSON NULL");
        }
    }

    private function ensureProductMappingsTable()
    {
        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS product_mappings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id VARCHAR(100) UNIQUE NOT NULL,
                product_slug VARCHAR(50) UNIQUE NOT NULL,
                product_name VARCHAR(100) NOT NULL,
                product_type ENUM('subscription', 'lifetime', 'addon', 'bundle') NOT NULL,
                billing_cycle ENUM('monthly', 'yearly', 'one-time') NOT NULL,
                plan_level VARCHAR(20) NULL,
                features JSON NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_product_slug (product_slug),
                INDEX idx_product_type (product_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function seedProductMappings()
    {
        $products = [
            [
                'product_id' => '7nQs3PYRz6Wc_zSwZEKmpA==',
                'product_slug' => 'pro-monthly-plan',
                'product_name' => 'Pro Monthly Plan',
                'product_type' => 'subscription',
                'billing_cycle' => 'monthly',
                'plan_level' => 'pro',
                'features' => json_encode(['csv_generator' => true, 'zip_manager' => false, 'copy_rename' => false, 'autopilot' => false])
            ],
            [
                'product_id' => '5FemOQM3T5CTJcoxLBz9lA==',
                'product_slug' => 'pro-yearly-plan',
                'product_name' => 'Pro Yearly Plan',
                'product_type' => 'subscription',
                'billing_cycle' => 'yearly',
                'plan_level' => 'pro',
                'features' => json_encode(['csv_generator' => true, 'zip_manager' => false, 'copy_rename' => false, 'autopilot' => false])
            ],
            [
                'product_id' => 'IsVC3Fk2_BX3eoeUBsmMHQ==',
                'product_slug' => 'platinum-monthly-plan',
                'product_name' => 'Platinum Monthly Plan',
                'product_type' => 'subscription',
                'billing_cycle' => 'monthly',
                'plan_level' => 'platinum',
                'features' => json_encode(['csv_generator' => true, 'zip_manager' => true, 'copy_rename' => true, 'autopilot' => true])
            ],
            [
                'product_id' => 'QqX3oihPnbHi56uTL33xtw==',
                'product_slug' => 'platinum-yearly-plan',
                'product_name' => 'Platinum Yearly Plan',
                'product_type' => 'subscription',
                'billing_cycle' => 'yearly',
                'plan_level' => 'platinum',
                'features' => json_encode(['csv_generator' => true, 'zip_manager' => true, 'copy_rename' => true, 'autopilot' => true])
            ],
            [
                'product_id' => 'v7bddkeH5_4CZOF-rvdVYg==',
                'product_slug' => 'lifetime-access-plan',
                'product_name' => 'Lifetime Access Plan',
                'product_type' => 'lifetime',
                'billing_cycle' => 'one-time',
                'plan_level' => 'platinum',
                'features' => json_encode(['csv_generator' => true, 'zip_manager' => true, 'copy_rename' => true, 'autopilot' => true])
            ],
            [
                'product_id' => 'n3naDS2BY26jmjBgz8iQkQ==',
                'product_slug' => 'csv-generator-addon',
                'product_name' => 'CSV Generator Add-on',
                'product_type' => 'addon',
                'billing_cycle' => 'one-time',
                'plan_level' => null,
                'features' => json_encode(['csv_generator' => true])
            ],
            [
                'product_id' => 'RnSl8osTSdq8ObTYFGuZWw==',
                'product_slug' => 'zip-manager-addon',
                'product_name' => 'ZIP Manager Add-on',
                'product_type' => 'addon',
                'billing_cycle' => 'one-time',
                'plan_level' => null,
                'features' => json_encode(['zip_manager' => true])
            ],
            [
                'product_id' => 'qHSLgP8ikGyof7yc-PNU5Q==',
                'product_slug' => 'copy-rename-addon',
                'product_name' => 'Copy & Rename Add-on',
                'product_type' => 'addon',
                'billing_cycle' => 'one-time',
                'plan_level' => null,
                'features' => json_encode(['copy_rename' => true])
            ],
            [
                'product_id' => 'Rv3lRIIKoziiUCsyZT_8xg==',
                'product_slug' => 'ai-autopilot-bundle',
                'product_name' => 'AI Autopilot Bundle',
                'product_type' => 'addon',
                'billing_cycle' => 'one-time',
                'plan_level' => null,
                'features' => json_encode(['autopilot' => true])
            ],
            [
                'product_id' => 'BWD85J4nF3sS9ggXMdtTqQ==',
                'product_slug' => 'all-in-one-bundle',
                'product_name' => 'All-in-One Bundle',
                'product_type' => 'bundle',
                'billing_cycle' => 'one-time',
                'plan_level' => null,
                'features' => json_encode(['csv_generator' => true, 'zip_manager' => true, 'copy_rename' => true, 'autopilot' => true])
            ]
        ];

        $stmt = $this->conn->prepare("
            INSERT INTO product_mappings
            (product_id, product_slug, product_name, product_type, billing_cycle, plan_level, features)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                product_name = VALUES(product_name),
                product_type = VALUES(product_type),
                billing_cycle = VALUES(billing_cycle),
                plan_level = VALUES(plan_level),
                features = VALUES(features)
        ");

        foreach ($products as $product) {
            try {
                $stmt->execute([
                    $product['product_id'],
                    $product['product_slug'],
                    $product['product_name'],
                    $product['product_type'],
                    $product['billing_cycle'],
                    $product['plan_level'],
                    $product['features']
                ]);
            } catch (Exception $e) {
                error_log("Product mapping seed error: " . $e->getMessage());
            }
        }
    }

    private function ensureUsersSecurityColumns()
    {
        $columns = [
            'reset_token'         => "ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) NULL",
            'reset_token_expires' => "ALTER TABLE users ADD COLUMN reset_token_expires DATETIME NULL",
            'password_updated_at' => "ALTER TABLE users ADD COLUMN password_updated_at DATETIME NULL",
            'account_locked'      => "ALTER TABLE users ADD COLUMN account_locked TINYINT(1) NOT NULL DEFAULT 0",
            'two_factor_secret'   => "ALTER TABLE users ADD COLUMN two_factor_secret VARCHAR(64) NULL",
            'two_factor_enabled'  => "ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0",
            'two_factor_backup_codes' => "ALTER TABLE users ADD COLUMN two_factor_backup_codes TEXT NULL",
        ];

        foreach ($columns as $col => $sql) {
            try {
                $check = $this->conn->query("SHOW COLUMNS FROM users LIKE '$col'");
                if ($check && $check->rowCount() === 0) {
                    $this->conn->exec($sql);
                }
            } catch (Exception $e) {
                error_log("AutoMigration users column '$col': " . $e->getMessage());
            }
        }

        try {
            $check = $this->conn->query("SHOW INDEX FROM users WHERE Key_name = 'idx_reset_token'");
            if ($check && $check->rowCount() === 0) {
                $this->conn->exec("ALTER TABLE users ADD INDEX idx_reset_token (reset_token)");
            }
        } catch (Exception $e) {
            error_log("AutoMigration reset_token index: " . $e->getMessage());
        }
    }

    private function ensurePasswordHistoryTable()
    {
        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS password_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function ensureEmailSystemTables()
    {
        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS email_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                to_email VARCHAR(255) NOT NULL,
                to_name VARCHAR(100) DEFAULT '',
                from_email VARCHAR(255) NULL,
                from_name VARCHAR(100) NULL,
                reply_to VARCHAR(255) NULL,
                subject VARCHAR(255) NOT NULL,
                body_html TEXT NOT NULL,
                body_text TEXT NULL,
                template_key VARCHAR(50) NULL,
                status ENUM('pending','sent','failed') DEFAULT 'pending',
                priority INT DEFAULT 5,
                attempts INT DEFAULT 0,
                max_attempts INT DEFAULT 3,
                scheduled_at DATETIME NULL,
                sent_at DATETIME NULL,
                error_message TEXT NULL,
                metadata JSON NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_priority (priority),
                INDEX idx_scheduled (scheduled_at),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS email_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                template_key VARCHAR(50) UNIQUE NOT NULL,
                name VARCHAR(100) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body_html TEXT NOT NULL,
                body_text TEXT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_template_key (template_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS email_preferences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL UNIQUE,
                welcome_emails TINYINT(1) DEFAULT 1,
                invoice_emails TINYINT(1) DEFAULT 1,
                plan_expiry_warnings TINYINT(1) DEFAULT 1,
                marketing_emails TINYINT(1) DEFAULT 1,
                unsubscribed_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->seedDefaultEmailTemplates();
    }

    private function seedDefaultEmailTemplates()
    {
        $templates = [
            [
                'template_key' => 'password_reset',
                'name'         => 'Password Reset',
                'subject'      => 'Reset your BulkReplace password',
                'body_html'    => '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif;background:#060610;color:#c8c8e8;margin:0;padding:0;}.container{max-width:560px;margin:40px auto;background:#0e0e20;border:1px solid #1e1e38;border-top:3px solid #f0a500;border-radius:12px;overflow:hidden;}.header{padding:32px 40px;border-bottom:1px solid #1e1e38;}.logo{font-size:22px;font-weight:800;color:#fff;}.tagline{font-size:11px;color:#454568;margin-top:4px;font-family:monospace;}.body{padding:32px 40px;}.title{font-size:20px;font-weight:700;color:#fff;margin-bottom:12px;}.text{font-size:13px;color:#9090b8;line-height:1.8;margin-bottom:20px;font-family:monospace;}.btn{display:inline-block;background:linear-gradient(135deg,#f0a500,#c47d00);color:#000;font-weight:700;font-size:14px;padding:14px 32px;border-radius:10px;text-decoration:none;margin:8px 0;}.code-box{background:#060610;border:1px solid #1e1e38;border-left:3px solid #f0a500;border-radius:8px;padding:16px;font-family:monospace;font-size:12px;color:#c8c8e8;margin:16px 0;word-break:break-all;}.note{font-size:11px;color:#454568;font-family:monospace;line-height:1.8;}.footer{padding:20px 40px;border-top:1px solid #1e1e38;font-size:10px;color:#454568;font-family:monospace;}</style></head><body><div class="container"><div class="header"><div class="logo">⚡ BulkReplace</div><div class="tagline">BULK CONTENT REPLACER</div></div><div class="body"><div class="title">Reset Your Password</div><div class="text">Hi {{user_name}},<br><br>We received a request to reset the password for your BulkReplace account. Click the button below to create a new password.</div><a href="{{reset_url}}" class="btn">Reset My Password →</a><div class="code-box">Or copy this link:<br>{{reset_url}}</div><div class="note">⚠ This link expires in <strong style="color:#f0a500;">1 hour</strong>.<br>If you didn\'t request a password reset, you can safely ignore this email.<br>Your password will not change until you use the link above.</div></div><div class="footer">© BulkReplace · <a href="{{app_url}}" style="color:#f0a500;">{{app_url}}</a> · This is an automated email, please do not reply.</div></div></body></html>',
                'body_text'    => "Reset your BulkReplace password\n\nHi {{user_name}},\n\nWe received a request to reset your password.\nClick or copy the link below:\n\n{{reset_url}}\n\nThis link expires in 1 hour.\n\nIf you didn't request this, ignore this email.\n\n— BulkReplace Team",
            ],
            [
                'template_key' => 'welcome',
                'name'         => 'Welcome Email',
                'subject'      => 'Welcome to BulkReplace, {{user_name}}!',
                'body_html'    => '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif;background:#060610;color:#c8c8e8;margin:0;padding:0;}.container{max-width:560px;margin:40px auto;background:#0e0e20;border:1px solid #1e1e38;border-top:3px solid #00d4aa;border-radius:12px;overflow:hidden;}.header{padding:32px 40px;border-bottom:1px solid #1e1e38;}.logo{font-size:22px;font-weight:800;color:#fff;}.tagline{font-size:11px;color:#454568;margin-top:4px;font-family:monospace;}.body{padding:32px 40px;}.title{font-size:20px;font-weight:700;color:#fff;margin-bottom:12px;}.text{font-size:13px;color:#9090b8;line-height:1.8;margin-bottom:20px;font-family:monospace;}.btn{display:inline-block;background:linear-gradient(135deg,#f0a500,#c47d00);color:#000;font-weight:700;font-size:14px;padding:14px 32px;border-radius:10px;text-decoration:none;margin:8px 0;}.footer{padding:20px 40px;border-top:1px solid #1e1e38;font-size:10px;color:#454568;font-family:monospace;}</style></head><body><div class="container"><div class="header"><div class="logo">⚡ BulkReplace</div><div class="tagline">BULK CONTENT REPLACER</div></div><div class="body"><div class="title">Welcome aboard, {{user_name}}! 🎉</div><div class="text">Your account is ready. You\'re on the <strong style="color:#f0a500;">Free plan</strong> with 20 rows/month to get started.<br><br>When you\'re ready to process more, activate your license key or upgrade your plan from the billing page.</div><a href="{{dashboard_url}}" class="btn">Go to Dashboard →</a></div><div class="footer">© BulkReplace · <a href="{{app_url}}" style="color:#f0a500;">{{app_url}}</a></div></div></body></html>',
                'body_text'    => "Welcome to BulkReplace, {{user_name}}!\n\nYour account is ready. You're on the Free plan.\n\nGo to your dashboard: {{dashboard_url}}\n\n— BulkReplace Team",
            ],
        ];

        $stmt = $this->conn->prepare("
            INSERT INTO email_templates (template_key, name, subject, body_html, body_text)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE name=name
        ");

        foreach ($templates as $t) {
            try {
                $stmt->execute([$t['template_key'], $t['name'], $t['subject'], $t['body_html'], $t['body_text']]);
            } catch (Exception $e) {
                error_log("Email template seed error: " . $e->getMessage());
            }
        }
    }

    private function ensureAutopilotTables()
    {
        // Create autopilot_jobs table for queue system
        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS autopilot_jobs (
                id VARCHAR(36) PRIMARY KEY,
                user_id INT NOT NULL,
                total_domains INT NOT NULL DEFAULT 0,
                processed_domains INT NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                keyword_hint TEXT,
                user_hints TEXT,
                result_data JSON,
                error_log JSON,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create autopilot_queue table for individual domain processing
        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS autopilot_queue (
                id VARCHAR(36) PRIMARY KEY,
                job_id VARCHAR(36) NOT NULL,
                domain VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                result_data JSON,
                error_message TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME NULL,
                INDEX idx_job_id (job_id),
                INDEX idx_status (status),
                INDEX idx_job_status (job_id, status),
                FOREIGN KEY (job_id) REFERENCES autopilot_jobs(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
