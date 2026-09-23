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
       VALUES ($UA,0,'900000000001','Tenant A Page'),($UB,0,'900000000002','Tenant B Page'),
              ($UA,0,'900000000003','Tenant A Other Business');
     UPDATE package SET module_ids='342,410,408' WHERE id=90;" >/dev/null

PAGE_A=$(sql "SELECT id FROM facebook_rx_fb_page_info WHERE user_id=$UA AND page_id='900000000001'")
PAGE_A2=$(sql "SELECT id FROM facebook_rx_fb_page_info WHERE user_id=$UA AND page_id='900000000003'")
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

S2=$(curl -s -b "$T/$A" "$B/onboarding/step/2")
check "step 2 lists both of tenant A's pages" "$(echo "$S2" | grep -c -e 'Tenant A Page' -e 'Tenant A Other Business')" "2"
check "every listed channel has a checkbox" "$(echo "$S2" | grep -c 'class="ob-target"')" "$(echo "$S2" | grep -c 'fa-cog')"
check "step 2 pre-ticks nothing" "$(curl -s -b "$T/$A" "$B/onboarding/step/2" | grep 'class="ob-target"' | grep -c checked)" "0"
check "step 2 offers a Manage link per channel" "$([ "$(echo "$S2" | grep -c 'fa-cog')" -ge 2 ] && echo yes)" "yes"
check "step 2 does not list tenant B's page" "$(curl -s -b "$T/$A" "$B/onboarding/step/2" | grep -c 'Tenant B Page')" "0"
check "step 3 is locked until pages are chosen" "$(curl -s -o /dev/null -w '%{redirect_url}' -b "$T/$A" "$B/onboarding/step/3" | grep -c 'onboarding/step/2')" "1"
r=$(post $A save_agent --data-urlencode "csrf_token=$KA" --data-urlencode "agent_name=x")
check "saving the agent before choosing pages is refused" "$(echo "$r" | jget status)" "'0'"
r=$(post $A save_channels --data-urlencode "csrf_token=$KA")
check "step 2 refuses an empty choice" "$(echo "$r" | jget status)" "'0'"
r=$(post $A save_channels --data-urlencode "csrf_token=$KA" --data-urlencode "targets[]=fb:$PAGE_B")
check "step 2 refuses another tenant's page" "$(echo "$r" | jget status)" "'0'"
r=$(post $A save_channels --data-urlencode "csrf_token=$KA" --data-urlencode "targets[]=fb:$PAGE_A" --data-urlencode "targets[]=fb:$PAGE_B")
check "step 2 keeps only the tenant's own page from a mixed list" "$(echo "$r" | jget selected)" "1"
check "step 2 stored exactly the chosen page" "$(sql "SELECT JSON_EXTRACT(data_json,'\$.targets') FROM onboarding_state WHERE user_id=$UA")" "[\"fb:$PAGE_A\"]"
check "step 2 created no channel of its own" "$(sql "SELECT COUNT(*) FROM facebook_rx_fb_page_info WHERE user_id=$UA")" "2"

r=$(post $A save_agent --data-urlencode "csrf_token=$KA" --data-urlencode "agent_name=منى" --data-urlencode "extra=إحنا مقفولين يوم التلات.")
check "step 3 created the AI employee" "$(echo "$r" | jget status)" "'1'"
check "step 3 created exactly one profile" "$(sql "SELECT COUNT(*) FROM ai_agent_profiles WHERE user_id=$UA")" "1"
check "step 3 kept the Arabic name" "$(sql "SELECT agent_name FROM ai_agent_profiles WHERE user_id=$UA")" "منى"
check "step 3 compiled the zero-discount rule into the prompt" "$(sql "SELECT LOCATE('أقصى خصم مسموح بيه: 0%', sales_system_prompt)>0 FROM ai_agent_profiles WHERE user_id=$UA")" "1"
check "step 3 compiled the never-invent-prices rule" "$(sql "SELECT LOCATE('متألّفش سعر أبدًا', sales_system_prompt)>0 FROM ai_agent_profiles WHERE user_id=$UA")" "1"
check "step 3 compiled the availability rule" "$(sql "SELECT LOCATE('متأكدش أبدًا إن الغرفة متاحة', sales_system_prompt)>0 FROM ai_agent_profiles WHERE user_id=$UA")" "1"
check "step 3 carried the owner's own note" "$(sql "SELECT LOCATE('مقفولين يوم التلات', sales_system_prompt)>0 FROM ai_agent_profiles WHERE user_id=$UA")" "1"
check "step 3 assigned the profile to the connected page" "$(sql "SELECT COUNT(*) FROM ai_agent_assignments WHERE user_id=$UA AND target_id='$PAGE_A'")" "1"
check "step 3 left the other business's page alone" "$(sql "SELECT COUNT(*) FROM ai_agent_assignments WHERE user_id=$UA AND target_id='$PAGE_A2'")" "0"
check "step 3 assigned nothing for tenant B" "$(sql "SELECT COUNT(*) FROM ai_agent_assignments WHERE user_id=$UB")" "0"

r=$(post $A save_knowledge --data-urlencode "csrf_token=$KA")
check "step 4 inserted the 8 hotel starter questions" "$(echo "$r" | jget inserted)" "8"
check "step 4 rows are all keyed to the template" "$(sql "SELECT COUNT(*) FROM ai_faq WHERE user_id=$UA AND source_key LIKE 'tpl:hotel:%'")" "8"
check "step 4 rows belong to the chosen page only" "$(sql "SELECT COUNT(*) FROM ai_faq WHERE user_id=$UA AND page_id='900000000001'")" "8"
check "step 4 wrote nothing account-wide" "$(sql "SELECT COUNT(*) FROM ai_faq WHERE user_id=$UA AND (page_id IS NULL OR page_id='')")" "0"
check "step 4 wrote nothing for the other business's page" "$(sql "SELECT COUNT(*) FROM ai_faq WHERE user_id=$UA AND page_id='900000000003'")" "0"
check "step 4 answers are marked 'edit me'" "$(sql "SELECT COUNT(*) FROM ai_faq WHERE user_id=$UA AND answer LIKE '%عدّلني%'")" "8"
check "step 4 placeholders are switched off" "$(sql "SELECT COUNT(*) FROM ai_faq WHERE user_id=$UA AND status='1'")" "0"
check "step 4 stored the Arabic question intact" "$(sql "SELECT question FROM ai_faq WHERE user_id=$UA AND source_key LIKE 'tpl:hotel:checkin_checkout@%'")" "مواعيد الدخول والخروج إيه؟"
r=$(post $A save_knowledge --data-urlencode "csrf_token=$KA" --data-urlencode "faq[checkin_checkout]=الدخول ٣ العصر.")
check "a typed answer goes live" "$(sql "SELECT status FROM ai_faq WHERE user_id=$UA AND source_key LIKE 'tpl:hotel:checkin_checkout@%'")" "1"
check "only the typed answer is live" "$(sql "SELECT COUNT(*) FROM ai_faq WHERE user_id=$UA AND status='1'")" "1"

r=$(post $A save_pricing --data-urlencode "csrf_token=$KA")
check "step 5 saved the hotel price list" "$(echo "$r" | jget mode)" "'calculator'"
check "step 5 wrote one pricing_config row" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UA")" "1"
check "step 5 audited the change" "$(sql "SELECT COUNT(*) FROM pricing_config_history WHERE user_id=$UA")" "1"
check "step 5 attributed the audit row to the user" "$(sql "SELECT changed_by FROM pricing_config_history WHERE user_id=$UA")" "$UA"
check "step 5 labelled the audit row" "$(sql "SELECT summary FROM pricing_config_history WHERE user_id=$UA")" "Setup Guide: starter price list"
check "step 5 kept the Arabic currency word" "$(sql "SELECT JSON_VALUE(config_json,'\$.currency') FROM pricing_config WHERE user_id=$UA")" "جنيه"
check "step 5 price list is usable by the calculator" "$(sql "SELECT JSON_VALUE(config_json,'\$.accommodation.rooms[0].nightly') FROM pricing_config WHERE user_id=$UA")" "1000"
check "step 5 wrote the list on the chosen page" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UA AND channel_type='fb' AND target_id='$PAGE_A'")" "1"
check "step 5 did NOT write the account-wide default list" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UA AND channel_type=''")" "0"
check "step 5 wrote nothing for the other business's page" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UA AND target_id='$PAGE_A2'")" "0"
check "step 5 audit row records the page" "$(sql "SELECT CONCAT(channel_type,':',target_id) FROM pricing_config_history WHERE user_id=$UA")" "fb:$PAGE_A"
check "step 5 reported the page it saved" "$(echo "$r" | jget saved_pages)" "[\"Tenant A Page\"]"
S5=$(curl -s -b "$T/$A" "$B/onboarding/step/5")
check "step 5 page shows the chosen page already has a list" "$(echo "$S5" | grep -c 'pr-has-list')" "1"
check "step 5 links to Products & Pricing with that page preselected" "$(echo "$S5" | grep -c "pricing?scope=fb%3A$PAGE_A\"")" "1"
check "step 5 page does not list the other business's page" "$(echo "$S5" | grep -c 'Tenant A Other Business')" "0"
check "step 5 hides 'use for all my pages' once a list exists" "$(echo "$S5" | grep -c 'id="pr_all"')" "0"
check "the preselect link opens that page's list" "$(curl -s -b "$T/$A" "$B/pricing?scope=fb%3A$PAGE_A" | grep -c "var SCOPE = \"fb:$PAGE_A\"")" "1"
r=$(post $A save_pricing --data-urlencode "csrf_token=$KA" -d use_for_all=1)
check "'use for all my pages' is refused when a list already exists" "$(echo "$r" | jget status)" "'0'"
check "the refused tick wrote no default list" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UA AND channel_type=''")" "0"

r=$(post $A save_pipeline --data-urlencode "csrf_token=$KA")
check "step 6 created the pipeline" "$(echo "$r" | jget status)" "'1'"
PIPE_A=$(sql "SELECT id FROM crm_pipelines WHERE user_id=$UA")
check "step 6 created exactly one pipeline" "$(sql "SELECT COUNT(*) FROM crm_pipelines WHERE user_id=$UA")" "1"
check "step 6 added the hotel stage names" "$(sql "SELECT COUNT(*) FROM crm_stages WHERE pipeline_id=$PIPE_A AND name IN ('New Enquiry','Dates Collected','Quote Sent','Waiting Deposit','Booked')")" "5"
check "step 6 kept the CRM default stages too" "$(sql "SELECT COUNT(*) FROM crm_stages WHERE pipeline_id=$PIPE_A AND name IN ('New Lead','Won','Lost')")" "3"

r=$(post $A save_hours --data-urlencode "csrf_token=$KA" -d mode=staff_hours_silent -d hours_start=10:30 -d hours_end=18:00 -d hours_days=1,2,3)
check "step 7 applied to tenant A's channel" "$(echo "$r" | jget channels_updated)" "1"
check "step 7 turned the staff window on" "$(sql "SELECT hours_enabled FROM facebook_rx_fb_page_info WHERE id=$PAGE_A")" "1"
check "step 7 did NOT touch the other business's page" "$(sql "SELECT hours_enabled FROM facebook_rx_fb_page_info WHERE id=$PAGE_A2")" "0"
check "step 7 normalised the start time" "$(sql "SELECT hours_start FROM facebook_rx_fb_page_info WHERE id=$PAGE_A")" "10:30:00"
check "step 7 normalised the end time" "$(sql "SELECT hours_end FROM facebook_rx_fb_page_info WHERE id=$PAGE_A")" "18:00:00"
check "step 7 stored the days in the existing format" "$(sql "SELECT hours_days FROM facebook_rx_fb_page_info WHERE id=$PAGE_A")" "1,2,3"
check "step 7 did NOT touch tenant B's channel" "$(sql "SELECT hours_enabled FROM facebook_rx_fb_page_info WHERE user_id=$UB")" "0"
r=$(post $A save_hours --data-urlencode "csrf_token=$KA" -d mode=staff_hours_silent -d hours_start=99:99 -d hours_end=18:00 -d hours_days=9)
check "step 7 rejects an impossible time and falls back" "$(sql "SELECT hours_start FROM facebook_rx_fb_page_info WHERE id=$PAGE_A")" "09:00:00"
check "step 7 rejects an impossible weekday and keeps the stored days" "$(sql "SELECT hours_days FROM facebook_rx_fb_page_info WHERE id=$PAGE_A")" "1,2,3"

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
post $A save_channels --data-urlencode "csrf_token=$KA" --data-urlencode "targets[]=fb:$PAGE_A" >/dev/null
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
sql "UPDATE ai_faq SET answer='الدخول ٣ العصر والخروج ١١ الصبح.' WHERE user_id=$UA AND source_key LIKE 'tpl:hotel:checkin_checkout@%';
     INSERT INTO ai_faq (user_id,page_id,source_key,question,answer,status,created_at) VALUES ($UA,NULL,NULL,'بتقبلوا حيوانات أليفة؟','أيوه، بشرط.','1',NOW());" >/dev/null
post $A save_knowledge --data-urlencode "csrf_token=$KA" >/dev/null
check "a blank field does not wipe the owner's answer" "$(sql "SELECT answer FROM ai_faq WHERE user_id=$UA AND source_key LIKE 'tpl:hotel:checkin_checkout@%'")" "الدخول ٣ العصر والخروج ١١ الصبح."
check "a hand-written FAQ is left alone" "$(sql "SELECT answer FROM ai_faq WHERE user_id=$UA AND source_key IS NULL")" "أيوه، بشرط."
check "re-running deletes nothing" "$(sql "SELECT COUNT(*) FROM ai_faq WHERE user_id=$UA")" "9"
r=$(post $A save_knowledge --data-urlencode "csrf_token=$KA" --data-urlencode "faq[checkin_checkout]=الدخول ٢ الضهر.")
check "a typed answer does replace the stored one" "$(sql "SELECT answer FROM ai_faq WHERE user_id=$UA AND source_key LIKE 'tpl:hotel:checkin_checkout@%'")" "الدخول ٢ الضهر."
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
r=$(post $Bt save_channels --data-urlencode "csrf_token=$KB" --data-urlencode "targets[]=fb:$PAGE_A")
check "tenant B cannot choose tenant A's page" "$(echo "$r" | jget status)" "'0'"
post $Bt save_channels --data-urlencode "csrf_token=$KB" --data-urlencode "targets[]=fb:$PAGE_B" >/dev/null
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
check "clinic checklist says prices stay as answers" "$(curl -s -b "$T/$Bt" "$B/onboarding/step/9" | grep -c 'Prices kept as answers')" "1"
S5B=$(curl -s -b "$T/$Bt" "$B/onboarding/step/5")
check "clinic step 5 offers the products & services list" "$(echo "$S5B" | grep -c 'id="pr_items"')" "1"
check "clinic step 5 offers to skip" "$(echo "$S5B" | grep -c 'id="ob_skip"')" "1"
check "with zero lists, clinic step 5 offers 'use for all my pages'" "$(echo "$S5B" | grep -c 'id="pr_all"')" "1"
check "clinic step 5 links B's own page to Products & Pricing" "$(echo "$S5B" | grep -c "pricing?scope=fb%3A$PAGE_B\"")" "1"

echo "== a vertical without the calculator: products & services on the chosen page =="
r=$(post $Bt save_pricing --data-urlencode "csrf_token=$KB" --data-urlencode "items[0][name]=كشف" -d "items[0][price]=0" -d "items[0][unit]=session")
check "a zero price is refused by the pricing validator" "$(echo "$r" | jget status)" "'0'"
case "$r" in *"price must be"*) ok "the refusal names the pricing rule";; *) bad "the refusal names the pricing rule" "$r";; esac
check "the refused catalog wrote nothing" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UB")" "0"
r=$(post $Bt save_pricing --data-urlencode "csrf_token=$KB" --data-urlencode "items[0][name]=كشف" -d "items[0][price]=300" -d "items[0][unit]=session" \
      --data-urlencode "items[1][name]=متابعة" -d "items[1][price]=150" -d "items[1][unit]=item" -d "items[2][name]=" -d "items[2][price]=" --data-urlencode "currency=جنيه")
check "clinic saved its products & services" "$(echo "$r" | jget mode)" "'catalog'"
check "the catalog went on B's chosen page" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UB AND channel_type='fb' AND target_id='$PAGE_B'")" "1"
check "the catalog wrote no default list" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UB AND channel_type=''")" "0"
check "blank rows were dropped" "$(sql "SELECT JSON_LENGTH(config_json,'\$.catalog.items') FROM pricing_config WHERE user_id=$UB")" "2"
check "the Arabic item name is intact" "$(sql "SELECT JSON_VALUE(config_json,'\$.catalog.items[0].name') FROM pricing_config WHERE user_id=$UB")" "كشف"
check "the unit was kept" "$(sql "SELECT JSON_VALUE(config_json,'\$.catalog.items[0].unit') FROM pricing_config WHERE user_id=$UB")" "session"
check "the catalog audit row records the page" "$(sql "SELECT CONCAT(channel_type,':',target_id) FROM pricing_config_history WHERE user_id=$UB")" "fb:$PAGE_B"
check "the catalog audit row is labelled" "$(sql "SELECT summary FROM pricing_config_history WHERE user_id=$UB")" "Setup Guide: products & services"
check "the wizard's AI employee can now use the price tool" "$(sql "SELECT ai_tools_enabled FROM ai_agent_profiles WHERE user_id=$UB")" "1"
check "clinic checklist: every chosen page has a list" "$(curl -s -b "$T/$Bt" "$B/onboarding/step/9" | grep -c 'Price list on every page')" "1"
check "tenant A's lists are untouched by B's catalog" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UA")" "1"
r=$(post $Bt save_pricing --data-urlencode "csrf_token=$KB" --data-urlencode "items[0][name]=غيره" -d "items[0][price]=999")
check "a second catalog never overwrites the page's list" "$(sql "SELECT JSON_VALUE(config_json,'\$.catalog.items[0].name') FROM pricing_config WHERE user_id=$UB")" "كشف"
check "the kept page is reported" "$(echo "$r" | jget kept_existing)" "[\"Tenant B Page\"]"
check "keeping a page adds no audit row" "$(sql "SELECT COUNT(*) FROM pricing_config_history WHERE user_id=$UB")" "1"
r=$(post $Bt save_pricing --data-urlencode "csrf_token=bogus" --data-urlencode "items[0][name]=x" -d "items[0][price]=5")
case "$r" in *CSRF*|*csrf*) ok "step 5 without a valid token is refused";; *) bad "step 5 without a valid token is refused" "$r";; esac
r=$(post $Bt save_pricing --data-urlencode "csrf_token=$KB" -d skip=1)
check "skipping still works" "$(echo "$r" | jget mode)" "'faq_only'"
check "skipping removed nothing" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UB")" "1"

echo "== 'use for all my pages' only on an account with zero lists =="
sql "DELETE FROM pricing_config WHERE user_id=$UB; DELETE FROM pricing_config_history WHERE user_id=$UB;" >/dev/null
r=$(post $Bt save_pricing --data-urlencode "csrf_token=$KB" -d use_for_all=1 --data-urlencode "items[0][name]=كشف" -d "items[0][price]=300")
check "the ticked list is saved" "$(echo "$r" | jget default)" "true"
check "it became the account-wide default list" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UB AND channel_type='' AND target_id=''")" "1"
check "no page list was written beside it" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UB AND channel_type<>''")" "0"
check "the default-list audit row says so" "$(sql "SELECT CONCAT('[',channel_type,':',target_id,']') FROM pricing_config_history WHERE user_id=$UB")" "[:]"
check "checklist: the page resolves to the default list" "$(curl -s -b "$T/$Bt" "$B/onboarding/step/9" | grep -c 'Price list on every page')" "1"
check "step 5 shows the page uses the list for all pages" "$(curl -s -b "$T/$Bt" "$B/onboarding/step/5" | grep -c 'Uses the list for all pages')" "1"
r=$(post $Bt save_pricing --data-urlencode "csrf_token=$KB" -d use_for_all=1 --data-urlencode "items[0][name]=غيره" -d "items[0][price]=5")
check "a second tick is refused" "$(echo "$r" | jget status)" "'0'"
check "the default list was not overwritten" "$(sql "SELECT JSON_VALUE(config_json,'\$.catalog.items[0].name') FROM pricing_config WHERE user_id=$UB AND channel_type=''")" "كشف"
sql "DELETE FROM pricing_config WHERE user_id=$UB; DELETE FROM pricing_config_history WHERE user_id=$UB;" >/dev/null

echo "== tenant B cannot read tenant A's onboarding state =="
check "B's state row is its own" "$(sql "SELECT JSON_VALUE(data_json,'\$.business_name') FROM onboarding_state WHERE user_id=$UB")" "عيادة النور"
check "B's step 3 does not show A's agent" "$(curl -s -b "$T/$Bt" "$B/onboarding/step/3" | grep -c 'مقفولين يوم التلات')" "0"
check "B's step 9 checklist does not name A's pages" "$(curl -s -b "$T/$Bt" "$B/onboarding/step/9" | grep -c 'Tenant A Page')" "0"
check "B's step 4 does not show A's hotel questions" "$(curl -s -b "$T/$Bt" "$B/onboarding/step/4" | grep -c 'مواعيد الدخول والخروج')" "0"
# a forged step number cannot reach another tenant's data either: the controller keys on session uid only
check "step numbers are clamped, not trusted" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/$Bt" "$B/onboarding/step/99")" "200"

echo "== step 5 across several pages: kept, written, WhatsApp kinds kept apart =="
login $A; KA=$(tok $A)
sql "DELETE FROM openwa_accounts WHERE id=77; DELETE FROM whatsapp_accounts WHERE id=77;
     INSERT INTO openwa_accounts (id,user_id,label,base_url,api_key,session_id) VALUES (77,$UA,'OB personal','http://x','k','ob-sess-77');
     INSERT INTO whatsapp_accounts (id,user_id,label,phone_number_id,access_token,verify_token) VALUES (77,$UA,'OB cloud','1','t','v');
     INSERT INTO pricing_config (user_id,channel_type,target_id,config_json,status,updated_at)
       VALUES ($UA,'fb','$PAGE_A2','{\"currency\":\"جنيه\",\"catalog\":{\"items\":[{\"name\":\"OWNER-OWN\",\"price\":1}]}}','1',NOW());" 2>&1 | grep -v Warning
H0=$(sql "SELECT COUNT(*) FROM pricing_config_history WHERE user_id=$UA")
r=$(post $A save_channels --data-urlencode "csrf_token=$KA" --data-urlencode "targets[]=fb:$PAGE_A" --data-urlencode "targets[]=fb:$PAGE_A2" \
      --data-urlencode "targets[]=wa:77" --data-urlencode "targets[]=openwa:77")
check "four pages chosen" "$(echo "$r" | jget selected)" "4"
S5=$(curl -s -b "$T/$A" "$B/onboarding/step/5")
check "step 5 shows both pages that already have a list" "$(echo "$S5" | grep -c 'pr-has-list')" "2"
check "step 5 links the other page preselected" "$(echo "$S5" | grep -c "pricing?scope=fb%3A$PAGE_A2\"")" "1"
check "step 5 links the Cloud number as wa_cloud" "$(echo "$S5" | grep -c 'pricing?scope=wa_cloud%3A77"')" "1"
check "step 5 links the personal number as openwa" "$(echo "$S5" | grep -c 'pricing?scope=openwa%3A77"')" "1"
r=$(post $A save_pricing --data-urlencode "csrf_token=$KA")
check "step 5 saved the two pages without a list" "$(echo "$r" | jget saved)" "2"
check "step 5 reports both kept pages" "$(echo "$r" | jget kept_existing)" "[\"Tenant A Page\",\"Tenant A Other Business\"]"
check "the owner's own list is untouched" "$(sql "SELECT JSON_VALUE(config_json,'\$.catalog.items[0].name') FROM pricing_config WHERE user_id=$UA AND target_id='$PAGE_A2'")" "OWNER-OWN"
check "wizard 'wa' went to WhatsApp Cloud" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UA AND channel_type='wa_cloud' AND target_id='77'")" "1"
check "wizard 'openwa' went to the personal number" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UA AND channel_type='openwa' AND target_id='77'")" "1"
check "no list was written under a bare 'wa' scope" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UA AND channel_type='wa'")" "0"
check "still no default list" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UA AND channel_type=''")" "0"
check "one audit row per written page, each with its page" "$(sql "SELECT GROUP_CONCAT(CONCAT(channel_type,':',target_id) ORDER BY channel_type) FROM pricing_config_history WHERE user_id=$UA AND channel_type IN ('wa_cloud','openwa')")" "openwa:77,wa_cloud:77"
check "exactly two new audit rows" "$(( $(sql "SELECT COUNT(*) FROM pricing_config_history WHERE user_id=$UA") - H0 ))" "2"
S9=$(curl -s -b "$T/$A" "$B/onboarding/step/9")
check "checklist: every chosen page has a list" "$(echo "$S9" | grep -c 'Price list on every page')" "1"
check "checklist names no missing page" "$(echo "$S9" | grep -c 'No price list yet')" "0"
sql "UPDATE pricing_config SET status='0' WHERE user_id=$UA AND target_id='$PAGE_A2'" >/dev/null
check "checklist names the page whose list is switched off" "$(curl -s -b "$T/$A" "$B/onboarding/step/9" | grep -c 'No price list yet: Tenant A Other Business')" "1"
r=$(post $A save_pricing --data-urlencode "csrf_token=$KA")
check "a switched-off list is still kept, not overwritten" "$(sql "SELECT CONCAT(status,JSON_VALUE(config_json,'\$.catalog.items[0].name')) FROM pricing_config WHERE user_id=$UA AND target_id='$PAGE_A2'")" "0OWNER-OWN"
# a page of another tenant smuggled into the saved state never gets a list
sql "UPDATE onboarding_state SET data_json=JSON_SET(data_json,'\$.targets',JSON_ARRAY('fb:$PAGE_A','fb:$PAGE_B')) WHERE user_id=$UA" >/dev/null
post $A save_pricing --data-urlencode "csrf_token=$KA" >/dev/null
check "tenant B's page never gets tenant A's list" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE target_id='$PAGE_B' AND channel_type='fb'")" "0"
sql "UPDATE package SET module_ids='342,410' WHERE id=90" >/dev/null
rm -f "$T/$A"; login $A
check "without Products & Pricing in the plan, step 5 shows no dead link" "$(curl -s -b "$T/$A" "$B/onboarding/step/5" | grep -c 'pricing?scope=')" "0"
sql "UPDATE package SET module_ids='342,410,408' WHERE id=90" >/dev/null
rm -f "$T/$A"; login $A
# back to the single page the earlier checks assumed
sql "DELETE FROM pricing_config WHERE user_id=$UA AND (target_id='$PAGE_A2' OR target_id='77');
     DELETE FROM openwa_accounts WHERE id=77; DELETE FROM whatsapp_accounts WHERE id=77;
     UPDATE onboarding_state SET data_json=JSON_SET(data_json,'\$.targets',JSON_ARRAY('fb:$PAGE_A')) WHERE user_id=$UA" >/dev/null

echo "== the module gate =="
check "the sidebar shows the Setup Guide to a member who has it" "$(curl -s -b "$T/$A" "$B/ai_knowledge_base" | grep -c "/onboarding'")" "1"
sql "UPDATE package SET module_ids='342' WHERE id=90" >/dev/null
rm -f "$T/$A"; login $A
check "the sidebar hides it from a member who does not" "$(curl -s -b "$T/$A" "$B/ai_knowledge_base" | grep -c "/onboarding'")" "0"
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
