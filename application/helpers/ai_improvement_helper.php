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
