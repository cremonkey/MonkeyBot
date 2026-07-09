-- SPEC-19: re-engagement broadcast to prior FB/IG contacts.
-- Eligibility-gated: only contacts inside Meta's 24h window are sent to
-- automatically; the rest queue until they message the page again.

CREATE TABLE IF NOT EXISTS reengage_campaign (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(200) NOT NULL,
  social_media ENUM('fb','ig','web') NOT NULL DEFAULT 'fb',
  page_table_id INT NOT NULL DEFAULT 0,
  fb_page_id VARCHAR(200) NOT NULL DEFAULT '',
  message_json MEDIUMTEXT,
  variant_b_json MEDIUMTEXT NULL,
  filters_json TEXT,
  messages_per_hour INT NOT NULL DEFAULT 60,
  jitter_min_sec INT NOT NULL DEFAULT 2,
  jitter_max_sec INT NOT NULL DEFAULT 8,
  quiet_start TIME NOT NULL DEFAULT '22:00:00',
  quiet_end TIME NOT NULL DEFAULT '08:00:00',
  timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Cairo',
  daily_cap INT NOT NULL DEFAULT 500,
  schedule_time DATETIME NULL,
  queue_ttl_days INT NOT NULL DEFAULT 30,
  reentry_idle_minutes INT NOT NULL DEFAULT 30,
  status ENUM('draft','scheduled','running','paused','done','halted') NOT NULL DEFAULT 'draft',
  halt_reason TEXT,
  consecutive_errors INT NOT NULL DEFAULT 0,
  created_at DATETIME NULL,
  completed_at DATETIME NULL,
  KEY idx_user (user_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per targeted contact. `eligibility` is a fact about the customer and
-- is recomputed at send time; `state` is the row's place in the campaign
-- lifecycle. Keeping them apart is what makes the queue resumable.
CREATE TABLE IF NOT EXISTS reengage_recipient (
  id INT AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT NOT NULL,
  user_id INT NOT NULL,
  subscriber_auto_id INT NOT NULL,
  subscribe_id VARCHAR(255) NOT NULL DEFAULT '',
  page_table_id INT NOT NULL DEFAULT 0,
  eligibility ENUM('in_window','human_agent','out_of_window') NOT NULL DEFAULT 'out_of_window',
  state ENUM('pending','sending','waiting_reentry','reentered','sent','skipped','failed','expired','fulfilled') NOT NULL DEFAULT 'pending',
  skip_reason VARCHAR(64) NOT NULL DEFAULT '',
  ab_variant CHAR(1) NULL,
  queued_at DATETIME NULL,
  reentered_at DATETIME NULL,
  sent_at DATETIME NULL,
  expires_at DATETIME NULL,
  error_code VARCHAR(20) NOT NULL DEFAULT '',
  error_message TINYTEXT,
  message_sent_id VARCHAR(200) NOT NULL DEFAULT '',
  UNIQUE KEY uq_campaign_subscriber (campaign_id, subscriber_auto_id),
  KEY idx_campaign_state (campaign_id, state),
  KEY idx_sent_at (campaign_id, sent_at),
  KEY idx_subscriber_state (subscriber_auto_id, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cursor-resumable import of historical Graph conversations.
CREATE TABLE IF NOT EXISTS reengage_import_run (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  page_table_id INT NOT NULL,
  social_media ENUM('fb','ig') NOT NULL DEFAULT 'fb',
  cursor_after TEXT,
  thread_count INT NOT NULL DEFAULT 0,
  imported_count INT NOT NULL DEFAULT 0,
  updated_count INT NOT NULL DEFAULT 0,
  status ENUM('running','done','failed') NOT NULL DEFAULT 'running',
  error_message TEXT,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  KEY idx_user_page (user_id, page_table_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Customers who asked to stop. Checked at list-build AND again at send time.
CREATE TABLE IF NOT EXISTS reengage_optout (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  subscribe_id VARCHAR(255) NOT NULL,
  social_media ENUM('fb','ig','wa','web','tg') NOT NULL DEFAULT 'fb',
  source VARCHAR(32) NOT NULL DEFAULT 'keyword',
  created_at DATETIME NULL,
  UNIQUE KEY uq_user_subscriber (user_id, subscribe_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sidebar entry, immediately after AI Agents (serial 45).
DELETE FROM menu WHERE url='reengage';
INSERT INTO menu (name,icon,color,url,serial,module_access,have_child,only_admin,only_member,add_ons_id,is_external,header_text,is_menu_manager,custom_page_id)
VALUES ('Re-engagement','fas fa-rotate-left','#20c997','reengage',46,'',0,0,0,0,0,'',0,0);
