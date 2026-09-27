-- Daily analytics aggregates (remaining-work Task 21 / C6, brief SPEC-13/29/41).
--
-- One row per (user_id, day, channel, metric): a pre-computed number so the Analytics Hub page
-- never runs a heavy live scan for the new business KPIs. Filled by cron_hub's analytics_daily
-- step (Cron_hub.php _run_analytics_daily(), registered in run()) so it works without the job
-- queue (jobs_enabled) being on. Each run recomputes the previous day in full plus today
-- incrementally, and upserts (INSERT ... ON DUPLICATE KEY UPDATE) so a re-run for the same day
-- just overwrites the same rows - idempotent, safe to run every tick.
--
-- 'channel' is the platform (fb/ig/wa/tg/web) or 'all' for the cross-channel total. 'metric' is
-- one of the analytics_kpi_helper.php ANALYTICS_METRIC_* names (chats, qualified_leads,
-- bookings, handoffs, ai_resolved, resolved_count, resolution_seconds_sum,
-- first_response_seconds_sum, first_response_count, followups_sent, followups_succeeded,
-- reengage_sent, reengage_succeeded, ai_replies, unanswered, ai_cost_usd,
-- stalled_after_price_quote). Analytics_hub.php reads this table for the new KPIs and shows
-- "collecting data" when a tenant has no rows yet (nothing here is a live scan).
--
-- Additive, idempotent (CREATE TABLE IF NOT EXISTS). No semicolons in comments: the migration
-- runner splits statements on a semicolon at end of line.
DO 0;

CREATE TABLE IF NOT EXISTS `analytics_daily` (
  `user_id` int unsigned NOT NULL COMMENT 'tenant',
  `day` date NOT NULL,
  `channel` varchar(16) NOT NULL DEFAULT 'all' COMMENT 'fb ig wa tg web or all',
  `metric` varchar(40) NOT NULL,
  `value` decimal(14,4) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`, `day`, `channel`, `metric`),
  KEY `idx_analytics_daily_user_day` (`user_id`, `day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
