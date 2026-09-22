#!/bin/sh
# Phase 0 regression suite. Runs ONLY against the local staging stack (127.0.0.1:8089),
# never production. Seed data expected (see docs/runbooks/staging.md):
#   users 11 (Tenant A) and 12 (Tenant B), package 90 = module 342 only,
#   KB source 501 owned by user 11, facebook_rx_config row with api_secret 'staging-app-secret'.
set -u
B=${STAGING_URL:-http://127.0.0.1:8089}
APP=${STAGING_APP:-monkeybot-staging-app-1}
T=$(mktemp -d)
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "PASS  $1"; }
bad()  { FAIL=$((FAIL+1)); echo "FAIL  $1  -> $2"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "got '$2' want '$3'"; fi; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

login() { # $1 email -> cookie jar $T/$1
  j="$T/$1"
  tok=$(curl -s -c "$j" "$B/home/login_page" | grep -o 'name="csrf_token"[^>]*value="[^"]*"' | sed 's/.*value="//;s/"$//' | head -1)
  curl -s -b "$j" -c "$j" -o /dev/null -X POST "$B/home/login" \
    --data-urlencode "csrf_token=$tok" --data-urlencode "username=$1" --data-urlencode "password=Staging#Pass1"
}
ajax() { # $1 jar, $2 path, rest = curl data args
  j="$1"; p="$2"; shift 2
  curl -s -b "$j" -H 'X-Requested-With: XMLHttpRequest' -X POST "$B/$p" "$@"
}

echo "== cron endpoints closed over HTTP =="
check "cron_hub/run over HTTP refused"          "$(code "$B/cron_hub/run")" "403"
check "tiktok poll over HTTP refused"           "$(code "$B/tiktok_bot/tiktok_cron/poll_comments")" "404"
check "cron_job/delete_junk_data needs key"     "$(curl -s "$B/cron_job/delete_junk_data")" "API Key is required."
check "cron_job/subscriber_broadcaster needs key" "$(curl -s "$B/cron_job/subscriber_broadcaster")" "API Key is required."
check "cron_job with bogus key refused"         "$(curl -s "$B/cron_job/delete_junk_data/1-bogus")" "API Key does not match with any user."

echo "== scheduler health via CLI =="
docker exec -u www-data "$APP" php /var/www/html/index.php cron_hub run >/dev/null 2>&1
check "/health ok after a CLI tick" "$(curl -s "$B/health" | sed -n 's/.*"status":"\([a-z]*\)".*/\1/p')" "ok"

echo "== KB tenant isolation (IDOR) =="
login b@staging.local
r=$(ajax "$T/b@staging.local" ai_knowledge_base/preview_chunks_action --data-urlencode id=501)
case "$r" in *TENANT-A-CONFIDENTIAL*) bad "tenant B cannot read tenant A chunks" "leaked: $r";; *) ok "tenant B cannot read tenant A chunks";; esac
login a@staging.local
r=$(ajax "$T/a@staging.local" ai_knowledge_base/preview_chunks_action --data-urlencode id=501)
case "$r" in *TENANT-A-CONFIDENTIAL*) ok "tenant A can read own chunks";; *) bad "tenant A can read own chunks" "$r";; esac

echo "== Meta signature (mode from config; run once in log, once in enforce) =="
body='{"object":"page","entry":[{"id":"0","time":1,"messaging":[{"sender":{"id":"1"},"recipient":{"id":"0"},"timestamp":1,"read":{"watermark":1}}]}]}'
sig=$(printf '%s' "$body" | openssl dgst -sha256 -hmac 'staging-app-secret' | sed 's/^.*= //')
MODE=$(docker exec "$APP" php -r 'define("BASEPATH",1); define("FCPATH","/var/www/html/"); define("APPPATH","/var/www/html/application/"); $config=[]; include "/var/www/html/application/config/config.php"; echo $config["meta_webhook_signature_mode"] ?? "log";')
v=$(code -X POST -H "X-Hub-Signature-256: sha256=$sig" -H 'Content-Type: application/json' --data "$body" "$B/home/central_webhook_callback")
i=$(code -X POST -H "X-Hub-Signature-256: sha256=deadbeef" -H 'Content-Type: application/json' --data "$body" "$B/home/central_webhook_callback")
f=$(code -X POST --data-urlencode "response_raw=$body" "$B/messenger_bot/webhook_callback_main")
isig=$(docker exec "$APP" php -r 'define("BASEPATH",1); define("FCPATH","/var/www/html/"); define("APPPATH","/var/www/html/application/"); $config=[]; include "/var/www/html/application/config/config.php"; echo hash_hmac("sha256", $argv[1], $config["encryption_key"]);' "$body")
g=$(code -X POST -H "X-MB-Internal: $isig" --data-urlencode "response_raw=$body" "$B/messenger_bot/webhook_callback_main")
check "correctly signed internal forward accepted ($MODE)" "$g" "200"
check "valid Meta signature accepted ($MODE)" "$v" "200"
if [ "$MODE" = "enforce" ]; then
  check "invalid Meta signature rejected (enforce)" "$i" "403"
  check "forged internal forward rejected (enforce)" "$f" "403"
else
  check "invalid Meta signature not rejected (log)" "$i" "200"
  check "forged internal forward not rejected (log)" "$f" "200"
fi
docker exec "$APP" cat /var/www/html/application/cache/webhook_security.json 2>/dev/null | tr -d '\n ' | cut -c1-300; echo

echo "== entitlements (custom modules) =="
login a@staging.local
c=$(code -b "$T/a@staging.local" "$B/crm")
ENF=$(docker exec "$APP" php -r 'define("BASEPATH",1); define("FCPATH","/var/www/html/"); define("APPPATH","/var/www/html/application/"); $config=[]; include "/var/www/html/application/config/config.php"; echo empty($config["entitlement_enforce_custom_modules"]) ? "off" : "on";')
if [ "$ENF" = "on" ]; then
  loc=$(curl -s -o /dev/null -w '%{redirect_url}' -b "$T/a@staging.local" "$B/crm")
  case "$loc" in *access_forbidden*) ok "member without CRM module is denied (enforce)";; *) bad "member without CRM module is denied" "code=$c loc=$loc";; esac
else
  check "member without CRM module still allowed (log only)" "$c" "200"
fi

echo "== sensitive files =="
for p in vendor/autoload.php composer.lock docker-compose.yml docs/README.md DOKPLOY_SETUP.md; do
  check "/$p denied" "$(code "$B/$p")" "403"
done
check "/documentation/ public" "$(code "$B/documentation/index.html")" "200"

rm -rf "$T"
echo "== $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
