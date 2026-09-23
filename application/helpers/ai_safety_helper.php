<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | AI safety follow-ups (baseline item 17, S-04): the price guard's fail-closed mode.
 |
 | The SPEC-21 judge (ai_verify_price_grounding) returns 'unknown' whenever it cannot
 | decide: no key for the tenant's provider, HTTP error, timeout, empty
 | source, an unreadable verdict. Until now 'unknown' meant "send the reply unchanged",
 | so a broken judge let an invented price reach the customer.
 |
 | With config item `price_guard_fail_closed` = TRUE, an unverifiable reply is only sent
 | when every price figure in it is literally written in the authoritative facts the
 | judge would have seen (prompt layers, FAQ, knowledge base, calculate_price results in
 | $CI->ai_price_facts). Otherwise the reply is replaced by a hand-off message and the
 | conversation is flagged for a human. Unset or FALSE (the default) keeps today's
 | behaviour byte for byte and only logs "price_guard: would block ..." at 'error' level.
 |
 | Why not block every 'unknown': the judge fails open on any outage (either provider,
 | a missing key, a timeout). Blocking them all would silence every price answer that is
 | correct and written in the owner's own source. (Until 2026-09-23 the judge always used
 | OpenAI, so every Anthropic tenant got 'unknown'; it now follows the tenant's provider.)
 */

if (!function_exists('ai_price_guard_fail_closed_enabled')) {
    /** TRUE only when the flag is explicitly on; unset / FALSE / '0' / '' mean off. */
    function ai_price_guard_fail_closed_enabled()
    {
        $CI =& get_instance();
        $v = (isset($CI->config) && is_object($CI->config)) ? $CI->config->item('price_guard_fail_closed') : null;
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v === 1;
        if (is_string($v)) return in_array(strtolower(trim($v)), array('1', 'true', 'yes', 'on'), true);
        return false;
    }
}

if (!function_exists('ai_safety_ascii_digits')) {
    /** Arabic-Indic and Extended Arabic-Indic digits to ASCII; Arabic separators to ASCII. */
    function ai_safety_ascii_digits($text)
    {
        return strtr((string) $text, array(
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٬' => ',', '٫' => '.',
        ));
    }
}

if (!function_exists('ai_safety_number_key')) {
    /** "11,500" / "11500.00" / "011500" -> "11500"; "4502.5" -> "4502.5". */
    function ai_safety_number_key($raw)
    {
        $n = str_replace(',', '', (string) $raw);
        if (strpos($n, '.') !== false) $n = rtrim(rtrim($n, '0'), '.');
        $n = ltrim($n, '0');
        return $n === '' ? '0' : $n;
    }
}

if (!function_exists('ai_price_figures')) {
    /**
     * The price figures a reply states, normalised (see ai_safety_number_key). A figure is:
     * a thousands-grouped number (5,500), a number next to a currency word or sign on either
     * side (900 جنيه, EGP 350, $40), or a number after "بـ" ("الداي يوز بـ350"). Bare small
     * numbers ("2 nights", "24 hours") are not prices and are ignored.
     *
     * @return string[] unique figures, in order of appearance
     */
    function ai_price_figures($text)
    {
        $t = ai_safety_ascii_digits($text);
        if ($t === '') return array();
        $num = '(\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?)';
        $cur_after  = '(?:جنيه\p{Arabic}*|ج\.?\s?م|EGP|L\.?E\b|pounds?\b|USD|\$|دولار\p{Arabic}*|ريال\p{Arabic}*|SAR|درهم\p{Arabic}*|AED|€|EUR|£)';
        $cur_before = '(?:EGP|L\.?E|USD|US\$|\$|SAR|AED|€|EUR|£|جنيه)';
        $found = array();
        $patterns = array(
            '/(?<![\d.,])(\d{1,3}(?:,\d{3})+(?:\.\d+)?)(?![\d,])/u',
            '/' . $num . '\s*' . $cur_after . '/iu',
            '/' . $cur_before . '\s*' . $num . '/iu',
            '/بـ\s*' . $num . '/u',
        );
        foreach ($patterns as $p) {
            if (preg_match_all($p, $t, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[1] as $hit) {
                    $key = ai_safety_number_key($hit[0]);
                    if ($key === '0') continue;
                    if (!isset($found[$key]) || $hit[1] < $found[$key]) $found[$key] = $hit[1];
                }
            }
        }
        asort($found);
        return array_map('strval', array_keys($found));
    }
}

if (!function_exists('ai_price_fact_numbers')) {
    /** Every number written in the authoritative facts, normalised, as a lookup set. */
    function ai_price_fact_numbers($facts)
    {
        $t = ai_safety_ascii_digits(is_array($facts) ? implode("\n", $facts) : (string) $facts);
        $set = array();
        if (preg_match_all('/\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?/u', $t, $m)) {
            foreach ($m[0] as $n) $set[ai_safety_number_key($n)] = true;
        }
        return $set;
    }
}

if (!function_exists('ai_price_unverified_figures')) {
    /**
     * Price figures in $reply that are not written anywhere in $facts.
     *
     * @param string          $reply
     * @param string|string[] $facts guard source text, and/or $CI->ai_price_facts lines
     * @return string[]
     */
    function ai_price_unverified_figures($reply, $facts)
    {
        $have = ai_price_fact_numbers($facts);
        $out = array();
        foreach (ai_price_figures($reply) as $f) if (!isset($have[$f])) $out[] = $f;
        return $out;
    }
}

if (!function_exists('ai_price_guard_action')) {
    /**
     * What to do with a priced reply after the judge ran.
     *
     *  'ungrounded'                -> 'deflect'  (the existing SPEC-21 block, unchanged)
     *  'grounded'                  -> 'send'
     *  'unknown' / 'error' / other -> 'send' when every figure is written in $facts,
     *                                 else 'block' (flag on) or 'would_block' (flag off)
     *
     * @return array{action:string, unverified:string[]}
     */
    function ai_price_guard_action($verdict, $reply, $facts, $fail_closed)
    {
        if ($verdict === 'ungrounded') return array('action' => 'deflect', 'unverified' => array());
        if ($verdict === 'grounded') return array('action' => 'send', 'unverified' => array());
        $unverified = ai_price_unverified_figures($reply, $facts);
        if (empty($unverified)) return array('action' => 'send', 'unverified' => array());
        return array('action' => $fail_closed ? 'block' : 'would_block', 'unverified' => $unverified);
    }
}

if (!function_exists('ai_price_guard_handoff_text')) {
    /** The reply sent instead of an unverifiable price. Mirrors the customer's script. */
    function ai_price_guard_handoff_text($human = '')
    {
        $is_arabic = (bool) preg_match('/\p{Arabic}/u', (string) $human);
        return $is_arabic
            ? 'ثواني بس، هخلّي حد من الفريق يأكدلك السعر بالظبط ويرد عليك هنا في أقرب وقت 🙏'
            : 'One moment, please. A member of our team will confirm the exact price and reply to you here shortly.';
    }
}

if (!function_exists('ai_price_guard_flag_handoff')) {
    /**
     * Flag the thread for a human after a fail-closed block.
     *
     * Primary: the conversations handoff state machine (conversation_transition to
     * WAITING_FOR_HUMAN by actor 'ai', audited in conversation_events, mirrored to
     * messenger_bot_subscriber.bot_paused_until). A RESOLVED thread is reopened first
     * (RESOLVED -> AI_ACTIVE is an allowed edge), a thread already with a human is left
     * as it is. Fallback when no conversation row exists: the handoff_to_human tool
     * (Ai_tools), which pauses the bot, opens an urgent CRM follow-up and alerts the owner.
     *
     * Never throws; a failure is returned and logged, the customer still gets the hand-off text.
     *
     * @return array{ok:bool, via:string, conversation_id:int, error:string}
     */
    function ai_price_guard_flag_handoff($user_id, $platform, $page_id, $subscribe_id, $note = '')
    {
        $res = array('ok' => false, 'via' => '', 'conversation_id' => 0, 'error' => '');
        $user_id = (int) $user_id;
        $platform = (string) $platform;
        $subscribe_id = (string) $subscribe_id;
        if ($user_id <= 0 || $subscribe_id === '') { $res['error'] = 'no thread'; return $res; }
        $note = $note !== '' ? $note : 'price_guard: unverifiable price, team to confirm';
        try {
            $CI =& get_instance();
            if ($CI->db->table_exists('conversations')) {
                $CI->load->helper('conversation');
                $q = $CI->db->query(
                    'SELECT id, channel_account_id, handoff_state FROM conversations
                      WHERE user_id = ? AND platform = ? AND external_thread_id = ? ORDER BY updated_at DESC, id DESC',
                    array($user_id, $platform, $subscribe_id)
                );
                $rows = $q ? $q->result_array() : array();
                if (!empty($rows)) {
                    // Several channel accounts can share an external id: prefer this page's.
                    $want = array();
                    if (is_numeric($page_id)) $want[] = (int) $page_id;
                    if (in_array($platform, array('fb', 'ig'), true) && (string) $page_id !== '') {
                        $want[] = (int) conversation_resolve_account($user_id, $platform, 0, (string) $page_id);
                    }
                    $conv = $rows[0];
                    foreach ($rows as $r) if (in_array((int) $r['channel_account_id'], $want, true)) { $conv = $r; break; }

                    $res['via'] = 'conversation';
                    $res['conversation_id'] = (int) $conv['id'];
                    $state = (string) $conv['handoff_state'];
                    if (in_array($state, array('WAITING_FOR_HUMAN', 'HUMAN_ACTIVE', 'AI_ASSIST'), true)) {
                        $res['ok'] = true;   // a human already owns (or is queued for) the thread
                        return $res;
                    }
                    if ($state === 'RESOLVED') {
                        $r1 = conversation_transition($conv['id'], 'AI_ACTIVE', 'ai', null, $note, $user_id);
                        if (empty($r1['ok'])) { $res['error'] = (string) $r1['error']; return $res; }
                    }
                    $t = conversation_transition($conv['id'], 'WAITING_FOR_HUMAN', 'ai', null, $note, $user_id);
                    $res['ok'] = !empty($t['ok']);
                    $res['error'] = (string) ($t['error'] ?? '');
                    return $res;
                }
            }

            // Fallback: the existing handoff_to_human tool.
            $sub = $CI->db->select('id')->from('messenger_bot_subscriber')
                ->where('subscribe_id', $subscribe_id)->where('user_id', $user_id)->limit(1)->get()->row_array();
            $CI->load->library('Ai_tools');
            $CI->ai_tools->execute('handoff_to_human', array('reason' => $note), array(
                'user_id' => $user_id, 'page_id' => $page_id, 'subscribe_id' => $subscribe_id,
                'social_media' => $platform, 'subscriber_row_id' => isset($sub['id']) ? (int) $sub['id'] : 0,
            ));
            $res['ok'] = true;
            $res['via'] = 'handoff_tool';
            return $res;
        } catch (Throwable $e) {
            log_message('error', 'price_guard: handoff failed: ' . $e->getMessage());
            $res['error'] = $e->getMessage();
            return $res;
        }
    }
}
