-- Instagram comment/mention campaigns: per-campaign test mode (ported from manychat-lite).
-- dry_run = 1 means the webhook runs matching and AI as usual but sends nothing to Instagram
-- (no private reply, no public reply, no hide/delete). Report rows are tagged [TEST MODE] and
-- removed when the campaign goes live, so they never block a real reply. Additive only.
--
-- Apply with:  php index.php cron_hub migrate
DO 0;

ALTER TABLE `instagram_reply_autoreply`
  ADD COLUMN IF NOT EXISTS `dry_run` tinyint(1) NOT NULL DEFAULT 0;
