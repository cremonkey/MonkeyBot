-- Gate the custom-module sidebar items by package (entitlement_helper map, modules 400-407).
-- Idempotent: only fills rows that still have an empty module_access.
-- Package contents are tenant business data and are NOT set here: on 2026-09-22 modules
-- 400-407 were added to the existing Trial and Monthly packages by hand (Admin decision).
DO 0;
UPDATE menu SET module_access='401' WHERE url='crm' AND module_access='';
UPDATE menu SET module_access='405' WHERE url='analytics_hub' AND module_access='';
UPDATE menu SET module_access='403' WHERE url='ai_content_writer' AND module_access='';
UPDATE menu SET module_access='404' WHERE url='appointment_booking' AND module_access='';
UPDATE menu SET module_access='400' WHERE url='ai_agents' AND module_access='';
UPDATE menu SET module_access='406' WHERE url='reengage' AND module_access='';
