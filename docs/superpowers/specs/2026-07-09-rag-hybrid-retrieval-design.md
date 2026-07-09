# Hybrid RAG Retrieval for MonkeyBot

**Date:** 2026-07-09
**Status:** Approved, pending implementation plan

## Problem

The AI knowledge base never reaches the model on Arabic conversations.

Customers write Arabic. Every knowledge chunk is English (44 of 45 chunks contain
no Arabic characters at all). Retrieval is `MATCH ... AGAINST` — a lexical match.
An Arabic query cannot lexically match English text, so retrieval returns nothing
on every Arabic message.

Verified against the live database on 2026-07-09:

| Query | Language | Top score |
|---|---|---|
| `عندكم غرف واقامة` | Arabic | **0** |
| `rooms accommodation price` | English | 4.116 |

The bot then behaves exactly as designed: guardrail rule 3 (`ZERO OUTSIDE
INFORMATION`) forbids inventing an answer, so it declines and hands off to the
team. The failure is in the retrieval layer beneath the guardrail, not the
guardrail itself. Chunk 4 of source 18 contains `Double Room Premium ... From EGP
5,500/night`; the model has never seen it.

No prompt change can fix this. `MATCH ... AGAINST` cannot relate `غرف` to `Room`.
Cross-lingual retrieval requires semantic search.

## Approach

Hybrid retrieval: semantic (embeddings + cosine similarity) merged with the
existing lexical (FULLTEXT) search.

Semantic search handles natural language and cross-lingual matching. Lexical
search handles exact tokens — product codes, model numbers, prices — where
embeddings are weak. Neither subsumes the other.

Cosine similarity is computed in PHP. At the current scale (45 chunks) and any
realistic near-term scale, brute-force comparison costs well under a millisecond.
MariaDB 10.11 has no `VECTOR` type (introduced in 11.7), and this design
deliberately avoids a database upgrade on a live production server.

Embeddings come from OpenAI `text-embedding-3-small`, using the API key already
stored in `open_ai_config`. Anthropic does not offer an embeddings endpoint.

Rejected alternatives:

- **Translate chunks at ingest.** Doubles storage, locks the system to two
  languages, and leaves the underlying synonym problem intact: even translated,
  `اقامة` will not lexically match `night` or `stay`.
- **Translate the customer query before searching.** Adds an LLM call to every
  message, and the search remains lexical, so synonyms still fail.

## Architecture

The retrieval layer lives entirely in `application/helpers/ai_knowledge_helper.php`.

This placement is load-bearing. The `ai_knowledge_base` module is a third-party
addon: it is tracked in git, but the addon installer overwrites files under
`application/modules/` on reinstall, and a sibling addon bundle has clobbered
core files before (the 2026-07-06 `my_helper.php` incident). The helper lives in
`application/helpers/`, which the installer does not touch. Keeping all
retrieval logic there means an addon reinstall cannot break retrieval; the
module only calls into it.

Both existing call sites — `Home.php:7168` and
`ai_knowledge_base/controllers/Ai_knowledge_base.php:373` — continue to call
`ai_get_knowledge_context()` with an unchanged signature. Neither file changes.

### Components

Four functions, each with one responsibility.

**`ai_embed_text($text)`**
Returns a vector (array of floats) from OpenAI `text-embedding-3-small`, using the
`open_ai_config` API key. Returns `false` on any failure — network error, rate
limit, missing key, malformed response. Never throws.

**`ai_vector_search($user_id, $query_vec, $page_id, $limit)`**
Loads chunks that have an embedding, computes cosine similarity in PHP, and
returns those above a similarity threshold.

> **Revised during implementation (2026-07-09).** This section originally called
> the threshold "the grounding guard" and proposed calibrating it to separate
> answerable from unanswerable questions. Calibration disproved that premise.
>
> Measured against the live knowledge base with a 14-positive / 8-negative eval
> set, **the two classes overlap**:
>
> | | top cosine |
> |---|---|
> | `المنتجع فين؟` — answerable, lowest positive | 0.2126 |
> | `عندكم تذاكر طيران؟` — unanswerable, highest negative | 0.3111 |
>
> Cosine measures topical proximity, not whether the answer is present. A
> flight-ticket question is close to resort and booking text. Any threshold high
> enough to reject it also rejects `عندكم سبا؟` (0.2532), which the knowledge
> base does answer. No single threshold separates the classes.
>
> Deciding "do I actually know this?" belongs to the model, and that mechanism
> already exists and is tested: guardrail rule 3 (`ZERO OUTSIDE INFORMATION`)
> and the `[[UNANSWERED]]` marker make it decline and hand off to the team when
> the excerpts do not answer the question. Verified end to end: given 4,904
> characters of resort context, the model answered the flight-ticket question
> with "إحنا مش بنقدم تذاكر طيران" and invented nothing.
>
> The threshold's real job is therefore narrower — **a noise filter, not a
> judge**. It is set to `0.20`, which discards obvious junk
> (`ما هي عاصمة اليابان؟` 0.0852, `do you sell insurance` 0.1496) while
> retaining 14/14 answerable questions in the eval set.
>
> Had the threshold been calibrated on the benchmark query alone, `0.28` would
> have shipped and silently dropped four legitimate questions. The negative
> control is what caught this.

**`ai_fulltext_search($user_id, $query, $page_id, $limit)`**
The current `MATCH ... AGAINST` logic, extracted verbatim from the existing
`ai_get_knowledge_context()`. Behavior unchanged, including the existing
optional-terms handling that works around InnoDB stopwords.

**`ai_get_knowledge_context($user_id, $query, $page_id, $limit)`**
The public interface. Signature unchanged. Calls both searches, merges results —
vector hits first, then FULLTEXT hits that are not already present — and
truncates to `$limit`. Page-scoped sources are still tried before user-level
sources, as today.

### Data flow

**Ingest.** text → chunk → one embedding call per chunk → store the vector as
packed `float32` (`pack('g*', ...)`) in a new `embedding BLOB` column on
`ai_knowledge_chunks`. At 1536 dimensions this is 6 KB per chunk.

**Query.** customer message → one embedding call → cosine against the page's
chunks → merge with FULLTEXT results → inject into the prompt.

### Error handling

This is the most important property of the design: **nothing that works today
can break.**

If the query-time embedding call fails, `ai_get_knowledge_context()` falls back
to FULLTEXT-only results — precisely today's behavior. No degraded service, no
error surfaced to the customer.

If a chunk has a `NULL` embedding — added before the migration, or ingested while
the embeddings API was down — it is invisible to vector search and reachable
through FULLTEXT exactly as today.

If the `embedding` column does not exist, vector search is skipped entirely.

The system degrades to its current behavior under every failure mode.

### Chunk quality fixes

Two defects degrade retrieval regardless of the search method, and both are
visible in the live data:

1. **Character-based chunking splits words.** Chunk 3 of source 18 ends mid-token
   at `From EG`. `ai_chunk_text()` must break on a word boundary at or before the
   target size, preserving the existing overlap behavior.

2. **URL extraction captures navigation chrome.** Chunks contain
   `Terms of ServicePrivacy PolicyCookie Policy| Crafted by Creative Monkey`.
   `ai_extract_url_text()` already strips `script,style,noscript,nav,footer,header,aside`
   via `Simple_html_dom`. Extend the stripped-selector list and add whitespace
   normalization between adjacent inline elements so words do not concatenate
   (`ServicePrivacy` → `Service Privacy`).

## Migration

A one-off script backfills embeddings for the 45 existing chunks. 45 API calls;
cost under one cent.

The script is idempotent — it skips chunks that already have an embedding — so it
is safe to re-run after a partial failure.

Schema change: `ALTER TABLE ai_knowledge_chunks ADD COLUMN embedding BLOB NULL`.
Additive and nullable, so it cannot break the current code path. Mirror the
statement into `assets/backup_db/migrations/` per the project's new-module
pattern, and into the addon module's own `CREATE TABLE` statement
(`Ai_knowledge_base.php:415`) so a fresh install matches.

## Testing

The decisive test already exists — it is the query that failed in production.

**Benchmark:** `عندكم غرف واقامة` must retrieve a chunk containing a real room
price. *Result: 4,902 characters of context containing `EGP 5,650`. The model
answers `الأسعار تبدأ من 3,510 جنيه`, which appears verbatim in the knowledge
base as `starting from EGP 3,510/night`.*

**Negative control:** a query with no correct answer in the knowledge base (for
example, asking about a service the resort does not offer) must retrieve nothing
above the threshold, so the bot still declines rather than injecting a loosely
related chunk. Without this control, lowering the threshold to make the benchmark
pass would silently destroy the grounding guarantee.

**Fallback:** with the embeddings API deliberately unreachable, an English query
must still return the results FULLTEXT returns today.

**End to end:** through the public webchat widget (`POST webchat/send`, widget key
`0b9ac3dc1b62303001d29ca9`), an Arabic question about room prices must produce an
answer quoting the configured price exactly, per guardrail rule 5.

All tests run against the live schema and real data. No mocked retrieval.

## Deliberately out of scope

- **Source attribution / citations.** Useful for auditing, not needed to fix this
  bug. Deferred.
- **MariaDB upgrade.** Unnecessary — brute-force cosine is fast enough, and
  upgrading a live production database is not a risk worth taking for this.
- **Filling knowledge-base gaps.** A content task, not an engineering one, and
  independent of this work.

## Constraints

- PHP 7.4 in the app container. `vendor/` autoload fatals on 7.4
  (`composer platform_check` requires ≥ 8.1), so no Composer packages. Use
  `curl` and `pack`/`unpack` directly, as `crm_sheet_helper.php` already does for
  Google's service-account JWT.
- Live production. Ship the additive migration before the code that reads the
  column.
- `application/config` is a Docker named volume; the repo copy is not the live
  config. This work adds no config keys, so no `docker cp` round-trip is needed.
