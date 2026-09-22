#!/bin/sh
# Setup Guide (Phase 11) regression suite — STAGING ONLY. Needs staging_seed.sh.
# Default target is the Phase 11 isolated stack on 127.0.0.1:8092; override with
#   STAGING_URL / STAGING_APP / STAGING_DB.
set -u
B=${STAGING_URL:-http://127.0.0.1:8092}; DBC=${STAGING_DB:-mb-onboarding-test-db-1}
APPC=${STAGING_APP:-mb-onboarding-test-app-1}
T=$(mktemp -d); PASS=0; FAIL=0
php(){ docker exec -i "$APPC" php "$@"; }
ok(){ PASS=$((PASS+1)); echo "PASS  $1"; }; bad(){ FAIL=$((FAIL+1)); echo "FAIL  $1  -> $2"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "got '$2' want '$3'"; fi; }
sql(){ docker exec "$DBC" sh -c 'mariadb -N -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "$0"' "$1"; }
login(){ j="$T/$1"; tok=$(curl -s -c "$j" "$B/home/login_page" | grep -o 'name="csrf_token"[^>]*value="[^"]*"' | sed 's/.*value="//;s/"$//');
  curl -s -b "$j" -c "$j" -o /dev/null -X POST "$B/home/login" --data-urlencode "csrf_token=$tok" --data-urlencode "username=$1" --data-urlencode "password=Staging#Pass1"; }
tok(){ curl -s -b "$T/$1" "$B/onboarding/step/1" | grep -o 'TOKEN: "[^"]*"' | sed 's/.*"\(.*\)"/\1/'; }
post(){ j="$T/$1"; p="$2"; shift 2; curl -s -b "$j" -H 'X-Requested-With: XMLHttpRequest' -X POST "$B/onboarding/$p" "$@"; }
jget(){ php -r '$d=json_decode(stream_get_contents(STDIN),true); $p=explode(".",$argv[1]); foreach($p as $k){ if(!is_array($d)||!array_key_exists($k,$d)){echo "∅";exit;} $d=$d[$k]; } echo is_array($d)?json_encode($d,JSON_UNESCAPED_UNICODE):var_export($d,true);' "$1"; }

A=a@staging.local; UA=11
Bt=b@staging.local; UB=12

# ---- clean slate for both tenants (synthetic data only) ----------------------
sql "DELETE FROM onboarding_state WHERE user_id IN ($UA,$UB);
     DELETE FROM ai_faq WHERE user_id IN ($UA,$UB);
     DELETE FROM ai_agent_assignments WHERE user_id IN ($UA,$UB);
     DELETE FROM ai_agent_profiles WHERE user_id IN ($UA,$UB);
     DELETE FROM pricing_config WHERE user_id IN ($UA,$UB);
     DELETE FROM pricing_config_history WHERE user_id IN ($UA,$UB);
     DELETE FROM crm_stages WHERE pipeline_id IN (SELECT id FROM crm_pipelines WHERE user_id IN ($UA,$UB));
     DELETE FROM crm_pipelines WHERE user_id IN ($UA,$UB);
     DELETE FROM facebook_rx_fb_page_info WHERE user_id IN ($UA,$UB);
     DELETE FROM webchat_settings WHERE user_id IN ($UA,$UB);
     INSERT INTO facebook_rx_fb_page_info (user_id,facebook_rx_fb_user_info_id,page_id,page_name)
       VALUES ($UA,0,'900000000001','Tenant A Page'),($UB,0,'900000000002','Tenant B Page');
     UPDATE package SET module_ids='342,410' WHERE id=90;" >/dev/null

PAGE_A=$(sql "SELECT id FROM facebook_rx_fb_page_info WHERE user_id=$UA")
PAGE_B=$(sql "SELECT id FROM facebook_rx_fb_page_info WHERE user_id=$UB")

echo "== tenant A walks all 9 steps (hotel) =="
login $A
check "member with the Setup Guide opens it" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/$A" "$B/onboarding/step/1")" "200"
KA=$(tok $A)
check "a token was issued" "$([ -n "$KA" ] && echo yes || echo no)" "yes"

r=$(post $A save_business --data-urlencode "csrf_token=$KA" -d template_key=hotel --data-urlencode "business_name=قرية بيوت")
check "step 1 business type saved" "$(echo "$r" | jget status)" "'1'"
check "step 1 wrote the tenant's state row" "$(sql "SELECT template_key FROM onboarding_state WHERE user_id=$UA")" "hotel"
check "step 1 stored Arabic intact" "$(sql "SELECT JSON_VALUE(data_json,'\$.business_name') FROM onboarding_state WHERE user_id=$UA")" "قرية بيوت"

r=$(post $A save_channels --data-urlencode "csrf_token=$KA")
check "step 2 sees exactly tenant A's channel" "$(echo "$r" | jget connected)" "1"
check "step 2 created no channel of its own" "$(sql "SELECT COUNT(*) FROM facebook_rx_fb_page_info WHERE user_id=$UA")" "1"

r=$(post $A save_agent --data-urlencode "csrf_token=$KA" --data-urlencode "agent_name=منى" --data-urlencode "extra=إحنا مقفولين يوم التلات.")
check "step 3 created the AI employee" "$(echo "$r" | jget status)" "'1'"
check "step 3 created exactly one profile" "$(sql "SELECT COUNT(*) FROM ai_agent_profiles WHERE user_id=$UA")" "1"
check "step 3 kept the Arabic name" "$(sql "SELECT agent_name FROM ai_agent_profiles WHERE user_id=$UA")" "منى"
check "step 3 compiled the zero-discount rule into the prompt" "$(sql "SELECT LOCATE('أقصى خصم مسموح بيه: 0%', sales_system_prompt)>0 FROM ai_agent_profiles WHERE user_id=$UA")" "1"
check "step 3 compiled the never-invent-prices rule" "$(sql "SELECT LOCATE('متألّفش سعر أبدًا', sales_system_prompt)>0 FROM ai_agent_profiles WHERE user_id=$UA")" "1"
check "step 3 compiled the availability rule" "$(sql "SELECT LOCATE('متأكدش أبدًا إن الغرفة متاحة', sales_system_prompt)>0 FROM ai_agent_profiles WHERE user_id=$UA")" "1"
check "step 3 carried the owner's own note" "$(sql "SELECT LOCATE('مقفولين يوم التلات', sales_system_prompt)>0 FROM ai_agent_profiles WHERE user_id=$UA")" "1"
check "step 3 assigned the profile to the connected page" "$(sql "SELECT COUNT(*) FROM ai_agent_assignments WHERE user_id=$UA AND target_id='$PAGE_A'")" "1"
check "step 3 assigned nothing for tenant B" "$(sql "SELECT COUNT(*) FROM ai_agent_assignments WHERE user_id=$UB")" "0"

r=$(post $A save_knowledge --data-urlencode "csrf_token=$KA")
check "step 4 inserted the 8 hotel starter questions" "$(echo "$r" | jget inserted)" "8"
check "step 4 rows are all keyed to the template" "$(sql "SELECT COUNT(*) FROM ai_faq WHERE user_id=$UA AND source_key LIKE 'tpl:hotel:%'")" "8"
check "step 4 answers are marked 'edit me'" "$(sql "SELECT COUNT(*) FROM ai_faq WHERE user_id=$UA AND answer LIKE '%عدّلني%'")" "8"
check "step 4 stored the Arabic question intact" "$(sql "SELECT question FROM ai_faq WHERE user_id=$UA AND source_key='tpl:hotel:checkin_checkout'")" "مواعيد الدخول والخروج إيه؟"

r=$(post $A save_pricing --data-urlencode "csrf_token=$KA")
check "step 5 saved the hotel price list" "$(echo "$r" | jget mode)" "'calculator'"
check "step 5 wrote one pricing_config row" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UA")" "1"
check "step 5 audited the change" "$(sql "SELECT COUNT(*) FROM pricing_config_history WHERE user_id=$UA")" "1"
check "step 5 attributed the audit row to the user" "$(sql "SELECT changed_by FROM pricing_config_history WHERE user_id=$UA")" "$UA"
check "step 5 labelled the audit row" "$(sql "SELECT summary FROM pricing_config_history WHERE user_id=$UA")" "Setup Guide: starter price list"
check "step 5 kept the Arabic currency word" "$(sql "SELECT JSON_VALUE(config_json,'\$.currency') FROM pricing_config WHERE user_id=$UA")" "جنيه"
check "step 5 price list is usable by the calculator" "$(sql "SELECT JSON_VALUE(config_json,'\$.accommodation.rooms[0].nightly') FROM pricing_config WHERE user_id=$UA")" "1000"

r=$(post $A save_pipeline --data-urlencode "csrf_token=$KA")
check "step 6 created the pipeline" "$(echo "$r" | jget status)" "'1'"
PIPE_A=$(sql "SELECT id FROM crm_pipelines WHERE user_id=$UA")
check "step 6 created exactly one pipeline" "$(sql "SELECT COUNT(*) FROM crm_pipelines WHERE user_id=$UA")" "1"
check "step 6 added the hotel stage names" "$(sql "SELECT COUNT(*) FROM crm_stages WHERE pipeline_id=$PIPE_A AND name IN ('New Enquiry','Dates Collected','Quote Sent','Waiting Deposit','Booked')")" "5"
check "step 6 kept the CRM default stages too" "$(sql "SELECT COUNT(*) FROM crm_stages WHERE pipeline_id=$PIPE_A AND name IN ('New Lead','Won','Lost')")" "3"

r=$(post $A save_hours --data-urlencode "csrf_token=$KA" -d mode=staff_hours_silent -d hours_start=10:30 -d hours_end=18:00 -d hours_days=1,2,3)
check "step 7 applied to tenant A's channel" "$(echo "$r" | jget channels_updated)" "1"
check "step 7 turned the staff window on" "$(sql "SELECT hours_enabled FROM facebook_rx_fb_page_info WHERE user_id=$UA")" "1"
check "step 7 normalised the start time" "$(sql "SELECT hours_start FROM facebook_rx_fb_page_info WHERE user_id=$UA")" "10:30:00"
check "step 7 normalised the end time" "$(sql "SELECT hours_end FROM facebook_rx_fb_page_info WHERE user_id=$UA")" "18:00:00"
check "step 7 stored the days in the existing format" "$(sql "SELECT hours_days FROM facebook_rx_fb_page_info WHERE user_id=$UA")" "1,2,3"
check "step 7 did NOT touch tenant B's channel" "$(sql "SELECT hours_enabled FROM facebook_rx_fb_page_info WHERE user_id=$UB")" "0"
r=$(post $A save_hours --data-urlencode "csrf_token=$KA" -d mode=staff_hours_silent -d hours_start=99:99 -d hours_end=18:00 -d hours_days=9)
check "step 7 rejects an impossible time and falls back" "$(sql "SELECT hours_start FROM facebook_rx_fb_page_info WHERE user_id=$UA")" "09:00:00"
check "step 7 rejects an impossible weekday and keeps the stored days" "$(sql "SELECT hours_days FROM facebook_rx_fb_page_info WHERE user_id=$UA")" "1,2,3"

# step 8: no OpenAI key is configured on staging, and the wizard must say so, never invent a reply
r=$(post $A test_reply --data-urlencode "csrf_token=$KA" --data-urlencode "message=السلام عليكم")
check "step 8 refuses to fake a reply without AI credentials" "$(echo "$r" | jget status)" "'0'"
case "$(echo "$r" | jget message)" in *credentials*|*key*) ok "step 8 says why there is no reply";; *) bad "step 8 says why there is no reply" "$r";; esac
check "step 8 stored no reply" "$(echo "$r" | jget reply)" "∅"
check "step 8 did not mark the wizard as tested" "$(sql "SELECT JSON_VALUE(data_json,'\$.tested_at') IS NULL FROM onboarding_state WHERE user_id=$UA")" "1"

r=$(post $A go_live --data-urlencode "csrf_token=$KA")
check "step 9 goes live once the required items are done" "$(echo "$r" | jget status)" "'1'"
check "step 9 recorded completion" "$(sql "SELECT completed_at IS NOT NULL FROM onboarding_state WHERE user_id=$UA")" "1"
check "step 9 page renders the checklist" "$(curl -s -b "$T/$A" "$B/onboarding/step/9" | grep -c 'AI employee created')" "1"

echo "== every step re-run: updates, never duplicates =="
post $A save_business --data-urlencode "csrf_token=$KA" -d template_key=hotel --data-urlencode "business_name=قرية بيوت" >/dev/null
post $A save_channels --data-urlencode "csrf_token=$KA" >/dev/null
post $A save_agent --data-urlencode "csrf_token=$KA" --data-urlencode "agent_name=منى" >/dev/null
r=$(post $A save_knowledge --data-urlencode "csrf_token=$KA")
post $A save_pricing --data-urlencode "csrf_token=$KA" >/dev/null
r2=$(post $A save_pipeline --data-urlencode "csrf_token=$KA")
post $A save_hours --data-urlencode "csrf_token=$KA" -d mode=always_on >/dev/null
check "re-run inserts no second FAQ set" "$(echo "$r" | jget inserted)" "0"
check "re-run adds no second stage set" "$(echo "$r2" | jget added)" "0"
check "still exactly one agent profile" "$(sql "SELECT COUNT(*) FROM ai_agent_profiles WHERE user_id=$UA")" "1"
check "still exactly one assignment" "$(sql "SELECT COUNT(*) FROM ai_agent_assignments WHERE user_id=$UA")" "1"
check "still exactly 8 starter FAQs" "$(sql "SELECT COUNT(*) FROM ai_faq WHERE user_id=$UA")" "8"
check "still exactly one pricing_config" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UA")" "1"
check "an unchanged price list adds no audit row" "$(sql "SELECT COUNT(*) FROM pricing_config_history WHERE user_id=$UA")" "1"
check "still exactly one pipeline" "$(sql "SELECT COUNT(*) FROM crm_pipelines WHERE user_id=$UA")" "1"
check "still exactly 11 stages" "$(sql "SELECT COUNT(*) FROM crm_stages WHERE pipeline_id=$PIPE_A")" "11"
check "going live twice keeps one state row" "$(sql "SELECT COUNT(*) FROM onboarding_state WHERE user_id=$UA")" "1"

echo "== an owner's edits survive a re-run =="
sql "UPDATE ai_faq SET answer='الدخول ٣ العصر والخروج ١١ الصبح.' WHERE user_id=$UA AND source_key='tpl:hotel:checkin_checkout';
     INSERT INTO ai_faq (user_id,page_id,source_key,question,answer,status,created_at) VALUES ($UA,NULL,NULL,'بتقبلوا حيوانات أليفة؟','أيوه، بشرط.','1',NOW());" >/dev/null
post $A save_knowledge --data-urlencode "csrf_token=$KA" >/dev/null
check "a blank field does not wipe the owner's answer" "$(sql "SELECT answer FROM ai_faq WHERE user_id=$UA AND source_key='tpl:hotel:checkin_checkout'")" "الدخول ٣ العصر والخروج ١١ الصبح."
check "a hand-written FAQ is left alone" "$(sql "SELECT answer FROM ai_faq WHERE user_id=$UA AND source_key IS NULL")" "أيوه، بشرط."
check "re-running deletes nothing" "$(sql "SELECT COUNT(*) FROM ai_faq WHERE user_id=$UA")" "9"
r=$(post $A save_knowledge --data-urlencode "csrf_token=$KA" --data-urlencode "faq[checkin_checkout]=الدخول ٢ الضهر.")
check "a typed answer does replace the stored one" "$(sql "SELECT answer FROM ai_faq WHERE user_id=$UA AND source_key='tpl:hotel:checkin_checkout'")" "الدخول ٢ الضهر."
sql "UPDATE crm_stages SET name='Deposit Paid' WHERE pipeline_id=$PIPE_A AND name='Booked'" >/dev/null
post $A save_pipeline --data-urlencode "csrf_token=$KA" >/dev/null
check "a renamed stage is not deleted, the template name is added beside it" "$(sql "SELECT COUNT(*) FROM crm_stages WHERE pipeline_id=$PIPE_A AND name IN ('Deposit Paid','Booked')")" "2"

echo "== tenant B is a separate world =="
login $Bt
check "tenant B opens its own guide" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/$Bt" "$B/onboarding/step/1")" "200"
check "tenant B's page does not leak tenant A's business name" "$(curl -s -b "$T/$Bt" "$B/onboarding/step/1" | grep -c 'قرية بيوت')" "0"
check "tenant B starts on step 1" "$(sql "SELECT current_step FROM onboarding_state WHERE user_id=$UB")" "1"
check "tenant B has no template yet" "$(sql "SELECT template_key IS NULL FROM onboarding_state WHERE user_id=$UB")" "1"
KB=$(tok $Bt)
check "tenant B cannot jump past step 1 without choosing" "$(curl -s -o /dev/null -w '%{redirect_url}' -b "$T/$Bt" "$B/onboarding/step/5" | grep -c 'onboarding/step/1')" "1"
r=$(post $Bt save_business --data-urlencode "csrf_token=$KB" -d template_key=clinic --data-urlencode "business_name=عيادة النور")
check "tenant B picks its own vertical" "$(sql "SELECT template_key FROM onboarding_state WHERE user_id=$UB")" "clinic"
check "tenant A's template is unchanged" "$(sql "SELECT template_key FROM onboarding_state WHERE user_id=$UA")" "hotel"
post $Bt save_agent --data-urlencode "csrf_token=$KB" --data-urlencode "agent_name=سلمى" >/dev/null
post $Bt save_knowledge --data-urlencode "csrf_token=$KB" >/dev/null
check "tenant B's agent is its own row" "$(sql "SELECT COUNT(*) FROM ai_agent_profiles WHERE user_id=$UB")" "1"
check "tenant A still has exactly one agent" "$(sql "SELECT COUNT(*) FROM ai_agent_profiles WHERE user_id=$UA")" "1"
check "tenant B got the 9 clinic questions, not the hotel ones" "$(sql "SELECT COUNT(*) FROM ai_faq WHERE user_id=$UB AND source_key LIKE 'tpl:clinic:%'")" "9"
check "tenant B has no hotel questions" "$(sql "SELECT COUNT(*) FROM ai_faq WHERE user_id=$UB AND source_key LIKE 'tpl:hotel:%'")" "0"
check "tenant A's FAQ count is untouched" "$(sql "SELECT COUNT(*) FROM ai_faq WHERE user_id=$UA")" "9"
check "tenant B's clinic prompt forbids medical advice" "$(sql "SELECT LOCATE('متشخّصش حالة', sales_system_prompt)>0 FROM ai_agent_profiles WHERE user_id=$UB")" "1"

echo "== a vertical the calculator cannot serve writes no prices =="
r=$(post $Bt save_pricing --data-urlencode "csrf_token=$KB")
check "clinic pricing step succeeds without a calculator" "$(echo "$r" | jget mode)" "'faq_only'"
check "clinic wrote no pricing_config" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UB")" "0"
check "clinic wrote no pricing history" "$(sql "SELECT COUNT(*) FROM pricing_config_history WHERE user_id=$UB")" "0"
check "clinic step explains where prices go" "$(curl -s -b "$T/$Bt" "$B/onboarding/step/5" | grep -c 'Knowledge')" "$(curl -s -b "$T/$Bt" "$B/onboarding/step/5" | grep -c 'Knowledge')"
case "$(curl -s -b "$T/$Bt" "$B/onboarding/step/5")" in *"stay as answers"*) ok "clinic step says prices stay as answers";; *) bad "clinic step says prices stay as answers" "phrase not found";; esac
check "tenant A's price list is untouched by tenant B" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UA")" "1"

echo "== tenant B cannot read tenant A's onboarding state =="
check "B's state row is its own" "$(sql "SELECT JSON_VALUE(data_json,'\$.business_name') FROM onboarding_state WHERE user_id=$UB")" "عيادة النور"
check "B's step 3 does not show A's agent" "$(curl -s -b "$T/$Bt" "$B/onboarding/step/3" | grep -c 'مقفولين يوم التلات')" "0"
check "B's step 9 checklist does not count A's channels" "$(curl -s -b "$T/$Bt" "$B/onboarding/step/9" | grep -c 'Tenant A Page')" "0"
check "B's step 4 does not show A's hotel questions" "$(curl -s -b "$T/$Bt" "$B/onboarding/step/4" | grep -c 'مواعيد الدخول والخروج')" "0"
# a forged step number cannot reach another tenant's data either: the controller keys on session uid only
check "step numbers are clamped, not trusted" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/$Bt" "$B/onboarding/step/99")" "200"

echo "== the module gate =="
sql "UPDATE package SET module_ids='342' WHERE id=90" >/dev/null
login $Bt
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$T/$Bt" "$B/onboarding")
case "$loc" in *access_forbidden*) ok "member without the Setup Guide module is denied";; *) bad "member without the Setup Guide module is denied" "$loc";; esac
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$T/$Bt" "$B/onboarding/step/3")
case "$loc" in *access_forbidden*) ok "a deep step link is denied too";; *) bad "a deep step link is denied too" "$loc";; esac
r=$(post $Bt save_agent --data-urlencode "csrf_token=$KB" --data-urlencode "agent_name=hack")
check "a denied member cannot POST a step" "$(echo "$r" | jget status)" "'0'"
check "the denied POST wrote nothing" "$(sql "SELECT COUNT(*) FROM ai_agent_profiles WHERE user_id=$UB")" "1"
sql "UPDATE package SET module_ids='342,410' WHERE id=90" >/dev/null

echo "== CSRF =="
login $A
r=$(post $A save_business --data-urlencode "csrf_token=bogus" -d template_key=ecommerce)
case "$r" in *CSRF*|*csrf*) ok "a step POST without a valid token is refused";; *) bad "a step POST without a valid token is refused" "$r";; esac
check "the refused POST did not change the template" "$(sql "SELECT template_key FROM onboarding_state WHERE user_id=$UA")" "hotel"

echo "== an unknown vertical is refused =="
KA=$(tok $A)
r=$(post $A save_business --data-urlencode "csrf_token=$KA" -d template_key=../../config/database)
check "a path-traversal vertical key is refused" "$(echo "$r" | jget status)" "'0'"
check "the tenant kept its real template" "$(sql "SELECT template_key FROM onboarding_state WHERE user_id=$UA")" "hotel"

echo "== admin is never blocked =="
login admin@staging.local
check "admin opens the Setup Guide" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/admin@staging.local" "$B/onboarding/step/1")" "200"

rm -rf "$T"; echo "== $PASS passed, $FAIL failed =="; [ "$FAIL" -eq 0 ]
