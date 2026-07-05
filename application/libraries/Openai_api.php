<?php 

class Openai_api 
{
    /**
     * Call OpenAI completion endpoint.
     *
     * Supports two calling styles:
     * 1. Legacy text-completion (prompt string) for old text models.
     * 2. Chat-completion (messages array) for modern chat models.
     *
     * @param string $api_key
     * @param string|array $prompt_or_messages  Either a prompt string (legacy) or an array of chat messages.
     * @param string $model
     * @param int    $max_tokens
     * @param string $instruction
     * @param string $description
     * @param string $human
     * @param float  $temperature
     * @return string JSON response
     */
    public function open_ai_completion($api_key, $prompt_or_messages, $model="text-davinci-003", $max_tokens=1500, $instruction="AI Agent", $description="description in the flow", $human="", $temperature=0.4)
    {
        $text_completion_model = array(
            "text-davinci-003", "text-davinci-002", "text-curie-001", "text-babbage-001", 
            "text-ada-001", "davinci", "curie", "babbage", "ada"
        );

        // Legacy /v1/completions models are an explicit allow-list; everything else
        // (gpt-4o, gpt-4o-mini, gpt-4.1, gpt-4.1-mini, future models) uses /v1/chat/completions.
        $completion = in_array($model, $text_completion_model) ? "text" : "chat";

        if ($completion == "text") {
            $data['model'] = $model;
            $data['prompt'] = is_array($prompt_or_messages) ? $this->messages_to_prompt($prompt_or_messages) : $prompt_or_messages;
            $data['max_tokens'] = $max_tokens;
            $data['temperature'] = $temperature;
            $data['top_p'] = 1;
            $data['frequency_penalty'] = 0;
            $data['presence_penalty'] = 0;
            $url = "https://api.openai.com/v1/completions";
        } else {
            $data['model'] = $model;
            $data['max_tokens'] = $max_tokens;
            $data['temperature'] = $temperature;
            $data['top_p'] = 1;
            $data['frequency_penalty'] = 0;
            $data['presence_penalty'] = 0;

            if (is_array($prompt_or_messages) && !empty($prompt_or_messages)) {
                $data['messages'] = $prompt_or_messages;
            } else {
                $system_content = $instruction . "." . $description;
                $data['messages'] = array(
                    array("role" => "system", "content" => $system_content),
                    array("role" => "user", "content" => $human)
                );
            }

            $url = "https://api.openai.com/v1/chat/completions";
        }

        $data = json_encode($data);

        $headers = array("Content-Type: application/json", "Authorization: Bearer {$api_key}");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $result = curl_exec($ch);

        if ($completion == "chat") {
            $result_array = json_decode($result, true);
            if (isset($result_array['choices'][0]['message']['content'])) {
                $response = $result_array['choices'][0]['message']['content'];
                $result_array['choices'][0]['text'] = $response;
                $result = json_encode($result_array);
            }
            return $result;
        }

        return $result;
    }

    /**
     * Convert a chat messages array into a single prompt string for legacy text models.
     */
    private function messages_to_prompt($messages)
    {
        $prompt = "";
        foreach ($messages as $msg) {
            $role = isset($msg['role']) ? $msg['role'] : 'user';
            $content = isset($msg['content']) ? $msg['content'] : '';
            if ($role == 'system') {
                $prompt .= "Instructions: " . $content . "\n\n";
            } elseif ($role == 'assistant') {
                $prompt .= "AI: " . $content . "\n";
            } else {
                $prompt .= "Human: " . $content . "\n";
            }
        }
        $prompt .= "AI:";
        return $prompt;
    }
}
