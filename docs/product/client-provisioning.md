# Client provisioning from the Agency Control Center

Phase 11 — brief §31 (Agency Control Center), §32 (client onboarding), §40 (audit log),
§46 (owner language), §51 (no silent failure).

Before this: Creative Monkey could only **view** existing clients on the Agency Control
Center (`application/controllers/Agency.php`). Creating a brand-new client account still
needed the public sign-up form or a raw DB insert. This closes that gap — an agency admin can
now onboard a client end-to-end (account + package + optional starter template) without
leaving the admin panel.

---

## 1. What it does

A "New client" button on `/agency` opens a modal: business name, contact email, a package
picked from the real `package` table, and an optional industry template. Submitting it:

1. Validates every field with a clear, specific message per failure (never a raw DB error).
2. Creates one `users` row — the client's account.
3. If a template was chosen, creates one `onboarding_state` row so the client's first visit
   to the Setup Guide (`/onboarding`) starts on step 1 with that business type already picked,
   instead of blank.
4. Shows the generated temporary password **once**, in the modal, clearly labelled "copy this
   now — it will not be shown again."
5. Logs an audit line: which admin, which client, which package, which template (or none).

No new tables were needed for the account itself — a provisioned client is an ordinary
`users` row and an ordinary `onboarding_state` row, written through the same shape the stock
sign-up flow and the Setup Guide already use. See §5 for the one column that *was* added.

---

## 2. Where the code lives

| File | Role |
|---|---|
| `application/helpers/provisioning_helper.php` | All the logic: validation, password generation, the transaction. Takes an injectable `$db`, same pattern as `pricing_helper.php`'s `pricing_save_config()`. |
| `application/controllers/Agency_provision.php` | Thin HTTP adapter: admin-only gate, CSRF, reads POST fields, calls the helper, logs the audit line, returns JSON. |
| `application/controllers/Agency.php` | Unchanged except: now also hands the package list and template list to the view for the modal's dropdowns. Still 100% read-only itself — see its own docblock. |
| `application/views/admin/agency/index.php` | The "New client" button, modal, and its `$.post` / CSRF / iziToast JS — same conventions as `admin/pricing/index.php`. |
| `assets/backup_db/migrations/2026-09-27-client-provisioning.sql` | Adds `users.provisioned_by` / `users.provisioned_at`. |
| `tests/provisioning/run.php` | DB-free unit tests (FakeDb) — validation, duplicate email, password strength/uniqueness, and the transaction-rollback guarantee. |
| `docker/scripts/staging_provisioning_tests.sh` | HTTP + real-DB end-to-end suite. |

### Why a separate controller instead of adding to Agency.php

`Agency.php`'s own docblock states "nothing here writes data" and every one of its methods is
a grouped `SELECT`. Provisioning opens a transaction and writes two tables. Keeping it in
`Agency_provision.php` means that promise stays true by inspection (not by convention), and
the write path can be unit-tested without dragging in Agency's aggregate dashboard queries.
The two controllers share the exact same admin-only gate, copied from `Agency.php`'s
constructor on purpose rather than factored into a shared base, so each stays readable on its
own.

---

## 3. Field set, matched to the stock signup — not reinvented

`Home::sign_up_action()` (public sign-up) and `Admin::add_user_action()` (existing manual
admin user creation) were both read before writing this. Provisioning reuses their
conventions exactly:

| Field | Convention reused |
|---|---|
| `password` | `pw_hash()` from `password_helper.php` (SPEC-00 Task A) — the same call both stock flows make. |
| `user_type` | `'Member'` |
| `status` | `'1'` — active immediately. (Sign-up sets `'0'` pending an activation email *unless* `enable_signup_activation` is off, in which case it also sets `'1'`. A client an admin is provisioning by hand needs no activation email, so this flow always goes straight to `'1'`.) |
| `deleted` | `'0'` |
| `expired_date` | `date('Y-m-d', strtotime('+'.$validity.' day', strtotime(today)))` — the exact formula `sign_up_action()` uses for the default package, applied here to whichever package the admin picked. |
| `affiliate_id` | `0` (sign-up's column; provisioning has no affiliate source). |

The only fields provisioning adds beyond the stock set are `provisioned_by` / `provisioned_at`
(see §5) — nothing else.

---

## 4. Template pre-seed: how the first login lands on step 1, filled in

If the admin picks a business type, `provisioning_create_client()` writes one
`onboarding_state` row in the exact shape `Onboarding::state()` creates the first time a
tenant opens the Setup Guide on their own — `user_id`, `current_step = 1`, the chosen
`template_key`, `data_json = '{}'`, `updated_at = now`. A plain `insert()` is enough here
(not `Onboarding::state()`'s `INSERT ... ON DUPLICATE KEY UPDATE`) because the `user_id` was
just created by this same function — it is structurally impossible for a row to already
exist.

`Onboarding::step($n)` only lets a client past step 1 once a template is set
(`template_of($st) !== null`); with no template chosen at provisioning time the client starts
on step 1 exactly like a self-signed-up client does. Nothing about the wizard itself changed —
provisioning only pre-fills the one row it already reads.

---

## 5. What was actually missing: `users.provisioned_by` / `provisioned_at`

The brief's own instruction was: add a table only if a real gap is found. The one real gap —
nothing recorded *which admin* created an account for accounts made outside public sign-up —
got two nullable columns, not a new table:

```sql
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `provisioned_by` INT(11) DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `provisioned_at` DATETIME DEFAULT NULL;
ALTER TABLE `users` ADD KEY IF NOT EXISTS `idx_provisioned_by` (`provisioned_by`);
```

Both are `NULL` for every row created any other way (public sign-up, legacy imports, the
existing `Admin::add_user_action()` manual path) — this is additive and changes nothing about
those flows. Idempotent: `ADD COLUMN IF NOT EXISTS` / `ADD KEY IF NOT EXISTS`, safe to re-run.

---

## 6. Validation and the transaction

Every input is checked before anything is written, with one message per problem — never a raw
DB constraint error reaching the admin:

- **Business name** — required, non-blank after `strip_tags()`.
- **Contact email** — required, valid format, and checked against every existing `users.email`
  (not filtered by `deleted`, matching `sign_up_action()`'s own `is_unique[users.email]` rule).
- **Package** — must be a real, non-deleted row in `package`.
- **Template** (optional) — if given, must be a key `industry_template_load()` resolves, and
  must pass `industry_template_validate()` (the same structural check the Setup Guide itself
  runs on step 1).

The `users` insert and the `onboarding_state` insert (when a template was chosen) run inside
one `$db->trans_start()` / `trans_complete()` block. Right before the second insert, the
template is **reloaded and revalidated** — deliberately redundant with the pre-check above.
This is defense-in-depth against the one scenario a pre-check cannot rule out: the template
file changing or disappearing between the check and the write. If that recheck ever fails,
`trans_rollback()` removes the just-inserted `users` row too — provisioning never leaves a
half-created account. `tests/provisioning/run.php` proves this deterministically with a
`FakeDb` and a template stub rigged to pass its first validation and fail its second (see the
"transaction rollback" section of that file for exactly how). Over real HTTP,
`staging_provisioning_tests.sh` proves the same guarantee from the observable side: every
rejected `provision_client()` call — including ones that name a template — leaves zero rows in
both `users` and `onboarding_state`.

---

## 7. The one-time password

`provisioning_generate_password()` builds a 16-character password guaranteeing a lowercase
letter, an uppercase letter, a digit and a symbol (from alphabets that exclude
easily-confused characters like `l`/`1`/`O`/`0`), then shuffles it (Fisher–Yates) so the
guaranteed characters aren't always in the same position. It uses `random_int()` throughout.

The plaintext password:

- **Is returned once**, in the JSON response of a successful `provision_client()` call.
- **Is shown once**, in the modal, under a clearly labelled "copy this now — it will not be
  shown again" warning, with a copy button.
- **Is never logged.** `Agency_provision::provision_client()`'s audit line
  (`log_message('error', 'audit: client ... provisioned by admin ...')`) deliberately never
  includes it — only the hash (`pw_hash($plain_password)`) is stored, in `users.password`,
  exactly where every other account's password lives.
- Is not re-fetchable afterwards by any code path — there is no "show password again" action.

---

## 8. Audit trail (brief §40)

Every successful provision writes one line via `log_message('error', ...)`, the same
convention `Automations.php` and `Sequences.php` already use for their own sensitive admin
actions (`audit: automation ... saved/deleted/... by user ...`) — chosen deliberately so this
sits in the same searchable trail rather than inventing a second logging convention. It
records who (admin id), what (client id + email), when (the log timestamp), and which
package/template — never the password. `users.provisioned_by` / `provisioned_at` (§5) is the
durable, queryable half of the same trail: `SELECT * FROM users WHERE provisioned_by = <id>`
answers "which clients did this admin onboard" without grepping logs.

---

## 9. Security notes

- **Admin-only, not a tenant entitlement.** Gated in `Agency_provision`'s constructor exactly
  like `Agency.php`'s own (`user_type == 'Admin'`, else `redirect('home/access_forbidden')`).
  This is deliberately *not* wired through `entitlement_helper.php` — it is an agency action,
  not a package feature a tenant could ever be granted.
- **CSRF-checked** the same way every other write action in the app is
  (`csrf_token_check()`), verified by `staging_provisioning_tests.sh`.
- **No orphaned accounts.** See §6 — the transaction guarantee.
- **Tenant isolation on day one.** The new client's `onboarding_state` row is scoped to its
  own `user_id` like every other tenant's; nothing about provisioning grants cross-tenant
  visibility. Verified in `staging_provisioning_tests.sh`.
