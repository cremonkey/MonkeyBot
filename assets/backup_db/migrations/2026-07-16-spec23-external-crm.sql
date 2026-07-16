-- SPEC-23 — push every AI-captured lead into an external CRM (8xCRM).
--
-- Credentials live here, never in the repo. The OAuth password grant needs all four of
-- client_id/client_secret/username/password; client credentials alone are not enough.
-- Verified against https://byootbay.8xcrm.com on 2026-07-16: token_type=Bearer,
-- expires_in=2592000 (30d), and storeLead returned {"status":true,"data":{"id":3861}}.
--
-- NOTE the host: the docs live on byootbay.8xcrm.NET but that host serves the Angular
-- SPA — POSTing /oauth/token there returns HTML. The API is on .COM (which redirects
-- only the browser UI to .net). Getting this wrong looks like "the token endpoint is
-- broken".

CREATE TABLE IF NOT EXISTS `crm_external_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `provider` varchar(30) NOT NULL DEFAULT '8xcrm',
  `base_url` varchar(255) NOT NULL DEFAULT 'https://byootbay.8xcrm.com',
  `client_id` varchar(100) DEFAULT NULL,
  `client_secret` varchar(255) DEFAULT NULL,
  `username` varchar(190) DEFAULT NULL,
  `password` varchar(190) DEFAULT NULL,
  `form_id` varchar(100) DEFAULT NULL,
  `default_country_code` varchar(5) NOT NULL DEFAULT 'EG',
  `email_account_type_id` int(11) NOT NULL DEFAULT 22,
  `access_token` text DEFAULT NULL,
  `token_type` varchar(30) DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `status` enum('0','1') NOT NULL DEFAULT '0',
  `last_error` text DEFAULT NULL,
  `last_sync_at` datetime DEFAULT NULL,
  `sent_count` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log every push so a silent outage is visible. The hook fails OPEN — a broken CRM
-- must never cost us the customer's reply — so this table is the only evidence.
CREATE TABLE IF NOT EXISTS `crm_external_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `deal_id` int(11) DEFAULT NULL,
  `provider` varchar(30) NOT NULL DEFAULT '8xcrm',
  `phone` varchar(60) DEFAULT NULL,
  `remote_id` varchar(60) DEFAULT NULL,
  `ok` enum('0','1') NOT NULL DEFAULT '0',
  `http_code` int(11) DEFAULT NULL,
  `error` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `deal_id` (`deal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Turn the log into a QUEUE. Measured 2026-07-16: 8xCRM's storeLead takes 10-14s per
-- call. That ran inside Ai_tools::t_save_lead, i.e. while the customer waits for the
-- bot's reply — every lead would have cost the customer a 10s+ stall, and a 12s timeout
-- made it fail intermittently anyway. The lead is now enqueued instantly and pushed by
-- Cron_hub (every 5 min), which also buys retries for free.
ALTER TABLE `crm_external_log`
  ADD COLUMN `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending' AFTER `provider`,
  ADD COLUMN `attempts` int(11) NOT NULL DEFAULT 0 AFTER `status`,
  ADD COLUMN `payload` text DEFAULT NULL AFTER `attempts`,
  ADD COLUMN `next_attempt_at` datetime DEFAULT NULL AFTER `attempts`,
  ADD KEY `status_next` (`status`, `next_attempt_at`);
