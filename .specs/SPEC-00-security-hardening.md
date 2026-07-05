# SPEC-00: Security Hardening (Phase 0)

> READ `.specs/MASTER-PLAN.md` header first (live production, config volume, lint & smoke commands, git).
> Commit after EACH task separately. If a task breaks the smoke test, revert that task and mark it BLOCKED in your final report — do not leave the site broken.

## Task A — Password hashing: MD5 → password_hash (backward compatible)
Current: passwords hashed with `md5()` in `application/controllers/Home.php` (login/signup/reset), `Admin.php`, `Change_password.php`, `Ecommerce.php` (store customer auth if any).
1. `grep -rn "md5(" application/controllers application/models application/modules --include=*.php` — classify each hit: PASSWORD context vs token/cache-key context. ONLY change password contexts.
2. Login flows: replace `WHERE password = md5($input)` pattern with: fetch user row by email/username, then
   `if (password_verify($input, $row->password)) OK; elseif (md5($input) === $row->password) { OK + UPDATE password = password_hash($input, PASSWORD_DEFAULT); }`
3. All password INSERT/UPDATE sites (signup, change password, reset password, admin create user): use `password_hash($input, PASSWORD_DEFAULT)`.
4. Check password column length ≥ 60 chars in live DB (`SHOW CREATE TABLE users;` — find the actual users table name via the login code). If varchar < 255, ALTER to varchar(255) (fresh mysqldump first).
5. Verify: smoke test login page 200. Report which flows you could not verify end-to-end.

## Task B — Enable SSL verification in outbound cURL
1. `grep -rn "CURLOPT_SSL_VERIFYPEER" application/ --include=*.php` — flip all `false/FALSE/0` to `true`, and `CURLOPT_SSL_VERIFYHOST` false/0 → `2`. Files include: libraries/Openai_api.php, Fb_rx_login.php, Google_youtube_login.php, Mailchimp_api.php, Paypal_class.php, controllers/Update_system.php, others.
2. Verify CA bundle inside container: `docker exec monkeybot-app-1 php -r '$c=curl_init("https://api.openai.com/v1/models");curl_setopt($c,CURLOPT_RETURNTRANSFER,1);curl_setopt($c,CURLOPT_SSL_VERIFYPEER,true);curl_exec($c);echo curl_error($c)?:"TLS-OK";'` → expect TLS-OK.
3. Lint all changed files. Commit.

## Task C — Session & cookie hardening (config volume + repo copy — BOTH)
In `application/config/config.php` — live copy via `docker cp monkeybot-app-1:/var/www/html/application/config/config.php /tmp/live-config.php`, edit, `docker cp` back; AND the repo copy `/tmp/MonkeyBot/application/config/config.php`:
- `$config['sess_expiration'] = 14400;` (was 0 = never)
- `$config['cookie_secure'] = TRUE;` (site is HTTPS-only via Traefik)
- `$config['cookie_httponly'] = TRUE;`
- Leave sess_save_path as-is (nginx already denies /application/*; it's a docker volume).
Verify page still renders 200 and no new PHP errors (`docker exec monkeybot-app-1 sh -c 'ls -t /var/www/html/application/logs/ | head -2'` then tail newest). Commit repo copy.

## Task D — CSRF protection (highest breakage risk — do LAST)
1. First inspect real public/webhook URIs: `grep -n "public function" application/controllers/Messenger_bot.php | head -40` (find webhook/verify endpoints), same for Instagram_reply.php, Cron_job.php, Paypal_ipn.php, Stripe_action.php, Ecommerce.php (public store/checkout URLs).
2. In BOTH config copies:
   `$config['csrf_protection'] = TRUE;` `$config['csrf_regenerate'] = FALSE;`
   `$config['csrf_exclude_uris'] = array(<real webhook/cron/payment/public-store URI regexes from step 1>);`
3. Global AJAX token injection: new file `assets/js/csrf-ajax.js`:
   ```js
   $.ajaxPrefilter(function(options){
     if (!/^(GET|HEAD|OPTIONS)$/i.test(options.type||'GET')) {
       var m = document.cookie.match(/(?:^|; )csrf_cookie_name=([^;]+)/);
       if (m) { if (options.data instanceof FormData) { options.data.append('csrf_test_name', decodeURIComponent(m[1])); }
         else { options.data = (options.data? options.data + '&':'') + 'csrf_test_name=' + m[1]; } }
     }
   });
   ```
   Include it in the admin theme footer view (find where jquery is loaded under `application/views/admin/theme/`), AND in the member theme if separate. Use the configured csrf cookie/token names from config.
4. Plain `<form method=post>` views (non form_open, non-AJAX): fix at minimum login/signup/change-password by adding `<input type="hidden" name="csrf_test_name" value="<?php echo $this->security->get_csrf_hash(); ?>">`. List remaining unfixed forms in report.
5. Verify login flow with cookies+token via curl inside monkeybot-web-1 (GET login to get cookie, POST with token → expect NOT 403). If breakage is widespread: revert csrf_protection to FALSE in both copies, keep the JS groundwork committed, report BLOCKED with findings.

## Task E — Encrypt OpenAI key at rest
1. Create `application/helpers/secret_helper.php`: `secret_encrypt($plain)` → `'enc::' . <CI encryption>->encrypt($plain)`; `secret_decrypt($stored)` → decrypt if `enc::` prefix else return as-is (lazy migration). Load CI instance via `get_instance()`, `$CI->load->library('encryption')`.
2. Update `Integration.php::open_ai_api_credentials_action()` (~291-353) to store via secret_encrypt; update read site `Home.php::get_ai_reply_open_ai()` (~7001) via secret_decrypt; UI form must show masked key (last 4 chars), and on save keep old value if field submitted masked/unchanged.
3. Lint + smoke + commit.

## Task F — Restrict Update_system
In `application/controllers/Update_system.php` constructor, after existing auth checks (match its existing admin-check style):
```php
if (getenv('ALLOW_SYSTEM_UPDATE') !== '1') { show_error('System update is disabled on this deployment.', 403); }
```
Commit.

## Task G — Fix WhatsApp link bug
`application/controllers/Ecommerce.php` ~line 3061: URL contains `phone=phone=` — fix to single `phone=`. Check ~3057 variant too. Lint + commit.

## Final report format
Per task: DONE/BLOCKED + files changed + one-line verification output. List every flow you could not fully verify.
