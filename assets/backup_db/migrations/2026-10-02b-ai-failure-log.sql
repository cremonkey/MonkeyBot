-- AI provider and tool failure log (remaining-work Task 4, brief 51: no silent failure).
--
-- Ai_provider returns provider errors (missing key, upstream {"error":...}) as JSON to the
-- caller unchanged - the customer's reply behaviour never changes - but until now nothing
-- recorded that a failure happened. ai_tools_failure records tool exceptions the same way
-- (kind='tool'), and Ai_tools::execute() already returns its own safe fallback string to the model.
--
-- Code works before this migration runs: ai_failure_helper.php calls table_exists() fresh
-- on every insert attempt (not cached) and never throws either way. Deliberate deviation
-- from plan rule 4's "cache the result in a static": this only runs on the failure path
-- (a handful of times per AI turn, not a hot per-page-load loop like Inbox.php), so a
-- fresh check every time costs nothing and the table is picked up the moment this
-- migration lands, with no worker restart needed.
--
-- Apply with:  php index.php cron_hub migrate
-- Idempotent (CREATE TABLE IF NOT EXISTS). No semicolons in comments: the runner splits on them.
DO 0;

CREATE TABLE IF NOT EXISTS `ai_failure_log` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `kind` ENUM('provider','tool') NOT NULL,
  `provider` VARCHAR(32) NOT NULL DEFAULT '',
  `model` VARCHAR(64) NOT NULL DEFAULT '',
  `purpose` VARCHAR(40) NOT NULL DEFAULT '',
  `tool_name` VARCHAR(64) NOT NULL DEFAULT '',
  `args_hash` VARCHAR(64) NOT NULL DEFAULT '',
  `error_class` VARCHAR(80) NOT NULL DEFAULT '',
  `error_message` VARCHAR(500) NOT NULL DEFAULT '',
  `http_status` SMALLINT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
