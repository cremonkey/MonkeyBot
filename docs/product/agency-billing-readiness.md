# Agency Control Center — usage/billing readiness (brief §11)

- **Brief:** §11 (usage/billing readiness), §31 (Agency Control Center)
- **Code:** `application/controllers/Agency.php`, `application/helpers/agency_billing_helper.php`,
  `application/views/admin/agency/index.php`
- **Migration:** none — reads existing `package.monthly_limit`, `users.expired_date`/`package_id`, `usage_log`
- **Tests:** `tests/summary_billing/run.php` (unit, no DB — percentage/expiry-flag math against
  constructed fixtures), `docker/scripts/staging_summary_billing_tests.sh` (HTTP+DB — a seeded
  near-expiry package renders correctly on the real page)

## What this is

Extends the existing, admin-only, read-only Agency Control Center (`Agency::index()`) with, per
client row:

- **Package name** (`package.package_name` via `users.package_id`).
- **Renewal status**: days until `users.expired_date`, flagged red ("danger") when the account
  is already expired or has fewer than 7 days left, exactly matching the badge the page already
  showed for "package expired" (this only adds the *days* visibility and the *due-soon* case
  that wasn't flagged before).
- **AI usage vs the package's `monthly_limit`**, shown as a simple progress bar + percentage.

A **"Renewals due soon"** count was added to the top totals row, next to the existing
clients/channels/waiting/missed/leads/AI-cost tiles, following the same `$totals` array pattern
already in `Agency.php`.

This is **readiness / visibility only** — not a billing or invoicing system. Payment collection,
plan changes and package purchases already exist through the stock `Payment.php` gateways and
are untouched here.

## `monthly_limit` — the JSON shape, and where it was found

`package.monthly_limit` is a JSON object: **`{"<module_id>": "<limit>", ...}`**, where the key is
a `modules.id` and `0` (or the key being absent) means unlimited. This is not a new format — it
is the exact shape already read by:

- `Home::_check_usage()` (`application/controllers/Home.php:3863-3917`) — the real enforcement
  path every stock module limit goes through. Its two branches are mirrored exactly by
  `agency_billing_helper.php`:
  - `modules.extra_text === ''` (e.g. module 340 "Bot - AI Reply", module 66 "Facebook Pages -
    Subscribers/Page") -> **cumulative** usage, `SUM(usage_count)` from `usage_log` with no
    month filter.
  - `modules.extra_text` non-empty (e.g. `'month'`) -> **month-scoped**, filtered to the current
    `usage_month`/`usage_year`.
- `Payment.php` (writing `monthly_limit`/`bulk_limit` when a package is created/edited) and every
  admin/member package screen that reads it back
  (`application/views/admin/payment/{add,edit,details}_package.php`,
  `application/views/admin/user/details_user.php`, `application/views/member/{buy_package,usage_log}.php`).

The readiness metric shown on the Agency page is **module 340, "Bot - AI Reply"**
(`assets/backup_db/initial_db.sql`, `modules` table) — a real, pre-existing module id, picked as
the closest match to the brief's own example ("message count, AI calls"), not invented. Its
`extra_text` is `''`, so usage is the cumulative `usage_log` total for that module/tenant.

## Math (`application/helpers/agency_billing_helper.php`, unit-tested, DB-free)

- `agency_billing_days_until_expiry($expired_date, $now)` — whole days until expiry; negative
  once expired; `NULL` when there is no expiry to compare (Admin/agency accounts, or an
  all-zero `'0000-00-00'` date some legacy rows carry).
- `agency_billing_expiry_status($days, $due_soon_days = 7)` — `status` of `none|expired|due_soon|ok`;
  `flag` is `'danger'` for `expired` and `due_soon` (brief: "flag red if <7 days or already
  expired"), else `null`.
- `agency_billing_is_renewal_due_soon($days, $due_soon_days = 7)` — the boolean the totals-row
  count sums.
- `agency_billing_usage_percent($limit_raw, $usage)` — `limit <= 0` (including a missing key)
  reads as unlimited (`percent: null`), matching `Home::_check_usage()`'s own "0 means unlimited"
  rule; otherwise `percent = round(min(100, usage/limit*100))`.
- `agency_billing_decode_monthly_limit($json)` — defensive `json_decode`; a malformed or empty
  value reads as "no limits set" (empty array), never throws.

## Why no new DB query for "usage vs limit" duplicates `Home::_check_usage()`

`Agency::index()` already aggregates read-only, per-tenant `GROUP BY user_id` queries
(`_count_by_user`, `_sum_by_user`, `_ai_cost_by_user`). The new `_module_usage_by_user($module_id,
$is_monthly)` follows the exact same shape (one grouped query, `table_exists()`-guarded) rather
than calling `Home::_check_usage()` once per tenant per row (which is written for a single
logged-in user's own session/package, not a cross-tenant admin aggregate, and would be N+1).
