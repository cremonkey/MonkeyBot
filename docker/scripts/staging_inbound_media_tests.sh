#!/bin/sh
# Inbound media (WhatsApp Cloud, Telegram, OpenWA webhooks) HTTP+DB suite. STAGING ONLY, on the
# isolated stack docker-compose.media-test.yml (127.0.0.1:8105). Never the shared staging stack,
# never production. Synthetic data only. No real provider is called: graph.facebook.com and
# api.telegram.org resolve, inside that project's network only, to tests/inbound_media/fake_provider.php.
#
#   docker/scripts/staging_inbound_media_tests.sh --tls "$DIR"   # throwaway CA + cert + php ini into $DIR
#   MEDIA_TLS_DIR="$DIR" docker compose -p mb-media-test -f docker-compose.media-test.yml up -d
#   docker exec -i <db> sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < assets/backup_db/initial_db.sql
#   docker exec -u www-data <app> php /var/www/html/index.php cron_hub migrate
#   STAGING_DB=... STAGING_APP=... docker/scripts/staging_seed.sh
#   docker/scripts/staging_inbound_media_tests.sh
set -u
if [ "${1:-}" = "--tls" ]; then
  D=$2; mkdir -p "$D"; cd "$D" || exit 1
  openssl req -x509 -newkey rsa:2048 -nodes -keyout ca.key -out ca.crt -days 2 -subj "/CN=mb-media-test throwaway CA" 2>/dev/null
  openssl req -newkey rsa:2048 -nodes -keyout server.key -out server.csr -subj "/CN=graph.facebook.com" 2>/dev/null
  printf 'subjectAltName=DNS:graph.facebook.com,DNS:api.telegram.org\n' > san.ext
  openssl x509 -req -in server.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out server.crt -days 2 -extfile san.ext 2>/dev/null
  docker run --rm --entrypoint cat monkeybot-app-ojzrhf-app:latest /etc/ssl/certs/ca-certificates.crt > bundle.pem
  cat ca.crt >> bundle.pem
  printf 'curl.cainfo=/srv/tls/bundle.pem\nopenssl.cafile=/srv/tls/bundle.pem\n' > zz-test-ca.ini
  chmod 644 ./*; echo "tls material in $D"; exit 0
fi

B=${STAGING_URL:-http://127.0.0.1:8105}
DBC=${STAGING_DB:-mb-media-test-db-1}
APP=${STAGING_APP:-mb-media-test-app-1}
PROV=${FAKEPROV_CT:-mb-media-test-fakeprov-1}
T=$(mktemp -d); PASS=0; FAIL=0
ok(){ PASS=$((PASS+1)); echo "PASS  $1"; }
bad(){ FAIL=$((FAIL+1)); echo "FAIL  $1  -> $2"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "got '$2' want '$3'"; fi; }
contains(){ case "$2" in *"$3"*) ok "$1";; *) bad "$1" "'$2' does not contain '$3'";; esac; }
sql(){ docker exec "$DBC" sh -c 'mariadb -N -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "$0"' "$1"; }
login(){ j="$T/$1"; tok=$(curl -s -c "$j" "$B/home/login_page" | grep -o 'name="csrf_token"[^>]*value="[^"]*"' | sed 's/.*value="//;s/"$//' | head -1);
  curl -s -b "$j" -c "$j" -o /dev/null -X POST "$B/home/login" --data-urlencode "csrf_token=$tok" --data-urlencode "username=$1" --data-urlencode "password=Staging#Pass1"; }
tok(){ curl -s -b "$T/$1" "$B/inbox" | grep -o 'var TOKEN = "[^"]*"' | sed 's/.*"\(.*\)"/\1/'; }
post(){ j="$T/$1"; p="$2"; shift 2; curl -s -b "$j" -H 'X-Requested-With: XMLHttpRequest' -X POST "$B/inbox/$p" "$@"; }
jphp(){ docker exec -i "$APP" php -r "\$d=json_decode(stream_get_contents(STDIN),true); $1"; }
provlog(){ docker exec "$PROV" sh -c 'cat /tmp/fakeprov.log 2>/dev/null' ; }
provcount(){ provlog | grep -c "$1"; }
errlog(){ docker exec "$APP" sh -c 'cat /var/www/html/application/logs/log-*.php 2>/dev/null'; }

UID_A=11
WA=9505; TG=9503; OW=9504
WA_SECRET=wa-app-secret-a; TG_SECRET=tg-hook-secret-a; TG_TOKEN='777:tg-token_a'

wa_post(){ # $1 = JSON body; prints "http_code seconds"
  sig=$(printf '%s' "$1" | openssl dgst -sha256 -hmac "$WA_SECRET" | sed 's/.* //')
  curl -s -o "$T/wa_out" -w '%{http_code} %{time_total}' -X POST "$B/whatsapp_bot/webhook/$WA" \
    -H 'Content-Type: application/json' -H "X-Hub-Signature-256: sha256=$sig" --data-binary "$1"; }
wa_msg(){ # $1 wamid, $2 type, $3 inner JSON object
  printf '{"object":"whatsapp_business_account","entry":[{"id":"WABA","changes":[{"field":"messages","value":{"messaging_product":"whatsapp","metadata":{"phone_number_id":"pn-a"},"contacts":[{"profile":{"name":"Cust"},"wa_id":"201000000001"}],"messages":[{"from":"201000000001","id":"%s","timestamp":"1700000000","type":"%s","%s":%s}]}}]}]}' "$1" "$2" "$2" "$3"; }
tg_post(){ curl -s -o "$T/tg_out" -w '%{http_code}' -X POST "$B/telegram_bot/webhook/$TG" -H 'Content-Type: application/json' \
    -H "X-Telegram-Bot-Api-Secret-Token: $TG_SECRET" --data-binary "$1"; }
ow_post(){ curl -s -o "$T/ow_out" -w '%{http_code}' -X POST "$B/openwa_bot/webhook/$OW" -H 'Content-Type: application/json' --data-binary "$1"; }
row(){ sql "SELECT CONCAT_WS(' | ', message_content, IFNULL(media_json,'NULL'), fb_message_id) FROM livechat_messages WHERE user_id=$UID_A AND sender='user' AND fb_message_id='$1'"; }
path_of(){ sql "SELECT JSON_VALUE(media_json,'$.path') FROM livechat_messages WHERE user_id=$UID_A AND fb_message_id='$1' LIMIT 1"; }

echo "== reset + seed channel accounts (synthetic, tenant A=$UID_A) =="
sql "DELETE FROM conversation_events WHERE user_id=$UID_A; DELETE FROM conversations WHERE user_id=$UID_A;
     DELETE FROM customer_identities WHERE user_id=$UID_A; DELETE FROM customers WHERE user_id=$UID_A;
     DELETE FROM livechat_messages WHERE user_id=$UID_A;
     DELETE FROM whatsapp_accounts WHERE id=$WA; DELETE FROM telegram_accounts WHERE id=$TG; DELETE FROM openwa_accounts WHERE id=$OW;
     UPDATE package SET module_ids='342,411' WHERE id=90;
     INSERT INTO whatsapp_accounts (id,user_id,phone_number_id,access_token,app_secret,verify_token,ai_enabled,status) VALUES ($WA,$UID_A,'pn-a','wa-token-a','$WA_SECRET','vt-a','0','1');
     INSERT INTO telegram_accounts (id,user_id,bot_username,bot_token,webhook_secret,ai_enabled,status) VALUES ($TG,$UID_A,'tbot','$TG_TOKEN','$TG_SECRET','0','1');
     INSERT INTO openwa_accounts (id,user_id,base_url,api_key,session_id,status,bot_enabled,ai_enabled) VALUES ($OW,$UID_A,'http://openwa.test','k','sess-a','1','0','0');" >/dev/null
# team_members comes from the optional team-member addon, not the migration set: the list
# endpoint joins it, so a minimal stand-in is created here exactly as staging_inbox_tests.sh does.
sql "CREATE TABLE IF NOT EXISTS team_members (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
       name VARCHAR(150) NOT NULL, email VARCHAR(150) DEFAULT NULL, password VARCHAR(255) DEFAULT NULL) ENGINE=InnoDB" >/dev/null
docker exec "$PROV" sh -c ': > /tmp/fakeprov.log'
docker exec "$APP" sh -c 'rm -f /var/www/html/application/logs/log-*.php'

echo "== before the migration: exactly today's behaviour =="
sql "ALTER TABLE livechat_messages DROP COLUMN IF EXISTS media_json" >/dev/null
r=$(wa_post "$(wa_msg wamid.PRE1 image '{"id":"IMG_PRE","mime_type":"image/jpeg","caption":"pre cap"}')")
check "pre-migration: WA webhook 200" "${r%% *}" "200"
check "pre-migration: WA image logged as [non-text message], no id" "$(sql "SELECT CONCAT(message_content,'|',fb_message_id) FROM livechat_messages WHERE user_id=$UID_A AND platform='wa' AND sender='user' ORDER BY id DESC LIMIT 1")" "[non-text message]|"
check "pre-migration: provider not called" "$(provcount IMG_PRE)" "0"
tg_post '{"update_id":1,"message":{"message_id":1,"chat":{"id":5551},"from":{"first_name":"T"},"photo":[{"file_id":"IMG_TGPRE","file_size":900}]}}' >/dev/null
check "pre-migration: TG photo logged as [non-text message]" "$(sql "SELECT message_content FROM livechat_messages WHERE user_id=$UID_A AND platform='tg' ORDER BY id DESC LIMIT 1")" "[non-text message]"
login a@staging.local; K=$(tok a@staging.local)
CONV_PRE=$(sql "SELECT conversation_id FROM livechat_messages WHERE user_id=$UID_A AND platform='wa' ORDER BY id DESC LIMIT 1")
r=$(post a@staging.local conversation_thread --data-urlencode "csrf_token=$K" -d conversation_id="$CONV_PRE")
check "pre-migration: inbox thread still loads (no media_json in SELECT)" "$(echo "$r" | jphp 'echo $d["status"]."|".var_export(end($d["messages"])["render"]["media_unavailable"],true);')" "1|true"

echo "== migration is idempotent =="
for i in 1 2; do docker exec -i "$DBC" sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < assets/backup_db/migrations/2026-10-01-inbound-media.sql; done
check "media_json column exists after running the migration twice" "$(sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='livechat_messages' AND COLUMN_NAME='media_json'")" "1"

echo "== WhatsApp Cloud =="
r=$(wa_post "$(wa_msg wamid.IMG1 image '{"id":"IMG1","mime_type":"image/jpeg","caption":"see this"}')")
check "WA image: 200" "${r%% *}" "200"
p=$(path_of wamid.IMG1)
contains "WA image: caption kept as text, media_json path stored" "$(row wamid.IMG1)" "see this | {\"type\":\"image\",\"path\":\"upload/inbox_in/"
case "$p" in upload/inbox_in/[0-9][0-9][0-9][0-9]/[0-9][0-9]/${UID_A}_*.jpg) ok "WA image: path upload/inbox_in/YYYY/MM/<tenant>_<hex>.jpg ($p)";; *) bad "WA image path" "$p";; esac
check "WA image: served by nginx as image/jpeg" "$(curl -s -o /dev/null -w '%{http_code} %{content_type}' "$B/$p")" "200 image/jpeg"
check "WA image: lookup + download both used the bearer token" "$(provlog | grep -E 'IMG1' | grep -c 'Bearer wa-token-a')" "2"
contains "WA image: lookup went to the versioned graph URL" "$(provlog | grep '"path":"/v' | head -1)" '"host":"graph.facebook.com"'

before=$(provcount IMG1)
wa_post "$(wa_msg wamid.IMG1 image '{"id":"IMG1","mime_type":"image/jpeg","caption":"see this"}')" >/dev/null
check "WA retry (same wamid): still one row" "$(sql "SELECT COUNT(*) FROM livechat_messages WHERE fb_message_id='wamid.IMG1'")" "1"
check "WA retry: provider not called again" "$(provcount IMG1)" "$before"

wa_post "$(wa_msg wamid.VOICE1 audio '{"id":"VOICE1","mime_type":"audio/ogg; codecs=opus","voice":true}')" >/dev/null
contains "WA voice note: [voice note] + audio stored as .ogg" "$(row wamid.VOICE1)" '[voice note] | {"type":"audio","path":"upload/inbox_in/'
case "$(path_of wamid.VOICE1)" in *.ogg) ok "WA voice note: .ogg";; *) bad "WA voice ext" "$(path_of wamid.VOICE1)";; esac

wa_post "$(wa_msg wamid.DOC1 document '{"id":"DOC1","mime_type":"application/pdf","filename":"../../evil.php"}')" >/dev/null
case "$(path_of wamid.DOC1)" in upload/inbox_in/*/${UID_A}_*.pdf) ok "WA document: stored as .pdf, sender filename ignored";; *) bad "WA doc path" "$(path_of wamid.DOC1)";; esac
check "WA document: no file named after the sender anywhere" "$(docker exec "$APP" sh -c 'find /var/www/html/upload -name "*evil*" | wc -l')" "0"

wa_post "$(wa_msg wamid.VID1 video '{"id":"VID1","mime_type":"video/mp4"}')" >/dev/null
contains "WA video: stored" "$(row wamid.VID1)" '[video] | {"type":"video","path":"upload/inbox_in/'

wa_post "$(wa_msg wamid.EXE1 document '{"id":"EXE1","mime_type":"application/pdf","filename":"x.pdf"}')" >/dev/null
check "WA executable disguised as pdf: [non-text message] + not-stored marker" "$(row wamid.EXE1)" '[non-text message] | {"type":"file","path":null} | wamid.EXE1'
check "WA executable: nothing written to disk" "$(docker exec "$APP" sh -c 'grep -rl ELF /var/www/html/upload/inbox_in 2>/dev/null | wc -l')" "0"
contains "WA executable: logged at error level" "$(errlog | grep 'inbound_media: wa' | grep 'type not allowed')" "ERROR"

wa_post "$(wa_msg wamid.FAIL1 image '{"id":"FAIL1","mime_type":"image/jpeg","caption":"cap kept"}')" >/dev/null
check "WA provider 500 with caption: caption + not-stored marker" "$(row wamid.FAIL1)" 'cap kept | {"type":"image","path":null} | wamid.FAIL1'
wa_post "$(wa_msg wamid.BIG1 video '{"id":"BIG1","mime_type":"video/mp4"}')" >/dev/null
check "WA over 16 MB: refused" "$(row wamid.BIG1)" '[non-text message] | {"type":"video","path":null} | wamid.BIG1'
check "WA over 16 MB: never downloaded" "$(provcount '/fakecdn/BIG1')" "0"
r=$(wa_post "$(wa_msg wamid.SLOW1 image '{"id":"SLOW1","mime_type":"image/jpeg"}')")
secs=${r#* }; secs=${secs%%.*}
check "WA slow provider: webhook still 200" "${r%% *}" "200"
if [ "$secs" -lt 12 ]; then ok "WA slow provider: answered in ${secs}s (timeout 8s, provider sleeps 20s)"; else bad "WA slow provider response time" "${secs}s"; fi
check "WA slow provider: [non-text message]" "$(row wamid.SLOW1 | cut -d'|' -f1 | sed 's/ $//')" "[non-text message]"
contains "WA errors never log the token" "$(errlog | grep -c wa-token-a)" "0"

wa_post "$(printf '{"object":"whatsapp_business_account","entry":[{"changes":[{"value":{"messages":[{"from":"201000000001","id":"wamid.TXT1","type":"text","text":{"body":"plain hello"}}]}}]}]}')" >/dev/null
check "WA text unchanged: no media_json, no id (as before)" "$(sql "SELECT CONCAT(message_content,'|',IFNULL(media_json,'NULL'),'|',fb_message_id) FROM livechat_messages WHERE user_id=$UID_A AND message_content='plain hello'")" "plain hello|NULL|"

echo "== Telegram =="
tg_post '{"update_id":10,"message":{"message_id":21,"chat":{"id":5551},"from":{"first_name":"T"},"caption":"tg cap","photo":[{"file_id":"IMG_small","file_size":100},{"file_id":"IMG_big","file_size":900}]}}' >/dev/null
contains "TG photo: caption + image stored" "$(row 5551:21)" 'tg cap | {"type":"image","path":"upload/inbox_in/'
check "TG photo: largest size fetched" "$(provcount 'IMG_big')" "2"
check "TG photo: smaller size not fetched" "$(provcount 'IMG_small')" "0"
p=$(path_of 5551:21); check "TG photo served" "$([ -n "$p" ] && [ "$p" != NULL ] && curl -s -o /dev/null -w '%{http_code}' "$B/$p")" "200"
tg_post '{"update_id":10,"message":{"message_id":21,"chat":{"id":5551},"from":{"first_name":"T"},"caption":"tg cap","photo":[{"file_id":"IMG_small","file_size":100},{"file_id":"IMG_big","file_size":900}]}}' >/dev/null
check "TG retry: still one row" "$(sql "SELECT COUNT(*) FROM livechat_messages WHERE fb_message_id='5551:21'")" "1"
tg_post '{"update_id":11,"message":{"message_id":22,"chat":{"id":5551},"voice":{"file_id":"VOICE_tg","mime_type":"audio/ogg","file_size":400}}}' >/dev/null
contains "TG voice: [voice note] + audio" "$(row 5551:22)" '[voice note] | {"type":"audio","path":"upload/inbox_in/'
tg_post '{"update_id":12,"message":{"message_id":23,"chat":{"id":5551},"document":{"file_id":"DOC_tg","file_name":"a.phtml","mime_type":"application/pdf"}}}' >/dev/null
case "$(path_of 5551:23)" in *.pdf) ok "TG document: .pdf from bytes (sender name a.phtml ignored)";; *) bad "TG doc" "$(path_of 5551:23)";; esac
tg_post '{"update_id":13,"message":{"message_id":24,"chat":{"id":5551},"document":{"file_id":"TRAV_tg","file_name":"x.pdf"}}}' >/dev/null
check "TG traversal file_path: refused, no download" "$(row 5551:24)" '[non-text message] | {"type":"file","path":null} | 5551:24'
check "TG traversal: no /file/ request" "$(provlog | grep -c 'etc/passwd')" "0"
tg_post '{"update_id":14,"message":{"message_id":25,"chat":{"id":5551},"document":{"file_id":"FAIL_tg"}}}' >/dev/null
check "TG getFile failure: [non-text message]" "$(row 5551:25 | cut -d'|' -f1 | sed 's/ $//')" "[non-text message]"
check "TG errors never log the bot token" "$(errlog | grep -c 'tg-token_a')" "0"
tg_post '{"update_id":15,"message":{"message_id":26,"chat":{"id":5551},"text":"tg text"}}' >/dev/null
check "TG text unchanged" "$(sql "SELECT CONCAT(IFNULL(media_json,'NULL'),'|',fb_message_id) FROM livechat_messages WHERE message_content='tg text'")" "NULL|"

echo "== OpenWA (gateway forwards no file) =="
check "OW image with caption: 200" "$(ow_post '{"event":"message.received","sessionId":"sess-a","data":{"id":"OWID1","from":"201000000002@c.us","chatId":"201000000002@c.us","fromMe":false,"isGroup":false,"body":"ow cap","type":"imageMessage","contact":{"number":"201000000002"}}}')" "200"
check "OW image: caption + not-stored marker" "$(row OWID1)" 'ow cap | {"type":"image","path":null} | OWID1'
ow_post '{"event":"message.received","sessionId":"sess-a","data":{"id":"OWID2","from":"201000000002@c.us","fromMe":false,"isGroup":false,"body":"","type":"pttMessage"}}' >/dev/null
check "OW voice note, no caption: [non-text] + marker" "$(row OWID2)" '[non-text] | {"type":"audio","path":null} | OWID2'
ow_post '{"event":"message.received","sessionId":"sess-a","data":{"id":"OWID2","from":"201000000002@c.us","fromMe":false,"isGroup":false,"body":"","type":"pttMessage"}}' >/dev/null
check "OW redelivery: still one row" "$(sql "SELECT COUNT(*) FROM livechat_messages WHERE fb_message_id='OWID2'")" "1"

echo "== Unified Inbox renders the stored media =="
CONV=$(sql "SELECT conversation_id FROM livechat_messages WHERE fb_message_id='wamid.IMG1'")
r=$(post a@staging.local conversation_thread --data-urlencode "csrf_token=$K" -d conversation_id="$CONV" -d limit=100)
kinds=$(echo "$r" | jphp 'foreach($d["messages"] as $m){ foreach($m["render"]["attachments"] as $a) echo $a["type"],","; } ')
check "WA thread: image, audio, file, video attachments in order" "$kinds" "image,audio,file,video,"
check "WA thread: image URL is base_url + stored path" "$(echo "$r" | jphp 'foreach($d["messages"] as $m){ if($m["render"]["attachments"]){ echo $m["render"]["attachments"][0]["url"]; break; } }')" "https://bot.cremonkey.com/$(path_of wamid.IMG1)"
check "WA thread: caption shown as text next to the image" "$(echo "$r" | jphp 'foreach($d["messages"] as $m){ if($m["render"]["attachments"]){ echo $m["render"]["text"]; break; } }')" "see this"
check "WA thread: [voice note] placeholder not repeated as text" "$(echo "$r" | jphp 'foreach($d["messages"] as $m){ if($m["message_content"]==="[voice note]") echo "[".$m["render"]["text"]."]"; }')" "[]"
check "WA thread: failed downloads flagged unavailable" "$(echo "$r" | jphp '$n=0; foreach($d["messages"] as $m){ if($m["render"]["media_unavailable"]) $n++; } echo $n;')" "5"
check "WA thread: media_json itself not exposed" "$(echo "$r" | grep -c media_json)" "0"
r=$(post a@staging.local conversations_data --data-urlencode "csrf_token=$K" -d platform=tg)
contains "TG list preview is readable text" "$(echo "$r" | jphp 'foreach($d["rows"] as $x) echo $x["last_message_preview"],";";')" "tg text"

echo
echo "$PASS passed, $FAIL failed"
rm -rf "$T"
[ "$FAIL" -eq 0 ]
