-- Phase 10.5 follow-up (brief §25/§26/§51) — Sequences safety gate: rate limits, quiet
-- hours, jitter and a distinct opt-out log reason for send_message steps, closing the gap
-- documented in docs/automations/sequences.md §6.B/§7 ("Sequences has no rate-limit budget,
-- quiet-hours window, daily cap ... of its own").
--
-- DESIGN — why columns on `sequences`, not a new `sequence_send_limits` table:
--   Each of these is a single scalar per sequence (nullable = inherit the account default),
--   read on every send_message step exactly once. A side table would need its own
--   one-row-per-sequence uniqueness, its own join, and buys nothing a nullable column does
--   not already give for free — `sequences` already IS the one-row-per-sequence config
--   surface (see `trigger_event`, `status`). A side table earns its keep for a 1:many
--   relationship; this is 1:1. Kept for a new table: NONE.
--
-- DESIGN — account-wide default source: `sales_automation_settings` was checked first (per
-- the task) for existing account-wide quiet-hours/rate columns to reuse. It has none —
-- only followup/digest/deflection settings — so this migration ADDS the account-wide
-- defaults there rather than inventing a second one-row-per-tenant settings table.
-- `sales_automation_settings` is already the account's one-row automation-settings home
-- (brief item: "Sales Automation" = today's per-tenant automation config); extending it
-- keeps ONE such table instead of two, and future automated-send features (not just
-- Sequences) inherit the same account defaults for free.
--
-- DESIGN — defaults chosen (see docs/automations/sequences.md §6 for full reasoning):
--   default_messages_per_hour / default_daily_cap: rate limiting is ALWAYS ON (per this
--     task's requirement) — 30/hour, 200/day, roughly half Reengage's own per-campaign
--     defaults (60/hour, 500/day), since a Sequences account budget is shared across every
--     sequence at once, not curated per campaign the way Reengage's is.
--   default_quiet_hours_start = default_quiet_hours_end ('00:00:00' = equal, meaning "no
--     quiet hours" per reengage_in_quiet_hours()'s own documented semantics): OFF by
--     default. Unlike Reengage (a deliberately-configured promotional campaign an owner
--     sets up with eyes open, default 22:00-08:00), Sequences already had live steps
--     running with no time-of-day gate at all (brief's own example: "T +10 minutes: help
--     message" for a fresh lead, which an owner would NOT want silently deferred by 8
--     hours the moment this migration lands). Quiet hours become available, not imposed.
--   default_jitter_min/max_seconds (0/0 = OFF by default): same reasoning — jitter exists
--     to make BULK sends look less bot-like; it is not something every 1-step operational
--     sequence needs by default, and defaulting it on would silently delay every send by a
--     few seconds where nothing was delayed before. An owner who wants Reengage-style
--     jitter turns it on per sequence.
-- Rate limiting has no such "off by default" option because closing that specific gap is
-- this task's actual requirement (brief §51: no silent failure — an account-wide budget
-- must exist, even before an owner has ever configured one).
--
-- DESIGN — respect_opt_out: brief §51 (no silent failure) and the task's own hard rule:
-- this must NEVER be a toggle an automated send can be exempted by. The column is added
-- (schema completeness / a future read-only display), defaulted TRUE, and constrained to
-- TRUE at the DB layer — CHECK (respect_opt_out = 1) — so it cannot be flipped even by
-- direct SQL, let alone a UI bug. Application code (sequence_helper.php) does not read
-- this column at all: the opt-out check is unconditional for every send_message step,
-- sourced from the same `reengage_optout` table `Automation_gateway::context()` already
-- reads (see docs/automations/sequences.md). Belt AND suspenders, not a switch.
--
-- Apply with:  php index.php cron_hub migrate
-- Idempotent: ADD COLUMN IF NOT EXISTS / guarded constraint add.
DO 0;

ALTER TABLE `sales_automation_settings`
  ADD COLUMN IF NOT EXISTS `default_messages_per_hour` INT NOT NULL DEFAULT 30
    COMMENT 'Account-wide fallback for sequences.messages_per_hour when a sequence leaves it NULL (inherit)' AFTER `deflect_alert_whatsapp`,
  ADD COLUMN IF NOT EXISTS `default_daily_cap` INT NOT NULL DEFAULT 200
    COMMENT 'Account-wide daily send cap for automated sequence sends; no per-sequence override (see migration header)' AFTER `default_messages_per_hour`,
  ADD COLUMN IF NOT EXISTS `default_quiet_hours_start` TIME NOT NULL DEFAULT '00:00:00'
    COMMENT 'Account-wide fallback for sequences.quiet_hours_start; equal to end = no quiet hours (OFF by default, see migration header)' AFTER `default_daily_cap`,
  ADD COLUMN IF NOT EXISTS `default_quiet_hours_end` TIME NOT NULL DEFAULT '00:00:00'
    COMMENT 'Account-wide fallback for sequences.quiet_hours_end' AFTER `default_quiet_hours_start`,
  ADD COLUMN IF NOT EXISTS `default_jitter_min_seconds` INT NOT NULL DEFAULT 0
    COMMENT 'Account-wide fallback for sequences.jitter_min_seconds; 0/0 = OFF by default' AFTER `default_quiet_hours_end`,
  ADD COLUMN IF NOT EXISTS `default_jitter_max_seconds` INT NOT NULL DEFAULT 0
    COMMENT 'Account-wide fallback for sequences.jitter_max_seconds' AFTER `default_jitter_min_seconds`;

ALTER TABLE `sequences`
  ADD COLUMN IF NOT EXISTS `messages_per_hour` INT NULL DEFAULT NULL
    COMMENT 'NULL = inherit sales_automation_settings.default_messages_per_hour' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `quiet_hours_start` TIME NULL DEFAULT NULL
    COMMENT 'NULL = inherit sales_automation_settings.default_quiet_hours_start' AFTER `messages_per_hour`,
  ADD COLUMN IF NOT EXISTS `quiet_hours_end` TIME NULL DEFAULT NULL
    COMMENT 'NULL = inherit sales_automation_settings.default_quiet_hours_end' AFTER `quiet_hours_start`,
  ADD COLUMN IF NOT EXISTS `jitter_min_seconds` INT NULL DEFAULT NULL
    COMMENT 'NULL = inherit sales_automation_settings.default_jitter_min_seconds' AFTER `quiet_hours_end`,
  ADD COLUMN IF NOT EXISTS `jitter_max_seconds` INT NULL DEFAULT NULL
    COMMENT 'NULL = inherit sales_automation_settings.default_jitter_max_seconds' AFTER `jitter_min_seconds`,
  ADD COLUMN IF NOT EXISTS `respect_opt_out` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'Always TRUE — see migration header. Never read by application code to gate the opt-out check; the check is unconditional.' AFTER `jitter_max_seconds`;

-- Enforced even against direct SQL. MariaDB 10.2.1+ evaluates CHECK constraints (this
-- server runs 10.11) — a stray UPDATE ... SET respect_out_out = 0 is rejected, not silently
-- accepted.
SET @chk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'sequences' AND CONSTRAINT_NAME = 'chk_sequences_respect_opt_out'
);
SET @sql = IF(@chk_exists = 0,
  'ALTER TABLE `sequences` ADD CONSTRAINT `chk_sequences_respect_opt_out` CHECK (`respect_opt_out` = 1)',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- New, distinct skip reasons (brief §51: no silent failure — each safety gate gets its own
-- visible reason, not folded into the generic 'skipped_policy'). 'pending_jitter' is an
-- internal transient state (see sequence_helper.php sequence_advance()): a send_message
-- step whose jitter delay has not yet elapsed. It resolves to a final status (success /
-- skipped_* / failed) within at most one more worker tick (~60s) and is not meant to be a
-- steady-state count an owner watches, unlike the skipped_* reasons.
ALTER TABLE `sequence_step_log`
  MODIFY COLUMN `status` ENUM(
    'success','skipped','skipped_policy','skipped_stop_condition',
    'skipped_opt_out','skipped_quiet_hours','skipped_rate_limit',
    'pending_jitter','failed'
  ) NOT NULL DEFAULT 'failed';
