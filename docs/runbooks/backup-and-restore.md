# Runbook: Backup and Restore

**Status: automated nightly since 2026-09-23.**

| What | Where | When |
|---|---|---|
| Full DB dump + `application/config` volume | `/root/backups/monkeybot-nightly/<YYYYMMDD-HHMMSS>/` (`db.sql.gz`, `config-volume.tar.gz`) | 03:37 daily, kept 14 days |
| Restore test (fresh dump into a throwaway `mariadb:10.11` container on tmpfs, table count must equal live) | marker file `restore-test-ok` in that night's directory | Sundays (force any time with `FORCE_RESTORE_TEST=1`) |
| Log | `/var/log/monkeybot-backup.log` — every run ends in `backup ok:` or `BACKUP FAILED:` | |

The script runs from **`/usr/local/bin/monkeybot-backup.sh`**, scheduled by **`/etc/cron.d/monkeybot-ops`**.
The copies in `docker/ops/` are for review only — editing them changes nothing until they are copied
to those paths. The script fails (exit 1, directory removed) on a non-zero dump, a corrupt gzip, a
missing `-- Dump completed` marker, a dump under 1 MB, or a config archive without `config.php`,
so a bad night cannot look like a good one.

Uptime: `/usr/local/bin/monkeybot-health-watch.sh` probes `https://bot.cremonkey.com/health`
every 5 minutes through Traefik and logs each state change (and each failed probe) to
`/var/log/monkeybot-health.log`. It is on the same host, so it does not replace an
off-host monitor (it cannot report the host itself being down).

Older one-off backups (before migrations and deploys) sit next to it under `/root/backups/`,
named `monkeybot-<reason>-<YYYYMMDD-HHMMSS>`.

Backups live under `/root/backups/`, one directory per backup, named `monkeybot-<reason>-<YYYYMMDD-HHMMSS>`. That directory is on the same host as production. This is not an off-site copy.

## What needs backing up

| Asset | Where | Why |
|---|---|---|
| Database | Volume `monkeybot_db_data` (container `monkeybot-app-ojzrhf-db-1`) | All tenant data |
| Config | Volume `monkeybot_app_config` (`application/config`) | Live config, including the real `encryption_key`. It is **not** in git. |
| Uploads | Volumes `monkeybot_app_upload`, `monkeybot_app_upload_caster`, `monkeybot_app_download` | Media and user files |
| Env | `/root/monkeybot/.env` | DB credentials, base URL |
| Code | Git branch, plus the working tree | Uncommitted work exists (see baseline G-01) |

**The config volume is critical.** Stored API keys are encrypted with `encryption_key` (`enc::` values). If you restore the DB without the matching config, those keys cannot be decrypted.

## Take a backup

Run these commands as root on the host. Credentials come from the db container's own environment. Never type or paste passwords on the command line.

```sh
TS=$(date +%Y%m%d-%H%M%S)
DEST=/root/backups/monkeybot-manual-$TS
mkdir -p "$DEST" && chmod 700 "$DEST"

# 1. Database (consistent InnoDB snapshot, utf8mb4, routines/triggers included)
docker exec monkeybot-app-ojzrhf-db-1 sh -c \
  'exec mariadb-dump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --quick \
   --routines --triggers --default-character-set=utf8mb4 "$MYSQL_DATABASE"' \
  | gzip > "$DEST/db.sql.gz"

# 2. Config volume
docker run --rm -v monkeybot_app_config:/src:ro -v "$DEST":/dst alpine \
  tar czf /dst/config-volume.tar.gz -C /src .

# 3. (optional, larger) uploads
docker run --rm -v monkeybot_app_upload:/src:ro -v "$DEST":/dst alpine \
  tar czf /dst/upload-volume.tar.gz -C /src .

# 4. Sanity check
gunzip -t "$DEST/db.sql.gz" && zcat "$DEST/db.sql.gz" | tail -n 1   # expect "-- Dump completed"
ls -la "$DEST"
```

Notes on the commands:

- The single quotes in step 1 make `$MYSQL_ROOT_PASSWORD` and `$MYSQL_DATABASE` expand **inside** the container. Confirm the variable names against the key names in `/root/monkeybot/.env` (the MYSQL_* keys), but do not print their values.
- The server runs with `--character-set-server=utf8`. `--default-character-set=utf8mb4` makes the dump keep 4-byte characters (emoji, some Arabic presentation forms) intact.
- Take a backup before each of these:
  - `cron_hub migrate`
  - any change to a retention window
  - re-enabling the scheduler after a pause
  - enabling a legacy `cron_job/*` job
  - any deploy that touches the schema

## Restore

Restoring overwrites live data. **Stop the scheduler first**, so that no tick writes during the restore.

```sh
sudo mv /etc/cron.d/monkeybot /root/monkeybot.cron.paused
SRC=/root/backups/<backup-dir>
```

### Rehearse in a disposable database (always do this first)

```sh
docker exec monkeybot-app-ojzrhf-db-1 sh -c \
  'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE restore_test CHARACTER SET utf8mb4"'
zcat "$SRC/db.sql.gz" | docker exec -i monkeybot-app-ojzrhf-db-1 sh -c \
  'exec mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" --default-character-set=utf8mb4 restore_test'
# compare row counts of key tables against the live DB, then:
docker exec monkeybot-app-ojzrhf-db-1 sh -c \
  'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE restore_test"'
```

### Restore production (only with the owner's approval)

```sh
# take a fresh backup of the current state first (see above)
zcat "$SRC/db.sql.gz" | docker exec -i monkeybot-app-ojzrhf-db-1 sh -c \
  'exec mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" --default-character-set=utf8mb4 "$MYSQL_DATABASE"'

# config volume (only if config is lost or corrupted)
docker run --rm -v monkeybot_app_config:/dst -v "$SRC":/src:ro alpine \
  sh -c 'tar xzf /src/config-volume.tar.gz -C /dst'
docker restart monkeybot-app-ojzrhf-app-1
```

### After the restore

1. Log in.
2. Open one Messenger conversation.
3. Check `/health`.
4. Check that `schema_migrations` matches the files in `assets/backup_db/migrations/`.
5. Resume the scheduler: `sudo cp /root/monkeybot.cron.paused /etc/cron.d/monkeybot && sudo chmod 644 /etc/cron.d/monkeybot`.

## Recommended: automate it (open action)

1. **Nightly script.** Put a script at `/root/monkeybot-ops/backup.sh` that runs steps 1, 2 and 4 above. It should:
   - write to `/root/backups/monkeybot-nightly-<ts>`;
   - keep 14 daily and 8 weekly copies.
2. **Keep the script out of the repo.** Cron should run the script from outside the repo tree, and the repo copy should be documentation only.
3. **Never let a failure look like success.** The script must:
   - `set -euo pipefail`;
   - check that the dump ends with `-- Dump completed`;
   - check that the file is not 0 bytes;
   - exit non-zero on any failure, and write a status JSON that `/health` or System health can show.
4. **Off-site copy.** Copy each backup to storage off the host (object storage or a second server), encrypted.
5. **Restore test.** Once a month, restore into a disposable DB and compare row counts.
