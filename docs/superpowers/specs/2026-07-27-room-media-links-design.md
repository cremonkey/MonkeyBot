# SPEC-31 — Room photo links + per-room services (Byoot Bay / Kemzo)

Date: 2026-07-27
Status: approved design, not implemented
Owner decisions captured in this doc are final unless re-raised.

## Problem

The bot can quote room prices but has no way to show the customer what a room
looks like, and no per-room service list. The owner supplied one album URL per
room on `kemzo.byootbayeg.com` plus the resort gallery for Day Use.

Writing those URLs into the profile prompt is the obvious move and is rejected.
It is the same failure class as SPEC-21's ungrounded prices: the model
cross-maps a real value from one item onto another (measured repeatedly — a
Junior Suite price quoted for a "Royal Suite", a day-use price confirmed at a
number the customer proposed). A wrong photo link is that failure with a
customer-visible artifact, and no existing guard inspects URLs — the price guard
only reasons about numbers. Prompt-layer fixes for this class have been tried
and measured as insufficient.

## Approach

Deterministic source of truth in code + a regex gate on the outgoing reply.
The model never authors a URL; it calls a tool and quotes what PHP returns, and
anything it emits that is not a known URL is stripped before sending.

Phase 1 (this spec) sends a text link on every channel. Phase 2 (later) can send
real image carousels reading the same data with no data migration.

## Data model

Media lives inside the existing `pricing_config.config_json` (user_id 2), not a
new table. Rationale: room names are already the join key for `calculate_price`;
two stores would drift and a drifted name is exactly the bug this spec exists to
prevent.

New top-level `media` block:

```json
"media": {
  "items": {
    "<canonical item name>": {
      "kind": "room" | "day_use" | "day_use_unit",
      "album": "https://…",
      "aliases": ["…"],
      "services": ["…"]
    }
  }
}
```

`aliases` are load-bearing, not decoration. The recurring live failure is that a
customer uses a word the prompt does not contain (روم / شاليه / أوضة / يونت) and
the bot refuses a question it can answer. Aliases must cover the colloquial
words for each room, in Arabic.

## Owner decisions (2026-07-27)

The website and the bot disagreed on three points. Resolved:

1. **Single Room price** — the website's **3,510** is correct. `pricing_config`
   and profile 6 currently say 3,500 and must be corrected to 3,510.
2. **Two distinct 4,500 rooms** — the site has *Deluxe Double or Twin Room*
   (24 m², Garden View) and *King Room with Garden View* (67 m², Garden View),
   both 4,500 for 2 guests. The bot knew one. Add the King room as a **seventh**
   room.
3. **Royal Suite → Family Suite** — the bot's name is renamed to match the site.
   `Royal Suite` / `رويال` stay as aliases so returning customers are understood.
4. **4-night offer applies to both 4,500 rooms** — King Garden View gets
   `offers: {"4": 11500}`, same as Deluxe Double or Twin.

## Item table (authoritative)

| Canonical name (bot + site) | nightly | capacity | 4-night | album |
|---|---|---|---|---|
| Standard Single Room | 3,510 | 1 | — | `/en/rooms/standard-single-mountain-view` |
| Deluxe Double or Twin Room | 4,500 | 2 | 11,500 | `/en/rooms/deluxe-double-twin` |
| King Room with Garden View | 4,500 | 2 | 11,500 | `/en/rooms/king-garden-view` |
| Deluxe Double Room with Balcony | 5,500 | 2 | 15,500 | `/en/rooms/deluxe-double-balcony` |
| Standard Triple Room | 5,650 | 3 | 17,000 | `/en/rooms/standard-triple` |
| Junior Suite with Pool View | 7,800 | 2+2 | 23,000 | `/en/rooms/junior-suite-pool-view` |
| Family Suite | 11,500 | 4+2 | 30,500 | `/en/rooms/family-suite` |

Room URLs are prefixed `https://kemzo.byootbayeg.com`.
Day Use album: `https://www.byootbayeg.com/en/portfolio`.

Existing `pricing_config` names (`Single Room`, `Double Garden View`,
`Double Pool View`, `Triple Garden View`, `Junior Suite Pool View`,
`Royal Suite Pool View`) are renamed to the canonical column above and kept as
aliases, so nothing that already worked stops matching.

Services, taken verbatim from the room pages and rendered in Arabic: نص إقامة
(فطار وعشا يومياً — منيو محدد، وأوبن بوفيه يوم الجمعة)، واي فاي مجاني، تكييف،
حمام خاص، تلفزيون، جيم، حمامات سباحة، دي جيه. المشروبات غير شاملة. Per-room
extras: الإطلالة، المساحة، ونوع السرير من الجدول أعلاه.

This also closes a known gap: buffet timing was in `ai_unanswered_questions` and
the bot had previously invented an answer for it.

## Components

1. **`application/helpers/media_helper.php`**
   - `media_get_items($user_id)` — reads the `media` block via
     `pricing_get_config()`; returns `array()` when absent (feature simply off).
   - `media_find($cfg, $name)` — exact canonical → alias → Arabic-normalised
     comparison (strip tashkeel, أ/إ/آ→ا, ة→ه, ى→ي, collapse spaces). Returns
     `null` when nothing matches. It never picks a "closest" item.
   - `media_all_urls($cfg)` — the whitelist for the gate.

2. **`get_room_media` tool** in `application/libraries/Ai_tools.php`
   - Registered in `catalog()` only when the `media` block is non-empty, mirroring
     how `calculate_price` is gated on `pricing_config`.
   - Arg: `item` (string). Returns
     `ITEM: <name> | SERVICES: … | PHOTOS: <url>`.
   - Unmatched item → a string telling the model to ask the customer which room
     they mean. Never a guessed link.
   - Pushes the returned line onto a new `$this->CI->ai_media_facts` (same shape
     and lifecycle as `ai_price_facts`, reset at `Home.php:7078`) which is
     appended to `$guard_source` next to the computed-totals block, so a correct
     reply is not blocked by the price guard.

3. **Outgoing link gate** in `Home.php`, placed after the `[[UNANSWERED]]` strip
   and before the history save (same slot as the price guard, for the same
   reason: a poisoned reply must never enter `ai_conversation_history`).
   - Extract every `https?://…` from the reply.
   - Any URL not in `media_all_urls()` is removed and the sentence carrying it
     replaced with a short Arabic line offering to send the photos.
   - Log to `ai_price_guard_log` with `verdict='media'` — reuse the table, no new
     schema. The log must store the offending URL so a false strip is diagnosable.
   - Fail-open on any error: never block a reply because the gate itself failed.

4. **Room matching in `pricing_helper.php` must be tightened at the same time.**
   `pricing_calc_accommodation()` picks a room with
   `mb_stripos($r['name'], $room_name)` over a capacity-sorted list — a
   first-substring-wins match that was safe with six distinctly-named rooms and
   is not safe after this change: *Deluxe Double or Twin Room* and *Deluxe
   Double Room with Balcony* (4,500 vs 5,500) both contain "Deluxe Double", and
   the smaller room sorts first, so a customer asking about the balcony room
   would be quoted 4,500. The resolver becomes: exact canonical → alias →
   normalised exact → longest normalised substring, i.e. the same function
   `media_find()` uses, applied to a shared alias list. Aliases therefore live
   on the room entries, not only in the `media` block.

5. **Prompt changes** — one guardrail line ("photo links come only from the tool;
   never type a URL yourself") plus one worked ❌/✅ pair in profile 6. **No URL
   appears anywhere in any prompt text**: examples leak, and a leaked URL is a
   customer-visible wrong link.

## Propagation (mandatory, easy to get wrong)

The renames and the price correction touch the prompt, which exists in four
stores. Edit `ai_agent_profiles` id 6, then run
`python3 docker/scripts/sync_agent_prompt.py --profile 6 --bots 71,72 --flows 66,67`
and verify char count + CR count. Skipping the sync leaves Messenger and
Instagram on the stale copy and looks like "the fix didn't work".

DB writes go through `mariadb --default-character-set=utf8mb4`, and prompt text
is read/written with `newline=''` and `\\r\\n` escaping.

## Testing

Via the webchat widget bound to profile 6 (the harness that exercises the real
prompt path):

1. Photo request by canonical name → correct album, one line + one question.
2. Photo request in colloquial Arabic (شاليه / أوضة / الجناح العيلة) → matches
   via alias, correct album.
3. A room that does not exist (الفيلا) → asks which room, no link.
4. "ابعتلي صور" with no room named → asks which room, or offers the Day Use
   gallery if the conversation is about Day Use.
5. Injection: a reply coerced to contain a foreign URL is stripped by the gate.
6. Regression on the three price questions and the two known deflection traps
   («باقات الداي يوز», «تفاصيل الإقامة كاملة») to confirm nothing regressed.
7. `media_find` unit coverage for the normalisation cases.

Test from a fresh subscriber or purge history first — old conversations carry
poisoned assistant turns and the model imitates them.

## Out of scope

- Image carousels / attachments (phase 2, reads the same `media.items`).
- An admin UI for editing the links; seeded by script for now, like
  `pricing_config`.
- Day-use unit albums (كابينة / غرفة / جناح الداي يوز) — the shape supports them
  as `kind: "day_use_unit"`; the owner has not supplied URLs.
