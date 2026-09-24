# Runbook: Deployment

**Owner-run.** This procedure deploys to production. It requires access to the Dokploy host and the
production containers. It is not something a coding agent runs — agents build and verify branches;
the owner merges and deploys them following this runbook.

## Current reality (2026-09-25)

| Fact | Consequence |
|---|---|
| Production code is a bind-mounted git checkout at `/etc/dokploy/compose/monkeybot-app-ojzrhf/code`, currently on branch `chore/commit-live-work-20260922`. It is mounted read-write into the `app` container and read-only into `web` as `/var/www/html`. There is no build step. | **Every file save in that directory is a production deploy.** A PHP syntax error takes the affected pages down at once. Deploying means merging into that checkout, not pushing to trigger a pipeline. |
| The repo root is the web root. | Every new file in the repo is publicly served unless nginx denies it. Internal material stays under `docs/`, `docker/`, `.specs/` or another path nginx already denies. |
| `application/config` is a Docker volume (`monkeybot_app_config`) in production, separate from the git tree. | **The repo's `application/config/*.php` never reaches production.** The live config only exists in the volume. A `git merge` into the production checkout does not change config; the local config volume's diff from the repo's tracked defaults must be preserved separately (see steps below). |
| `application/cache`, `application/logs`, `upload`, `upload_caster`, `download` are also external Docker volumes shadowing the repo copies. | Same as config: files under these paths in the repo are cosmetic; the real state lives in the volumes. |
| Containers: `monkeybot-app-ojzrhf-app-1`, `monkeybot-app-ojzrhf-web-1`, `monkeybot-app-ojzrhf-db-1`. | These are production. No test traffic, no destructive commands, no restarts outside this procedure. |
| nginx config `docker/nginx/default.conf` is a single-file bind mount. | Editors/`sed -i` replace the file's inode; the container keeps the old one until `web` is restarted. After any nginx change: `docker restart monkeybot-app-ojzrhf-web-1`, then confirm with `docker exec monkeybot-app-ojzrhf-web-1 cat /etc/nginx/conf.d/default.conf`. |
| Schema changes are SQL files in `assets/backup_db/migrations/`, applied only by `php index.php cron_hub migrate` inside the app container, tracked in `schema_migrations`. | Never change the schema by hand. Some columns were added by hand historically (`hours_*`, `openwa_channel` is not recorded) — known drift, not a pattern to repeat. |
| Nightly backups run from `/root/backups/monkeybot-nightly` (cron on the host, `/usr/local/bin/monkeybot-backup.sh` via `/etc/cron.d/monkeybot-ops`), independent of any git worktree. | A backup exists from the previous night regardless of this deploy. Still take a fresh one for tables you're about to touch — see step 2 below. |
| `/health` reports a version and is polled by an on-host 5-minute watcher. | Use it as the go/no-go signal after every deploy. |

## Deploy procedure

This assumes code has already been reviewed and merged into `release/9.x-integration` in a worktree
(never in the production checkout directly) and is ready to ship.

### 1. Before: know what you're about to change

- `git -C /etc/dokploy/compose/monkeybot-app-ojzrhf/code status` — confirm exactly what's dirty in
  the production checkout right now. Other sessions may have live-edited files there; never
  `git add -A` or `git checkout -- .` blind. Note anything uncommitted before you touch it.
- Confirm which tables the incoming change's migrations (if any) touch.

### 2. Back up affected tables

Before merging anything that includes a migration or changes data-bearing code, take a targeted
backup of the tables involved, in addition to relying on the nightly backup in
`/root/backups/monkeybot-nightly`:

```sh
docker exec monkeybot-app-ojzrhf-db-1 mysqldump -u<user> -p<pass> <db> <table1> <table2> \
  > /root/backups/monkeybot-nightly/pre-deploy-$(date +%Y%m%d-%H%M%S)-<short-desc>.sql
```

Never print the credentials in a command that gets logged/echoed; use a credentials file or env var
already present on the host rather than typing the password inline where it can be captured.

### 3. Set aside the local `config.php` diff

`application/config/config.php` (and any other config file) in the production **checkout** may carry
a local diff from what's tracked in `release/9.x-integration` — this is expected, because the live
config actually served comes from the `monkeybot_app_config` volume, and the checkout's copy may have
been hand-edited to mirror it (secrets replaced with placeholders per the config-volume convention).

```sh
cd /etc/dokploy/compose/monkeybot-app-ojzrhf/code
git diff application/config/ > /tmp/config-diff-pre-deploy.patch   # keep this off the repo, off web root
```

If the diff is empty, note that and move on.

### 4. Merge `release/9.x-integration`

```sh
cd /etc/dokploy/compose/monkeybot-app-ojzrhf/code
git fetch origin  # or the relevant remote/worktree source
git merge release/9.x-integration
```

Resolve conflicts favoring the integration branch for application code; config files are handled
separately in the next step, so if config.php conflicts, take either side and move to step 5 anyway.

### 5. Re-apply the config diff

```sh
git apply /tmp/config-diff-pre-deploy.patch   # if step 3's diff was non-empty
```

If the merge changed config.php upstream in a way that conflicts with the local diff, resolve by
hand: the volume-served values win for anything that's actually a secret/environment value; take the
new tracked defaults for anything genuinely new from `release/9.x-integration`. When in doubt, diff
the resulting file against what's live in the `monkeybot_app_config` volume
(`docker exec monkeybot-app-ojzrhf-app-1 cat /var/www/html/application/config/config.php`, or whatever
path the volume mounts to) and reconcile by hand — the checkout copy is documentation of the volume,
it is never authoritative over it.

### 6. Lint changed files

```sh
git diff --name-only ORIG_HEAD HEAD -- '*.php' | while read -r f; do
  docker exec monkeybot-app-ojzrhf-app-1 php -l "/var/www/html/$f"
done
```

Fix any syntax error before proceeding — the merge already landed live, so a broken file is already
serving errors. Linting after the save still leaves a window where the file was broken; for large
changes prefer writing under a temp name, linting, then `mv` into place next time, but for a merge
this is typically fast enough that the lint-after-merge window is acceptable.

### 7. Migrations, if the incoming code needs them

**Run migrations BEFORE the code that needs them goes live**, not after, or requests will hit new
code expecting a table/column that doesn't exist yet. In practice this means: if step 4's merge
includes both a migration and code that depends on it, run the migration immediately after the merge
completes and before considering the deploy done — do not leave code and schema out of sync even
briefly if you can help it. For this reason, the plan's other tasks are also expected to guard new
tables/columns with `table_exists()`/`field_exists()` checks so the two orders are both survivable.

```sh
docker exec -u www-data monkeybot-app-ojzrhf-app-1 php /var/www/html/index.php cron_hub migrate
```

This is owner-run only — no coding agent runs `cron_hub migrate` against production under any
circumstance (see `docs/plans/2026-09-24-remaining-work.md` §2 rule 1).

### 8. Config volume changes

If the deploy also requires a change to the live config (not just the checkout's mirror of it), write
it into the `monkeybot_app_config` volume directly — editing the repo copy alone does nothing live:

```sh
docker exec monkeybot-app-ojzrhf-app-1 sh -c 'cat > /var/www/html/application/config/<file>.php' < local-edited-file.php
```

### 9. nginx changes, if any

If this deploy touches `docker/nginx/default.conf`, restart `web` after the merge so the container
picks up the new inode:

```sh
docker restart monkeybot-app-ojzrhf-web-1
docker exec monkeybot-app-ojzrhf-web-1 cat /etc/nginx/conf.d/default.conf   # confirm it's the new content
```

### 10. Verify

- `curl -s -o /dev/null -w '%{http_code}\n' https://<host>/health` — must show `ok` and the new
  version within a few minutes (allow for the health watcher's own 5-minute cadence before treating a
  stale reading as a failure).
- Smoke pages: `/` returns 200; `/home/login_page` returns 200; log in and open Livechat; trigger one
  bot reply on a test page/conversation; confirm the Unified Inbox loads.
- Check `application/logs` (volume `monkeybot_app_logs`) for new errors since the deploy timestamp.
- If a scheduler-affecting change shipped, watch the next tick in `/var/log/monkeybot-cron.log`.
- Record the result plainly: "Verified" only with the evidence above attached; otherwise "Implemented
  but not tested" (brief §58) — never claim success without having actually checked `/health` and the
  smoke pages.

### 11. Commit

```sh
git -C /etc/dokploy/compose/monkeybot-app-ojzrhf/code add -A   # only in the production checkout,
                                                                 # after reviewing what's staged
git -C /etc/dokploy/compose/monkeybot-app-ojzrhf/code commit -m "Deploy: <summary> (from release/9.x-integration)"
```

Note the resulting commit SHA in the backup directory from step 2 (e.g. a text file alongside the SQL
dump) — this is the rollback anchor for step 12.

## Rollback

Rollback is a merge-back to the previous commit recorded in the backup directory from step 2/11, not
a `git reset --hard` (other sessions may be reading/editing the same tree; a hard reset can discard
work that isn't yours).

```sh
cd /etc/dokploy/compose/monkeybot-app-ojzrhf/code
git revert --no-commit <bad-commit>..HEAD   # or merge in the previous known-good commit directly
docker exec monkeybot-app-ojzrhf-app-1 php -l /var/www/html/<changed files>   # re-lint
```

- **Code rollback:** since migrations are additive-only (`CREATE TABLE IF NOT EXISTS`,
  `ADD COLUMN IF NOT EXISTS`, no `DROP`, no destructive `MODIFY` — see the plan's non-negotiable
  rules), a code-only rollback needs no DB restore in the normal case: old code simply ignores
  columns/tables it doesn't know about.
- **DB rollback:** only restore from the step-2 backup (or the nightly backup in
  `/root/backups/monkeybot-nightly`) if the deployed migration was not backward-compatible in
  practice, or if the incident involves bad data rather than bad code. Restoring the DB is more
  disruptive than reverting code — prefer code rollback first, confirm `/health` and smoke pages,
  and only restore data if the problem persists.
- After rollback, repeat the verify step (10) before considering the incident closed.

## No secrets here

This runbook intentionally contains no credentials, tokens, or connection strings. Database
credentials, the encryption key, and API tokens live only in the `monkeybot_app_config` volume and
`/root/monkeybot/.env` — never copy them into this file, into commit messages, or into backup
filenames.

## Target pipeline (brief §8) — not yet built, still the direction to work toward

The procedure above is the real one used today: a direct merge into a live bind-mounted checkout,
because there is still no staging environment and no build/release pipeline. That gap is tracked as
an open precondition in `docs/architecture/adr/ADR-000-strangler-fig-modular-monolith.md`. The
target shape:

```
Development -> Staging -> Automated checks -> Production deploy -> Health check -> Rollback if needed
```

| Step | Minimum viable version | Prerequisite |
|---|---|---|
| 1. Production state in source control | All live work committed, token-free remote, pushed. Tag releases. | Remote credential/token issue resolved (flagged in the baseline as still open) |
| 2. Release version | `/health` reports a version, each deploy is a git tag. | `/health` exists today; tagging discipline still needed |
| 3. Backup procedure | Nightly backup (already live, `/root/backups/monkeybot-nightly`) plus a backup before each deploy (step 2 above, currently manual). | Done for nightly; per-deploy backup is manual today, not automated |
| 4. DB migration procedure | Only through `cron_hub migrate`, idempotent SQL. | Already true |
| 5. Staging | A second Dokploy compose project with its own volumes, a sanitised DB copy, and test FB/IG/OpenWA accounts. Must not touch production volumes. | Owner approval, a test Meta app |
| 6. Automated checks | `php -l` on changed files (manual today, step 6 above), smoke URLs, channel contract tests as they're written. | Staging |
| 7. Deploy from git, not live edits | Production checks out a tag into a fresh directory and atomically swaps the bind-mount path, or Dokploy redeploys from a git tag. Live editing (this runbook's current procedure) stops being necessary. | Steps 1–6 |
| 8. Health gate | `/health` must be `ok` within 10 minutes of a deploy (two scheduler ticks) or roll back automatically. | `/health` exists; automated gating does not yet |
| 9. Rollback | Re-deploy the previous tag; restore DB only if a migration wasn't backward-compatible. | Steps 4 and 7 |

Recommended low-risk infrastructure change: mount the nginx **directory** (`docker/nginx/` to
`/etc/nginx/conf.d/`) instead of a single file, so `nginx -s reload` picks up edits without a `web`
restart. This needs one `web` restart to apply, then removes the need for step 9 above going forward.
