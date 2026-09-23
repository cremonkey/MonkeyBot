-- Inbound media for WhatsApp Cloud, Telegram and OpenWA (docs/product/unified-inbox.md,
-- "Inbound media").
--
-- Adds a nullable livechat_messages.media_json. The three webhooks fill it on NEW media rows with
-- {"type":"image|audio|video|file","path":"upload/inbox_in/YYYY/MM/<uid>_<hex>.<ext>","mime":...,"size":...}
-- or, when the file could not be stored, {"type":...,"path":null}. message_content stays readable
-- text (the caption, a transcript, or a placeholder such as [image]). Every other row keeps NULL.
--
-- Before this runs, the webhooks and the inbox check field_exists and behave exactly as before.
-- The nullable column is added at the end, instantly, with no rebuild on MariaDB 10.11.
-- Idempotent (IF NOT EXISTS). No semicolons in comments: the runner splits on them.
DO 0;

ALTER TABLE `livechat_messages`
  ADD COLUMN IF NOT EXISTS `media_json` text NULL DEFAULT NULL
    COMMENT 'inbound wa/tg media reference (JSON), NULL for everything else';
