#!/bin/sh
# Automation Engine regression suite — STAGING ONLY. Synthetic data, never production.
#
#   docker compose -p mb-automation-test -f docker-compose.automation-test.yml up -d
#   docker exec -i <db> sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < assets/backup_db/initial_db.sql
#   docker exec -u www-data <app> php /var/www/html/index.php cron_hub migrate
#   STAGING_DB=... STAGING_APP=... docker/scripts/staging_seed.sh
#   STAGING_URL=http://127.0.0.1:8091 STAGING_DB=... STAGING_APP=... docker/scripts/staging_automation_tests.sh
#
# It proves the whole chain on a live stack: a real app action emits a real event, the CLI
# worker picks it up, the actions land in the database, a policy-denied message is logged and
# NOT sent, replay is idempotent, a member without module 409 is refused, and the worker has
# no HTTP surface.
set -u
B=${STAGING_URL:-http://127.0.0.1:8091}
DBC=${STAGING_DB:-mb-automation-test-db-1}
APP=${STAGING_APP:-mb-automation-test-app-1}
ROOT=${STAGING_ROOT:-$(cd "$(dirname "$0")/../.." && pwd)}
T=$(mktemp -d); PASS=0; FAIL=0

ok(){ PASS=$((PASS+1)); echo "PASS  $1"; }
bad(){ FAIL=$((FAIL+1)); echo "FAIL  $1  -> $2"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "got '$2' want '$3'"; fi; }
contains(){ case "$2" in *"$3"*) ok "$1";; *) bad "$1" "'$2' does not contain '$3'";; esac }
sql(){ docker exec "$DBC" sh -c 'mariadb -N -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "$0"' "$1"; }
cli(){ docker exec -u www-data "$APP" php /var/www/html/index.php "$@"; }
login(){ j="$T/$1"; tok=$(curl -s -c "$j" "$B/home/login_page" | grep -o 'name="csrf_token"[^>]*value="[^"]*"' | sed 's/.*value="//;s/"$//');
  curl -s -b "$j" -c "$j" -o /dev/null -X POST "$B/home/login" --data-urlencode "csrf_token=$tok" --data-urlencode "username=$1" --data-urlencode "password=Staging#Pass1"; }
tokof(){ curl -s -b "$T/$1" "$B/$2" | grep -o 'var TOKEN = "[^"]*"' | sed 's/.*"\(.*\)"/\1/'; }
post(){ j="$T/$1"; p="$2"; shift 2; curl -s -b "$j" -H 'X-Requested-With: XMLHttpRequest' -X POST "$B/$p" "$@"; }
jget(){ docker exec -i "$APP" php -r '$d=json_decode(stream_get_contents(STDIN),true); $p=explode(".",$argv[1]); foreach($p as $k){ if(!is_array($d)||!array_key_exists($k,$d)){echo "MISSING";exit;} $d=$d[$k]; } echo is_array($d)?json_encode($d,JSON_UNESCAPED_UNICODE):var_export($d,true);' "$1"; }

UID_A=1; UID_B=12
echo "== reset =="
sql "DELETE FROM automation_action_log; DELETE FROM automation_runs; DELETE FROM automation_rules; DELETE FROM events;
     DELETE FROM messenger_bot_subscribers_label; DELETE FROM messenger_bot_broadcast_contact_group WHERE group_name='Hot lead';
     DELETE FROM messenger_bot_subscriber WHERE id IN (9001,9002); DELETE FROM crm_deals WHERE user_id IN ($UID_A,$UID_B);
     DELETE FROM livechat_messages WHERE user_id=$UID_A;
     UPDATE package SET module_ids='342' WHERE id=90;" >/dev/null

# A customer of tenant A who last wrote 48 hours ago on Facebook: inside the 7-day window but
# OUTSIDE the 24h window, so channel policy must refuse an AUTOMATED message to them.
sql "INSERT INTO messenger_bot_subscriber (id,user_id,page_table_id,page_id,subscribe_id,full_name,email,phone_number,social_media,status,lead_score,subscribed_at,last_subscriber_interaction_time)
     VALUES (9001,$UID_A,1,'PAGE1','PSID9001','Nada Test','nada@example.test','201000000000','fb','1',80,NOW(),DATE_SUB(NOW(), INTERVAL 48 HOUR));" >/dev/null

login admin@staging.local
check "admin opens Automations" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/admin@staging.local" "$B/automations")" "200"
K=$(tokof admin@staging.local automations)
[ -n "$K" ] || { echo "no CSRF token on /automations"; exit 1; }

echo "== the worker has no web surface =="
check "automation_worker/run over HTTP is 404" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/admin@staging.local" "$B/automation_worker/run")" "404"
check "automation_worker index over HTTP is 404" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/admin@staging.local" "$B/automation_worker")" "404"
check "automation_worker/pending over HTTP is 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B/automation_worker/pending")" "404"

echo "== creating rules through the UI endpoints =="
r=$(post admin@staging.local automations/save --data-urlencode "csrf_token=$K" -d id=0 \
  --data-urlencode "name=Hot lead handling" -d trigger_event=lead.stage_changed -d status=active \
  --data-urlencode 'conditions_json=[{"field":"lead_score","op":"gte","value":70}]' \
  --data-urlencode 'actions_json=[{"type":"add_label","label":"Hot lead"},{"type":"update_lead_score","delta":5}]')
check "rule A saved and activated" "$(echo "$r" | jget status)" "'1'"
RULE_A=$(echo "$r" | jget id | tr -d "'")

r=$(post admin@staging.local automations/save --data-urlencode "csrf_token=$K" -d id=0 \
  --data-urlencode "name=Message the customer" -d trigger_event=lead.stage_changed -d status=active \
  --data-urlencode 'conditions_json=[]' \
  --data-urlencode 'actions_json=[{"type":"send_message","text":"Hello {{full_name}}"}]')
check "rule B saved and activated" "$(echo "$r" | jget status)" "'1'"
RULE_B=$(echo "$r" | jget id | tr -d "'")

r=$(post admin@staging.local automations/save --data-urlencode "csrf_token=$K" -d id=0 \
  --data-urlencode "name=Never matches" -d trigger_event=lead.stage_changed -d status=active \
  --data-urlencode 'conditions_json=[{"field":"lead_score","op":"gte","value":999}]' \
  --data-urlencode 'actions_json=[{"type":"add_label","label":"Impossible"}]')
RULE_C=$(echo "$r" | jget id | tr -d "'")
check "rule C saved" "$(echo "$r" | jget status)" "'1'"

r=$(post admin@staging.local automations/save --data-urlencode "csrf_token=$K" -d id=0 \
  --data-urlencode "name=Bad rule" -d trigger_event=lead.stage_changed -d status=draft \
  --data-urlencode 'conditions_json=[]' --data-urlencode 'actions_json=[{"type":"call_webhook","url":"http://insecure.test/x"}]')
check "http:// webhook rejected at save time" "$(echo "$r" | jget status)" "'0'"
contains "the error is in plain language" "$r" "must start with"

r=$(post admin@staging.local automations/save --data-urlencode "csrf_token=bogus" -d id=0 --data-urlencode "name=x" -d trigger_event=lead.created)
contains "save without a valid CSRF token is refused" "$r" "CSRF"

echo "== dry run changes nothing =="
r=$(post admin@staging.local automations/test --data-urlencode "csrf_token=$K" -d trigger_event=lead.stage_changed -d event_id=0 \
  --data-urlencode 'payload_json={"lead_score":80,"platform":"fb","subscriber_id":9001}' \
  --data-urlencode 'conditions_json=[{"field":"lead_score","op":"gte","value":70}]' \
  --data-urlencode 'actions_json=[{"type":"add_label","label":"Dry"},{"type":"send_message","text":"hi"}]')
check "dry run says the conditions match" "$(echo "$r" | jget matched)" "true"
contains "dry run explains the message would not go out" "$(echo "$r" | jget actions.1.would)" "not send"
check "dry run created no run rows" "$(sql "SELECT COUNT(*) FROM automation_runs")" "0"
check "dry run created no label" "$(sql "SELECT COUNT(*) FROM messenger_bot_broadcast_contact_group WHERE group_name='Dry'")" "0"

echo "== a real app action emits a real event =="
curl -s -b "$T/admin@staging.local" -o /dev/null "$B/crm"            # seeds the default pipeline
PID=$(sql "SELECT id FROM crm_pipelines WHERE user_id=$UID_A ORDER BY id LIMIT 1")
S_NEW=$(sql "SELECT id FROM crm_stages WHERE pipeline_id=$PID AND name='New Lead'")
S_INT=$(sql "SELECT id FROM crm_stages WHERE pipeline_id=$PID AND name='Interested'")
sql "INSERT INTO crm_deals (id,user_id,pipeline_id,stage_id,title,value,currency,subscriber_id,status,created_at,updated_at)
     VALUES (9101,$UID_A,$PID,$S_NEW,'Test deal',1000,'EGP',9001,'open',NOW(),NOW());" >/dev/null
post admin@staging.local crm/deal_move --data-urlencode "csrf_token=$K" -d id=9101 -d "stage_id=$S_INT" >/dev/null
check "moving a deal emitted lead.stage_changed" "$(sql "SELECT COUNT(*) FROM events WHERE user_id=$UID_A AND event_type='lead.stage_changed'")" "1"
check "the event is unprocessed" "$(sql "SELECT COUNT(*) FROM events WHERE processed_at IS NULL")" "1"
EV=$(sql "SELECT id FROM events ORDER BY id DESC LIMIT 1")

echo "== feature flag (brief 50) =="
FLAG_LINE=$(grep -Fn '$config['"'"'automation_engine_enabled'"'"'] = ' "$ROOT/application/config/config.php" | head -1 | cut -d: -f1)
if [ -n "$FLAG_LINE" ]; then
  sed -i "${FLAG_LINE}s/TRUE/FALSE/" "$ROOT/application/config/config.php"
  out=$(cli automation_worker run 2>&1)
  contains "worker refuses to run while the flag is off" "$out" "disabled"
  check "and it left the event alone" "$(sql "SELECT COUNT(*) FROM events WHERE processed_at IS NULL")" "1"
  sed -i "${FLAG_LINE}s/FALSE/TRUE/" "$ROOT/application/config/config.php"
else
  echo "SKIP  feature flag (no automation_engine_enabled line in config.php)"
fi

echo "== the worker =="
out=$(cli automation_worker run 2>&1)
contains "worker reports what it did" "$out" "events 1"
check "the event is now marked processed" "$(sql "SELECT COUNT(*) FROM events WHERE processed_at IS NULL")" "0"
check "one run per active rule" "$(sql "SELECT COUNT(*) FROM automation_runs")" "3"
check "rule A succeeded" "$(sql "SELECT status FROM automation_runs WHERE rule_id=$RULE_A")" "success"
check "rule C was skipped (conditions not met)" "$(sql "SELECT status FROM automation_runs WHERE rule_id=$RULE_C")" "skipped"
contains "and the skip says which condition failed" "$(sql "SELECT detail FROM automation_runs WHERE rule_id=$RULE_C")" "lead_score"

echo "== the actions really happened =="
check "the customer was tagged" "$(sql "SELECT COUNT(*) FROM messenger_bot_subscribers_label l JOIN messenger_bot_broadcast_contact_group g ON g.id=l.contact_group_id WHERE g.group_name='Hot lead' AND l.subscriber_table_id=9001")" "1"
check "the tag belongs to tenant A" "$(sql "SELECT user_id FROM messenger_bot_broadcast_contact_group WHERE group_name='Hot lead'")" "$UID_A"
check "the lead score went 80 -> 85" "$(sql "SELECT lead_score FROM messenger_bot_subscriber WHERE id=9001")" "85"
check "both of rule A's actions are logged" "$(sql "SELECT COUNT(*) FROM automation_action_log al JOIN automation_runs r ON r.id=al.run_id WHERE r.rule_id=$RULE_A")" "2"

echo "== channel policy denial (brief 26 + 51) =="
check "the denied send is recorded, not hidden" "$(sql "SELECT al.status FROM automation_action_log al JOIN automation_runs r ON r.id=al.run_id WHERE r.rule_id=$RULE_B")" "skipped_policy"
check "with the policy's own reason" "$(sql "SELECT al.error FROM automation_action_log al JOIN automation_runs r ON r.id=al.run_id WHERE r.rule_id=$RULE_B")" "automation_outside_24h_window"
check "NOTHING was sent to the customer" "$(sql "SELECT COUNT(*) FROM livechat_messages WHERE user_id=$UID_A AND subscriber_id='PSID9001'")" "0"
check "a denial is never retried" "$(sql "SELECT al.retry_count FROM automation_action_log al JOIN automation_runs r ON r.id=al.run_id WHERE r.rule_id=$RULE_B")" "0"

echo "== idempotency =="
sql "UPDATE events SET processed_at=NULL WHERE id=$EV" >/dev/null
cli automation_worker run >/dev/null 2>&1
check "replaying the event creates no extra runs" "$(sql "SELECT COUNT(*) FROM automation_runs")" "3"
check "and the score was not changed again" "$(sql "SELECT lead_score FROM messenger_bot_subscriber WHERE id=9001")" "85"
check "and the customer still has one tag" "$(sql "SELECT COUNT(*) FROM messenger_bot_subscribers_label WHERE subscriber_table_id=9001")" "1"

echo "== tenant isolation =="
RUN_A=$(sql "SELECT id FROM automation_runs WHERE rule_id=$RULE_A")
sql "INSERT INTO events (user_id,event_type,subject_type,subject_id,payload,created_at)
     VALUES ($UID_B,'lead.stage_changed','deal','9101','{\\\"lead_score\\\":95,\\\"subscriber_id\\\":9001}',NOW());" >/dev/null
cli automation_worker run >/dev/null 2>&1
check "tenant A's rules ignore tenant B's event" "$(sql "SELECT COUNT(*) FROM automation_runs")" "3"
check "tenant A's customer was not touched by it" "$(sql "SELECT lead_score FROM messenger_bot_subscriber WHERE id=9001")" "85"

login b@staging.local
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$T/b@staging.local" "$B/automations")
contains "member without module 409 is denied the page" "$loc" "access_forbidden"
sql "UPDATE package SET module_ids='342,409' WHERE id=90" >/dev/null
login b@staging.local
check "member WITH module 409 gets in" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/b@staging.local" "$B/automations")" "200"
r=$(curl -s -b "$T/b@staging.local" -H 'X-Requested-With: XMLHttpRequest' "$B/automations/run_detail/$RUN_A")
check "tenant B cannot read tenant A's run detail" "$(echo "$r" | jget status)" "'0'"
check "tenant B sees none of tenant A's rules" "$(curl -s -b "$T/b@staging.local" "$B/automations" | grep -c 'Hot lead handling')" "0"
r=$(post b@staging.local automations/set_status --data-urlencode "csrf_token=$(tokof b@staging.local automations)" -d "id=$RULE_A" -d status=paused)
check "tenant B cannot pause tenant A's rule" "$(echo "$r" | jget status)" "'0'"
check "and tenant A's rule is still running" "$(sql "SELECT status FROM automation_rules WHERE id=$RULE_A")" "active"
sql "UPDATE package SET module_ids='342' WHERE id=90" >/dev/null

echo "== ops health =="
contains "the worker records its health" "$(docker exec "$APP" cat /var/www/html/application/cache/ops_health.json 2>/dev/null)" "automation_worker"

rm -rf "$T"
echo "== $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
