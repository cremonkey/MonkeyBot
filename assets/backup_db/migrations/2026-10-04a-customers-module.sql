-- Task 16 (C1, brief §16/§13) — Customer 360 (read side). Module id 413 "Customers": a
-- read-only aggregation page over customers/customer_identities/conversations plus the
-- optional CRM/appointments/ecommerce tables, and a compact panel reused from Inbox
-- (Inbox::customer_panel). Adds no tables: everything it reads already exists
-- (2026-09-23-conversation-core.sql, 2026-09-25-unified-inbox.sql, 2026-09-30-inbox-labels.sql,
-- 2026-07-05-spec12.sql crm_deals, 2026-07-05-spec17.sql ab_appointments, ecommerce_cart in
-- initial_db.sql). This migration only registers the module/package/menu entitlement rows,
-- copying the pattern of 2026-09-26-sequences.sql exactly.
--
-- Apply with:  php index.php cron_hub migrate
-- Idempotent: INSERT IGNORE / guarded UPDATE / DELETE-then-INSERT on the menu row.
DO 0;

-- Module 413 continues the Creative Monkey block (400-412, see 2026-09-22-custom-module-
-- entitlements.sql, 2026-09-23-pricing-admin.sql, 2026-09-24-event-automation.sql,
-- 2026-09-24-onboarding.sql, 2026-09-25-unified-inbox.sql, 2026-09-26-sequences.sql). The id
-- map lives in application/helpers/entitlement_helper.php ('customers' => 413) and MUST match.
INSERT IGNORE INTO `modules` (`id`, `module_name`, `add_ons_id`, `extra_text`, `limit_enabled`, `bulk_limit_enabled`, `deleted`)
  VALUES (413, 'Customers', 0, '', '0', '0', '0');

-- Owner decision (same as every prior Creative Monkey module): every existing package
-- includes it.
UPDATE package SET module_ids = CONCAT_WS(',', NULLIF(module_ids, ''), '413')
  WHERE deleted = '0' AND FIND_IN_SET('413', module_ids) = 0;

DELETE FROM menu WHERE url = 'customers';
INSERT INTO menu (name,icon,color,url,serial,module_access,have_child,only_admin,only_member,add_ons_id,is_external,header_text,is_menu_manager,custom_page_id)
VALUES ('Customers','fas fa-address-book','#6f42c1','customers',11,'413',0,0,0,0,0,'',0,0);
