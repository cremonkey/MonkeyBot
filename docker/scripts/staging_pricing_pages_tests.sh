#!/bin/sh
# Products & Pricing per page — STAGING / isolated test stacks ONLY. Needs staging_seed.sh.
# Default target is the per-page pricing test stack on 127.0.0.1:8100; override with
#   STAGING_URL / STAGING_APP / STAGING_DB.
# Proves on a real stack (HTTP + DB + the real reply-path code through pricing_ai_probe.php):
#   the migration keeps a single-list tenant identical (before == after), two pages with
#   different catalogs get their own prices from the AI tool, a page without its own list
#   gets the default, copy / move / remove, openwa #N vs WhatsApp Cloud #N stay apart,
#   IDOR on every write endpoint, CSRF, and a member without module 408 is denied.
set -u
B=${STAGING_URL:-http://127.0.0.1:8100}; DBC=${STAGING_DB:-mb-pricing2-test-db-1}
APPC=${STAGING_APP:-mb-pricing2-test-app-1}
T=$(mktemp -d); PASS=0; FAIL=0
php(){ docker exec -i "$APPC" php "$@"; }
ok(){ PASS=$((PASS+1)); echo "PASS  $1"; }; bad(){ FAIL=$((FAIL+1)); echo "FAIL  $1  -> $2"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "got '$2' want '$3'"; fi; }
has(){ case "$2" in *"$3"*) ok "$1";; *) bad "$1" "'$3' not in: $(echo "$2" | cut -c1-400)";; esac; }
hasnt(){ case "$2" in *"$3"*) bad "$1" "'$3' found in: $(echo "$2" | cut -c1-400)";; *) ok "$1";; esac; }
sql(){ docker exec "$DBC" sh -c 'mariadb -N -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "$0"' "$1"; }
login(){ j="$T/$1"; tok=$(curl -s -c "$j" "$B/home/login_page" | grep -o 'name="csrf_token"[^>]*value="[^"]*"' | sed 's/.*value="//;s/"$//');
  curl -s -b "$j" -c "$j" -o /dev/null -X POST "$B/home/login" --data-urlencode "csrf_token=$tok" --data-urlencode "username=$1" --data-urlencode "password=Staging#Pass1"; }
tok(){ curl -s -b "$T/$1" "$B/pricing" | grep -o 'var TOKEN = "[^"]*"' | sed 's/.*"\(.*\)"/\1/'; }
post(){ j="$T/$1"; p="$2"; shift 2; curl -s -b "$j" -H 'X-Requested-With: XMLHttpRequest' -X POST "$B/$p" "$@"; }
jget(){ php -r '$d=json_decode(stream_get_contents(STDIN),true); $p=explode(".",$argv[1]); foreach($p as $k){ if(!is_array($d)||!array_key_exists($k,$d)){echo "∅";exit;} $d=$d[$k]; } echo is_array($d)?json_encode($d,JSON_UNESCAPED_UNICODE):(is_bool($d)?var_export($d,true):$d);' "$1"; }
b64(){ printf '%s' "$1" | base64 | tr -d '\n'; }
# NB: never `echo "$json" | jget` — dash's echo turns the "\n" inside rule10 into a raw
# newline and the JSON no longer parses. printf '%s' passes it through untouched.
# probe <social> <page_id> [<tool> <args_json>]  (user 1 unless PROBE_UID is set)
probe(){ if [ $# -ge 3 ]; then docker exec -u www-data "$APPC" php /var/www/html/docker/scripts/pricing_ai_probe.php "${PROBE_UID:-1}" "$1" "$2" "$3" "$(b64 "$4")";
         else docker exec -u www-data "$APPC" php /var/www/html/docker/scripts/pricing_ai_probe.php "${PROBE_UID:-1}" "$1" "$2"; fi; }

A=admin@staging.local; UA=1; Bt=b@staging.local; UB=12
BYOOT=$(cat "$(dirname "$0")/../../docs/byoot-pricing-config.json" | tr -d '\n')
SALON='{"currency":"جنيه","catalog":{"items":[{"name":"قص شعر","price":150,"unit":"session","aliases":["haircut"],"variants":[{"name":"أطفال","price":100}]},{"name":"Keratin Short","price":900,"unit":"session","aliases":["كيراتين"]},{"name":"Keratin Long","price":1400,"unit":"session","aliases":["كيراتين"]},{"name":"Bridal package","unit":"item","price_on_request":true}]}}'
SHOP='{"currency":"EGP","catalog":{"items":[{"name":"قص شعر","price":300,"unit":"session"}]}}'
OWA='{"currency":"جنيه","catalog":{"items":[{"name":"Delivery box","price":70,"unit":"piece"}]}}'
CLOUD='{"currency":"جنيه","catalog":{"items":[{"name":"Delivery box","price":85,"unit":"piece"}]}}'
HAIR2='{"type":"item","item":"قص شعر","quantity":2}'
STAY4='{"type":"accommodation","room_or_package":"Double Garden","nights":4,"adults":2}'

# ---------------------------------------------------------------- synthetic fixtures
sql "DELETE FROM pricing_config WHERE user_id IN ($UA,$UB); DELETE FROM pricing_config_history WHERE user_id IN ($UA,$UB);
     DELETE FROM facebook_rx_fb_page_info WHERE page_id LIKE 'PGTEST_%';
     DELETE FROM openwa_accounts WHERE id IN (55,56); DELETE FROM whatsapp_accounts WHERE id=55;
     UPDATE package SET module_ids='342' WHERE id=90;" >/dev/null
sql "INSERT INTO facebook_rx_fb_page_info (user_id,facebook_rx_fb_user_info_id,page_id,page_name,page_access_token,instagram_business_account_id) VALUES
       ($UA,0,'PGTEST_A111','Salon Page','x',''),($UA,0,'PGTEST_B222','Shop Page','x',''),($UA,0,'PGTEST_C333','Resort Page','x','IGTEST_C'),
       ($UA,0,'PGTEST_D444','Blog Page','x',''),($UB,0,'PGTEST_X999','Tenant B page','x','');
     INSERT INTO openwa_accounts (id,user_id,label,base_url,api_key,session_id) VALUES (55,$UA,'Personal WA','http://x','k','owtest-sess');
     INSERT INTO openwa_accounts (id,user_id,label,base_url,api_key,session_id) VALUES (56,$UB,'B personal','http://x','k','owtest-b');
     INSERT INTO whatsapp_accounts (id,user_id,label,phone_number_id,access_token,verify_token) VALUES (55,$UA,'Cloud WA','1','t','v');" 2>&1 | grep -v Warning
pid(){ sql "SELECT id FROM facebook_rx_fb_page_info WHERE page_id='$1'"; }
PA=$(pid PGTEST_A111); PB=$(pid PGTEST_B222); PC=$(pid PGTEST_C333); PD=$(pid PGTEST_D444); PX=$(pid PGTEST_X999)

# ---------------------------------------------------------------- migration: single-list tenant unchanged
sql "ALTER TABLE pricing_config DROP INDEX IF EXISTS uq_user_scope, DROP COLUMN IF EXISTS channel_type, DROP COLUMN IF EXISTS target_id;
     ALTER TABLE pricing_config ADD UNIQUE KEY user_id (user_id);
     ALTER TABLE pricing_config_history DROP COLUMN IF EXISTS channel_type, DROP COLUMN IF EXISTS target_id;
     DELETE FROM schema_migrations WHERE filename='2026-09-29-pricing-per-page.sql';" >/dev/null
check "pre-migration shape restored (no scope column)" "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pricing_config' AND COLUMN_NAME='channel_type'")" "0"
sql "INSERT INTO pricing_config (user_id,config_json,status,updated_at) VALUES ($UA,'$(printf '%s' "$BYOOT" | sed "s/'/''/g")','1',NOW())"
PRE_C=$(probe fb PGTEST_C333 calculate_price "$STAY4"); PRE_D=$(probe fb PGTEST_D444)
check "pre-migration: the single list prices a stay (4 nights = offer 11,500)" "$(printf "%s" "$PRE_C" | jget price_facts.0 | grep -c '11,500')" "1"
has "pre-migration: rule 10 is the original text" "$(printf "%s" "$PRE_D" | jget rule10)" "a number of nights, OR a number of people/adults, OR a headcount for day-use"
m=$(docker exec -u www-data "$APPC" php /var/www/html/index.php cron_hub migrate)
has "migration applies cleanly" "$m" "applied 2026-09-29-pricing-per-page.sql"
check "existing row became the default list ('','')" "$(sql "SELECT CONCAT(channel_type,'|',target_id) FROM pricing_config WHERE user_id=$UA")" "|"
check "old UNIQUE(user_id) dropped" "$(sql "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pricing_config' AND INDEX_NAME='user_id'")" "0"
check "new UNIQUE(user_id,channel_type,target_id)" "$(sql "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pricing_config' AND INDEX_NAME='uq_user_scope' AND NON_UNIQUE=0")" "3"
check "history table got the scope columns" "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pricing_config_history' AND COLUMN_NAME IN ('channel_type','target_id')")" "2"
POST_C=$(probe fb PGTEST_C333 calculate_price "$STAY4"); POST_D=$(probe fb PGTEST_D444)
check "post-migration: identical tool result + price facts for the same stay" "$POST_C" "$PRE_C"
check "post-migration: identical rule 10 / tool definition" "$POST_D" "$PRE_D"
has "migration is idempotent (second run applies nothing)" "$(docker exec -u www-data "$APPC" php /var/www/html/index.php cron_hub migrate)" "applied 0 migration(s)"

# ---------------------------------------------------------------- UI: selector lists page names, never raw ids
login $A
H=$(curl -s -b "$T/$A" "$B/pricing")
has "selector offers 'All pages (default list)'" "$H" "All pages (default list)"
has "selector lists the page by name" "$H" "Salon Page"
has "selector lists the openwa number separately" "$H" "Personal WA"
has "selector lists the cloud number separately" "$H" "Cloud WA"
hasnt "no raw FB page id shown" "$H" "PGTEST_A111"
hasnt "tenant B's page is not offered" "$H" "Tenant B page"
has "Products & services tab is there for every business" "$H" "Products &amp; services"
K=$(tok $A)
H=$(curl -s -b "$T/$A" "$B/pricing?scope=fb:$PA")
has "a page without its own list says it uses the default" "$H" "No own list — this page currently uses the default list"

# ---------------------------------------------------------------- two pages, two catalogs
r=$(post $A pricing/save --data-urlencode "csrf_token=$K" -d "scope=fb:$PA" --data-urlencode "config_json=$SALON" -d summary=salon)
check "page A catalog saved" "$(printf "%s" "$r" | jget status)" "1"
r=$(post $A pricing/save --data-urlencode "csrf_token=$K" -d "scope=fb:$PB" --data-urlencode "config_json=$SHOP" -d summary=shop)
check "page B catalog saved" "$(printf "%s" "$r" | jget status)" "1"
check "three lists now (default + A + B)" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UA")" "3"
check "history row for A records page A" "$(sql "SELECT CONCAT(channel_type,':',target_id) FROM pricing_config_history WHERE user_id=$UA AND summary='salon'")" "fb:$PA"
pa=$(probe fb PGTEST_A111 calculate_price "$HAIR2")
has "AI on page A: 2 haircuts = 300 جنيه (page A price)" "$(printf "%s" "$pa" | jget result)" "300 جنيه"
has "page A figure is in ai_price_facts (price guard source)" "$(printf "%s" "$pa" | jget price_facts)" "300 جنيه"
pb=$(probe fb PGTEST_B222 calculate_price "$HAIR2")
has "AI on page B: same item = 600 EGP (page B price)" "$(printf "%s" "$pb" | jget result)" "600 EGP"
hasnt "page B never gets page A's price" "$(printf "%s" "$pb" | jget result)" "300 جنيه"
pc=$(probe fb PGTEST_C333 calculate_price "$STAY4")
has "page C (no own list) gets the default list: 11,500" "$(printf "%s" "$pc" | jget result)" "11,500"
pci=$(probe ig IGTEST_C calculate_price "$STAY4")
has "Instagram of page C resolves to page C's scope (default here)" "$(printf "%s" "$pci" | jget result)" "11,500"
check "page A tool offers only items" "$(probe fb PGTEST_A111 | jget tool_enum)" '["item"]'
check "page C tool is the original stays/day-use one" "$(probe fb PGTEST_C333 | jget tool_enum)" '["accommodation","day_use"]'
has "page A rule 10 talks about products" "$(probe fb PGTEST_A111 | jget rule10)" "product or service"
amb=$(probe fb PGTEST_A111 calculate_price '{"type":"item","item":"كيراتين"}')
has "ambiguous alias -> the AI asks which one" "$(printf "%s" "$amb" | jget result)" "Keratin Short / Keratin Long"
check "ambiguous alias -> no price fact" "$(printf "%s" "$amb" | jget price_facts)" "[]"
has "price on request -> NO FINAL TOTAL" "$(probe fb PGTEST_A111 calculate_price '{"type":"item","item":"Bridal package"}' | jget result)" "NO FINAL TOTAL"
r=$(post $A pricing/calc --data-urlencode "csrf_token=$K" --data-urlencode "config_json=$SALON" -d kind=item --data-urlencode "item=haircut" --data-urlencode "variant=أطفال" -d quantity=3)
check "test calculator prices items (3 × 100 kids haircut)" "$(printf "%s" "$r" | jget result.total)" "300"

# ---------------------------------------------------------------- openwa #55 vs cloud #55
r=$(post $A pricing/save --data-urlencode "csrf_token=$K" -d "scope=openwa:55" --data-urlencode "config_json=$OWA" -d summary=owa)
check "openwa #55 list saved" "$(printf "%s" "$r" | jget status)" "1"
r=$(post $A pricing/save --data-urlencode "csrf_token=$K" -d "scope=wa_cloud:55" --data-urlencode "config_json=$CLOUD" -d summary=cloud)
check "wa_cloud #55 list saved (no unique collision)" "$(printf "%s" "$r" | jget status)" "1"
has "Openwa_bot context (session id) -> 70" "$(probe wa owtest-sess calculate_price '{"type":"item","item":"Delivery box"}' | jget result)" "70 جنيه"
has "Whatsapp_bot context (account id 55) -> 85" "$(probe wa 55 calculate_price '{"type":"item","item":"Delivery box"}' | jget result)" "85 جنيه"

# ---------------------------------------------------------------- copy / remove / move
r=$(post $A pricing/copy --data-urlencode "csrf_token=$K" -d "scope=fb:$PA" -d "to=fb:$PC")
check "copy page A -> page C" "$(printf "%s" "$r" | jget status)" "1"
has "page C now prices from its copy (300)" "$(probe fb PGTEST_C333 calculate_price "$HAIR2" | jget result)" "300 جنيه"
check "copy left page A intact" "$(sql "SELECT JSON_VALUE(config_json,'\$.catalog.items[0].price') FROM pricing_config WHERE user_id=$UA AND channel_type='fb' AND target_id='$PA'")" "150"
r=$(post $A pricing/copy --data-urlencode "csrf_token=$K" -d "scope=fb:$PB" -d "to=fb:$PC")
check "copy onto a page that has a list asks first" "$(printf "%s" "$r" | jget code)" "exists"
r=$(post $A pricing/remove --data-urlencode "csrf_token=$K" -d "scope=fb:$PC")
check "remove page C's own list" "$(printf "%s" "$r" | jget status)" "1"
has "page C back on the default list" "$(probe fb PGTEST_C333 calculate_price "$STAY4" | jget result)" "11,500"
has "page D (no own list) uses the default before the move" "$(probe fb PGTEST_D444 calculate_price "$STAY4" | jget result)" "11,500"
r=$(post $A pricing/move --data-urlencode "csrf_token=$K" -d "to=fb:$PC")
check "move the default list to page C" "$(printf "%s" "$r" | jget status)" "1"
check "no default list left" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UA AND channel_type='' AND target_id=''")" "0"
has "page C prices the Byoot stay from its own (moved) list" "$(probe fb PGTEST_C333 calculate_price "$STAY4" | jget result)" "11,500"
d=$(probe fb PGTEST_D444 calculate_price "$STAY4")
has "page D no longer quotes the Byoot prices" "$(printf "%s" "$d" | jget result)" "Pricing is not configured"
check "page D gets no rule 10" "$(printf "%s" "$d" | jget rule10)" ""
check "history names the moved-to page" "$(sql "SELECT COUNT(*) FROM pricing_config_history WHERE user_id=$UA AND channel_type='fb' AND target_id='$PC' AND summary LIKE 'Default list moved to Resort Page%'")" "1"
H=$(curl -s -b "$T/$A" "$B/pricing")
has "history shows which page a change was for" "$H" 'fa-map-pin"></i> Salon Page'

# ---------------------------------------------------------------- IDOR + CSRF
before=$(sql "SELECT COUNT(*) FROM pricing_config")
r=$(post $A pricing/save --data-urlencode "csrf_token=$K" -d "scope=fb:$PX" --data-urlencode "config_json=$SHOP")
check "save onto tenant B's page rejected" "$(printf "%s" "$r" | jget status)" "0"
r=$(post $A pricing/copy --data-urlencode "csrf_token=$K" -d "scope=fb:$PA" -d "to=fb:$PX")
check "copy onto tenant B's page rejected" "$(printf "%s" "$r" | jget status)" "0"
r=$(post $A pricing/move --data-urlencode "csrf_token=$K" -d "to=openwa:56")
check "move onto tenant B's number rejected" "$(printf "%s" "$r" | jget status)" "0"
r=$(post $A pricing/toggle --data-urlencode "csrf_token=$K" -d "scope=fb:$PX" -d active=0)
check "toggle on tenant B's page rejected" "$(printf "%s" "$r" | jget status)" "0"
r=$(post $A pricing/save --data-urlencode "csrf_token=$K" -d "scope=evil:1" --data-urlencode "config_json=$SHOP")
check "unknown channel scope rejected (not silently the default)" "$(printf "%s" "$r" | jget status)" "0"
check "no row was written by any rejected call" "$(sql "SELECT COUNT(*) FROM pricing_config")" "$before"
has "GET with a foreign page shows a warning, not that page" "$(curl -s -b "$T/$A" "$B/pricing?scope=fb:$PX")" "not connected to your account"
r=$(post $A pricing/save --data-urlencode "csrf_token=bogus" -d "scope=fb:$PA" --data-urlencode "config_json=$SHOP")
case "$r" in *CSRF*|*csrf*) ok "save without a valid CSRF token refused";; *) bad "save without a valid CSRF token refused" "$r";; esac
r=$(post $A pricing/copy --data-urlencode "csrf_token=bogus" -d "scope=fb:$PA" -d "to=fb:$PD")
case "$r" in *CSRF*|*csrf*) ok "copy without a valid CSRF token refused";; *) bad "copy without a valid CSRF token refused" "$r";; esac

# ---------------------------------------------------------------- member without module 408
login $Bt
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$T/$Bt" "$B/pricing")
case "$loc" in *access_forbidden*) ok "member without pricing module denied (page)";; *) bad "member without pricing module denied (page)" "$loc";; esac
r=$(post $Bt pricing/save -d "scope=fb:$PX" --data-urlencode "config_json=$SHOP")
check "member without pricing module cannot save" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UB")" "0"

# ---------------------------------------------------------------- clean up synthetic rows
sql "DELETE FROM pricing_config WHERE user_id IN ($UA,$UB); DELETE FROM pricing_config_history WHERE user_id IN ($UA,$UB);
     DELETE FROM facebook_rx_fb_page_info WHERE page_id LIKE 'PGTEST_%'; DELETE FROM openwa_accounts WHERE id IN (55,56); DELETE FROM whatsapp_accounts WHERE id=55;" >/dev/null
rm -rf "$T"; echo "== $PASS passed, $FAIL failed =="; [ "$FAIL" -eq 0 ]
