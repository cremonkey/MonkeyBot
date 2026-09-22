<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__ . '/../helpers/conversation_summary_helper.php';

/**
 * AI conversation summary (brief §13, §51) — generates a short, factual summary of a
 * conversation thread ON DEMAND from the messages already available via
 * `livechat_messages.conversation_id` (Phase 9.5 trigger, ADR-001). It does NOT read
 * `ai_conversation_history` (that table is keyed per-subscriber, not per-conversation — see
 * docs/product/unified-inbox.md "Deferred").
 *
 * DESIGN (mirrors Flow_prompt_generator, brief §51 "no silent failure"): the AI call itself is
 * taken through a small injectable seam so summarize() can be exercised by
 * tests/summary_billing/run.php with NO CodeIgniter, NO database and NO network. Never invents
 * a summary: any AI failure (no credentials, provider error, unreadable response, empty
 * transcript) returns ok=false with a reason, never a fabricated placeholder.
 */
class Conversation_summarizer
{
    /** Newest N messages sent to the model — enough context without an unbounded prompt. */
    const MAX_MESSAGES = 50;

    /** @var callable|null function($config_row, array $messages, array $overrides): string (raw JSON, Ai_provider::completion() shape) */
    protected $ai_caller;

    public function __construct($params = array())
    {
        $this->ai_caller = (isset($params['ai_caller']) && is_callable($params['ai_caller'])) ? $params['ai_caller'] : null;
    }

    /**
     * @param mixed $config_row  open_ai_config row, passed through to the AI caller unchanged
     * @param array $messages    livechat_messages rows (id, sender, agent_name, message_content, conversation_time), oldest first
     * @return array{ok:bool, summary:?string, message:string}
     */
    public function summarize($config_row, array $messages)
    {
        $messages = array_slice($messages, -self::MAX_MESSAGES);
        $has_content = false;
        foreach ($messages as $m) {
            if (trim((string) ($m['message_content'] ?? '')) !== '') { $has_content = true; break; }
        }
        if (!$has_content) {
            return array('ok' => false, 'summary' => null, 'message' => 'No messages to summarize yet.');
        }

        $prompt_messages = conversation_summary_build_messages($messages);

        $system = conversation_summary_system_prompt();
        $raw = $this->call_ai($config_row, $prompt_messages, array(
            'purpose'     => 'conversation_summary',
            'max_tokens'  => 350,
            'temperature' => 0.2,
            'system'      => $system,      // Anthropic path
            'instruction' => $system,      // OpenAI path
        ));

        list($text, $err) = conversation_summary_extract_text($raw);
        if ($text === null) {
            return array('ok' => false, 'summary' => null, 'message' => $err !== '' ? $err : 'Could not generate a summary right now.');
        }
        $text = trim($text);
        if ($text === '') {
            return array('ok' => false, 'summary' => null, 'message' => 'The AI returned an empty summary.');
        }
        return array('ok' => true, 'summary' => $text, 'message' => '');
    }

    protected function call_ai($config_row, array $messages, array $overrides)
    {
        if ($this->ai_caller !== null) return call_user_func($this->ai_caller, $config_row, $messages, $overrides);
        $CI = &get_instance();
        $CI->load->library('Ai_provider');
        return $CI->ai_provider->completion($config_row, $messages, $overrides);
    }
}
