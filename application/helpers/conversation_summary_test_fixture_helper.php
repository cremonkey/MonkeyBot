<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * TEST-ONLY escape hatch for docker/scripts/staging_summary_billing_tests.sh (brief §13),
 * mirroring flow_prompt_test_fixture_helper.php exactly (see that file's docblock for the full
 * rationale — same pattern, same safety properties).
 *
 * This file is only ever loaded from Inbox::summarize() when
 * $config['conversation_summary_test_fixture'] is truthy, and that config item defaults to
 * FALSE in application/config/config.php and is never set in production. It exists so the
 * staging HTTP+DB suite can exercise the real summarize() endpoint (entitlement check, tenant
 * scoping, caching, regeneration-on-new-message) WITHOUT a real AI provider API key — the same
 * seam tests/summary_billing/run.php uses via Conversation_summarizer's injectable ai_caller,
 * just reached through a config flag instead of a constructor argument because the HTTP
 * request/response cycle has no other place to inject a PHP callable from outside.
 *
 * Returns the TEXT an AI provider would have returned at choices[0].text — Inbox::summarize()
 * wraps it into Ai_provider::completion()'s normalized {"choices":[{"text": ...}]} shape before
 * handing it to Conversation_summarizer, exactly like Flow_prompt::generate() does with
 * flow_prompt_test_fixture(). The caller still goes through Conversation_summarizer's own
 * parse/validate path, so this only fakes the network call, nothing about the summary pipeline
 * itself.
 */
function conversation_summary_test_fixture($name)
{
    switch ($name) {
        case 'empty':
            // Proves the "never fabricate a summary" path (brief §51): an empty AI response
            // must surface as a failure, never an invented placeholder.
            return '';

        case 'fixed':
        default:
            return 'TEST-FIXTURE SUMMARY: the customer is asking about a booking; status open; no invented facts.';
    }
}
