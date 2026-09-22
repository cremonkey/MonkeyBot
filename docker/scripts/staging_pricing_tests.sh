#!/bin/sh
# Products & Pricing regression suite — STAGING ONLY (127.0.0.1:8089). Needs staging_seed.sh.
set -u
B=${STAGING_URL:-http://127.0.0.1:8089}; DBC=${STAGING_DB:-monkeybot-staging-db-1}
T=$(mktemp -d); PASS=0; FAIL=0
php(){ docker exec -i "${STAGING_APP:-monkeybot-staging-app-1}" php "$@"; }
ok(){ PASS=$((PASS+1)); echo "PASS  $1"; }; bad(){ FAIL=$((FAIL+1)); echo "FAIL  $1  -> $2"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "got '$2' want '$3'"; fi; }
sql(){ docker exec "$DBC" sh -c 'mariadb -N -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "$0"' "$1"; }
login(){ j="$T/$1"; tok=$(curl -s -c "$j" "$B/home/login_page" | grep -o 'name="csrf_token"[^>]*value="[^"]*"' | sed 's/.*value="//;s/"$//');
  curl -s -b "$j" -c "$j" -o /dev/null -X POST "$B/home/login" --data-urlencode "csrf_token=$tok" --data-urlencode "username=$1" --data-urlencode "password=Staging#Pass1"; }
tok(){ curl -s -b "$T/$1" "$B/pricing" | grep -o 'var TOKEN = "[^"]*"' | sed 's/.*"\(.*\)"/\1/'; }
post(){ j="$T/$1"; p="$2"; shift 2; curl -s -b "$j" -H 'X-Requested-With: XMLHttpRequest' -X POST "$B/$p" "$@"; }
jget(){ php -r '$d=json_decode(stream_get_contents(STDIN),true); $p=explode(".",$argv[1]); foreach($p as $k){ if(!is_array($d)||!array_key_exists($k,$d)){echo "∅";exit;} $d=$d[$k]; } echo is_array($d)?json_encode($d,JSON_UNESCAPED_UNICODE):var_export($d,true);' "$1"; }

CFG=$(cat "$(dirname "$0")/../../docs/byoot-pricing-config.json" | php -r '$c=json_decode(stream_get_contents(STDIN),true); $c["media"]=["items"=>["Double Garden View"=>["album"=>"https://example.test/album","aliases"=>["dgv"]]]]; echo json_encode($c,JSON_UNESCAPED_UNICODE);')
UID_A=1
sql "DELETE FROM pricing_config WHERE user_id=$UID_A; DELETE FROM pricing_config_history WHERE user_id=$UID_A;" >/dev/null

login admin@staging.local
check "admin opens /pricing" "$(curl -s -o /dev/null -w '%{http_code}' -b "$T/admin@staging.local" "$B/pricing")" "200"
K=$(tok admin@staging.local)

r=$(post admin@staging.local pricing/calc --data-urlencode "csrf_token=$K" --data-urlencode "config_json=$CFG" -d kind=accommodation --data-urlencode "item=Double Garden" -d nights=4 -d adults=2 -d c_free=0 -d c_half=0 -d c_full=0)
check "draft calc: Double Garden 4 nights = package offer" "$(echo "$r" | jget result.total)" "11500"
r=$(post admin@staging.local pricing/calc --data-urlencode "csrf_token=$K" --data-urlencode "config_json=$CFG" -d kind=day_use -d item= -d nights=1 -d adults=5 -d c_free=0 -d c_half=0 -d c_full=0)
check "draft calc: day use 5 adults, every 5 pay 4 = 4×900" "$(echo "$r" | jget result.total)" "3600"
check "draft calc did not save anything" "$(sql "SELECT COUNT(*) FROM pricing_config WHERE user_id=$UID_A")" "0"

BAD=$(echo "$CFG" | php -r '$c=json_decode(stream_get_contents(STDIN),true); $c["accommodation"]["rooms"][0]["nightly"]=0; $c["accommodation"]["rooms"][1]["name"]=$c["accommodation"]["rooms"][2]["name"]; echo json_encode($c,JSON_UNESCAPED_UNICODE);')
r=$(post admin@staging.local pricing/save --data-urlencode "csrf_token=$K" --data-urlencode "config_json=$BAD" -d summary=bad)
check "invalid prices rejected" "$(echo "$r" | jget status)" "'0'"
case "$r" in *nightly*duplicate*|*duplicate*nightly*) ok "errors name the price and the duplicate";; *) bad "errors name the price and the duplicate" "$r";; esac
check "rejected save wrote nothing" "$(sql "SELECT COUNT(*) FROM pricing_config_history WHERE user_id=$UID_A")" "0"

r=$(post admin@staging.local pricing/save --data-urlencode "csrf_token=$K" --data-urlencode "config_json=$CFG" -d summary=initial)
check "valid prices saved" "$(echo "$r" | jget status)" "'1'"
check "history row written" "$(sql "SELECT COUNT(*) FROM pricing_config_history WHERE user_id=$UID_A")" "1"
check "unknown keys preserved (media alias)" "$(sql "SELECT JSON_VALUE(config_json,'$.media.items.\"Double Garden View\".aliases[0]') FROM pricing_config WHERE user_id=$UID_A")" "dgv"
check "Arabic preserved" "$(sql "SELECT JSON_VALUE(config_json,'$.currency') FROM pricing_config WHERE user_id=$UID_A")" "جنيه"
post admin@staging.local pricing/save --data-urlencode "csrf_token=$K" --data-urlencode "config_json=$CFG" -d summary=same >/dev/null
check "unchanged save adds no history" "$(sql "SELECT COUNT(*) FROM pricing_config_history WHERE user_id=$UID_A")" "1"
r=$(post admin@staging.local pricing/save --data-urlencode "csrf_token=bogus" --data-urlencode "config_json=$CFG")
case "$r" in *CSRF*|*csrf*) ok "save without valid CSRF token refused";; *) bad "save without valid CSRF token refused" "$r";; esac

login b@staging.local
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$T/b@staging.local" "$B/pricing")
case "$loc" in *access_forbidden*) ok "member without pricing module denied";; *) bad "member without pricing module denied" "$loc";; esac
sql "UPDATE package SET module_ids='342,408' WHERE id=90" >/dev/null
login b@staging.local
HID=$(sql "SELECT id FROM pricing_config_history WHERE user_id=$UID_A LIMIT 1")
r=$(curl -s -b "$T/b@staging.local" -H 'X-Requested-With: XMLHttpRequest' "$B/pricing/history_entry/$HID")
check "tenant B cannot read tenant A price history" "$(echo "$r" | jget status)" "'0'"
check "tenant B page shows empty defaults, not A's rooms" "$(curl -s -b "$T/b@staging.local" "$B/pricing" | grep -c 'Double Garden View')" "0"
sql "UPDATE package SET module_ids='342' WHERE id=90" >/dev/null

rm -rf "$T"; echo "== $PASS passed, $FAIL failed =="; [ "$FAIL" -eq 0 ]
