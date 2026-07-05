<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** SPEC-10 — WhatsApp Cloud API client (Graph API v21.0). */
class Whatsapp_api
{
    private function post($phone_number_id, $access_token, $payload)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://graph.facebook.com/v21.0/' . $phone_number_id . '/messages');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Authorization: Bearer ' . $access_token));
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true);
    }

    public function send_text($access_token, $phone_number_id, $to, $text)
    {
        return $this->post($phone_number_id, $access_token, array(
            'messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text',
            'text' => array('preview_url' => true, 'body' => $text),
        ));
    }

    public function send_template($access_token, $phone_number_id, $to, $template_name, $lang = 'en', $components = array())
    {
        $tpl = array('name' => $template_name, 'language' => array('code' => $lang));
        if (!empty($components)) $tpl['components'] = $components;
        return $this->post($phone_number_id, $access_token, array(
            'messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => $tpl,
        ));
    }
}
