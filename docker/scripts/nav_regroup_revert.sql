-- Revert for assets/backup_db/migrations/2026-10-05a-nav-regroup.sql (Task 22, C7,
-- brief §34 — admin navigation regroup).
--
-- Restores exactly the three columns that migration touched (`serial`,
-- `header_text`, `have_child`) from the pre-regroup snapshot table
-- `menu_backup_20261005`, matched by `id` (menu row identity). `url` and
-- `module_access` were never changed by the migration, so there is nothing to
-- restore there.
--
-- Run manually, never by cron_hub migrate:
--   docker exec -i <db-container> sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
--     < docker/scripts/nav_regroup_revert.sql
--
-- Requires menu_backup_20261005 to exist (created by the migration's first run).

UPDATE `menu` AS m
JOIN `menu_backup_20261005` AS b ON b.`id` = m.`id`
SET
  m.`serial`      = b.`serial`,
  m.`header_text` = b.`header_text`,
  m.`have_child`  = b.`have_child`;
