-- Generic job queue (remaining-work Task 11 / B3, brief §42).
--
-- One table backs a small worker pool: `job_helper.php` (job_enqueue/job_claim/...) writes and
-- reads it, `controllers/Job_worker.php` (CLI only) drains it. Claiming is atomic (an UPDATE ...
-- WHERE status='queued' AND run_after<=NOW() ... LIMIT <n>, tagged with a unique locked_by
-- token, followed by a SELECT of that token — MariaDB 10.11 does not support UPDATE ... LIMIT
-- with ORDER BY returning rows, so this two-step claim is what stays portable). Handlers are
-- looked up by `type` in a small in-code registry; today only two: kb_chunk_embed and
-- conversation_summary (see docs in job_helper.php).
--
-- Behind config flag `jobs_enabled` (unset/FALSE = OFF = today's inline behaviour, nothing
-- enqueues, this table stays empty). Idempotent (IF NOT EXISTS). No semicolons in comments:
-- the migration runner splits on them.
DO 0;

CREATE TABLE IF NOT EXISTS `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `type` varchar(64) NOT NULL,
  `payload_json` longtext NOT NULL,
  `status` enum('queued','running','done','failed','dead') NOT NULL DEFAULT 'queued',
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `max_attempts` int unsigned NOT NULL DEFAULT 5,
  `run_after` datetime NOT NULL,
  `locked_at` datetime NULL DEFAULT NULL,
  `locked_by` varchar(64) NULL DEFAULT NULL,
  `last_error` text NULL DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_jobs_claim` (`status`, `run_after`),
  KEY `idx_jobs_user` (`user_id`),
  KEY `idx_jobs_locked_by` (`locked_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
