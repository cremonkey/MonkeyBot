# Runbook: Deployment

## Current reality (2026-09-22)

| Fact | Consequence |
|---|---|
| The repo tree `/etc/dokploy/compose/monkeybot-app-ojzrhf/code` is bind-mounted into `app` (read-write) and `web` (read-only) as `/var/www/html`. There is no build step. | **Every file save is a production deploy.** A PHP syntax error takes the affected pages down at once. |
| The repo root is the web root. | Every new file in the repo is publicly served unless nginx denies it. Put internal material under `docs/`, `docker/`, `.specs/` or another path that is already denied. |
| These paths are external Docker volumes that shadow the repo copies: `application/config`, `application/cache`, `application/logs`, `upload`, `upload_caster`, `download`. | Editing `application/config/*.php` in the repo does **nothing** live. Config changes must be written into the `monkeybot_app_config` volume, for example with `docker exec monkeybot-app-ojzrhf-app-1 …`. Mirror them in the repo with secrets as placeholders. |
| nginx config `docker/nginx/default.conf` is a **single-file** bind mount. | Editors and `sed -i` replace the file's inode, but the container keeps the old inode. `nginx -s reload` re-reads the **old** file. After editing, restart the web container: `docker restart monkeybot-app-ojzrhf-web-1`. Then confirm with `docker exec monkeybot-app-ojzrhf-web-1 cat /etc/nginx/conf.d/default.conf`. |
| Secrets are in `/root/monkeybot/.env` (`env_file`). The real `encryption_key` lives only in the config volume. | Never commit either one. |
| Git: branch `chore/commit-live-work-20260922`, not pushed. The work done after it is still uncommitted. The remote URL embeds a token (baseline S-09). | There is no rollback point for the uncommitted work. |
| There is only production. | No staging exists. |
| Schema changes are SQL files in `assets/backup_db/migrations/`, applied by `php index.php cron_hub migrate` and tracked in `schema_migrations`. | Some columns were added by hand (`hours_*`). `openwa_channel` is not recorded. |

## Checklist for a safe change today

### Before

1. `git status`. Know exactly which files you are about to change. Other sessions may be editing the same tree: stage files by name and never use `git add -A`.
2. Make a copy you can roll back to. Commit first, or `git stash`, or `cp file file.bak-<tag>`. `*.bak*` is gitignored and denied by nginx. Delete the copy after verification.
3. If the change touches the schema, config or retention, take a backup (see `backup-and-restore.md`).
4. If it is a schema change, write a new SQL file in `assets/backup_db/migrations/`. Never change the schema by hand.

### Change

5. Edit the files in a scratch copy, then lint them before they go live:

   ```sh
   docker exec monkeybot-app-ojzrhf-app-1 php -l /var/www/html/<path>
   ```

   Linting after the save still leaves a window in which the file is broken. For large edits, write the new file under a temporary name, lint it, then `mv` it into place.
6. Put new behaviour behind a config flag with a `log` mode when it can reject traffic or users. Examples: webhook signatures, entitlements.
7. Config change: write it into the volume. nginx change: restart `web`.

### Verify

8. Smoke test:
   - `/` returns 200;
   - `/home/login_page` returns 200;
   - you can log in and open Livechat;
   - one bot reply on a test page;
   - `/health` returns `ok`.
9. Check `application/logs` (volume `monkeybot_app_logs`) for new errors.
10. For a scheduler change, watch the next tick in `/var/log/monkeybot-cron.log`.
11. Record the result: "Verified" only with evidence, otherwise "Implemented but not tested" (brief §58).

### After

12. Commit the change with a clear message.

### Rollback

- Restore the previous file from git or from its `.bak` copy. The change is live instantly.
- Config: restore the previous file into the volume.
- DB: restore from backup, following `backup-and-restore.md`.

## Target pipeline (brief §8), introduced step by step

```
Development -> Staging -> Automated checks -> Production deploy -> Health check -> Rollback if needed
```

| Step | Minimum viable version | Prerequisite |
|---|---|---|
| 1. Production state in source control | All live work committed, token-free remote, pushed. Tag releases (`v8.5.4-phase0`, …). | S-09 token rotation |
| 2. Release version | `/health` reports a version, and each deploy is a git tag. | `/health` finished |
| 3. Backup procedure | Automated nightly backup plus a backup before each deploy. | `backup-and-restore.md` automation |
| 4. DB migration procedure | Only through `cron_hub migrate`. Idempotent SQL. Record the existing drift first (`hours_*`, `openwa_channel`). | Schema read access |
| 5. Staging | A second Dokploy compose project with its own volumes, a sanitised DB copy, and test FB, IG and OpenWA accounts. It must not use production volumes. | Owner approval, a test Meta app |
| 6. Automated checks | `php -l` on changed files; smoke URLs; the channel contract tests (brief §44) as they are written. | Staging |
| 7. Deploy from git, not from live edits | Production checks out a tag into a fresh directory, then atomically swaps the bind-mount path, or Dokploy redeploys from the git tag. Live editing stops. | Steps 1–6 |
| 8. Health gate | After a deploy, `/health` must be `ok` within 10 minutes (two scheduler ticks), otherwise roll back. | `/health` |
| 9. Rollback | Re-deploy the previous tag. Restore the DB only if a migration was not backward-compatible. Migrations should be additive, so that a code rollback needs no DB restore. | Steps 4 and 7 |

Recommended low-risk infrastructure change: mount the nginx **directory** (`docker/nginx/` to `/etc/nginx/conf.d/`) instead of a single file, so that `nginx -s reload` picks up edits. This needs one `web` restart when it is applied.
