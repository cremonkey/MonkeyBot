# monkey bot 8.5.4 Security and Deployment Report

Date: 2026-06-13

## Executive Summary

The project is a PHP CodeIgniter 3 application for a digital marketing/chatbot platform. The codebase includes first-party controllers/modules plus many bundled third-party libraries and frontend assets.

No obvious PHP web shell or malicious upload payload was found in the usual writable/upload directories during static scanning. However, the application is old and needs hardening before production: CSRF is disabled, passwords use MD5, SSL verification is disabled in many cURL calls, and several dependencies are bundled old versions.

## Project Inventory

- Framework: CodeIgniter 3.1.5
- Product: monkey bot 8.5.4
- Approximate size: 278 MB
- Files: 12,948
- Main app code: `application/controllers`, `application/models`, `application/modules`, `application/views`
- Database seed: `assets/backup_db/initial_db.sql`
- Installer marker: `application/install.txt`
- Upload/storage folders: `upload`, `upload_caster`, `download`, `application/cache`

## Changes Applied

- Fixed a syntax-breaking stray `+` in `application/config/config.php`.
- Replaced the default weak CodeIgniter `encryption_key` value `12345` with a random 64-character hex key.

## Security Findings

### High Priority

1. CSRF protection is disabled.
   - File: `application/config/config.php`
   - Current: `$config['csrf_protection'] = FALSE;`
   - Risk: authenticated state-changing AJAX/form endpoints are exposed to CSRF.
   - Recommendation: enable CSRF after testing AJAX endpoints and adding token handling where missing.

2. Password hashing uses MD5.
   - Files include `application/controllers/Home.php`, `Admin.php`, `Change_password.php`, `Ecommerce.php`
   - Risk: MD5 is fast and unsuitable for passwords.
   - Recommendation: migrate to `password_hash()` / `password_verify()` with backward-compatible login migration.

3. SSL certificate verification is disabled in many outbound cURL requests.
   - Examples: `application/libraries/Fb_rx_login.php`, `Openai_api.php`, `Google_youtube_login.php`, `Mailchimp_api.php`, `Paypal_class.php`, `application/controllers/Update_system.php`
   - Risk: man-in-the-middle attacks against API tokens and payment/social integrations.
   - Recommendation: set `CURLOPT_SSL_VERIFYPEER` true and configure a valid CA bundle on the server.

4. The bundled update system can download/replace files.
   - File: `application/controllers/Update_system.php`
   - Risk: dangerous if admin access is compromised or update source is compromised.
   - Recommendation: restrict to trusted admins only, disable on production if not needed, and back up before use.

### Medium Priority

1. Cookies are not marked secure by default.
   - File: `application/config/config.php`
   - Current: `$config['cookie_secure'] = FALSE;`
   - Recommendation: set true after HTTPS is enabled.

2. Global XSS filtering is disabled.
   - File: `application/config/config.php`
   - Current: `$config['global_xss_filtering'] = FALSE;`
   - Recommendation: keep contextual escaping in views, and test before enabling globally because old CI global filtering can break rich content.

3. Database debug is enabled.
   - File: `application/config/database.php`
   - Current: `$db['default']['db_debug'] = TRUE;`
   - Recommendation: set false in production after installation.

4. Session files are stored under `application/cache`.
   - The folder has `.htaccess` deny rules for Apache.
   - Recommendation: keep it outside web root where possible, or enforce equivalent Nginx/LiteSpeed deny rules.

5. Several JavaScript `eval()` usages exist.
   - Mostly UI counter/action code in views and bundled assets.
   - Recommendation: refactor over time; do not allow user-controlled strings to reach these calls.

### Positive Findings

- No PHP files were found inside `upload`, `upload_caster`, or `download`.
- Upload-related directories include `.htaccess` rules denying PHP execution.
- `assets/backup_db/.htaccess` denies access to the SQL seed on Apache.
- Database credentials are not currently hardcoded; `application/config/database.php` is blank pending installation.

## Deployment Readiness

The project is not fully ready to upload until the server environment and production config are prepared.

Required server stack:

- PHP compatible with this legacy CodeIgniter app. PHP 7.4 is usually safer for legacy CI3 code than PHP 8.x unless tested.
- MySQL/MariaDB.
- PHP extensions: mysqli, curl, mbstring, json, gd, fileinfo, openssl, zip.
- Apache with mod_rewrite, or equivalent Nginx rewrite rules.

Required writable paths:

- `application/cache`
- `application/logs`
- `upload`
- `upload_caster`
- `download`
- Any subfolders created by uploads/imports/exports.

Production config checklist:

- Set real `base_url` in `application/config/config.php`.
- Install using `/home/installation` while `application/install.txt` exists.
- Confirm `application/install.txt` is deleted after installation.
- Set `db_debug` to `FALSE` after installation.
- Enable HTTPS and set `force_https` to `1` in `application/config/my_config.php`.
- Set `cookie_secure` to `TRUE` after HTTPS is live.
- Restrict access to `application`, `system`, `assets/backup_db`, `upload`, `download`, and cache folders at web server level.
- Configure cron jobs for `application/controllers/Cron_job.php` according to the app documentation.
- Remove or block `documentation` from public production access if not needed.

## Apache Notes

The existing `.htaccess` rules are intended for Apache and deny direct PHP execution except root `index.php`. If hosting on Nginx, add equivalent rules. Do not rely on `.htaccess` outside Apache-compatible servers.

## Verification Limitations

PHP CLI is not installed in the local environment, so syntax linting and runtime smoke tests could not be executed locally. Before launch, run:

```bash
php -l application/config/config.php
php -l index.php
```

Then browse:

- `/`
- `/home/installation`
- `/home/login`

## Final Recommendation

Proceed with upload only after applying the production checklist. The code does not show obvious malware in the scanned areas, but it should be treated as a legacy PHP app and deployed behind HTTPS, strict web server deny rules, limited file permissions, and a full database/files backup policy.
