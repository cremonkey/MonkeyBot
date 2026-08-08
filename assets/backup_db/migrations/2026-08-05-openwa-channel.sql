-- OpenWA (self-hosted WhatsApp gateway) channel — same bot paths as FB/IG via media_type='wa'
CREATE TABLE IF NOT EXISTS openwa_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  label VARCHAR(100) DEFAULT '',
  base_url VARCHAR(255) NOT NULL DEFAULT 'https://wa.cremonkey.com',
  api_key TEXT NOT NULL,
  session_id VARCHAR(64) NOT NULL,
  session_name VARCHAR(100) DEFAULT '',
  display_phone VARCHAR(30) DEFAULT '',
  webhook_secret VARCHAR(64) DEFAULT NULL,
  openwa_webhook_id VARCHAR(64) DEFAULT NULL,
  bot_enabled ENUM('0','1') DEFAULT '1',
  ai_enabled ENUM('0','1') DEFAULT '1',
  no_match_found_reply ENUM('enabled','disabled') DEFAULT 'enabled',
  status ENUM('0','1') DEFAULT '1',
  created_at DATETIME,
  UNIQUE KEY uq_openwa_session (session_id),
  KEY idx_openwa_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DELETE FROM menu WHERE url='openwa_bot';
INSERT INTO menu (name,icon,color,url,serial,module_access,have_child,only_admin,only_member,add_ons_id,is_external,header_text,is_menu_manager,custom_page_id)
VALUES ('OpenWA WhatsApp','fab fa-whatsapp','#128C7E','openwa_bot',39,'',0,0,0,0,0,'More Channels',0,0);
