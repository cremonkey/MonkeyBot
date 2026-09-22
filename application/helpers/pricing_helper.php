<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * SPEC-24 — deterministic price calculation for the calculate_price AI tool.
 *
 * All arithmetic lives here, never in the model: the price guard cannot verify a total
 * (measured 4/8) and the source numbers collide across items, so a computed figure must
 * be produced in code and handed to the model (and to the guard) as authoritative.
 *
 * Config shape (pricing_config.config_json), per user:
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
 *   "children": {"free_max_age":5,"free_cap":2,"half_min_age":6,"half_max_age":11,"full_min_age":12,"half_price_known":false}
 * }
 */

if (!function_exists('pricing_get_config')) {
    function pricing_get_config($user_id)
    {
        $ci = &get_instance();
        if (!$ci->db->table_exists('pricing_config')) return null;
        $row = $ci->db->from('pricing_config')->where('user_id', (int) $user_id)->where('status', '1')->get()->row_array();
        if (empty($row) || empty($row['config_json'])) return null;
        $cfg = json_decode($row['config_json'], true);
        return is_array($cfg) ? $cfg : null;
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
    function pricing_calc_day_use($cfg, $package_name, $adults, $c_free, $c_half, $c_full)
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
        return array(
            'ok' => true, 'kind' => 'day_use', 'item' => $pkg['name'],
            'per_person' => (int) $pkg['per_person'], 'people' => $people,
            'total' => $total, 'needs_team' => $needs_team,
            'notes' => array_filter(array_merge($notes, array($offer_note))),
            'currency' => isset($cfg['currency']) ? $cfg['currency'] : 'جنيه',
        );
    }
}

if (!function_exists('pricing_calc_accommodation')) {
    function pricing_calc_accommodation($cfg, $room_name, $nights, $adults, $c_free, $c_half, $c_full)
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
                'currency' => isset($cfg['currency']) ? $cfg['currency'] : 'جنيه');
        }

        // per-night vs a written multi-night offer price
        $offers = isset($chosen['offers']) ? $chosen['offers'] : array();
        $total = null; $basis = '';
        if (isset($offers[(string) $nights])) {
            $total = (int) $offers[(string) $nights];
            $basis = 'سعر عرض الـ' . $nights . ' ليالي';
        } else {
            $total = (int) $chosen['nightly'] * $nights;
            $basis = $nights == 1 ? 'سعر الليلة' : ($nights . ' ليالي × سعر الليلة');
        }

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
            'nightly' => (int) $chosen['nightly'], 'basis' => $basis,
            'total' => $total, 'needs_team' => $needs_team, 'notes' => $notes,
            'currency' => isset($cfg['currency']) ? $cfg['currency'] : 'جنيه');
    }
}

/*
 * ---------------------------------------------------------------------------
 * Products & Pricing admin (Phase 10 / brief 21): validation + audited save.
 * The editor sends the WHOLE config object back, so keys this validator does not
 * know (media, room aliases, future fields) are preserved untouched.
 * ---------------------------------------------------------------------------
 */
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
        if (!isset($cfg['accommodation']) && !isset($cfg['day_use'])) $e[] = 'Add at least rooms or day-use packages.';
        return $e;
    }
}

if (!function_exists('pricing_save_config')) {
    /**
     * Validate and save; writes an audit row with before/after. Returns [ok, errors[]].
     */
    function pricing_save_config($user_id, $cfg, $changed_by, $summary = '')
    {
        $errors = pricing_validate_config($cfg);
        if (!empty($errors)) return array(false, $errors);
        $ci = &get_instance();
        $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $row = $ci->db->from('pricing_config')->where('user_id', (int) $user_id)->get()->row_array();
        $before = $row['config_json'] ?? null;
        if ($before !== null && json_decode($before, true) == $cfg) return array(true, array()); // no change
        $ci->db->trans_start();
        if ($row) $ci->db->where('id', $row['id'])->update('pricing_config', array('config_json' => $json, 'status' => '1', 'updated_at' => date('Y-m-d H:i:s')));
        else $ci->db->insert('pricing_config', array('user_id' => (int) $user_id, 'config_json' => $json, 'status' => '1', 'updated_at' => date('Y-m-d H:i:s')));
        if ($ci->db->table_exists('pricing_config_history')) {
            $ci->db->insert('pricing_config_history', array(
                'user_id' => (int) $user_id, 'changed_by' => (int) $changed_by,
                'before_json' => $before, 'after_json' => $json,
                'summary' => mb_substr((string) $summary, 0, 255), 'created_at' => date('Y-m-d H:i:s'),
            ));
        }
        $ci->db->trans_complete();
        return $ci->db->trans_status() ? array(true, array()) : array(false, array('Database error while saving.'));
    }
}
