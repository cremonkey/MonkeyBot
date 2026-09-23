#!/bin/sh
# Unified Inbox realtime (Pusher) + log-noise suite — STAGING ONLY, isolated stack
# (docker-compose.rt-test.yml, 127.0.0.1:8107). Never the shared staging stack, never production.
# Run AFTER docker/scripts/staging_inbox_tests.sh on the same stack (it seeds tenants A/B, their
# conversations and package 90 with module 411).
#
#   STAGING_URL=http://127.0.0.1:8107 STAGING_DB=mb-rt-test-db-1 STAGING_APP=mb-rt-test-app-1 \
#   FAKEPUSHER_CT=mb-rt-test-fakepusher-1 docker/scripts/staging_inbox_realtime_tests.sh
#
# Points Pusher at the stack's fake Pusher HTTP API (tests/inbox/fake_pusher.php) by writing a
# TEMPORARY application/config/pusher.php into the bind-mounted tree; the original is restored
# on exit. Proves: the private-channel auth endpoint signs only the caller's own tenant channel
# (CSRF + session + module 411), inbound (web widget) and outbound (inbox reply) messages
# publish ids only to the owning tenant's channel, a hanging Pusher never breaks or stalls a
# message (short timeout, message still stored), Pusher unconfigured = no publish and no
# realtime on the page, and the pages that used to flood the log now log no warnings.
set -u
B=${STAGING_URL:-http://127.0.0.1:8107}
DBC=${STAGING_DB:-mb-rt-test-db-1}
APP=${STAGING_APP:-mb-rt-test-app-1}
FP=${FAKEPUSHER_CT:-mb-rt-test-fakepusher-1}
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
PCONF="$ROOT/application/config/pusher.php"
T=$(mktemp -d); PASS=0; FAIL=0
ok(){ PASS=$((PASS+1)); echo "PASS  $1"; }
bad(){ FAIL=$((FAIL+1)); echo "FAIL  $1  -> $2"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "got '$2' want '$3'"; fi; }
contains(){ case "$2" in *"$3"*) ok "$1";; *) bad "$1" "'$2' does not contain '$3'";; esac; }
lacks(){ case "$2" in *"$3"*) bad "$1" "found '$3'";; *) ok "$1";; esac; }
sql(){ docker exec "$DBC" sh -c 'mariadb -N -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "$0"' "$1"; }
login(){ j="$T/$1"; tok=$(curl -s -c "$j" "$B/home/login_page" | grep -o 'name="csrf_token"[^>]*value="[^"]*"' | sed 's/.*value="//;s/"$//' | head -1);
  curl -s -b "$j" -c "$j" -o /dev/null -X POST "$B/home/login" --data-urlencode "csrf_token=$tok" --data-urlencode "username=$1" --data-urlencode "password=Staging#Pass1"; }
tok(){ curl -s -b "$T/$1" "$B/inbox" | grep -o 'var TOKEN = "[^"]*"' | sed 's/.*"\(.*\)"/\1/'; }
post(){ j="$T/$1"; p="$2"; shift 2; curl -s -b "$j" -H 'X-Requested-With: XMLHttpRequest' -X POST "$B/inbox/$p" "$@"; }
fplog(){ docker exec "$FP" sh -c 'cat /tmp/fake_pusher.log 2>/dev/null' ; }
fpreset(){ docker exec "$FP" sh -c ': > /tmp/fake_pusher.log'; }
applog(){ docker exec "$APP" sh -c 'cat /var/www/html/application/logs/log-*.php 2>/dev/null'; }
applog_reset(){ docker exec "$APP" sh -c 'rm -f /var/www/html/application/logs/log-*.php'; }
cp "$PCONF" "$T/pusher.php.orig"
restore(){ cp "$T/pusher.php.orig" "$PCONF"; }
trap restore EXIT
restore_now(){ restore; sleep 3; } # opcache.revalidate_freq=2
pusher_on(){ # $1 = app id ("1", or "slow" to make the fake API hang 5s)
  cat > "$PCONF" <<PHP
<?php
\$config['pusher_app_id'] = '$1';
\$config['pusher_app_key'] = 'rtkey';
\$config['pusher_app_secret'] = 'rtsecret';
\$config['pusher_cluster'] = '';
\$config['pusher_host'] = 'fakepusher';
\$config['pusher_port'] = 8080;
\$config['pusher_scheme'] = 'http';
\$config['inbox_realtime_timeout'] = 1;
PHP
  sleep 3 # opcache.revalidate_freq=2
}

UID_A=11; UID_B=12; A=a@staging.local; Bt=b@staging.local
WEB_A=$(sql "SELECT id FROM conversations WHERE user_id=$UID_A AND external_thread_id='WEBSESS-A1'")
[ -n "$WEB_A" ] || { echo "run staging_inbox_tests.sh on this stack first"; exit 1; }
sql "UPDATE package SET module_ids='342,411' WHERE id=90;
     DELETE FROM webchat_settings WHERE widget_key='rtwidgetA';
     INSERT INTO webchat_settings (user_id,widget_key,ai_enabled,status) VALUES ($UID_A,'rtwidgetA','0','1');
     DELETE FROM webchat_sessions WHERE widget_key='rtwidgetA';" >/dev/null 2>&1

# ------------------------------------------------------------------ Pusher NOT configured
echo "== Pusher not configured: exactly the old behaviour (no realtime, no publish) =="
restore_now; fpreset; applog_reset
login "$A"; K=$(tok "$A")
page=$(curl -s -b "$T/$A" "$B/inbox")
contains "inbox page: realtime config is null" "$page" "var RT = null;"
r=$(post "$A" realtime_auth --data-urlencode "csrf_token=$K" -d socket_id=1.2 -d channel_name=private-inbox-x-11 -w ' %{http_code}')
contains "auth endpoint refuses everything when Pusher is off (403)" "$r" " 403"
r=$(curl -s -X POST "$B/webchat/send" -d widget_key=rtwidgetA -d message='hello while pusher is off')
contains "web widget message accepted" "$r" "session_key"
check "no publish attempted when Pusher is off" "$(fplog | wc -l | tr -d ' ')" "0"

# ------------------------------------------------------------------ Pusher configured (fake API)
echo "== Pusher configured (fake API) =="
pusher_on 1; fpreset
NS=$(docker exec "$APP" php -r 'define("BASEPATH",1); define("FCPATH","/var/www/html/"); define("APPPATH","/var/www/html/application/"); include "/var/www/html/application/config/config.php"; include "/var/www/html/application/helpers/inbox_realtime_helper.php"; echo inbox_realtime_namespace($config["encryption_key"]);')
CH_A="private-inbox-$NS-$UID_A"; CH_B="private-inbox-$NS-$UID_B"
page=$(curl -s -b "$T/$A" "$B/inbox")
contains "inbox page carries tenant A's own private channel" "$page" "\"channel\":\"$CH_A\""
lacks "inbox page never carries the Pusher secret" "$page" "rtsecret"

echo "== channel auth: tenant isolation =="
want=$(docker exec "$APP" php -r 'echo "rtkey:".hash_hmac("sha256", "123.456:".$argv[1], "rtsecret");' "$CH_A")
r=$(post "$A" realtime_auth --data-urlencode "csrf_token=$K" -d socket_id=123.456 --data-urlencode "channel_name=$CH_A")
check "tenant A is authorized for its own channel, with a valid Pusher signature" "$r" "{\"auth\":\"$want\"}"
r=$(post "$A" realtime_auth --data-urlencode "csrf_token=$K" -d socket_id=123.456 --data-urlencode "channel_name=$CH_B" -w ' %{http_code}')
contains "tenant A is refused tenant B's channel (403)" "$r" " 403"
r=$(post "$A" realtime_auth --data-urlencode "csrf_token=$K" -d socket_id=123.456 -d channel_name=livechat_channel -w ' %{http_code}')
contains "tenant A is refused a non-inbox channel (403)" "$r" " 403"
r=$(post "$A" realtime_auth -d socket_id=123.456 --data-urlencode "channel_name=$CH_A" -w ' %{http_code}')
contains "no CSRF token -> 403" "$r" " 403"
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/inbox/realtime_auth" -d socket_id=123.456 --data-urlencode "channel_name=$CH_A")
case "$code" in 30*) ok "logged out -> redirected to login, nothing signed ($code)";; *) bad "logged out is not authorized" "http $code";; esac
login "$Bt"; KB=$(tok "$Bt")
r=$(post "$Bt" realtime_auth --data-urlencode "csrf_token=$KB" -d socket_id=123.456 --data-urlencode "channel_name=$CH_A" -w ' %{http_code}')
contains "tenant B is refused tenant A's channel (403)" "$r" " 403"
sql "UPDATE package SET module_ids='342' WHERE id=90" >/dev/null
login "$A" # module access is read at login
loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$T/$A" -X POST "$B/inbox/realtime_auth" --data-urlencode "csrf_token=$K" -d socket_id=123.456 --data-urlencode "channel_name=$CH_A")
contains "without module 411 the auth endpoint is not reachable" "$loc" "access_forbidden"
sql "UPDATE package SET module_ids='342,411' WHERE id=90" >/dev/null
login "$A"; K=$(tok "$A")

echo "== publish: inbound web message -> tenant A's channel, ids only =="
fpreset
r=$(curl -s -X POST "$B/webchat/send" -d widget_key=rtwidgetA -d message='TOPSECRET customer text')
SK=$(echo "$r" | sed -n 's/.*"session_key":"\([^"]*\)".*/\1/p')
MID=$(sql "SELECT MAX(id) FROM livechat_messages WHERE subscriber_id='$SK'")
CID=$(sql "SELECT conversation_id FROM livechat_messages WHERE id=$MID")
log=$(fplog)
check "exactly one batch request reached Pusher" "$(echo "$log" | grep -c batch_events)" "1"
contains "event went to tenant A's channel" "$log" "\"channel\":\"$CH_A\""
contains "event names the conversation and message ids" "$log" "\"data\":\"{\\\"conversation_id\\\":$CID,\\\"message_id\\\":$MID,\\\"sender\\\":\\\"user\\\"}\""
lacks "event carries no message content" "$log" "TOPSECRET"
lacks "nothing was published to tenant B's channel" "$log" "$CH_B"

echo "== publish: outbound inbox reply -> tenant A's channel =="
fpreset
r=$(post "$A" reply --data-urlencode "csrf_token=$K" -d conversation_id="$WEB_A" --data-urlencode "message=REPLYSECRET agent text")
contains "inbox reply still succeeds" "$r" '"status":"1"'
log=$(fplog)
contains "reply published to tenant A's channel" "$log" "\"channel\":\"$CH_A\""
contains "reply event names conversation $WEB_A, sender bot" "$log" "conversation_id\\\":$WEB_A,"
lacks "reply event carries no message content" "$log" "REPLYSECRET"

echo "== a hanging Pusher never breaks or stalls a message =="
pusher_on slow; fpreset
before=$(sql "SELECT COUNT(*) FROM livechat_messages WHERE subscriber_id='$SK'")
t0=$(date +%s%N)
r=$(curl -s -X POST "$B/webchat/send" -d widget_key=rtwidgetA -d session_key="$SK" -d message='sent while pusher hangs')
ms=$(( ($(date +%s%N) - t0) / 1000000 ))
contains "message accepted while Pusher hangs" "$r" "session_key"
check "message stored while Pusher hangs" "$(sql "SELECT COUNT(*) FROM livechat_messages WHERE subscriber_id='$SK'")" "$((before + 1))"
[ "$ms" -lt 3500 ] && ok "request bounded by the publish timeout (${ms}ms, fake API hangs 5000ms)" || bad "request bounded by the publish timeout" "${ms}ms"
contains "failure logged once as inbox_realtime: publish failed" "$(applog)" "inbox_realtime: publish failed"

restore_now
echo "== log noise: pages that used to warn now log none =="
applog_reset
sql "UPDATE package SET monthly_limit='{}', visible='1', price='10' WHERE id=90" >/dev/null
for p in inbox dashboard; do curl -s -o /dev/null -b "$T/$A" "$B/$p"; done
curl -s -o /dev/null "$B/"
curl -s -o /dev/null "$B/home"
login admin@staging.local
for p in inbox dashboard "payment/package_manager" "payment/details_package/90" "payment/edit_package/90"; do curl -s -o /dev/null -b "$T/admin@staging.local" "$B/$p"; done
L=$(applog)
lacks "no 'Undefined variable \$fb_rx_account_switching_info'" "$L" "fb_rx_account_switching_info"
lacks "no foreach() on null in header/usermenu" "$L" "foreach() argument must be of type array"
lacks "no 'Undefined array key' from package module limits" "$L" "Undefined array key"
check "no Severity lines at all from these pages" "$(echo "$L" | grep -c 'Severity')" "0"

echo "== $PASS passed, $FAIL failed =="
restore; trap - EXIT; rm -rf "$T"
[ "$FAIL" -eq 0 ]
