# Channel Adapters

- **Phase:** 9.0 (ADR-002, brief §10, §11, §44)
- **Code:** `application/libraries/Channels/`, `application/helpers/channel_adapter_helper.php`
- **Tests:** `tests/run.php`, `tests/channels/`
- **Platform status per channel** (live, awaiting activation, and so on) is in `capability-matrix.md`. This page covers only what the adapter code does.

## What exists

| Piece | File | Role |
|---|---|---|
| `ChannelAdapterInterface` | `Channels/ChannelAdapterInterface.php` | `key()`, `capabilities()`, `supports()`, `sendText()`, `sendMedia()`, `normalizeInbound()`, `normalizeInboundBatch()` |
| `InboundMessage` | `Channels/InboundMessage.php` | Normalised inbound message (brief §11). Field meanings are in the class docblock. |
| `OutboundMessage` | `Channels/OutboundMessage.php` | Recipient, text, optional media, and the already-decrypted credentials the adapter needs. |
| `SendResult` | `Channels/SendResult.php` | `ok`, `external_message_id`, `error`, `retryable`, `unsupported`, `http_status`, `response` |
| `Capability` | `Channels/Capability.php` | Capability names |
| `HttpTransport` / `CurlTransport` | `Channels/Http.php` | The HTTP seam. `CurlTransport` sets exactly the curl options the legacy clients set. |
| Adapters | `Channels/Adapters/*.php` | One per channel (see below) |
| `ChannelRegistry` | `Channels/ChannelRegistry.php` | `ChannelRegistry::for($key)` builds an adapter; `useTransport()` swaps HTTP for tests |
| Loader | `Channels/bootstrap.php` | CodeIgniter 3 has no autoloader for namespaced libraries, so this file `require_once`s the layer |
| Helper | `helpers/channel_adapter_helper.php` | `channel_adapter($key)` returns the adapter, or `NULL` for an unknown key |

All classes live in the namespace `MonkeyBot\Channels`.

```php
$this->load->helper('channel_adapter');
$result = channel_adapter('tg')->sendText(
    \MonkeyBot\Channels\OutboundMessage::text($chat_id, $text, array('bot_token' => $token), $user_id)
);
if (!$result->ok && $result->retryable) { /* queue a retry */ }
```

## Capability matrix (what the adapter code implements)

A capability is listed only when the adapter implements it **and** a contract test covers it. Something the platform supports but the adapter does not implement yet is marked "no". Unsupported calls return `SendResult::unsupported()`: `ok=false`, `unsupported=true`, `retryable=false`, and no HTTP call is made.

| Key | Adapter | send_text | send_image | send_media | mark_seen | typing | buttons | templates | receive_webhook |
|---|---|---|---|---|---|---|---|---|---|
| `fb` | `FacebookMessengerAdapter` | yes | no | no | no | no | no | no | yes |
| `ig` | `InstagramAdapter` | yes | no | no | no | no | no | no | yes |
| `wa_openwa` | `OpenWaAdapter` | yes | yes (Note 1) | no | no | no | no | no | yes |
| `wa_cloud` | `WhatsAppCloudAdapter` | yes | no | no | no | no | no | no (Note 2) | yes |
| `tg` | `TelegramAdapter` | yes | no | no | no | no | no | no | yes |
| `web` | `WebChatAdapter` | yes (Note 3) | no | no | no | no | no | no | yes (Note 4) |
| `tiktok` | `TikTokAdapter` | no | no | no | no | no | no | no | no (Note 5) |

**Notes**

1. `send_image` mirrors `Openwa_api::send_image()`, which `Messenger_bot` already uses in production.
2. `Whatsapp_api::send_template()` exists but is not exposed through the adapter yet.
3. Web chat has no push API. "Sending" means writing the row that the widget's poller reads. The adapter holds no database handle: the caller passes an outbox callable through `withOutbox()`. Without one, `sendText()` fails; it never pretends to deliver.
4. For web chat, `receive_webhook` means the adapter parses the widget's `send` POST fields.
5. TikTok has no public DM API (partner-only), and comment polling is out of scope.

Platform features such as Meta buttons, carousels, OTN/RCN, WhatsApp templates and Telegram `sendPhoto` still live in the legacy engines. Each one is added here only together with its own contract test.

## Requests (byte-for-byte the legacy ones)

| Key | Request | Timeouts / TLS |
|---|---|---|
| `fb`, `ig` | `POST graph_url('me/messages?access_token=', TRUE) . urlencode(token)` (legacy tier, v20.0), JSON `{recipient:{id}, messaging_type:RESPONSE, message:{text}}` | 20s; `VERIFYPEER` on; `VERIFYHOST` and connect timeout not set |
| `wa_openwa` | `POST {base_url}/api/sessions/{rawurlencode(session)}/messages/send-text` (or `send-image`), headers `Content-Type`, `Accept` and `X-API-Key`; `chatId` from `Openwa_api::to_chat_id()` | 15s, connect 5s; `VERIFYHOST` 2 |
| `wa_cloud` | `POST https://graph.facebook.com/v21.0/{phone_number_id}/messages` (hardcoded v21.0, as before), `Authorization: Bearer` | 30s; `VERIFYHOST` 2 |
| `tg` | `POST https://api.telegram.org/bot{token}/sendMessage`, form-encoded `chat_id`, `text`, no explicit headers | 30s; `VERIFYHOST` 2 |

Error strings are unchanged: `graph: {message} (code {code})`, `openwa: {json}`, `wa: {json}` and `tg: {json}`.

`retryable` is true for:

- transport failure;
- HTTP 429 and 5xx;
- Graph `is_transient` errors, or codes 1, 2, 4, 17, 32, 341, 613 and 1200;
- WhatsApp Cloud throttling codes 80007, 130429, 131000, 131016 and 131056;
- Telegram `error_code` 429 or 5xx;
- a web outbox exception.

## Inbound normalisation

`normalizeInbound($raw, ['tenant_id' => user_id, 'account_id' => row id])` returns the first customer message, or `NULL`. `normalizeInboundBatch()` returns all of them, because Meta and WhatsApp Cloud batch several events into one delivery.

Normalisers only parse:

- no database access;
- no HTTP;
- no logging;
- no exceptions.

The following all return `NULL`: echoes, read and delivery receipts, WhatsApp Cloud status callbacks, OpenWA `fromMe` and group messages (the same filters `Openwa_bot::webhook()` applies), malformed payloads, and payloads that belong to another channel.

| Key | Accepts | `external_message_id` | `channel_account_id` |
|---|---|---|---|
| `fb` / `ig` | Full webhook body (`object` must be `page` / `instagram`) or one `messaging[]` event | `message.mid` (or `postback.mid`) | `recipient.id` (page id / IG account id) |
| `wa_openwa` | Gateway `message.received` body (`openwaCompat` shape from `/opt/evolution/extensions/webhook-router`) | `data.id` | context `account_id`, otherwise `sessionId` |
| `wa_cloud` | `whatsapp_business_account` webhook body | `wamid…` | `metadata.phone_number_id` |
| `tg` | Telegram Update with `message` or `edited_message` | `"{chat_id}:{message_id}"` (edits add `:e{edit_date}`) | context `account_id` |
| `web` | Widget POST fields (`widget_key`, `session_key`, `message`) | `client_message_id` if sent, else `NULL` (the widget sends none today) | `widget_key` |

For attachments, the adapter never invents URLs:

- **OpenWA:** the gateway does not forward a media URL.
- **WhatsApp Cloud and Telegram:** they send media ids. `url` is `NULL` and `id` carries the media or file id.
- **Captions:** a caption becomes `text` when there is no body.

### Not wired yet (intentional)

The normalisers are **not called by any webhook controller** yet: `Messenger_bot`, `Openwa_bot`, `Whatsapp_bot`, `Telegram_bot` and `Webchat` still parse their own input. They will be wired in with the unified inbound pipeline (ADR-002, "Inbound second"), after:

- verification;
- idempotency on `external_message_id`;
- the ADR-001 persistence.

The legacy engines' own reply paths (for example `Messenger_bot` → `Openwa_api`, and `Whatsapp_bot` → `Whatsapp_api`) also still send directly. Only `channel_send_text()` goes through the adapters today. Its callers are `Cron_hub`, `Ai_tools` and `Reengage_sender`.

## `channel_send_text()` facade

`application/helpers/channel_send_helper.php` keeps these unchanged:

- its signature and `array(bool ok, string error)` return shape;
- the account lookups;
- `secret_decrypt()`;
- the OpenWA-then-Cloud order for `wa`: when an OpenWA account is found, its result is final and a failure does **not** fall through to Cloud;
- `channel_log_message()`;
- every error string.

`channel_send_fb_ig`, `channel_send_wa`, `channel_send_tg` and `channel_send_web` remain as thin wrappers. `tiktok` and unknown channels still return `unsupported channel: …`.

### Known legacy quirks

**Fixed in 9.0 (owner-approved policy: no silent failure):** fb/ig network failures, timeouts and non-JSON replies used to count as sent and were logged as delivered. `channel_send_text()` now returns `[false, error]` for them and logs nothing, so the caller retries or records the failure.
 (preserved on purpose)

1. **fb/ig: "sent" unless Graph returned an `error` object.** A network failure, a timeout or a non-JSON 5xx page therefore still counts as success and is logged to `livechat_messages`. That is how the code worked before 9.0. The adapter itself reports these cases correctly (`ok=false`, `retryable=true`). Only the facade function `channel_send_meta_legacy_ok()` keeps the old rule. Removing it is a one-line behaviour change and should be its own reviewed commit. The test `facade fb: LEGACY QUIRK preserved` pins the current behaviour.
2. **OpenWA: any 2xx counts as sent**, whatever the body says. This is unchanged.

### Small differences from pre-9.0 (edge cases only)

- **Missing credentials.** An empty token, `base_url`, `api_key`, `session_id`, `phone_number_id` or `bot_token` (for example a failed `secret_decrypt`) now fails *before* any HTTP call, with `{key}: {field} missing`. Previously the request was sent without credentials and failed at the API with that API's error string. Both return `ok=false`; only the error text differs.
- **`NULL` recipient.** A `NULL` recipient is now rejected as `{key}: empty message or recipient`. Previously it was sent as JSON `null`.
- **Web outbox errors.** A PHP `Error` (not an `Exception`) raised while inserting the web row is now caught and returned as `array(false, msg)`. Previously it propagated.

## Adding a channel

1. Create `Channels/Adapters/{Name}Adapter.php` extending `AbstractChannelAdapter` in namespace `MonkeyBot\Channels`. Implement `key()`, `capabilities()`, `sendText()` (and `sendMedia()` if supported), and `parseInbound()`.
2. Do all HTTP through `$this->http->send(new HttpRequest(...))`. Never call curl directly.
3. List only what you implemented in `capabilities()`. The base class already answers "unsupported" for everything else.
4. Add the file to `Channels/bootstrap.php` and the key to `ChannelRegistry::ADAPTERS`.
5. Add fixtures to `tests/channels/fixtures/{prefix}_*.json` and a spec entry in `adapter_specs()` in `tests/channels/AdapterContractTest.php`, covering the request, the success reply, a channel rejection and inbound expectations. The common suite then runs automatically. Update the matrix above.

## Running the tests

Run from the repository root. The tests need no database, no CodeIgniter bootstrap and no network.

```bash
docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/run.php
docker run --rm -v "$PWD":/app -w /app monkeybot-app-ojzrhf-app:latest php tests/run.php wa_openwa   # filter by name
```

`tests/bootstrap.php` sets up the environment:

- It stubs `get_instance()` (config only), `log_message()` and `secret_decrypt()`. The `secret_decrypt()` stub returns `dec(x)`, so the tests can prove decryption happens.
- It loads the real `fb_graph_helper`, with the production version values v26.0 and legacy v20.0.
- It turns every PHP warning into a test failure.

`tests/` is CLI-only: every file exits unless `PHP_SAPI === 'cli'`, and nginx denies `/tests/`.

The inbound fixtures are **synthetic**. They were written from the documented Meta, WhatsApp Cloud and Telegram webhook shapes and from the gateway's `openwaCompat` transform, not recorded from production traffic. Replace them with recorded (and scrubbed) payloads when the inbound pipeline is wired.
