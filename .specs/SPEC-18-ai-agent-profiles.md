# SPEC-18: Per-channel AI Agent identity/brand-voice profiles

> Live prod. Read MASTER-PLAN header. Approved design: assignable profiles (identity+behavior), share account key/provider.

## Tables (migration 2026-07-05-spec18.sql + live)
```sql
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
-- menu: AI Agents under a new "AI Tools" header (with AI Content Writer)
```

## Resolver (helper ai_agent_helper.php: ai_resolve_profile($user_id,$social_media,$page_id))
- cheap gate: if user has 0 assignments → return null.
- fb/ig: target = facebook_rx_fb_page_info.id where (id=$page_id OR page_id=$page_id) AND user_id. (Messenger passes table id; IG passes fb string.)
- wa/tg: target = $page_id (account id).
- web: target = webchat_settings.id for user (incoming $page_id='webchat').
- lookup ai_agent_assignments (user_id,channel_type,target) → profile (status=1). Return null if none.

## Wire into Home::get_ai_reply_open_ai (after loading $api_info)
Overlay profile fields onto $api_info[0]: instruction_to_ai, sales_mode_enabled, sales_system_prompt, max_history_messages, temperature, memory_ttl_hours, auto_language, sentiment_enabled, ai_tools_enabled. If profile.model set: override models (openai) or anthropic_model (anthropic) per $api_info[0].ai_provider. Keep keys+provider from account. If no profile → unchanged (backward compatible).

## Controller Ai_agents.php + views admin/ai_agents/
- index: Profiles tab (list + new/edit/copy) + Assignments tab (each connected fb/ig page, wa/tg account, web widget → dropdown of profiles).
- save_profile / delete_profile (POST csrf) / save_assignment (POST csrf, upsert).
- session-auth, uid = real_user_id ?: user_id, ownership on every write.

## Menu: new "AI Tools" header; move AI Content Writer under it; add AI Agents.
