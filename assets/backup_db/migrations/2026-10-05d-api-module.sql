-- Task 28 follow-up (C13, brief §37) — tenant-facing "API keys" page. Module id 414 "API
-- Keys": create/list/revoke own api_tokens rows (assets/backup_db/migrations/
-- 2026-10-04g-api-tokens.sql, which already exists — this migration adds no tables, only
-- the module/package/menu entitlement rows). Copies the pattern of
-- 2026-10-04a-customers-module.sql exactly.
--
-- Apply with:  php index.php cron_hub migrate
-- Idempotent: INSERT IGNORE / guarded UPDATE / DELETE-then-INSERT on the menu row.
DO 0;

-- Module 414 continues the Creative Monkey block (400-413). The id map lives in
-- application/helpers/entitlement_helper.php ('api' => 414) and MUST match.
INSERT IGNORE INTO `modules` (`id`, `module_name`, `add_ons_id`, `extra_text`, `limit_enabled`, `bulk_limit_enabled`, `deleted`)
  VALUES (414, 'API Keys', 0, '', '0', '0', '0');

-- Owner decision (same as every prior Creative Monkey module): every existing package
-- includes it.
UPDATE package SET module_ids = CONCAT_WS(',', NULLIF(module_ids, ''), '414')
  WHERE deleted = '0' AND FIND_IN_SET('414', module_ids) = 0;

DELETE FROM menu WHERE url = 'api_keys';
INSERT INTO menu (name,icon,color,url,serial,module_access,have_child,only_admin,only_member,add_ons_id,is_external,header_text,is_menu_manager,custom_page_id)
VALUES ('API Keys','fas fa-key','#20c997','api_keys',12,'414',0,0,0,0,0,'',0,0);
