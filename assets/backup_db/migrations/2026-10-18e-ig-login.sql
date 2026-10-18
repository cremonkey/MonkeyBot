-- Instagram API with Instagram Login (no Facebook Page needed). Ported idea from manychat-lite.
-- ig_login_config holds the Instagram app (id + encrypted secret). Accounts connected this way
-- live in facebook_rx_fb_page_info like any IG account, marked connection_type = 'ig_login',
-- with page_id = instagram_business_account_id = the IG user id and the IG user token as the
-- page token. Token expiry is tracked so cron_hub can refresh before the 60-day limit.
-- Additive only.
--
-- Apply with:  php index.php cron_hub migrate
DO 0;

CREATE TABLE IF NOT EXISTS `ig_login_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `app_id` varchar(64) NOT NULL DEFAULT '',
  `app_secret_enc` text NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `facebook_rx_fb_page_info`
  ADD COLUMN IF NOT EXISTS `connection_type` varchar(20) NOT NULL DEFAULT 'fb_login',
  ADD COLUMN IF NOT EXISTS `ig_token_expires_at` datetime NULL,
  ADD COLUMN IF NOT EXISTS `ig_token_refreshed_at` datetime NULL,
  ADD COLUMN IF NOT EXISTS `ig_token_error` varchar(500) NULL;
