-- SPEC-14: A/B testing for messenger broadcasts (schema foundation)
CREATE TABLE IF NOT EXISTS ab_test_variants (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, broadcast_serial_id INT NOT NULL,
  variant CHAR(1) NOT NULL, message LONGTEXT, sent_count INT DEFAULT 0, delivered_count INT DEFAULT 0,
  click_count INT DEFAULT 0, KEY idx_bc (broadcast_serial_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE messenger_bot_broadcast_serial_send ADD COLUMN ab_variant CHAR(1) NULL;
-- DEFERRED (documented): compose-UI variant B textarea, cron split by (send_row_id % 2) in
-- Cron_job::braodcast_message, and per-variant report. Broadcast engine is currently unused (0 rows).
