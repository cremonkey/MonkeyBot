-- Phase 11 / brief 31: Agency Control Center (admin-only sidebar entry). No schema change.
DO 0;
DELETE FROM menu WHERE url = 'agency';
INSERT INTO menu (name,icon,color,url,serial,module_access,have_child,only_admin,only_member,add_ons_id,is_external,header_text,is_menu_manager,custom_page_id)
VALUES ('Agency Control Center','fas fa-building','#6777ef','agency',1,'',0,1,0,0,0,'',0,0);
