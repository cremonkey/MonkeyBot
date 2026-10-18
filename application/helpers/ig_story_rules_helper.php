<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Instagram story-reply rules. Pure functions + one DB-reading entry point.
 * Precedence: active rule for this exact story > first active keyword rule > NULL
 * (NULL = fall through to the old generic story-private-reply bot, unchanged).
 * A story-specific rule with keywords only fires when a keyword matches too.
 */

if (!function_exists('igsr_pick_rule')) {
    function igsr_pick_rule(array $rules, $story_id, $text)
    {
        if (!function_exists('igcg_exact_match')) require_once __DIR__ . '/ig_comment_guard_helper.php';
        $story_id = (string) $story_id;
        $kw_hit = function ($r) use ($text) {
            $kws = array_filter(array_map('trim', explode(',', (string) $r['keywords'])), 'strlen');
            foreach ($kws as $k) if (igcg_exact_match($text, $k)) return true;
            return false;
        };
        $has_kw = function ($r) { return trim((string) $r['keywords']) !== ''; };

        foreach ($rules as $r) {                         // 1. this exact story
            if ((int) $r['is_active'] !== 1 || (string) $r['story_id'] === '' || (string) $r['story_id'] !== $story_id) continue;
            if (!$has_kw($r) || $kw_hit($r)) return $r;
        }
        foreach ($rules as $r) {                         // 2. any story, by keyword
            if ((int) $r['is_active'] !== 1 || (string) $r['story_id'] !== '' || !$has_kw($r)) continue;
            if ($kw_hit($r)) return $r;
        }
        return null;
    }
}

if (!function_exists('igsr_message')) {
    /** Send API body for a rule: plain text, or text + one link button. */
    function igsr_message(array $rule, $igsid)
    {
        $text = mb_substr((string) $rule['reply_text'], 0, 640, 'UTF-8');
        $url = trim((string) $rule['button_url']);
        $title = trim((string) $rule['button_title']);
        if ($url !== '' && $title !== '' && preg_match('#^https://#i', $url)) {
            $message = array('attachment' => array('type' => 'template', 'payload' => array(
                'template_type' => 'button', 'text' => $text,
                'buttons' => array(array('type' => 'web_url', 'url' => $url, 'title' => mb_substr($title, 0, 20, 'UTF-8'))),
            )));
        } else {
            $message = array('text' => $text);
        }
        return json_encode(array('recipient' => array('id' => (string) $igsid), 'messaging_type' => 'RESPONSE', 'message' => $message), JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('igsr_validate')) {
    /** @return string error message, '' when valid */
    function igsr_validate(array $in)
    {
        if (trim((string) $in['reply_text']) === '') return 'Reply text is required';
        if (trim((string) $in['story_id']) === '' && trim((string) $in['keywords']) === '') return 'Pick a story or enter at least one keyword';
        $u = trim((string) $in['button_url']); $t = trim((string) $in['button_title']);
        if (($u === '') !== ($t === '')) return 'Button needs both a title and a link';
        if ($u !== '' && !preg_match('#^https://#i', $u)) return 'Button link must start with https://';
        return '';
    }
}

if (!function_exists('igsr_handle')) {
    /**
     * Called from the Messenger webhook for a story reply. Records the story, picks a rule,
     * returns the Send API body to send (or NULL to fall through). Never throws.
     */
    function igsr_handle($db, $page_table_id, $story_id, $story_url, $text, $igsid)
    {
        $prev = isset($db->db_debug) ? $db->db_debug : null;
        if ($prev !== null) $db->db_debug = false;
        try {
            $db->query('INSERT INTO ig_story_seen (page_table_id, story_id, story_url, last_text) VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE replies = replies + 1, last_seen = NOW(), story_url = VALUES(story_url), last_text = VALUES(last_text)',
                array((int) $page_table_id, (string) $story_id, (string) $story_url, mb_substr((string) $text, 0, 500, 'UTF-8')));
            $q = $db->query('SELECT * FROM ig_story_rules WHERE page_table_id = ? AND is_active = 1 ORDER BY id ASC', array((int) $page_table_id));
            if (!$q) return null;
            $rule = igsr_pick_rule($q->result_array(), $story_id, $text);
            if (!$rule) return null;
            $db->query('UPDATE ig_story_rules SET sent_count = sent_count + 1, last_sent_at = NOW() WHERE id = ?', array((int) $rule['id']));
            return igsr_message($rule, $igsid);
        } catch (\Throwable $e) {
            return null;
        } finally {
            if ($prev !== null) $db->db_debug = $prev;
        }
    }
}
