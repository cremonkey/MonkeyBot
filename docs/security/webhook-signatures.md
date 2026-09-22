# Meta Webhook Signatures: log → enforce rollout

**Status:** Implemented / awaiting activation. Both checks run in `log` mode, and the code is not yet committed.

**Brief:** §7.3. **Baseline finding:** S-02.

## What is checked

```
Meta ──POST + X-Hub-Signature-256──> home/central_webhook_callback
                                         check 1: meta_signature_gate()
          ──POST response_raw + X-MB-Internal──> messenger_bot/webhook_callback_main
                                                 comment_automation/webhook_callback_main
                                                 instagram_reply/webhook_callback
                                         check 2: mb_internal_gate()
Webview_builder (form submit) ──signed with mb_internal_headers()──> messenger_bot/webhook_callback_main
```

The code is in `application/helpers/webhook_security_helper.php`.

### Check 1: `meta_signature`

The check computes HMAC-SHA256 over the **raw request body**. The key is each non-deleted `facebook_rx_config.api_secret`, decrypted through `secret_decrypt`. The result is compared with `hash_equals` against the value after the `sha256=` prefix of the header.

| Result | Meaning |
|---|---|
| `valid` | The signature matches one configured app secret. |
| `invalid` | Secrets are configured, but none of them matches. |
| `missing` | There is no header, the `sha256=` prefix is missing, or the body is empty. This includes the legacy fallback where the payload arrives as a `response_raw` form field. |
| `no_secret` | No app secret is configured, or none decrypts. |

### Check 2: `internal_forward`

The check computes HMAC-SHA256 of `response_raw`, keyed with config `encryption_key`, and compares it with the `X-MB-Internal` header.

| Result | Meaning |
|---|---|
| `valid` | The header matches. |
| `invalid` | The header is present but does not match. |
| `missing` | There is no header. |

The verify-token handshake (`hub.challenge`) is answered before either check runs, so it is not affected.

## Modes

The modes are set in the live config, meaning the `monkeybot_app_config` volume, not the repo.

| Key | Values | Default |
|---|---|---|
| `meta_webhook_signature_mode` | `off` \| `log` \| `enforce` | `log` |
| `internal_webhook_mode` | `off` \| `log` \| `enforce` | `log` |

What each mode does:

- **`off`**: no check and no counting.
- **`log`**: counts every outcome and writes a CodeIgniter `error` log line when the outcome is not `valid`. **Nothing is ever rejected.**
- **`enforce`**: anything that is not `valid` gets HTTP 403, and processing stops.

An unknown value falls back to `log`.

Outcomes are counted in `application/cache/webhook_security.json`, per check. For each outcome there is a counter plus a `last_<outcome>` timestamp. The admin **System health** page shows the counters and the current modes; that page is in progress.

## Why log first

Enforcing a wrong or missing app secret would reject every real Meta delivery, which stops all FB and IG replies and comment automation. Meta then retries, and eventually disables the subscription. The counters prove what real traffic looks like before anything is rejected.

## Decision criteria

The counters are cumulative. Before the observation window starts, note the baseline values, or empty the file:

```sh
docker exec -u www-data monkeybot-app-ojzrhf-app-1 sh -c ': > /var/www/html/application/cache/webhook_security.json'
```

### Switch `internal_webhook_mode` to `enforce` first

This carries the lower risk, because we control both ends. Switch when **all** of these hold:

1. At least 7 days of normal traffic, with at least 200 `internal_forward` events.
2. `internal_forward.invalid == 0`, and `internal_forward.missing == 0`, **or** every `missing` line in the log has been traced to a forged or test request and not to one of our own callers.
3. At least one real message for each of these has been handled after the counters were reset:
   - a Messenger DM;
   - an IG DM;
   - an FB comment;
   - an IG comment;
   - a Webview form submit.
4. A code search shows no other caller POSTs to the three handlers without `mb_internal_headers()`. Only `Home::central_webhook_callback` and `Webview_builder` do so today.

### Then switch `meta_webhook_signature_mode` to `enforce`

Switch when **all** of these hold:

1. At least 7 days after the internal check has been enforced, with at least 200 `meta_signature` events.
2. `meta_signature.no_secret == 0`: every configured app has a secret that decrypts.
3. `meta_signature.invalid == 0`. Any `invalid` means an app secret is wrong, or a second Meta app is posting (for example a separate Instagram app). Fix the config and reset the counters.
4. `meta_signature.missing` is either 0 or can be explained only as non-Meta traffic. Traffic from Meta always carries the header.
5. The same five real-traffic types listed above were each seen `valid` after the reset.
6. Staging should exist so the switch can be rehearsed first. If it does not exist, switch during a low-traffic hour, with someone watching.

## How to switch

1. Edit the config in the volume, not in the repo. Set the key and value, for example `$config['internal_webhook_mode'] = 'enforce';`.
2. Mirror the change in the repo config.
3. No restart is needed. PHP-FPM reads the config on every request.

### Verify for 30 minutes

- Real FB and IG replies keep working.
- The `valid` counters keep rising.
- `/health` returns `ok`.
- Error logs are clean.
- A forged POST to `/messenger_bot/webhook_callback_main` gets 403.

### Roll back

Set the mode back to `log`. It takes effect immediately.

## Known limitations and follow-ups

- **One counter for all traffic.** There is one counter per check, not per page or object type. To tell FB from IG `invalid` outcomes, read the log lines.
- **The internal key is `encryption_key`.** Rotating it also breaks every stored `enc::` value. Before enforcing, consider a dedicated internal key (baseline S-10).
- **Other channels already fail closed.** OpenWA (`X-OpenWA-Signature`), WhatsApp Cloud (HMAC) and Telegram (secret-token header) are separate from this rollout.
- **Instagram with a separate app.** If Instagram is ever subscribed through a separate Meta app, that app's secret must be in `facebook_rx_config`, or its deliveries will show as `invalid`.
