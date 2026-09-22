<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Unified Inbox (Phase 9.5, brief §13/§14/§46) — pure / DB-shape logic factored out of
 * Inbox.php so it is unit-testable without a full CodeIgniter bootstrap
 * (tests/inbox/run.php). The controller calls these same functions: a passing test proves
 * the real code path, not a parallel reimplementation.
 *
 * Nothing here touches the database directly except the *_scope_* helpers, which take the
 * query builder as an argument (duck-typed: where/like/or_like/group_start/group_end/or_where)
 * so tests can pass a recording fake instead of a real CI DB object.
 */

if (!function_exists('inbox_status_action_map')) {
    /** UI action -> target handoff_state. Every action here is agent-initiated (brief §14). */
    function inbox_status_action_map()
    {
        return array(
            'resolve'       => 'RESOLVED',
            'reopen'        => 'AI_ACTIVE',
            'takeover'      => 'HUMAN_ACTIVE',
            'return_to_bot' => 'AI_ACTIVE',
            'assist'        => 'AI_ASSIST',
        );
    }
}

if (!function_exists('inbox_status_action_to_state')) {
    function inbox_status_action_to_state($action)
    {
        $map = inbox_status_action_map();
        return isset($map[$action]) ? $map[$action] : null;
    }
}

if (!function_exists('inbox_handoff_label')) {
    /** Owner-language label for a handoff_state (brief §46: no bot_paused_until/XeroChat jargon). */
    function inbox_handoff_label($state)
    {
        $m = array(
            'AI_ACTIVE'         => 'AI is replying',
            'WAITING_FOR_HUMAN' => 'Waiting for a teammate',
            'HUMAN_ACTIVE'      => "You're handling this",
            'AI_ASSIST'         => 'AI is helping you',
            'RESOLVED'          => 'Closed',
        );
        return isset($m[$state]) ? $m[$state] : $state;
    }
}

if (!function_exists('inbox_takeover_path')) {
    /**
     * Ordered handoff_state hops an agent must walk through to reach HUMAN_ACTIVE from
     * $from_state, following the brief §14 edges exactly (conversation_helper.php's
     * conversation_transition_map): AI_ACTIVE has no direct edge to HUMAN_ACTIVE, only via
     * WAITING_FOR_HUMAN. Every hop here is verified allowed for actor_type='agent' in
     * tests/inbox/run.php against conversation_transition_allowed().
     */
    function inbox_takeover_path($from_state)
    {
        $paths = array(
            'AI_ACTIVE'         => array('WAITING_FOR_HUMAN', 'HUMAN_ACTIVE'),
            'WAITING_FOR_HUMAN' => array('HUMAN_ACTIVE'),
            'AI_ASSIST'         => array('HUMAN_ACTIVE'),
            'RESOLVED'          => array('AI_ACTIVE', 'WAITING_FOR_HUMAN', 'HUMAN_ACTIVE'),
            'HUMAN_ACTIVE'      => array(),
        );
        return isset($paths[$from_state]) ? $paths[$from_state] : array('HUMAN_ACTIVE');
    }
}

if (!function_exists('inbox_resolve_path')) {
    /** Ordered hops to RESOLVED. Only HUMAN_ACTIVE -> RESOLVED exists, so from any other
     *  state we take over first (brief §14 has no AI_ASSIST -> RESOLVED or WAITING -> RESOLVED edge). */
    function inbox_resolve_path($from_state)
    {
        if ($from_state === 'HUMAN_ACTIVE') return array('RESOLVED');
        return array_merge(inbox_takeover_path($from_state), array('RESOLVED'));
    }
}

if (!function_exists('inbox_platform_to_policy_key')) {
    /**
     * conversations.platform ('fb'|'ig'|'wa'|'tg'|'web') -> channel_policy_decide() /
     * ChannelRegistry key. 'wa' is ambiguous (OpenWA vs Cloud share the platform value per
     * ADR-001's "wa account-id collision" note); callers that know the resolved adapter pass
     * it through $wa_adapter_key.
     */
    function inbox_platform_to_policy_key($platform, $wa_adapter_key = 'wa')
    {
        if ($platform === 'wa') return $wa_adapter_key === 'wa_cloud' ? 'wa_cloud' : 'wa';
        return $platform;
    }
}

if (!function_exists('inbox_reply_decision')) {
    /**
     * Whether a human agent's reply may be sent right now. The brief requires every human
     * reply to go through channel_policy_decide(sender='human') first; on deny the caller
     * must show WHY (policy_explain) and must NOT fabricate a sent message.
     * @return array{allowed:bool, reason:string, window:string, messaging_type:?string, tag:?string, explain:string}
     */
    function inbox_reply_decision($policy_platform, $last_inbound_at, $opted_out = false, $now = null)
    {
        $ctx = array('sender' => 'human', 'last_inbound_at' => $last_inbound_at, 'opted_out' => (bool) $opted_out);
        if ($now !== null) $ctx['now'] = $now;
        $d = channel_policy_decide($policy_platform, $ctx);
        $d['explain'] = channel_policy_explain($d['reason']);
        return $d;
    }
}

if (!function_exists('inbox_scope_conversations')) {
    /**
     * Apply the tenant guard + list filters to a query builder. user_id is ALWAYS applied
     * first, unconditionally — no filter path may bypass it (no IDOR, brief §38/§57).
     * Expects the builder already has FROM conversations c (customers joined as cu when a
     * search is requested).
     *
     * $filters: platform, status, handoff_state, assigned_user_id, unread_only (bool), label_id, search
     */
    function inbox_scope_conversations($db, $user_id, array $filters = array())
    {
        $db->where('c.user_id', (int) $user_id);

        if (!empty($filters['platform'])) $db->where('c.platform', (string) $filters['platform']);
        if (!empty($filters['status'])) $db->where('c.status', (string) $filters['status']);
        if (!empty($filters['handoff_state'])) $db->where('c.handoff_state', (string) $filters['handoff_state']);
        if (array_key_exists('assigned_user_id', $filters) && $filters['assigned_user_id'] !== null && $filters['assigned_user_id'] !== '') {
            $db->where('c.assigned_user_id', (int) $filters['assigned_user_id']);
        }
        if (!empty($filters['unread_only'])) $db->where('c.unread_count >', 0);
        if (!empty($filters['label_id']) && (int) $filters['label_id'] > 0) {
            // Labels are tenant-scoped twice: the conversation (c.user_id, above) and the
            // label row itself (cl.user_id), so another tenant's label id matches nothing.
            $db->where(inbox_label_filter_sql((int) $user_id, (int) $filters['label_id']), null, false);
        }

        if (!empty($filters['search'])) {
            $s = trim((string) $filters['search']);
            $db->group_start();
            $db->like('cu.display_name', $s);
            $db->or_like('cu.phone', $s);
            $db->or_where(
                "EXISTS (SELECT 1 FROM livechat_messages lm WHERE lm.conversation_id = c.id AND lm.message_content LIKE " .
                    "'%" . addcslashes($s, "%_\\'") . "%' LIMIT 1)"
            );
            $db->group_end();
        }
        return $db;
    }
}

if (!function_exists('inbox_scope_conversation_owner')) {
    /** Tenant + id guard for a single conversation (thread view, reply, assign, notes, status). */
    function inbox_scope_conversation_owner($db, $conversation_id, $user_id)
    {
        return $db->where('id', (int) $conversation_id)->where('user_id', (int) $user_id);
    }
}

// ------------------------------------------------------------------ labels (conversation_labels)

if (!function_exists('inbox_label_filter_sql')) {
    /** EXISTS condition for "conversation c carries label $label_id". Both ids are cast to int
     *  here, so the string is safe to hand to the query builder unescaped. */
    function inbox_label_filter_sql($user_id, $label_id)
    {
        return 'EXISTS (SELECT 1 FROM conversation_labels cl WHERE cl.conversation_id = c.id AND cl.user_id = '
            . (int) $user_id . ' AND cl.label_id = ' . (int) $label_id . ')';
    }
}

if (!function_exists('inbox_label_max_per_tenant')) {
    function inbox_label_max_per_tenant() { return 200; }
}

if (!function_exists('inbox_label_normalize_name')) {
    /**
     * A label name as it is stored: tags stripped, whitespace collapsed, trimmed, at most 60
     * characters (the column width). NULL when nothing usable is left.
     */
    function inbox_label_normalize_name($name)
    {
        if (!is_string($name)) return null;
        $name = strip_tags($name);
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name);
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
        if ($name === '') return null;
        return mb_substr($name, 0, 60);
    }
}

if (!function_exists('inbox_label_normalize_color')) {
    /** '#rrggbb' (lower case) or NULL. Anything else is dropped, never stored or echoed. */
    function inbox_label_normalize_color($color)
    {
        if (!is_string($color)) return null;
        $color = strtolower(trim($color));
        if ($color !== '' && $color[0] !== '#') $color = '#' . $color;
        return preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : null;
    }
}

if (!function_exists('inbox_scope_labels')) {
    /** Tenant guard for inbox_labels, applied first (same rule as inbox_scope_conversations). */
    function inbox_scope_labels($db, $user_id, $label_id = null)
    {
        $db->where('user_id', (int) $user_id);
        if ($label_id !== null) $db->where('id', (int) $label_id);
        return $db;
    }
}

// ------------------------------------------------------------------ canned responses by channel

if (!function_exists('inbox_canned_channels_normalize')) {
    /**
     * The value stored in canned_response.channels. NULL means "every channel" (the value every
     * pre-existing row has, so those keep showing everywhere). Accepts an array or a
     * comma-separated string of conversation platforms. Unknown values are dropped. Choosing
     * every platform, or none, or 'all', is stored as NULL too, so "all" has one spelling.
     */
    function inbox_canned_channels_normalize($input)
    {
        if ($input === null || $input === '' || $input === 'all') return null;
        if (is_string($input)) $input = explode(',', $input);
        if (!is_array($input)) return null;
        $known = array('fb', 'ig', 'wa', 'tg', 'web');
        $picked = array();
        foreach ($input as $v) {
            $v = strtolower(trim((string) $v));
            if (in_array($v, $known, true)) $picked[$v] = true;
        }
        if (empty($picked) || count($picked) === count($known)) return null;
        $out = array();
        foreach ($known as $k) if (isset($picked[$k])) $out[] = $k; // stable order
        return implode(',', $out);
    }
}

if (!function_exists('inbox_canned_visible_on')) {
    /** Whether a canned response with this `channels` value is offered on $platform. */
    function inbox_canned_visible_on($channels, $platform)
    {
        if ($channels === null || trim((string) $channels) === '') return true;
        return in_array((string) $platform, explode(',', (string) $channels), true);
    }
}

if (!function_exists('inbox_scope_canned')) {
    /**
     * Tenant guard (first, always) plus the per-channel filter for canned_response.
     * $platform NULL/'' = no channel filter (every row of the tenant). $has_channels_column is
     * FALSE before the 2026-09-30b migration has run: then every row is returned, as before.
     */
    function inbox_scope_canned($db, $user_id, $platform = null, $has_channels_column = true)
    {
        $db->where('user_id', (int) $user_id);
        $platform = (string) $platform;
        if ($platform === '' || !$has_channels_column) return $db;
        if (!in_array($platform, array('fb', 'ig', 'wa', 'tg', 'web'), true)) $platform = '-';
        $db->group_start();
        $db->where('channels IS NULL', null, false);
        $db->or_where('channels', '');
        $db->or_where("FIND_IN_SET('" . $platform . "', channels) > 0", null, false);
        $db->group_end();
        return $db;
    }
}

// ------------------------------------------------------------------ attachments

if (!function_exists('inbox_safe_media_url')) {
    /** An absolute http(s) URL, or NULL. Nothing else (javascript:, data:, relative) is ever rendered. */
    function inbox_safe_media_url($url)
    {
        if (!is_string($url)) return null;
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) return null;
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') return null;
        if ((string) parse_url($url, PHP_URL_HOST) === '') return null;
        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }
}

if (!function_exists('inbox_message_render')) {
    /**
     * What the thread shows for one livechat_messages.message_content. Never modifies the row.
     *
     * The stored shapes (all written by existing code, none by this function):
     *  - plain text (wa, tg, web and most bot rows)
     *  - the raw Meta webhook body for fb/ig inbound (Messenger_bot -> Home::insert_livechat_data),
     *    whose message.attachments[].payload.url is the media URL Meta sent
     *  - a Send API body for fb/ig media or template sends (Livechat, and Inbox::reply_image),
     *    whose message.attachment.payload.url is the media URL
     *  - '[non-text message]' / '[non-text]', which Whatsapp_bot, Telegram_bot and Openwa_bot log
     *    for media: those channels send a media id, never a URL, so there is nothing to show
     *
     * @return array{text:string, attachments:array<int,array{type:string,url:?string}>, media_unavailable:bool}
     */
    function inbox_message_render($content)
    {
        $out = array('text' => '', 'attachments' => array(), 'media_unavailable' => false);
        $content = (string) $content;
        $trim = trim($content);
        if ($trim === '[non-text message]' || $trim === '[non-text]') {
            $out['media_unavailable'] = true;
            return $out;
        }
        if ($trim === '' || ($trim[0] !== '{')) { $out['text'] = $content; return $out; }
        $j = json_decode($trim, true);
        if (!is_array($j)) { $out['text'] = $content; return $out; }

        $kinds = array('image' => 'image', 'video' => 'video', 'audio' => 'audio', 'file' => 'file', 'sticker' => 'image');
        $add = function ($type, $payload) use (&$out, $kinds) {
            $type = is_string($type) ? strtolower($type) : '';
            if (!isset($kinds[$type]) || !is_array($payload)) return false;
            $url = inbox_safe_media_url(isset($payload['url']) ? $payload['url'] : null);
            if ($url === null) return false;
            $out['attachments'][] = array('type' => $kinds[$type], 'url' => $url);
            return true;
        };

        // Meta webhook body (fb/ig inbound): entry[0].messaging[0].message | postback
        if (isset($j['object']) && isset($j['entry'][0]['messaging'][0]) && is_array($j['entry'][0]['messaging'][0])) {
            $ev = $j['entry'][0]['messaging'][0];
            if (isset($ev['message']) && is_array($ev['message'])) {
                $m = $ev['message'];
                if (isset($m['text']) && is_scalar($m['text'])) $out['text'] = (string) $m['text'];
                $unrenderable = false;
                if (isset($m['attachments']) && is_array($m['attachments'])) {
                    foreach ($m['attachments'] as $a) {
                        if (!is_array($a) || !$add(isset($a['type']) ? $a['type'] : '', isset($a['payload']) ? $a['payload'] : null)) $unrenderable = true;
                    }
                }
                if ($unrenderable && empty($out['attachments']) && $out['text'] === '') $out['media_unavailable'] = true;
            } elseif (isset($ev['postback']['title']) && is_scalar($ev['postback']['title'])) {
                $out['text'] = (string) $ev['postback']['title'];
            }
            return $out;
        }

        // Send API body (fb/ig outbound): message.text | message.attachment
        if (isset($j['message']) && is_array($j['message'])) {
            $m = $j['message'];
            if (isset($m['text']) && is_scalar($m['text'])) $out['text'] = (string) $m['text'];
            if (isset($m['attachment']) && is_array($m['attachment'])) {
                $a = $m['attachment'];
                if (!$add(isset($a['type']) ? $a['type'] : '', isset($a['payload']) ? $a['payload'] : null)
                    && isset($a['payload']['text']) && is_scalar($a['payload']['text']) && $out['text'] === '') {
                    $out['text'] = (string) $a['payload']['text']; // button / generic template text
                }
            }
            if ($out['text'] === '' && empty($out['attachments'])) $out['text'] = $content;
            return $out;
        }

        $out['text'] = $content; // some other JSON: shown as stored, as before
        return $out;
    }
}

if (!function_exists('inbox_message_preview')) {
    /** One-line list preview for a message_content (max 120 characters, no markup). */
    function inbox_message_preview($content)
    {
        if ($content === null) return '';
        $r = inbox_message_render($content);
        $text = trim(strip_tags($r['text']));
        if ($text === '' && !empty($r['attachments'])) $text = '[' . $r['attachments'][0]['type'] . ']';
        if ($text === '' && $r['media_unavailable']) $text = '[media]';
        return mb_substr($text, 0, 120);
    }
}

if (!function_exists('inbox_image_max_bytes')) {
    /** 5 MB: the lowest image limit of the providers that can send images by URL
     *  (WhatsApp Cloud 5 MB, Telegram sendPhoto by URL 5 MB, Messenger 8 MB for images). */
    function inbox_image_max_bytes() { return 5 * 1024 * 1024; }
}

if (!function_exists('inbox_image_upload_validate')) {
    /**
     * Validates an uploaded image from its BYTES, never from the client's file name or MIME type.
     * Only JPEG and PNG (the formats every image-capable channel accepts). The stored extension
     * comes from the detected type, so the file can never be stored as .php/.phtml/.svg.
     *
     * @return array{ok:bool, error:string, ext:?string, mime:?string}
     */
    function inbox_image_upload_validate($path, $size, $upload_error = 0, $max_bytes = null)
    {
        $fail = function ($e) { return array('ok' => false, 'error' => $e, 'ext' => null, 'mime' => null); };
        if ($max_bytes === null) $max_bytes = inbox_image_max_bytes();
        if ((int) $upload_error === 1 || (int) $upload_error === 2) return $fail('The image is larger than 5 MB');
        if ((int) $upload_error !== 0) return $fail('The upload did not complete');
        if (!is_string($path) || $path === '' || !is_file($path)) return $fail('No image was uploaded');
        $real = (int) filesize($path);
        if ($real <= 0 || (int) $size <= 0) return $fail('The image is empty');
        if ($real > $max_bytes || (int) $size > $max_bytes) return $fail('The image is larger than 5 MB');

        $allowed = array('image/jpeg' => array('jpg', IMAGETYPE_JPEG), 'image/png' => array('png', IMAGETYPE_PNG));
        $mime = function_exists('finfo_open') ? (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path) : '';
        if (!isset($allowed[$mime])) return $fail('Only JPEG and PNG images can be sent');
        $info = @getimagesize($path);
        if (!is_array($info) || (int) $info[2] !== $allowed[$mime][1]) return $fail('The file is not a valid image');
        if ((int) $info[0] < 1 || (int) $info[1] < 1 || (int) $info[0] > 10000 || (int) $info[1] > 10000) return $fail('The image dimensions are not supported');

        // Defence in depth: the file is served as a static image and never executed, but a
        // polyglot carrying PHP or HTML markup has no business being stored at all.
        $bytes = (string) file_get_contents($path);
        if (preg_match('/<\?php|<script|<html/i', $bytes)) return $fail('The file is not a valid image');

        return array('ok' => true, 'error' => '', 'ext' => $allowed[$mime][0], 'mime' => $mime);
    }
}

if (!function_exists('inbox_image_storage_name')) {
    /** Relative path under the web root for a stored reply image: upload/inbox/YYYY/MM/inbox{uid}_{random}.{ext}.
     *  Same place as Livechat's own uploads (upload/), which is the app_upload volume in production. */
    function inbox_image_storage_name($user_id, $ext, $random_hex, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $ext = in_array($ext, array('jpg', 'png'), true) ? $ext : 'jpg';
        $rand = preg_replace('/[^a-f0-9]/', '', strtolower((string) $random_hex));
        return 'upload/inbox/' . date('Y', $now) . '/' . date('m', $now) . '/inbox' . (int) $user_id . '_' . $rand . '.' . $ext;
    }
}

if (!function_exists('inbox_image_message_content')) {
    /** The livechat_messages.message_content stored for an image reply: the Send API shape, so
     *  Livechat (fb/ig) renders it the way it renders its own media sends, and
     *  inbox_message_render() shows it on every channel. */
    function inbox_image_message_content($recipient, $url)
    {
        return json_encode(array(
            'recipient' => array('id' => (string) $recipient),
            'message'   => array('attachment' => array('type' => 'image', 'payload' => array('url' => (string) $url, 'is_reusable' => false))),
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
