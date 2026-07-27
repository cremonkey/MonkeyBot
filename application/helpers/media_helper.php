<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * SPEC-31 — photo albums + per-item services for the get_room_media AI tool.
 *
 * The model never authors a URL. It names an item, this resolves the name
 * deterministically, and PHP hands back the album link stored in
 * pricing_config.config_json (media block). Anything the model still emits on
 * its own is stripped by the outgoing gate in Home.php.
 *
 * Resolution never guesses: an unknown name and an AMBIGUOUS name both return
 * null, so the bot asks which room instead of sending the wrong album. This is
 * the same failure class as SPEC-21's cross-mapped prices, with the mistake
 * visible to the customer.
 *
 * Media block shape (inside pricing_config.config_json):
 * "media": { "items": { "<canonical name>": {
 *      "kind":"room"|"day_use"|"day_use_unit", "album":"https://…",
 *      "services":"…", "aliases":["…"] } } }
 * For rooms the aliases live on accommodation.rooms[].aliases (single source —
 * pricing_calc_accommodation resolves names through the same list).
 */

if (!function_exists('media_normalize')) {
    /** Arabic-tolerant normalisation: tashkeel, hamza forms, ة/ه, ى/ي, punctuation, case. */
    function media_normalize($s)
    {
        $s = trim((string) $s);
        if ($s === '') return '';
        $s = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $s);
        $s = str_replace(
            array('أ', 'إ', 'آ', 'ٱ', 'ة', 'ى', 'ؤ', 'ئ', 'ـ'),
            array('ا', 'ا', 'ا', 'ا', 'ه', 'ي', 'و', 'ي', ''),
            $s
        );
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim(mb_strtolower($s, 'UTF-8'));
    }
}

if (!function_exists('media_index')) {
    /**
     * Flatten the config into: canonical => array(name, kind, album, services, aliases[]).
     * Rooms come from accommodation.rooms (so they exist even with no album yet);
     * non-room entries come from the media block alone.
     */
    function media_index($cfg)
    {
        $out = array();
        if (!is_array($cfg)) return $out;
        $media = isset($cfg['media']['items']) && is_array($cfg['media']['items']) ? $cfg['media']['items'] : array();

        $rooms = isset($cfg['accommodation']['rooms']) && is_array($cfg['accommodation']['rooms'])
            ? $cfg['accommodation']['rooms'] : array();
        foreach ($rooms as $r) {
            if (empty($r['name'])) continue;
            $n = $r['name'];
            $m = isset($media[$n]) && is_array($media[$n]) ? $media[$n] : array();
            $out[$n] = array(
                'name'     => $n,
                'kind'     => 'room',
                'album'    => isset($m['album']) ? trim($m['album']) : '',
                'services' => isset($m['services']) ? trim($m['services']) : '',
                'aliases'  => array_merge(
                    isset($r['aliases']) && is_array($r['aliases']) ? $r['aliases'] : array(),
                    isset($m['aliases']) && is_array($m['aliases']) ? $m['aliases'] : array()
                ),
            );
        }
        foreach ($media as $n => $m) {
            if (isset($out[$n]) || !is_array($m)) continue;
            $out[$n] = array(
                'name'     => $n,
                'kind'     => isset($m['kind']) ? $m['kind'] : 'other',
                'album'    => isset($m['album']) ? trim($m['album']) : '',
                'services' => isset($m['services']) ? trim($m['services']) : '',
                'aliases'  => isset($m['aliases']) && is_array($m['aliases']) ? $m['aliases'] : array(),
            );
        }
        return $out;
    }
}

if (!function_exists('media_find')) {
    /**
     * Resolve a customer/model-supplied name to exactly one item.
     * Tiers: exact canonical -> exact alias -> substring either way.
     * Returns null when nothing matches AND when more than one item matches at
     * the winning tier (e.g. a bare "سويت" that fits two suites).
     */
    function media_find($cfg, $name)
    {
        $q = media_normalize($name);
        if ($q === '') return null;
        $items = media_index($cfg);
        if (empty($items)) return null;

        $exact     = array();
        $alias     = array();
        $part      = array();
        $contained = array();
        foreach ($items as $it) {
            $cn = media_normalize($it['name']);
            if ($cn === $q) { $exact[] = $it; continue; }
            $hit_alias = false;
            foreach ($it['aliases'] as $a) {
                if (media_normalize($a) === $q) { $hit_alias = true; break; }
            }
            if ($hit_alias) { $alias[] = $it; continue; }

            // Two different partial matches, ranked apart on purpose:
            //  (a) the customer said MORE than a known name  ("الدبل بول فيو" ⊃ "دبل بول فيو")
            //      — specific, and the LONGEST such name wins, so "بول فيو" cannot
            //        drag the Junior Suite into a question about the balcony room.
            //  (b) the customer said LESS than a known name  ("بول فيو" ⊂ two rooms)
            //      — genuinely ambiguous whenever it fits more than one item.
            $best = 0;
            $names = array_merge(array($it['name']), $it['aliases']);
            foreach ($names as $cand) {
                $c = media_normalize($cand);
                if ($c === '' || mb_strlen($c) < 3) continue;
                if (mb_strpos($q, $c) !== false) { $best = max($best, mb_strlen($c)); }
                elseif (mb_strpos($c, $q) !== false && $best === 0) { $best = -1; }
            }
            if ($best > 0)       $contained[] = array('item' => $it, 'len' => $best);
            elseif ($best === -1) $part[] = $it;
        }

        if (count($exact) === 1) return $exact[0];
        if (count($exact) > 1) return null;
        if (count($alias) === 1) return $alias[0];
        if (count($alias) > 1) return null;

        if (!empty($contained)) {
            usort($contained, function ($a, $b) { return $b['len'] - $a['len']; });
            if (count($contained) === 1 || $contained[0]['len'] > $contained[1]['len']) {
                return $contained[0]['item'];
            }
            return null;   // two equally specific names matched: ask
        }
        if (count($part) === 1) return $part[0];
        return null;       // nothing, or ambiguous: ask, never guess
    }
}

if (!function_exists('media_all_urls')) {
    /** Whitelist for the outgoing link gate. */
    function media_all_urls($cfg)
    {
        $urls = array();
        foreach (media_index($cfg) as $it) {
            if (!empty($it['album'])) $urls[] = $it['album'];
        }
        return array_values(array_unique($urls));
    }
}

if (!function_exists('media_extract_urls')) {
    /** Every http(s) URL in a reply, with trailing punctuation trimmed. */
    function media_extract_urls($text)
    {
        if (!preg_match_all('#https?://[^\s<>"\'\)\]]+#iu', (string) $text, $m)) return array();
        $out = array();
        foreach ($m[0] as $u) {
            $out[] = rtrim($u, ".,;:!؟?،");
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('media_unwrap_markdown_links')) {
    /**
     * Messenger, Instagram and WhatsApp render NOTHING — a markdown link reaches the
     * customer as the literal "[هنا](https://…)". The model writes them anyway
     * (measured live), and a rule against it is the kind of abstract instruction
     * gpt-4o-mini ignores, so unwrap deterministically instead.
     * "[label](url)" -> "label: url", and a label that adds nothing -> just the url.
     */
    function media_unwrap_markdown_links($text)
    {
        // Bold/italic markers are the same problem as the links: the model keeps
        // emitting them despite the profile's ❌ examples, and every channel we send
        // to shows them literally. Strip the markers, keep the words.
        $text = preg_replace('/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/us', '$2', (string) $text);

        if (strpos($text, '](') === false) return $text;
        return preg_replace_callback(
            '/\[([^\]\n]{0,80})\]\((https?:\/\/[^\s\)]+)\)/u',
            function ($m) {
                $label = trim($m[1]);
                $bare  = array('هنا', 'من هنا', 'اضغط هنا', 'here', 'link', 'الرابط', 'اللينك', '');
                if (in_array(mb_strtolower($label, 'UTF-8'), $bare, true)) return $m[2];
                return $label . ': ' . $m[2];
            },
            $text
        );
    }
}

if (!function_exists('media_link_deflection_text')) {
    /** Replacement reply when the model emitted a link we did not give it. */
    function media_link_deflection_text()
    {
        return 'أبعتلك صور المكان حالاً 😊 تحب أشوفلك أنهي غرفة بالظبط؟';
    }
}

if (!function_exists('media_url_allowed')) {
    /** Allowed = exactly a configured album (ignoring a trailing slash / case of the host). */
    function media_url_allowed($url, $allowed)
    {
        $norm = function ($u) {
            $u = trim((string) $u);
            $u = preg_replace('#^https?://#i', '', $u);
            $u = rtrim($u, '/');
            return mb_strtolower($u, 'UTF-8');
        };
        $n = $norm($url);
        foreach ($allowed as $a) {
            if ($n === $norm($a)) return true;
        }
        return false;
    }
}
