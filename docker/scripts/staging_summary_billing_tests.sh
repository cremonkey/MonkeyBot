#!/bin/sh
# AI conversation summary (brief §13) + Agency usage/billing readiness (brief §11) HTTP+DB
# regression suite — STAGING ONLY, isolated stack (127.0.0.1:8098). Never the shared staging
# stack (monkeybot-staging), never production. Synthetic data only.
#
#   docker compose -p mb-summarybilling-test -f docker-compose.summarybilling-test.yml up -d
#   docker exec -i <db> sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < assets/backup_db/initial_db.sql
#   docker exec -u www-data <app> php /var/www/html/index.php cron_hub migrate
#   STAGING_DB=... STAGING_APP=... docker/scripts/staging_seed.sh
#   STAGING_URL=http://127.0.0.1:8098 STAGING_DB=... STAGING_APP=... docker/scripts/staging_summary_billing_tests.sh
#
# Proves, against the real endpoints:
#  - Inbox::summarize() on a real conversation with seeded messages returns a summary and
#    caches it (conversations.last_summary/last_summary_at);
#  - a second call with no new messages returns the cache WITHOUT calling the AI again
#    (last_summary_at is byte-for-byte unchanged in the DB — if the controller had regenerated
#    it would always overwrite this column with a fresh now());
#  - a third call after a new inbound message DOES regenerate (last_summary_at advances);
#  - tenant isolation: tenant B cannot summarize tenant A's conversation (IDOR);
#  - a member without module 411 (inbox) is denied;
#  - the Agency Control Center renders package/expiry/usage readiness correctly for a seeded
#    near-expiry, near-limit package.
#
# The AI provider itself is faked via config.php's conversation_summary_test_fixture flag (see
# that file's docblock and application/helpers/conversation_summary_test_fixture_helper.php) —
# flipped with sed exactly like staging_prompt_to_flow_tests.sh flips prompt_to_flow_test_fixture
# — so no real AI provider API key is needed anywhere in this run.
set -u
B=${STAGING_URL:-http://127.0.0.1:8098}
DBC=${STAGING_DB:-mb-summarybilling-test-db-1}
APP=${STAGING_APP:-mb-summarybilling-test-app-1}
ROOT=${STAGING_ROOT:-$(cd "$(dirname "$0")/../.." && pwd)}
T=$(mktemp -d); PASS=0; FAIL=0

ok(){ PASS=$((PASS+1)); echo "PASS  $1"; }
bad(){ FAIL=$((FAIL+1)); echo "FAIL  $1  -> $2"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "got '$2' want '$3'"; fi; }
contains(){ case "$2" in *"$3"*) ok "$1";; *) bad "$1" "'$2' does not contain '$3'";; esac; }
not_contains(){ case "$2" in *"$3"*) bad "$1" "'$2' unexpectedly contains '$3'";; *) ok "$1";; esac; }
sql(){ docker exec "$DBC" sh -c 'mariadb -N -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "$0"' "$1"; }
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
     DELETE FROM livechat_messages WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM facebook_rx_fb_page_info WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM open_ai_config WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM usage_log WHERE module_id=340 AND user_id IN ($UID_A,$UID_B);
     DELETE FROM package WHERE id=91;
     UPDATE package SET module_ids='342' WHERE id=90;
     UPDATE users SET package_id=90, expired_date='2030-01-01' WHERE id IN ($UID_A,$UID_B);" >/dev/null

echo "== member without module 411 (inbox) is denied =="
login "$A"
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$T/$A" "$B/inbox")
case "$loc" in *access_forbidden*) ok "member without inbox module denied, redirected to access_forbidden";; *) bad "member without inbox module denied" "redirect=$loc";; esac

echo "== grant module 411 (inbox), re-login =="
sql "UPDATE package SET module_ids='342,411' WHERE id=90" >/dev/null
login "$A"
login "$Bt"
K_A=$(tok "$A"); K_B=$(tok "$Bt")
[ -n "$K_A" ] || { echo "no CSRF token for tenant A on /inbox"; exit 1; }

echo "== seed: channel account, AI credentials, and a conversation with messages (tenant A) =="
sql "
INSERT INTO facebook_rx_fb_page_info (id,user_id,facebook_rx_fb_user_info_id,page_id,page_name,page_access_token,has_instagram,bot_enabled)
  VALUES (9601,$UID_A,0,'PAGE9601FB','Tenant A Page','tok-fb-a',0,'1'),
         (9602,$UID_B,0,'PAGE9602FB','Tenant B Page','tok-fb-b',0,'1');
INSERT INTO open_ai_config (user_id,ai_provider,models,open_ai_secret_key,instruction_to_ai) VALUES ($UID_A,'openai','gpt-4o-mini','','');
INSERT INTO livechat_messages (user_id,subscriber_id,page_table_id,fb_page_id,sender,platform,agent_name,message_content,conversation_time) VALUES
  ($UID_A,'FBSUB-SB1',9601,'PAGE9601FB','user','fb',NULL,'Hi, is the deluxe room available next weekend?',DATE_SUB(NOW(), INTERVAL 10 MINUTE)),
  ($UID_A,'FBSUB-SB1',9601,'PAGE9601FB','bot','fb','AI','Let me check availability for you.',DATE_SUB(NOW(), INTERVAL 9 MINUTE));
" >/dev/null

CONV_ID=$(sql "SELECT id FROM conversations WHERE user_id=$UID_A AND external_thread_id='FBSUB-SB1' LIMIT 1")
if [ -z "$CONV_ID" ]; then echo "conversation was not linked by the Phase 9.5 trigger — aborting"; exit 1; fi
ok "seeded conversation linked by the trigger (id=$CONV_ID)"

echo "== flip the test-only AI fixture flag on (never set in production — see config.php) =="
FLAG_LINE=$(grep -n "conversation_summary_test_fixture" "$ROOT/application/config/config.php" | grep FALSE | head -1 | cut -d: -f1)
if [ -z "$FLAG_LINE" ]; then echo "no conversation_summary_test_fixture line in config.php — aborting"; exit 1; fi
sed -i "${FLAG_LINE}s/FALSE/'fixed'/" "$ROOT/application/config/config.php"
# opcache.revalidate_freq=2 (docker/php/php.ini) means a request within ~2s of the file edit
# can still see the old compiled config.php — give it a moment before the first AI-dependent call.
sleep 3

echo "== happy path: first summarize() call generates and caches a summary =="
r=$(post "$A" summarize --data-urlencode "csrf_token=$K_A" -d "conversation_id=$CONV_ID")
check "first call: status=1" "$(echo "$r" | jget status)" "'1'"
check "first call: not served from cache (nothing cached yet)" "$(echo "$r" | jget cached)" "false"
contains "first call: summary text comes from the fixture" "$(echo "$r" | jget summary)" "TEST-FIXTURE SUMMARY"

DB_SUMMARY_1=$(sql "SELECT last_summary FROM conversations WHERE id=$CONV_ID")
DB_SUMMARY_AT_1=$(sql "SELECT last_summary_at FROM conversations WHERE id=$CONV_ID")
contains "conversations.last_summary was written" "$DB_SUMMARY_1" "TEST-FIXTURE SUMMARY"
[ -n "$DB_SUMMARY_AT_1" ] && ok "conversations.last_summary_at was written" || bad "conversations.last_summary_at was written" "(empty)"

sleep 1

echo "== second call, no new messages: cache returned, AI NOT re-invoked =="
r2=$(post "$A" summarize --data-urlencode "csrf_token=$K_A" -d "conversation_id=$CONV_ID")
check "second call: status=1" "$(echo "$r2" | jget status)" "'1'"
check "second call: served from cache" "$(echo "$r2" | jget cached)" "true"
DB_SUMMARY_AT_2=$(sql "SELECT last_summary_at FROM conversations WHERE id=$CONV_ID")
check "second call: last_summary_at is UNCHANGED (proves the AI was not called again)" "$DB_SUMMARY_AT_2" "$DB_SUMMARY_AT_1"

echo "== a new message arrives, then a third call DOES regenerate =="
sql "INSERT INTO livechat_messages (user_id,subscriber_id,page_table_id,fb_page_id,sender,platform,agent_name,message_content,conversation_time)
     VALUES ($UID_A,'FBSUB-SB1',9601,'PAGE9601FB','user','fb',NULL,'Also, do you have parking?',NOW());" >/dev/null
r3=$(post "$A" summarize --data-urlencode "csrf_token=$K_A" -d "conversation_id=$CONV_ID")
check "third call: status=1" "$(echo "$r3" | jget status)" "'1'"
check "third call: NOT served from cache (a newer message exists)" "$(echo "$r3" | jget cached)" "false"
DB_SUMMARY_AT_3=$(sql "SELECT last_summary_at FROM conversations WHERE id=$CONV_ID")
if [ "$DB_SUMMARY_AT_3" \> "$DB_SUMMARY_AT_2" ]; then ok "third call: last_summary_at advanced (regenerated)"; else bad "third call: last_summary_at advanced (regenerated)" "before=$DB_SUMMARY_AT_2 after=$DB_SUMMARY_AT_3"; fi

echo "== explicit refresh=1 bypasses the cache even with no new messages =="
r4=$(post "$A" summarize --data-urlencode "csrf_token=$K_A" -d "conversation_id=$CONV_ID" -d "refresh=1")
check "refresh: status=1" "$(echo "$r4" | jget status)" "'1'"
check "refresh: NOT served from cache" "$(echo "$r4" | jget cached)" "false"

echo "== tenant isolation: B cannot summarize A's conversation (IDOR) =="
rB=$(post "$Bt" summarize --data-urlencode "csrf_token=$K_B" -d "conversation_id=$CONV_ID")
check "tenant B is refused against tenant A's conversation" "$(echo "$rB" | jget status)" "'0'"
contains "  ...with 'Conversation not found', not tenant A's summary" "$(echo "$rB" | jget message)" "Conversation not found"

echo "== no AI credentials configured -> never fabricates a summary =="
sql "INSERT INTO livechat_messages (user_id,subscriber_id,page_table_id,fb_page_id,sender,platform,agent_name,message_content,conversation_time)
     VALUES ($UID_B,'FBSUB-SB2',9602,'PAGE9602FB','user','fb',NULL,'Tenant B customer message, no AI credentials on this tenant',NOW());" >/dev/null
CONV_ID_B=$(sql "SELECT id FROM conversations WHERE user_id=$UID_B AND external_thread_id='FBSUB-SB2' LIMIT 1")
rNoCred=$(post "$Bt" summarize --data-urlencode "csrf_token=$K_B" -d "conversation_id=$CONV_ID_B")
check "no open_ai_config row for tenant B: refused" "$(echo "$rNoCred" | jget status)" "'0'"
contains "  ...with the same wording ai_agent_helper.php already uses" "$(echo "$rNoCred" | jget message)" "No AI credentials configured"

echo "== CSRF enforced on summarize() =="
rCsrf=$(post "$A" summarize --data-urlencode "csrf_token=bogus" -d "conversation_id=$CONV_ID")
contains "summarize() without a valid CSRF token is refused" "$rCsrf" "CSRF"

echo "== cleanup: restore the test-only flag =="
sed -i "${FLAG_LINE}s/'fixed'/FALSE/" "$ROOT/application/config/config.php"

echo ""
echo "== Agency Control Center: usage/billing readiness (brief §11) =="
echo "== seed a near-expiry, near-limit package for tenant B =="
sql "
INSERT INTO package (id,package_name,module_ids,monthly_limit,bulk_limit,price,validity,is_default,deleted,visible)
  VALUES (91,'Readiness Test Pkg','342,340','{\"340\":\"5\"}','{}','0','30','0','0','1');
UPDATE users SET package_id=91, expired_date=DATE_ADD(NOW(), INTERVAL 3 DAY) WHERE id=$UID_B;
INSERT INTO usage_log (module_id,user_id,usage_month,usage_year,usage_count) VALUES (340,$UID_B,MONTH(NOW()),YEAR(NOW()),3);
" >/dev/null
# module 340's extra_text is '' (cumulative, not month-scoped — see agency_billing_helper.php's
# docblock), so the usage_month/usage_year values above are irrelevant to the read path; the
# row's usage_count is summed regardless of month.

login admin@staging.local
AGENCY_HTML=$(curl -s -b "$T/admin@staging.local" "$B/agency")
contains "Agency page renders for admin" "$AGENCY_HTML" "Agency Control Center"
contains "the seeded package name appears" "$AGENCY_HTML" "Readiness Test Pkg"
contains "the near-expiry renewal is flagged (badge-danger, <7 days)" "$AGENCY_HTML" "badge-danger"
contains "the usage fraction (3/5) is shown" "$AGENCY_HTML" "3/5"
contains "the usage percentage (60%) is shown" "$AGENCY_HTML" "60%"
contains "'Renewals due soon' totals tile is present" "$AGENCY_HTML" "Renewals due soon"

echo "== cleanup: restore tenant B's package/expiry, drop the test package =="
sql "UPDATE users SET package_id=90, expired_date='2030-01-01' WHERE id=$UID_B;
     DELETE FROM usage_log WHERE module_id=340 AND user_id=$UID_B;
     DELETE FROM package WHERE id=91;
     UPDATE package SET module_ids='342' WHERE id=90;
     DELETE FROM conversation_events WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM conversations WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM customer_identities WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM customers WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM livechat_messages WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM facebook_rx_fb_page_info WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM open_ai_config WHERE user_id IN ($UID_A,$UID_B);" >/dev/null

rm -rf "$T"
echo "== $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
