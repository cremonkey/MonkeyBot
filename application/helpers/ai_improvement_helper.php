<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | AI Improvement Center (Phase 10 / brief 20): group what the bot could not answer so the
 | owner fixes a gap once, not question by question. Arabic-aware normalisation + token
 | Jaccard similarity; deterministic (no model call), so grouping is free and repeatable.
 */

if (!function_exists('aii_normalize')) {
    function aii_normalize($text)
    {
        $t = mb_strtolower(trim((string) $text), 'UTF-8');
        $t = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $t);          // harakat, tatweel
        $t = strtr($t, array('أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي'));
        $t = strtr($t, array('٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'));
        $t = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $t);
        $t = preg_replace('/(\p{L})\1{2,}/u', '$1', $t);                                // "ازاااي" -> "ازي"
        return trim(preg_replace('/\s+/u', ' ', $t));
    }
}

if (!function_exists('aii_tokens')) {
    function aii_tokens($norm)
    {
        static $stop = array('ال', 'في', 'من', 'على', 'عن', 'الى', 'الي', 'و', 'يا', 'هو', 'هي', 'انا', 'ده', 'دي', 'ايه', 'هل', 'لو',
            'the', 'a', 'an', 'is', 'are', 'to', 'of', 'for', 'and', 'or', 'in', 'on', 'do', 'you', 'i', 'it', 'what', 'how');
        $out = array();
        foreach (explode(' ', $norm) as $w) {
            if ($w === '' || in_array($w, $stop, true)) continue;
            if (mb_strlen($w) > 3 && mb_substr($w, 0, 2) === 'ال') $w = mb_substr($w, 2);   // strip Arabic article
            $out[$w] = true;
        }
        return $out;
    }
}

if (!function_exists('aii_group')) {
    /**
     * @param array $rows each with id, question, created_at, social_media (+ anything)
     * @return array groups sorted by count desc: key, sample, count, ids[], channels[], first_at, last_at
     */
    function aii_group(array $rows, $threshold = 0.6)
    {
        $groups = array();
        foreach ($rows as $r) {
            $norm = aii_normalize($r['question'] ?? '');
            if ($norm === '') continue;
            $tok = aii_tokens($norm);
            $best = null; $bestScore = 0.0;
            foreach ($groups as $gi => $g) {
                if ($g['key'] === $norm) { $best = $gi; $bestScore = 1.0; break; }
                $inter = count(array_intersect_key($tok, $g['tokens']));
                $union = count($tok + $g['tokens']);
                $score = $union ? $inter / $union : 0;
                if ($score > $bestScore) { $bestScore = $score; $best = $gi; }
            }
            if ($best !== null && $bestScore >= $threshold) {
                $g = &$groups[$best];
                $g['count']++; $g['ids'][] = (int) $r['id'];
                $g['channels'][$r['social_media'] ?? '?'] = true;
                if ($r['created_at'] < $g['first_at']) $g['first_at'] = $r['created_at'];
                if ($r['created_at'] > $g['last_at']) { $g['last_at'] = $r['created_at']; }
                unset($g);
            } else {
                $groups[] = array('key' => $norm, 'tokens' => $tok, 'sample' => (string) $r['question'], 'count' => 1,
                    'ids' => array((int) $r['id']), 'channels' => array(($r['social_media'] ?? '?') => true),
                    'first_at' => $r['created_at'], 'last_at' => $r['created_at']);
            }
        }
        foreach ($groups as &$g) { $g['channels'] = array_keys($g['channels']); unset($g['tokens']); }
        unset($g);
        usort($groups, function ($a, $b) { return ($b['count'] <=> $a['count']) ?: strcmp($b['last_at'], $a['last_at']); });
        return $groups;
    }
}

if (!function_exists('aii_similar')) {
    function aii_similar($a, $b, $threshold = 0.6)
    {
        $na = aii_normalize($a); $nb = aii_normalize($b);
        if ($na === '' || $nb === '') return false;
        if ($na === $nb) return true;
        $ta = aii_tokens($na); $tb = aii_tokens($nb);
        $u = count($ta + $tb);
        return $u > 0 && count(array_intersect_key($ta, $tb)) / $u >= $threshold;
    }
}

/*
 | Remaining-work Task 20 (C5, brief §20): four more signal cards for the AI Improvement
 | Center — tool failures, repeated escalations, human corrections, low-confidence guard
 | verdicts. Aggregation/grouping logic lives here so it is testable without a database
 | (tests/ai_improvement/run.php); the controller only runs the guarded queries and hands
 | the rows to these functions.
 */

if (!function_exists('aii_is_ai_agent_name')) {
    /**
     * livechat_messages.sender has no 'agent' value: an AI reply and a human agent's reply
     * are both stored with sender='bot'; the human one is told apart by agent_name being
     * the agent's session username instead of the literal "bot"/"Bot" the AI pipeline
     * writes (Home.php:7933, :4853, :5476). Empty/null agent_name is treated as AI too
     * (older rows predate agent_name being stamped).
     */
    function aii_is_ai_agent_name($agent_name)
    {
        $n = trim((string) $agent_name);
        return $n === '' || strtolower($n) === 'bot';
    }
}

if (!function_exists('aii_group_tool_failures')) {
    /**
     * @param array $rows each with tool_name, error_class, error_message, created_at
     * @return array sorted by count desc: tool_name, error_class, sample, count, last_at
     */
    function aii_group_tool_failures(array $rows)
    {
        $groups = array();
        foreach ($rows as $r) {
            $tool = (string) ($r['tool_name'] ?? '');
            $class = (string) ($r['error_class'] ?? '');
            $key = $tool . "\0" . $class;
            if (!isset($groups[$key])) {
                $groups[$key] = array('tool_name' => $tool, 'error_class' => $class,
                    'sample' => (string) ($r['error_message'] ?? ''), 'count' => 0, 'last_at' => (string) ($r['created_at'] ?? ''));
            }
            $groups[$key]['count']++;
            if ((string) ($r['created_at'] ?? '') > $groups[$key]['last_at']) $groups[$key]['last_at'] = $r['created_at'];
        }
        $out = array_values($groups);
        usort($out, function ($a, $b) { return ($b['count'] <=> $a['count']) ?: strcmp($b['last_at'], $a['last_at']); });
        return $out;
    }
}

if (!function_exists('aii_escalation_rows')) {
    /**
     * Turns raw state-transition events (conversation_events.to_state = WAITING_FOR_HUMAN)
     * into aii_group()-ready rows, one row per escalation (not deduped per conversation), so
     * the token-similarity grouping counts total escalations per topic, not per conversation.
     * Conversations that escalated only once are dropped: a repeated escalation needs >= 2
     * on the SAME conversation.
     *
     * @param array $events   each with id, conversation_id, created_at (already filtered to
     *                        this tenant + to_state=WAITING_FOR_HUMAN by the caller's query)
     * @param array $first_by_conversation  conversation_id => array(question, social_media)
     *                                      (the first customer message of that conversation)
     * @param int   $min_count minimum escalations on one conversation to count as "repeated"
     * @return array rows shaped for aii_group(): id, question, social_media, created_at
     */
    function aii_escalation_rows(array $events, array $first_by_conversation, $min_count = 2)
    {
        $per_conv = array();
        foreach ($events as $e) {
            $cid = (int) ($e['conversation_id'] ?? 0);
            if ($cid <= 0) continue;
            $per_conv[$cid][] = $e;
        }
        $rows = array();
        foreach ($per_conv as $cid => $evts) {
            if (count($evts) < $min_count) continue;
            $first = $first_by_conversation[$cid] ?? null;
            if ($first === null || trim((string) ($first['question'] ?? '')) === '') continue;
            foreach ($evts as $e) {
                $rows[] = array(
                    'id'           => $cid,
                    'question'     => $first['question'],
                    'social_media' => $first['social_media'] ?? '?',
                    'created_at'   => (string) ($e['created_at'] ?? ''),
                );
            }
        }
        return $rows;
    }
}

if (!function_exists('aii_find_corrections')) {
    /**
     * A "human correction": a human agent's reply (sender='bot', agent_name != bot) sent
     * within $window_minutes of an AI reply (sender='bot', agent_name = bot/empty) in the
     * SAME conversation, with the human reply strictly after the AI one. Each AI reply is
     * matched to at most one (the nearest) human reply, so a long back-and-forth is not
     * over-counted.
     *
     * @param array $rows each with id, conversation_id, agent_name, message_content,
     *                    conversation_time — sender='bot' rows only, any order
     * @param int   $window_minutes
     * @return array correction records sorted by human_at desc: conversation_id, ai_id,
     *               ai_message, ai_at, human_id, human_agent, human_message, human_at, minutes
     */
    function aii_find_corrections(array $rows, $window_minutes = 10)
    {
        $per_conv = array();
        foreach ($rows as $r) {
            $cid = (int) ($r['conversation_id'] ?? 0);
            if ($cid <= 0) continue;
            $per_conv[$cid][] = $r;
        }
        $out = array();
        foreach ($per_conv as $cid => $msgs) {
            usort($msgs, function ($a, $b) { return strcmp((string) $a['conversation_time'], (string) $b['conversation_time']) ?: (($a['id'] ?? 0) <=> ($b['id'] ?? 0)); });
            $pending_ai = null;
            foreach ($msgs as $m) {
                if (aii_is_ai_agent_name($m['agent_name'] ?? '')) {
                    $pending_ai = $m; // latest AI reply waiting for a possible correction
                    continue;
                }
                // human agent reply
                if ($pending_ai === null) continue;
                $mins = (strtotime((string) $m['conversation_time']) - strtotime((string) $pending_ai['conversation_time'])) / 60;
                if ($mins >= 0 && $mins <= $window_minutes) {
                    $out[] = array(
                        'conversation_id' => $cid,
                        'ai_id'           => (int) $pending_ai['id'],
                        'ai_message'      => (string) $pending_ai['message_content'],
                        'ai_at'           => (string) $pending_ai['conversation_time'],
                        'human_id'        => (int) $m['id'],
                        'human_agent'     => (string) ($m['agent_name'] ?? ''),
                        'human_message'   => (string) $m['message_content'],
                        'human_at'        => (string) $m['conversation_time'],
                        'minutes'         => round($mins, 1),
                    );
                    $pending_ai = null; // this AI reply is now accounted for
                }
            }
        }
        usort($out, function ($a, $b) { return strcmp($b['human_at'], $a['human_at']); });
        return $out;
    }
}
