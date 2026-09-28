-- Read-only API v2 tokens (remaining-work Task 28 / C13, brief §37).
--
-- One row per issued personal-access token. The plaintext token is shown to the tenant exactly
-- once at creation time (Agency.php's admin area) and never stored: only token_hash (sha256 hex
-- of the plaintext) is kept, so a leaked database dump cannot be replayed as bearer tokens.
-- scopes is a comma-separated list of the api_v2 areas the token may read (conversations,
-- customers, crm, analytics, or '*' for all) - read-only regardless, this API v2 surface has no
-- write endpoints. expires_at is optional (NULL means no expiry) - revoked_at is set by the
-- tenant's own revoke action and is permanent (no un-revoke).
--
-- api_rate_limit is a companion table for the per-token, per-minute request cap: one row per
-- (token_id, window_start minute), upserted with an incrementing counter. A row here older than
-- the current window is simply ignored (not swept) - Api_v2_controller only ever reads/writes
-- the current minute's row, so the table grows slowly and can be pruned by a future cron step
-- without affecting correctness.
--
-- Additive, idempotent (CREATE TABLE IF NOT EXISTS). No semicolons in comments: the migration
-- runner splits statements on a semicolon at end of line.
DO 0;

CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL COMMENT 'tenant that owns/can revoke this token',
  `token_hash` char(64) NOT NULL COMMENT 'sha256 hex of the plaintext token; plaintext is never stored',
  `name` varchar(100) NOT NULL DEFAULT '',
  `scopes` varchar(255) NOT NULL DEFAULT '*',
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL COMMENT 'NULL = no expiry',
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_tokens_hash` (`token_hash`),
  KEY `idx_api_tokens_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_rate_limit` (
  `token_id` int unsigned NOT NULL,
  `window_start` datetime NOT NULL COMMENT 'current minute, truncated to the minute',
  `request_count` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`token_id`, `window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
