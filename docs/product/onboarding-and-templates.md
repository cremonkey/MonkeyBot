# Onboarding wizard & industry templates

Phase 11 — brief §32 (client onboarding), §33 (industry templates), §46 (owner language),
§65 (product north star).

The goal from §65 is one path: create an account → connect a channel → pick `Hotel` → create an
AI employee → upload information → set prices → test a conversation → **Go live**. The Setup
Guide is that path. It is a *driver*, not a feature: every step writes through the save path the
underlying feature already has.

---

## 1. Templates are data

A vertical is one JSON file in `application/config/industry_templates/`:

```
hotel.json  clinic.json  ecommerce.json  real_estate.json
```

Nothing in the platform branches on the template key. `application/helpers/industry_template_helper.php`
loads a file, validates it, and compiles it; `application/controllers/Onboarding.php` then applies the
result through the existing features. There is no per-vertical controller, view, table or code path
(§33: *"Do NOT create hardcoded vertical-specific platform forks"*).

### Template shape

| Field | What it is |
|---|---|
| `key`, `schema_version` | file identity; `key` must match the filename |
| `display_name`, `tagline` | bilingual `{en, ar}` — shown on step 1 |
| `ai_employee.name_suggestion / role / goal` | bilingual; the owner can override all three on step 3 |
| `ai_employee.tone` | must be exactly `Friendly Egyptian Arabic` (§17) |
| `ai_employee.capabilities[]` | `{key, en, ar}` — what the AI is allowed to do |
| `ai_employee.restrictions.max_discount_percent` | must be `0` in a starter template |
| `ai_employee.restrictions.rules[]` | `{key, en, ar}`; must include `never_invent_prices` and `no_discount` |
| `starter_faq[]` | 6–10 `{key, question{en,ar}, answer{en,ar}}`; every answer must carry the `عدّلني` / `EDIT ME` marker |
| `pricing_supported` | `true` only where the price calculator can actually serve the vertical |
| `pricing_note` | bilingual; on a `false` vertical this is what the owner is told instead |
| `pricing_config` | the `pricing_config` object, or `null`. Must be `null` when `pricing_supported` is false |
| `working_mode` | `recommended` (`always_on` \| `staff_hours_silent`) + `hours_enabled/start/end/days` + a bilingual `note` |
| `pipeline.name`, `pipeline.stages[]` | `{name, stage_type, color}`; must contain one `won` and one `lost` stage |
| `qualification_questions[]` | `{key, en, ar}` — compiled into the prompt in order |

`industry_template_validate()` enforces all of the above, and for a `pricing_supported` template it
runs the **real** `pricing_validate_config()` from `pricing_helper.php` — not a copy of its rules.

### Why only `hotel` has a price list

The price calculator (`pricing_helper.php`) understands exactly two shapes today:
`accommodation.rooms` (nights, capacity, multi-night offers) and `day_use.packages` (per person,
group offers), plus a children age-band policy. That fits a hotel or resort.

It cannot express a consultation fee, a product SKU, or an instalment plan. So `clinic`,
`ecommerce` and `real_estate` set `pricing_supported: false` and `pricing_config: null`, and their
step 5 tells the owner — in both languages — to keep prices in the starter FAQ or in Knowledge,
where the AI reads them verbatim and never computes anything. Writing a half-supported
`pricing_config` for those verticals would produce confident wrong numbers, which is the one
failure mode the price guard exists to prevent.

### Adding a vertical

1. Copy `clinic.json` (no pricing) or `hotel.json` (pricing) to `application/config/industry_templates/<key>.json`.
2. Set `key` to the filename. Write natural Egyptian Arabic — it is customer-facing text, not a translation.
3. Keep `starter_faq` between 6 and 10 entries and keep the `عدّلني` marker in every Arabic answer.
4. Give the pipeline one `won` and one `lost` stage.
5. Only set `pricing_supported: true` (and a `pricing_config`) if the calculator can really compute
   that vertical's prices. Otherwise leave it `false` / `null` and write a clear `pricing_note`.
6. Add the key to `$EXPECTED` in `tests/onboarding/run.php` and run it. No PHP change is needed
   anywhere else, and no migration.

---

## 2. What each step writes

Access: entitlement key `onboarding` → module **410** (`entitlement_helper.php`). Admin is always
allowed. Every POST is AJAX + CSRF checked and scoped to the session's tenant; nothing takes a
user id from the request.

| Step | Screen | Writes |
|---|---|---|
| 1 | Business type | `onboarding_state.template_key`, `data_json.business_name`. Nothing else. |
| 2 | Channels | **Nothing.** Read-only status from the channel tables plus links to the existing connect pages (`social_accounts`, `whatsapp_bot`, `openwa_bot`, `telegram_bot`, `webchat`). Only `data_json.channels_seen_at` is recorded. |
| 3 | AI Employee | One `ai_agent_profiles` row per tenant (tracked as `data_json.profile_id`), with the persona compiled by `industry_template_compile_prompt()` into `agent_name`, `instruction_to_ai` and `sales_system_prompt`. Then one `ai_agent_assignments` row per connected channel **that has none yet** — an existing assignment is never overwritten or deleted. |
| 4 | Knowledge | `ai_faq` rows, one per starter question, keyed `source_key = 'tpl:<template>:<faq key>'`. Long documents stay on the existing Knowledge page; this step only sets the quick answers. |
| 5 | Products / Pricing | `pricing_supported` verticals: `pricing_save_config()` — same validation, same `pricing_config_history` audit row as the Products & Pricing screen. Other verticals: **no `pricing_config` at all**, only `data_json.pricing_mode = 'faq_only'`. |
| 6 | Lead pipeline | `crm_ensure_pipeline()` (the CRM's own function), then any template stage whose name is missing is appended to `crm_stages`. Existing stages keep their id, position and deals. |
| 7 | Working mode | `hours_enabled / hours_start / hours_end / hours_days` on every `facebook_rx_fb_page_info` and `openwa_accounts` row of that tenant, normalised by `working_hours_normalize_time()` — the same columns and format the channel settings pages write. `hours_enabled = '1'` means *staff shift window*: the bot stays silent inside it. |
| 8 | Test conversation | Nothing but `data_json.tested_at` on success. The reply comes from `ai_agent_preview_reply()`, the same function `/ai_agents/preview` calls. With no AI credentials or no key it says so plainly and shows **no reply** — it never fabricates one. |
| 9 | Go live | `onboarding_state.completed_at`. The checklist itself is computed live from the feature tables, never stored, so a tick always means the feature really is configured. Required items (business type, a connected channel, an AI employee, starter questions) block Go live; the rest are advisory. |

### Idempotency and deletion

Every step can be re-run:

* step 3 updates the same profile row; assignments are added, never replaced;
* step 4 updates the keyed rows in place — and a **blank** answer field means *keep what is stored*,
  so a re-run cannot wipe an answer the owner wrote. Hand-written FAQ rows have `source_key = NULL`
  and are never in the wizard's map at all;
* step 5 short-circuits when the config is unchanged, so no spurious audit rows;
* step 6 adds only missing stage names; a stage the owner renamed is left alone and the template name
  is added beside it;
* step 7 overwrites the tenant's own hours only.

**The wizard never deletes anything.** Switching vertical adds the new template's starter FAQ beside
the old one rather than removing it.

The pure decision function behind step 4, `industry_template_faq_plan()`, is what the controller
executes, so `tests/onboarding/run.php` tests the real rule against a fake table rather than a
re-implementation of it.

---

## 3. Schema

`assets/backup_db/migrations/2026-09-24-onboarding.sql` (idempotent):

* `onboarding_state` — `user_id` PK, `current_step`, `template_key`, `data_json`, `completed_at`,
  `updated_at`. Wizard bookkeeping only; no tenant business data lives here.
* `ai_faq.source_key VARCHAR(64) NULL` + unique index `(user_id, source_key)`. NULL for every
  hand-written FAQ, and MySQL/MariaDB unique indexes ignore NULL duplicates, so existing rows are
  unaffected.
* module `410` ("Setup Guide"), added to every non-deleted package, and the sidebar row
  `url='onboarding'`, `module_access='410'`.

---

## 4. Owner language (§46)

The screens say *Setup Guide*, *AI employee*, *Knowledge*, *Prices*, *Lead stages*,
*Stay quiet while my team is working*, *Go live*. They never say module id, entitlement, RAG,
prompt, webhook, cron, `bot_paused_until` or `pricing_config`. English labels carry Egyptian Arabic
helper text underneath; the customer-facing strings the AI actually uses are Arabic first.

---

## 5. Tests

| Suite | Scope |
|---|---|
| `tests/onboarding/run.php` | offline: all four template files against the schema, the hotel skeleton against the real `pricing_validate_config()` **and** the real calculator, persona compilation, starter-FAQ idempotency against a fake `ai_faq` table with the migration's unique index. 51 cases. |
| `docker/scripts/staging_onboarding_tests.sh` | HTTP + DB against a running staging stack: all nine steps for a member, what each one writes, re-runs producing no duplicates, an owner's edits surviving a re-run, tenant isolation, the module gate, CSRF, Arabic stored intact, and step 8 refusing to fake a reply. 94 cases. |

Run the staging suite with `STAGING_URL` / `STAGING_APP` / `STAGING_DB` pointing at your own stack —
never the shared one — after `assets/backup_db/initial_db.sql`, `cron_hub migrate` and
`docker/scripts/staging_seed.sh`. It rewrites `package.module_ids` for package 90 and the rows of
users 11 and 12 only, all synthetic.
