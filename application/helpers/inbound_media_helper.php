<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Inbound media for WhatsApp Cloud, Telegram and OpenWA webhooks (docs/product/unified-inbox.md,
 * "Inbound media").
 *
 * The webhooks deliver a media id, never a URL. This helper downloads the file from the provider
 * and stores it as upload/inbox_in/YYYY/MM/{user_id}_{random}.{ext}, so the Unified Inbox can
 * show it. The row keeps a readable message_content (the caption, or "[image]" and so on) and the
 * file reference goes in the nullable livechat_messages.media_json column
 * (migration 2026-10-01-inbound-media.sql).
 *
 * Rules:
 *  - the type comes from the file's BYTES (finfo). The provider's MIME type is used only to tell
 *    apart containers finfo cannot (mp4 audio vs video, OOXML vs zip, old Office files). The
 *    sender's file name is never used.
 *  - only the types in inbound_media_allowed_types() are stored, with the extension from that map.
 *    Nothing executable is stored, and the path is built from integers, a date and random hex only.
 *  - size cap (config item inbound_media_max_bytes, default 16 MB), checked against the declared
 *    size, during the download and on the result. A short timeout (inbound_media_timeout,
 *    default 8 s per request, connect 3 s). HTTPS only.
 *  - failures never throw. They return ok=false, and the capture functions log at 'error' level
 *    without the access token or the Telegram file URL (which contains the bot token).
 *
 * The HTTP transport is injectable: a callable ($url, array $headers, $max_bytes, $timeout)
 * returning array{ok:bool, status:int, body:string, content_type:string, error:string}.
 */

if (!function_exists('inbound_media_allowed_types')) {
    /** Detected MIME type => [extension, inbox attachment type]. Nothing else is ever stored. */
    function inbound_media_allowed_types()
    {
        return array(
            'image/jpeg' => array('jpg', 'image'),
            'image/png'  => array('png', 'image'),
            'image/webp' => array('webp', 'image'),
            'image/gif'  => array('gif', 'image'),
            'audio/ogg'  => array('ogg', 'audio'),
            'audio/opus' => array('ogg', 'audio'),
            'application/ogg' => array('ogg', 'audio'),
            'audio/mpeg' => array('mp3', 'audio'),
            'audio/mp4'  => array('m4a', 'audio'),
            'audio/x-m4a' => array('m4a', 'audio'),
            'audio/aac'  => array('aac', 'audio'),
            'audio/x-hx-aac-adts' => array('aac', 'audio'),
            'audio/amr'  => array('amr', 'audio'),
            'audio/x-wav' => array('wav', 'audio'),
            'audio/wav'  => array('wav', 'audio'),
            'video/mp4'  => array('mp4', 'video'),
            'video/3gpp' => array('3gp', 'video'),
            'video/quicktime' => array('mov', 'video'),
            'video/webm' => array('webm', 'video'),
            'application/pdf' => array('pdf', 'file'),
            'application/msword' => array('doc', 'file'),
            'application/vnd.ms-excel' => array('xls', 'file'),
            'application/vnd.ms-powerpoint' => array('ppt', 'file'),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => array('docx', 'file'),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => array('xlsx', 'file'),
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => array('pptx', 'file'),
        );
    }
}

if (!function_exists('inbound_media_placeholder')) {
    /** The message_content stored when a media message has no caption (and no transcript). */
    function inbound_media_placeholder($label)
    {
        $labels = array('image', 'sticker', 'voice note', 'audio', 'video', 'document');
        return '[' . (in_array($label, $labels, true) ? $label : 'media') . ']';
    }
}

// ------------------------------------------------------------------ payload extraction (pure)

if (!function_exists('inbound_media_extract_wa_cloud')) {
    /**
     * The media part of one WhatsApp Cloud `messages[]` item, or NULL for text and other types.
     * @return array{kind:string,label:string,id:string,caption:string,mime:string,size:int}|null
     */
    function inbound_media_extract_wa_cloud($m)
    {
        if (!is_array($m) || !isset($m['type']) || !is_string($m['type'])) return null;
        $map = array('image' => array('image', 'image'), 'sticker' => array('image', 'sticker'),
            'audio' => array('audio', 'audio'), 'voice' => array('audio', 'voice note'),
            'video' => array('video', 'video'), 'document' => array('file', 'document'));
        $t = $m['type'];
        if (!isset($map[$t]) || !isset($m[$t]) || !is_array($m[$t])) return null;
        $o = $m[$t];
        $id = isset($o['id']) && is_scalar($o['id']) ? (string) $o['id'] : '';
        if ($id === '') return null;
        $label = $map[$t][1];
        if ($t === 'audio' && !empty($o['voice'])) $label = 'voice note';
        return array(
            'kind' => $map[$t][0], 'label' => $label, 'id' => $id,
            'caption' => isset($o['caption']) && is_scalar($o['caption']) ? trim((string) $o['caption']) : '',
            'mime' => isset($o['mime_type']) && is_string($o['mime_type']) ? $o['mime_type'] : '',
            'size' => isset($o['file_size']) ? (int) $o['file_size'] : 0,
        );
    }
}

if (!function_exists('inbound_media_extract_telegram')) {
    /**
     * The media part of a Telegram message, or NULL. For photos, the largest size that fits
     * $max_bytes (Telegram sends several sizes, smallest first).
     * @return array{kind:string,label:string,id:string,caption:string,mime:string,size:int}|null
     */
    function inbound_media_extract_telegram($msg, $max_bytes = 0)
    {
        if (!is_array($msg)) return null;
        $caption = isset($msg['caption']) && is_scalar($msg['caption']) ? trim((string) $msg['caption']) : '';
        $pick = function ($o, $kind, $label) use ($caption) {
            if (!is_array($o) || !isset($o['file_id']) || !is_scalar($o['file_id']) || (string) $o['file_id'] === '') return null;
            return array('kind' => $kind, 'label' => $label, 'id' => (string) $o['file_id'], 'caption' => $caption,
                'mime' => isset($o['mime_type']) && is_string($o['mime_type']) ? $o['mime_type'] : '',
                'size' => isset($o['file_size']) ? (int) $o['file_size'] : 0);
        };
        if (!empty($msg['photo']) && is_array($msg['photo'])) {
            $best = null;
            foreach ($msg['photo'] as $p) {
                if (!is_array($p)) continue;
                $sz = isset($p['file_size']) ? (int) $p['file_size'] : 0;
                if ($max_bytes > 0 && $sz > $max_bytes) continue;
                if ($best === null || $sz >= (isset($best['file_size']) ? (int) $best['file_size'] : 0)) $best = $p;
            }
            if ($best === null) $best = end($msg['photo']); // all too big: the size check fails it later
            return $pick($best, 'image', 'image');
        }
        $order = array('voice' => array('audio', 'voice note'), 'audio' => array('audio', 'audio'),
            'video' => array('video', 'video'), 'video_note' => array('video', 'video'),
            'animation' => array('video', 'video'), 'sticker' => array('image', 'sticker'),
            'document' => array('file', 'document'));
        foreach ($order as $k => $kl) {
            if (isset($msg[$k]) && is_array($msg[$k])) {
                if ($k === 'sticker' && !empty($msg[$k]['is_animated'])) return null; // .tgs (gzipped JSON): not a displayable file
                return $pick($msg[$k], $kl[0], $kl[1]);
            }
        }
        return null;
    }
}

if (!function_exists('inbound_media_extract_openwa')) {
    /**
     * OpenWA gateway `message.received` data: the gateway forwards the Evolution messageType but
     * no media URL and no download route, so only the kind is known.
     * @return array{kind:string,label:string}|null
     */
    function inbound_media_extract_openwa($data)
    {
        if (!is_array($data) || !isset($data['type']) || !is_string($data['type'])) return null;
        $map = array('imageMessage' => array('image', 'image'), 'stickerMessage' => array('image', 'sticker'),
            'audioMessage' => array('audio', 'audio'), 'pttMessage' => array('audio', 'voice note'),
            'videoMessage' => array('video', 'video'), 'ptvMessage' => array('video', 'video'),
            'documentMessage' => array('file', 'document'), 'documentWithCaptionMessage' => array('file', 'document'));
        if (!isset($map[$data['type']])) return null;
        return array('kind' => $map[$data['type']][0], 'label' => $map[$data['type']][1]);
    }
}

// ------------------------------------------------------------------ validation + storage (pure fs)

if (!function_exists('inbound_media_detect')) {
    /**
     * Type of $bytes from their content. $declared (provider MIME) only disambiguates containers.
     * @return array{ok:bool, error:string, mime:?string, ext:?string, type:?string}
     */
    function inbound_media_detect($bytes, $declared = '')
    {
        $fail = function ($e) { return array('ok' => false, 'error' => $e, 'mime' => null, 'ext' => null, 'type' => null); };
        if (!is_string($bytes) || $bytes === '') return $fail('empty file');
        if (!function_exists('finfo_open')) return $fail('fileinfo extension missing');
        $detected = strtolower((string) finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $bytes));
        $declared = strtolower(trim((string) explode(';', (string) $declared)[0]));
        $allowed = inbound_media_allowed_types();

        $mime = $detected;
        $ooxml = array('application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation');
        $ole = array('application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint');
        if (in_array($detected, array('application/zip', 'application/octet-stream'), true) && in_array($declared, $ooxml, true)
            && substr($bytes, 0, 4) === "PK\x03\x04" && strpos($bytes, '[Content_Types].xml') !== false) {
            $mime = $declared;
        } elseif (in_array($detected, array('application/cdfv2', 'application/x-ole-storage', 'application/vnd.ms-office'), true)
            && in_array($declared, $ole, true)) {
            $mime = $declared;
        } elseif ($detected === 'video/mp4' && strpos($declared, 'audio/') === 0) {
            $mime = 'audio/mp4'; // m4a voice notes are an mp4 container
        } elseif ($detected === 'video/webm' && strpos($declared, 'audio/') === 0) {
            return $fail('audio/webm is not stored'); // no reliable audio-only check
        }
        if (!isset($allowed[$mime])) return $fail('type not allowed: ' . ($detected !== '' ? $detected : 'unknown'));

        // Defence in depth: files are served statically and upload/*.php is denied by nginx, but a
        // polyglot carrying PHP (or, for images, HTML) markup has no business being stored at all.
        if (stripos($bytes, '<?php') !== false) return $fail('file contains PHP markup');
        if ($allowed[$mime][1] === 'image' && preg_match('/<script|<html/i', $bytes)) return $fail('image contains markup');

        return array('ok' => true, 'error' => '', 'mime' => $mime, 'ext' => $allowed[$mime][0], 'type' => $allowed[$mime][1]);
    }
}

if (!function_exists('inbound_media_storage_name')) {
    /** upload/inbox_in/YYYY/MM/{user_id}_{random}.{ext}: integers, a date and hex only. */
    function inbound_media_storage_name($user_id, $ext, $random_hex, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $exts = array();
        foreach (inbound_media_allowed_types() as $v) $exts[$v[0]] = true;
        if (!isset($exts[$ext])) return null;
        $rand = preg_replace('/[^a-f0-9]/', '', strtolower((string) $random_hex));
        if (strlen($rand) < 16) return null;
        return 'upload/inbox_in/' . date('Y', $now) . '/' . date('m', $now) . '/' . (int) $user_id . '_' . $rand . '.' . $ext;
    }
}

if (!function_exists('inbound_media_store_bytes')) {
    /**
     * Validates $bytes and writes them under $root.
     * @return array{ok:bool, error:string, media:?array{type:string,path:string,mime:string,size:int}}
     */
    function inbound_media_store_bytes($user_id, $bytes, $declared_mime, $root, $max_bytes, $now = null, $random_hex = null)
    {
        $fail = function ($e) { return array('ok' => false, 'error' => $e, 'media' => null); };
        if (!is_string($bytes) || strlen($bytes) === 0) return $fail('empty file');
        if (strlen($bytes) > (int) $max_bytes) return $fail('file larger than ' . (int) $max_bytes . ' bytes');
        $d = inbound_media_detect($bytes, $declared_mime);
        if (!$d['ok']) return $fail($d['error']);
        $rel = inbound_media_storage_name($user_id, $d['ext'], $random_hex === null ? bin2hex(random_bytes(12)) : $random_hex, $now);
        if ($rel === null) return $fail('could not build a storage name');
        $abs = rtrim((string) $root, '/') . '/' . $rel;
        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return $fail('storage directory not writable');
        if (@file_put_contents($abs, $bytes, LOCK_EX) !== strlen($bytes)) { @unlink($abs); return $fail('could not write the file'); }
        @chmod($abs, 0644);
        return array('ok' => true, 'error' => '', 'media' => array('type' => $d['type'], 'path' => $rel, 'mime' => $d['mime'], 'size' => strlen($bytes)));
    }
}

if (!function_exists('inbound_media_json')) {
    /** The livechat_messages.media_json value. $media NULL = media message whose file is not stored. */
    function inbound_media_json($kind, $media = null)
    {
        if (is_array($media)) {
            return json_encode(array('type' => $media['type'], 'path' => $media['path'], 'mime' => $media['mime'], 'size' => (int) $media['size']), JSON_UNESCAPED_SLASHES);
        }
        return json_encode(array('type' => in_array($kind, array('image', 'audio', 'video', 'file'), true) ? $kind : 'file', 'path' => null));
    }
}

// ------------------------------------------------------------------ provider fetch (injected transport)

if (!function_exists('inbound_media_curl_get')) {
    /** Default transport: HTTPS GET with a byte cap enforced while downloading. */
    function inbound_media_curl_get($url, array $headers, $max_bytes, $timeout)
    {
        $buf = ''; $over = false;
        $ch = curl_init((string) $url);
        $opts = array(
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(3, (int) $timeout),
            CURLOPT_TIMEOUT => (int) $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf, &$over, $max_bytes) {
                if (strlen($buf) + strlen($chunk) > $max_bytes) { $over = true; return 0; } // aborts the transfer
                $buf .= $chunk;
                return strlen($chunk);
            },
        );
        if (defined('CURLOPT_PROTOCOLS')) { $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS; $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS; }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = $over ? 'file larger than ' . (int) $max_bytes . ' bytes' : ($res === false ? 'transport: ' . curl_error($ch) : '');
        curl_close($ch);
        return array('ok' => $err === '' && $status >= 200 && $status < 300, 'status' => $status, 'body' => $buf, 'content_type' => $ctype, 'error' => $err);
    }
}

if (!function_exists('inbound_media_https_url')) {
    function inbound_media_https_url($url)
    {
        return is_string($url) && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && (string) parse_url($url, PHP_URL_HOST) !== '' && strlen($url) <= 4096;
    }
}

if (!function_exists('inbound_media_fetch_wa_cloud')) {
    /**
     * WhatsApp Cloud: GET {graph}/{media-id} (bearer) for the short-lived URL, then GET that URL
     * with the same bearer. Returns the bytes; never throws.
     * @return array{ok:bool, error:string, bytes:string, mime:string, source_url:string}
     */
    function inbound_media_fetch_wa_cloud($graph_base, $media_id, $token, $http, $max_bytes, $timeout)
    {
        $fail = function ($e) { return array('ok' => false, 'error' => $e, 'bytes' => '', 'mime' => '', 'source_url' => ''); };
        if (!preg_match('/^[A-Za-z0-9_.\-]{1,128}$/', (string) $media_id)) return $fail('invalid media id');
        if ((string) $token === '') return $fail('no access token');
        $auth = array('Authorization: Bearer ' . $token);
        $r = call_user_func($http, rtrim((string) $graph_base, '/') . '/' . rawurlencode($media_id), $auth, 65536, $timeout);
        if (empty($r['ok'])) return $fail('media lookup failed (HTTP ' . (int) ($r['status'] ?? 0) . ($r['error'] !== '' ? ', ' . $r['error'] : '') . ')');
        $meta = json_decode((string) $r['body'], true);
        if (!is_array($meta) || !inbound_media_https_url($meta['url'] ?? null)) return $fail('media lookup returned no https url');
        if (isset($meta['file_size']) && (int) $meta['file_size'] > $max_bytes) return $fail('file larger than ' . (int) $max_bytes . ' bytes');
        $d = call_user_func($http, $meta['url'], $auth, $max_bytes, $timeout);
        if (empty($d['ok'])) return $fail('media download failed (HTTP ' . (int) ($d['status'] ?? 0) . ($d['error'] !== '' ? ', ' . $d['error'] : '') . ')');
        return array('ok' => true, 'error' => '', 'bytes' => (string) $d['body'],
            'mime' => isset($meta['mime_type']) && is_string($meta['mime_type']) ? $meta['mime_type'] : (string) $d['content_type'],
            'source_url' => $meta['url']);
    }
}

if (!function_exists('inbound_media_fetch_telegram')) {
    /**
     * Telegram: getFile, then {api}/file/bot{token}/{file_path}. The file URL contains the bot
     * token, so it is returned (for transcription) but never put in an error message.
     * @return array{ok:bool, error:string, bytes:string, mime:string, source_url:string}
     */
    function inbound_media_fetch_telegram($api_base, $token, $file_id, $http, $max_bytes, $timeout)
    {
        $fail = function ($e) { return array('ok' => false, 'error' => $e, 'bytes' => '', 'mime' => '', 'source_url' => ''); };
        if (!preg_match('/^[A-Za-z0-9_\-]{1,256}$/', (string) $file_id)) return $fail('invalid file id');
        if (!preg_match('/^[0-9]+:[A-Za-z0-9_\-]+$/', (string) $token)) return $fail('invalid bot token');
        $base = rtrim((string) $api_base, '/');
        $r = call_user_func($http, $base . '/bot' . $token . '/getFile?file_id=' . rawurlencode($file_id), array(), 65536, $timeout);
        if (empty($r['ok'])) return $fail('getFile failed (HTTP ' . (int) ($r['status'] ?? 0) . ')');
        $j = json_decode((string) $r['body'], true);
        $path = isset($j['result']['file_path']) && is_string($j['result']['file_path']) ? $j['result']['file_path'] : '';
        if (empty($j['ok']) || !preg_match('#^[A-Za-z0-9_\-][A-Za-z0-9_\-./]{0,255}$#', $path) || strpos($path, '..') !== false) return $fail('getFile returned no usable file_path');
        if (isset($j['result']['file_size']) && (int) $j['result']['file_size'] > $max_bytes) return $fail('file larger than ' . (int) $max_bytes . ' bytes');
        $url = $base . '/file/bot' . $token . '/' . $path;
        $d = call_user_func($http, $url, array(), $max_bytes, $timeout);
        if (empty($d['ok'])) return $fail('file download failed (HTTP ' . (int) ($d['status'] ?? 0) . ')');
        return array('ok' => true, 'error' => '', 'bytes' => (string) $d['body'], 'mime' => '', 'source_url' => $url);
    }
}

// ------------------------------------------------------------------ webhook entry points

if (!function_exists('inbound_media_limits')) {
    /** [max_bytes, timeout] from config items inbound_media_max_bytes / inbound_media_timeout, clamped. */
    function inbound_media_limits($max_item = null, $timeout_item = null)
    {
        $max = (int) $max_item > 0 ? (int) $max_item : 16 * 1024 * 1024;
        $max = max(1024, min($max, 64 * 1024 * 1024));
        $t = (int) $timeout_item > 0 ? (int) $timeout_item : 8;
        return array($max, max(1, min($t, 30)));
    }
}

if (!function_exists('inbound_media_capture')) {
    /**
     * Downloads (via $fetch, a closure returning the inbound_media_fetch_* shape) and stores one
     * media item. Failures are logged at 'error' and return ok=false. Never throws.
     * @param array $item from inbound_media_extract_* (kind, id, mime, size)
     * @param array $opts root, max_bytes, now, random_hex
     * @return array{ok:bool, error:string, media:?array, source_url:string}
     */
    function inbound_media_capture($platform, $user_id, array $item, callable $fetch, array $opts)
    {
        $max = (int) $opts['max_bytes'];
        try {
            if (!empty($item['size']) && (int) $item['size'] > $max) {
                $res = array('ok' => false, 'error' => 'file larger than ' . $max . ' bytes', 'media' => null, 'source_url' => '');
            } else {
                $f = $fetch();
                if (empty($f['ok'])) {
                    $res = array('ok' => false, 'error' => (string) $f['error'], 'media' => null, 'source_url' => '');
                } else {
                    $declared = (string) $f['mime'] !== '' ? $f['mime'] : (string) ($item['mime'] ?? '');
                    $s = inbound_media_store_bytes($user_id, $f['bytes'], $declared, $opts['root'], $max,
                        $opts['now'] ?? null, $opts['random_hex'] ?? null);
                    $res = array('ok' => $s['ok'], 'error' => $s['error'], 'media' => $s['media'], 'source_url' => $s['ok'] ? $f['source_url'] : '');
                }
            }
        } catch (Throwable $e) {
            $res = array('ok' => false, 'error' => 'exception: ' . $e->getMessage(), 'media' => null, 'source_url' => '');
        }
        if (!$res['ok']) {
            log_message('error', 'inbound_media: ' . $platform . ' user ' . (int) $user_id . ' ' . ($item['kind'] ?? '?')
                . ' not stored: ' . substr($res['error'], 0, 300));
        }
        return $res;
    }
}

// ------------------------------------------------------------------ CodeIgniter glue (webhooks)

if (!function_exists('inbound_media_ready')) {
    /** FALSE until migration 2026-10-01-inbound-media.sql has run: the webhooks then behave as before. */
    function inbound_media_ready()
    {
        static $ready = null;
        if ($ready === null) {
            $ci = &get_instance();
            $ready = (bool) $ci->db->field_exists('media_json', 'livechat_messages');
        }
        return $ready;
    }
}

if (!function_exists('inbound_media_seen')) {
    /** TRUE when this provider message was already logged (webhook retry): the caller skips it. */
    function inbound_media_seen($platform, $user_id, $page_table_id, $subscriber_id, $ext_id)
    {
        if ((string) $ext_id === '') return false;
        $ci = &get_instance();
        return $ci->db->select('id')->from('livechat_messages')
            ->where('fb_message_id', (string) $ext_id)
            ->where('user_id', (int) $user_id)
            ->where('platform', (string) $platform)
            ->where('page_table_id', (int) $page_table_id)
            ->where('subscriber_id', (string) $subscriber_id)
            ->where('sender', 'user')
            ->limit(1)->get()->num_rows() > 0;
    }
}

if (!function_exists('inbound_media_opts')) {
    function inbound_media_opts()
    {
        $ci = &get_instance();
        list($max, $timeout) = inbound_media_limits($ci->config->item('inbound_media_max_bytes'), $ci->config->item('inbound_media_timeout'));
        return array('root' => FCPATH, 'max_bytes' => $max, 'timeout' => $timeout);
    }
}

if (!function_exists('inbound_media_capture_wa_cloud')) {
    function inbound_media_capture_wa_cloud($user_id, array $item, $token)
    {
        $o = inbound_media_opts();
        $graph = graph_url_group('reads', ''); // same version group as wa_media_url()
        return inbound_media_capture('wa', $user_id, $item, function () use ($graph, $item, $token, $o) {
            return inbound_media_fetch_wa_cloud($graph, $item['id'], $token, 'inbound_media_curl_get', $o['max_bytes'], $o['timeout']);
        }, $o);
    }
}

if (!function_exists('inbound_media_capture_telegram')) {
    function inbound_media_capture_telegram($user_id, array $item, $token)
    {
        $o = inbound_media_opts();
        return inbound_media_capture('tg', $user_id, $item, function () use ($item, $token, $o) {
            return inbound_media_fetch_telegram('https://api.telegram.org', $token, $item['id'], 'inbound_media_curl_get', $o['max_bytes'], $o['timeout']);
        }, $o);
    }
}
