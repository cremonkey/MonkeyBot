<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * AI conversation summary (brief §13, §51) — pure / DB-free logic factored out of
 * Conversation_summarizer and Inbox::summarize() so it is unit-testable without a full
 * CodeIgniter bootstrap (tests/summary_billing/run.php). Nothing here touches the database
 * or the network.
 */

if (!function_exists('conversation_summary_should_regenerate')) {
    /**
     * Whether a cached summary is stale and must be regenerated.
     *
     *  - No cached summary yet (`$last_summary_at` empty)              -> regenerate.
     *  - A cached summary exists and no message is newer than it       -> keep the cache.
     *  - A cached summary exists but `$last_message_at` is newer       -> regenerate.
     *
     * An explicit "Regenerate" click bypasses this function entirely (the caller always
     * regenerates then), so this only decides the passive "reopening the same conversation"
     * case.
     */
    function conversation_summary_should_regenerate($last_summary_at, $last_message_at)
    {
        if (empty($last_summary_at)) return true;
        if (empty($last_message_at)) return false; // nothing to compare against; trust the cache
        return strtotime((string) $last_message_at) > strtotime((string) $last_summary_at);
    }
}

if (!function_exists('conversation_summary_system_prompt')) {
    /** Brief §51: never invent facts not in the transcript. Short, factual, verifiable. */
    function conversation_summary_system_prompt()
    {
        return "You summarize a customer support conversation for a human agent who is about to take over. "
            . "STRICT RULES: "
            . "1) Use ONLY facts explicitly present in the transcript below. Never invent, assume, or infer a fact, "
            . "name, number, date, or promise that is not literally stated. "
            . "2) Cover, briefly: what the customer wants, key facts they stated, the current status, and any open "
            . "question that is still unanswered. "
            . "3) At most 5 short sentences. No markdown headers, no bullet symbols, plain text. "
            . "4) If the transcript is too short or too unclear to summarize meaningfully, say exactly that instead "
            . "of guessing.";
    }
}

if (!function_exists('conversation_summary_build_messages')) {
    /**
     * Turns livechat_messages rows (id, sender, agent_name, message_content, conversation_time)
     * into the single user-turn Ai_provider::completion() expects. sender is 'user' (customer),
     * 'bot' (AI or agent reply — agent_name distinguishes), or 'system'.
     */
    function conversation_summary_build_messages(array $messages)
    {
        $lines = array();
        foreach ($messages as $m) {
            $sender = isset($m['sender']) ? (string) $m['sender'] : 'user';
            if ($sender === 'user') {
                $who = 'Customer';
            } elseif ($sender === 'bot') {
                $who = !empty($m['agent_name']) ? (string) $m['agent_name'] : 'Bot';
            } else {
                $who = 'System';
            }
            $content = isset($m['message_content']) ? trim((string) $m['message_content']) : '';
            if ($content === '') continue;
            $lines[] = $who . ': ' . $content;
        }
        $transcript = implode("\n", $lines);
        return array(
            array('role' => 'user', 'content' => "Summarize this conversation transcript:\n\n" . $transcript),
        );
    }
}

if (!function_exists('conversation_summary_extract_text')) {
    /**
     * Ai_provider::completion() always normalizes to a JSON string decoding to either
     * {error:{message}} or {choices:[{text}]}, whichever provider ran (see Ai_provider.php,
     * Openai_api.php, Anthropic_api.php).
     * @return array{0: ?string, 1: string} [text or null on failure, error message]
     */
    function conversation_summary_extract_text($raw)
    {
        $dec = json_decode((string) $raw, true);
        if (!is_array($dec)) return array(null, 'The AI call failed or returned no readable response.');
        if (isset($dec['error'])) {
            $msg = isset($dec['error']['message']) ? (string) $dec['error']['message'] : 'AI provider error.';
            return array(null, $msg);
        }
        if (isset($dec['choices'][0]['text'])) return array((string) $dec['choices'][0]['text'], '');
        return array(null, 'The AI call failed or returned no readable response.');
    }
}
