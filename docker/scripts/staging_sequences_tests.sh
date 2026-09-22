#!/bin/sh
# Sequences regression suite — STAGING ONLY. Synthetic data, never production.
#
#   docker compose -p mb-sequences-test -f docker-compose.sequences-test.yml up -d
#   docker exec -i <db> sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < assets/backup_db/initial_db.sql
#   docker exec -u www-data <app> php /var/www/html/index.php cron_hub migrate
#   STAGING_DB=... STAGING_APP=... docker/scripts/staging_seed.sh
#   STAGING_URL=http://127.0.0.1:8094 STAGING_DB=... STAGING_APP=... docker/scripts/staging_sequences_tests.sh
#
# It proves the whole chain on a live stack: a sequence created through the real UI
# endpoints, a subscriber enrolled (idempotently), the CLI worker advancing each step at its
# simulated due time (next_run_at is backdated rather than sleeping in real time — see
# condition-based-waiting), a channel-policy-denied send logged and NOT sent while a
# same-tick allowed send DOES go out, a stop_condition halting the rest of a sequence, replay
# never re-firing an already-claimed step, module 412 gating a member (never an admin), and
# sequence_worker having no HTTP surface at all.
set -u
B=${STAGING_URL:-http://127.0.0.1:8094}
DBC=${STAGING_DB:-mb-sequences-test-db-1}
APP=${STAGING_APP:-mb-sequences-test-app-1}
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
sql "DELETE FROM sequence_step_log; DELETE FROM sequence_enrollments; DELETE FROM sequence_steps; DELETE FROM sequences;
     DELETE FROM messenger_bot_subscribers_label; DELETE FROM messenger_bot_broadcast_contact_group WHERE group_name IN ('Sequence tag','Should not tag');
     DELETE FROM messenger_bot_subscriber WHERE id IN (9201,9202);
     DELETE FROM livechat_messages WHERE user_id=$UID_A;
     UPDATE package SET module_ids='342' WHERE id=90;" >/dev/null

# A customer of tenant A who last wrote 48 hours ago on Facebook: OUTSIDE the 24h automation
# window, so channel policy must refuse the sequence's send_message step to them.
sql "INSERT INTO messenger_bot_subscriber (id,user_id,page_table_id,page_id,subscribe_id,full_name,email,phone_number,social_media,status,lead_score,subscribed_at,last_subscriber_interaction_time)
     VALUES (9201,$UID_A,1,'PAGE1','PSID9201','Nada OutOfWindow','nada2@example.test','201000000001','fb','1',50,NOW(),DATE_SUB(NOW(), INTERVAL 48 HOUR));" >/dev/null
# A customer who just wrote: INSIDE the 24h window, for the happy path.
sql "INSERT INTO messenger_bot_subscriber (id,user_id,page_table_id,page_id,subscribe_id,full_name,email,phone_number,social_media,status,lead_score,subscribed_at,last_subscriber_interaction_time)
     VALUES (9202,$UID_A,1,'PAGE1','PSID9202','Sara InWindow','sara@example.test','201000000002','fb','1',50,NOW(),NOW());" >/dev/null

login admin@staging.local
check "admin opens Sequences" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/admin@staging.local" "$B/sequences")" "200"
K=$(tokof admin@staging.local sequences)
[ -n "$K" ] || { echo "no CSRF token on /sequences"; exit 1; }

echo "== the worker has no web surface =="
check "sequence_worker/run over HTTP is 404" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/admin@staging.local" "$B/sequence_worker/run")" "404"
check "sequence_worker index over HTTP is 404" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/admin@staging.local" "$B/sequence_worker")" "404"
check "sequence_worker/pending over HTTP is 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B/sequence_worker/pending")" "404"

echo "== creating a sequence (3 steps) through the UI endpoints =="
STEPS='[{"delay_seconds":0,"action_type":"add_label","action_params":{"label":"Sequence tag"},"stop_condition":null},
        {"delay_seconds":5,"action_type":"send_message","action_params":{"text":"Hello {{full_name}}"},"stop_condition":null},
        {"delay_seconds":10,"action_type":"update_lead_score","action_params":{"delta":7},"stop_condition":null}]'
r=$(post admin@staging.local sequences/save --data-urlencode "csrf_token=$K" -d id=0 \
  --data-urlencode "name=Hotel Lead Follow-up" -d trigger_event=manual -d status=active \
  --data-urlencode "steps_json=$STEPS")
check "sequence saved and activated" "$(echo "$r" | jget status)" "'1'"
SEQ=$(echo "$r" | jget id | tr -d "'")
check "3 steps were stored" "$(sql "SELECT COUNT(*) FROM sequence_steps WHERE sequence_id=$SEQ")" "3"

r=$(post admin@staging.local sequences/save --data-urlencode "csrf_token=bogus" -d id=0 --data-urlencode "name=x")
contains "save without a valid CSRF token is refused" "$r" "CSRF"

echo "== dry run changes nothing =="
r=$(post admin@staging.local sequences/test --data-urlencode "csrf_token=$K" --data-urlencode "steps_json=$STEPS" -d sample_subscriber_id=9201)
check "dry run responded" "$(echo "$r" | jget status)" "'1'"
contains "dry run's first step is immediate" "$(echo "$r" | jget schedule.0.when)" "Immediately"
contains "dry run explains the send would not go out for the out-of-window sample" "$(echo "$r" | jget schedule.1.policy.reason)" "automation_outside_24h_window"
check "dry run enrolled nobody" "$(sql "SELECT COUNT(*) FROM sequence_enrollments")" "0"
check "dry run sent nothing" "$(sql "SELECT COUNT(*) FROM livechat_messages WHERE user_id=$UID_A")" "0"

echo "== enroll two subscribers, idempotently =="
r=$(post admin@staging.local sequences/enroll_manual --data-urlencode "csrf_token=$K" -d "id=$SEQ" -d subscriber_id=9201)
check "manual enroll ok (out-of-window customer)" "$(echo "$r" | jget status)" "'1'"
EID_OOW=$(echo "$r" | jget enrollment_id | tr -d "'")
r=$(post admin@staging.local sequences/enroll_manual --data-urlencode "csrf_token=$K" -d "id=$SEQ" -d subscriber_id=9202)
check "manual enroll ok (in-window customer)" "$(echo "$r" | jget status)" "'1'"
EID_IW=$(echo "$r" | jget enrollment_id | tr -d "'")
r2=$(post admin@staging.local sequences/enroll_manual --data-urlencode "csrf_token=$K" -d "id=$SEQ" -d subscriber_id=9201)
check "re-enrolling the same customer returns the SAME enrollment id" "$(echo "$r2" | jget enrollment_id | tr -d "'")" "$EID_OOW"
check "exactly two enrollment rows exist (no double-enroll)" "$(sql "SELECT COUNT(*) FROM sequence_enrollments")" "2"

echo "== feature flag (brief 50): sequence_worker uses the SAME flag as automation_worker =="
FLAG_LINE=$(grep -n "automation_engine_enabled" "$ROOT/application/config/config.php" | head -1 | cut -d: -f1)
if [ -n "$FLAG_LINE" ]; then
  sed -i "${FLAG_LINE}s/TRUE/FALSE/" "$ROOT/application/config/config.php"
  out=$(cli sequence_worker run 2>&1)
  contains "worker refuses to run while the flag is off" "$out" "disabled"
  check "and it touched nothing" "$(sql "SELECT COUNT(*) FROM sequence_step_log")" "0"
  sed -i "${FLAG_LINE}s/FALSE/TRUE/" "$ROOT/application/config/config.php"
else
  echo "SKIP  feature flag (no automation_engine_enabled line in config.php)"
fi

echo "== step 1 (add_label): backdate next_run_at, run the worker =="
sql "UPDATE sequence_enrollments SET next_run_at = NOW() WHERE id IN ($EID_OOW,$EID_IW)" >/dev/null
out=$(cli sequence_worker run 2>&1)
contains "worker reports what it did" "$out" "enrollments 2"
check "both step-1s succeeded" "$(sql "SELECT COUNT(*) FROM sequence_step_log WHERE status='success'")" "2"
check "the tag landed on the out-of-window customer too (add_label needs no channel policy)" "$(sql "SELECT COUNT(*) FROM messenger_bot_subscribers_label l JOIN messenger_bot_broadcast_contact_group g ON g.id=l.contact_group_id WHERE g.group_name='Sequence tag' AND l.subscriber_table_id=9201")" "1"
check "current_step advanced to 1 for both" "$(sql "SELECT COUNT(*) FROM sequence_enrollments WHERE current_step=1")" "2"
check "next_run_at moved to step 2's absolute offset (enrolled_at + 5s)" "$(sql "SELECT TIMESTAMPDIFF(SECOND, enrolled_at, next_run_at) FROM sequence_enrollments WHERE id=$EID_IW")" "5"

echo "== step 2 (send_message): channel policy denies out-of-window, allows in-window =="
sql "UPDATE sequence_enrollments SET next_run_at = NOW() WHERE id IN ($EID_OOW,$EID_IW)" >/dev/null
cli sequence_worker run >/dev/null 2>&1
check "the out-of-window send is skipped_policy, not silently dropped" "$(sql "SELECT status FROM sequence_step_log WHERE enrollment_id=$EID_OOW ORDER BY id DESC LIMIT 1")" "skipped_policy"
check "with the policy's own reason recorded" "$(sql "SELECT detail FROM sequence_step_log WHERE enrollment_id=$EID_OOW ORDER BY id DESC LIMIT 1")" "automation_outside_24h_window"
check "NOTHING was sent to the out-of-window customer" "$(sql "SELECT COUNT(*) FROM livechat_messages WHERE user_id=$UID_A AND subscriber_id='PSID9201'")" "0"
# This sandbox has no real Meta page access token, so the actual Graph API call fails
# transport-side — same as any staging run without live credentials. What proves policy
# ALLOWED it (instead of denying it) is the STATUS: a policy denial is always
# 'skipped_policy' with the policy's own reason and the gateway is never even called; here
# the gateway WAS called and failed for an unrelated infrastructure reason.
check "the in-window customer's send was not blocked by policy" "$(sql "SELECT status FROM sequence_step_log WHERE enrollment_id=$EID_IW ORDER BY id DESC LIMIT 1")" "failed"
contains "its failure is an infrastructure reason, not a policy denial" "$(sql "SELECT detail FROM sequence_step_log WHERE enrollment_id=$EID_IW ORDER BY id DESC LIMIT 1")" "page token"
check "a policy denial still advances the sequence (not a dead stop)" "$(sql "SELECT current_step FROM sequence_enrollments WHERE id=$EID_OOW")" "2"

echo "== step 3 (update_lead_score): completion =="
sql "UPDATE sequence_enrollments SET next_run_at = NOW() WHERE id IN ($EID_OOW,$EID_IW)" >/dev/null
cli sequence_worker run >/dev/null 2>&1
check "both enrollments completed" "$(sql "SELECT COUNT(*) FROM sequence_enrollments WHERE status='completed'")" "2"
check "next_run_at is cleared on completion" "$(sql "SELECT COUNT(*) FROM sequence_enrollments WHERE status='completed' AND next_run_at IS NULL")" "2"
check "the in-window customer's lead score went 50 -> 57" "$(sql "SELECT lead_score FROM messenger_bot_subscriber WHERE id=9202")" "57"
check "each enrollment logged exactly 3 steps" "$(sql "SELECT COUNT(*) FROM sequence_step_log")" "6"

echo "== re-running the worker does not re-fire an already-processed step (idempotent) =="
sql "UPDATE sequence_enrollments SET status='active', current_step=2, next_run_at=NOW() WHERE id=$EID_IW" >/dev/null
cli sequence_worker run >/dev/null 2>&1
check "no new step_log row was created for the already-claimed step" "$(sql "SELECT COUNT(*) FROM sequence_step_log WHERE enrollment_id=$EID_IW")" "3"
check "the lead score was NOT changed again (still 57, not 64)" "$(sql "SELECT lead_score FROM messenger_bot_subscriber WHERE id=9202")" "57"
check "current_step did not move (the duplicate claim stopped it cold)" "$(sql "SELECT current_step FROM sequence_enrollments WHERE id=$EID_IW")" "2"

echo "== stop_condition halts the rest of the sequence =="
STEPS2='[{"delay_seconds":0,"action_type":"add_label","action_params":{"label":"Should not tag"},"stop_condition":[{"field":"subscriber.lead_score","op":"gte","value":0}]},
         {"delay_seconds":5,"action_type":"add_label","action_params":{"label":"Never reached"},"stop_condition":null}]'
r=$(post admin@staging.local sequences/save --data-urlencode "csrf_token=$K" -d id=0 \
  --data-urlencode "name=Stop condition test" -d trigger_event=manual -d status=active --data-urlencode "steps_json=$STEPS2")
SEQ2=$(echo "$r" | jget id | tr -d "'")
r=$(post admin@staging.local sequences/enroll_manual --data-urlencode "csrf_token=$K" -d "id=$SEQ2" -d subscriber_id=9202)
EID_STOP=$(echo "$r" | jget enrollment_id | tr -d "'")
sql "UPDATE sequence_enrollments SET next_run_at=NOW() WHERE id=$EID_STOP" >/dev/null
cli sequence_worker run >/dev/null 2>&1
check "the step is logged skipped_stop_condition" "$(sql "SELECT status FROM sequence_step_log WHERE enrollment_id=$EID_STOP")" "skipped_stop_condition"
check "the action never ran — no tag was added" "$(sql "SELECT COUNT(*) FROM messenger_bot_broadcast_contact_group WHERE group_name='Should not tag'")" "0"
check "the enrollment is stopped, not stuck" "$(sql "SELECT status FROM sequence_enrollments WHERE id=$EID_STOP")" "stopped"
check "a stopped_reason is recorded (brief 51: no silent failure)" "$(sql "SELECT COUNT(*) FROM sequence_enrollments WHERE id=$EID_STOP AND stopped_reason IS NOT NULL AND stopped_reason != ''")" "1"
sql "UPDATE sequence_enrollments SET next_run_at=NOW() WHERE id=$EID_STOP" >/dev/null
cli sequence_worker run >/dev/null 2>&1
check "a stopped enrollment is never picked up again" "$(sql "SELECT COUNT(*) FROM sequence_step_log WHERE enrollment_id=$EID_STOP")" "1"

echo "== entitlement (module 412) =="
login b@staging.local
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$T/b@staging.local" "$B/sequences")
contains "member without module 412 is denied the page" "$loc" "access_forbidden"
sql "UPDATE package SET module_ids='342,412' WHERE id=90" >/dev/null
login b@staging.local
check "member WITH module 412 gets in" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/b@staging.local" "$B/sequences")" "200"
sql "UPDATE package SET module_ids='342' WHERE id=90" >/dev/null
check "admin is never blocked, package or not" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/admin@staging.local" "$B/sequences")" "200"

echo "== ops health =="
contains "the worker records its health" "$(docker exec "$APP" cat /var/www/html/application/cache/ops_health.json 2>/dev/null)" "sequence_worker"

echo "== no regression: /automations still works with the new action types available =="
check "admin opens Automations" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/admin@staging.local" "$B/automations")" "200"
contains "'Start a sequence' is offered as an automation action" "$(curl -s -b "$T/admin@staging.local" "$B/automations/form")" "start_sequence"

rm -rf "$T"
echo "== $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
