# Runbook: KB embeddings backfill

Knowledge-base chunks (`ai_knowledge_chunks`) get an OpenAI embedding (`text-embedding-3-small`, 1536 dims, stored as a float32 BLOB) when a source is ingested. A chunk without a vector for the current model is still found by lexical search, but not by semantic search. The backfill embeds every such chunk.

## Supported command

Run it inside the app container, as `www-data`:

```bash
php /var/www/html/index.php cron_hub backfill_embeddings --dry-run      # count per tenant, no API calls
php /var/www/html/index.php cron_hub backfill_embeddings                # all tenants
php /var/www/html/index.php cron_hub backfill_embeddings --user 42      # one tenant
php /var/www/html/index.php cron_hub backfill_embeddings --batch 48 --pause-ms 1000   # gentler on the rate limit
```

`=` is not a permitted CodeIgniter URI character, so the flags take their value as the next argument (`--user 42`) or after a colon (`--user:42`). `--user=42` is rejected by the framework before the command runs.

The command is CLI-only: over HTTP it returns 404.

### What it does

| Property | Behaviour |
|---|---|
| Keys | `ai_openai_key()`: keys stored encrypted (`enc::`) are decrypted with the app's `encryption_key`, like every other OpenAI call. |
| Tenants on Anthropic | Embeddings are OpenAI-only. A tenant with no usable OpenAI key is skipped and reported (`no OpenAI key`, or `stored encrypted and could not be decrypted`). Its chunks stay on lexical search. |
| Idempotent | Only chunks with `embedding IS NULL` or a different/NULL `embedding_model` are selected. Safe to re-run at any time, including after an interruption. |
| Batching | Up to 96 texts per embeddings call, walked by chunk id. A failed batch is not retried again inside the same run; the next run picks it up. |
| Rate limits | HTTP 429, 5xx and transport failures back off exponentially (2s, 4s, 8s, 16s) and retry up to 4 times. HTTP 401/403 means the key is bad: the rest of that tenant is skipped. Full batches are paced by `--pause-ms` (default 250 ms). |
| Cost | Every successful call is recorded in `ai_usage_log` with purpose `embedding`, billed to the tenant. |
| Concurrency | MariaDB advisory lock `GET_LOCK('mb_embedding_backfill', 0)`. A second concurrent run prints `another backfill_embeddings run holds the lock` and exits 1. |
| Output | One line per tenant plus a summary: `done: users=… pending=… embedded=… failed=… skipped_no_key=… calls=… retries=…`. Chunk text and keys are never printed. |
| Exit code | 0 when nothing failed (tenants skipped for having no key do not count as failures), 1 otherwise, 2 for a bad argument. |

## Deprecated script

`docker/scripts/backfill_kb_embeddings.php` still works but is deprecated and prints a notice pointing here. It runs outside CodeIgniter, so it cannot decrypt `enc::` keys: those tenants are skipped. It has no back-off and no lock. Use it only if the CodeIgniter bootstrap itself is broken.

## Checking the result

```sql
SELECT s.user_id,
       SUM(c.embedding IS NOT NULL AND c.embedding_model = 'text-embedding-3-small') AS embedded,
       COUNT(*) AS total
  FROM ai_knowledge_chunks c JOIN ai_knowledge_sources s ON s.id = c.source_id
 GROUP BY s.user_id;
```
