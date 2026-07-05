-- SPEC-11: standalone web chat widget
CREATE TABLE IF NOT EXISTS webchat_settings (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL UNIQUE, widget_key VARCHAR(32) NOT NULL,
  title VARCHAR(100) DEFAULT 'Chat with us', color VARCHAR(7) DEFAULT '#0084ff',
  greeting VARCHAR(255) DEFAULT 'Hi! How can we help?', ai_enabled ENUM('0','1') DEFAULT '1',
  status ENUM('0','1') DEFAULT '1', UNIQUE KEY uq_key (widget_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS webchat_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, session_key VARCHAR(40) NOT NULL,
  visitor_name VARCHAR(100) NULL, page_url VARCHAR(255), created_at DATETIME, last_activity DATETIME,
  UNIQUE KEY uq_sess (session_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DELETE FROM menu WHERE url='webchat';
INSERT INTO menu (name,icon,color,url,serial,module_access,have_child,only_admin,only_member,add_ons_id,is_external,header_text,is_menu_manager,custom_page_id)
VALUES ('Web Chat Widget','fas fa-comment-dots','#0084ff','webchat',42,'',0,0,0,0,0,'',0,0);
