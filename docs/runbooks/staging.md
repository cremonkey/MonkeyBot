# Staging runbook

Staging is a separate Docker Compose project on the same host, isolated from production:
own git worktree, own DB volume with a **fresh schema** (never production data), no Traefik
labels, not on `dokploy-network`, web bound to `127.0.0.1:8089` only. It reuses the production
image tag (`monkeybot-app-ojzrhf-app:latest`) so it runs the same PHP runtime.

## Create / refresh
```sh
cd /etc/dokploy/compose/monkeybot-app-ojzrhf/code
git worktree add --detach /root/worktrees/monkeybot-staging <branch-or-commit>
cd /root/worktrees/monkeybot-staging
# staging-only edits (never commit): application/config/config.php
#   base_url = "http://127.0.0.1:8089/", a fresh random encryption_key, cookie_secure = FALSE
# .env.staging (chmod 600): MYSQL_HOST=db, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASSWORD,
#   MYSQL_ROOT_PASSWORD (+ the same values as MARIADB_*), APP_BASE_URL, TZ=UTC — random values
docker compose -p monkeybot-staging -f docker-compose.staging.yml up -d
docker exec -i monkeybot-staging-db-1 sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < assets/backup_db/initial_db.sql
docker exec -u www-data monkeybot-staging-app-1 php /var/www/html/index.php cron_hub migrate
```
A fresh build now reproduces the production schema exactly: 36 migrations, 0 errors, 172
tables, 0 column/index differences (diffed 2026-09-22 against the production structure loaded
into a scratch `prod_schema` DB — structure only, never data).

## Seed for the Phase 0 suite
Run `docker/scripts/staging_seed.sh` (synthetic data, idempotent). It creates: admin (id 1), members 11 and 12
user 11 with one chunk containing `TENANT-A-CONFIDENTIAL`, and a `facebook_rx_config` row with
`api_secret = 'staging-app-secret'`. Password for all: set via `password_hash` in the seed.

## Run the regression suite
```sh
docker/scripts/staging_phase0_tests.sh          # exits non-zero on any failure
```
Run it twice: once with the gates in `log`, once with staging-only config
`meta_webhook_signature_mode = 'enforce'`, `internal_webhook_mode = 'enforce'`,
`entitlement_enforce_custom_modules = TRUE`.

## Results on 2026-09-22
- log mode: 18/18 passed. enforce mode: 19/19 passed.
- Also verified by CLI probe: encrypted OpenAI key round-trips through `ai_openai_key()`;
  legacy plaintext keys pass through; admin is not blocked with enforcement on;
  `/system_health` renders for admin; a failing cron tick is recorded and `/health` turns
  `degraded`; `migrate` no longer records a migration whose statements failed.

## Tear down
```sh
docker compose -p monkeybot-staging -f docker-compose.staging.yml down -v
git worktree remove --force /root/worktrees/monkeybot-staging
```
