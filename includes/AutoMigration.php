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
