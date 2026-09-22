#!/usr/bin/env bash
# Nightly MonkeyBot backup: full DB dump + application/config volume (holds encryption_key).
# Fails loudly: a dump that is empty, truncated or missing its completion marker exits non-zero
# and is deleted, so a bad night never looks like a good one.
# Weekly (Sunday) it also restores the fresh dump into a throwaway MariaDB container and
# compares table counts with the live DB.  Runbook: docs/runbooks/backup-and-restore.md
set -euo pipefail
DB=monkeybot-app-ojzrhf-db-1
DEST_ROOT=/root/backups/monkeybot-nightly
STAMP=$(date +%Y%m%d-%H%M%S)
DEST="$DEST_ROOT/$STAMP"
KEEP_DAYS=14
log(){ echo "[$(date -Is)] $*"; }
fail(){ log "BACKUP FAILED: $*"; rm -rf "$DEST"; exit 1; }

mkdir -p "$DEST"; chmod 700 "$DEST_ROOT" "$DEST"

docker exec "$DB" sh -c 'exec mariadb-dump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --quick \
  --routines --triggers --default-character-set=utf8mb4 "$MYSQL_DATABASE"' | gzip > "$DEST/db.sql.gz" \
  || fail "mariadb-dump exited non-zero"
gunzip -t "$DEST/db.sql.gz" || fail "gzip stream is corrupt"
zcat "$DEST/db.sql.gz" | tail -n 1 | grep -c "^-- Dump completed" >/dev/null || fail "dump has no completion marker (truncated)"
SIZE=$(stat -c %s "$DEST/db.sql.gz")
[ "$SIZE" -gt 1000000 ] || fail "dump is only $SIZE bytes (expected several MB)"

docker run --rm -v monkeybot_app_config:/src:ro -v "$DEST":/dst alpine \
  tar czf /dst/config-volume.tar.gz -C /src . || fail "config volume archive failed"
tar tzf "$DEST/config-volume.tar.gz" | grep -c "config.php" >/dev/null || fail "config archive has no config.php"

if [ "$(date +%u)" = "7" ] || [ "${FORCE_RESTORE_TEST:-0}" = "1" ]; then
  RT=monkeybot-restore-test-$STAMP
  trap 'docker rm -f "$RT" >/dev/null 2>&1 || true' EXIT
  docker run -d --name "$RT" --tmpfs /var/lib/mysql:rw,size=3g -e MARIADB_ROOT_PASSWORD=restoretest \
    -e MARIADB_DATABASE=r mariadb:10.11 --character-set-server=utf8mb4 >/dev/null
  for i in $(seq 1 60); do docker exec "$RT" mariadb -uroot -prestoretest -e 'SELECT 1' r >/dev/null 2>&1 && break; sleep 2; done
  zcat "$DEST/db.sql.gz" | docker exec -i "$RT" mariadb -uroot -prestoretest --default-character-set=utf8mb4 r \
    || fail "restore test: import failed"
  LIVE=$(docker exec "$DB" sh -c 'mariadb -N -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=\"$MYSQL_DATABASE\""')
  REST=$(docker exec "$RT" mariadb -N -uroot -prestoretest -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='r'")
  [ "$LIVE" = "$REST" ] || fail "restore test: live has $LIVE tables, restore has $REST"
  docker rm -f "$RT" >/dev/null; trap - EXIT
  echo "$STAMP tables=$REST" > "$DEST/restore-test-ok"
  log "restore test ok: $REST tables"
fi

find "$DEST_ROOT" -mindepth 1 -maxdepth 1 -type d -mtime +$KEEP_DAYS -exec rm -rf {} +
log "backup ok: $DEST ($(du -sh "$DEST" | cut -f1))"
