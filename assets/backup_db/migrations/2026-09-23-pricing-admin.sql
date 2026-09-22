-- Phase 10 / brief 21: Products & Pricing becomes a product module instead of hand-seeded JSON.
-- History table = audit log of every pricing change (who, when, before/after).
DO 0;

CREATE TABLE IF NOT EXISTS pricing_config_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  changed_by INT NOT NULL,
  before_json MEDIUMTEXT NULL,
  after_json MEDIUMTEXT NULL,
  summary VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `modules` (`id`, `module_name`, `add_ons_id`, `extra_text`, `limit_enabled`, `bulk_limit_enabled`, `deleted`)
  VALUES (408, 'Products & Pricing', 0, '', '0', '0', '0');

-- Owner decision 2026-09-22: every existing package includes all Creative Monkey modules
UPDATE package SET module_ids = CONCAT_WS(',', NULLIF(module_ids, ''), '408')
  WHERE deleted = '0' AND FIND_IN_SET('408', module_ids) = 0;

DELETE FROM menu WHERE url = 'pricing';
INSERT INTO menu (name,icon,color,url,serial,module_access,have_child,only_admin,only_member,add_ons_id,is_external,header_text,is_menu_manager,custom_page_id)
VALUES ('Products & Pricing','fas fa-tags','#47c363','pricing',8,'408',0,0,0,0,0,'',0,0);
