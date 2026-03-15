<?php

class LicenseGenerator
{
    private $conn;

    private static $productPrefixes = [
        'pro-monthly-plan' => 'PRO-M',
        'pro-yearly-plan' => 'PRO-Y',
        'platinum-monthly-plan' => 'PLT-M',
        'platinum-yearly-plan' => 'PLT-Y',
        'lifetime-access-plan' => 'LIFE',
        'csv-generator-addon' => 'CSV',
        'zip-manager-addon' => 'ZIP',
        'copy-rename-addon' => 'CPY',
        'ai-autopilot-bundle' => 'AUTO',
        'all-in-one-bundle' => 'AIO'
    ];

    public function __construct($conn)
    {
        $this->conn = $conn;
        $this->ensureTables();
        $this->ensureBillingCycleEnum();
    }

    private function ensureBillingCycleEnum()
    {
        try {
            $result = $this->conn->query("SHOW COLUMNS FROM users LIKE 'billing_cycle'")->fetch();

            if ($result) {
                $needsUpdate = strpos($result['Type'], 'none') === false
                    || strpos($result['Type'], 'addon') === false
                    || strpos($result['Type'], 'lifetime') === false;

                if ($needsUpdate) {
                    $this->conn->exec("ALTER TABLE users MODIFY COLUMN billing_cycle ENUM('monthly','yearly','annual','lifetime','addon','none') NULL DEFAULT 'none'");
                    error_log("billing_cycle enum updated to include all required values");
                }
            }
        } catch (Exception $e) {
            error_log("Failed to update billing_cycle enum: " . $e->getMessage());
        }
    }

    private function ensureTables()
    {
        try {
            $usersTableExists = $this->conn->query("SHOW TABLES LIKE 'users'")->rowCount() > 0;

            $licensesTableSQL = "
                CREATE TABLE IF NOT EXISTS licenses (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    license_key VARCHAR(100) UNIQUE NOT NULL,
                    product_id VARCHAR(100) NOT NULL,
                    product_slug VARCHAR(50) NOT NULL,
                    sale_id VARCHAR(100) NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    user_id INT NULL,
                    status ENUM('active', 'inactive', 'revoked', 'expired', 'cancelled') DEFAULT 'inactive',
                    gumroad_license VARCHAR(100) NULL,
                    activated_at DATETIME NULL,
                    expires_at DATETIME NULL,
                    revoked_at DATETIME NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    metadata JSON NULL,
                    INDEX idx_license_key (license_key),
                    INDEX idx_gumroad_license (gumroad_license),
                    INDEX idx_email (email),
                    INDEX idx_user_id (user_id),
                    INDEX idx_product_id (product_id),
                    INDEX idx_sale_id (sale_id),
                    INDEX idx_status (status)" .
                    ($usersTableExists ? ", FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL" : "") . "
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";

            // Ensure existing tables have all required columns
            $this->conn->exec("
                ALTER TABLE licenses
                    ADD COLUMN IF NOT EXISTS revoked_at DATETIME NULL,
                    ADD COLUMN IF NOT EXISTS gumroad_license VARCHAR(100) NULL
            ");

            try {
                $this->conn->exec("ALTER TABLE licenses ADD INDEX IF NOT EXISTS idx_gumroad_license (gumroad_license)");
            } catch (Exception $idxEx) {
                // Index may already exist
            }

            $this->conn->exec($licensesTableSQL);

            $this->conn->exec("
                CREATE TABLE IF NOT EXISTS product_mappings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    product_id VARCHAR(100) UNIQUE NOT NULL,
                    product_slug VARCHAR(50) UNIQUE NOT NULL,
                    product_name VARCHAR(100) NOT NULL,
                    product_type ENUM('subscription', 'lifetime', 'addon', 'bundle') NOT NULL,
                    billing_cycle ENUM('monthly', 'yearly', 'lifetime') NULL,
                    plan_level ENUM('basic', 'pro', 'platinum') NULL,
                    features JSON NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_product_slug (product_slug)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $products = [
                ['5FemOQM3T5CTJcoxLBz9lA==', 'pro-yearly-plan', 'Pro Automation Yearly Plan', 'subscription', 'yearly', 'pro', '{"csv_gen": true, "zip_manager": true, "copy_rename": true, "autopilot": true}'],
                ['7nQs3PYRz6Wc_zSwZEKmpA==', 'pro-monthly-plan', 'Pro Automation Monthly Plan', 'subscription', 'monthly', 'pro', '{"csv_gen": true, "zip_manager": true, "copy_rename": true, "autopilot": true}'],
                ['QqX3oihPnbHi56uTL33xtw==', 'platinum-yearly-plan', 'Platinum Yearly Plan', 'subscription', 'yearly', 'platinum', '{"csv_gen": true, "zip_manager": true, "copy_rename": true, "autopilot": true, "priority_support": true}'],
                ['IsVC3Fk2_BX3eoeUBsmMHQ==', 'platinum-monthly-plan', 'Platinum Monthly Plan', 'subscription', 'monthly', 'platinum', '{"csv_gen": true, "zip_manager": true, "copy_rename": true, "autopilot": true, "priority_support": true}'],
                ['v7bddkeH5_4CZOF-rvdVYg==', 'lifetime-access-plan', 'Lifetime Access Plan', 'lifetime', 'lifetime', 'lifetime', '{"csv_gen": true, "zip_manager": true, "copy_rename": true, "autopilot": true, "priority_support": true, "lifetime_updates": true}'],
                ['Rv3lRIIKoziiUCsyZT_8xg==', 'ai-autopilot-bundle', 'AI Autopilot Bundle', 'bundle', 'lifetime', 'pro', '{"autopilot": true}'],
                ['n3naDS2BY26jmjBgz8iQkQ==', 'csv-generator-addon', 'CSV Generator Addon', 'addon', 'lifetime', 'basic', '{"csv_gen": true}'],
                ['RnSl8osTSdq8ObTYFGuZWw==', 'zip-manager-addon', 'Zip Manager Addon', 'addon', 'lifetime', 'basic', '{"zip_manager": true}'],
                ['qHSLgP8ikGyof7yc-PNU5Q==', 'copy-rename-addon', 'Copy & Rename Addon', 'addon', 'lifetime', 'basic', '{"copy_rename": true}'],
                ['BWD85J4nF3sS9ggXMdtTqQ==', 'all-in-one-bundle', 'All-In-One Bundle', 'bundle', 'lifetime', 'platinum', '{"csv_gen": true, "zip_manager": true, "copy_rename": true, "autopilot": true, "priority_support": true, "lifetime_updates": true}']
            ];

            foreach ($products as $product) {
                $checkStmt = $this->conn->prepare("SELECT COUNT(*) FROM product_mappings WHERE product_id = ?");
                $checkStmt->execute([$product[0]]);
                $checkProduct = $checkStmt->fetchColumn();
                if ($checkProduct == 0) {
                    $stmt = $this->conn->prepare("
                        INSERT INTO product_mappings (product_id, product_slug, product_name, product_type, billing_cycle, plan_level, features)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute($product);
                }
            }

            $userAddonsTableSQL = "
                CREATE TABLE IF NOT EXISTS user_addons (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    addon_id INT NULL,
                    addon_slug VARCHAR(100) NULL,
                    price DECIMAL(10,2) NULL DEFAULT 0,
                    is_active TINYINT(1) DEFAULT 1,
                    purchased_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    gumroad_sale_id VARCHAR(255) NULL," .
                    ($usersTableExists ? " FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE," : "") . "
                    UNIQUE KEY uk_user_addon_slug (user_id, addon_slug),
                    INDEX idx_user_active (user_id, is_active),
                    INDEX idx_addon_slug (addon_slug)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ";

            $this->conn->exec($userAddonsTableSQL);

            $hasAddonSlugColumn = $this->conn->query("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_NAME = 'user_addons'
                AND COLUMN_NAME = 'addon_slug'
            ")->fetchColumn() > 0;

            if (!$hasAddonSlugColumn) {
                $this->conn->exec("
                    ALTER TABLE user_addons
                    ADD COLUMN addon_slug VARCHAR(100) NULL AFTER addon_id,
                    ADD INDEX idx_addon_slug (addon_slug)
                ");
            }

            $hasGumroadSaleIdColumn = $this->conn->query("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_NAME = 'user_addons'
                AND COLUMN_NAME = 'gumroad_sale_id'
            ")->fetchColumn() > 0;

            if (!$hasGumroadSaleIdColumn) {
                $this->conn->exec("
                    ALTER TABLE user_addons
                    ADD COLUMN gumroad_sale_id VARCHAR(255) NULL
                ");
            }

            try {
                $this->conn->exec("
                    ALTER TABLE user_addons
                    MODIFY COLUMN addon_id INT NULL DEFAULT NULL
                ");
                error_log("Successfully made addon_id nullable");
            } catch (Exception $modifyEx) {
                error_log("Could not modify addon_id (may already be nullable): " . $modifyEx->getMessage());
            }
        } catch (Exception $e) {
            error_log("LicenseGenerator table init error: " . $e->getMessage());
        }
    }

    public function generateLicense($productId, $saleId, $email)
    {
        $productSlug = $this->getProductSlug($productId);

        if (!$productSlug) {
            throw new Exception("Unknown product ID: {$productId}");
        }

        $prefix = self::$productPrefixes[$productSlug] ?? 'GEN';

        $shortSaleId = substr(preg_replace('/[^a-zA-Z0-9]/', '', $saleId), 0, 8);
        $random = $this->generateRandomSegment();
        $checksum = $this->generateChecksum($prefix, $shortSaleId, $random);

        $licenseKey = "{$prefix}-{$shortSaleId}-{$random}-{$checksum}";

        return strtoupper($licenseKey);
    }

    public function saveLicense($licenseKey, $productId, $saleId, $email, $metadata = [])
    {
        $productSlug = $this->getProductSlug($productId);

        if (!$productSlug) {
            throw new Exception("Unknown product ID: {$productId}");
        }

        $productInfo = $this->getProductInfo($productId);

        $expiresAt = null;
        if ($productInfo && $productInfo['product_type'] === 'subscription') {
            if ($productInfo['billing_cycle'] === 'monthly') {
                $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
            } elseif ($productInfo['billing_cycle'] === 'yearly') {
                $expiresAt = date('Y-m-d H:i:s', strtotime('+365 days'));
            }
        }

        $stmt = $this->conn->prepare("
            INSERT INTO licenses
            (license_key, product_id, product_slug, sale_id, email, status, expires_at, gumroad_license, metadata)
            VALUES (?, ?, ?, ?, ?, 'inactive', ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                product_id = VALUES(product_id),
                expires_at = VALUES(expires_at),
                gumroad_license = VALUES(gumroad_license),
                metadata = VALUES(metadata)
        ");

        $metadataJson = json_encode($metadata);
        $gumroadLicense = $metadata['gumroad_license'] ?? null;

        $stmt->execute([
            $licenseKey,
            $productId,
            $productSlug,
            $saleId,
            $email,
            $expiresAt,
            $gumroadLicense,
            $metadataJson
        ]);

        return $this->conn->lastInsertId();
    }

    public function verifyLicense($licenseKey)
    {
        $stmt = $this->conn->prepare("
            SELECT l.*, pm.product_name, pm.product_type, pm.billing_cycle, pm.plan_level, pm.features
            FROM licenses l
            LEFT JOIN product_mappings pm ON l.product_id = pm.product_id
            WHERE l.license_key = ? OR l.gumroad_license = ?
            ORDER BY l.status = 'active' DESC, l.created_at DESC
            LIMIT 1
        ");

        $stmt->execute([$licenseKey, $licenseKey]);
        $license = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$license) {
            return ['valid' => false, 'error' => 'Invalid license key'];
        }

        if ($license['status'] === 'revoked') {
            return ['valid' => false, 'error' => 'License has been revoked'];
        }

        if ($license['status'] === 'expired') {
            return ['valid' => false, 'error' => 'License has expired'];
        }

        if ($license['expires_at'] && strtotime($license['expires_at']) < time()) {
            $this->updateLicenseStatus($licenseKey, 'expired');
            return ['valid' => false, 'error' => 'License has expired'];
        }

        return [
            'valid' => true,
            'license' => $license
        ];
    }

    public function activateLicense($licenseKey, $userId, $email)
    {
        if ($this->isGumroadLicenseFormat($licenseKey)) {
            return $this->activateGumroadLicense($licenseKey, $userId, $email);
        }

        $verification = $this->verifyLicense($licenseKey);

        if (!$verification['valid']) {
            return $verification;
        }

        $license = $verification['license'];

        if ($license['status'] === 'revoked') {
            return ['success' => false, 'error' => 'This license has been revoked and cannot be activated'];
        }

        if ($license['status'] === 'expired') {
            return ['success' => false, 'error' => 'This license has expired and cannot be activated'];
        }

        if ($license['status'] === 'cancelled') {
            return ['success' => false, 'error' => 'This license has been cancelled'];
        }

        if ($license['status'] === 'active' && $license['user_id'] && $license['user_id'] != $userId) {
            return ['success' => false, 'error' => 'License already activated by another user'];
        }

        $stmt = $this->conn->prepare("
            UPDATE licenses
            SET status = 'active',
                user_id = ?,
                activated_at = NOW()
            WHERE license_key = ?
        ");

        $stmt->execute([$userId, $licenseKey]);

        $this->applyLicenseToUser($userId, $license);

        return [
            'success'            => true,
            'message'            => 'License activated successfully',
            'product'            => $license['product_name'],
            'system_license_key' => $licenseKey
        ];
    }

    private function isGumroadLicenseFormat($licenseKey)
    {
        return preg_match('/^[A-F0-9]{8}-[A-F0-9]{8}-[A-F0-9]{8}-[A-F0-9]{8}$/i', $licenseKey);
    }

    private function activateGumroadLicense($licenseKey, $userId, $email)
    {
        $checkExisting = $this->conn->prepare("SELECT id FROM users WHERE gumroad_license=? AND id != ?");
        $checkExisting->execute([$licenseKey, $userId]);
        if ($checkExisting->fetch()) {
            return ['success' => false, 'error' => 'This license key is already in use by another account'];
        }

        // If this Gumroad key was already processed by webhook, return the existing system license key
        $existingSystemKey = $this->conn->prepare("SELECT license_key FROM licenses WHERE gumroad_license=? AND status='active' LIMIT 1");
        $existingSystemKey->execute([$licenseKey]);
        $existingRow = $existingSystemKey->fetch();

        if ($existingRow) {
            $existingSysKey = $existingRow['license_key'];
            // Update user's gumroad_license field if not already set
            $this->conn->prepare("UPDATE users SET gumroad_license=? WHERE id=? AND (gumroad_license IS NULL OR gumroad_license='')")
                ->execute([$licenseKey, $userId]);

            return [
                'success'            => true,
                'message'            => 'License activated successfully! Your plan is now active.',
                'product'            => 'Activated Plan',
                'system_license_key' => $existingSysKey,
                'gumroad_license_key'=> $licenseKey
            ];
        }

        if (!function_exists('curl_init')) {
            error_log("CURL not available for Gumroad verification");
            return ['success' => false, 'error' => 'Server configuration error. Please contact support.'];
        }

        // Build productMap from centralized config
        $productIds = defined('GUMROAD_PRODUCT_ID_MAP') ? array_keys(GUMROAD_PRODUCT_ID_MAP) : [
            '5FemOQM3T5CTJcoxLBz9lA==','7nQs3PYRz6Wc_zSwZEKmpA==',
            'QqX3oihPnbHi56uTL33xtw==','IsVC3Fk2_BX3eoeUBsmMHQ==',
            'v7bddkeH5_4CZOF-rvdVYg==','Rv3lRIIKoziiUCsyZT_8xg==',
            'n3naDS2BY26jmjBgz8iQkQ==','RnSl8osTSdq8ObTYFGuZWw==',
            'qHSLgP8ikGyof7yc-PNU5Q==','BWD85J4nF3sS9ggXMdtTqQ=='
        ];

        $verifyUrl = "https://api.gumroad.com/v2/licenses/verify";

        error_log("Verifying Gumroad license: " . substr($licenseKey, 0, 15) . "...");
        $verifiedProduct = null;
        $lastError = '';

        foreach ($productIds as $productId) {
            $postData = [
                'product_id' => $productId,
                'license_key' => $licenseKey,
                'increment_uses_count' => 'false'
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $verifyUrl);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                error_log("CURL Error for product {$productId}: " . $curlError);
                $lastError = 'Network error: ' . $curlError;
                continue;
            }

            if (empty($response)) {
                $lastError = 'Empty response from Gumroad';
                continue;
            }

            $result = json_decode($response, true);

            if (!$result || !isset($result['success'])) {
                $lastError = 'Invalid response format';
                continue;
            }

            if ($result['success']) {
                error_log("Gumroad license verified successfully for product: {$productId}");
                // Resolve config from centralized map
                $resolvedCfg = null;
                if (function_exists('resolveGumroadProduct')) {
                    $resolvedCfg = resolveGumroadProduct($productId);
                }
                if (!$resolvedCfg) {
                    $slug = isset(GUMROAD_PRODUCT_ID_MAP[$productId]) ? GUMROAD_PRODUCT_ID_MAP[$productId] : null;
                    $resolvedCfg = $slug && isset(GUMROAD_PRODUCT_MAP[$slug]) ? GUMROAD_PRODUCT_MAP[$slug] : null;
                }
                $verifiedProduct = [
                    'product_id' => $productId,
                    'config'     => $resolvedCfg ?? ['plan'=>'pro','cycle'=>'monthly','months'=>1,'slug'=>$productId,'name'=>'Unknown Product'],
                    'purchase'   => $result['purchase'] ?? null
                ];
                break;
            } else {
                $lastError = $result['message'] ?? 'Verification failed';
            }
        }

        if (!$verifiedProduct) {
            error_log("Gumroad license verification failed: " . $lastError);
            $helpText = "\n\nPossible reasons:\n• License key is incorrect\n• License has been refunded or cancelled\n• License is for a different product\n\nPlease check your Gumroad purchase email or contact support.";
            return ['success' => false, 'error' => $lastError . $helpText];
        }

        $purchase = $verifiedProduct['purchase'];

        if (!$purchase) {
            return ['success' => false, 'error' => 'No purchase data found'];
        }

        if ($purchase['refunded'] ?? false) {
            return ['success' => false, 'error' => 'This license has been refunded'];
        }

        if ($purchase['chargebacked'] ?? false) {
            return ['success' => false, 'error' => 'This license has been charged back'];
        }

        $config       = $verifiedProduct['config'];
        $purchase     = $verifiedProduct['purchase'];
        $saleId       = $purchase['sale_id'] ?? '';
        $purchaseEmail= $purchase['email'] ?? $email;

        $cycle     = $config['cycle'];
        $planName  = $config['name'] ?? ucfirst($config['plan']) . ' Plan';

        $expiresAt = $cycle === 'lifetime' ? null : date('Y-m-d H:i:s', strtotime(
            $cycle === 'monthly' ? '+30 days' : '+365 days'
        ));

        $systemLicenseKey = $this->generateLicense($verifiedProduct['product_id'], $saleId, $purchaseEmail);

        $this->conn->beginTransaction();
        try {
            $this->saveLicense($systemLicenseKey, $verifiedProduct['product_id'], $saleId, $purchaseEmail, [
                'gumroad_license' => $licenseKey,
                'user_id' => $userId,
                'activated_from' => 'gumroad'
            ]);

            $updateStmt = $this->conn->prepare("
                UPDATE users
                SET plan=?, billing_cycle=?, plan_expires_at=?, gumroad_license=?, gumroad_sale_id=?
                WHERE id=?
            ");
            $updateStmt->execute([$config['plan'], $config['cycle'], $expiresAt, $licenseKey, $saleId, $userId]);

            $licenseStmt = $this->conn->prepare("
                UPDATE licenses SET status='active', user_id=?, activated_at=NOW() WHERE license_key=?
            ");
            $licenseStmt->execute([$userId, $systemLicenseKey]);

            // Activate addon if purchased
            $addonSlug = $config['addon'] ?? null;
            if ($addonSlug) {
                try {
                    require_once dirname(__DIR__).'/config.php';
                    $unlockedSlugs = getAddonSlugs($addonSlug);

                    foreach ($unlockedSlugs as $slug) {
                        $checkAddon = $this->conn->prepare("SELECT id FROM user_addons WHERE user_id=? AND addon_slug=?");
                        $checkAddon->execute([$userId, $slug]);

                        if (!$checkAddon->fetch()) {
                            $this->conn->prepare("INSERT INTO user_addons(user_id, addon_slug, purchased_at, gumroad_sale_id) VALUES(?,?,NOW(),?)")
                                ->execute([$userId, $slug, $saleId]);
                            error_log("Gumroad license activation: Activated addon '{$slug}' for user {$userId}");
                        }
                    }
                } catch (Exception $addonEx) {
                    error_log("Gumroad license activation: Failed to activate addon - " . $addonEx->getMessage());
                }
            }

            $this->conn->commit();

            return [
                'success'            => true,
                'message'            => 'Gumroad license activated successfully! Your system license key: ' . $systemLicenseKey,
                'product'            => $planName,
                'system_license_key' => $systemLicenseKey,
                'gumroad_license_key'=> $licenseKey
            ];
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Gumroad license activation failed: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to activate license: ' . $e->getMessage()];
        }
    }

    private function applyLicenseToUser($userId, $license)
    {
        $productType = $license['product_type'];
        $planLevel = $license['plan_level'];
        $billingCycle = $license['billing_cycle'];
        $features = json_decode($license['features'], true);

        if ($productType === 'subscription' || $productType === 'lifetime') {
            $stmt = $this->conn->prepare("
                UPDATE users
                SET plan = ?,
                    billing_cycle = ?,
                    plan_expires_at = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $planLevel,
                $billingCycle,
                $license['expires_at'],
                $userId
            ]);
        }

        if ($features) {
            foreach ($features as $addon => $enabled) {
                if ($enabled) {
                    $checkAddon = $this->conn->prepare("SELECT id FROM user_addons WHERE user_id=? AND addon_slug=?");
                    $checkAddon->execute([$userId, $addon]);

                    if (!$checkAddon->fetch()) {
                        $this->conn->prepare("INSERT INTO user_addons(user_id, addon_slug, purchased_at, is_active) VALUES(?,?,NOW(),1)")
                            ->execute([$userId, $addon]);
                        error_log("License activation: Activated addon '{$addon}' for user {$userId}");
                    }
                }
            }
        }
    }

    private function updateLicenseStatus($licenseKey, $status)
    {
        $stmt = $this->conn->prepare("
            UPDATE licenses
            SET status = ?
            WHERE license_key = ?
        ");

        $stmt->execute([$status, $licenseKey]);
    }

    private function getProductSlug($productId)
    {
        // Use centralized map from config.php
        if (defined('GUMROAD_PRODUCT_ID_MAP') && isset(GUMROAD_PRODUCT_ID_MAP[$productId])) {
            return GUMROAD_PRODUCT_ID_MAP[$productId];
        }

        if (defined('GUMROAD_PRODUCT_MAP') && isset(GUMROAD_PRODUCT_MAP[$productId])) {
            return GUMROAD_PRODUCT_MAP[$productId]['slug'];
        }

        $stmt = $this->conn->prepare("SELECT product_slug FROM product_mappings WHERE product_id = ?");
        $stmt->execute([$productId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['product_slug'] : null;
    }

    private function getProductInfo($productId)
    {
        // Resolve via centralized map
        if (function_exists('resolveGumroadProduct')) {
            $slug = $this->getProductSlug($productId);
            if ($slug && defined('GUMROAD_PRODUCT_MAP') && isset(GUMROAD_PRODUCT_MAP[$slug])) {
                $cfg = GUMROAD_PRODUCT_MAP[$slug];
                return [
                    'product_type'  => in_array($cfg['cycle'], ['monthly','annual','yearly']) ? 'subscription' : ($cfg['cycle'] === 'lifetime' ? 'lifetime' : 'addon'),
                    'billing_cycle' => $cfg['cycle'],
                    'plan_level'    => $cfg['plan'],
                ];
            }
        }

        $stmt = $this->conn->prepare("SELECT * FROM product_mappings WHERE product_id = ?");
        $stmt->execute([$productId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function generateRandomSegment()
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $segment = '';

        for ($i = 0; $i < 8; $i++) {
            $segment .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $segment;
    }

    private function generateChecksum($prefix, $saleId, $random)
    {
        $combined = $prefix . $saleId . $random;
        $hash = hash('crc32', $combined);
        return strtoupper(substr($hash, 0, 4));
    }

    public function getLicensesByEmail($email)
    {
        $stmt = $this->conn->prepare("
            SELECT l.*, pm.product_name, pm.product_type, pm.billing_cycle
            FROM licenses l
            LEFT JOIN product_mappings pm ON l.product_id = pm.product_id
            WHERE l.email = ?
            ORDER BY l.created_at DESC
        ");

        $stmt->execute([$email]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLicensesByUserId($userId)
    {
        $stmt = $this->conn->prepare("
            SELECT l.*,
                   COALESCE(pm.product_name, UPPER(REPLACE(l.product_slug, '-', ' '))) AS product_name,
                   pm.product_type,
                   COALESCE(pm.billing_cycle, l.product_slug) AS billing_cycle
            FROM licenses l
            LEFT JOIN product_mappings pm ON l.product_id = pm.product_id
            WHERE l.user_id = ?
            ORDER BY l.created_at DESC
        ");

        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function checkExistingLicense($saleId)
    {
        $stmt = $this->conn->prepare("
            SELECT license_key
            FROM licenses
            WHERE sale_id = ?
        ");

        $stmt->execute([$saleId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? $result['license_key'] : null;
    }
}
