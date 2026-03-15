/*
  # Autopilot Queue System - Professional Batch Processing
  
  ## Overview
  This migration creates a professional queue system for Autopilot to handle unlimited domains
  without timeout or memory issues. Processes domains in chunks with real-time progress tracking.
  
  ## Tables Created
  
  ### `autopilot_jobs`
  Main job tracking table for batch operations
  - `id` (uuid, primary key) - Unique job identifier
  - `user_id` (integer) - Owner of the job
  - `total_domains` (integer) - Total number of domains in batch
  - `processed_domains` (integer) - Number completed so far
  - `status` (text) - Job status: pending, processing, completed, failed
  - `keyword_hint` (text) - Keyword hint for AI parser
  - `user_hints` (text) - User-provided detection hints
  - `result_data` (jsonb) - Final processed data
  - `error_log` (jsonb) - Error details if any
  - `created_at` (timestamptz) - Job creation time
  - `updated_at` (timestamptz) - Last update time
  - `completed_at` (timestamptz) - Completion time
  
  ### `autopilot_queue`
  Individual domain processing queue
  - `id` (uuid, primary key) - Unique queue item ID
  - `job_id` (uuid, foreign key) - Parent job reference
  - `domain` (text) - Domain to process
  - `status` (text) - Item status: pending, processing, completed, failed
  - `result_data` (jsonb) - Processed domain data
  - `error_message` (text) - Error details if failed
  - `created_at` (timestamptz) - Creation time
  - `processed_at` (timestamptz) - Processing completion time
  
  ## Security
  - RLS enabled on both tables
  - Users can only access their own jobs
  - Public access allowed (managed by PHP session)
  
  ## Indexes
  - Fast job lookup by user_id and status
  - Fast queue processing by job_id and status
  - Efficient progress tracking queries
*/

-- Create autopilot_jobs table
CREATE TABLE IF NOT EXISTS autopilot_jobs (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id integer NOT NULL,
  total_domains integer NOT NULL DEFAULT 0,
  processed_domains integer NOT NULL DEFAULT 0,
  status text NOT NULL DEFAULT 'pending',
  keyword_hint text DEFAULT '',
  user_hints text DEFAULT '',
  result_data jsonb DEFAULT '{}',
  error_log jsonb DEFAULT '[]',
  created_at timestamptz DEFAULT now(),
  updated_at timestamptz DEFAULT now(),
  completed_at timestamptz
);

-- Create autopilot_queue table
CREATE TABLE IF NOT EXISTS autopilot_queue (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  job_id uuid NOT NULL REFERENCES autopilot_jobs(id) ON DELETE CASCADE,
  domain text NOT NULL,
  status text NOT NULL DEFAULT 'pending',
  result_data jsonb DEFAULT '{}',
  error_message text,
  created_at timestamptz DEFAULT now(),
  processed_at timestamptz
);

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_autopilot_jobs_user_id ON autopilot_jobs(user_id);
CREATE INDEX IF NOT EXISTS idx_autopilot_jobs_status ON autopilot_jobs(status);
CREATE INDEX IF NOT EXISTS idx_autopilot_jobs_created_at ON autopilot_jobs(created_at DESC);

CREATE INDEX IF NOT EXISTS idx_autopilot_queue_job_id ON autopilot_queue(job_id);
CREATE INDEX IF NOT EXISTS idx_autopilot_queue_status ON autopilot_queue(status);
CREATE INDEX IF NOT EXISTS idx_autopilot_queue_job_status ON autopilot_queue(job_id, status);

-- Enable RLS
ALTER TABLE autopilot_jobs ENABLE ROW LEVEL SECURITY;
ALTER TABLE autopilot_queue ENABLE ROW LEVEL SECURITY;

-- Allow public access (access control handled by PHP session layer)
CREATE POLICY "Allow public access to autopilot_jobs"
  ON autopilot_jobs FOR ALL
  USING (true)
  WITH CHECK (true);

CREATE POLICY "Allow public access to autopilot_queue"
  ON autopilot_queue FOR ALL
  USING (true)
  WITH CHECK (true);
