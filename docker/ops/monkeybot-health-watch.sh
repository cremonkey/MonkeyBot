#!/usr/bin/env bash
# Every 5 minutes: probe https://bot.cremonkey.com/health from outside the containers
# (through Traefik, like a customer). Logs every state CHANGE (ok -> degraded/down and back)
# plus a line per failed probe, so an outage has a start and end time in
# /var/log/monkeybot-health.log. Last state lives in /var/lib/monkeybot-health.state.
set -u
URL=https://bot.cremonkey.com/health
STATE=/var/lib/monkeybot-health.state
body=$(curl -s -m 20 "$URL" 2>/dev/null) || body=''
status=$(printf '%s' "$body" | grep -o '"status":"[a-z]*"' | head -1 | cut -d'"' -f4)
[ -n "$status" ] || status=down
prev=$(cat "$STATE" 2>/dev/null || echo unknown)
if [ "$status" != "$prev" ]; then
  echo "[$(date -Is)] STATE $prev -> $status  ${body:0:300}"
  echo "$status" > "$STATE"
elif [ "$status" != "ok" ]; then
  echo "[$(date -Is)] still $status  ${body:0:300}"
fi
