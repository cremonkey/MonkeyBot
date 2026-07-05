-- SPEC-10: WhatsApp Cloud API channel
CREATE TABLE IF NOT EXISTS whatsapp_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
  label VARCHAR(100), waba_id VARCHAR(50), phone_number_id VARCHAR(50) NOT NULL,
  display_phone VARCHAR(30), access_token TEXT NOT NULL, verify_token VARCHAR(64) NOT NULL,
  ai_enabled ENUM('0','1') DEFAULT '1', status ENUM('0','1') DEFAULT '1', created_at DATETIME,
  UNIQUE KEY uq_pnid (phone_number_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS whatsapp_templates (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, account_id INT NOT NULL,
  template_name VARCHAR(120), language VARCHAR(10) DEFAULT 'en', category VARCHAR(30),
  body TEXT, meta_status VARCHAR(20) DEFAULT 'draft', created_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DELETE FROM menu WHERE url='whatsapp_bot';
INSERT INTO menu (name,icon,color,url,serial,module_access,have_child,only_admin,only_member,add_ons_id,is_external,header_text,is_menu_manager,custom_page_id)
VALUES ('WhatsApp Bot','fab fa-whatsapp','#25D366','whatsapp_bot',40,'',0,0,0,0,0,'',0,0);
