-- SPEC-13: AI usage log
CREATE TABLE IF NOT EXISTS ai_usage_log (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
  provider VARCHAR(20), model VARCHAR(100), input_tokens INT DEFAULT 0, output_tokens INT DEFAULT 0,
  purpose VARCHAR(40) DEFAULT 'chat_reply', created_at DATETIME, KEY idx_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
DELETE FROM menu WHERE url='analytics_hub';
INSERT INTO menu (name,icon,color,url,serial,module_access,have_child,only_admin,only_member,add_ons_id,is_external,header_text,is_menu_manager,custom_page_id)
VALUES ('Analytics Hub','fas fa-chart-line','#3abaf4','analytics_hub',8,'',0,0,0,0,0,'',0,0);
