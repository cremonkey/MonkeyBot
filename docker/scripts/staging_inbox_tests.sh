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
set -u
B=${STAGING_URL:-http://127.0.0.1:8093}
DBC=${STAGING_DB:-mb-inbox-test-db-1}
APP=${STAGING_APP:-mb-inbox-test-app-1}
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

echo "== backfill_conversations changes nothing (everything was already trigger-linked) =="
out=$(cli cron_hub backfill_conversations 2>&1); rc=$?
check "backfill exits 0" "$rc" "0"
r=$(post "$A" conversations_data --data-urlencode "csrf_token=$K" -d start=0 -d length=50)
check "tenant A's list still has 6 conversations after backfill" "$(echo "$r" | jget total)" "6"

rm -rf "$T"
echo "== $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
