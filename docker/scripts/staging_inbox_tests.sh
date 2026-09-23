#!/bin/sh
# Unified Inbox (Phase 9.5) regression suite — STAGING ONLY, isolated stack (127.0.0.1:8093).
# Never the shared staging stack (monkeybot-staging), never production. Synthetic data only.
#
#   docker compose -p mb-inbox-test -f docker-compose.inbox-test.yml up -d
#   docker exec -i <db> sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < assets/backup_db/initial_db.sql
#   docker exec -u www-data <app> php /var/www/html/index.php cron_hub migrate
#   STAGING_DB=... STAGING_APP=... docker/scripts/staging_seed.sh
#   STAGING_URL=http://127.0.0.1:8093 STAGING_DB=... STAGING_APP=... docker/scripts/staging_inbox_tests.sh
#
# Seeds its own conversation history (staging_seed.sh only creates the two tenants + a KB
# source), across fb/ig/tg/web/wa(OpenWA)/wa(Cloud), then proves: tenant-scoped list, IDOR on
# the thread endpoint, an allowed reply (web — no real network call: "sending" IS the DB row)
# sends and logs, a denied reply (wa_cloud, last inbound 30h ago) is refused with the reason
# and writes NO livechat row, assign/status/takeover write conversation_events, a member
# without module 411 is denied, CSRF is enforced, admin is never blocked, and
# backfill_conversations afterwards changes nothing (everything was already trigger-linked).
#
# Follow-up (labels, canned responses per channel, attachments, AI suggested reply) needs the
# stack in docker-compose.inbox2-test.yml (port 8102), which adds the fake OpenWA gateway
# (tests/inbox/fake_gateway.php) so an image reply is proven end to end with no real provider:
#   STAGING_URL=http://127.0.0.1:8102 STAGING_DB=mb-inbox2-test-db-1 STAGING_APP=mb-inbox2-test-app-1 \
#   FAKEGW_CT=mb-inbox2-test-fakegw-1 docker/scripts/staging_inbox_tests.sh
# Without FAKEGW_CT the image-send success/failure checks are skipped (reported as SKIP).
set -u
B=${STAGING_URL:-http://127.0.0.1:8093}
DBC=${STAGING_DB:-mb-inbox-test-db-1}
APP=${STAGING_APP:-mb-inbox-test-app-1}
FAKEGW_CT=${FAKEGW_CT:-}
FAKEGW_URL=${FAKEGW_URL:-http://fakegw:8080}
T=$(mktemp -d); PASS=0; FAIL=0
ok(){ PASS=$((PASS+1)); echo "PASS  $1"; }
bad(){ FAIL=$((FAIL+1)); echo "FAIL  $1  -> $2"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "got '$2' want '$3'"; fi; }
contains(){ case "$2" in *"$3"*) ok "$1";; *) bad "$1" "'$2' does not contain '$3'";; esac; }
sql(){ docker exec "$DBC" sh -c 'mariadb -N -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "$0"' "$1"; }
cli(){ docker exec -u www-data "$APP" php /var/www/html/index.php "$@"; }
login(){ j="$T/$1"; tok=$(curl -s -c "$j" "$B/home/login_page" | grep -o 'name="csrf_token"[^>]*value="[^"]*"' | sed 's/.*value="//;s/"$//' | head -1);
  curl -s -b "$j" -c "$j" -o /dev/null -X POST "$B/home/login" --data-urlencode "csrf_token=$tok" --data-urlencode "username=$1" --data-urlencode "password=Staging#Pass1"; }
tok(){ curl -s -b "$T/$1" "$B/inbox" | grep -o 'var TOKEN = "[^"]*"' | sed 's/.*"\(.*\)"/\1/'; }
post(){ j="$T/$1"; p="$2"; shift 2; curl -s -b "$j" -H 'X-Requested-With: XMLHttpRequest' -X POST "$B/inbox/$p" "$@"; }
jget(){ docker exec -i "$APP" php -r '$d=json_decode(stream_get_contents(STDIN),true); $p=explode(".",$argv[1]); foreach($p as $k){ if(!is_array($d)||!array_key_exists($k,$d)){echo "MISSING";exit;} $d=$d[$k]; } echo is_array($d)?json_encode($d,JSON_UNESCAPED_UNICODE):var_export($d,true);' "$1"; }

UID_A=11; UID_B=12
A=a@staging.local; Bt=b@staging.local

echo "== reset (synthetic only) =="
sql "DELETE FROM conversation_events WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM conversations WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM customer_identities WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM customers WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM internal_notes WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM conversation_labels WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM inbox_labels WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM canned_response WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM ai_conversation_history WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM livechat_messages WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM facebook_rx_fb_page_info WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM telegram_accounts WHERE user_id=$UID_A;
     DELETE FROM openwa_accounts WHERE user_id=$UID_A;
     DELETE FROM whatsapp_accounts WHERE user_id=$UID_A;
     UPDATE package SET module_ids='342' WHERE id=90;" >/dev/null
sql "DROP TABLE IF EXISTS team_members" >/dev/null 2>&1 # only ever a test-only stand-in, see below

echo "== member without module 411 is denied =="
login "$A"
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$T/$A" "$B/inbox")
case "$loc" in *access_forbidden*) ok "member without inbox module denied, redirected to access_forbidden";; *) bad "member without inbox module denied" "redirect=$loc";; esac

sql "UPDATE package SET module_ids='342,411' WHERE id=90" >/dev/null

echo "== seeding channel accounts + conversation history (tenant A=$UID_A, tenant B=$UID_B) =="
# team_members is provided by the (optional) team-member addon and is not part of
# initial_db.sql / the migration set on this branch — Inbox.php is defensive when the table
# is absent (index() and assign() both table_exists()-guard it). To exercise the assign
# feature end-to-end this suite creates a minimal stand-in with the addon's known shape
# (id, user_id, name — the columns Home::assign_conversation_to_team_member already reads).
sql "CREATE TABLE IF NOT EXISTS team_members (
       id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, name VARCHAR(150) NOT NULL,
       email VARCHAR(150) DEFAULT NULL, password VARCHAR(255) DEFAULT NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
     DELETE FROM team_members WHERE user_id=$UID_A;
INSERT INTO facebook_rx_fb_page_info (id,user_id,facebook_rx_fb_user_info_id,page_id,page_name,page_access_token,has_instagram,bot_enabled)
  VALUES (9301,$UID_A,0,'PAGE9301FB','Tenant A Page','tok-fb-a',0,'1'),
         (9302,$UID_A,0,'PAGE9302IG','Tenant A IG','tok-ig-a',1,'1'),
         (9401,$UID_B,0,'PAGE9401FB','Tenant B Page','tok-fb-b',0,'1');
INSERT INTO telegram_accounts (id,user_id,bot_token,webhook_secret,status) VALUES (9303,$UID_A,'tgtoken-a','wsa','1');
INSERT INTO openwa_accounts (id,user_id,base_url,api_key,session_id,status) VALUES (9304,$UID_A,'http://openwa.test','apikey-a','sess-a','1');
INSERT INTO whatsapp_accounts (id,user_id,phone_number_id,access_token,verify_token,status) VALUES (9305,$UID_A,'pn-a','watoken-a','vt-a','1');
INSERT INTO team_members (id,user_id,name,email,password) VALUES (9306,$UID_A,'Agent Ayah','ayah@staging.local','x');
" >/dev/null

NOW_RECENT="DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
NOW_OLD="DATE_SUB(NOW(), INTERVAL 30 HOUR)"
sql "
INSERT INTO livechat_messages (user_id,subscriber_id,page_table_id,fb_page_id,sender,platform,agent_name,message_content,conversation_time) VALUES
  ($UID_A,'FBSUB-A1',9301,'PAGE9301FB','user','fb',NULL,'Hi, is the room available?',$NOW_RECENT),
  ($UID_A,'IGSUB-A1',9302,'PAGE9302IG','user','ig',NULL,'Do you ship internationally?',$NOW_RECENT),
  ($UID_A,'TGCHAT-A1',9303,'','user','tg',NULL,'Hello from Telegram',$NOW_RECENT),
  ($UID_A,'WEBSESS-A1',0,'','user','web',NULL,'Hi from the website widget',$NOW_RECENT),
  ($UID_A,'2010000000001',9304,'','user','wa',NULL,'Hi via WhatsApp Web',$NOW_RECENT),
  ($UID_A,'2010000000002',9305,'','user','wa',NULL,'Old WhatsApp Cloud message',$NOW_OLD),
  ($UID_B,'FBSUB-B1',9401,'PAGE9401FB','user','fb',NULL,'Tenant B customer message',$NOW_RECENT);
" >/dev/null

conv_id(){ sql "SELECT id FROM conversations WHERE user_id=$1 AND external_thread_id='$2'"; }
FB_A=$(conv_id $UID_A FBSUB-A1); IG_A=$(conv_id $UID_A IGSUB-A1); TG_A=$(conv_id $UID_A TGCHAT-A1)
WEB_A=$(conv_id $UID_A WEBSESS-A1); WAOPEN_A=$(conv_id $UID_A 2010000000001); WACLOUD_A=$(conv_id $UID_A 2010000000002)
FB_B=$(conv_id $UID_B FBSUB-B1)
check "trigger auto-created tenant A's 6 conversations" "$([ -n "$FB_A" ] && [ -n "$IG_A" ] && [ -n "$TG_A" ] && [ -n "$WEB_A" ] && [ -n "$WAOPEN_A" ] && [ -n "$WACLOUD_A" ] && echo yes)" "yes"
check "trigger auto-created tenant B's conversation" "$([ -n "$FB_B" ] && echo yes)" "yes"

echo "== admin is never blocked =="
login admin@staging.local
check "admin opens /inbox" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/admin@staging.local" "$B/inbox")" "200"

echo "== tenant-scoped conversation list =="
login "$A"
check "tenant A opens /inbox" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/$A" "$B/inbox")" "200"
K=$(tok "$A")
[ -n "$K" ] || { echo "no CSRF token on /inbox for tenant A"; exit 1; }

r=$(post "$A" conversations_data --data-urlencode "csrf_token=$K" -d start=0 -d length=50)
check "list call ok" "$(echo "$r" | jget status)" "'1'"
check "tenant A's list has exactly 6 conversations" "$(echo "$r" | jget total)" "6"
case "$r" in *FBSUB-B1*) bad "tenant A's list never contains tenant B's thread id" "leaked FBSUB-B1";; *) ok "tenant A's list never contains tenant B's thread id";; esac

echo "== IDOR: conversation_thread only returns the caller's own conversation =="
r=$(post "$A" conversation_thread --data-urlencode "csrf_token=$K" -d conversation_id="$WEB_A")
check "tenant A reads own web thread" "$(echo "$r" | jget status)" "'1'"
check "own thread has messages" "$([ "$(echo "$r" | jget messages)" != "MISSING" ] && echo yes)" "yes"

r=$(post "$A" conversation_thread --data-urlencode "csrf_token=$K" -d conversation_id="$FB_B")
check "tenant A CANNOT read tenant B's conversation by id (IDOR)" "$(echo "$r" | jget status)" "'0'"

echo "== reply: allowed (web — no real network, sending IS the DB row) =="
r=$(post "$A" reply --data-urlencode "csrf_token=$K" -d conversation_id="$WEB_A" --data-urlencode "message=Hello from agent, room is available")
check "web reply allowed and sent" "$(echo "$r" | jget status)" "'1'"
# agent_name is the session 'username', which Home::login() sets to the account's display
# name (users.name = 'Tenant A' for a@staging.local, see staging_seed.sh), not the email —
# same convention Livechat.php's insert_livechat_data() already uses for bot-sent rows.
check "web reply logged as sender=bot with agent name" \
  "$(sql "SELECT COUNT(*) FROM livechat_messages WHERE conversation_id=$WEB_A AND sender='bot' AND agent_name='Tenant A' AND message_content='Hello from agent, room is available'")" "1"

echo "== reply: denied (wa_cloud, last inbound 30h ago -> template_required_outside_24h) =="
r=$(post "$A" reply --data-urlencode "csrf_token=$K" -d conversation_id="$WACLOUD_A" --data-urlencode "message=will not send")
check "wa_cloud reply outside 24h window denied" "$(echo "$r" | jget status)" "'0'"
check "denial reason surfaced" "$(echo "$r" | jget reason)" "'template_required_outside_24h'"
contains "denial explanation is owner-language, not a raw code" "$(echo "$r" | jget explain)" "template"
check "denied reply wrote NO livechat row" "$(sql "SELECT COUNT(*) FROM livechat_messages WHERE conversation_id=$WACLOUD_A AND message_content='will not send'")" "0"

echo "== CSRF enforced =="
r=$(post "$A" reply -d csrf_token=bogus -d conversation_id="$WEB_A" -d message=nope)
case "$r" in *CSRF*|*csrf*) ok "reply without a valid CSRF token is refused";; *) bad "reply without a valid CSRF token is refused" "$r";; esac
check "no row was written for the CSRF-refused attempt" "$(sql "SELECT COUNT(*) FROM livechat_messages WHERE message_content='nope'")" "0"

echo "== assign round-trip =="
r=$(post "$A" assign --data-urlencode "csrf_token=$K" -d conversation_id="$FB_A" -d team_member_id=9306)
check "assign to Agent Ayah" "$(echo "$r" | jget status)" "'1'"
check "assigned_user_id persisted" "$(sql "SELECT assigned_user_id FROM conversations WHERE id=$FB_A")" "9306"
r=$(post "$A" assign --data-urlencode "csrf_token=$K" -d conversation_id="$FB_A" -d team_member_id=0)
check "unassign" "$(echo "$r" | jget status)" "'1'"
check "assigned_user_id cleared" "$(sql "SELECT IFNULL(assigned_user_id,'NULL') FROM conversations WHERE id=$FB_A")" "NULL"

echo "== takeover / status round-trip, audited in conversation_events =="
before=$(sql "SELECT COUNT(*) FROM conversation_events WHERE conversation_id=$IG_A")
r=$(post "$A" takeover --data-urlencode "csrf_token=$K" -d conversation_id="$IG_A")
check "takeover from AI_ACTIVE succeeds (2 hops: WAITING_FOR_HUMAN -> HUMAN_ACTIVE)" "$(echo "$r" | jget status)" "'1'"
check "takeover lands on HUMAN_ACTIVE" "$(sql "SELECT handoff_state FROM conversations WHERE id=$IG_A")" "HUMAN_ACTIVE"
after=$(sql "SELECT COUNT(*) FROM conversation_events WHERE conversation_id=$IG_A")
check "takeover wrote 2 audited conversation_events rows" "$((after - before))" "2"
check "both events are actor_type=agent" "$(sql "SELECT COUNT(*) FROM conversation_events WHERE conversation_id=$IG_A AND actor_type='agent'")" "2"

r=$(post "$A" set_status --data-urlencode "csrf_token=$K" -d conversation_id="$IG_A" -d action=resolve)
check "resolve from HUMAN_ACTIVE" "$(echo "$r" | jget status)" "'1'"
check "status is resolved" "$(sql "SELECT status FROM conversations WHERE id=$IG_A")" "resolved"
r=$(post "$A" set_status --data-urlencode "csrf_token=$K" -d conversation_id="$IG_A" -d action=reopen)
check "reopen back to AI_ACTIVE" "$(sql "SELECT handoff_state FROM conversations WHERE id=$IG_A")" "AI_ACTIVE" # after jget status check below
check "reopen call itself ok" "$(echo "$r" | jget status)" "'1'"

r=$(post "$A" return_to_bot --data-urlencode "csrf_token=$K" -d conversation_id="$TG_A")
check "return_to_bot ok" "$(echo "$r" | jget status)" "'1'"
check "return_to_bot leaves AI_ACTIVE (was already AI_ACTIVE: no-op, still ok)" "$(sql "SELECT handoff_state FROM conversations WHERE id=$TG_A")" "AI_ACTIVE"

echo "== internal notes =="
r=$(post "$A" note_save --data-urlencode "csrf_token=$K" -d conversation_id="$FB_A" --data-urlencode "note=Called customer, will follow up tomorrow")
check "note saved" "$(echo "$r" | jget status)" "'1'"
check "note persisted, tenant-scoped" "$(sql "SELECT COUNT(*) FROM internal_notes WHERE conversation_id=$FB_A AND user_id=$UID_A AND note='Called customer, will follow up tomorrow'")" "1"

echo "== tenant B cannot touch tenant A's conversation through any endpoint =="
login "$Bt"
sql "UPDATE package SET module_ids='342,411' WHERE id=90" >/dev/null
KB=$(tok "$Bt")
r=$(post "$Bt" note_save --data-urlencode "csrf_token=$KB" -d conversation_id="$FB_A" --data-urlencode "note=should not be allowed")
check "tenant B cannot write a note on tenant A's conversation" "$(echo "$r" | jget status)" "'0'"
check "no such note was written" "$(sql "SELECT COUNT(*) FROM internal_notes WHERE note='should not be allowed'")" "0"

# ==================================================================================================
# Follow-up: labels, canned responses per channel, attachments, AI suggested reply
# ==================================================================================================
ncount(){ docker exec "$APP" sh -c 'ls /var/www/html/upload/inbox/*/*/ 2>/dev/null | grep -c "^inbox'"$UID_A"'_" || true'; }

echo "== labels: create / rename / duplicate / CSRF =="
r=$(post "$A" label_save --data-urlencode "csrf_token=$K" --data-urlencode "name=  VIP   <b>customer</b> " -d color=%23AA3300)
check "tenant A creates a label" "$(echo "$r" | jget status)" "'1'"
LBL_A=$(sql "SELECT id FROM inbox_labels WHERE user_id=$UID_A AND name='VIP customer'")
check "label stored normalised (tags stripped, whitespace collapsed, colour lower-cased)" "$(sql "SELECT CONCAT(name,'|',color) FROM inbox_labels WHERE id='$LBL_A'")" "VIP customer|#aa3300"
r=$(post "$A" label_save --data-urlencode "csrf_token=$K" --data-urlencode "name=VIP customer")
check "duplicate label name refused" "$(echo "$r" | jget status)" "'0'"
r=$(post "$A" label_save --data-urlencode "csrf_token=$K" --data-urlencode "name=Follow up" -d color='red;x')
LBL_A2=$(sql "SELECT id FROM inbox_labels WHERE user_id=$UID_A AND name='Follow up'")
check "a bad colour is dropped, not stored" "$(sql "SELECT IFNULL(color,'NULL') FROM inbox_labels WHERE id='$LBL_A2'")" "NULL"
r=$(post "$A" label_save --data-urlencode "csrf_token=$K" -d label_id="$LBL_A2" --data-urlencode "name=Follow up tomorrow")
check "rename own label" "$(sql "SELECT name FROM inbox_labels WHERE id='$LBL_A2'")" "Follow up tomorrow"
r=$(post "$A" label_save -d csrf_token=bogus --data-urlencode "name=csrf label")
check "label_save without a valid CSRF token writes nothing" "$(sql "SELECT COUNT(*) FROM inbox_labels WHERE name='csrf label'")" "0"
sql "INSERT INTO inbox_labels (id,user_id,name,color,created_at,updated_at) VALUES (99801,$UID_B,'B secret label',NULL,NOW(),NOW())" >/dev/null

echo "== labels: attach across channels, filter the list, IDOR =="
for c in "$FB_A" "$TG_A" "$WAOPEN_A"; do post "$A" label_attach --data-urlencode "csrf_token=$K" -d conversation_id="$c" -d label_id="$LBL_A" >/dev/null; done
r=$(post "$A" label_attach --data-urlencode "csrf_token=$K" -d conversation_id="$TG_A" -d label_id="$LBL_A")
check "attaching twice is idempotent" "$(sql "SELECT COUNT(*) FROM conversation_labels WHERE conversation_id=$TG_A AND label_id=$LBL_A")" "1"
contains "attach answers with the conversation's labels" "$(echo "$r" | jget labels)" "VIP customer"
r=$(post "$A" conversations_data --data-urlencode "csrf_token=$K" -d start=0 -d length=50 -d label_id="$LBL_A")
check "list filtered by label: fb + tg + wa(OpenWA) conversations" "$(echo "$r" | jget total)" "3"
contains "list rows carry their labels" "$(echo "$r" | jget rows.0.labels)" "VIP customer"
r=$(post "$A" conversations_data --data-urlencode "csrf_token=$K" -d start=0 -d length=50 -d label_id=99801)
check "filtering by ANOTHER tenant's label id matches nothing" "$(echo "$r" | jget total)" "0"
r=$(post "$A" label_attach --data-urlencode "csrf_token=$K" -d conversation_id="$FB_A" -d label_id=99801)
check "tenant A cannot attach tenant B's label" "$(echo "$r" | jget status)" "'0'"
r=$(post "$A" label_attach --data-urlencode "csrf_token=$K" -d conversation_id="$FB_B" -d label_id="$LBL_A")
check "tenant A cannot label tenant B's conversation" "$(echo "$r" | jget status)" "'0'"
check "no cross-tenant conversation_labels row exists" "$(sql "SELECT COUNT(*) FROM conversation_labels WHERE (label_id=99801 AND user_id<>$UID_B) OR conversation_id=$FB_B")" "0"
r=$(post "$Bt" label_detach --data-urlencode "csrf_token=$KB" -d conversation_id="$FB_A" -d label_id="$LBL_A")
check "tenant B cannot detach tenant A's label" "$(echo "$r" | jget status)" "'0'"
r=$(post "$Bt" label_delete --data-urlencode "csrf_token=$KB" -d label_id="$LBL_A")
check "tenant B cannot delete tenant A's label" "$(echo "$r" | jget status)" "'0'"
r=$(post "$Bt" labels_list --data-urlencode "csrf_token=$KB")
case "$r" in *"VIP customer"*) bad "tenant B's label list never shows tenant A's labels" "leaked";; *) ok "tenant B's label list never shows tenant A's labels";; esac
r=$(post "$A" conversation_thread --data-urlencode "csrf_token=$K" -d conversation_id="$TG_A")
contains "thread returns the conversation's labels" "$(echo "$r" | jget labels)" "VIP customer"
r=$(post "$A" label_detach --data-urlencode "csrf_token=$K" -d conversation_id="$TG_A" -d label_id="$LBL_A")
check "detach" "$(sql "SELECT COUNT(*) FROM conversation_labels WHERE conversation_id=$TG_A")" "0"
r=$(post "$A" label_delete --data-urlencode "csrf_token=$K" -d label_id="$LBL_A")
check "delete label" "$(echo "$r" | jget status)" "'1'"
check "deleting a label removes it from every conversation" "$(sql "SELECT COUNT(*) FROM conversation_labels WHERE label_id=$LBL_A")" "0"
check "tenant B's label untouched" "$(sql "SELECT COUNT(*) FROM inbox_labels WHERE id=99801")" "1"

echo "== canned responses filtered by channel =="
sql "INSERT INTO canned_response (id,user_id,page_id,media_type,name,message) VALUES
       (99701,$UID_A,9301,'fb','Legacy greeting','Hello from Livechat'),
       (99702,$UID_B,9401,'fb','B only','B text')" >/dev/null
check "a pre-existing row has channels NULL (= every channel)" "$(sql "SELECT IFNULL(channels,'NULL') FROM canned_response WHERE id=99701")" "NULL"
r=$(post "$A" canned_save --data-urlencode "csrf_token=$K" --data-urlencode "name=WA hours" --data-urlencode "message=We open at 9" -d 'channels[]=wa' -d 'channels[]=tg')
check "create a WhatsApp + Telegram canned response" "$(echo "$r" | jget status)" "'1'"
CAN_WA=$(sql "SELECT id FROM canned_response WHERE user_id=$UID_A AND name='WA hours'")
check "stored channels, page_id 0 (never in Livechat's per-page list)" "$(sql "SELECT CONCAT(channels,'|',page_id) FROM canned_response WHERE id=$CAN_WA")" "wa,tg|0"
r=$(post "$A" canned_responses --data-urlencode "csrf_token=$K" -d conversation_id="$WACLOUD_A")
contains "wa conversation: WA-only response offered" "$r" "WA hours"
contains "wa conversation: legacy (NULL) response still offered" "$r" "Legacy greeting"
r=$(post "$A" canned_responses --data-urlencode "csrf_token=$K" -d conversation_id="$FB_A")
case "$r" in *"WA hours"*) bad "fb conversation: WA-only response hidden" "shown";; *) ok "fb conversation: WA-only response hidden";; esac
contains "fb conversation: legacy response still offered" "$r" "Legacy greeting"
r=$(post "$A" canned_responses --data-urlencode "csrf_token=$K")
check "no conversation: every row of the tenant (as before)" "$(echo "$r" | jget rows | grep -o '"id"' | wc -l | tr -d ' ')" "2"
r=$(post "$A" canned_responses --data-urlencode "csrf_token=$K" -d conversation_id="$FB_B")
check "canned_responses with another tenant's conversation id refused" "$(echo "$r" | jget status)" "'0'"
r=$(post "$A" canned_save --data-urlencode "csrf_token=$K" -d canned_id=99701 --data-urlencode "name=Legacy greeting" --data-urlencode "message=Hello from Livechat" -d 'channels[]=fb')
check "editing a Livechat row keeps its page_id and media_type" "$(sql "SELECT CONCAT(page_id,'|',media_type,'|',channels) FROM canned_response WHERE id=99701")" "9301|fb|fb"
r=$(post "$A" canned_save --data-urlencode "csrf_token=$K" -d canned_id=99702 --data-urlencode "name=hijack" --data-urlencode "message=x")
check "tenant A cannot edit tenant B's canned response" "$(sql "SELECT name FROM canned_response WHERE id=99702")" "B only"
r=$(post "$A" canned_delete --data-urlencode "csrf_token=$K" -d canned_id=99702)
check "tenant A cannot delete tenant B's canned response" "$(sql "SELECT COUNT(*) FROM canned_response WHERE id=99702")" "1"
r=$(post "$A" canned_delete -d csrf_token=bogus -d canned_id="$CAN_WA")
check "canned_delete without CSRF refused" "$(sql "SELECT COUNT(*) FROM canned_response WHERE id=$CAN_WA")" "1"
r=$(post "$A" canned_delete --data-urlencode "csrf_token=$K" -d canned_id="$CAN_WA")
check "canned_delete own row" "$(sql "SELECT COUNT(*) FROM canned_response WHERE id=$CAN_WA")" "0"

echo "== attachments: incoming media shown from the stored row =="
sql "INSERT INTO livechat_messages (user_id,subscriber_id,page_table_id,fb_page_id,sender,platform,agent_name,message_content,conversation_time) VALUES
  ($UID_A,'FBSUB-A1',9301,'PAGE9301FB','user','fb',NULL,
   '{\"object\":\"page\",\"entry\":[{\"id\":\"PAGE9301FB\",\"messaging\":[{\"sender\":{\"id\":\"FBSUB-A1\"},\"recipient\":{\"id\":\"PAGE9301FB\"},\"message\":{\"mid\":\"m_img\",\"attachments\":[{\"type\":\"image\",\"payload\":{\"url\":\"https://scontent.example/pic.jpg\"}},{\"type\":\"image\",\"payload\":{\"url\":\"javascript:alert(1)\"}}]}}]}]}', NOW()),
  ($UID_A,'2010000000002',9305,'','user','wa',NULL,'[non-text message]',NOW());" >/dev/null
r=$(post "$A" conversation_thread --data-urlencode "csrf_token=$K" -d conversation_id="$FB_A" -d limit=5)
contains "fb webhook body: the image URL is rendered" "$r" "https:\/\/scontent.example\/pic.jpg"
case "$r" in *'"url":"javascript'*) bad "javascript: URL never rendered as media" "rendered";; *) ok "javascript: URL never rendered as media";; esac
r=$(post "$A" conversation_thread --data-urlencode "csrf_token=$K" -d conversation_id="$WACLOUD_A" -d limit=5)
contains "wa media placeholder -> media_unavailable, no invented URL" "$r" '"media_unavailable":true'
r=$(post "$A" conversations_data --data-urlencode "csrf_token=$K" -d start=0 -d length=50)
case "$r" in *'\"object\":\"page\"'*|*'{"object"'*) bad "list preview never shows raw webhook JSON" "raw JSON in preview";; *) ok "list preview never shows raw webhook JSON";; esac

echo "== attachments: image replies =="
docker exec "$APP" php -r '$i=imagecreatetruecolor(12,9); imagepng($i);' > "$T/ok.png"
printf '<?php system($_GET["c"]); ?>' > "$T/shell.jpg"
up(){ j="$T/$1"; shift; curl -s -b "$j" -H 'X-Requested-With: XMLHttpRequest' -X POST "$B/inbox/reply_image" "$@"; }
check "attach button: web cannot send images" "$(post "$A" conversation_thread --data-urlencode "csrf_token=$K" -d conversation_id="$WEB_A" | jget can_send_image)" "false"
check "attach button: telegram can" "$(post "$A" conversation_thread --data-urlencode "csrf_token=$K" -d conversation_id="$TG_A" | jget can_send_image)" "true"
check "attach button: OpenWA can" "$(post "$A" conversation_thread --data-urlencode "csrf_token=$K" -d conversation_id="$WAOPEN_A" | jget can_send_image)" "true"
before_files=$(ncount)
r=$(up "$A" -F "csrf_token=$K" -F conversation_id="$WEB_A" -F "image=@$T/ok.png;type=image/png")
check "image reply on web: refused as unsupported" "$(echo "$r" | jget unsupported)" "true"
# the media-display section above added a fresh inbound row to this thread; put it back outside 24h
sql "UPDATE conversations SET last_inbound_at=$NOW_OLD WHERE id=$WACLOUD_A" >/dev/null
r=$(up "$A" -F "csrf_token=$K" -F conversation_id="$WACLOUD_A" -F "image=@$T/ok.png;type=image/png")
check "image reply on wa_cloud 30h after last inbound: policy denied" "$(echo "$r" | jget reason)" "'template_required_outside_24h'"
r=$(up "$A" -F "csrf_token=bogus" -F conversation_id="$WAOPEN_A" -F "image=@$T/ok.png;type=image/png")
case "$r" in *CSRF*) ok "image reply without CSRF refused";; *) bad "image reply without CSRF refused" "$r";; esac
r=$(up "$Bt" -F "csrf_token=$KB" -F conversation_id="$WAOPEN_A" -F "image=@$T/ok.png;type=image/png")
check "tenant B cannot send an image into tenant A's conversation" "$(echo "$r" | jget message)" "'Conversation not found'"
r=$(up "$A" -F "csrf_token=$K" -F conversation_id="$WAOPEN_A" -F "image=@$T/shell.jpg;type=image/jpeg")
check "PHP file disguised as .jpg refused" "$(echo "$r" | jget message)" "'Only JPEG and PNG images can be sent'"
check "nothing was stored for any refused upload" "$(ncount)" "$before_files"

if [ -n "$FAKEGW_CT" ]; then
  sql "UPDATE openwa_accounts SET base_url='$FAKEGW_URL', session_id='sess-a' WHERE id=9304" >/dev/null
  docker exec "$FAKEGW_CT" sh -c ': > /tmp/fakegw.log'
  rows_before=$(sql "SELECT COUNT(*) FROM livechat_messages WHERE conversation_id=$WAOPEN_A")
  r=$(up "$A" -F "csrf_token=$K" -F conversation_id="$WAOPEN_A" -F "image=@$T/ok.png;type=image/png")
  check "image reply via OpenWA (fake gateway) sent" "$(echo "$r" | jget status)" "'1'"
  gw=$(docker exec "$FAKEGW_CT" cat /tmp/fakegw.log)
  contains "gateway got send-image on the tenant's session" "$gw" '"path":"/api/sessions/sess-a/messages/send-image"'
  contains "gateway got the chat id" "$gw" '"chatId":"2010000000001@c.us"'
  contains "gateway got a public upload/inbox URL of a .png" "$gw" "/upload/inbox/"
  url=$(echo "$r" | jget url | tr -d "'")
  rel=$(echo "$url" | sed 's#^https\?://[^/]*/##')
  check "stored file exists under upload/inbox with a random name and .png" "$(docker exec "$APP" sh -c "test -f /var/www/html/$rel && echo yes" )" "yes"
  case "$rel" in upload/inbox/*/*/inbox${UID_A}_*.png) ok "stored path follows upload/inbox/YYYY/MM/inbox{uid}_{random}.png";; *) bad "stored path pattern" "$rel";; esac
  check "nginx serves it as a static PNG" "$(curl -s -o /dev/null -w '%{http_code} %{content_type}' "$B/$rel")" "200 image/png"
  check "exactly one livechat row written after the successful send" "$(sql "SELECT COUNT(*) FROM livechat_messages WHERE conversation_id=$WAOPEN_A")" "$((rows_before + 1))"
  contains "row stored in the Send API image shape" "$(sql "SELECT message_content FROM livechat_messages WHERE conversation_id=$WAOPEN_A ORDER BY id DESC LIMIT 1")" '"attachment":{"type":"image"'
  r=$(post "$A" conversation_thread --data-urlencode "csrf_token=$K" -d conversation_id="$WAOPEN_A" -d limit=3)
  contains "thread renders the sent image" "$r" '"type":"image"'
  # failure path: the gateway answers 500 -> no row, stored file deleted
  sql "UPDATE openwa_accounts SET session_id='sess-fail' WHERE id=9304" >/dev/null
  files=$(ncount); rows_before=$(sql "SELECT COUNT(*) FROM livechat_messages WHERE conversation_id=$WAOPEN_A")
  r=$(up "$A" -F "csrf_token=$K" -F conversation_id="$WAOPEN_A" -F "image=@$T/ok.png;type=image/png")
  check "gateway failure is reported, not faked" "$(echo "$r" | jget status)" "'0'"
  check "failed send wrote no livechat row" "$(sql "SELECT COUNT(*) FROM livechat_messages WHERE conversation_id=$WAOPEN_A")" "$rows_before"
  check "failed send left no stored file" "$(ncount)" "$files"
  sql "UPDATE openwa_accounts SET base_url='http://openwa.test', session_id='sess-a' WHERE id=9304" >/dev/null
else
  echo "SKIP  image-send success/failure checks (FAKEGW_CT not set)"
fi

echo "== AI suggested reply (ai_conversation_history.conversation_id) =="
sql "INSERT INTO ai_conversation_history (user_id,page_id,subscribe_id,social_media,human_message,ai_reply,created_at,conversation_id) VALUES
  ($UID_A,'9303','TGCHAT-A1','tg','price?','Old answer',DATE_SUB(NOW(), INTERVAL 5 MINUTE),$TG_A),
  ($UID_A,'9303','TGCHAT-A1','tg','and for two nights?','Two nights are 1800 EGP',NOW(),$TG_A),
  ($UID_B,'9401','FBSUB-B1','fb','x','TENANT-B-DRAFT',DATE_ADD(NOW(), INTERVAL 1 MINUTE),$TG_A);" >/dev/null
r=$(post "$A" conversation_thread --data-urlencode "csrf_token=$K" -d conversation_id="$TG_A")
check "thread shows the latest AI turn of this conversation" "$(echo "$r" | jget ai_draft.reply)" "'Two nights are 1800 EGP'"
check "...with the question it answered" "$(echo "$r" | jget ai_draft.answering)" "'and for two nights?'"
case "$r" in *TENANT-B-DRAFT*) bad "another tenant's AI row is never shown, even if it points at this conversation id" "leaked";; *) ok "another tenant's AI row is never shown, even if it points at this conversation id";; esac
check "a conversation with no linked AI turn has no draft" "$(post "$A" conversation_thread --data-urlencode "csrf_token=$K" -d conversation_id="$FB_A" | jget ai_draft)" "NULL"
if [ -n "${MBCONV_USER:-}" ]; then
  out=$(docker exec -e MBCONV_HOST="${MBCONV_HOST:-db}" -e MBCONV_USER="$MBCONV_USER" -e MBCONV_PW="$MBCONV_PW" -e MBCONV_DB="$MBCONV_DB" "$APP" php /var/www/html/tests/inbox/ai_link_db.php 2>&1); rc=$?
  echo "$out" | sed 's/^/    /'
  check "conversation_ai_history_link() against the real DB (tests/inbox/ai_link_db.php)" "$rc" "0"
else
  echo "SKIP  tests/inbox/ai_link_db.php (MBCONV_USER/MBCONV_PW/MBCONV_DB not set)"
fi

echo "== backfill_conversations changes nothing (everything was already trigger-linked) =="
out=$(cli cron_hub backfill_conversations 2>&1); rc=$?
check "backfill exits 0" "$rc" "0"
r=$(post "$A" conversations_data --data-urlencode "csrf_token=$K" -d start=0 -d length=50)
check "tenant A's list still has 6 conversations after backfill" "$(echo "$r" | jget total)" "6"

rm -rf "$T"
echo "== $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
