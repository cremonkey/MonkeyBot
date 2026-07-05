<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * SPEC-02 — Provider-agnostic AI completion router.
 *
 * Dispatches to OpenAI or Anthropic based on the open_ai_config row's `ai_provider`
 * column, and always returns a JSON STRING whose decoded shape exposes
 * choices[0]['text'] — identical to Openai_api::open_ai_completion — so every
 * existing caller of get_ai_reply_open_ai keeps working unchanged.
 */
class Ai_provider
{
    /**
     * @param array|object $config_row  A row from open_ai_config.
     * @param array $messages           Chat messages [{role,content}].
     * @param array $overrides          Optional: model, max_tokens, temperature, instruction, description, human, system.
     * @return string JSON string (choices[0].text present; or {error:{message}}).
     */
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

        if ($provider === 'anthropic') {
            $api_key = secret_decrypt(isset($cfg['anthropic_secret_key']) ? $cfg['anthropic_secret_key'] : '');
            if (empty($api_key)) {
                return json_encode(array('error' => array('message' => 'Anthropic API key is not configured.')));
            }
            $model = isset($overrides['model']) && $overrides['model'] ? $overrides['model']
                : (!empty($cfg['anthropic_model']) ? $cfg['anthropic_model'] : 'claude-haiku-4-5');
            // Anthropic wants system separate; pull leading system message if present.
            $system = isset($overrides['system']) ? $overrides['system'] : '';
            $CI->load->library('Anthropic_api');
            return $CI->anthropic_api->anthropic_completion($api_key, $messages, $model, $max_tokens, $system, $temperature);
        }

        // default: OpenAI
        $api_key = secret_decrypt(isset($cfg['open_ai_secret_key']) ? $cfg['open_ai_secret_key'] : '');
        if (empty($api_key)) {
            return json_encode(array('error' => array('message' => 'OpenAI API key is not configured.')));
        }
        $model = isset($overrides['model']) && $overrides['model'] ? $overrides['model']
            : (!empty($cfg['models']) ? $cfg['models'] : 'gpt-4o-mini');
        $instruction = isset($overrides['instruction']) ? $overrides['instruction'] : (isset($cfg['instruction_to_ai']) ? $cfg['instruction_to_ai'] : '');
        $description = isset($overrides['description']) ? $overrides['description'] : '';
        $human = isset($overrides['human']) ? $overrides['human'] : '';
        $CI->load->library('Openai_api');
        return $CI->openai_api->open_ai_completion($api_key, $messages, $model, $max_tokens, $instruction, $description, $human, $temperature);
    }
}
