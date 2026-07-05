-- SPEC-07: lead scoring
ALTER TABLE messenger_bot_subscriber ADD COLUMN lead_score INT NOT NULL DEFAULT 0, ADD INDEX idx_lead_score (lead_score);
CREATE TABLE IF NOT EXISTS lead_scoring_rules (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL DEFAULT 0,
  event_type VARCHAR(50) NOT NULL, points INT NOT NULL, status ENUM('0','1') NOT NULL DEFAULT '1',
  UNIQUE KEY uq_user_event (user_id, event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
CREATE TABLE IF NOT EXISTS lead_scoring_events (
  id INT AUTO_INCREMENT PRIMARY KEY, subscriber_id INT NOT NULL, user_id INT NOT NULL,
  event_type VARCHAR(50) NOT NULL, points INT NOT NULL, event_data VARCHAR(255) NULL,
  created_at DATETIME NOT NULL, KEY idx_sub (subscriber_id), KEY idx_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
INSERT IGNORE INTO lead_scoring_rules (user_id,event_type,points) VALUES
 (0,'message_received',1),(0,'postback_click',3),(0,'price_question',5),
 (0,'add_to_cart',8),(0,'purchase',15),(0,'human_handoff',10);
