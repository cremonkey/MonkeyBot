-- Unified Inbox follow-up: canned responses filtered by channel (docs/product/unified-inbox.md).
--
-- canned_response.media_type is the Livechat (module 82) page category and is left exactly as
-- it is. Livechat keeps filtering on page_id + media_type, unchanged. This adds a separate,
-- nullable `channels` column that only the Unified Inbox reads:
--
--   NULL (every existing row, and the default)  the response is offered on every channel,
--                                               which is what the inbox showed before
--   'fb,wa,tg'                                  offered only on those conversation platforms
--                                               (fb, ig, wa, tg, web)
--
-- An instant ADD COLUMN on MariaDB 10.11 (nullable, at the end, no rebuild).
-- Idempotent (IF NOT EXISTS). No semicolons in comments: the runner splits on them.
DO 0;

ALTER TABLE `canned_response`
  ADD COLUMN IF NOT EXISTS `channels` varchar(64) NULL DEFAULT NULL
    COMMENT 'Unified Inbox: comma-separated conversation platforms (fb,ig,wa,tg,web) this response is offered on. NULL = all channels.';
