# Graph API version migration

## Why

The Facebook Graph version was hardcoded in ~72 places across eight versions
(v2.6, v2.11, v4.0, v5.0, v8.0, v17.0, v18.0, v19.0) plus 25 URLs with no version
at all.

Those versions were fiction. Meta silently promotes any request below its oldest
live version to that oldest version. Verified with the `facebook-api-version`
response header on 2026-08-21:

| version in code | version Meta served |
|---|---|
| none, v2.6, v2.11, v4.0, v5.0, v8.0, v17.0, v18.0, v19.0, v20.0 | **v20.0** |
| v21.0 | v21.0 |
| v26.0 (latest live) | v26.0 |

So the whole app was really running on v20.0, and would have jumped to v21.0 on
its own the day Meta retires v20.0 — with no code change and no warning.

## What is in place now

`application/helpers/fb_graph_helper.php` (autoloaded) exposes:

* `fb_graph_version($tier)` — `'default'` or `'legacy'`
* `graph_url($path, $legacy = FALSE)` — builds `https://graph.facebook.com/<version>/<path>`

Both versions come from `application/config/config.php`:

```php
$config['fb_graph_version']        = 'v26.0';  // migration target
$config['fb_graph_version_legacy'] = 'v20.0';  // today's real behaviour, frozen
```

**Note:** `application/config/` is a Docker volume that shadows the code tree.
Editing `config.php` in the repo does nothing until it is also written into the
volume.

### Phase 0 — central config (done)
Helper + config keys added. No behaviour change.

### Phase 1 — unversioned URLs pinned (done)
25 sites. `https://graph.facebook.com/X` -> `graph_url("X", TRUE)` = v20.0.
Same version Meta was already serving, but now explicit and ours to control.

### Phase 2 — stale versions pinned (done)
47 URL sites plus 5 SDK `default_graph_version` values and the bundled SDK's
`DEFAULT_GRAPH_VERSION` constant. All previously-fictional versions now resolve
through `fb_graph_version('legacy')`.

`application/libraries/Whatsapp_api.php` stays on the literal v21.0 — that one is
honoured by Meta, so rewriting it to the legacy pin would be a real downgrade.

Verification for phases 1 and 2:
* textual proof that every one of the 72 changed lines is a prefix-only rewrite
* runtime equivalence test: 25/25 URLs identical to the originals plus v20.0
* SDK builds requests on v20.0
* no new PHP errors in the application log

## What is left

### Phase 3 — the actual cutover, v20.0 -> v26.0

Not started. It needs live page tokens to test, and the business was under a Meta
restriction when phases 0-2 were done — deliberately no calls were made against
Meta with the account's tokens.

Move endpoint groups off the legacy pin one at a time by flipping their
`graph_url(..., TRUE)` to `graph_url(...)`:

1. **Safe reads** — `/{post_id}` fields, `/{page_id}/conversations`,
   `/{thread_id}/messages`, `/debug_token`, `/me/picture`
2. **Send API** — `me/messages`. Message tags shrank after v4.0; check every tag
   still in use
3. **messenger_profile** — 14 sites; some fields were removed
4. **oauth/access_token, oauth/access_token_info** — last, breaking these breaks login
5. **Page insights** — `me/insights`, `{post_id}/insights`. Many metrics were
   deleted between v19 and v22; expect to re-pick metrics

### Stays on the legacy pin — removed from newer versions

* `me/custom_labels`, `{label_id}/label` — Custom Labels API
* `me/messaging_feature_review`
* `me/broadcast_reach_estimations` — dead code, the system has never sent a broadcast

These need a rewrite or removal, not a version bump.

## Rollback

`/root/backups/monkeybot-graph-20260821-200105/ROLLBACK.sh` restores the code tree
and the config volume. Per-file originals are alongside each edited file as
`*.bak-graphver-20260821`.

To undo only the version behaviour without touching code, set
`fb_graph_version_legacy` back in config.php.
