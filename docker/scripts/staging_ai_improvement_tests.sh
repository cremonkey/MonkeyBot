#!/bin/sh
# AI Improvement Center — STAGING ONLY. Needs staging_seed.sh. Synthetic data.
set -u
B=${STAGING_URL:-http://127.0.0.1:8089}; DBC=${STAGING_DB:-monkeybot-staging-db-1}
T=$(mktemp -d); PASS=0; FAIL=0
ok(){ PASS=$((PASS+1)); echo "PASS  $1"; }; bad(){ FAIL=$((FAIL+1)); echo "FAIL  $1  -> $2"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "got '$2' want '$3'"; fi; }
sql(){ docker exec "$DBC" sh -c 'mariadb -N -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "$0"' "$1"; }
login(){ j="$T/$1"; tok=$(curl -s -c "$j" "$B/home/login_page" | grep -o 'name="csrf_token"[^>]*value="[^"]*"' | sed 's/.*value="//;s/"$//');
  curl -s -b "$j" -c "$j" -o /dev/null -X POST "$B/home/login" --data-urlencode "csrf_token=$tok" --data-urlencode "username=$1" --data-urlencode "password=Staging#Pass1"; }
sql "UPDATE package SET module_ids='342,407' WHERE id=90;
DELETE FROM ai_unanswered_questions WHERE user_id IN (11,12); DELETE FROM ai_faq WHERE user_id IN (11,12);
INSERT INTO ai_unanswered_questions (id,user_id,page_id,social_media,question,status,created_at) VALUES
 (9001,11,'5','fb','هل الأطفال تحت ٦ سنين مجاناً؟','new',NOW() - INTERVAL 3 DAY),
 (9002,11,'5','wa','الاطفال تحت 6 سنين مجانا؟؟','new',NOW() - INTERVAL 2 DAY),
 (9003,11,'5','ig','هل الاطفال تحت ٦ سنين مجانا','new',NOW() - INTERVAL 1 DAY),
 (9004,11,'5','fb','فين مكانكم؟','new',NOW()),
 (9005,12,'7','fb','الاطفال تحت 6 سنين مجانا','new',NOW());" >/dev/null
login a@staging.local
P=$(curl -s -b "$T/a@staging.local" "$B/ai_improvement")
check "page opens for a member with the module" "$(echo "$P" | grep -c "id=\"t_gaps\"")" "1"
check "3 phrasings grouped into one gap (asked 3×)" "$(echo "$P" | grep -c 'asked 3×')" "1"
check "gap carries exactly tenant A's ids" "$(echo "$P" | grep -o 'data-ids="[0-9,]*"' | head -1)" 'data-ids="9003,9002,9001"'
case "$P" in *9005*) bad "tenant B's question not shown to A" "leaked";; *) ok "tenant B's question not shown to A";; esac
K=$(echo "$P" | grep -o 'var TOKEN = "[^"]*"' | sed 's/.*"\(.*\)"/\1/')
r=$(curl -s -b "$T/a@staging.local" -H 'X-Requested-With: XMLHttpRequest' -X POST "$B/ai_improvement/answer_group" \
   --data-urlencode "csrf_token=$K" -d 'ids[]=9001' -d 'ids[]=9002' -d 'ids[]=9003' -d 'ids[]=9005' \
   --data-urlencode "answer=أيوه، الأطفال تحت 6 سنين مجاناً (حد أقصى طفلين)." --data-urlencode "question=هل الأطفال تحت ٦ سنين مجاناً؟")
case "$r" in *'"status":"1"'*) ok "one answer saved for the group";; *) bad "one answer saved for the group" "$r";; esac
check "all 3 of A's questions resolved" "$(sql "SELECT COUNT(*) FROM ai_unanswered_questions WHERE id IN (9001,9002,9003) AND status='resolved'")" "3"
check "tenant B's question untouched (id smuggled in)" "$(sql "SELECT status FROM ai_unanswered_questions WHERE id=9005")" "new"
check "one FAQ row for A with before=3 and author" "$(sql "SELECT CONCAT(COUNT(*),':',MAX(questions_before),':',MAX(created_by IS NOT NULL)) FROM ai_faq WHERE user_id=11")" "1:3:1"
check "FAQ scoped to the page the gap came from" "$(sql "SELECT page_id FROM ai_faq WHERE user_id=11")" "5"
check "Arabic answer stored intact" "$(sql "SELECT answer LIKE '%مجاناً%' FROM ai_faq WHERE user_id=11")" "1"
sql "INSERT INTO ai_unanswered_questions (id,user_id,page_id,social_media,question,status,created_at) VALUES (9006,11,'5','fb','الأطفال تحت ٦ سنين مجانا؟','new',NOW() + INTERVAL 1 MINUTE)" >/dev/null
P=$(curl -s -b "$T/a@staging.local" "$B/ai_improvement")
check "impact: 1 similar question still missed after the answer" "$(echo "$P" | grep -o 'badge badge-warning" title="The answer may not cover[^>]*>[0-9]*' | sed 's/.*>//')" "1"
login b@staging.local
sql "UPDATE package SET module_ids='342' WHERE id=90" >/dev/null
login b@staging.local
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$T/b@staging.local" "$B/ai_improvement")
case "$loc" in *access_forbidden*) ok "member without the module denied";; *) bad "member without the module denied" "$loc";; esac
sql "DELETE FROM ai_unanswered_questions WHERE id BETWEEN 9001 AND 9006; DELETE FROM ai_faq WHERE user_id IN (11,12)" >/dev/null
rm -rf "$T"; echo "== $PASS passed, $FAIL failed =="; [ "$FAIL" -eq 0 ]
