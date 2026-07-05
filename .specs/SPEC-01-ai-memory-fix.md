# SPEC-01: Fix AI conversation memory on Instagram & Comments (Phase 1.1)

> READ `.specs/MASTER-PLAN.md` header first (live production, lint & smoke commands, git).

## Problem (verified)
`Home.php::get_ai_reply_open_ai($description, $human, $user_id, $page_id='', $subscribe_id='', $social_media='fb')` (line ~6999) only loads/saves conversation memory (`ai_conversation_history`) when `$page_id` and `$subscribe_id` are non-empty. Today only `Messenger_bot.php:1136` passes them. These 9 call sites pass just 3 args, so every reply is context-free:
- `Instagram_reply.php`: lines ~3186, ~3367, ~3464
- `Comment_automation.php`: lines ~4637, ~4895, ~5034, ~5601, ~5857, ~5997

## Work
1. First inspect `Messenger_bot.php:1136` to see exactly which id formats it passes for `$page_id`/`$subscribe_id` — stay consistent with that convention.
2. For EACH of the 9 call sites, read ±80 surrounding lines to find in-scope variables for the page identifier and the counterpart (IG user / commenter) id.
3. Update calls:
   - Instagram_reply.php sites → `get_ai_reply_open_ai($desc, $human, $user_id, $page_id, $ig_user_id, 'ig')`
   - Comment_automation.php sites → comment contexts; pass `($desc, $human, $user_id, $page_id, $commenter_id, 'fb')` (or 'ig' if that function handles Instagram comments — check each function's context).
   - If a site has NO counterpart id in scope, leave it unchanged and note it.
4. `ai_conversation_history.social_media` is enum('fb','ig') — both values valid. No schema change.
5. Do NOT alter the function itself; only call sites.
6. Lint changed files. Smoke test. Single commit: `feat: pass conversation context to AI on IG DMs and comment replies`.

## Report
Table: call site → variables used → changed/skipped(why).
