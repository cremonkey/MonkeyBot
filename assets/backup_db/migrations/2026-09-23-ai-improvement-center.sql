-- Phase 10 / brief 20: AI Improvement Center. Who answered a gap and from which group,
-- so before/after effectiveness can be measured.
DO 0;
ALTER TABLE ai_faq
  ADD COLUMN IF NOT EXISTS created_by INT NULL,
  ADD COLUMN IF NOT EXISTS source_group VARCHAR(190) NULL COMMENT 'normalised question key of the gap this answer closed',
  ADD COLUMN IF NOT EXISTS questions_before INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;

DELETE FROM menu WHERE url = 'ai_improvement';
INSERT INTO menu (name,icon,color,url,serial,module_access,have_child,only_admin,only_member,add_ons_id,is_external,header_text,is_menu_manager,custom_page_id)
VALUES ('AI Improvement Center','fas fa-lightbulb','#ffa426','ai_improvement',9,'407',0,0,0,0,0,'',0,0);
