-- Bind webchat sessions to their widget + IP for per-session/per-IP rate limiting and
-- cross-widget isolation (security: unauthenticated cost-drain + session read).
ALTER TABLE `webchat_sessions`
  ADD COLUMN `widget_key` varchar(64) DEFAULT NULL AFTER `user_id`,
  ADD COLUMN `ip` varchar(64) DEFAULT NULL AFTER `page_url`,
  ADD KEY `ip_activity` (`ip`, `last_activity`);
