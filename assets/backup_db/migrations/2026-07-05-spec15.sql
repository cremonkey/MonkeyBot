-- SPEC-15: Telegram channel
CREATE TABLE IF NOT EXISTS telegram_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
  bot_username VARCHAR(100), bot_token VARCHAR(120) NOT NULL, webhook_secret VARCHAR(64) NOT NULL,
  ai_enabled ENUM('0','1') DEFAULT '1', status ENUM('0','1') DEFAULT '1', created_at DATETIME,
  UNIQUE KEY uq_token (bot_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DELETE FROM menu WHERE url='telegram_bot';
INSERT INTO menu (name,icon,color,url,serial,module_access,have_child,only_admin,only_member,add_ons_id,is_external,header_text,is_menu_manager,custom_page_id)
VALUES ('Telegram Bot','fab fa-telegram','#0088cc','telegram_bot',41,'',0,0,0,0,0,'',0,0);
