-- SPEC-10 fix (review I1): store Meta app secret to verify X-Hub-Signature-256 on inbound webhooks
ALTER TABLE whatsapp_accounts ADD COLUMN app_secret VARCHAR(160) NULL AFTER access_token;
