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
        return array(
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
                'description' => "Escalate the conversation to a human agent. Use when the customer is angry, asks for a human, or the request is beyond your ability.",
                'params' => array('type' => 'object', 'properties' => new stdClass()),
            ),
        );
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
                case 'handoff_to_human':  return $this->t_handoff($context);
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
        $db->from('ecommerce_cart')->where('user_id', $user_id)
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

    protected function t_handoff($context)
    {
        $sub_id = (int) ($context['subscriber_row_id'] ?? 0);
        if ($sub_id > 0) {
            // pause the bot if SPEC-09 column exists
            $fields = $this->CI->db->list_fields('messenger_bot_subscriber');
            if (in_array('bot_paused_until', $fields)) {
                $this->CI->db->where('id', $sub_id)->update('messenger_bot_subscriber', array('bot_paused_until' => date('Y-m-d H:i:s', strtotime('+6 hours'))));
            }
        }
        return 'A human agent will take over shortly. Please hold on.';
    }
}
