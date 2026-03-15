<?php

function addPerformanceIndexes(PDO $db): array {
    $results = [];
    $indexes = [
        "ALTER TABLE users ADD INDEX idx_plan (plan)" => "users.plan",
        "ALTER TABLE users ADD INDEX idx_email (email)" => "users.email",
        "ALTER TABLE users ADD INDEX idx_created_at (created_at)" => "users.created_at",
        "ALTER TABLE users ADD INDEX idx_plan_expires (plan_expires_at)" => "users.plan_expires_at",

        "ALTER TABLE usage_log ADD INDEX idx_user_month (user_id, created_at)" => "usage_log.user_id+created_at",
        "ALTER TABLE usage_log ADD INDEX idx_created_at (created_at)" => "usage_log.created_at",
        "ALTER TABLE usage_log ADD INDEX idx_user_date (user_id, created_at DESC)" => "usage_log.user_id+created_at_desc",

        "ALTER TABLE licenses ADD INDEX idx_user_id (user_id)" => "licenses.user_id",
        "ALTER TABLE licenses ADD INDEX idx_license_key (license_key)" => "licenses.license_key",
        "ALTER TABLE licenses ADD INDEX idx_status (status)" => "licenses.status",

        "ALTER TABLE user_addons ADD INDEX idx_user_id (user_id)" => "user_addons.user_id",
        "ALTER TABLE user_addons ADD INDEX idx_addon_id (addon_id)" => "user_addons.addon_id",
        "ALTER TABLE user_addons ADD INDEX idx_active (is_active)" => "user_addons.is_active",

        "ALTER TABLE place_cache ADD INDEX idx_cache_key (cache_key)" => "place_cache.cache_key",
        "ALTER TABLE place_cache ADD INDEX idx_location (location_slug)" => "place_cache.location_slug",
        "ALTER TABLE place_cache ADD INDEX idx_last_used (last_used)" => "place_cache.last_used",

        "ALTER TABLE email_queue ADD INDEX idx_email_queue_processing (status, scheduled_at)" => "email_queue.status+scheduled_at",
        "ALTER TABLE email_queue ADD INDEX idx_email_queue_user (user_id, created_at DESC)" => "email_queue.user_id+created_at_desc",

        "ALTER TABLE audit_logs ADD INDEX idx_audit_logs_user_action (user_id, action, created_at DESC)" => "audit_logs.user_id+action+created_at_desc",
        "ALTER TABLE audit_logs ADD INDEX idx_audit_logs_action (action, created_at DESC)" => "audit_logs.action+created_at_desc",
    ];

    foreach ($indexes as $sql => $indexName) {
        try {
            $db->exec($sql);
            $results[] = ['index' => $indexName, 'status' => 'created', 'error' => null];
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                $results[] = ['index' => $indexName, 'status' => 'exists', 'error' => null];
            } else {
                $results[] = ['index' => $indexName, 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }
    }

    return $results;
}
