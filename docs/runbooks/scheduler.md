# Runbook: Scheduler

**Status:** Verified Live since 2026-09-22 15:10 (the first scheduled tick ran and printed its JSON with 0 sends).

## How it runs

The host file `/etc/cron.d/monkeybot` holds the schedule. The source copy is `docker/cron/monkeybot` in the repo.

| Every | Command (inside `monkeybot-app-ojzrhf-app-1`, as `www-data`) | Host lock |
|---|---|---|
| 5 min | `timeout 280 php /var/www/html/index.php cron_hub run` | `/run/monkeybot-cron-hub.lock` |
| 5 min | `timeout 280 php /var/www/html/index.php tiktok_bot/tiktok_cron poll_comments` | `/run/monkeybot-tiktok.lock` |

Each tick is protected three ways:

- `flock -n` on the host, so a slow tick is never stacked on top of another one.
- `Cron_hub::run` takes the MariaDB advisory lock `GET_LOCK('monkeybot_cron_hub', 0)`. If another run holds it, the tick prints `{"skipped":"another run in progress"}` and exits.
- `timeout 280` kills a stuck run before the next tick is due.

### What `cron_hub run` does, in order

1. Follow-ups: silent-lead follow-ups, inside Meta's 24h window.
2. AI coupon reminders.
3. Daily digest, by email or WhatsApp.
4. Re-engage campaign sending.
5. External CRM queue: 8xCRM push from `crm_external_log`.
6. Missed-question (deflect) alerts.
7. Retention (see the warning below).

### Not scheduled

These legacy jobs are **not** in the cron file: the `cron_job/*` methods (drip sequences, broadcasts, `lead_score_decay`, abandoned-cart reminders, membership alerts, posting, SMS and email sending, `expired_users_disable_bot`). See the baseline finding F-05. Before you add one, count its pending backlog, because the first run sends everything that is queued.

TikTok `poll_dms` is stubbed and also not scheduled.

## Access control

| Path | Rule |
|---|---|
| CLI (`is_cli()`) | Always allowed. |
| HTTP to `cron_hub/run` and `tiktok_bot/tiktok_cron/*` | Allowed only when config `cron_secret` is set **and** `?key=` matches it. When `cron_secret` is unset (today), every HTTP call gets 403. |
| HTTP to `cron_job/*` | Needs a valid `native_api` key that belongs to an active Admin user. |
| Sales Automation "Run now" | Runs `php index.php cron_hub run` through `shell_exec` (CLI). It needs no secret. |

The HTTP refusal has been implemented but **not yet verified with a live request**.

## Logs and health

| Where | What |
|---|---|
| `/var/log/monkeybot-cron.log` (host) | One JSON line per `cron_hub` tick. `No active comment campaigns.` lines come from the TikTok poll. |
| `application/cache/ops_health.json` (volume `monkeybot_app_cache`) | Written on every `cron_hub` run: last run, duration, result, last failure. |
| `/health` | Returns 200 `ok`, 200 `degraded` when the last successful `cron_hub` run is more than 15 minutes old, or 503 `down` when the DB is down. In progress: not yet verified. |
| Admin: System health | Shows jobs, webhook counters and the switches. In progress. |

Useful commands:

```sh
tail -n 20 /var/log/monkeybot-cron.log
docker exec monkeybot-app-ojzrhf-app-1 cat /var/www/html/application/cache/ops_health.json
```

A healthy tick looks like this:

```
{"followups":{"sent":0,...},"external_crm":{...},"retention":{"nothing_to_prune":true}}
```

An error tick prints `{"error":"..."}`. It is also recorded as a failure in `ops_health.json` and in the CodeIgniter error log.

## Run a tick manually

Always use the CLI. Always run as `www-data`, so that files written to cache and logs stay writable by PHP-FPM.

```sh
docker exec -u www-data monkeybot-app-ojzrhf-app-1 php /var/www/html/index.php cron_hub run
docker exec -u www-data monkeybot-app-ojzrhf-app-1 php /var/www/html/index.php tiktok_bot/tiktok_cron poll_comments
```

A manual tick is a **real** tick. It sends real messages and runs retention. It cannot overlap a scheduled tick, because of the DB lock.

Other CLI helpers on `Cron_hub`:

| Command | Purpose | Warning |
|---|---|---|
| `migrate` | Apply the SQL files in `assets/backup_db/migrations/` | Take a backup first. |
| `kb_probe` | Knowledge-base probe | |
| `rechunk_kb <source_id>` | Re-chunk one KB source | |
| `encrypt_crm_secrets` | Encrypt stored CRM secrets | |

## Pause the scheduler

To stop all ticks without touching the app:

```sh
sudo mv /etc/cron.d/monkeybot /root/monkeybot.cron.paused
```

To resume, copy it back. The file must be owned by root with mode 644.

```sh
sudo cp /root/monkeybot.cron.paused /etc/cron.d/monkeybot && sudo chmod 644 /etc/cron.d/monkeybot
```

To pause only one job, comment out its line in `/etc/cron.d/monkeybot`. cron re-reads `/etc/cron.d` by itself.

To stop a tick that is running now, stop its `docker exec` process on the host. Alternatively, leave it alone: `timeout 280` ends it.

While the scheduler is paused, `/health` reports `degraded` after 15 minutes. This is expected.

## Retention warning

Retention runs on **every** tick. The code comment says "hourly", but that is not what happens. Each tick deletes up to 5,000 rows per table that are older than:

| Table | Age limit |
|---|---|
| `livechat_messages` | 120 days (`conversation_time`) |
| `ai_conversation_history` | 60 days |
| `ai_usage_log` | 120 days |
| `ai_price_guard_log` | 120 days |
| `ai_reply_audit` | 180 days |
| `ai_deflect_alert_log` | 180 days |
| `crm_external_log` | 30 days (rows with status sent or failed only) |

**These deletes cannot be undone except from a backup.**

On 2026-09-22 an audit GET to the then-open `/cron_hub/run` ran a tick. Retention has since removed about 10,000 `ai_conversation_history` rows. The first 5,000 predate the backup at `/root/backups/monkeybot-cron-20260922-145956/`.

Before you change a retention window, or restore the scheduler after a long pause, take a backup (see `backup-and-restore.md`) and get the owner's sign-off.

## Installing on a new host

```sh
sudo cp docker/cron/monkeybot /etc/cron.d/monkeybot && sudo chmod 644 /etc/cron.d/monkeybot
```

Check that the container name in the file matches `docker ps`. The old file targeted a container that does not exist, which is why no job ran before 2026-09-22.
