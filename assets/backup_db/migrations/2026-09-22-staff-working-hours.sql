-- Staff working hours (feature shipped 2026-08-19, columns were added to the live DB by hand
-- with no migration). This file makes the schema reproducible on a fresh install.
--
-- Idempotent: ADD COLUMN IF NOT EXISTS (MariaDB 10.0.2+), so on the live DB it is a no-op.
-- Column types are reconstructed from how the code reads/writes them
-- (application/helpers/working_hours_helper.php, Messenger_bot::mark_seen_chat_human_settings,
-- Openwa_bot::save_hours); the live columns may differ in type. Verify with
-- SHOW CREATE TABLE before relying on these definitions elsewhere.
--
-- hours_enabled '1' = staff-shift window active; the bot stays silent inside the window.
-- hours_days    comma list of ISO weekdays, 1=Mon .. 7=Sun.
-- hours_start / hours_end  'HH:MM:SS', Africa/Cairo; start >= end means an overnight window.
-- hours_message / hours_offline_sent_at  left over from the earlier away-message behaviour,
--               no longer read (kept so old data is not lost).

ALTER TABLE facebook_rx_fb_page_info
  ADD COLUMN IF NOT EXISTS hours_enabled ENUM('0','1') NOT NULL DEFAULT '0',
  ADD COLUMN IF NOT EXISTS hours_start VARCHAR(8) NOT NULL DEFAULT '09:00:00',
  ADD COLUMN IF NOT EXISTS hours_end VARCHAR(8) NOT NULL DEFAULT '22:00:00',
  ADD COLUMN IF NOT EXISTS hours_days VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
  ADD COLUMN IF NOT EXISTS hours_message TEXT NULL;

ALTER TABLE openwa_accounts
  ADD COLUMN IF NOT EXISTS hours_enabled ENUM('0','1') NOT NULL DEFAULT '0',
  ADD COLUMN IF NOT EXISTS hours_start VARCHAR(8) NOT NULL DEFAULT '09:00:00',
  ADD COLUMN IF NOT EXISTS hours_end VARCHAR(8) NOT NULL DEFAULT '22:00:00',
  ADD COLUMN IF NOT EXISTS hours_days VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
  ADD COLUMN IF NOT EXISTS hours_message TEXT NULL;

ALTER TABLE messenger_bot_subscriber
  ADD COLUMN IF NOT EXISTS hours_offline_sent_at DATETIME NULL;
