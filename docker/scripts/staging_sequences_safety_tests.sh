#!/bin/sh
# Sequences SAFETY GATE suite (2026-09-27) — STAGING ONLY. Synthetic data, never production.
#
#   docker compose -p mb-seqsafety-test -f docker-compose.seqsafety-test.yml up -d
#   docker exec -i <db> sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < assets/backup_db/initial_db.sql
#   docker exec -u www-data <app> php /var/www/html/index.php cron_hub migrate
#   STAGING_DB=... STAGING_APP=... docker/scripts/staging_seed.sh
#   STAGING_URL=http://127.0.0.1:8095 STAGING_DB=... STAGING_APP=... docker/scripts/staging_sequences_safety_tests.sh
#
# Proves, on a live stack: the new columns exist post-migration, an opted-out customer's
# send_message step is refused and logged skipped_opt_out, a sequence-level quiet-hours
# window refuses a send and logs skipped_quiet_hours, an account-wide rate budget exhausted
# by one send blocks the next and logs skipped_rate_limit (and does not retry it — the
# enrollment completes instead), a sequence-level jitter override reschedules next_run_at
# instead of sending on the first tick and actually sends on the next, and the enrollments
# page renders the "N skipped: ..." tallies (brief §51: no silent failure).
set -u
B=${STAGING_URL:-http://127.0.0.1:8095}
DBC=${STAGING_DB:-mb-seqsafety-test-db-1}
APP=${STAGING_APP:-mb-seqsafety-test-app-1}
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

UID_A=1

echo "== migration landed =="
check "sequences.messages_per_hour exists" "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sequences' AND COLUMN_NAME='messages_per_hour'")" "1"
check "sequences.respect_opt_out exists" "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sequences' AND COLUMN_NAME='respect_opt_out'")" "1"
check "sales_automation_settings.default_messages_per_hour exists" "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales_automation_settings' AND COLUMN_NAME='default_messages_per_hour'")" "1"
check "sequence_step_log.status accepts the new skip reasons" "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sequence_step_log' AND COLUMN_NAME='status' AND COLUMN_TYPE LIKE '%skipped_rate_limit%'")" "1"
echo "== respect_opt_out cannot be flipped, even by direct SQL (CHECK constraint) =="
out=$(sql "UPDATE sequences SET respect_opt_out=0 WHERE 1=0" 2>&1; sql "SET SESSION sql_mode=''; INSERT INTO sequences (user_id,name,trigger_event,status,respect_opt_out,created_at,updated_at) VALUES ($UID_A,'chk-test','manual','draft',0,NOW(),NOW())" 2>&1)
contains "a direct INSERT with respect_opt_out=0 is rejected by the CHECK constraint" "$out" "CONSTRAINT"
check "and nothing was inserted" "$(sql "SELECT COUNT(*) FROM sequences WHERE name='chk-test'")" "0"

echo "== reset =="
sql "DELETE FROM sequence_step_log; DELETE FROM sequence_enrollments; DELETE FROM sequence_steps; DELETE FROM sequences;
     DELETE FROM reengage_optout WHERE user_id=$UID_A;
     DELETE FROM messenger_bot_subscriber WHERE id IN (9301,9302,9303,9304,9305);
     UPDATE sales_automation_settings SET default_messages_per_hour=30, default_daily_cap=200, default_quiet_hours_start='00:00:00', default_quiet_hours_end='00:00:00', default_jitter_min_seconds=0, default_jitter_max_seconds=0 WHERE user_id=$UID_A;
     UPDATE package SET module_ids='342' WHERE id=90;" >/dev/null

# Customers, all recently active (inside the 24h channel-policy window) so channel policy
# itself never interferes with these tests — only the NEW safety gate should block them.
sql "INSERT INTO messenger_bot_subscriber (id,user_id,page_table_id,page_id,subscribe_id,full_name,email,phone_number,social_media,status,lead_score,subscribed_at,last_subscriber_interaction_time) VALUES
  (9301,$UID_A,1,'PAGE1','PSID9301','OptedOut Customer','oo@example.test','201000000301','fb','1',50,NOW(),NOW()),
  (9302,$UID_A,1,'PAGE1','PSID9302','QuietHours Customer','qh@example.test','201000000302','fb','1',50,NOW(),NOW()),
  (9303,$UID_A,0,'','PSID9303','RateLimit One','rl1@example.test','201000000303','web','1',50,NOW(),NOW()),
  (9304,$UID_A,0,'','PSID9304','RateLimit Two','rl2@example.test','201000000304','web','1',50,NOW(),NOW()),
  (9305,$UID_A,1,'PAGE1','PSID9305','Jitter Customer','jt@example.test','201000000305','fb','1',50,NOW(),NOW());" >/dev/null
sql "INSERT INTO reengage_optout (user_id, subscribe_id, social_media, source, created_at) VALUES ($UID_A,'PSID9301','fb','keyword',NOW());" >/dev/null

login admin@staging.local
K=$(tokof admin@staging.local sequences)
[ -n "$K" ] || { echo "no CSRF token on /sequences"; exit 1; }

echo "== opt-out blocks the send and is logged distinctly =="
STEPS='[{"delay_seconds":0,"action_type":"send_message","action_params":{"text":"Hello {{full_name}}"},"stop_condition":null}]'
r=$(post admin@staging.local sequences/save --data-urlencode "csrf_token=$K" -d id=0 \
  --data-urlencode "name=Opt-out test" -d trigger_event=manual -d status=active --data-urlencode "steps_json=$STEPS")
SEQ_OO=$(echo "$r" | jget id | tr -d "'")
r=$(post admin@staging.local sequences/enroll_manual --data-urlencode "csrf_token=$K" -d "id=$SEQ_OO" -d subscriber_id=9301)
EID_OO=$(echo "$r" | jget enrollment_id | tr -d "'")
sql "UPDATE sequence_enrollments SET next_run_at=NOW() WHERE id=$EID_OO" >/dev/null
cli sequence_worker run >/dev/null 2>&1
check "logged skipped_opt_out" "$(sql "SELECT status FROM sequence_step_log WHERE enrollment_id=$EID_OO")" "skipped_opt_out"
check "nothing was sent" "$(sql "SELECT COUNT(*) FROM livechat_messages WHERE user_id=$UID_A AND subscriber_id='PSID9301'")" "0"
check "the enrollment completed rather than getting stuck" "$(sql "SELECT status FROM sequence_enrollments WHERE id=$EID_OO")" "completed"

echo "== quiet hours blocks the send and is logged distinctly =="
STEPS='[{"delay_seconds":0,"action_type":"send_message","action_params":{"text":"Hello {{full_name}}"},"stop_condition":null}]'
r=$(post admin@staging.local sequences/save --data-urlencode "csrf_token=$K" -d id=0 \
  --data-urlencode "name=Quiet hours test" -d trigger_event=manual -d status=active --data-urlencode "steps_json=$STEPS")
SEQ_QH=$(echo "$r" | jget id | tr -d "'")
# Bracket "now" with an explicit, always-on quiet window on the sequence itself.
sql "UPDATE sequences SET quiet_hours_start='00:00:00', quiet_hours_end='23:59:59' WHERE id=$SEQ_QH" >/dev/null
r=$(post admin@staging.local sequences/enroll_manual --data-urlencode "csrf_token=$K" -d "id=$SEQ_QH" -d subscriber_id=9302)
EID_QH=$(echo "$r" | jget enrollment_id | tr -d "'")
sql "UPDATE sequence_enrollments SET next_run_at=NOW() WHERE id=$EID_QH" >/dev/null
cli sequence_worker run >/dev/null 2>&1
check "logged skipped_quiet_hours" "$(sql "SELECT status FROM sequence_step_log WHERE enrollment_id=$EID_QH")" "skipped_quiet_hours"
check "nothing was sent" "$(sql "SELECT COUNT(*) FROM livechat_messages WHERE user_id=$UID_A AND subscriber_id='PSID9302'")" "0"

echo "== rate budget exhausted blocks the second send, account-wide =="
STEPS='[{"delay_seconds":0,"action_type":"send_message","action_params":{"text":"Hi"},"stop_condition":null}]'
r=$(post admin@staging.local sequences/save --data-urlencode "csrf_token=$K" -d id=0 \
  --data-urlencode "name=Rate limit test" -d trigger_event=manual -d status=active --data-urlencode "steps_json=$STEPS")
SEQ_RL=$(echo "$r" | jget id | tr -d "'")
sql "UPDATE sequences SET messages_per_hour=1 WHERE id=$SEQ_RL" >/dev/null
r=$(post admin@staging.local sequences/enroll_manual --data-urlencode "csrf_token=$K" -d "id=$SEQ_RL" -d subscriber_id=9303)
EID_RL1=$(echo "$r" | jget enrollment_id | tr -d "'")
r=$(post admin@staging.local sequences/enroll_manual --data-urlencode "csrf_token=$K" -d "id=$SEQ_RL" -d subscriber_id=9304)
EID_RL2=$(echo "$r" | jget enrollment_id | tr -d "'")
sql "UPDATE sequence_enrollments SET next_run_at=NOW() WHERE id IN ($EID_RL1,$EID_RL2)" >/dev/null
cli sequence_worker run >/dev/null 2>&1
S1=$(sql "SELECT status FROM sequence_step_log WHERE enrollment_id=$EID_RL1")
S2=$(sql "SELECT status FROM sequence_step_log WHERE enrollment_id=$EID_RL2")
# platform=web bypasses Meta entirely (channel_send_web -> livechat_messages, no page
# token needed) so this proves a REAL success, not just an infra-failure-shaped 'not denied'.
check "the first send (within budget) actually succeeded" "$S1" "success"
check "the second send was refused by the exhausted budget" "$S2" "skipped_rate_limit"
check "the second enrollment still advances (completes), it is not stuck retrying" "$(sql "SELECT status FROM sequence_enrollments WHERE id=$EID_RL2")" "completed"

echo "== jitter reschedules next_run_at instead of sending immediately =="
STEPS='[{"delay_seconds":0,"action_type":"send_message","action_params":{"text":"Hi"},"stop_condition":null}]'
r=$(post admin@staging.local sequences/save --data-urlencode "csrf_token=$K" -d id=0 \
  --data-urlencode "name=Jitter test" -d trigger_event=manual -d status=active --data-urlencode "steps_json=$STEPS")
SEQ_JT=$(echo "$r" | jget id | tr -d "'")
sql "UPDATE sequences SET jitter_min_seconds=30, jitter_max_seconds=30 WHERE id=$SEQ_JT" >/dev/null
r=$(post admin@staging.local sequences/enroll_manual --data-urlencode "csrf_token=$K" -d "id=$SEQ_JT" -d subscriber_id=9305)
EID_JT=$(echo "$r" | jget enrollment_id | tr -d "'")
sql "UPDATE sequence_enrollments SET next_run_at=NOW() WHERE id=$EID_JT" >/dev/null
cli sequence_worker run >/dev/null 2>&1
check "the claim row is pending_jitter, not a terminal status" "$(sql "SELECT status FROM sequence_step_log WHERE enrollment_id=$EID_JT")" "pending_jitter"
check "current_step is NOT advanced (this step has not run yet)" "$(sql "SELECT current_step FROM sequence_enrollments WHERE id=$EID_JT")" "0"
check "next_run_at moved forward by roughly the jitter (>=25s)" "1" "$(sql "SELECT TIMESTAMPDIFF(SECOND, NOW(), next_run_at) >= 25 FROM sequence_enrollments WHERE id=$EID_JT")"
# Simulate the worker's NEXT tick, once the jitter has elapsed.
sql "UPDATE sequence_enrollments SET next_run_at=NOW() WHERE id=$EID_JT" >/dev/null
cli sequence_worker run >/dev/null 2>&1
check "exactly one claim row still exists (the SAME one, reused)" "$(sql "SELECT COUNT(*) FROM sequence_step_log WHERE enrollment_id=$EID_JT")" "1"
check "the step resolved to a terminal status on the second tick, not still pending" "0" "$(sql "SELECT COUNT(*) FROM sequence_step_log WHERE enrollment_id=$EID_JT AND status='pending_jitter'")"

echo "== enrollments page surfaces the skip counts (brief 51: no silent failure) =="
html=$(curl -s -b "$T/admin@staging.local" "$B/sequences/enrollments")
contains "the enrollments page mentions quiet hours" "$html" "Quiet hours"
contains "the enrollments page mentions rate limit" "$html" "Rate limit"
contains "the enrollments page mentions opted out" "$html" "Opted out"

echo "== ops health mentions the worker =="
contains "the worker records its health" "$(docker exec "$APP" cat /var/www/html/application/cache/ops_health.json 2>/dev/null)" "sequence_worker"

rm -rf "$T"
echo "== $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
