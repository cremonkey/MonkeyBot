<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * SPEC-24 — deterministic price calculation for the calculate_price AI tool.
 *
 * All arithmetic lives here, never in the model: the price guard cannot verify a total
 * (measured 4/8) and the source numbers collide across items, so a computed figure must
 * be produced in code and handed to the model (and to the guard) as authoritative.
 *
 * Config shape (pricing_config.config_json), one per tenant PER SCOPE (see the per-page
 * section below: a page's own list, else the account-wide default list). Every section is
 * optional; a list may hold only a product catalog (see pricing_calc_item):
 * {
 *   "currency": "جنيه",
 *   "accommodation": {
 *     "rooms": [
 *       {"name":"Double Garden View","capacity":2,"nightly":4500,"offers":{"4":11500}},
 *       ...
 *     ],
 *     "note":"سعر الغرفة كاملة، مش للفرد. الإقامة شاملة استخدام حمام السباحة."
 *   },
 *   "day_use": {
 *     "packages":[{"name":"الباقة الأولى","per_person":900},{"name":"الباقة الثانية","per_person":1350}],
 *     "nth_free":{"every":5,"pay":4}      // buy 4 tickets, 5th free
 *   },
 *   "children": {"free_max_age":5,"free_cap":2,"half_min_age":6,"half_max_age":11,"full_min_age":12,"half_price_known":false},
 *   "catalog": {"items":[{"name":"قص شعر","price":150,"unit":"session","variants":[{"name":"أطفال","price":100}]}]}
 * }
 */

/*
 * ---------------------------------------------------------------------------
 * Per-page price lists (2026-09-29-pricing-per-page.sql).
 * A list belongs to a SCOPE = (channel_type, target_id). ('', '') is the account-wide
 * DEFAULT list — every row that existed before per-page lists is a default list, so a
 * tenant that never assigns a list to a page behaves exactly as before.
 * Resolution at reply time: the page's own ACTIVE list -> the default ACTIVE list -> none.
 * ---------------------------------------------------------------------------
 */
if (!function_exists('pricing_scope_channels')) {
    /**
     * Channel types a list can be scoped to, and the table each target lives in.
     * openwa and wa_cloud are deliberately distinct: both tables use small numeric ids,
     * so a single 'wa' namespace (ai_agent_assignments' flaw) would hand openwa #5 the
     * price list of cloud number #5.
     */
    function pricing_scope_channels()
    {
        return array('fb', 'openwa', 'wa_cloud', 'tg', 'web');
    }
}

if (!function_exists('pricing_normalize_scope')) {
    /**
     * Accepts null, "ch:target", array(ch, target) or array('channel_type'=>…, 'target_id'=>…).
     * Anything unknown or incomplete is the default scope array('', '').
     */
    function pricing_normalize_scope($scope)
    {
        $ch = ''; $t = '';
        if (is_string($scope) && strpos($scope, ':') !== false) {
            list($ch, $t) = explode(':', $scope, 2);
        } elseif (is_array($scope)) {
            if (array_key_exists('channel_type', $scope)) { $ch = $scope['channel_type']; $t = $scope['target_id'] ?? ''; }
            elseif (isset($scope[0])) { $ch = $scope[0]; $t = $scope[1] ?? ''; }
        }
        $ch = (string) $ch; $t = trim((string) $t);
        if (!in_array($ch, pricing_scope_channels(), true) || $t === '' || strlen($t) > 64) return array('', '');
        return array($ch, $t);
    }
}

if (!function_exists('pricing_scope_key')) {
    /** "fb:12" style key for forms/URLs; '' for the default list. */
    function pricing_scope_key($scope)
    {
        list($ch, $t) = pricing_normalize_scope($scope);
        return $ch === '' ? '' : $ch . ':' . $t;
    }
}

if (!function_exists('pricing_has_scope_columns')) {
    /** Before the migration runs the scope columns do not exist; code must still work. */
    function pricing_has_scope_columns()
    {
        static $yes = false;   // once present they never go away; a "no" is re-checked
        if ($yes) return true;
        $ci = &get_instance();
        if (!$ci->db->table_exists('pricing_config')) return false;
        $yes = (bool) $ci->db->field_exists('channel_type', 'pricing_config');
        return $yes;
    }
}

if (!function_exists('pricing_resolve_scope')) {
    /**
     * Map what a bot passes into get_ai_reply_open_ai($user_id, $social_media, $page_id)
     * to the price-list scope. Mirrors ai_resolve_profile()'s normalisation, but every
     * lookup is restricted to this tenant's own rows, and WhatsApp is split by account type:
     *   fb / ig  page_id = FB page id string (Messenger_bot, Comment_automation,
     *            Instagram_reply) or facebook_rx_fb_page_info.id  -> ('fb', page row id)
     *   wa       Openwa_bot passes openwa_accounts.session_id     -> ('openwa', account id)
     *            Whatsapp_bot passes whatsapp_accounts.id         -> ('wa_cloud', account id)
     *            both match (a numeric session name that equals a cloud id) -> default:
     *            never risk quoting another number's list.
     *   tg       telegram_accounts.id                             -> ('tg', id)
     *   web      webchat_settings.widget_key ('webchat'/'' = the first widget) -> ('web', key)
     * Anything unknown / not owned by this tenant -> the default scope array('', '').
     */
    function pricing_resolve_scope($user_id, $social_media, $page_id)
    {
        $default = array('', '');
        try {
            $user_id = (int) $user_id;
            $page_id = trim((string) $page_id);
            if ($user_id <= 0) return $default;
            $ci = &get_instance();
            $db = $ci->db;
            $one = function ($table, $field, $value, $select = 'id') use ($db, $user_id) {
                if (!$db->table_exists($table)) return null;
                $r = $db->select($select)->from($table)->where('user_id', $user_id)->where($field, $value)->limit(1)->get()->row_array();
                return empty($r) ? null : $r;
            };
            $sm = (string) $social_media;

            if ($sm === 'fb' || $sm === 'ig') {
                if ($page_id === '') return $default;
                $r = $one('facebook_rx_fb_page_info', 'page_id', $page_id);
                if ($r === null && ctype_digit($page_id)) $r = $one('facebook_rx_fb_page_info', 'id', $page_id);
                if ($r === null && $sm === 'ig') $r = $one('facebook_rx_fb_page_info', 'instagram_business_account_id', $page_id);
                return $r === null ? $default : array('fb', (string) $r['id']);
            }
            if ($sm === 'wa' || $sm === 'openwa' || $sm === 'wa_cloud') {
                if ($page_id === '') return $default;
                $ow = ($sm !== 'wa_cloud') ? $one('openwa_accounts', 'session_id', $page_id) : null;
                if ($ow === null && $sm === 'openwa' && ctype_digit($page_id)) $ow = $one('openwa_accounts', 'id', $page_id);
                $wc = ($sm !== 'openwa' && ctype_digit($page_id)) ? $one('whatsapp_accounts', 'id', $page_id) : null;
                if ($ow !== null && $wc !== null) return $default;   // ambiguous: never guess
                if ($ow !== null) return array('openwa', (string) $ow['id']);
                if ($wc !== null) return array('wa_cloud', (string) $wc['id']);
                return $default;
            }
            if ($sm === 'tg') {
                if (!ctype_digit($page_id)) return $default;
                $r = $one('telegram_accounts', 'id', $page_id);
                return $r === null ? $default : array('tg', (string) $r['id']);
            }
            if ($sm === 'web') {
                if (!$db->table_exists('webchat_settings')) return $default;
                if ($page_id !== '' && $page_id !== 'webchat') {
                    $r = $one('webchat_settings', 'widget_key', $page_id, 'widget_key');
                } else {
                    $r = $db->select('widget_key')->from('webchat_settings')->where('user_id', $user_id)
                        ->order_by('id', 'ASC')->limit(1)->get()->row_array();
                }
                return empty($r['widget_key']) ? $default : array('web', (string) $r['widget_key']);
            }
            return $default;
        } catch (Exception $e) {
            log_message('error', 'pricing_resolve_scope: ' . $e->getMessage());
            return $default;
        }
    }
}

if (!function_exists('pricing_get_row')) {
    /** The row for exactly this scope (no fallback), or null. */
    function pricing_get_row($user_id, $scope = null, $active_only = false)
    {
        $ci = &get_instance();
        if (!$ci->db->table_exists('pricing_config')) return null;
        list($ch, $t) = pricing_normalize_scope($scope);
        if (!pricing_has_scope_columns()) {
            if ($ch !== '') return null;   // pre-migration: only the default list exists
            $ci->db->from('pricing_config')->where('user_id', (int) $user_id);
        } else {
            $ci->db->from('pricing_config')->where('user_id', (int) $user_id)->where('channel_type', $ch)->where('target_id', $t);
        }
        if ($active_only) $ci->db->where('status', '1');
        $row = $ci->db->limit(1)->get()->row_array();
        return empty($row) ? null : $row;
    }
}

if (!function_exists('pricing_get_config')) {
    /**
     * The price list the AI uses.
     *   pricing_get_config($uid)          -> the account-wide default list (the legacy call)
     *   pricing_get_config($uid, $scope)  -> that page's own active list, else the default
     *                                        active list, else null
     */
    function pricing_get_config($user_id, $scope = null)
    {
        list($ch, $t) = pricing_normalize_scope($scope);
        $row = null;
        if ($ch !== '') $row = pricing_get_row($user_id, array($ch, $t), true);
        if (empty($row) || empty($row['config_json'])) $row = pricing_get_row($user_id, null, true);
        if (empty($row) || empty($row['config_json'])) return null;
        $cfg = json_decode($row['config_json'], true);
        return is_array($cfg) ? $cfg : null;
    }
}

if (!function_exists('pricing_config_sections')) {
    /** Which priced sections a config actually has content in. */
    function pricing_config_sections($cfg)
    {
        $has = function ($v) { return is_array($v) && count($v) > 0; };
        return array(
            'accommodation' => is_array($cfg) && $has($cfg['accommodation']['rooms'] ?? null),
            'day_use'       => is_array($cfg) && $has($cfg['day_use']['packages'] ?? null),
            'catalog'       => is_array($cfg) && $has($cfg['catalog']['items'] ?? null),
            'packages'      => is_array($cfg) && $has($cfg['packages'] ?? null),
        );
    }
}

if (!function_exists('pricing_prompt_rule')) {
    /**
     * System-prompt rule 10 for a resolved config ('' when there is no config).
     * A config without a product catalog gets the original stays/day-use rule byte for
     * byte, so every existing tenant's prompt is unchanged. With a catalog the rule names
     * only the sections this page's list really has.
     */
    function pricing_prompt_rule($cfg)
    {
        if (empty($cfg) || !is_array($cfg)) return '';
        $tail = " all mean CALL THE TOOL now with what you have - do not ask more questions first if you already have a count, and never answer such a question with 'بيبدأ من' when the customer gave you a specific number. Quote the tool's total exactly. If it returns NO FINAL TOTAL, tell the customer the team will confirm and ask for their WhatsApp - do not invent a total.";
        $s = pricing_config_sections($cfg);
        if (!$s['catalog']) {
            return "\n10. NEVER do price arithmetic yourself. Call the calculate_price tool whenever the customer gives a COUNT you can price: a number of nights, OR a number of people/adults, OR a headcount for day-use. 'داي يوز لـ5 أفراد', 'اقامة ليلتين', '4 كبار وطفلين'" . $tail;
        }
        $what = array(); $ex = array();
        if ($s['accommodation']) { $what[] = 'a number of nights for a stay'; $ex[] = "'اقامة ليلتين'"; $ex[] = "'4 كبار وطفلين'"; }
        if ($s['day_use'])       { $what[] = 'a headcount for day-use'; $ex[] = "'داي يوز لـ5 أفراد'"; }
        $what[] = 'a quantity of a product or service from the price list (type item: the item in the customer\'s words, the option/size if they said one, and the quantity)';
        $ex[] = "'عايز 3 من ده'"; $ex[] = "'جلستين'"; $ex[] = "'بكام الواحد'";
        return "\n10. NEVER do price arithmetic yourself. Call the calculate_price tool whenever the customer asks a price or gives a COUNT you can price: " . implode(', OR ', $what) . '. ' . implode(', ', $ex) . $tail
            . " If the tool says the name matches several items, ask the customer which one they mean - never pick one yourself.";
    }
}

if (!function_exists('pricing_tool_definition')) {
    /**
     * The calculate_price tool for a resolved config. No config, or a config without a
     * product catalog, gets the original definition unchanged (see pricing_prompt_rule).
     */
    function pricing_tool_definition($cfg = null)
    {
        $legacy = array(
            'name' => 'calculate_price',
            'description' => "Compute an EXACT price total for accommodation or day-use. ALWAYS call this instead of doing the math yourself whenever the customer gives enough to price a stay: for accommodation pass nights + how many adults and children (by age band); for day_use pass the package and how many adults and children. It applies the real offers (multi-night discount, buy-N-get-one-free), picks a room that fits the headcount, and defers to the team for anything it cannot price. Quote its total verbatim.",
            'params' => array(
                'type' => 'object',
                'properties' => array(
                    'type' => array('type' => 'string', 'enum' => array('accommodation', 'day_use'), 'description' => 'accommodation = overnight stay; day_use = day visit without staying.'),
                    'room_or_package' => array('type' => 'string', 'description' => "For accommodation: the room name if the customer named one (else leave empty and it picks the fitting room). For day_use: the package name if named."),
                    'nights' => array('type' => 'integer', 'description' => 'Accommodation only: number of nights.'),
                    'adults' => array('type' => 'integer', 'description' => 'Number of adults (12+ counts as adult).'),
                    'children_under_5' => array('type' => 'integer', 'description' => 'Children up to 5 years (usually free).'),
                    'children_6_to_11' => array('type' => 'integer', 'description' => 'Children from 6 to under 12.'),
                    'children_12_plus' => array('type' => 'integer', 'description' => 'Children 12 or older (billed as full adults).'),
                ),
                'required' => array('type'),
            ),
        );
        $s = pricing_config_sections($cfg);
        if (!$s['catalog'] && !$s['packages']) return $legacy;

        $enum = array(); $desc = array(); $props = array();
        if ($s['accommodation']) { $enum[] = 'accommodation'; $desc[] = 'accommodation = overnight stay'; }
        if ($s['day_use'])       { $enum[] = 'day_use';       $desc[] = 'day_use = day visit without staying'; }
        if ($s['catalog'])       { $enum[] = 'item';          $desc[] = 'item = a product or service from the price list'; }
        if ($s['packages'])      { $enum[] = 'package';       $desc[] = 'package = a fixed-price bundle from the price list'; }
        $props['type'] = array('type' => 'string', 'enum' => $enum, 'description' => implode('; ', $desc) . '.');
        $props['item'] = array('type' => 'string', 'description' => "type item/package: the product/service/package the customer asked about, in their own words (Arabic or English)." );
        $props['variant'] = array('type' => 'string', 'description' => 'type item: the option/size/variant if the customer named one, else leave empty.');
        $props['quantity'] = array('type' => 'number', 'description' => 'type item/package: how many (pieces, hours, sessions, kg...). Default 1.');
        $props['coupon_code'] = array('type' => 'string', 'description' => 'A discount coupon code — ONLY if the customer typed one themselves. Never invent or suggest a code.');
        $how = array();
        if ($s['catalog'])  $how[] = 'for item pass the item name + variant (if named) + quantity';
        if ($s['packages']) $how[] = 'for package pass the package name + quantity';
        if ($s['accommodation'] || $s['day_use']) {
            foreach ($legacy['params']['properties'] as $k => $v) if ($k !== 'type') $props[$k] = $v;
            if ($s['accommodation']) {
                $how[] = 'for accommodation pass nights + how many adults and children (by age band)';
                $props['checkin_date'] = array('type' => 'string', 'description' => 'Accommodation only: check-in date (YYYY-MM-DD) if the customer gave one — needed for seasonal/weekend pricing.');
            }
            if ($s['day_use'])       $how[] = 'for day_use pass the package and how many adults and children';
        }
        $how = implode('; ', $how);
        return array(
            'name' => 'calculate_price',
            'description' => "Compute an EXACT price from this page's price list. ALWAYS call this instead of stating or multiplying a price yourself: " . $how . ". It resolves the item name (Arabic nicknames too), applies the real prices" . ($s['accommodation'] || $s['day_use'] ? ' and offers' : '') . ", and defers to the team for anything it cannot price (price on request, unclear item). If the name matches several items it tells you to ask which one. Quote its total verbatim.",
            'params' => array('type' => 'object', 'properties' => $props, 'required' => array('type')),
        );
    }
}

/*
 * ---------------------------------------------------------------------------
 * Generic product / service catalog (any business, not only hotels).
 * "catalog": {"items": [{"name":"قص شعر","category":"Hair","price":150,"unit":"session",
 *     "variants":[{"name":"أطفال","price":100}], "price_on_request":false,
 *     "aliases":["haircut"], "note":"…"}]}
 * unit: item|piece|hour|session|person|night|day|month|kg|custom (custom needs unit_label).
 * ---------------------------------------------------------------------------
 */
if (!function_exists('pricing_catalog_units')) {
    /** unit => Arabic word used in the quote the customer sees ('' = no word). */
    function pricing_catalog_units()
    {
        return array('item' => '', 'piece' => 'قطعة', 'hour' => 'ساعة', 'session' => 'جلسة', 'person' => 'فرد',
            'night' => 'ليلة', 'day' => 'يوم', 'month' => 'شهر', 'kg' => 'كجم', 'custom' => '');
    }
}

if (!function_exists('pricing_money')) {
    /** Exact money value: whole numbers stay int, otherwise 2 decimals. */
    function pricing_money($v)
    {
        $r = round((float) $v, 2);
        return (floor($r) == $r) ? (int) $r : $r;
    }
}

if (!function_exists('pricing_fmt')) {
    function pricing_fmt($v)
    {
        $v = pricing_money($v);
        return is_int($v) ? number_format($v) : number_format($v, 2);
    }
}

if (!function_exists('pricing_catalog_index')) {
    /** Catalog items in the shape media_match() resolves over: name => item (+aliases). */
    function pricing_catalog_index($cfg)
    {
        $out = array();
        $items = isset($cfg['catalog']['items']) && is_array($cfg['catalog']['items']) ? $cfg['catalog']['items'] : array();
        foreach ($items as $it) {
            if (!is_array($it) || trim((string) ($it['name'] ?? '')) === '') continue;
            $it['name'] = trim((string) $it['name']);
            $it['aliases'] = isset($it['aliases']) && is_array($it['aliases']) ? array_values(array_filter($it['aliases'], 'is_string')) : array();
            $out[$it['name']] = $it;
        }
        return $out;
    }
}

/*
 * ---------------------------------------------------------------------------
 * C11 (task 26) — pricing extensions: seasons, weekday/weekend rates, minimum
 * stay, packages, quantity discounts, coupons. Every new field is OPTIONAL —
 * a config that does not set them produces byte-identical output to before
 * this change. New calculator params are appended AFTER the original ones
 * with safe defaults, so every existing call site is untouched.
 *
 * accommodation.rooms[].min_nights   int >= 1, reject shorter stays (no price).
 * accommodation.rooms[].weekend_nightly  nightly rate for a "weekend" night.
 * accommodation.weekend_days         list of ISO weekdays (1=Mon..7=Sun) that
 *                                     count as weekend; default [5,6] (Fri,Sat).
 *   Weekday/weekend only applies when a check-in date is given (it needs real
 *   dates to know which nights are weekend), and only when a fixed offers[n]
 *   price is not already used for this exact night count.
 * accommodation.seasons[] = {name, start:'Y-m-d', end:'Y-m-d',
 *   rooms:{room_name:{nightly?, weekend_nightly?, offers?}}}
 *   The season whose [start,end] contains the check-in date overrides the
 *   matching room's nightly/weekend_nightly/offers for that stay.
 * packages[] = {name, price, quantity_discounts?}  — a fixed-price bundle,
 *   priced like a catalog item (see pricing_calc_package).
 * catalog.items[].quantity_discounts[] = {min_qty, price} — best (highest
 *   min_qty <= quantity) tier replaces the unit price.
 * coupons[] = {code, type:'percent'|'fixed', value, start?, end?} — applied
 *   ONLY when the caller passes an explicit $coupon_code (never guessed from
 *   the config), and only within [start,end] when those are set.
 * ---------------------------------------------------------------------------
 */
if (!function_exists('pricing_weekend_days')) {
    /** ISO weekday numbers (1=Mon..7=Sun) counted as "weekend". Default Fri+Sat. */
    function pricing_weekend_days($cfg)
    {
        $acc = isset($cfg['accommodation']) && is_array($cfg['accommodation']) ? $cfg['accommodation'] : array();
        $d = isset($acc['weekend_days']) && is_array($acc['weekend_days']) ? $acc['weekend_days'] : array(5, 6);
        $out = array();
        foreach ($d as $n) { $n = (int) $n; if ($n >= 1 && $n <= 7) $out[] = $n; }
        return empty($out) ? array(5, 6) : array_values(array_unique($out));
    }
}

if (!function_exists('pricing_find_season')) {
    /** The season row (if any) whose [start,end] contains $date ('Y-m-d'), else null. */
    function pricing_find_season($cfg, $date)
    {
        if (empty($date) || !is_string($date)) return null;
        $ts = strtotime($date);
        if ($ts === false) return null;
        $seasons = isset($cfg['accommodation']['seasons']) && is_array($cfg['accommodation']['seasons']) ? $cfg['accommodation']['seasons'] : array();
        foreach ($seasons as $s) {
            if (!is_array($s) || empty($s['start']) || empty($s['end'])) continue;
            $st = strtotime((string) $s['start']); $en = strtotime((string) $s['end']);
            if ($st === false || $en === false) continue;
            if ($ts >= $st && $ts <= $en) return $s;
        }
        return null;
    }
}

if (!function_exists('pricing_room_with_season')) {
    /** $room with any season override for this room name merged in (season wins). */
    function pricing_room_with_season($room, $season)
    {
        if (empty($season) || !is_array($season) || empty($room['name'])) return $room;
        $rooms = isset($season['rooms']) && is_array($season['rooms']) ? $season['rooms'] : array();
        $ov = isset($rooms[$room['name']]) && is_array($rooms[$room['name']]) ? $rooms[$room['name']] : null;
        if ($ov === null) return $room;
        if (isset($ov['nightly'])) $room['nightly'] = $ov['nightly'];
        if (isset($ov['weekend_nightly'])) $room['weekend_nightly'] = $ov['weekend_nightly'];
        if (isset($ov['offers']) && is_array($ov['offers'])) $room['offers'] = $ov['offers'];
        $room['_season'] = (string) ($season['name'] ?? '');
        return $room;
    }
}

if (!function_exists('pricing_weekday_weekend_total')) {
    /**
     * Sum of per-night rates from $checkin_date over $nights nights, using
     * $room['weekend_nightly'] on weekend nights (per pricing_weekend_days) and
     * $room['nightly'] otherwise. Returns null when weekend_nightly is not set
     * (nothing to differ from the flat rate) or the date is invalid.
     */
    function pricing_weekday_weekend_total($cfg, $room, $checkin_date, $nights)
    {
        if (empty($room['weekend_nightly']) || empty($checkin_date)) return null;
        $ts = strtotime((string) $checkin_date);
        if ($ts === false) return null;
        $weekend_days = pricing_weekend_days($cfg);
        $total = 0; $weekend_nights = 0;
        for ($i = 0; $i < $nights; $i++) {
            $dow = (int) date('N', strtotime("+$i day", $ts));
            if (in_array($dow, $weekend_days, true)) { $total += (float) $room['weekend_nightly']; $weekend_nights++; }
            else $total += (float) $room['nightly'];
        }
        return array((int) round($total), $weekend_nights);
    }
}

if (!function_exists('pricing_find_coupon')) {
    /** The coupon row for $code (case-insensitive) if it exists and is within its validity window today, else null. */
    function pricing_find_coupon($cfg, $code)
    {
        $code = trim((string) $code);
        if ($code === '') return null;
        $coupons = isset($cfg['coupons']) && is_array($cfg['coupons']) ? $cfg['coupons'] : array();
        $today = date('Y-m-d');
        foreach ($coupons as $c) {
            if (!is_array($c) || trim((string) ($c['code'] ?? '')) === '') continue;
            if (mb_strtolower(trim((string) $c['code'])) !== mb_strtolower($code)) continue;
            if (!empty($c['start']) && $today < substr((string) $c['start'], 0, 10)) return null;
            if (!empty($c['end']) && $today > substr((string) $c['end'], 0, 10)) return null;
            return $c;
        }
        return null;
    }
}

if (!function_exists('pricing_apply_coupon')) {
    /**
     * Applies $coupon_code (explicit, never inferred) to $total.
     * @return array [new_total, fact_line_or_null] — fact_line is an itemised string for
     *               ai_price_facts, or null when there is nothing to apply/report.
     */
    function pricing_apply_coupon($cfg, $total, $coupon_code)
    {
        $coupon_code = trim((string) $coupon_code);
        if ($coupon_code === '' || $total === null) return array($total, null);
        $c = pricing_find_coupon($cfg, $coupon_code);
        if ($c === null) return array($total, 'كود الخصم "' . $coupon_code . '" مش صالح أو منتهي');
        $type = ($c['type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $value = (float) ($c['value'] ?? 0);
        $discount = $type === 'percent' ? ($total * $value / 100) : $value;
        $discount = min($discount, $total);
        $new_total = pricing_money($total - $discount);
        $label = $type === 'percent' ? ($value . '%') : (pricing_fmt($discount) . ' ' . (isset($cfg['currency']) ? $cfg['currency'] : 'جنيه'));
        $fact = 'كوبون ' . trim((string) $c['code']) . ' (' . $label . ' خصم): -' . pricing_fmt($discount) . ' -> الإجمالي ' . pricing_fmt($new_total);
        return array($new_total, $fact);
    }
}

if (!function_exists('pricing_quantity_tier_price')) {
    /** The unit price from $tiers (list of {min_qty,price}) that applies to $qty, or null. */
    function pricing_quantity_tier_price($tiers, $qty)
    {
        if (!is_array($tiers) || empty($tiers)) return null;
        $best = null;
        foreach ($tiers as $t) {
            if (!is_array($t) || !isset($t['min_qty'], $t['price'])) continue;
            $min = (float) $t['min_qty'];
            if ($qty >= $min && ($best === null || $min > $best['min_qty'])) $best = array('min_qty' => $min, 'price' => (float) $t['price']);
        }
        return $best;
    }
}

if (!function_exists('pricing_calc_package')) {
    /**
     * Fixed-price bundle from cfg.packages: {name, price, quantity_discounts?}.
     * Same contract as pricing_calc_item (unknown -> ok=false; otherwise a total,
     * with an optional quantity-discount tier and/or coupon applied).
     */
    function pricing_calc_package($cfg, $name, $quantity = 1, $coupon_code = null)
    {
        $cur = isset($cfg['currency']) && $cfg['currency'] !== '' ? $cfg['currency'] : 'جنيه';
        $packages = isset($cfg['packages']) && is_array($cfg['packages']) ? $cfg['packages'] : array();
        if (empty($packages)) return array('ok' => false, 'reason' => 'no_packages', 'error' => 'no packages configured');
        $name = trim((string) $name);
        if ($name === '') return array('ok' => false, 'reason' => 'no_item', 'error' => 'no package given');
        $q = is_numeric($quantity) ? (float) $quantity : 1.0;
        if ($q <= 0) $q = 1.0;
        $qty = (floor($q) == $q) ? (int) $q : $q;

        $found = null;
        foreach ($packages as $p) {
            if (is_array($p) && isset($p['name']) && mb_stripos((string) $p['name'], $name) !== false) { $found = $p; break; }
        }
        if ($found === null) return array('ok' => false, 'reason' => 'unknown', 'error' => 'unknown package', 'item' => $name);
        if (!isset($found['price']) || !is_numeric($found['price']) || (float) $found['price'] <= 0) {
            return array('ok' => true, 'kind' => 'package', 'item' => $found['name'], 'quantity' => $qty, 'currency' => $cur,
                'reason' => 'price_on_request', 'unit_price' => null, 'total' => null, 'needs_team' => true, 'notes' => array(), 'facts' => array());
        }
        $price = (float) $found['price'];
        $facts = array();
        $tier = pricing_quantity_tier_price($found['quantity_discounts'] ?? null, $q);
        if ($tier !== null) {
            $price = $tier['price'];
            $facts[] = 'خصم كمية (' . $tier['min_qty'] . '+): سعر الوحدة ' . pricing_fmt($price) . ' ' . $cur;
        }
        $total = pricing_money(round($price * 100) * $q / 100);
        list($total, $cfact) = pricing_apply_coupon($cfg, $total, $coupon_code);
        if ($cfact !== null) $facts[] = $cfact;
        return array('ok' => true, 'kind' => 'package', 'item' => $found['name'], 'quantity' => $qty, 'currency' => $cur,
            'reason' => 'ok', 'unit_price' => pricing_money($price), 'total' => $total, 'needs_team' => false,
            'notes' => array(), 'facts' => $facts);
    }
}

if (!function_exists('pricing_calc_item')) {
    /**
     * Exact, deterministic price for a catalog item: unit price x quantity, where a named
     * variant's price replaces the item's base price. Never guesses:
     *   unknown name                   -> ok=false, reason 'unknown'
     *   ambiguous name / variant       -> ok=true, total=null, needs_team, candidates[]
     *   price_on_request               -> ok=true, total=null, needs_team
     * Names resolve through media_match() — the same alias-aware resolver rooms and
     * photo albums use.
     */
    function pricing_calc_item($cfg, $name, $variant = '', $quantity = 1, $coupon_code = null)
    {
        $cur = isset($cfg['currency']) && $cfg['currency'] !== '' ? $cfg['currency'] : 'جنيه';
        $index = pricing_catalog_index($cfg);
        if (empty($index)) return array('ok' => false, 'reason' => 'no_catalog', 'error' => 'no products configured');
        $name = trim((string) $name);
        if ($name === '') return array('ok' => false, 'reason' => 'no_item', 'error' => 'no item given');
        if (!function_exists('media_match')) {
            $ci = &get_instance();
            $ci->load->helper('media');
        }
        $q = is_numeric($quantity) ? (float) $quantity : 1.0;
        if ($q <= 0) $q = 1.0;
        if ($q > 100000) return array('ok' => false, 'reason' => 'quantity', 'error' => 'quantity too large');
        $q = round($q, 3);
        $qty = (floor($q) == $q) ? (int) $q : $q;

        $m = media_match($cfg, $name, $index);
        $base = array('ok' => true, 'kind' => 'item', 'quantity' => $qty, 'currency' => $cur, 'notes' => array(), 'candidates' => array(), 'facts' => array());
        if ($m['hit'] === null) {
            if (empty($m['candidates'])) return array('ok' => false, 'reason' => 'unknown', 'error' => 'unknown item', 'item' => $name);
            return array_merge($base, array('reason' => 'ambiguous', 'item' => $name, 'total' => null, 'needs_team' => true,
                'candidates' => array_values($m['candidates'])));
        }
        $it = $index[$m['hit']['name']];
        $units = pricing_catalog_units();
        $unit = isset($it['unit'], $units[$it['unit']]) ? $it['unit'] : 'item';
        $unit_word = $unit === 'custom' ? trim((string) ($it['unit_label'] ?? '')) : $units[$unit];
        $out = array_merge($base, array('item' => $it['name'], 'unit' => $unit, 'unit_word' => $unit_word, 'variant' => ''));
        if (!empty($it['note'])) $out['notes'][] = (string) $it['note'];

        if (!empty($it['price_on_request'])) {
            return array_merge($out, array('reason' => 'price_on_request', 'unit_price' => null, 'total' => null, 'needs_team' => true));
        }

        $price = isset($it['price']) && is_numeric($it['price']) && (float) $it['price'] > 0 ? (float) $it['price'] : null;
        $variants = array();
        foreach ((isset($it['variants']) && is_array($it['variants']) ? $it['variants'] : array()) as $v) {
            if (is_array($v) && trim((string) ($v['name'] ?? '')) !== '') {
                $v['name'] = trim((string) $v['name']);
                $v['aliases'] = isset($v['aliases']) && is_array($v['aliases']) ? $v['aliases'] : array();
                $variants[$v['name']] = $v;
            }
        }
        $variant = trim((string) $variant);
        if ($variant !== '' && !empty($variants)) {
            $vm = media_match(null, $variant, $variants);
            if ($vm['hit'] === null) {
                return array_merge($out, array('reason' => 'variant', 'unit_price' => null, 'total' => null, 'needs_team' => true,
                    'candidates' => array_keys($variants)));
            }
            $vv = $variants[$vm['hit']['name']];
            $out['variant'] = $vv['name'];
            if (!empty($vv['price_on_request'])) {
                return array_merge($out, array('reason' => 'price_on_request', 'unit_price' => null, 'total' => null, 'needs_team' => true));
            }
            $price = (float) $vv['price'];
        } elseif ($price === null && !empty($variants)) {
            // no base price and the customer did not say which option: ask, don't guess
            return array_merge($out, array('reason' => 'variant', 'unit_price' => null, 'total' => null, 'needs_team' => true,
                'candidates' => array_keys($variants)));
        } elseif ($variant !== '' && empty($variants)) {
            $out['notes'][] = 'مفيش اختيارات مسجلة للصنف ده - السعر الأساسي';
        }
        if ($price === null) {
            return array_merge($out, array('reason' => 'price_on_request', 'unit_price' => null, 'total' => null, 'needs_team' => true));
        }
        $facts = array();
        $tier = pricing_quantity_tier_price($it['quantity_discounts'] ?? null, $q);
        if ($tier !== null) {
            $price = $tier['price'];
            $facts[] = 'خصم كمية (' . $tier['min_qty'] . '+): سعر الوحدة ' . pricing_fmt($price) . ' ' . $cur;
        }
        // exact: work in integer piastres so 0.1 + 0.2 style float drift can never show up
        $total = round(round($price * 100) * $q) / 100;
        $total = pricing_money($total);
        list($total, $cfact) = pricing_apply_coupon($cfg, $total, $coupon_code);
        if ($cfact !== null) $facts[] = $cfact;
        return array_merge($out, array('reason' => 'ok', 'unit_price' => pricing_money($price),
            'total' => $total, 'needs_team' => false, 'facts' => $facts));
    }
}

if (!function_exists('pricing_headcount')) {
    /**
     * Resolve a headcount into billable "people" and flags. Children 12+ are full people,
     * children under the free age are free (capped), children in the half band are half a
     * person — but only if the config knows the half price; otherwise they can't be
     * totalled and we say so.
     * Returns array(people_float, notes[], needs_team_bool).
     */
    function pricing_headcount($cfg, $adults, $c_free, $c_half, $c_full)
    {
        $ch = isset($cfg['children']) ? $cfg['children'] : array();
        $free_cap = isset($ch['free_cap']) ? (int) $ch['free_cap'] : 2;
        $half_known = !empty($ch['half_price_known']);

        $people = (float) max(0, (int) $adults) + max(0, (int) $c_full); // adults + 12+
        $notes = array();
        $needs_team = false;

        $free = max(0, (int) $c_free);
        if ($free > 0) {
            $counted = min($free, $free_cap);
            $notes[] = $counted . ' طفل مجانًا' . ($free > $free_cap ? ' (الحد الأقصى ' . $free_cap . ' مجانًا؛ الزيادة تتأكد من الفريق)' : '');
            if ($free > $free_cap) $needs_team = true;
        }

        $half = max(0, (int) $c_half);
        if ($half > 0) {
            if ($half_known) {
                $people += $half * 0.5;
                $notes[] = $half . ' طفل بنص فرد';
            } else {
                // Cannot total: the half-person price is not set. Documented open item.
                $needs_team = true;
                $notes[] = $half . ' طفل من 6 لأقل من 12 (سعر نص الفرد يتأكد من الفريق)';
            }
        }
        return array($people, $notes, $needs_team);
    }
}

if (!function_exists('pricing_calc_day_use')) {
    function pricing_calc_day_use($cfg, $package_name, $adults, $c_free, $c_half, $c_full, $coupon_code = null)
    {
        $du = isset($cfg['day_use']) ? $cfg['day_use'] : array();
        $packages = isset($du['packages']) ? $du['packages'] : array();
        if (empty($packages)) return array('ok' => false, 'error' => 'no day-use packages configured');

        // pick the named package, else the cheapest
        $pkg = null;
        foreach ($packages as $p) {
            if ($package_name !== '' && mb_stripos($p['name'], $package_name) !== false) { $pkg = $p; break; }
        }
        if ($pkg === null) {
            usort($packages, function ($a, $b) { return $a['per_person'] - $b['per_person']; });
            $pkg = $packages[0];
        }

        list($people, $notes, $needs_team) = pricing_headcount($cfg, $adults, $c_free, $c_half, $c_full);
        if ($people <= 0) return array('ok' => false, 'error' => 'no paying guests');

        // nth-free offer (buy N, one free) applies to the full-person count only
        $billable_people = $people;
        $whole = (int) floor($people);
        $nf = isset($du['nth_free']) ? $du['nth_free'] : null;
        $offer_note = '';
        if ($nf && !empty($nf['every']) && !empty($nf['pay']) && $whole >= (int) $nf['every']) {
            $groups = intdiv($whole, (int) $nf['every']);
            $free_tickets = $groups; // one free per full group
            $billable_people = $people - $free_tickets;
            $offer_note = 'عرض: كل ' . (int) $nf['every'] . ' الـ' . (int) $nf['every'] . ' مجانًا (' . $free_tickets . ' تذكرة هدية)';
        }

        $total = (int) round($billable_people * (float) $pkg['per_person']);
        $facts = array();
        list($total, $cfact) = pricing_apply_coupon($cfg, $total, $coupon_code);
        if ($cfact !== null) $facts[] = $cfact;
        return array(
            'ok' => true, 'kind' => 'day_use', 'item' => $pkg['name'],
            'per_person' => (int) $pkg['per_person'], 'people' => $people,
            'total' => $total, 'needs_team' => $needs_team,
            'notes' => array_filter(array_merge($notes, array($offer_note))),
            'currency' => isset($cfg['currency']) ? $cfg['currency'] : 'جنيه',
            'facts' => $facts,
        );
    }
}

if (!function_exists('pricing_calc_accommodation')) {
    function pricing_calc_accommodation($cfg, $room_name, $nights, $adults, $c_free, $c_half, $c_full, $checkin_date = null, $coupon_code = null)
    {
        $acc = isset($cfg['accommodation']) ? $cfg['accommodation'] : array();
        $rooms = isset($acc['rooms']) ? $acc['rooms'] : array();
        if (empty($rooms)) return array('ok' => false, 'error' => 'no rooms configured');
        $nights = max(1, (int) $nights);

        // total heads that must physically fit (free children still occupy the room)
        $heads = max(0, (int) $adults) + max(0, (int) $c_free) + max(0, (int) $c_half) + max(0, (int) $c_full);
        $ch = isset($cfg['children']) ? $cfg['children'] : array();

        // choose the room: honour a named request only if it fits; otherwise smallest room
        // that holds everyone (over-capacity => bigger room, per the owner).
        usort($rooms, function ($a, $b) { return $a['capacity'] - $b['capacity']; });
        $chosen = null; $upgraded = false;
        if ($room_name !== '') {
            // SPEC-31: resolve through the shared alias-aware resolver. A plain
            // first-substring match over the capacity-sorted list picks the WRONG
            // room now that two names share a prefix ("Deluxe Double or Twin" 4,500
            // vs "Deluxe Double Room with Balcony" 5,500) — it would quote the
            // cheaper one for the balcony room. media_find() also refuses to guess
            // when a name is ambiguous, which leaves $chosen null => fits-by-headcount.
            if (function_exists('media_find')) {
                $hit = media_find($cfg, $room_name);
                if ($hit !== null) {
                    foreach ($rooms as $r) if ($r['name'] === $hit['name']) { $chosen = $r; break; }
                }
            } else {
                foreach ($rooms as $r) if (mb_stripos($r['name'], $room_name) !== false) { $chosen = $r; break; }
            }
            if ($chosen !== null && $heads > (int) $chosen['capacity']) { $chosen = null; $upgraded = true; }
        }
        if ($chosen === null) {
            foreach ($rooms as $r) if ((int) $r['capacity'] >= max(1, $heads)) { $chosen = $r; break; }
        }
        if ($chosen === null) {
            // nobody fits — largest room, defer to team for extra beds
            $chosen = end($rooms);
            return array('ok' => true, 'kind' => 'accommodation', 'item' => $chosen['name'],
                'nights' => $nights, 'total' => null, 'needs_team' => true,
                'notes' => array('العدد أكبر من أكبر غرفة (' . $chosen['name'] . ' بتستوعب ' . $chosen['capacity'] . ') - يتأكد من الفريق'),
                'currency' => isset($cfg['currency']) ? $cfg['currency'] : 'جنيه', 'facts' => array());
        }

        // minimum stay (optional per room): reject shorter stays outright, no price.
        if (isset($chosen['min_nights']) && (int) $chosen['min_nights'] > 0 && $nights < (int) $chosen['min_nights']) {
            return array('ok' => false, 'reason' => 'min_stay', 'error' => 'stay shorter than minimum',
                'item' => $chosen['name'], 'min_nights' => (int) $chosen['min_nights'], 'nights' => $nights);
        }

        // seasons (optional): a season covering the check-in date can override this
        // room's nightly / weekend_nightly / offers for the whole stay.
        $season = pricing_find_season($cfg, $checkin_date);
        $priced_room = pricing_room_with_season($chosen, $season);
        $facts = array();
        if (isset($priced_room['_season'])) $facts[] = 'موسم ' . $priced_room['_season'] . ' مطبّق';

        // per-night vs a written multi-night offer price, else weekday/weekend (if dated), else flat
        $offers = isset($priced_room['offers']) ? $priced_room['offers'] : array();
        $total = null; $basis = '';
        if (isset($offers[(string) $nights])) {
            $total = (int) $offers[(string) $nights];
            $basis = 'سعر عرض الـ' . $nights . ' ليالي';
        } else {
            $ww = pricing_weekday_weekend_total($cfg, $priced_room, $checkin_date, $nights);
            if ($ww !== null) {
                list($total, $weekend_nights) = $ww;
                $basis = $nights . ' ليالي (منها ' . $weekend_nights . ' ويك إند) × سعر الليلة المناسب';
                if ($weekend_nights > 0) $facts[] = $weekend_nights . ' ليلة ويك إند بسعر ' . pricing_fmt($priced_room['weekend_nightly']);
            } else {
                $total = (int) $priced_room['nightly'] * $nights;
                $basis = $nights == 1 ? 'سعر الليلة' : ($nights . ' ليالي × سعر الليلة');
            }
        }
        list($total, $cfact) = pricing_apply_coupon($cfg, $total, $coupon_code);
        if ($cfact !== null) $facts[] = $cfact;

        // half-band children imply an unknown surcharge unless the config sets a price
        $needs_team = false; $notes = array();
        if ((int) $c_half > 0 && empty($ch['half_price_known'])) {
            $needs_team = true;
            $notes[] = (int) $c_half . ' طفل من 6 لأقل من 12: رسوم الطفل تتأكد من الفريق';
        }
        if ((int) $c_free > 0) $notes[] = (int) $c_free . ' طفل تحت ' . (isset($ch['free_max_age']) ? $ch['free_max_age'] : 5) . ' مجانًا';
        if ($upgraded) $notes[] = 'العدد أكبر من الغرفة المطلوبة، فرشحنا الأنسب';

        // suggest the 4-night (or any) offer when it is cheaper than this nights count
        $upsell = '';
        foreach ($offers as $on => $op) {
            if ((int) $on > $nights && (int) $op < ($chosen['nightly'] * $nights) === false) {}
        }
        foreach ($offers as $on => $op) {
            if ((int) $on > $nights && (int) $op <= $total) {
                $upsell = 'عرض الـ' . (int) $on . ' ليالي بـ' . number_format((int) $op) . ' - ليالي أكتر بسعر أقل';
            }
        }
        if ($upsell !== '') $notes[] = $upsell;

        return array('ok' => true, 'kind' => 'accommodation', 'item' => $chosen['name'],
            'capacity' => (int) $chosen['capacity'], 'nights' => $nights,
            'nightly' => (int) $priced_room['nightly'], 'basis' => $basis,
            'total' => $total, 'needs_team' => $needs_team, 'notes' => $notes,
            'currency' => isset($cfg['currency']) ? $cfg['currency'] : 'جنيه', 'facts' => $facts);
    }
}

/*
 * ---------------------------------------------------------------------------
 * Products & Pricing admin (Phase 10 / brief 21): validation + audited save.
 * The editor sends the WHOLE config object back, so keys this validator does not
 * know (media, room aliases, future fields) are preserved untouched.
 * ---------------------------------------------------------------------------
 */
if (!function_exists('pricing_validate_quantity_discounts')) {
    /** @return string[] errors for a quantity_discounts list ({min_qty, price} tiers) with a given label prefix. */
    function pricing_validate_quantity_discounts($label, $tiers)
    {
        $e = array();
        $num = function ($v) { return is_numeric($v) && (float) $v >= 0; };
        if (!is_array($tiers)) { $e[] = "$label: quantity discounts must be a list."; return $e; }
        $seen = array();
        foreach (array_values($tiers) as $j => $t) {
            $tlabel = "$label: quantity discount #" . ($j + 1);
            if (!is_array($t)) { $e[] = "$tlabel: must be an object."; continue; }
            if (!$num($t['min_qty'] ?? null) || (float) ($t['min_qty'] ?? 0) <= 0) $e[] = "$tlabel: minimum quantity must be > 0.";
            else {
                $mk = (float) $t['min_qty'];
                if (isset($seen[$mk])) $e[] = "$tlabel: duplicate minimum quantity.";
                $seen[$mk] = true;
            }
            if (!$num($t['price'] ?? null) || (float) ($t['price'] ?? 0) <= 0) $e[] = "$tlabel: tier price must be > 0.";
        }
        return $e;
    }
}

if (!function_exists('pricing_validate_config')) {
    /** @return string[] human-readable errors; empty = valid */
    function pricing_validate_config($cfg)
    {
        $e = array();
        if (!is_array($cfg)) return array('Config must be a JSON object.');
        $isInt = function ($v) { return is_int($v) || (is_string($v) && ctype_digit($v)) || (is_float($v) && floor($v) == $v); };
        $num = function ($v) { return is_numeric($v) && (float) $v >= 0; };

        if (isset($cfg['accommodation'])) {
            $rooms = $cfg['accommodation']['rooms'] ?? array();
            if (!is_array($rooms)) $e[] = 'accommodation.rooms must be a list.';
            else {
                $seen = array();
                foreach (array_values($rooms) as $i => $r) {
                    $label = 'Room #' . ($i + 1);
                    $name = trim((string) ($r['name'] ?? ''));
                    if ($name === '') { $e[] = "$label: name is required."; continue; }
                    $label = "Room \"$name\"";
                    $k = mb_strtolower($name);
                    if (isset($seen[$k])) $e[] = "$label: duplicate room name.";
                    $seen[$k] = true;
                    if (!$isInt($r['capacity'] ?? null) || (int) $r['capacity'] < 1) $e[] = "$label: capacity must be a whole number ≥ 1.";
                    if (!$num($r['nightly'] ?? null) || (float) $r['nightly'] <= 0) $e[] = "$label: nightly price must be > 0.";
                    if (isset($r['min_nights']) && (!$isInt($r['min_nights']) || (int) $r['min_nights'] < 1)) $e[] = "$label: minimum stay must be a whole number ≥ 1.";
                    if (isset($r['weekend_nightly']) && (!$num($r['weekend_nightly']) || (float) $r['weekend_nightly'] <= 0)) $e[] = "$label: weekend nightly price must be > 0.";
                    if (isset($r['offers'])) {
                        if (!is_array($r['offers'])) $e[] = "$label: offers must be an object {nights: total}.";
                        else foreach ($r['offers'] as $nights => $total) {
                            if (!$isInt($nights) || (int) $nights < 2) $e[] = "$label: offer nights \"$nights\" must be a whole number ≥ 2.";
                            if (!$num($total) || (float) $total <= 0) $e[] = "$label: offer total for $nights nights must be > 0.";
                            elseif ($num($r['nightly'] ?? null) && (float) $total > (float) $r['nightly'] * (int) $nights)
                                $e[] = "$label: the $nights-night offer ($total) costs more than $nights × nightly — check it.";
                        }
                    }
                }
            }
            if (isset($cfg['accommodation']['weekend_days'])) {
                $wd = $cfg['accommodation']['weekend_days'];
                if (!is_array($wd) || empty($wd)) $e[] = 'accommodation.weekend_days must be a non-empty list of weekdays (1=Mon..7=Sun).';
                else foreach ($wd as $d) if (!$isInt($d) || (int) $d < 1 || (int) $d > 7) $e[] = 'accommodation.weekend_days: each day must be 1 (Mon) to 7 (Sun).';
            }
            if (isset($cfg['accommodation']['seasons'])) {
                $seasons = $cfg['accommodation']['seasons'];
                if (!is_array($seasons)) $e[] = 'accommodation.seasons must be a list.';
                else foreach (array_values($seasons) as $i => $s) {
                    $label = 'Season #' . ($i + 1);
                    if (!is_array($s)) { $e[] = "$label: must be an object."; continue; }
                    $sname = trim((string) ($s['name'] ?? ''));
                    if ($sname === '') $e[] = "$label: name is required.";
                    else $label = "Season \"$sname\"";
                    $st = isset($s['start']) ? strtotime((string) $s['start']) : false;
                    $en = isset($s['end']) ? strtotime((string) $s['end']) : false;
                    if (empty($s['start']) || $st === false) $e[] = "$label: start date is required (YYYY-MM-DD).";
                    if (empty($s['end']) || $en === false) $e[] = "$label: end date is required (YYYY-MM-DD).";
                    if ($st !== false && $en !== false && $en < $st) $e[] = "$label: end date must be on or after the start date.";
                    if (isset($s['rooms'])) {
                        if (!is_array($s['rooms'])) $e[] = "$label: rooms must be an object {room name: overrides}.";
                        else foreach ($s['rooms'] as $rname => $ov) {
                            if (!is_array($ov)) { $e[] = "$label: override for \"$rname\" must be an object."; continue; }
                            if (isset($ov['nightly']) && (!$num($ov['nightly']) || (float) $ov['nightly'] <= 0)) $e[] = "$label: \"$rname\" nightly override must be > 0.";
                            if (isset($ov['weekend_nightly']) && (!$num($ov['weekend_nightly']) || (float) $ov['weekend_nightly'] <= 0)) $e[] = "$label: \"$rname\" weekend nightly override must be > 0.";
                            if (isset($ov['offers']) && !is_array($ov['offers'])) $e[] = "$label: \"$rname\" offers override must be an object.";
                        }
                    }
                }
            }
        }
        if (isset($cfg['day_use'])) {
            $pk = $cfg['day_use']['packages'] ?? array();
            if (!is_array($pk)) $e[] = 'day_use.packages must be a list.';
            else foreach (array_values($pk) as $i => $p) {
                $name = trim((string) ($p['name'] ?? ''));
                if ($name === '') $e[] = 'Day-use package #' . ($i + 1) . ': name is required.';
                if (!$num($p['per_person'] ?? null) || (float) $p['per_person'] <= 0) $e[] = "Day-use package \"$name\": price per person must be > 0.";
            }
            if (!empty($cfg['day_use']['nth_free'])) {
                $nf = $cfg['day_use']['nth_free'];
                $every = (int) ($nf['every'] ?? 0); $pay = (int) ($nf['pay'] ?? 0);
                if ($every < 2 || $pay < 1 || $pay >= $every) $e[] = 'Group offer: "every" must be ≥ 2 and "pay" between 1 and every−1 (e.g. every 5, pay 4).';
            }
        }
        if (isset($cfg['children'])) {
            $c = $cfg['children'];
            foreach (array('free_max_age', 'free_cap', 'half_min_age', 'half_max_age', 'full_min_age') as $f) {
                if (isset($c[$f]) && (!$isInt($c[$f]) || (int) $c[$f] < 0)) $e[] = "Children: $f must be a whole number ≥ 0.";
            }
            if (isset($c['free_max_age'], $c['half_min_age']) && (int) $c['half_min_age'] <= (int) $c['free_max_age'])
                $e[] = 'Children: the half-price band must start after the free age.';
            if (isset($c['half_max_age'], $c['full_min_age']) && (int) $c['full_min_age'] <= (int) $c['half_max_age'])
                $e[] = 'Children: full price must start after the half-price band.';
        }
        if (isset($cfg['catalog'])) {
            $items = is_array($cfg['catalog']) ? ($cfg['catalog']['items'] ?? array()) : null;
            if (!is_array($items)) $e[] = 'catalog.items must be a list.';
            else {
                $units = pricing_catalog_units();
                $seen = array();
                foreach (array_values($items) as $i => $it) {
                    $label = 'Product #' . ($i + 1);
                    if (!is_array($it)) { $e[] = "$label: must be an object."; continue; }
                    $name = trim((string) ($it['name'] ?? ''));
                    if ($name === '') { $e[] = "$label: name is required."; continue; }
                    $label = "Product \"$name\"";
                    $k = mb_strtolower($name);
                    if (isset($seen[$k])) $e[] = "$label: duplicate product name.";
                    $seen[$k] = true;
                    $unit = $it['unit'] ?? 'item';
                    if (!is_string($unit) || !isset($units[$unit])) $e[] = "$label: unit must be one of " . implode(', ', array_keys($units)) . '.';
                    elseif ($unit === 'custom' && trim((string) ($it['unit_label'] ?? '')) === '') $e[] = "$label: write the unit name (e.g. box, meter).";
                    $por = !empty($it['price_on_request']);
                    if (isset($it['price_on_request']) && !is_bool($it['price_on_request']) && !in_array($it['price_on_request'], array(0, 1, '0', '1'), true))
                        $e[] = "$label: price_on_request must be true or false.";
                    $variants = $it['variants'] ?? array();
                    if (!is_array($variants)) { $e[] = "$label: variants must be a list."; $variants = array(); }
                    $has_price = array_key_exists('price', $it) && $it['price'] !== null && $it['price'] !== '';
                    if ($has_price && (!$num($it['price']) || (float) $it['price'] <= 0)) $e[] = "$label: price must be > 0.";
                    elseif (!$has_price && !$por && empty($variants)) $e[] = "$label: price must be > 0 (or tick \"price on request\").";
                    $vseen = array();
                    foreach (array_values($variants) as $j => $v) {
                        $vn = is_array($v) ? trim((string) ($v['name'] ?? '')) : '';
                        if ($vn === '') { $e[] = "$label: option #" . ($j + 1) . ' needs a name.'; continue; }
                        $vk = mb_strtolower($vn);
                        if (isset($vseen[$vk])) $e[] = "$label: duplicate option \"$vn\".";
                        $vseen[$vk] = true;
                        if (empty($v['price_on_request']) && (!$num($v['price'] ?? null) || (float) $v['price'] <= 0))
                            $e[] = "$label: option \"$vn\" price must be > 0.";
                    }
                    if (isset($it['aliases']) && (!is_array($it['aliases']) || count(array_filter($it['aliases'], 'is_string')) !== count($it['aliases'])))
                        $e[] = "$label: other names must be a list of words.";
                    if (isset($it['category']) && !is_string($it['category'])) $e[] = "$label: category must be text.";
                    if (isset($it['note']) && !is_string($it['note'])) $e[] = "$label: note must be text.";
                    if (isset($it['quantity_discounts'])) $e = array_merge($e, pricing_validate_quantity_discounts($label, $it['quantity_discounts']));
                }
            }
        }
        if (isset($cfg['packages'])) {
            $pkgs = $cfg['packages'];
            if (!is_array($pkgs)) $e[] = 'packages must be a list.';
            else {
                $seen = array();
                foreach (array_values($pkgs) as $i => $p) {
                    $label = 'Package #' . ($i + 1);
                    if (!is_array($p)) { $e[] = "$label: must be an object."; continue; }
                    $name = trim((string) ($p['name'] ?? ''));
                    if ($name === '') { $e[] = "$label: name is required."; continue; }
                    $label = "Package \"$name\"";
                    $k = mb_strtolower($name);
                    if (isset($seen[$k])) $e[] = "$label: duplicate package name.";
                    $seen[$k] = true;
                    $por = !empty($p['price_on_request']);
                    if (!$por && (!$num($p['price'] ?? null) || (float) $p['price'] <= 0)) $e[] = "$label: price must be > 0 (or tick \"price on request\").";
                    if (isset($p['quantity_discounts'])) $e = array_merge($e, pricing_validate_quantity_discounts($label, $p['quantity_discounts']));
                }
            }
        }
        if (isset($cfg['coupons'])) {
            $coupons = $cfg['coupons'];
            if (!is_array($coupons)) $e[] = 'coupons must be a list.';
            else {
                $seen = array();
                foreach (array_values($coupons) as $i => $c) {
                    $label = 'Coupon #' . ($i + 1);
                    if (!is_array($c)) { $e[] = "$label: must be an object."; continue; }
                    $code = trim((string) ($c['code'] ?? ''));
                    if ($code === '') { $e[] = "$label: code is required."; continue; }
                    $label = "Coupon \"$code\"";
                    $k = mb_strtolower($code);
                    if (isset($seen[$k])) $e[] = "$label: duplicate coupon code.";
                    $seen[$k] = true;
                    $type = $c['type'] ?? 'percent';
                    if (!in_array($type, array('percent', 'fixed'), true)) $e[] = "$label: type must be percent or fixed.";
                    if (!$num($c['value'] ?? null) || (float) ($c['value'] ?? 0) <= 0) $e[] = "$label: value must be > 0.";
                    elseif ($type === 'percent' && (float) $c['value'] > 100) $e[] = "$label: percent value cannot exceed 100.";
                    $st = isset($c['start']) && $c['start'] !== '' ? strtotime((string) $c['start']) : null;
                    $en = isset($c['end']) && $c['end'] !== '' ? strtotime((string) $c['end']) : null;
                    if ($st === false) $e[] = "$label: start date is invalid (YYYY-MM-DD).";
                    if ($en === false) $e[] = "$label: end date is invalid (YYYY-MM-DD).";
                    if ($st && $en && $en < $st) $e[] = "$label: end date must be on or after the start date.";
                }
            }
        }
        if (!isset($cfg['accommodation']) && !isset($cfg['day_use']) && !isset($cfg['catalog']) && !isset($cfg['packages']))
            $e[] = 'Add at least one product or service (or rooms / day-use packages).';
        elseif (!isset($cfg['accommodation']) && !isset($cfg['day_use']) && empty($cfg['catalog']['items']) && empty($cfg['packages']))
            $e[] = 'Add at least one product or service.';
        return $e;
    }
}

if (!function_exists('pricing_save_config')) {
    /**
     * Validate and save one list; writes an audit row with before/after and the scope it
     * belongs to. $scope null = the account-wide default list (the original behaviour).
     * Returns [ok, errors[]].
     */
    function pricing_save_config($user_id, $cfg, $changed_by, $summary = '', $scope = null)
    {
        $errors = pricing_validate_config($cfg);
        if (!empty($errors)) return array(false, $errors);
        list($ch, $t) = pricing_normalize_scope($scope);
        $scoped = pricing_has_scope_columns();
        if ($ch !== '' && !$scoped) return array(false, array('Per-page price lists need the latest database update (run migrations).'));
        $ci = &get_instance();
        $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $row = pricing_get_row($user_id, array($ch, $t), false);
        $before = $row['config_json'] ?? null;
        if ($before !== null && json_decode($before, true) == $cfg) return array(true, array()); // no change
        $ci->db->trans_start();
        if ($row) $ci->db->where('id', $row['id'])->update('pricing_config', array('config_json' => $json, 'status' => '1', 'updated_at' => date('Y-m-d H:i:s')));
        else {
            $ins = array('user_id' => (int) $user_id, 'config_json' => $json, 'status' => '1', 'updated_at' => date('Y-m-d H:i:s'));
            if ($scoped) { $ins['channel_type'] = $ch; $ins['target_id'] = $t; }
            $ci->db->insert('pricing_config', $ins);
        }
        pricing_history_write($user_id, array($ch, $t), $changed_by, $before, $json, $summary);
        $ci->db->trans_complete();
        return $ci->db->trans_status() ? array(true, array()) : array(false, array('Database error while saving.'));
    }
}

if (!function_exists('pricing_history_write')) {
    function pricing_history_write($user_id, $scope, $changed_by, $before, $after, $summary)
    {
        $ci = &get_instance();
        if (!$ci->db->table_exists('pricing_config_history')) return;
        list($ch, $t) = pricing_normalize_scope($scope);
        $h = array(
            'user_id' => (int) $user_id, 'changed_by' => (int) $changed_by,
            'before_json' => $before, 'after_json' => $after,
            'summary' => mb_substr((string) $summary, 0, 255), 'created_at' => date('Y-m-d H:i:s'),
        );
        if (pricing_has_scope_columns() && $ci->db->field_exists('channel_type', 'pricing_config_history')) {
            $h['channel_type'] = $ch; $h['target_id'] = $t;
        }
        $ci->db->insert('pricing_config_history', $h);
    }
}

if (!function_exists('pricing_copy_config')) {
    /**
     * Copy the list of $from onto $to (validated + audited like any save). The source is
     * untouched. Refuses to overwrite an existing list unless $overwrite.
     * Returns [ok, errors[], code] with code 'exists' | 'no_source' | ''.
     */
    function pricing_copy_config($user_id, $from, $to, $changed_by, $overwrite = false, $summary = '')
    {
        $from = pricing_normalize_scope($from); $to = pricing_normalize_scope($to);
        if ($from === $to) return array(false, array('Pick a different page to copy to.'), 'same');
        $src = pricing_get_row($user_id, $from, false);
        $cfg = !empty($src['config_json']) ? json_decode($src['config_json'], true) : null;
        if (!is_array($cfg)) return array(false, array('This list is empty — save it first.'), 'no_source');
        if (!$overwrite && pricing_get_row($user_id, $to, false)) return array(false, array('That page already has its own price list.'), 'exists');
        list($ok, $errors) = pricing_save_config($user_id, $cfg, $changed_by, $summary, $to);
        return array($ok, $errors, '');
    }
}

if (!function_exists('pricing_move_config')) {
    /**
     * Move a list (normally the default one) to another scope: after this the source
     * scope has no list of its own. Keeps the row (id, on/off state); audited on the
     * destination. Refuses to replace an existing list unless $overwrite.
     * Returns [ok, errors[], code].
     */
    function pricing_move_config($user_id, $from, $to, $changed_by, $overwrite = false, $summary = '')
    {
        $from = pricing_normalize_scope($from); $to = pricing_normalize_scope($to);
        if ($from === $to) return array(false, array('Pick a different page.'), 'same');
        if (!pricing_has_scope_columns()) return array(false, array('Per-page price lists need the latest database update (run migrations).'), 'schema');
        $src = pricing_get_row($user_id, $from, false);
        if (empty($src)) return array(false, array('There is no list to move.'), 'no_source');
        $dst = pricing_get_row($user_id, $to, false);
        if ($dst && !$overwrite) return array(false, array('That page already has its own price list.'), 'exists');
        $ci = &get_instance();
        $ci->db->trans_start();
        if ($dst) {
            $ci->db->where('id', $dst['id'])->delete('pricing_config');
        }
        $ci->db->where('id', $src['id'])->update('pricing_config', array('channel_type' => $to[0], 'target_id' => $to[1], 'updated_at' => date('Y-m-d H:i:s')));
        pricing_history_write($user_id, $to, $changed_by, $dst['config_json'] ?? null, $src['config_json'], $summary);
        pricing_history_write($user_id, $from, $changed_by, $src['config_json'], null, $summary);
        $ci->db->trans_complete();
        return $ci->db->trans_status() ? array(true, array(), '') : array(false, array('Database error while moving.'), 'db');
    }
}

if (!function_exists('pricing_delete_config')) {
    /** Remove one scope's own list (audited). The page then falls back to the default list. */
    function pricing_delete_config($user_id, $scope, $changed_by, $summary = '')
    {
        $row = pricing_get_row($user_id, $scope, false);
        if (empty($row)) return array(true, array());
        $ci = &get_instance();
        $ci->db->trans_start();
        $ci->db->where('id', $row['id'])->delete('pricing_config');
        pricing_history_write($user_id, $scope, $changed_by, $row['config_json'], null, $summary);
        $ci->db->trans_complete();
        return $ci->db->trans_status() ? array(true, array()) : array(false, array('Database error while removing.'));
    }
}

if (!function_exists('pricing_connected_targets')) {
    /**
     * Every connected page/number/widget a price list can be attached to, with the owner's
     * name for it. Built on ai_agent_connected_channels() (one source for "what is
     * connected"), re-keyed onto the price-list channel types: its 'wa' rows are split
     * into wa_cloud / openwa by their 'kind'.
     * @return array[] {scope:'fb:12', channel, target, label, note, icon}
     */
    function pricing_connected_targets($user_id)
    {
        $ci = &get_instance();
        if (!function_exists('ai_agent_connected_channels')) $ci->load->helper('ai_agent');
        $out = array();
        foreach (ai_agent_connected_channels($user_id) as $c) {
            $ch = $c['channel'];
            if ($ch === 'wa') $ch = (($c['kind'] ?? '') === 'openwa') ? 'openwa' : 'wa_cloud';
            if (!in_array($ch, pricing_scope_channels(), true)) continue;
            $t = (string) $c['target'];
            if ($t === '') continue;
            $out[] = array('scope' => $ch . ':' . $t, 'channel' => $ch, 'target' => $t,
                'label' => (string) $c['label'], 'note' => (string) ($c['note'] ?? ''), 'icon' => (string) ($c['icon'] ?? ''));
        }
        return $out;
    }
}

if (!function_exists('pricing_target_owned')) {
    /** IDOR gate: the default scope, or a target that is one of THIS tenant's connections. */
    function pricing_target_owned($user_id, $scope)
    {
        list($ch, $t) = pricing_normalize_scope($scope);
        if ($ch === '') return true;
        foreach (pricing_connected_targets($user_id) as $c) {
            if ($c['channel'] === $ch && $c['target'] === $t) return true;
        }
        return false;
    }
}
