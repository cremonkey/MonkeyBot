-- SPEC-18: per-channel AI agent identity/brand-voice profiles
CREATE TABLE IF NOT EXISTS ai_agent_profiles (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
  name VARCHAR(100) NOT NULL, agent_name VARCHAR(100),
  instruction_to_ai TEXT, sales_mode_enabled ENUM('0','1') DEFAULT '1', sales_system_prompt TEXT,
  model VARCHAR(100) NULL, max_history_messages INT DEFAULT 6, temperature DECIMAL(3,2) DEFAULT 0.70,
  memory_ttl_hours INT DEFAULT 24, auto_language ENUM('0','1') DEFAULT '1',
  sentiment_enabled ENUM('0','1') DEFAULT '0', ai_tools_enabled ENUM('0','1') DEFAULT '0',
  status ENUM('0','1') DEFAULT '1', created_at DATETIME, KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ai_agent_assignments (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, profile_id INT NOT NULL,
  channel_type ENUM('fb','ig','wa','tg','web') NOT NULL, target_id VARCHAR(64) NOT NULL,
  UNIQUE KEY uq_target (user_id, channel_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- sidebar: new "AI Tools" group
UPDATE menu SET serial=44, header_text='AI Tools' WHERE url='ai_content_writer';
DELETE FROM menu WHERE url='ai_agents';
INSERT INTO menu (name,icon,color,url,serial,module_access,have_child,only_admin,only_member,add_ons_id,is_external,header_text,is_menu_manager,custom_page_id)
VALUES ('AI Agents','fas fa-user-astronaut','#6f42c1','ai_agents',45,'',0,0,0,0,0,'',0,0);
