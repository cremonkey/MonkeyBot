# Prompt-to-Flow

Master brief §24 (`/root/MonkeyBot Prompt.md`), cross-referenced with §46 (UX principle: hide
implementation terminology) and §51 (no silent failure).

> When someone asks about hotel booking, ask for arrival date, departure date and guest
> count, calculate the price, ask for confirmation and save the lead.
>
> AI should generate a structured flow. Then show the generated flow visually. User may
> manually edit the generated flow. Flow structure must still be deterministic. The AI
> generates the workflow definition. It does not become the workflow runtime.

## How it works

1. The owner types a plain-language instruction into a "Generate from a description" box on
   the Visual Flow Builder list page (`application/modules/visual_flow_builder/views/
   flow_builder_list.php`) and picks a page/channel, exactly like "Create New Flow" already
   asks for.
2. `Flow_prompt::generate()` (`application/controllers/Flow_prompt.php`) checks the module-315
   permission gate, CSRF, and that the page belongs to the caller's tenant, then calls
   `Flow_prompt_generator::generate()`.
3. `Flow_prompt_generator` (`application/libraries/Flow_prompt_generator.php`) builds a system
   prompt describing the v1 node vocabulary below, calls the AI provider
   (`Ai_provider::completion()`, `purpose = 'flow_generation'`, so it is cost-tracked exactly
   like every other AI call in this codebase), and hands the raw response to
   `Flow_prompt_validator::validate()` — a hand-written, deterministic PHP validator that is
   the ONLY thing that decides accept or reject. The AI's output is never trusted directly.
4. On a JSON-decode failure or a validation failure, ONE retry is sent with the validator's
   own error message appended ("that JSON was rejected: ..., reply again with ONLY corrected
   JSON"). A second failure returns a clear structured failure — `{"status":0,"message":...}`
   — never a thrown exception and never a half-written row.
5. On success, every `postbackId` / `xitFbpostbackId` / `newPostbackId` / `uniqueId` value is
   replaced with a fresh `bin2hex(random_bytes(8))` string, regardless of what the AI produced
   (mirrors `Home::updateArrayValues()`, the same regeneration `duplicate_builder_data()` and
   `copy_builder_data()` already do — see below).
6. Exactly one row is INSERTed into `visual_flow_builder_campaign` — the same table and the
   same shape `Visual_flow_builder::duplicate_builder_data()` writes (`user_id`, `is_system =
   '0'`, `action_type = 'reply'`, `page_id`, `json_data`, `reference_name`, `unique_id`,
   `media_type`). The response is `{status, id, edit_url, message, substitutions}`.

## The draft-not-live guarantee, and how it is achieved

Nothing goes live from this feature. Concretely:

- `Flow_prompt_generator` never calls `Visual_flow_builder::flowbuilder_submit()` — the method
  that atomically publishes a campaign's `json_data` into the live `messenger_bot` /
  `messenger_bot_postback` rows the runtime actually reads. It is not referenced anywhere in
  this feature's code.
- The generator's only side effect is the one `INSERT INTO visual_flow_builder_campaign` in
  `Flow_prompt_generator::generate()`. Everything else (`build_draft()`, the validator, the
  fallback substitutions, id regeneration) is pure — no database, no network beyond the
  injected AI call.
- A draft becomes live the exact same way a hand-drawn flow does: a human opens
  `visual_flow_builder/edit_builder_data/{id}/1/{media_type}` (the `edit_url` this feature
  returns) in the real Visual Flow Builder editor and clicks Save. That Save action is what
  calls `flowbuilder_submit()`, in code this feature never touches.
- Proved in `docker/scripts/staging_prompt_to_flow_tests.sh`: generating a draft creates
  exactly one `visual_flow_builder_campaign` row and leaves `messenger_bot` /
  `messenger_bot_postback` row counts unchanged.

## Access control

Visual Flow Builder has its own legacy module-permission system — module id **315** — and
does **not** use `application/helpers/entitlement_helper.php` (confirmed by a full-codebase
grep before writing any code: `entitlement_helper.php` has no `visual_flow_builder` /
`prompt_to_flow` key, and `Visual_flow_builder.php` never calls `entitlement_require()`).
`Flow_prompt.php` mirrors the exact pattern already used by
`Visual_flow_builder::copy_builder_data()`:

```php
if (!check_module_action_access($module_id = 315, $actions = 1, 'check')) {
    echo json_encode(array('status' => 0, 'message' => ..., ...));
    return;
}
```

`$actions = 1` is the same bitmask `duplicate_builder_data()` and `copy_builder_data()` use
for their create-like actions. No new entitlement key was added, and the entitlement-helper
edit that was staged before this work started (`'prompt_to_flow' => 413`) was reverted — that
system does not gate this module.

`Flow_prompt::generate()` also calls `$this->csrf_token_check()`, which
`Visual_flow_builder.php`'s own AJAX actions never do anywhere (verified by grep; global
`$config['csrf_protection']` is `FALSE` in this app, and `Visual_flow_builder.php` relies only
on `ajax_check()` + session auth). A brand-new endpoint should not inherit that gap, so this is
a deliberate improvement over the existing module's own pattern, not a regression.

## Testing the AI call without a real API key

`Flow_prompt_generator`'s constructor takes an injectable `ai_caller` callable — the same
dependency-injection seam `Automation_engine` uses for its gateway (`$params['gateway']`) and
`Automation_gateway`/`Automation_engine`'s own docblocks describe. This is what
`tests/prompt_to_flow/run.php` uses directly, with zero CodeIgniter, zero database, zero
network.

The HTTP endpoint has no constructor to inject into from the outside, so
`docker/scripts/staging_prompt_to_flow_tests.sh` reaches the same seam through a **test-only
config flag**: `$config['prompt_to_flow_test_fixture']` in `application/config/config.php`,
which defaults to `FALSE` and is never set in production. When truthy (a fixture name string),
`Flow_prompt::generate()` loads `application/helpers/flow_prompt_test_fixture_helper.php` and
passes a fake `ai_caller` into `Flow_prompt_generator`'s constructor that returns a canned flow
instead of calling `Ai_provider`. The staging script flips this flag with `sed` before running
and restores it to `FALSE` afterwards — the exact same pattern
`docker/scripts/staging_sequences_tests.sh` uses for `automation_engine_enabled`. Everything
downstream of the fake network call (parsing, `Flow_prompt_validator::validate()`, the safety
fallbacks, id regeneration, the DB insert) is the real production code path.

## v1 node vocabulary

A node's **type** is identified by which input port it declares — there is no `"type"` field
in `json_data`; this matches `application/libraries/Flow_builder.php`'s own
`$input_output_array` exactly. Node `"1"` is always the entry/reference node.

| Type | Input port | Output port(s) | `data` fields |
|---|---|---|---|
| `reference` (node `"1"` only) | `referenceInput` | `referenceOutput` | `xitFbpostbackId` (unique), `title`, `postbackId`, `triggerKeyword`, `triggerMatchingType`, `labelIds` (optional array) |
| `postback` (a second entry point) | `newPostbackInput` | `newPostbackOutput` | `title`, `postbackId` (unique), `labelIds` (optional) |
| `text` | `textInput` | `textOutput` / `textOutputButton` / `textOutputQuickreply` | `textMessage`, `uniqueId` (unique), `delayReplyFor` (0), `IsTypingOnDisplayChecked` (false) |
| `quickReply` | `quickReplyInput` | `quickReplyOutput` | `postbackId`, `buttonText`, `replyType` (`post_back` \| `new_post_back`), `newPostbackId` (unique, when chaining to a new postback) |
| `userInputFlow` | `userInputFlowInput` | `userInputFlowOutput` | `userInputFlowIdValue` — **must be `'new'`** (inline questions only; referencing a saved flow is out of scope for v1) |
| `userInputFlow` question (chained) | `userInputFlowSingleInput` | `userInputFlowSingleOutput` | `questionType`, `question`, `replyType`, `customField`, `uniqueId` (unique); the last question also carries `newPostbackId` |
| `condition` | `conditionInput` | `conditionOutputTrue` / `conditionOutputFalse` | `all_match` (AND) or `any_match` (OR, exactly one true), parallel arrays `custom_field_variable[]` / `custom_field_operator[]` / `custom_field_variable_value[]`, non-empty |
| `httpApi` | `httpApiInput` | `httpApiOutput` | `httpApiId` (integer, must belong to the calling tenant) |

**Condition operators** (verified against `condition_check()` in
`application/helpers/my_helper.php` — the brief's own illustrative `=`/`!=`/`>`/`<` were not
the real strings): `equal`, `not_equal`, `less_than`, `greater_than`,
`less_than_or_equal`, `greater_than_or_equal`, `contains`, `in_array`, `start_with`,
`end_with`, `has_value`.

**Uniqueness**: every `postbackId`, `xitFbpostbackId`, `newPostbackId`, `uniqueId` value must
be locally unique within one flow. The AI is told it may use any placeholder for these — the
system always regenerates every one of them before saving, mirroring
`Home::updateArrayValues()`'s own field list.

Label assignment (`data.labelIds`) is a **property** on `reference`/`postback` nodes, not a
separate node type.

## The two safety fallbacks (never silent — brief §51)

**Price / lookup steps never invent a number.** If the instruction implies a price or lookup
step:
- and the tenant has at least one active `settings_http_apis` row (`WHERE user_id = ? AND
  status = '1'`), the system prompt gives the AI that row's id and it may emit an `httpApi`
  node referencing it;
- otherwise the AI is told to emit a `text` node with the exact placeholder copy:
  `[Price - connect this to Products & Pricing / an HTTP API step; team to configure]`.

As a second line of defense (defense-in-depth — the validator/generator never simply trusts
that the AI followed instructions), `Flow_prompt_generator::apply_safe_fallbacks()`
independently checks every `httpApi` node's `httpApiId` against the tenant's actual
`settings_http_apis` ids. Any node referencing an id the tenant does not own is rewritten
in-place into the same placeholder `text` node (graph connections, including the *upstream*
node's connection into it, are rewritten to match), and a plain-language line is appended to
the response's `substitutions` array — shown to the owner in the UI, never hidden.

**No "create a CRM deal" node exists anywhere in Visual Flow Builder** (confirmed by a
full-codebase grep during design). The system prompt tells the AI that "save the lead" /
"create a deal" intent must be represented as a `labelIds: ["Lead"]` tag on the relevant
`reference`/`postback` node instead — an Automations rule watching that label is the real path
to a CRM deal, and wiring that rule up is a separate, manual step for the owner. As a second
line of defense, if a misbehaving AI instead marks a node's `data` with a
`crm_deal_request`/`requestedCrmDeal` escape-hatch field, `apply_safe_fallbacks()` converts it
to the same label tag and reports the substitution the same way.

Every substitution is returned in the JSON response's `substitutions` array and shown in the
UI under a visible warning box — brief §51 forbids hiding this kind of thing behind a
generically-successful response.

## Out of scope for v1 (documented, not silently dropped)

Rejected by `Flow_prompt_validator` because their port names are not in the v1 allow-list:
OTN / RCN (`otnInput`/`rcnSingleInput`), carousel (`carouselInput`/`carouselItemInput`),
ecommerce (`ecommerceInput`), image/video/audio/file media nodes
(`imageInput`/`videoInput`/`audioInput`/`fileInput`/`facebookMediaInput`), sequence/drip nodes
(`sequenceInput`/`sequenceSingleInput`), the generic template node
(`genericTemplateInput`), the OpenAI reply node (`openaiInput`), delay/button/trigger/label
nodes used standalone (`delayInput`/`buttonInput`/`triggerInput`/`labelInput`), and reusing an
existing saved `userInputFlow` campaign by id (`userInputFlowIdValue` must be `'new'`).

## Extending the vocabulary later

1. Add the new port names to `Flow_prompt_validator::ALLOWED_PORTS` and a
   `INPUT_PORT_TYPE` entry.
2. Add its structural requirements to `Flow_prompt_validator::validate()`'s per-type switch
   (see the `text` / `quickReply` cases for the shape).
3. Add the type's field vocabulary to `Flow_prompt_generator::system_prompt()`.
4. Add an accept fixture (and, if the type has failure modes worth catching, a reject fixture)
   to `tests/prompt_to_flow/run.php`.
5. Cross-check the new fields against `application/libraries/Flow_builder.php` and
   `application/modules/visual_flow_builder/controllers/Visual_flow_builder.php` — they remain
   the authoritative source of truth for what the real editor and `flowbuilder_submit()`
   expect, since this feature only ever produces a draft the human re-saves through them.

## Test results (2026-09-22, this branch)

- `tests/prompt_to_flow/run.php` (DB-free): **37/37 passed** — validator accept (minimal,
  condition-branch, hotel-booking with `httpApi`, hotel-booking with the text fallback) and
  reject (unknown node type, out-of-scope `carousel` node, dangling connection, duplicate
  unique-id field, malformed/empty condition arrays, unknown condition operator); generator
  retry-then-structured-failure on invalid AI JSON, recovery on a corrected retry, the
  CRM-deal-request and unowned-httpApi fallbacks (each reported in `substitutions`, never
  hidden), fresh unique ids on every success, and a blank instruction failing fast without
  ever calling the AI.
- `docker/scripts/staging_prompt_to_flow_tests.sh` (HTTP+DB, isolated stack, fake AI fixture
  via the test-only config flag above): see the deployment report / session notes for the pass
  count from the run — covers a member without module 315 being denied, CSRF enforcement,
  tenant isolation (IDOR) against another tenant's page, the happy path producing exactly one
  valid `visual_flow_builder_campaign` row, the draft-not-live guarantee (`messenger_bot` /
  `messenger_bot_postback` row counts unchanged), a blank instruction never calling the AI, and
  admin not being blocked by the module-315 gate.
- `docker/scripts/staging_phase0_tests.sh` re-run on the same isolated stack for regression.

## Files

- `application/libraries/Flow_prompt_validator.php` — the deterministic schema validator.
- `application/libraries/Flow_prompt_generator.php` — system prompt, AI call + retry, the two
  safety fallbacks, unique-id regeneration, the one DB insert.
- `application/controllers/Flow_prompt.php` — the `generate()` AJAX endpoint.
- `application/helpers/flow_prompt_test_fixture_helper.php` — test-only, see above.
- `application/modules/visual_flow_builder/views/flow_builder_list.php` — the "Generate from a
  description" modal.
- `tests/prompt_to_flow/run.php`, `docker/scripts/staging_prompt_to_flow_tests.sh`.
