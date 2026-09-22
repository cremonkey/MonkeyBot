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

## Phase 3 — code migration complete, all groups still pinned to legacy by default

Status as of 2026-09-22: **the engineering is done.** Every call site is converted
to per-group version resolution, the diagnostic tool is built, and every group
still resolves to v20.0 by default -- production's behaviour has not changed one
bit. What is left is entirely operational: run the diagnostic tool against one
real page per group, then flip that group's config key. No more code changes are
expected before or during rollout.

### Per-group version resolution

`application/helpers/fb_graph_helper.php` now also exposes, alongside the
unchanged `fb_graph_version()`/`graph_url()` from Phase 0:

* `fb_graph_group_version($group)` -- resolves ONE endpoint group's version from
  its own config key, `fb_graph_group_<group>_version`, defaulting to
  `fb_graph_version('legacy')` (v20.0) when that key is unset.
* `graph_url_group($group, $path)` -- builds
  `https://graph.facebook.com/<that group's version>/<path>`.
* `fb_graph_insights_dropped_metrics()` / `fb_graph_insights_unsupported($csv)` --
  the documented list of insights metrics Meta has confirmed removed "when
  v26.0 releases" (see Insights below), and a helper to check a metric list
  against it.

Every one of the ~70 call sites this app makes now goes through
`graph_url_group()`. **67 were converted** (see the full list and group
breakdown below); **2 were deleted as confirmed dead code** (see Labels /
dead-code below); the 3 commented-out `graph_url(...)` lines already dead in
the source (Fb_rx_login.php:824, 889, 1114) were left untouched, as was the
one `graph_url()` reference inside a comment in
`Channels/Adapters/WhatsAppCloudAdapter.php` (documents that file's hardcoded
v21.0, on purpose -- see "Permanently pinned" below).

### Config keys (all seven currently unset -> byte-identical to today)

```php
// application/config/config.php -- commented out on purpose, see the file for
// the full block. Uncomment ONE line at a time, after running Graph_diagnostics
// for that group against a real page.
// $config['fb_graph_group_reads_version']              = 'v26.0';
// $config['fb_graph_group_send_version']               = 'v26.0';
// $config['fb_graph_group_messenger_profile_version']  = 'v26.0';
// $config['fb_graph_group_oauth_version']               = 'v26.0'; // flip LAST
// $config['fb_graph_group_insights_version']           = 'v26.0';
// $config['fb_graph_group_labels_version']             = 'v26.0';
// $config['fb_graph_group_page_admin_version']         = 'v26.0';
```

`messaging_feature_review` has **no config key at all** -- `fb_graph_group_version()`
hard-codes it to v20.0 and never reads config for it, on purpose (see below), so
a group-wide flip (or a typo'd config key) can never drag it along by accident.

### Groups, call-site counts, and what changed in the URL/fields

| Group | Sites | Endpoints | Field/param changes found for v20->v26 |
|---|---:|---|---|
| `reads` | 26 | `/{post_id}` fields, `/{page_id}/conversations`, `/{thread_id}/messages`, `/debug_token`, `/me/picture`, `/me/videos`, `/crosspost_whitelisted_pages`, `/{app_id}` fields, `og_object`/`engagement`, Places `search`, `ig_hashtag_search` | None. Fetched Meta's own changelog pages for v21.0-v26.0 (developers.facebook.com/docs/graph-api/changelog/version21.0 .. version26.0) and none document a breaking change, field rename, or removal for any of these endpoints. Same field names work unchanged on v20.0 and v26.0. |
| `send` | 7 | `me/messages` (Send API) | The endpoint and payload shape are unchanged. **Not unchanged: message tags.** See "Send API message tags" below -- this is the one real, urgent finding from this research pass. |
| `messenger_profile` | 14 | `me/messenger_profile` (get_started, greeting, persistent_menu, whitelisted_domains, ice_breakers) | Checked the current Messenger Profile API reference (developers.facebook.com/docs/messenger-platform/reference/messenger-profile-api). All 5 fields this codebase reads/writes are still current, undeprecated fields. `target_audience` and `home_url` are not used by this codebase, so their status doesn't matter here. |
| `oauth` | 4 | `oauth/access_token`, `oauth/access_token_info` | No breaking changes found in v21.0-v26.0 changelogs. Flip this group LAST regardless -- a bad flip here breaks login for every tenant. |
| `insights` | 1 | `me/insights` (`page_messages_total_messaging_connections`, `page_messages_new_conversations_unique`, `page_messages_blocked_conversations_unique`, `page_messages_reported_conversations_unique`) | Meta's v25.0 changelog (developers.facebook.com/docs/graph-api/changelog/version25.0) documents Page/Post/Video/Stories **impression and view** metrics "removed when v26.0 releases" (full list in `fb_graph_insights_dropped_metrics()`). The 4 messaging/conversation metrics this codebase actually requests are **not** on that list -- confirmed by direct comparison. `get_analytics_data()` now calls `fb_graph_insights_unsupported()` before building the URL and returns a clear `monkeybot_gate` error instead of a raw Graph error if a future metric addition collides with the dropped list while this group is not on legacy. Today, with 0 dropped metrics in the request, this is a no-op. |
| `labels` | 6 | `me/custom_labels`, `{label_id}/label` (Custom Labels API) | Checked the Custom Labels API guide (developers.facebook.com/docs/messenger-platform/identity/custom-labels): **currently supported**, "v12.0 or higher is required," no deprecation notice, no sunset date. Contrary to the pre-Phase-3 doc's assumption, this is a normal flippable group, not a dead one. |
| `page_admin` | 8 | delete/hide comment (FB + IG), block commenter, IG mention auto-reply (x2), test-user role grant | **New group, not one of the 5 the original plan named.** These 8 sites are write/moderation calls (`method=delete`, `method=post&is_hidden=true`, etc.) that don't fit "safe reads" (they have side effects) and aren't Send API/oauth/messenger_profile/insights/labels either. Bundling them into `reads` would have mislabelled them as side-effect-free; bundling them into `send` would have conflated page moderation with Messenger's message-tag rules. Isolated into their own flippable group instead, same legacy default. No breaking changes found for these endpoints in the v21.0-v26.0 changelogs. |
| `messaging_feature_review` (hard-pinned, not owner-flippable) | 1 | `me/messaging_feature_review` | Live code path (`Social_accounts::page_review_status()` calls `Fb_rx_login::get_page_review_status()` -- **not dead code**, contrary to being lumped in with the two genuinely-dead endpoints in the original plan). No v26.0 deprecation found, but per the task's instruction to keep anything without a *confirmed* v26.0 path isolated and hard-pinned, `fb_graph_group_version()` ignores config for this group entirely and always returns v20.0. It can be reclassified as a normal flippable group in a follow-up once someone explicitly re-verifies it against v26.0. |

**Deleted as confirmed dead code** (not converted, not pinned -- removed):
`Fb_rx_login::start_reach_estimation()` and `::reach_estimation_count()`
(`me/broadcast_reach_estimations`, `{reach_estimation_id}`). Grepped the entire
codebase: neither function has any caller anywhere. Matches the original
doc's own claim ("the system has never sent a broadcast"). Removed rather than
converted or pinned, with a comment left in place explaining why (Fb_rx_login.php,
where `start_reach_estimation()` used to be).

### Send API message tags -- urgent, independent of this migration

While researching the Send API group (per the original plan's own instruction
to "check every tag still in use"), found that Meta deprecated the
`CONFIRMED_EVENT_UPDATE`, `ACCOUNT_UPDATE`, and `POST_PURCHASE_UPDATE` message
tags **globally, in February 2026** -- API requests using them now get error
code 100, regardless of which Graph API version the request is pinned to. This
codebase's RCN/OTN broadcast tool (`application/views/messenger_tools/rcn_manager/`,
`otn_manager/`, `message_tag_modal.php`) still offers all three as picker
options. Separately, Recurring Notifications (the `NON_PROMOTIONAL_SUBSCRIPTION`
tag / `subscription_token` flow that same tool uses) was discontinued globally
on 2026-02-10 except in AU/EU/JP/KR/UK.

**This is not a v20-vs-v26 URL problem** -- these tags are dead today regardless
of which version `send` is pinned to, so flipping the `send` group's config key
will not fix or break this. It is flagged here because it surfaced during this
research and directly affects the same code area, but fixing it means auditing
and rebuilding the RCN/OTN broadcast tool's tag choices, not adjusting a Graph
API version -- out of scope for this migration ticket and left for the owner to
prioritize separately.

Sources: [Meta's deprecation of Message Tags](https://community.manychat.com/product-updates/meta-s-deprecation-of-the-message-tags-feature-on-messenger-9010),
[Meta's Recurring Marketing Messages API end](https://www.socialmediatoday.com/news/metas-recurring-marketing-messages-api-will-end-this-week/811668/).

### Permanently pinned, not part of this rollout

* `application/libraries/Whatsapp_api.php` -- hardcoded v21.0, WhatsApp Cloud
  API, a separate product Meta actually honours that version for. Untouched
  (per the task's own hard rule).
* `messaging_feature_review` group -- see table above.

### Diagnostic tool

`application/controllers/Graph_diagnostics.php`, admin-only
(`user_type == 'Admin'`, same gate as `Agency.php`, plus `logged_in`). For ONE
connected page the current tenant owns and ONE group, `run()` (POST only) makes
exactly 4 read-only, side-effect-free calls -- `/{page_id}?fields=id,name` and
`/me/picture?redirect=false`, once on `fb_graph_version('legacy')` and once on
`fb_graph_version('default')` -- and renders the two raw responses plus a
field-by-field diff. It never calls the group's real endpoint (never posts to
`me/messages`, never touches `me/messenger_profile`); the chosen group only
labels the report and names the config key flipping it would set. `index()`
(GET, page+group picker) never touches the network at all.

HTTP goes through the same injectable seam `application/libraries/Channels/Http.php`
already uses for the channel adapters (`HttpTransport`/`HttpRequest`/`HttpResponse`/
`CurlTransport`), via a `setHttpTransport()` test seam on the controller.
`tests/graph_migration/run.php` proves, using the REAL controller code
(`ReflectionClass::newInstanceWithoutConstructor()` to skip CodeIgniter's
DB/session bootstrap, a fake DB, and a recording fake `HttpTransport` standing
in for the network -- never a live stack): a GET request, a POST with no page
chosen, a POST with an unrecognised group, and a POST for a page owned by a
different tenant are all refused with **zero** calls reaching the transport;
`index()` also makes zero calls; and the one valid, fully-explicit request makes
**exactly 4** calls, all to the two safe probes, never to `me/messages` or any
other write endpoint.

### Runbook -- rolling out group by group

For each group, in this order (`reads`, `send`, `messenger_profile`, `insights`,
`labels`, `page_admin`, then `oauth` last):

1. Log in as an Admin on the tenant with a real connected page.
2. Go to `/graph_diagnostics`, pick that page and the group, submit.
3. Read the diff. Empty diff on both probes for both HTTP status and every
   field = safe to flip. A non-empty diff means read what changed before
   proceeding -- it will show exactly which field/value differs between v20.0
   and v26.0 for that live page.
4. If safe: in `application/config/config.php` (and the config volume -- see
   "Note" under Phase 0 above, still applies), uncomment that group's
   `$config['fb_graph_group_<group>_version']` line and set it to `'v26.0'`.
   Deploy.
5. Watch real traffic for that group for a while (error logs, that group's
   normal user-facing flows) before moving to the next group.
6. Repeat for the next group. Do `oauth` last, exactly as the original plan
   said -- it breaks login for every tenant if wrong.
7. `labels` and `page_admin` are not part of the original 5-group plan; flip
   them whenever convenient, same procedure.
8. `messaging_feature_review` has no config key and cannot be flipped by this
   mechanism -- leave it on v20.0 until someone explicitly re-verifies it
   against v26.0 and adds it back to the flippable set in
   `fb_graph_helper.php`.

Rolling back a single group is a one-line revert (comment the config line back
out, or delete it) -- no code change, no redeploy of anything except config.

## Rollback

`/root/backups/monkeybot-graph-20260821-200105/ROLLBACK.sh` restores the code tree
and the config volume. Per-file originals are alongside each edited file as
`*.bak-graphver-20260821`.

To undo only the version behaviour without touching code, set
`fb_graph_version_legacy` back in config.php, or (Phase 3) comment out or
delete any `fb_graph_group_<group>_version` key you had set.
