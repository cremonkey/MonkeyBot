<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Instagram comment auto-reply guards.
 *
 *  igcg_claim()        atomic "first delivery wins" per (campaign, comment). Replaces the
 *                      read-then-insert dedupe that let two close Meta deliveries both send.
 *  igcg_exact_match()  word-run keyword match used by trigger_matching_type = 'exact'.
 *                      Fixes the old splitter: keywords are trimmed and lower-cased, any
 *                      keyword length works (was max 3 words), Arabic punctuation/diacritics,
 *                      alef/ya/ta-marbuta variants and tatweel are normalised, and a keyword
 *                      never matches inside a longer word.
 */

if (!function_exists('igcg_claim')) {
    /**
     * @param object $db  CI database (or anything with query()/affected_rows()/db_debug)
     * @return bool TRUE when this request owns the comment and may reply.
     *              Fails OPEN (TRUE) on any DB error, e.g. before the migration has run,
     *              so the old report-table dedupe keeps protecting as before.
     */
    function igcg_claim($db, $autoreply_table_id, $comment_id)
    {
        $comment_id = (string) $comment_id;
        if ($comment_id === '' || $comment_id === '0') return true;

        $prev = isset($db->db_debug) ? $db->db_debug : null;
        if ($prev !== null) $db->db_debug = false;
        try {
            $ok = $db->query(
                'INSERT IGNORE INTO ig_comment_claims (autoreply_table_id, comment_id) VALUES (?, ?)',
                array((int) $autoreply_table_id, $comment_id)
            );
            if ($ok === false) {
                if (function_exists('log_message')) log_message('error', 'igcg_claim: insert failed, failing open');
                return true;
            }
            return (int) $db->affected_rows() === 1;
        } catch (\Throwable $e) {
            if (function_exists('log_message')) log_message('error', 'igcg_claim: ' . $e->getMessage());
            return true;
        } finally {
            if ($prev !== null) $db->db_debug = $prev;
        }
    }
}

if (!function_exists('igcg_normalize')) {
    function igcg_normalize($text)
    {
        $text = (string) $text;
        if (class_exists('Normalizer')) {
            $n = \Normalizer::normalize($text, \Normalizer::FORM_KC);
            if ($n !== false) $text = $n;
        }
        $text = mb_strtolower($text, 'UTF-8');
        // Arabic: diacritics + tatweel out, letter variants folded.
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, array('أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي'));
        return $text;
    }
}

if (!function_exists('igcg_words')) {
    /** Split into words: letters, marks, digits, underscore. @mentions dropped. */
    function igcg_words($text)
    {
        $text = preg_replace('/@[\p{L}\p{N}_.]+/u', ' ', igcg_normalize($text));
        $parts = preg_split('/[^\p{L}\p{M}\p{N}_]+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY);
        return $parts === false ? array() : $parts;
    }
}

if (!function_exists('igcg_exact_match')) {
    function igcg_exact_match($text, $keyword)
    {
        $kw = igcg_words($keyword);
        if (empty($kw)) return false;
        $words = igcg_words($text);
        $n = count($kw);
        for ($i = 0, $last = count($words) - $n; $i <= $last; $i++) {
            if (array_slice($words, $i, $n) === $kw) return true;
        }
        return false;
    }
}

if (!function_exists('igcg_is_dry_run')) {
    /** TRUE when the campaign is in test mode. Any DB error (e.g. column missing) => FALSE (live, as before). */
    function igcg_is_dry_run($db, $autoreply_table_id)
    {
        $prev = isset($db->db_debug) ? $db->db_debug : null;
        if ($prev !== null) $db->db_debug = false;
        try {
            $q = $db->query('SELECT dry_run FROM instagram_reply_autoreply WHERE id = ? LIMIT 1', array((int) $autoreply_table_id));
            if (!$q) return false;
            $row = $q->row_array();
            return !empty($row) && (int) $row['dry_run'] === 1;
        } catch (\Throwable $e) {
            return false;
        } finally {
            if ($prev !== null) $db->db_debug = $prev;
        }
    }
}

if (!defined('IGCG_TEST_MODE_TAG')) define('IGCG_TEST_MODE_TAG', '[TEST MODE] ');

if (!class_exists('Igcg_dry_run_fb')) {
    /**
     * Stands in for $this->fb_rx_login while a campaign is in test mode: every call that would
     * change something on Instagram returns a fake success, reads pass through untouched.
     */
    class Igcg_dry_run_fb
    {
        const WRITES = array('instagram_auto_comment', 'instagram_hide_comment', 'instagram_delete_comment',
                             'send_private_reply', 'get_private_reply_message_id_info');
        public $inner; public $skipped = array();
        public function __construct($inner) { $this->inner = $inner; }
        public function __call($name, $args)
        {
            if (in_array($name, self::WRITES, true)) {
                $this->skipped[] = $name;
                return array('id' => '', 'dry_run' => true);
            }
            return call_user_func_array(array($this->inner, $name), $args);
        }
        public function __get($k) { return $this->inner->$k; }
        public function __set($k, $v) { $this->inner->$k = $v; }
    }
}

if (!function_exists('igcg_campaign_controls')) {
    /**
     * Test-mode + follow-gate controls for one campaign.
     * $style 'circle' = icon buttons (full-account / mention dropdowns), 'dropdown' = menu items (post list).
     */
    function igcg_campaign_controls($table_id, $db, $lang, $style = 'circle')
    {
        $id = (int) $table_id;
        $dry = igcg_is_dry_run($db, $id);
        $gate = false;
        if (function_exists('igfg_settings')) $gate = igfg_settings($db, $id) !== null;
        $h = function ($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); };

        $t_dry = $dry ? $lang->line('Test mode is ON: nothing is sent. Click to go live') : $lang->line('Turn on test mode: match and log, send nothing');
        $t_gate = $gate ? $lang->line('Follow gate is ON: reply is sent only to followers. Click to edit') : $lang->line('Follow gate: send the reply only after the user follows');

        if ($style === 'dropdown') {
            return "<a class='pointer dropdown-item has-icon igcg_test_mode_button orange' table_id='" . $id . "' to_do='" . ($dry ? 'off' : 'on') . "'><i class='fas fa-flask'></i> " . $h($dry ? $lang->line('Test mode: ON') : $lang->line('Test mode')) . "</a>"
                 . "<a class='pointer dropdown-item has-icon igfg_gate_button purple' table_id='" . $id . "'><i class='fas fa-user-lock'></i> " . $h($gate ? $lang->line('Follow gate: ON') : $lang->line('Follow gate')) . "</a>";
        }
        return '<a title="' . $h($t_dry) . '" data-toggle="tooltip" data-placement="top" href="#" table_id="' . $id . '" to_do="' . ($dry ? 'off' : 'on') . '" class="btn btn-circle ' . ($dry ? 'btn-warning' : 'btn-outline-warning') . ' igcg_test_mode_button"><i class="fas fa-flask"></i></a>'
             . '<a title="' . $h($t_gate) . '" data-toggle="tooltip" data-placement="top" href="#" table_id="' . $id . '" class="btn btn-circle ' . ($gate ? 'btn-info' : 'btn-outline-info') . ' igfg_gate_button"><i class="fas fa-user-lock"></i></a>';
    }
}
