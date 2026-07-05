<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** SPEC-15 — Telegram Bot API client. */
class Telegram_api
{
    private function call($token, $method, $params = array())
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.telegram.org/bot' . $token . '/' . $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true);
    }

    public function get_me($token) { return $this->call($token, 'getMe'); }

    public function send_message($token, $chat_id, $text)
    {
        return $this->call($token, 'sendMessage', array('chat_id' => $chat_id, 'text' => $text));
    }

    public function set_webhook($token, $url, $secret)
    {
        return $this->call($token, 'setWebhook', array('url' => $url, 'secret_token' => $secret));
    }
}
