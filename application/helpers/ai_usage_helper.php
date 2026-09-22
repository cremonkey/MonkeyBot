<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | Single place that records AI usage and its estimated cost (Phase 10 / brief 30).
 | Used by Ai_provider (chat) and by every direct OpenAI call (embeddings, chunking,
 | price judge, Whisper, preview). Never throws: accounting must not break a reply.
 */

if (!function_exists('ai_usage_price')) {
    /** @return array{0:float,1:float,2:float} [input per 1M, output per 1M, per minute] */
    function ai_usage_price($provider, $model)
    {
        static $cache = array();
        $k = $provider . '|' . $model;
        if (isset($cache[$k])) return $cache[$k];
        $p = array(1.0, 3.0, 0.0); // conservative default for unknown models
        try {
            $CI =& get_instance();
            if ($CI->db->table_exists('ai_model_pricing')) {
                $r = $CI->db->from('ai_model_pricing')->where('provider', $provider)->where('model', $model)->limit(1)->get()->row_array();
                if ($r) $p = array((float) $r['input_per_1m_usd'], (float) $r['output_per_1m_usd'], (float) $r['per_minute_usd']);
            }
        } catch (Throwable $e) { /* keep default */ }
        return $cache[$k] = $p;
    }
}

if (!function_exists('ai_usage_record')) {
    /**
     * @param string $purpose chat_reply | embedding | kb_chunking | price_judge | transcription | agent_preview | ...
     * @param float  $units   non-token units (audio seconds for Whisper)
     */
    function ai_usage_record($user_id, $provider, $model, $purpose, $input_tokens = 0, $output_tokens = 0, $units = 0)
    {
        try {
            $CI =& get_instance();
            if (!$CI->db->table_exists('ai_usage_log')) return;
            list($pin, $pout, $pmin) = ai_usage_price($provider, $model);
            $cost = ($input_tokens / 1000000 * $pin) + ($output_tokens / 1000000 * $pout) + ($units / 60 * $pmin);
            $row = array(
                'user_id' => (int) $user_id, 'provider' => $provider, 'model' => $model,
                'input_tokens' => (int) $input_tokens, 'output_tokens' => (int) $output_tokens,
                'purpose' => substr((string) $purpose, 0, 40), 'created_at' => date('Y-m-d H:i:s'),
            );
            if ($CI->db->field_exists('estimated_cost_usd', 'ai_usage_log')) {
                $row['units'] = round((float) $units, 3);
                $row['estimated_cost_usd'] = round($cost, 6);
            }
            $CI->db->insert('ai_usage_log', $row);
        } catch (Throwable $e) { /* never break the caller */ }
    }
}

if (!function_exists('ai_usage_record_openai_response')) {
    /** Convenience for OpenAI JSON responses that carry a `usage` block. */
    function ai_usage_record_openai_response($user_id, $model, $purpose, $raw)
    {
        $d = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($d) || !isset($d['usage'])) return;
        $u = $d['usage'];
        $in = (int) ($u['prompt_tokens'] ?? $u['input_tokens'] ?? $u['total_tokens'] ?? 0);
        $out = (int) ($u['completion_tokens'] ?? $u['output_tokens'] ?? 0);
        ai_usage_record($user_id, 'openai', $model, $purpose, $in, $out);
    }
}
