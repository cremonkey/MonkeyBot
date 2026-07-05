-- SPEC-15 fix (review I3): encrypted token can exceed 120 chars once a strong encryption_key is set
ALTER TABLE telegram_accounts MODIFY bot_token TEXT NOT NULL;
