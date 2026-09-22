#!/bin/sh
# Prompt-to-Flow HTTP+DB regression suite (brief §24) — STAGING ONLY. Synthetic data, never
# production. Run docker/scripts/staging_seed.sh first for the base fixtures (admin id 1,
# tenants 11/12, package 90).
#
#   docker compose -p mb-prompttoflow-test -f docker-compose.prompttoflow-test.yml up -d
#   docker exec -i <db> sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < assets/backup_db/initial_db.sql
#   docker exec -u www-data <app> php /var/www/html/index.php cron_hub migrate
#   STAGING_DB=... STAGING_APP=... docker/scripts/staging_seed.sh
#   STAGING_URL=http://127.0.0.1:8096 STAGING_DB=... STAGING_APP=... docker/scripts/staging_prompt_to_flow_tests.sh
#
# Proves, against the real endpoint: generating a draft creates ONLY a
# visual_flow_builder_campaign row with valid json_data and touches NEITHER messenger_bot NOR
# messenger_bot_postback (the draft-not-live guarantee — nothing publishes without a human
# opening the real editor and clicking Save there); a member without module 315 is denied; a
# tenant cannot generate against another tenant's page (IDOR); CSRF is enforced on generate().
#
# The AI provider itself is faked via config.php's prompt_to_flow_test_fixture flag (see that
# file's docblock and application/helpers/flow_prompt_test_fixture_helper.php) — this script
# flips it with sed exactly like staging_sequences_tests.sh flips automation_engine_enabled,
# so no real AI provider API key is needed anywhere in this run.
set -u
B=${STAGING_URL:-http://127.0.0.1:8096}
DBC=${STAGING_DB:-mb-prompttoflow-test-db-1}
APP=${STAGING_APP:-mb-prompttoflow-test-app-1}
ROOT=${STAGING_ROOT:-$(cd "$(dirname "$0")/../.." && pwd)}
T=$(mktemp -d); PASS=0; FAIL=0

ok(){ PASS=$((PASS+1)); echo "PASS  $1"; }
bad(){ FAIL=$((FAIL+1)); echo "FAIL  $1  -> $2"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "got '$2' want '$3'"; fi; }
contains(){ case "$2" in *"$3"*) ok "$1";; *) bad "$1" "'$2' does not contain '$3'";; esac }
sql(){ docker exec "$DBC" sh -c 'mariadb -N -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "$0"' "$1"; }
cli(){ docker exec -u www-data "$APP" php /var/www/html/index.php "$@"; }
login(){ j="$T/$1"; tok=$(curl -s -c "$j" "$B/home/login_page" | grep -o 'name="csrf_token"[^>]*value="[^"]*"' | sed 's/.*value="//;s/"$//' | head -1);
  curl -s -b "$j" -c "$j" -o /dev/null -X POST "$B/home/login" --data-urlencode "csrf_token=$tok" --data-urlencode "username=$1" --data-urlencode "password=Staging#Pass1"; }
tokof(){ curl -s -b "$T/$1" "$B/$2" | grep -o 'PROMPT_TO_FLOW_CSRF = "[^"]*"' | sed 's/.*"\(.*\)"/\1/'; }
# csrf_token_session is set once per session (Home's constructor) and reused everywhere, so
# this works on ANY page the account can reach — including ones module 315 does not gate —
# to get a valid token without depending on module-315 access to the flow builder page itself.
anytok(){ curl -s -b "$T/$1" "$B/home/login_page" | grep -o 'name="csrf_token"[^>]*value="[^"]*"' | sed 's/.*value="//;s/"$//' | head -1; }
post(){ j="$T/$1"; p="$2"; shift 2; curl -s -b "$j" -H 'X-Requested-With: XMLHttpRequest' -X POST "$B/$p" "$@"; }
jget(){ docker exec -i "$APP" php -r '$d=json_decode(stream_get_contents(STDIN),true); $p=explode(".",$argv[1]); foreach($p as $k){ if(!is_array($d)||!array_key_exists($k,$d)){echo "MISSING";exit;} $d=$d[$k]; } echo is_array($d)?json_encode($d,JSON_UNESCAPED_UNICODE):var_export($d,true);' "$1"; }

UID_A=11; UID_B=12
echo "== reset =="
sql "DELETE FROM visual_flow_builder_campaign WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM facebook_rx_fb_page_info WHERE id=9301;
     DELETE FROM open_ai_config WHERE user_id=$UID_A;
     UPDATE package SET module_ids='342' WHERE id=90;" >/dev/null

sql "INSERT INTO facebook_rx_fb_page_info
       (id,user_id,facebook_rx_fb_user_info_id,page_id,page_name,username,page_access_token,add_date,last_lead_sync,last_estimaed_at,bot_enabled,has_instagram)
     VALUES (9301,$UID_A,0,'PAGE9301','Tenant A Hotel Page','tenantahotel','stub-token',CURDATE(),NOW(),NOW(),'1','0');" >/dev/null
sql "INSERT INTO open_ai_config (user_id,ai_provider,models,open_ai_secret_key,instruction_to_ai) VALUES ($UID_A,'openai','gpt-4o-mini','','');" >/dev/null

BASELINE_MB=$(sql "SELECT COUNT(*) FROM messenger_bot")
BASELINE_MBP=$(sql "SELECT COUNT(*) FROM messenger_bot_postback")

echo "== flip the test-only AI fixture flag on (never set in production — see config.php) =="
FLAG_LINE=$(grep -n "prompt_to_flow_test_fixture" "$ROOT/application/config/config.php" | grep FALSE | head -1 | cut -d: -f1)
if [ -z "$FLAG_LINE" ]; then echo "no prompt_to_flow_test_fixture line in config.php — aborting"; exit 1; fi
sed -i "${FLAG_LINE}s/FALSE/'hotel_text_fallback'/" "$ROOT/application/config/config.php"

echo "== member without module 315 is denied =="
login b@staging.local
# Without module 315, Visual_flow_builder's OWN controller (which renders the page carrying
# the CSRF token) redirects tenant B away before any token is issued — so K_B comes back
# empty and the request is denied at the CSRF gate rather than reaching the module-315 check
# inside Flow_prompt::generate() itself. Either way the outcome required by the brief holds:
# a member without module 315 access never gets a usable draft. We assert on that outcome
# (status is never success), not on which specific gate produced the denial.
K_B=$(tokof b@staging.local visual_flow_builder/flowbuilder_manager)
r=$(post b@staging.local flow_prompt/generate --data-urlencode "csrf_token=$K_B" --data-urlencode "instruction=hotel booking" -d page_table_id=9301)
st=$(echo "$r" | jget status)
if [ "$st" = "'1'" ] || [ "$st" = "1" ]; then bad "member without module 315 is denied" "got a success status: $r"; else ok "member without module 315 is denied"; fi

# a second, targeted check with a genuinely valid CSRF token, to prove it is specifically
# check_module_action_access(315, ...) inside Flow_prompt::generate() doing the denying here,
# not just the outer CSRF gate.
AT_B=$(anytok b@staging.local)
if [ -n "$AT_B" ]; then
  r2=$(post b@staging.local flow_prompt/generate --data-urlencode "csrf_token=$AT_B" --data-urlencode "instruction=hotel booking" -d page_table_id=9301)
  contains "  ...specifically, the module-315 permission message (not CSRF, not ownership)" "$(echo "$r2" | jget message)" "access to perform this action"
else
  echo "SKIP  targeted module-315 denial check (could not obtain a token for tenant B any other way)"
fi

echo "== grant module 315, re-login =="
sql "UPDATE package SET module_ids='342,315' WHERE id=90" >/dev/null
login a@staging.local
login b@staging.local
K_A=$(tokof a@staging.local visual_flow_builder/flowbuilder_manager)
K_B=$(tokof b@staging.local visual_flow_builder/flowbuilder_manager)
[ -n "$K_A" ] || { echo "no CSRF token for tenant A on the flow builder page"; exit 1; }

echo "== CSRF enforced on generate() =="
r=$(post a@staging.local flow_prompt/generate --data-urlencode "csrf_token=bogus" --data-urlencode "instruction=hotel booking" -d page_table_id=9301)
contains "generate() without a valid CSRF token is refused" "$r" "CSRF"

echo "== tenant isolation: B cannot generate against A's page (IDOR) =="
r=$(post b@staging.local flow_prompt/generate --data-urlencode "csrf_token=$K_B" --data-urlencode "instruction=hotel booking" -d page_table_id=9301)
check "tenant B is refused against tenant A's page" "$(echo "$r" | jget status)" "0"
contains "  ...with an ownership message, not a crash" "$(echo "$r" | jget message)" "belong"

echo "== happy path: tenant A generates a DRAFT (fake AI fixture, no real API key) =="
r=$(post a@staging.local flow_prompt/generate --data-urlencode "csrf_token=$K_A" --data-urlencode "instruction=When someone asks about hotel booking, ask guests and confirm." -d page_table_id=9301)
check "generate reports success" "$(echo "$r" | jget status)" "1"
ID=$(echo "$r" | jget id | tr -d "'")
contains "an edit_url pointing at the real editor is returned" "$(echo "$r" | jget edit_url)" "visual_flow_builder/edit_builder_data/$ID/1/"

echo "== the draft is real, owned by the right tenant, and has valid json_data =="
check "exactly one new campaign row" "$(sql "SELECT COUNT(*) FROM visual_flow_builder_campaign WHERE id=$ID")" "1"
check "it is owned by tenant A" "$(sql "SELECT user_id FROM visual_flow_builder_campaign WHERE id=$ID")" "$UID_A"
check "it is scoped to page 9301" "$(sql "SELECT page_id FROM visual_flow_builder_campaign WHERE id=$ID")" "9301"
VALID_JSON=$(docker exec "$APP" php -r '$r=json_decode($argv[1],true); echo (is_array($r) && isset($r["nodes"]["1"])) ? "yes" : "no";' "$(sql "SELECT json_data FROM visual_flow_builder_campaign WHERE id=$ID")")
check "json_data decodes to a valid nodes graph" "$VALID_JSON" "yes"

echo "== draft-not-live guarantee: NOTHING published to messenger_bot / messenger_bot_postback =="
check "messenger_bot row count unchanged" "$(sql "SELECT COUNT(*) FROM messenger_bot")" "$BASELINE_MB"
check "messenger_bot_postback row count unchanged" "$(sql "SELECT COUNT(*) FROM messenger_bot_postback")" "$BASELINE_MBP"

echo "== a blank instruction is refused without ever calling the AI =="
r=$(post a@staging.local flow_prompt/generate --data-urlencode "csrf_token=$K_A" --data-urlencode "instruction=" -d page_table_id=9301)
check "blank instruction refused" "$(echo "$r" | jget status)" "0"

echo "== admin is never blocked by the module-315 gate =="
login admin@staging.local
K_ADMIN=$(tokof admin@staging.local visual_flow_builder/flowbuilder_manager)
sql "UPDATE package SET module_ids='342' WHERE id=90" >/dev/null
# admin does not own page 9301 either, so this still fails ownership — but the MESSAGE proves
# admin got past the module-315 gate (package has no 315 at all here) rather than being denied
# by it; a module-315 denial would say "You do not have access to perform this action."
r=$(post admin@staging.local flow_prompt/generate --data-urlencode "csrf_token=$K_ADMIN" --data-urlencode "instruction=hotel booking" -d page_table_id=9301)
contains "admin is not blocked by module 315 (fails ownership instead, not permission)" "$(echo "$r" | jget message)" "belong"

echo "== cleanup: restore the test-only flag and package to their defaults =="
sed -i "${FLAG_LINE}s/'hotel_text_fallback'/FALSE/" "$ROOT/application/config/config.php"
sql "UPDATE package SET module_ids='342' WHERE id=90;
     DELETE FROM visual_flow_builder_campaign WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM facebook_rx_fb_page_info WHERE id=9301;
     DELETE FROM open_ai_config WHERE user_id=$UID_A;" >/dev/null

rm -rf "$T"
echo "== $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
