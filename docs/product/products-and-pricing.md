# Products & Pricing (per page, any business)

Owner request (2026-09-23): prices and products must belong to a **specific page**. Any newly
connected page can have **its own** products and prices, and the price list is **not only
rooms + day-use**. Screen: `/pricing` (module 408). Code: `application/helpers/pricing_helper.php`,
`application/controllers/Pricing.php`, `application/views/admin/pricing/index.php`,
`application/libraries/Ai_tools.php` (`calculate_price`, `get_room_media`).

## Scoping model

`pricing_config` holds one row per **tenant per scope**:

| column | meaning |
|---|---|
| `user_id` | tenant |
| `channel_type` | `''` (default list), `fb`, `openwa`, `wa_cloud`, `tg`, `web` |
| `target_id` | `''` for the default list, else the id of the connection (below) |

`UNIQUE(user_id, channel_type, target_id)` (migration `2026-09-29-pricing-per-page.sql`, which
replaced the old `UNIQUE(user_id)`). `pricing_config_history` carries the same two columns so
every audit row says which page it was for.

| channel_type | target_id | covers |
|---|---|---|
| `fb` | `facebook_rx_fb_page_info.id` | the Facebook page: Messenger, comments, **and its Instagram** |
| `openwa` | `openwa_accounts.id` | personal WhatsApp number (OpenWA) |
| `wa_cloud` | `whatsapp_accounts.id` | WhatsApp Cloud API number |
| `tg` | `telegram_accounts.id` | Telegram bot |
| `web` | `webchat_settings.widget_key` | one website chat widget |

`openwa` and `wa_cloud` are deliberately separate. Both tables use small numeric ids, and the
AI-agent assignments' shared `wa` channel cannot tell OpenWA #5 from Cloud #5. Price lists do
not reuse that namespace.

**Every row that existed before the migration is the default list (`''`, `''`).** A tenant that
never gives a page its own list behaves exactly as before: same list, same rule 10 text, same
tool definition, same totals. `staging_pricing_pages_tests.sh` checks this by comparing the
reply-path output before and after running the migration.

## Resolution order (reply time)

`pricing_resolve_scope($user_id, $social_media, $page_id)` maps what each bot passes into
`get_ai_reply_open_ai()` to a scope. Every lookup is restricted to the tenant's own rows:

| bot | passes `$social_media`, `$page_id` | resolves to |
|---|---|---|
| Messenger_bot, Comment_automation | `fb` (Messenger_bot: `ig` for Instagram DMs), the FB page id string; a page row id also resolves | `fb:<page row id>` |
| Instagram_reply | `ig`, the FB page id string | `fb:<page row id>` (same list as the page) |
| (any) | `ig`, the Instagram business account id | `fb:<linked page row id>` |
| Openwa_bot | `wa`, `openwa_accounts.session_id` | `openwa:<account id>` |
| Whatsapp_bot | `wa`, `whatsapp_accounts.id` | `wa_cloud:<account id>` |
| Telegram_bot | `tg`, `telegram_accounts.id` | `tg:<id>` |
| Webchat | `web`, the `widget_key` (`webchat`/empty → first widget) | `web:<widget_key>` |
| Tiktok_cron, IG mentions (no page id) | — | default |

If a `wa` page id matches **both** an OpenWA session name and a Cloud account id (a numeric
session name), the resolver returns the default list. It never guesses between two numbers.

Then `pricing_get_config($user_id, $scope)` returns:

1. the page's **own active** list, else
2. the **default active** list, else
3. `null`: no pricing on this page. Rule 10 is not added and the tool tells the model to hand prices to the team.

If the owner switches off a page's own list, that page falls back to the default list.
`pricing_get_config($user_id)` (one argument) still returns the default list. The Setup Guide
(step 5) writes one page-scoped list per page chosen for the business, never over an existing
list, and writes the default list only on an account with no lists at all when the owner ticks
*Use this list for all my pages* (see `docs/product/onboarding-and-templates.md`).

## Catalog schema (any business)

Every section is optional. A list may contain only a catalog, only rooms/day-use, or both.

```json
"catalog": {"items": [
  {"name": "قص شعر", "category": "Hair", "price": 150, "unit": "session",
   "variants": [{"name": "أطفال", "price": 100}],
   "aliases": ["haircut"], "note": "…"},
  {"name": "Bridal package", "unit": "item", "price_on_request": true},
  {"name": "Paint", "price": 12.5, "unit": "custom", "unit_label": "tube"}
]}
```

- `unit`: `item | piece | hour | session | person | night | day | month | kg | custom`. `custom` requires `unit_label`.
- `price` must be > 0. It can be left out when `price_on_request` is true, or when the item has `variants` (each variant has its own price > 0).
- Item names must be unique (case-insensitive), and so must variant names within an item.
- The existing rooms / day-use / children validation is unchanged. A config needs at least one priced section.

`pricing_calc_item($cfg, $name, $variant, $quantity)` returns an exact result: unit price ×
quantity, where a named variant's price replaces the base price. The arithmetic is done in
integer piastres, so there is no float drift. Names are resolved by `media_match()`, the same
alias-aware resolver used for rooms and photo albums, so there is only one copy of it. It never guesses:

| case | result |
|---|---|
| unknown name | `ok=false, reason=unknown`: the AI says the team will confirm |
| ambiguous name (two items share an alias) | `total=null, needs_team, candidates=[…]`: the AI asks which one |
| variants, no base price, no option named / unknown option | `total=null, candidates=[options]` |
| `price_on_request` | `total=null, needs_team` |

## AI behaviour

- `calculate_price` gains `type: "item"` (`item`, `variant`, `quantity`). The tool definition is built for the page's resolved list by `pricing_tool_definition()`. A list without a catalog, or no list, gets the **original definition byte for byte**. A list with a catalog gets an enum limited to the sections it actually has, for example `["item"]` for a salon.
- `t_calculate_price` and `t_room_media` resolve the list from the tools context (`page_id`, `social_media`), not from `user_id` alone. `Ai_provider` passes the context to `openai_tools()` and `anthropic_tools()`.
- **Price guard:** an item result is appended to `$CI->ai_price_facts`, the same array the stay and day-use totals use. `Home::get_ai_reply_open_ai` feeds that array to `ai_verify_price_grounding()` as "Computed totals (authoritative, from the pricing tool)". A correct catalog price is therefore never blocked. The fact includes the unit price, the quantity and the total, e.g. `قص شعر × 2 جلسة = 300 جنيه (150 جنيه للواحد)`.
- Rule 10 in the system prompt comes from `pricing_prompt_rule()` for the resolved list. A list without a catalog gets the original rule text unchanged. A list with a catalog mentions stays and day-use only if the list has them, and always mentions products. No list means no rule 10.
- The outgoing link gate (`media_all_urls`) whitelists the resolved page's albums.

Known limitation: a list with no catalog keeps the **original** rule 10 and tool text, even if it
has rooms only and no day-use. This keeps existing tenants byte-identical. Sections drive the
wording only once a catalog is present.

## Owner UI (`/pricing`)

- **Price list for**: *All pages (default list)* plus every connected page, number and widget, shown by name (`pricing_connected_targets()`, built on `ai_agent_connected_channels()`). Pages that have their own list are marked "✓ own list". A page without one shows *"No own list — this page currently uses the default list"*.
- **Products & services** is the first tab for every business: name, category, unit, price, price on request, options (`Small=100, Large=150`), other names and a note. The **Rooms / Day use / Children** tabs appear only when the list already has those sections, or when the owner ticks *"This page sells stays / day visits"*.
- **Copy this list to…** another page (asks before replacing a list that already exists). **Move the default list to a page** is on the default list. **Remove this page's own list** sends the page back to the default list.
- The test calculator supports items (option + quantity). The history shows which page each change was for.
- Every AJAX endpoint (`save`, `calc`, `toggle`, `copy`, `move`, `remove`) checks the CSRF token, is scoped to the tenant, and rejects a scope that is not one of the tenant's own connections (IDOR). An unknown channel is rejected; it is never silently treated as the default list.

## How to attach an existing list (e.g. Byoot's rooms) to its page

1. Open **Products & Pricing** and keep *All pages (default list)* selected.
2. In **Move the default list to a page…**, pick the Byoot Facebook page and press **Move**.
3. From then on only that page, and its Instagram, quotes the Byoot rooms. Other pages have no list until you give them one, so they hand price questions to the team.

To keep the Byoot prices as the fallback for every page and give one page different prices,
select that page and save a list there, or use **Copy this list to…** and edit the copy.

## Tests

- `tests/pricing/run.php` (DB-free): resolver per channel (including the openwa #5 vs cloud #5 collision and foreign pages), page → default → null, legacy 1-arg and pre-migration behaviour, item calculation exactness, the validator, save/copy/move/delete with audit scope, the IDOR gate, byte-identical rule and tool text, and `Ai_tools::execute` returning per-page prices into `ai_price_facts`.
- `docker/scripts/staging_pricing_pages_tests.sh` (isolated stack): the same checks end to end over HTTP and DB, using the real reply-path code through `docker/scripts/pricing_ai_probe.php`, plus migration before/after identity.
- `docker/scripts/staging_pricing_tests.sh`: the original 16 checks, still passing.
