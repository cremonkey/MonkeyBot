<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * SPEC-02/03 — Provider-agnostic AI completion router with optional tool/function calling.
 *
 * Dispatches to OpenAI or Anthropic based on open_ai_config.ai_provider, and always
 * returns a JSON STRING whose decoded shape exposes choices[0]['text'] — identical to
 * Openai_api::open_ai_completion — so every existing caller keeps working unchanged.
 *
 * When $overrides['tools_context'] is set AND config.ai_tools_enabled == '1', a tool
 * loop (max 3 rounds) lets the model call Ai_tools before producing its final text.
 */
class Ai_provider
{
    const MAX_TOOL_ROUNDS = 2; // bounded to limit worst-case wall-clock inside the webhook

    public function completion($config_row, $messages, $overrides = array())
    {
        $CI =& get_instance();
        $CI->load->helper('secret');
        $cfg = (array) $config_row;
        $provider = isset($cfg['ai_provider']) && $cfg['ai_provider'] === 'anthropic' ? 'anthropic' : 'openai';

        $max_tokens = isset($overrides['max_tokens']) ? (int) $overrides['max_tokens']
            : (!empty($cfg['maximum_token']) ? (int) $cfg['maximum_token'] : 1024);
        $temperature = isset($overrides['temperature']) ? (float) $overrides['temperature']
            : (isset($cfg['temperature']) && $cfg['temperature'] !== '' ? (float) $cfg['temperature'] : 0.7);

        $tools_context = isset($overrides['tools_context']) ? $overrides['tools_context'] : null;
        $tools_on = $tools_context !== null && isset($cfg['ai_tools_enabled']) && $cfg['ai_tools_enabled'] === '1';

        if ($provider === 'anthropic') {
            $api_key = secret_decrypt(isset($cfg['anthropic_secret_key']) ? $cfg['anthropic_secret_key'] : '');
            if (empty($api_key)) return json_encode(array('error' => array('message' => 'Anthropic API key is not configured.')));
            $model = isset($overrides['model']) && $overrides['model'] ? $overrides['model'] : (!empty($cfg['anthropic_model']) ? $cfg['anthropic_model'] : 'claude-haiku-4-5');
            $system = isset($overrides['system']) ? $overrides['system'] : '';
            $CI->load->library('Anthropic_api');
            if ($tools_on) return $this->anthropic_tool_loop($CI, $api_key, $messages, $model, $max_tokens, $system, $temperature, $tools_context);
            $raw = $CI->anthropic_api->anthropic_completion($api_key, $messages, $model, $max_tokens, $system, $temperature);
            $this->log_usage($CI, $cfg, 'anthropic', $model, $raw, isset($overrides['purpose']) ? $overrides['purpose'] : 'chat_reply');
            return $raw;
        }

        // OpenAI
        $api_key = secret_decrypt(isset($cfg['open_ai_secret_key']) ? $cfg['open_ai_secret_key'] : '');
        if (empty($api_key)) return json_encode(array('error' => array('message' => 'OpenAI API key is not configured.')));
        $model = isset($overrides['model']) && $overrides['model'] ? $overrides['model'] : (!empty($cfg['models']) ? $cfg['models'] : 'gpt-4o-mini');
        $instruction = isset($overrides['instruction']) ? $overrides['instruction'] : (isset($cfg['instruction_to_ai']) ? $cfg['instruction_to_ai'] : '');
        $description = isset($overrides['description']) ? $overrides['description'] : '';
        $human = isset($overrides['human']) ? $overrides['human'] : '';
        $CI->load->library('Openai_api');
        if ($tools_on) return $this->openai_tool_loop($CI, $api_key, $messages, $model, $max_tokens, $instruction, $description, $human, $temperature, $tools_context);
        $raw = $CI->openai_api->open_ai_completion($api_key, $messages, $model, $max_tokens, $instruction, $description, $human, $temperature);
        $this->log_usage($CI, $cfg, 'openai', $model, $raw, isset($overrides['purpose']) ? $overrides['purpose'] : 'chat_reply');
        return $raw;
    }

    // SPEC-13: record token usage; never disrupt the reply on logging failure.
    protected function log_usage($CI, $cfg, $provider, $model, $raw, $purpose)
    {
        try {
            if (!$CI->db->table_exists('ai_usage_log')) return;
            $dec = json_decode($raw, true);
            if (!is_array($dec) || isset($dec['error'])) return;
            $in = 0; $out = 0;
            if (isset($dec['usage']['prompt_tokens'])) { $in = (int) $dec['usage']['prompt_tokens']; $out = (int) ($dec['usage']['completion_tokens'] ?? 0); }
            elseif (isset($dec['usage']['input_tokens'])) { $in = (int) $dec['usage']['input_tokens']; $out = (int) ($dec['usage']['output_tokens'] ?? 0); }
            $CI->db->insert('ai_usage_log', array(
                'user_id' => (int) ($cfg['user_id'] ?? 0), 'provider' => $provider, 'model' => $model,
                'input_tokens' => $in, 'output_tokens' => $out, 'purpose' => $purpose, 'created_at' => date('Y-m-d H:i:s'),
            ));
        } catch (Exception $e) { /* ignore */ }
    }

    protected function openai_tool_loop($CI, $api_key, $messages, $model, $max_tokens, $instruction, $description, $human, $temperature, $context)
    {
        $CI->load->library('Ai_tools');
        $tools = $CI->ai_tools->openai_tools();
        $last = null;
        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $raw = $CI->openai_api->open_ai_completion($api_key, $messages, $model, $max_tokens, $instruction, $description, $human, $temperature, $tools);
            $dec = json_decode($raw, true);
            $last = $raw;
            if (!isset($dec['choices'][0]['message'])) return $raw; // error or unexpected shape
            $msg = $dec['choices'][0]['message'];
            if (empty($msg['tool_calls'])) return $raw; // final answer (text already normalized by lib)
            // record the assistant turn that requested tools, then answer each tool call
            $messages[] = $msg;
            foreach ($msg['tool_calls'] as $call) {
                $fn = isset($call['function']['name']) ? $call['function']['name'] : '';
                $args = array();
                if (isset($call['function']['arguments'])) { $args = json_decode($call['function']['arguments'], true); if (!is_array($args)) $args = array(); }
                $result = $CI->ai_tools->execute($fn, $args, $context);
                $messages[] = array('role' => 'tool', 'tool_call_id' => $call['id'] ?? '', 'content' => (string) $result);
            }
        }
        // ran out of rounds: force a final answer without tools
        $raw = $CI->openai_api->open_ai_completion($api_key, $messages, $model, $max_tokens, $instruction, $description, $human, $temperature);
        return $raw ?: $last;
    }

    protected function anthropic_tool_loop($CI, $api_key, $messages, $model, $max_tokens, $system, $temperature, $context)
    {
        $CI->load->library('Ai_tools');
        $tools = $CI->ai_tools->anthropic_tools();
        // Anthropic keeps its own running message array (with tool_use/tool_result blocks)
        $convo = array();
        foreach ((array) $messages as $m) {
            $role = isset($m['role']) ? $m['role'] : 'user';
            if ($role === 'system') continue;
            $convo[] = array('role' => ($role === 'assistant' ? 'assistant' : 'user'), 'content' => isset($m['content']) ? $m['content'] : '');
        }
        $last = null;
        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $raw = $CI->anthropic_api->anthropic_completion($api_key, $convo, $model, $max_tokens, $system, $temperature, $tools);
            $dec = json_decode($raw, true);
            $last = $raw;
            if (isset($dec['error'])) return $raw;
            $stop = $dec['stop_reason'] ?? null;
            $content = $dec['content'] ?? array();
            if ($stop !== 'tool_use') return $raw; // final text (choices[0].text present)
            // append assistant's tool_use turn, then a user turn carrying tool_result blocks
            $convo[] = array('role' => 'assistant', 'content' => $content);
            $results = array();
            foreach ($content as $block) {
                if (isset($block['type']) && $block['type'] === 'tool_use') {
                    $result = $CI->ai_tools->execute($block['name'] ?? '', $block['input'] ?? array(), $context);
                    $results[] = array('type' => 'tool_result', 'tool_use_id' => $block['id'] ?? '', 'content' => (string) $result);
                }
            }
            $convo[] = array('role' => 'user', 'content' => $results);
        }
        $raw = $CI->anthropic_api->anthropic_completion($api_key, $convo, $model, $max_tokens, $system, $temperature);
        return $raw ?: $last;
    }
}
