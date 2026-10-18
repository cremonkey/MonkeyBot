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
