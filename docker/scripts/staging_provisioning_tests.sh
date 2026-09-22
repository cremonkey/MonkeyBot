#!/bin/sh
# Client provisioning (Phase 11 / brief §31, §32, §40, §51) regression suite — STAGING ONLY.
# Default target is the isolated stack on 127.0.0.1:8097; override with
#   STAGING_URL / STAGING_APP / STAGING_DB.
# Needs docker/scripts/staging_seed.sh (admin id 1, package 90 = 'KB only', validity 3650).
#
# The mid-transaction rollback mechanics themselves (a template that turns out invalid AFTER
# the users row is already inserted) are proven deterministically in tests/provisioning/run.php
# with a FakeDb, because that exact race is not reachable from a single HTTP request by design
# -- provisioning_create_client() validates the template before it ever writes a row, so an
# unknown template_key here is rejected pre-write. What THIS suite proves end-to-end over real
# HTTP + a real database is the observable guarantee that matters to the brief: every rejected
# provision_client() call, including one carrying a template_key, leaves ZERO rows in `users`
# and `onboarding_state` -- no orphaned half-account, ever.
set -u
B=${STAGING_URL:-http://127.0.0.1:8097}
DBC=${STAGING_DB:-mb-provisioning-test-db-1}
APPC=${STAGING_APP:-mb-provisioning-test-app-1}
T=$(mktemp -d); PASS=0; FAIL=0
php(){ docker exec -i "$APPC" php "$@"; }
ok(){ PASS=$((PASS+1)); echo "PASS  $1"; }; bad(){ FAIL=$((FAIL+1)); echo "FAIL  $1  -> $2"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "got '$2' want '$3'"; fi; }
code(){ curl -s -o /dev/null -w '%{http_code}' "$@"; }
sql(){ docker exec "$DBC" sh -c 'mariadb -N -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "$0"' "$1"; }
login(){ # $1 email, $2 password -> cookie jar $T/$1
  j="$T/$1"
  tok=$(curl -s -c "$j" "$B/home/login_page" | grep -o 'name="csrf_token"[^>]*value="[^"]*"' | sed 's/.*value="//;s/"$//' | head -1)
  curl -s -b "$j" -c "$j" -o /dev/null -X POST "$B/home/login" \
    --data-urlencode "csrf_token=$tok" --data-urlencode "username=$1" --data-urlencode "password=$2"
}
tok(){ curl -s -b "$T/$1" "$B/agency" | grep -o 'var TOKEN = "[^"]*"' | sed 's/.*"\(.*\)"/\1/'; }
post(){ j="$T/$1"; p="$2"; shift 2; curl -s -b "$j" -H 'X-Requested-With: XMLHttpRequest' -X POST "$B/$p" "$@"; }
jget(){ php -r '$d=json_decode(stream_get_contents(STDIN),true); $p=explode(".",$argv[1]); foreach($p as $k){ if(!is_array($d)||!array_key_exists($k,$d)){echo "∅";exit;} $d=$d[$k]; } echo is_array($d)?json_encode($d,JSON_UNESCAPED_UNICODE):var_export($d,true);' "$1"; }

ADMIN=admin@staging.local
E1=owner-1@newclient.test
E2=owner-2@newclient.test

sql "DELETE FROM users WHERE email IN ('$E1','$E2'); DELETE FROM onboarding_state WHERE user_id NOT IN (SELECT id FROM users);
UPDATE package SET module_ids='342,410' WHERE id=90;" >/dev/null

# ------------------------------------------------------------------ admin opens the page
login "$ADMIN" 'Staging#Pass1'
check "admin opens /agency" "$(code -b "$T/$ADMIN" "$B/agency")" "200"
NC=$(curl -s -b "$T/$ADMIN" "$B/agency" | grep -c 'new_client_modal')
[ "$NC" -gt 0 ] && ok "New client modal present on the dashboard" || bad "New client modal present on the dashboard" "grep count $NC"
K=$(tok "$ADMIN")

# ------------------------------------------------------------------ non-admin denied (brief: not a silent no-op)
login b@staging.local 'Staging#Pass1'
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$T/b@staging.local" -H 'X-Requested-With: XMLHttpRequest' -X POST "$B/agency_provision/provision_client" \
  --data-urlencode "csrf_token=x" --data-urlencode "business_name=Sneaky" --data-urlencode "email=sneaky@newclient.test" --data-urlencode "package_id=90")
case "$loc" in *access_forbidden*) ok "member (non-admin) provisioning attempt denied, redirected";; *) bad "member (non-admin) provisioning attempt denied, redirected" "$loc";; esac
check "denied attempt created no row" "$(sql "SELECT COUNT(*) FROM users WHERE email='sneaky@newclient.test'")" "0"

# ------------------------------------------------------------------ CSRF enforced
r=$(post "$ADMIN" agency_provision/provision_client --data-urlencode "csrf_token=bogus" --data-urlencode "business_name=CSRF Test" --data-urlencode "email=csrf@newclient.test" -d package_id=90)
case "$r" in *CSRF*|*csrf*) ok "provisioning without valid CSRF token refused";; *) bad "provisioning without valid CSRF token refused" "$r";; esac
check "CSRF-refused attempt created no row" "$(sql "SELECT COUNT(*) FROM users WHERE email='csrf@newclient.test'")" "0"

# ------------------------------------------------------------------ invalid package_id rejected
r=$(post "$ADMIN" agency_provision/provision_client --data-urlencode "csrf_token=$K" --data-urlencode "business_name=Bad Package Co" --data-urlencode "email=badpkg@newclient.test" -d package_id=999999)
check "invalid package_id rejected" "$(echo "$r" | jget status)" "'0'"
case "$r" in *package*|*Package*) ok "invalid package_id error message names the package";; *) bad "invalid package_id error message names the package" "$r";; esac
check "invalid-package attempt created no row" "$(sql "SELECT COUNT(*) FROM users WHERE email='badpkg@newclient.test'")" "0"

# ------------------------------------------------------------------ invalid template_key rejected, ZERO rows either table
r=$(post "$ADMIN" agency_provision/provision_client --data-urlencode "csrf_token=$K" --data-urlencode "business_name=Bad Template Co" --data-urlencode "email=badtpl@newclient.test" -d package_id=90 --data-urlencode "template_key=not_a_real_template")
check "invalid template_key rejected" "$(echo "$r" | jget status)" "'0'"
check "invalid-template attempt created no users row" "$(sql "SELECT COUNT(*) FROM users WHERE email='badtpl@newclient.test'")" "0"
check "invalid-template attempt created no onboarding_state row" "$(sql "SELECT COUNT(*) FROM onboarding_state WHERE user_id NOT IN (SELECT id FROM users)")" "0"

# ------------------------------------------------------------------ happy path: provision with a template
r=$(post "$ADMIN" agency_provision/provision_client --data-urlencode "csrf_token=$K" --data-urlencode "business_name=Sunrise Clinic" --data-urlencode "email=$E1" -d package_id=90 --data-urlencode "template_key=hotel")
check "provision succeeds" "$(echo "$r" | jget status)" "'1'"
UID1=$(sql "SELECT id FROM users WHERE email='$E1'")
PW1=$(echo "$r" | jget temp_password | sed "s/^'//;s/'$//")
check "users row created" "$([ -n "$UID1" ] && echo yes)" "yes"
check "user_type = Member" "$(sql "SELECT user_type FROM users WHERE id=$UID1")" "Member"
check "status = 1 (active immediately)" "$(sql "SELECT status FROM users WHERE id=$UID1")" "1"
check "deleted = 0" "$(sql "SELECT deleted FROM users WHERE id=$UID1")" "0"
check "package_id matches chosen package" "$(sql "SELECT package_id FROM users WHERE id=$UID1")" "90"
EXP=$(sql "SELECT DATE(expired_date) FROM users WHERE id=$UID1")
WANT_EXP=$(docker exec "$APPC" php -r 'echo date("Y-m-d", strtotime("+3650 day", strtotime(date("Y-m-d"))));')
check "expired_date = today + package validity" "$EXP" "$WANT_EXP"
check "provisioned_by = the acting admin" "$(sql "SELECT provisioned_by FROM users WHERE id=$UID1")" "1"
check "provisioned_at recorded" "$([ -n "$(sql "SELECT provisioned_at FROM users WHERE id=$UID1")" ] && echo yes)" "yes"
check "onboarding_state seeded with the chosen template" "$(sql "SELECT template_key FROM onboarding_state WHERE user_id=$UID1")" "hotel"
check "onboarding_state starts at step 1" "$(sql "SELECT current_step FROM onboarding_state WHERE user_id=$UID1")" "1"

# ------------------------------------------------------------------ generated password actually logs the new user in
login "$E1" "$PW1"
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$T/$E1" "$B/onboarding")
case "$loc" in *step/1*|*step\/1*) ok "new client logs in with the generated password (session reaches Setup Guide step 1, not the login page)";; *) bad "new client logs in with the generated password" "redirected to '$loc'";; esac
# template pre-selected -> step 2 renders directly (200); with NO template, Onboarding::step()
# 302-redirects step>1 back to step 1, so a direct 200 here is the discriminator.
check "template pre-selection lets step 2 render (not bounced to step 1)" "$(code -b "$T/$E1" "$B/onboarding/step/2")" "200"

# ------------------------------------------------------------------ basic tenant isolation on first login
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$T/$E1" "$B/agency")
case "$loc" in *access_forbidden*) ok "new client cannot open the Agency Control Center (admin-only)";; *) bad "new client cannot open the Agency Control Center (admin-only)" "$loc";; esac
check "new client's onboarding_state has exactly one row (its own)" "$(sql "SELECT COUNT(*) FROM onboarding_state WHERE user_id=$UID1")" "1"
check "new client sees only its own data_json (blank, not another tenant's answers)" "$(sql "SELECT data_json FROM onboarding_state WHERE user_id=$UID1")" "{}"

# ------------------------------------------------------------------ duplicate email rejected, no second row
r=$(post "$ADMIN" agency_provision/provision_client --data-urlencode "csrf_token=$K" --data-urlencode "business_name=Duplicate Co" --data-urlencode "email=$E1" -d package_id=90)
check "duplicate email rejected" "$(echo "$r" | jget status)" "'0'"
case "$r" in *already*exists*) ok "duplicate email error is a clear message, not a DB error";; *) bad "duplicate email error is a clear message, not a DB error" "$r";; esac
check "still exactly one user row for that email" "$(sql "SELECT COUNT(*) FROM users WHERE email='$E1'")" "1"

# ------------------------------------------------------------------ provision without a template: no onboarding_state row
r=$(post "$ADMIN" agency_provision/provision_client --data-urlencode "csrf_token=$K" --data-urlencode "business_name=No Template Co" --data-urlencode "email=$E2" -d package_id=90)
check "provision without a template succeeds" "$(echo "$r" | jget status)" "'1'"
UID2=$(sql "SELECT id FROM users WHERE email='$E2'")
check "no onboarding_state row when no template chosen" "$(sql "SELECT COUNT(*) FROM onboarding_state WHERE user_id=$UID2")" "0"

sql "UPDATE package SET module_ids='342' WHERE id=90;
DELETE FROM users WHERE email IN ('$E1','$E2'); DELETE FROM onboarding_state WHERE user_id NOT IN (SELECT id FROM users);" >/dev/null
rm -rf "$T"; echo "== $PASS passed, $FAIL failed =="; [ "$FAIL" -eq 0 ]
