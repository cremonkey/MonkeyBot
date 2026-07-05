# Dokploy temp-domain setup notes

## Stack
- Nginx: serves the public site and routes PHP requests to PHP-FPM
- PHP: 7.4 FPM with the extensions required by CodeIgniter + legacy integrations
- Database: MariaDB 10.11 with compatibility-oriented defaults

## Runtime volumes
- `application/config`
- `application/cache`
- `application/logs`
- `upload`
- `upload_caster`
- `download`
- `db_data`

## Temporary domain
Set `APP_BASE_URL` to the temp domain once it exists.
Example:
- `https://temp-yourname.example.com`

## Database values
Set these before first boot:
- `MYSQL_DATABASE`
- `MYSQL_USER`
- `MYSQL_PASSWORD`
- `MYSQL_ROOT_PASSWORD`

## First run flow
1. Bring the stack up
2. Open `/home/installation`
3. Complete the installer
4. Verify that `application/install.txt` is removed
5. Confirm login works at `/home/login`

## Notes
- The app writes its DB and app config during installation, so `application/config` must stay writable.
- The Nginx config blocks direct access to `application/`, `system/`, `ci/`, `docker/`, and `assets/backup_db`.
- Upload paths are kept writable because the app stores media and exports there.
