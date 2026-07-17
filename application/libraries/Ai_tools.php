<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * SPEC-03 — AI tool/function definitions and execution.
 *
 * Tools let the AI act on real data: search products, check order status,
 * create a discount code, hand off to a human. All queries are ownership-scoped
 * by user_id and use the query builder. execute() never throws (webhook-safe).
 */
class Ai_tools
{
    protected $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
    }

    /**
     * Provider-neutral tool catalog. Each entry: name, description, params (JSON-schema object).
     */
    public function catalog()
    {
        $tools = array(
            array(
                'name' => 'search_products',
                'description' => "Search the store's product catalog by keyword and return matching products with prices. Use when the customer asks about products, prices, or availability.",
                'params' => array(
                    'type' => 'object',
                    'properties' => array(
                        'query' => array('type' => 'string', 'description' => 'Search keywords, e.g. a product name or category.'),
                    ),
                    'required' => array('query'),
                ),
            ),
            array(
                'name' => 'get_order_status',
                'description' => "Look up the customer's most recent order by their email or phone number.",
                'params' => array(
                    'type' => 'object',
                    'properties' => array(
                        'email_or_phone' => array('type' => 'string', 'description' => 'Customer email address or phone number.'),
                    ),
                    'required' => array('email_or_phone'),
                ),
            ),
            array(
                'name' => 'create_discount_code',
                'description' => "Generate a single-use percentage discount coupon (5-30%) to encourage a purchase. Returns the coupon code.",
                'params' => array(
                    'type' => 'object',
                    'properties' => array(
                        'percent' => array('type' => 'integer', 'description' => 'Discount percentage between 5 and 30.'),
                    ),
                    'required' => array('percent'),
                ),
            ),
            array(
                'name' => 'handoff_to_human',
                'description' => "Escalate the conversation to a human agent. Use when the customer is angry, asks for a human, or the request is beyond your ability. The team gets an urgent follow-up task, so always pass the reason.",
                'params' => array(
                    'type' => 'object',
                    'properties' => array(
                        'reason' => array('type' => 'string', 'description' => "One short sentence, in the customer's language, explaining why they need a human (complaint, anger, special request)."),
                    ),
                    'required' => array('reason'),
                ),
            ),
            array(
                'name' => 'save_lead_to_crm',
                'description' => "Register the customer as a lead in the CRM. Call this IMMEDIATELY whenever the customer shares a phone/WhatsApp number or an email address, or confirms they want to order. Always include request_summary: 1-2 sentences, in the customer's own language, describing what they want (service/product, quantity, deadline, budget if mentioned).",
                'params' => array(
                    'type' => 'object',
                    'properties' => array(
                        'name' => array('type' => 'string', 'description' => "Customer's name if they mentioned it."),
                        'phone' => array('type' => 'string', 'description' => 'Phone or WhatsApp number the customer shared.'),
                        'email' => array('type' => 'string', 'description' => 'Email address the customer shared.'),
                        'request_summary' => array('type' => 'string', 'description' => "Short summary of the customer's request, in the customer's language."),
                        'customer_profile' => array('type' => 'string', 'description' => "One line for the sales team, in the customer's language: buyer personality (decisive / analytical / hesitant / social / price-focused), their key needs, and any objection raised. Example: 'متردد - محتاج 500 كارت شخصي قبل الخميس - اعترض على السعر مرة'."),
                        'conversation_status' => array('type' => 'string', 'description' => "Where the conversation actually got to, in the customer's language, so the sales rep can pick it up mid-thread instead of starting over. Say what you already quoted or offered, what the customer decided or objected to, and what is still open. Facts only - never guess at something that was not said. Example: 'اتعرضت عليه الباقة الأولى 900 للفرد، عجبته بس لسه مأكدش التاريخ ولا عدد الأطفال'."),
                    ),
                    'required' => array('request_summary'),
                ),
            ),
        );

        // calculate_price is only offered when this account has a pricing config, so it
        // never appears for accounts that sell simple catalog products.
        if ($this->CI->db->table_exists('pricing_config')) {
            $uid = (int) ($this->CI->session->userdata('user_id') ?: 0);
            // catalog() is also called on the reply path where session is absent; there we
            // include the tool unconditionally and t_calculate_price no-ops without config.
            $tools[] = array(
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
        }
        return $tools;
    }

    /** OpenAI tools format */
    public function openai_tools()
    {
        $out = array();
        foreach ($this->catalog() as $t) {
            $out[] = array('type' => 'function', 'function' => array(
                'name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['params'],
            ));
        }
        return $out;
    }

    /** Anthropic tools format */
    public function anthropic_tools()
    {
        $out = array();
        foreach ($this->catalog() as $t) {
            $out[] = array('name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['params']);
        }
        return $out;
    }

    /**
     * Execute a tool. $context: user_id, page_id, subscribe_id, social_media, subscriber_row_id.
     * Returns a short string result for the model.
     */
    public function execute($name, $args, $context)
    {
        try {
            $user_id = (int) ($context['user_id'] ?? 0);
            if ($user_id <= 0) return 'Error: no account context.';
            switch ($name) {
                case 'search_products':   return $this->t_search_products($user_id, $args);
                case 'get_order_status':  return $this->t_order_status($user_id, $args);
                case 'create_discount_code': return $this->t_create_coupon($user_id, $args);
                case 'handoff_to_human':  return $this->t_handoff($context, $args);
                case 'save_lead_to_crm':  return $this->t_save_lead($user_id, $args, $context);
                case 'calculate_price':   return $this->t_calculate_price($user_id, $args);
                default: return 'Error: unknown tool.';
            }
        } catch (Exception $e) {
            log_message('error', 'Ai_tools '.$name.' failed: '.$e->getMessage());
            return 'The action could not be completed right now.';
        }
    }

    protected function t_search_products($user_id, $args)
    {
        $q = trim((string) ($args['query'] ?? ''));
        // SPEC-05: use the catalog helper so results include buy links the AI can share in chat
        if (file_exists(APPPATH.'helpers/ecommerce_catalog_helper.php')) {
            $this->CI->load->helper('ecommerce_catalog');
            $text = ecom_catalog_text($user_id, $q, 5);
            // stash carousel cards so the fb channel can send them alongside the text reply
            if ($text !== '' && function_exists('ecom_catalog_elements')) {
                $this->CI->ai_last_products = ecom_catalog_elements($user_id, $q, 5);
            }
            return $text !== '' ? ("Products found (share links with the customer):\n".$text) : ('No products matched "'.$q.'".');
        }
        $db = $this->CI->db;
        $db->select('p.product_name, p.sell_price, p.original_price, p.stock_item, p.stock_prevent_purchase')
           ->from('ecommerce_product p')->where('p.user_id', $user_id)->where('p.status', '1')->where('p.deleted', '0')
           ->group_start()->like('p.product_name', $q)->or_like('p.product_description', $q)->group_end()->limit(5);
        $rows = $db->get()->result_array();
        if (empty($rows)) return 'No products matched "'.$q.'".';
        $out = array();
        foreach ($rows as $r) { $out[] = $r['product_name'].' — '.$r['sell_price']; }
        return "Products found:\n- ".implode("\n- ", $out);
    }

    protected function t_order_status($user_id, $args)
    {
        $key = trim((string) ($args['email_or_phone'] ?? ''));
        if ($key === '') return 'No email or phone given.';
        $db = $this->CI->db;
        // only real placed orders (action_type=checkout), not in-progress/abandoned carts
        $db->from('ecommerce_cart')->where('user_id', $user_id)->where('action_type', 'checkout')
           ->group_start()->where('buyer_email', $key)->or_where('buyer_mobile', $key)->group_end()
           ->order_by('id', 'DESC')->limit(1);
        $row = $db->get()->row_array();
        if (empty($row)) return 'No order found for '.$key.'.';
        $when = !empty($row['ordered_at']) ? $row['ordered_at'] : 'pending';
        $paid = !empty($row['payment_method']) ? ('paid via '.$row['payment_method']) : 'not yet paid';
        return 'Latest order: total '.$row['payment_amount'].' '.$row['currency'].', '.$paid.', placed '.$when.'.';
    }

    protected function t_create_coupon($user_id, $args)
    {
        $percent = (int) ($args['percent'] ?? 0);
        if ($percent < 5) $percent = 5;
        if ($percent > 30) $percent = 30;
        // reuse SPEC-06 helper if present
        if (file_exists(APPPATH.'helpers/coupon_helper.php')) {
            $this->CI->load->helper('coupon');
            $store = $this->CI->db->select('id')->from('ecommerce_store')->where('user_id', $user_id)->order_by('id','ASC')->limit(1)->get()->row_array();
            if (empty($store)) return 'No store configured to attach a coupon.';
            if (function_exists('generate_coupon')) {
                $code = generate_coupon($user_id, $store['id'], $percent, 7, 'AI');
                if ($code) return 'Discount code '.$code.' for '.$percent.'% off, valid 7 days.';
            }
        }
        // inline fallback generator
        $store = $this->CI->db->select('id')->from('ecommerce_store')->where('user_id', $user_id)->order_by('id','ASC')->limit(1)->get()->row_array();
        if (empty($store)) return 'No store configured to attach a coupon.';
        for ($i = 0; $i < 6; $i++) {
            $code = 'AI'.strtoupper(substr(md5(uniqid('', true)), 0, 8));
            $exists = $this->CI->db->from('ecommerce_coupon')->where('coupon_code', $code)->count_all_results();
            if (!$exists) break;
        }
        $this->CI->db->insert('ecommerce_coupon', array(
            'user_id' => $user_id, 'store_id' => $store['id'], 'coupon_type' => 'percent',
            'coupon_code' => $code, 'coupon_amount' => $percent, 'free_shipping_enabled' => '0',
            'expiry_date' => date('Y-m-d H:i:s', strtotime('+7 days')), 'updated_at' => date('Y-m-d H:i:s'),
            'product_ids' => '', 'max_usage_limit' => 1, 'used' => 0, 'status' => '1',
        ));
        return 'Discount code '.$code.' for '.$percent.'% off, valid 7 days (single use).';
    }

    /**
     * The last few real turns of this conversation, compact, for the sales rep who picks
     * the lead up in the external CRM and cannot see the thread.
     *
     * Read from ai_conversation_history (one row = one human/ai pair). Kept short and
     * capped: this rides along in a CRM description field, not an archive. Returns ''
     * when there is nothing useful (e.g. the lead came from a channel with no history).
     */
    protected function _recent_transcript($user_id, $context, $turns = 4, $max_chars = 1200)
    {
        try {
            $page_id = (string) ($context['page_id'] ?? '');
            $sub_id  = (string) ($context['subscribe_id'] ?? '');
            if ($page_id === '' || $sub_id === '') return '';
            $db = $this->CI->db;
            if (!$db->table_exists('ai_conversation_history')) return '';

            $rows = $db->select('human_message, ai_reply')->from('ai_conversation_history')
                ->where('user_id', (int) $user_id)->where('page_id', $page_id)->where('subscribe_id', $sub_id)
                ->order_by('id', 'DESC')->limit((int) $turns)->get()->result_array();
            if (empty($rows)) return '';

            $out = array();
            foreach (array_reverse($rows) as $r) {
                $h = trim(preg_replace('/\s+/u', ' ', (string) $r['human_message']));
                $a = trim(preg_replace('/\s+/u', ' ', (string) $r['ai_reply']));
                if ($h !== '') $out[] = 'العميل: ' . mb_substr($h, 0, 200);
                if ($a !== '') $out[] = 'البوت: ' . mb_substr($a, 0, 200);
            }
            $text = implode("\n", $out);
            // keep the END of the conversation — that is where it actually got to
            if (mb_strlen($text) > $max_chars) $text = '…' . mb_substr($text, -$max_chars);
            return $text;
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * SPEC-24: exact price total. The computed figure is also stashed on the CI instance so
     * the price guard sees it as authoritative — otherwise the guard, which only knows the
     * per-night/per-person source numbers, would block a correct computed total.
     */
    protected function t_calculate_price($user_id, $args)
    {
        $this->CI->load->helper('pricing');
        $cfg = pricing_get_config($user_id);
        if (empty($cfg)) return 'Pricing is not configured; tell the customer the team will confirm the exact total and ask for their contact number.';

        $type    = ($args['type'] ?? '') === 'accommodation' ? 'accommodation' : 'day_use';
        $item    = trim((string) ($args['room_or_package'] ?? ''));
        $nights  = (int) ($args['nights'] ?? 1);
        $adults  = (int) ($args['adults'] ?? 0);
        $c_free  = (int) ($args['children_under_5'] ?? 0);
        $c_half  = (int) ($args['children_6_to_11'] ?? 0);
        $c_full  = (int) ($args['children_12_plus'] ?? 0);
        if ($adults <= 0 && $c_free <= 0 && $c_half <= 0 && $c_full <= 0) $adults = ($type === 'accommodation') ? 2 : 1;

        $r = $type === 'accommodation'
            ? pricing_calc_accommodation($cfg, $item, $nights, $adults, $c_free, $c_half, $c_full)
            : pricing_calc_day_use($cfg, $item, $adults, $c_free, $c_half, $c_full);

        if (empty($r['ok'])) return 'Could not price this exactly; tell the customer the team will confirm and ask for their contact number.';

        $cur = $r['currency'];
        $notes = !empty($r['notes']) ? implode('؛ ', $r['notes']) : '';

        if ($r['total'] === null || !empty($r['needs_team'])) {
            // Partial: we can name the item/basis but not a final total. Do NOT invent one.
            $line = $r['item'];
            if ($type === 'accommodation' && isset($r['nightly'])) $line .= ' - سعر الليلة ' . number_format($r['nightly']) . ' ' . $cur;
            $msg = 'ITEM: ' . $line . ($notes ? ' | ' . $notes : '')
                 . ' | NO FINAL TOTAL: some of this needs the team to confirm (say so and ask for the customer\'s WhatsApp; do not state a total).';
            $this->CI->ai_price_facts[] = $line . ($notes ? ' (' . $notes . ')' : '');
            return $msg;
        }

        $total_str = number_format($r['total']) . ' ' . $cur;
        // Fact the guard must treat as authoritative.
        if ($type === 'accommodation') {
            $fact = $r['item'] . ' لمدة ' . $r['nights'] . ' ليالي = ' . $total_str . ' (' . $r['basis'] . ')';
        } else {
            $fact = $r['item'] . ' للـDay Use لعدد ' . rtrim(rtrim(number_format($r['people'], 1), '0'), '.') . ' فرد = ' . $total_str;
        }
        $this->CI->ai_price_facts[] = $fact;

        return 'AUTHORITATIVE TOTAL (quote it exactly): ' . $fact . ($notes ? ' | ' . $notes : '')
             . ' | Present this total to the customer and move to close.';
    }

    protected function t_save_lead($user_id, $args, $context)
    {
        $name    = trim((string) ($args['name'] ?? ''));
        $phone   = trim((string) ($args['phone'] ?? ''));
        $email   = trim((string) ($args['email'] ?? ''));
        $summary = trim((string) ($args['request_summary'] ?? ''));
        $profile = trim((string) ($args['customer_profile'] ?? ''));
        $status  = trim((string) ($args['conversation_status'] ?? ''));
        if ($phone === '' && $email === '') {
            return 'No contact info to save yet: ask the customer for their phone/WhatsApp number or email first.';
        }

        $db  = $this->CI->db;
        $now = date('Y-m-d H:i:s');
        $source = in_array(($context['social_media'] ?? ''), array('fb','ig','wa','web','tg')) ? $context['social_media'] : 'manual';
        $sub_id = (int) ($context['subscriber_row_id'] ?? 0);

        // customer didn't state a name -> fall back to their social profile name
        if ($name === '' && $sub_id > 0) {
            $sub = $db->select('first_name, last_name, full_name')->from('messenger_bot_subscriber')->where('id', $sub_id)->get()->row_array();
            if (!empty($sub)) {
                $name = trim((string) ($sub['full_name'] ?? ''));
                if ($name === '') $name = trim(trim((string) ($sub['first_name'] ?? '')).' '.trim((string) ($sub['last_name'] ?? '')));
            }
        }

        $pipe = $db->select('id')->from('crm_pipelines')->where('user_id', $user_id)->where('status', '1')
                   ->order_by('is_default', 'DESC')->order_by('id', 'ASC')->limit(1)->get()->row_array();
        if (empty($pipe)) return 'Could not save: CRM has no pipeline configured.';
        $stage = $db->select('id')->from('crm_stages')->where('pipeline_id', $pipe['id'])->where('stage_type', 'open')
                    ->order_by('position', 'ASC')->limit(1)->get()->row_array();
        if (empty($stage)) return 'Could not save: CRM pipeline has no open stage.';

        // one open deal per customer: match by subscriber, else by the shared phone/email
        $db->from('crm_deals')->where('user_id', $user_id)->where('status', 'open');
        if ($sub_id > 0) {
            $db->where('subscriber_id', $sub_id);
        } else {
            $db->group_start();
            if ($phone !== '') $db->where('contact_phone', $phone);
            if ($email !== '') $db->or_where('contact_email', $email);
            $db->group_end();
        }
        $existing = $db->order_by('id', 'DESC')->limit(1)->get()->row_array();

        if (!empty($existing)) {
            $update = array('updated_at' => $now);
            if ($name !== ''  && empty($existing['contact_name']))  $update['contact_name']  = $name;
            if ($phone !== '' && empty($existing['contact_phone'])) $update['contact_phone'] = $phone;
            if ($email !== '' && empty($existing['contact_email'])) $update['contact_email'] = $email;
            // name learned after the deal was created: refresh a bare "Lead: <phone>" title
            if ($name !== '' && empty($existing['contact_name']) && strpos((string) $existing['title'], 'Lead: ') === 0) {
                $best_phone = $phone !== '' ? $phone : (string) $existing['contact_phone'];
                $update['title'] = 'Lead: '.$name.($best_phone !== '' ? ' ('.$best_phone.')' : '');
            }
            $db->where('id', $existing['id'])->update('crm_deals', $update);
            $deal_id = (int) $existing['id'];
            $result  = 'Customer already registered in the CRM (deal #'.$deal_id.'); their info and new request were added.';
        } else {
            $title = $name !== '' ? $name.($phone !== '' ? ' ('.$phone.')' : '') : ($phone !== '' ? $phone : $email);
            $db->insert('crm_deals', array(
                'user_id' => $user_id, 'pipeline_id' => $pipe['id'], 'stage_id' => $stage['id'],
                'title' => 'Lead: '.$title, 'subscriber_id' => ($sub_id > 0 ? $sub_id : null),
                'contact_name' => ($name !== '' ? $name : null),
                'contact_email' => ($email !== '' ? $email : null),
                'contact_phone' => ($phone !== '' ? $phone : null),
                'source' => $source, 'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
            ));
            $deal_id = (int) $db->insert_id();
            $db->insert('crm_deal_timeline', array(
                'deal_id' => $deal_id, 'user_id' => $user_id, 'action' => 'created',
                'new_value' => 'Lead captured by AI from '.$source, 'created_at' => $now,
            ));
            $result = 'Lead saved to the CRM (deal #'.$deal_id.'). Tell the customer the team will contact them soon.';
        }

        $details = array();
        if ($name !== '')  $details[] = 'Name: '.$name;
        if ($phone !== '') $details[] = 'Phone: '.$phone;
        if ($email !== '') $details[] = 'Email: '.$email;
        $note = $summary
            .($profile !== '' ? "\n\u{1F464} ".$profile : '')
            .($status !== '' ? "\n\u{1F4CD} وصل لفين: ".$status : '')
            .($details ? "\n".implode(' | ', $details) : '');

        // The same note is what gets mirrored outwards, plus the real last turns: the
        // rep opening the lead in the external CRM has no access to this conversation,
        // and a model-written summary can be wrong — the transcript can't.
        $transcript = $this->_recent_transcript($user_id, $context);
        $note_full = $note . ($transcript !== '' ? "\n\n── آخر المحادثة ──\n" . $transcript : '');
        $db->insert('crm_activities', array(
            'deal_id' => $deal_id, 'subscriber_id' => ($sub_id > 0 ? $sub_id : null), 'user_id' => $user_id,
            'type' => 'note', 'subject' => 'AI captured lead ('.$source.')',
            'description' => $note_full,
            'status' => 'completed', 'completed_at' => $now, 'created_at' => $now,
        ));

        // mirror the captured contact info onto the subscriber record so it
        // shows up everywhere (CRM contacts, live chat), not only on the deal
        if ($sub_id > 0 && ($phone !== '' || $email !== '')) {
            $sub_update = array();
            if ($phone !== '') $sub_update['phone_number'] = $phone;
            if ($email !== '') $sub_update['email'] = $email;
            $db->where('id', $sub_id)->update('messenger_bot_subscriber', $sub_update);
        }

        // SPEC-07: score the lead for sharing contact info, if the helper is available
        if ($sub_id > 0 && file_exists(APPPATH.'helpers/lead_scoring_helper.php')) {
            $this->CI->load->helper('lead_scoring');
            if (function_exists('lead_add_score')) @lead_add_score($sub_id, 'contact_info_shared');
        }

        // Everything below mirrors the lead outwards. The lead is already saved in our own
        // crm_deals at this point, and we are on the customer's reply path, so each of these
        // fails OPEN: an outage in someone else's service must never cost us the reply.
        $mirror = array(
            'source'      => $source,
            'name'        => $name !== '' ? $name : (string) ($existing['contact_name'] ?? ''),
            'phone'       => $phone !== '' ? $phone : (string) ($existing['contact_phone'] ?? ''),
            'email'       => $email !== '' ? $email : (string) ($existing['contact_email'] ?? ''),
            // Passed apart, not pre-joined: each external CRM has its own field limits
            // (8xCRM caps description at 191 chars), so let each helper decide what fits
            // and in what order. The complete note lives on our own deal regardless.
            'summary'     => $summary,
            'profile'     => $profile,
            'status'      => $status,
            'note_full'   => $note_full,
            'deal_id'     => $deal_id,
            'lead_status' => !empty($existing) ? 'updated' : 'new',
        );

        // mirror the lead onto the user's Google Sheet, if configured
        if (file_exists(APPPATH.'helpers/crm_sheet_helper.php')) {
            $this->CI->load->helper('crm_sheet');
            if (function_exists('crm_sheet_enqueue_lead')) {
                crm_sheet_enqueue_lead($user_id, $mirror);   // queued, not inline — off the reply path
            }
        }

        // SPEC-23: mirror the lead into the external CRM (8xCRM), if configured
        if (file_exists(APPPATH.'helpers/external_crm_helper.php')) {
            $this->CI->load->helper('external_crm');
            if (function_exists('xcrm_store_lead')) {
                xcrm_store_lead($user_id, $mirror);
            }
        }

        return $result;
    }

    protected function t_handoff($context, $args = array())
    {
        $db      = $this->CI->db;
        $now     = date('Y-m-d H:i:s');
        $sub_id  = (int) ($context['subscriber_row_id'] ?? 0);
        $user_id = (int) ($context['user_id'] ?? 0);
        $reason  = trim((string) ($args['reason'] ?? ''));
        $source  = in_array(($context['social_media'] ?? ''), array('fb','ig','wa','web','tg')) ? $context['social_media'] : 'manual';

        if ($sub_id > 0) {
            // pause the bot if SPEC-09 column exists
            $fields = $db->list_fields('messenger_bot_subscriber');
            if (in_array('bot_paused_until', $fields)) {
                $db->where('id', $sub_id)->update('messenger_bot_subscriber', array('bot_paused_until' => date('Y-m-d H:i:s', strtotime('+6 hours'))));
            }
        }

        // Surface the handoff in the CRM: an urgent pending follow-up task
        // (counts in the dashboard "Tasks Due" card) attached to the
        // customer's open deal, creating the deal if they don't have one.
        if ($user_id > 0) {
            $deal_id = null;
            if ($sub_id > 0) {
                $deal = $db->from('crm_deals')->where('user_id', $user_id)->where('subscriber_id', $sub_id)
                           ->where('status', 'open')->order_by('id', 'DESC')->limit(1)->get()->row_array();
                if (!empty($deal)) $deal_id = (int) $deal['id'];
            }
            if ($deal_id === null) {
                $pipe = $db->select('id')->from('crm_pipelines')->where('user_id', $user_id)->where('status', '1')
                           ->order_by('is_default', 'DESC')->order_by('id', 'ASC')->limit(1)->get()->row_array();
                $stage = empty($pipe) ? null : $db->select('id')->from('crm_stages')->where('pipeline_id', $pipe['id'])
                            ->where('stage_type', 'open')->order_by('position', 'ASC')->limit(1)->get()->row_array();
                if (!empty($pipe) && !empty($stage)) {
                    $name = '';
                    if ($sub_id > 0) {
                        $sub = $db->select('first_name, last_name, full_name')->from('messenger_bot_subscriber')->where('id', $sub_id)->get()->row_array();
                        if (!empty($sub)) {
                            $name = trim((string) ($sub['full_name'] ?? ''));
                            if ($name === '') $name = trim(trim((string) ($sub['first_name'] ?? '')).' '.trim((string) ($sub['last_name'] ?? '')));
                        }
                    }
                    $db->insert('crm_deals', array(
                        'user_id' => $user_id, 'pipeline_id' => $pipe['id'], 'stage_id' => $stage['id'],
                        'title' => 'Handoff: '.($name !== '' ? $name : 'customer'),
                        'subscriber_id' => ($sub_id > 0 ? $sub_id : null),
                        'contact_name' => ($name !== '' ? $name : null),
                        'source' => $source, 'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
                    ));
                    $deal_id = (int) $db->insert_id();
                    $db->insert('crm_deal_timeline', array(
                        'deal_id' => $deal_id, 'user_id' => $user_id, 'action' => 'created',
                        'new_value' => 'Human handoff requested via '.$source, 'created_at' => $now,
                    ));
                }
            }
            $db->insert('crm_activities', array(
                'deal_id' => $deal_id, 'subscriber_id' => ($sub_id > 0 ? $sub_id : null), 'user_id' => $user_id,
                'type' => 'follow_up', 'subject' => 'URGENT: customer needs a human ('.$source.')',
                'description' => ($reason !== '' ? $reason : 'Customer requested human assistance.'),
                'due_date' => $now, 'status' => 'pending', 'created_at' => $now,
            ));

            // Real-time alert: a CRM task alone means the owner only finds out if they
            // happen to look. Notify immediately via the same email/WhatsApp channels the
            // digest/gap-alerts use, so an escalated (often angry / high-intent) customer
            // isn't left waiting in the dark. Best-effort; never blocks the reply.
            $this->notify_handoff($user_id, $source, $reason, $deal_id);
        }

        return 'A human agent will take over shortly. Please hold on.';
    }

    /** Fire an owner alert for a human handoff, reusing sales_automation_settings channels. */
    protected function notify_handoff($user_id, $source, $reason, $deal_id)
    {
        try {
            $db = $this->CI->db;
            if (!$db->table_exists('sales_automation_settings')) return;
            $s = $db->from('sales_automation_settings')->where('user_id', (int) $user_id)->get()->row_array();
            if (empty($s)) return;
            // Use the deflection-alert channels if set, else the digest ones.
            $email = trim((string) ($s['deflect_alert_email'] ?? '')) ?: trim((string) ($s['digest_email'] ?? ''));
            $wa    = trim((string) ($s['deflect_alert_whatsapp'] ?? '')) ?: trim((string) ($s['digest_whatsapp'] ?? ''));
            if ($email === '' && $wa === '') return;

            $text = "🔔 عميل محتاج تدخل بشري (" . $source . ")\n\n"
                . ($reason !== '' ? "السبب: " . mb_substr($reason, 0, 200) . "\n" : '')
                . "المحادثة اتوقف فيها البوت. تابعها من: " . site_url('crm/tasks');

            $this->CI->load->helper('channel_send');
            if ($wa !== '' && function_exists('channel_send_text')) {
                @channel_send_text((int) $user_id, 'wa', $wa, $text);
            }
            if ($email !== '' && method_exists($this->CI, '_email_send_function')) {
                @$this->CI->_email_send_function('', nl2br(htmlspecialchars($text)), $email, 'MonkeyBot: عميل محتاج تدخل بشري', '', '', (int) $user_id);
            }
        } catch (Exception $e) {
            log_message('error', 'notify_handoff: ' . $e->getMessage());
        }
    }
}
