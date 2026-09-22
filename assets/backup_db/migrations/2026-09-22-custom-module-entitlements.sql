-- Phase 0 / 7.7 — package entitlements for Creative Monkey custom features.
-- Creates `modules` rows 400-407 so they can be ticked per package in
-- Admin > Package Manager (and per team role). The id map lives in
-- application/helpers/entitlement_helper.php (entitlement_map) and MUST match.
--
-- Apply with:  php index.php cron_hub migrate
-- Then edit every package (Monthly, Trial, ...) in Admin > Package Manager and tick
-- the features it should include. Only AFTER that set
--   $config['entitlement_enforce_custom_modules'] = TRUE
-- (default unset/FALSE = log-only). Admin users are never blocked.
--
-- Idempotent: INSERT IGNORE on the primary key. Pre-check that 400-407 are free:
--   SELECT id, module_name FROM modules WHERE id BETWEEN 400 AND 407
-- Menu rows are NOT changed here (module_access stays '').
-- The runner skips any chunk starting with a comment, so this header ends in a no-op.
DO 0;
INSERT IGNORE INTO `modules` (`id`, `module_name`, `add_ons_id`, `extra_text`, `limit_enabled`, `bulk_limit_enabled`, `deleted`) VALUES
(400, 'AI Agents', 0, '', '0', '0', '0'),
(401, 'CRM', 0, '', '0', '0', '0'),
(402, 'Sales Automation', 0, '', '0', '0', '0'),
(403, 'AI Content Writer', 0, '', '0', '0', '0'),
(404, 'Appointment Booking', 0, '', '0', '0', '0'),
(405, 'Analytics Hub', 0, '', '0', '0', '0'),
(406, 'Re-engagement', 0, '', '0', '0', '0'),
(407, 'Missed Questions', 0, '', '0', '0', '0');
