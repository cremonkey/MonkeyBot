-- Per-tenant feature flags (Task 10 / brief B2, §50).
--
-- One row per (user_id, flag) that overrides the flag for that tenant only. Reading a flag
-- (feature_flag_helper.php: feature_enabled($flag, $uid)) checks this table first; if no row
-- exists for that tenant it falls back to the existing global config item
-- ($this->config->item($flag), TRUE/'1'/1 = on), exactly as every flag read today. If neither
-- gives an answer the flag is FALSE. No existing global flag's behaviour changes: this table
-- starts empty and nothing seeds it, so every flag keeps reading from config until an admin
-- explicitly sets a tenant override.
--
-- Before this migration runs, feature_flag_helper.php checks table_exists() and falls straight
-- through to the config-item/FALSE behaviour — identical to today.
-- Idempotent (CREATE TABLE IF NOT EXISTS). No semicolons in comments: the runner splits on them.
DO 0;

CREATE TABLE IF NOT EXISTS `feature_flags` (
  `user_id` int(11) NOT NULL,
  `flag` varchar(64) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `flag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
