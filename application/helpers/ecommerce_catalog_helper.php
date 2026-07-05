<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 | SPEC-05 — In-chat product catalog helpers.
 | ecom_catalog_products(): ownership-scoped product search with buy links + images.
 | ecom_catalog_elements(): Facebook generic-template elements (carousel) for a future
 |   webhook send-path integration (kept ready; the AI tool uses ecom_catalog_products today).
 */

if (!function_exists('ecom_catalog_products')) {
    function ecom_catalog_products($user_id, $query = '', $limit = 5, $store_id = null)
    {
        $CI =& get_instance();
        $CI->db->select('p.id, p.product_name, p.sell_price, p.original_price, p.thumbnail, p.stock_item, p.stock_prevent_purchase, s.store_unique_id, s.store_name')
               ->from('ecommerce_product p')->join('ecommerce_store s', 's.id = p.store_id', 'left')
               ->where('p.user_id', (int)$user_id)->where('p.status', '1')->where('p.deleted', '0');
        if ($store_id) $CI->db->where('p.store_id', (int)$store_id);
        if ($query !== '') $CI->db->group_start()->like('p.product_name', $query)->or_like('p.product_description', $query)->group_end();
        $rows = $CI->db->limit((int)$limit)->get()->result_array();
        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'id' => $r['id'], 'name' => $r['product_name'],
                'price' => $r['sell_price'], 'original_price' => $r['original_price'],
                'image' => $r['thumbnail'] ? base_url('upload/ecommerce/'.$r['thumbnail']) : '',
                'url' => $r['store_unique_id'] ? base_url('ecommerce/store/'.$r['store_unique_id']) : base_url(),
                'store' => $r['store_name'],
                'in_stock' => !($r['stock_prevent_purchase'] === '1' && (int)$r['stock_item'] <= 0),
            );
        }
        return $out;
    }
}

if (!function_exists('ecom_catalog_elements')) {
    // Facebook Messenger generic-template elements (max 10). Ready for webhook send integration.
    function ecom_catalog_elements($user_id, $query = '', $limit = 10, $store_id = null)
    {
        $products = ecom_catalog_products($user_id, $query, min($limit, 10), $store_id);
        $elements = array();
        foreach ($products as $p) {
            $subtitle = $p['price'];
            if (!empty($p['original_price']) && $p['original_price'] > $p['price']) $subtitle .= ' (was '.$p['original_price'].')';
            if (!$p['in_stock']) $subtitle .= ' — out of stock';
            $el = array(
                'title' => mb_substr($p['name'], 0, 80),
                'subtitle' => (string) $subtitle,
                'buttons' => array(
                    array('type'=>'postback', 'title'=>'🛒 Add to Cart', 'payload'=>'ECOM_ADDCART_'.$p['id']),
                    array('type'=>'web_url', 'title'=>'View', 'url'=>$p['url']),
                ),
            );
            if ($p['image']) $el['image_url'] = $p['image'];
            $elements[] = $el;
        }
        return $elements;
    }
}

if (!function_exists('ecom_catalog_text')) {
    // Plain-text catalog (with links) for AI replies / channels without carousels.
    function ecom_catalog_text($user_id, $query = '', $limit = 5)
    {
        $products = ecom_catalog_products($user_id, $query, $limit);
        if (empty($products)) return '';
        $lines = array();
        foreach ($products as $p) {
            $line = '• '.$p['name'].' — '.$p['price'];
            if (!$p['in_stock']) $line .= ' (out of stock)';
            $line .= "\n  ".$p['url'];
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }
}
