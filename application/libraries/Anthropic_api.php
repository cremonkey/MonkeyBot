<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * SPEC-02 — Anthropic Claude Messages API driver.
 *
 * Mirrors Openai_api::open_ai_completion's contract: returns a JSON string whose
 * decoded shape exposes choices[0]['text'] so existing callers keep working.
 */
class Anthropic_api
{
    /**
     * @param string $api_key      Anthropic API key.
     * @param array  $messages     Chat messages [{role:'system'|'user'|'assistant', content:'...'}].
     * @param string $model        e.g. claude-haiku-4-5, claude-sonnet-4-5.
     * @param int    $max_tokens
     * @param string $system       Optional system prompt (system-role messages are also collected).
     * @param float  $temperature
     * @param array  $tools        Optional Anthropic tool definitions.
     * @return string JSON string with normalized choices[0]['text'] (+ raw content/stop_reason/usage).
     */
    public function anthropic_completion($api_key, $messages, $model = 'claude-haiku-4-5', $max_tokens = 1024, $system = '', $temperature = 0.7, $tools = null)
    {
        // Split out system-role messages; Anthropic takes system as a top-level param.
        $system_parts = array();
        if ($system !== '' && $system !== null) $system_parts[] = $system;
        $chat = array();
        foreach ((array) $messages as $m) {
            $role = isset($m['role']) ? $m['role'] : 'user';
            $content = isset($m['content']) ? $m['content'] : '';
            if ($role === 'system') { if ($content !== '') $system_parts[] = $content; continue; }
            if ($role !== 'user' && $role !== 'assistant') $role = 'user';
            $chat[] = array('role' => $role, 'content' => $content);
        }

        // Anthropic requires strict user/assistant alternation and a leading user turn.
        $normalized = array();
        foreach ($chat as $msg) {
            if (!empty($normalized) && $normalized[count($normalized) - 1]['role'] === $msg['role'] && is_string($msg['content'])) {
                // merge consecutive same-role text turns
                if (is_string($normalized[count($normalized) - 1]['content'])) {
                    $normalized[count($normalized) - 1]['content'] .= "\n" . $msg['content'];
                    continue;
                }
            }
            $normalized[] = $msg;
        }
        if (empty($normalized) || $normalized[0]['role'] !== 'user') {
            array_unshift($normalized, array('role' => 'user', 'content' => '...'));
        }

        $payload = array(
            'model' => $model,
            'max_tokens' => (int) $max_tokens,
            'temperature' => (float) $temperature,
            'messages' => $normalized,
        );
        if (!empty($system_parts)) $payload['system'] = implode("\n\n", $system_parts);
        if (!empty($tools)) $payload['tools'] = $tools;

        $headers = array(
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $result = curl_exec($ch);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($result === false || $result === '') {
            return json_encode(array('error' => array('message' => 'Anthropic request failed: ' . $curl_err)));
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            return json_encode(array('error' => array('message' => 'Anthropic returned an unreadable response.')));
        }
        if (isset($decoded['error'])) {
            // pass through in OpenAI-ish error shape
            $msg = isset($decoded['error']['message']) ? $decoded['error']['message'] : 'Anthropic API error';
            return json_encode(array('error' => array('message' => $msg), 'raw' => $decoded));
        }

        // Extract text from content blocks (type=text). tool_use blocks are preserved in raw.
        $text = '';
        if (isset($decoded['content']) && is_array($decoded['content'])) {
            foreach ($decoded['content'] as $block) {
                if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                    $text .= $block['text'];
                }
            }
        }

        $out = array(
            'choices' => array(array('text' => $text)),
            'content' => $decoded['content'] ?? array(),
            'stop_reason' => $decoded['stop_reason'] ?? null,
            'usage' => $decoded['usage'] ?? null,
            'model' => $decoded['model'] ?? $model,
        );
        return json_encode($out);
    }
}
