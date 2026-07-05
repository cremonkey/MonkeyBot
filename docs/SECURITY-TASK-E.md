# SPEC-00 Task E — Secret encryption (ACTION REQUIRED)

`application/helpers/secret_helper.php` is deployed and safe to use now, but at-rest
encryption stays OFF until a strong `encryption_key` is configured. The live key is
still the framework default `'12345'`, which is unusable for real encryption.

Rotating the key on production invalidates the Encryption library's ciphertext and was
intentionally NOT done autonomously (it's a shared-production change needing your sign-off).

## To activate (do in a short maintenance window)
1. Generate a key: `openssl rand -hex 32`
2. Set it in BOTH config copies (they must match):
   - Live volume: `docker cp` an edited `config.php` into `monkeybot-app-1:/var/www/html/application/config/config.php`
   - Repo copy: `/tmp/MonkeyBot/application/config/config.php` line `~230` `$config['encryption_key']`
   Better: read it from an env var so it never sits in a bind-mounted file.
3. Nothing else needed — `secret_encrypt()` auto-activates once the key is strong, and
   existing plaintext secrets get encrypted the next time each is saved (lazy migration).
   `secret_decrypt()` already handles both plaintext and `enc::` values.

## Impact
- No existing feature currently uses the Encryption library, and `sess_encrypt_cookie=FALSE`,
  so rotating the key only forces users to re-login. No data loss.
