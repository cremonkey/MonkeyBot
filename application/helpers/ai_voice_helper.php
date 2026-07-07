<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Voice-message transcription via OpenAI Whisper, so audio DMs flow through
 * the normal text pipeline (keyword match, AI reply, flows) on fb/ig/wa.
 */

if (!function_exists('ai_transcribe_audio')) {
    /**
     * Download an audio file and transcribe it.
     *
     * @param int    $user_id     owner account (for the OpenAI key)
     * @param string $audio_url   direct URL of the audio file
     * @param string $auth_bearer optional bearer token for the download (WhatsApp media)
     * @return string|false transcription text, or false
     */
    function ai_transcribe_audio($user_id, $audio_url, $auth_bearer = '')
    {
        $ci = &get_instance();
        try {
            $cfg = $ci->db->select('open_ai_secret_key')->from('open_ai_config')
                ->where('user_id', (int) $user_id)->limit(1)->get()->row_array();
            $api_key = isset($cfg['open_ai_secret_key']) ? trim((string) $cfg['open_ai_secret_key']) : '';
            if ($api_key === '' || $audio_url === '') return false;

            // download (max 20 MB)
            $headers = array();
            if ($auth_bearer !== '') $headers[] = 'Authorization: Bearer ' . $auth_bearer;
            $ch = curl_init($audio_url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
            ));
            $audio = curl_exec($ch);
            $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            if ($audio === false || strlen($audio) < 100 || strlen($audio) > 20 * 1024 * 1024) return false;

            $ext = 'mp3';
            if (strpos($ctype, 'ogg') !== false) $ext = 'ogg';
            elseif (strpos($ctype, 'mp4') !== false || strpos($ctype, 'm4a') !== false || strpos($ctype, 'aac') !== false) $ext = 'm4a';
            elseif (strpos($ctype, 'wav') !== false) $ext = 'wav';
            elseif (strpos($ctype, 'webm') !== false) $ext = 'webm';
            else {
                // fall back to the URL's extension when content-type is generic
                $url_ext = strtolower(pathinfo(parse_url($audio_url, PHP_URL_PATH), PATHINFO_EXTENSION));
                if (in_array($url_ext, array('mp3', 'mp4', 'm4a', 'ogg', 'oga', 'wav', 'webm', 'flac', 'mpga'))) $ext = $url_ext === 'oga' ? 'ogg' : $url_ext;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'voice_') . '.' . $ext;
            file_put_contents($tmp, $audio);

            $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $api_key),
                CURLOPT_POSTFIELDS     => array(
                    'model' => 'whisper-1',
                    'file'  => new CURLFile($tmp),
                ),
                CURLOPT_SSL_VERIFYPEER => true,
            ));
            $raw = curl_exec($ch);
            curl_close($ch);
            @unlink($tmp);

            $res = json_decode((string) $raw, true);
            if (!empty($res['text'])) return trim($res['text']);
            log_message('error', 'ai_transcribe_audio: ' . substr((string) $raw, 0, 300));
            return false;
        } catch (Exception $e) {
            log_message('error', 'ai_transcribe_audio: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('wa_media_url')) {
    /**
     * Resolve a WhatsApp Cloud API media id to its short-lived download URL.
     */
    function wa_media_url($media_id, $token)
    {
        $ch = curl_init('https://graph.facebook.com/v17.0/' . rawurlencode($media_id));
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $token),
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $raw = curl_exec($ch);
        curl_close($ch);
        $res = json_decode((string) $raw, true);
        return isset($res['url']) ? $res['url'] : '';
    }
}
