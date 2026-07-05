-- SPEC-16: AI content writer
CREATE TABLE IF NOT EXISTS ai_content_history (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
  content_type VARCHAR(30), prompt_input TEXT, generated_content LONGTEXT, language VARCHAR(30),
  created_at DATETIME, KEY idx_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- sidebar entry
DELETE FROM menu WHERE url='ai_content_writer';
INSERT INTO menu (name,icon,color,url,serial,module_access,have_child,only_admin,only_member,add_ons_id,is_external,header_text,is_menu_manager,custom_page_id)
VALUES ('AI Content Writer','fas fa-feather-alt','#c56cf0','ai_content_writer',95,'',0,0,0,0,0,'',0,0);
