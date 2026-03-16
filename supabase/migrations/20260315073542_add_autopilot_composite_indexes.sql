/*
  # Add composite indexes for autopilot tables

  Adds missing composite indexes to improve query performance for autopilot job
  status lookups and queue processing. These indexes speed up:
  - Fetching pending queue items for a specific job (job_id + status)
  - Listing a user's active jobs (user_id + status)
  - Auto-recovery query for stuck processing items (job_id + status + processed_at)
*/

CREATE INDEX IF NOT EXISTS idx_autopilot_jobs_user_status
  ON autopilot_jobs (user_id, status);

CREATE INDEX IF NOT EXISTS idx_autopilot_queue_job_status_pending
  ON autopilot_queue (job_id, status, created_at)
  WHERE status = 'pending';

CREATE INDEX IF NOT EXISTS idx_autopilot_queue_job_status_processing
  ON autopilot_queue (job_id, status, processed_at)
  WHERE status = 'processing';
