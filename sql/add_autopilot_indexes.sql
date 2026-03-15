-- ============================================================
-- Autopilot Performance Indexes (MySQL / CyberPanel)
-- Run this once via phpMyAdmin atau MySQL CLI
-- ============================================================

-- Index: cari job berdasarkan user + status (listing active jobs)
ALTER TABLE autopilot_jobs
    ADD INDEX IF NOT EXISTS idx_user_status (user_id, status);

-- Index: hitung pending/processing per job (queue stats)
ALTER TABLE autopilot_queue
    ADD INDEX IF NOT EXISTS idx_job_status_created (job_id, status, created_at);

-- Index: auto-recovery query (stuck processing items)
ALTER TABLE autopilot_queue
    ADD INDEX IF NOT EXISTS idx_job_status_processed (job_id, status, processed_at);
