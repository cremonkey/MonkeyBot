<?php
/**
 * CLI probe for per-page price lists — TEST STACKS ONLY (never production).
 * Boots the real CodeIgniter app (through `cron_hub migrate`, a no-op once migrations are
 * applied) and then calls the REAL reply-path code for one conversation context:
 *   pricing_resolve_scope -> pricing_get_config -> rule 10 text, the calculate_price
 *   tool definition, and Ai_tools::execute(), plus what landed in ai_price_facts (the
 *   price guard's authoritative source).
 *
 *   docker exec -u www-data <app> php /var/www/html/docker/scripts/pricing_ai_probe.php \
 *       <user_id> <social_media> <page_id> [<tool> <args_json_base64>]
 * Prints one JSON object.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$probe = array_slice($argv, 1);
if (count($probe) < 3) { fwrite(STDERR, "usage: pricing_ai_probe.php <user_id> <social> <page_id> [<tool> <args_b64>]\n"); exit(2); }

$root = dirname(__DIR__, 2);
$_SERVER['argv'] = array('index.php', 'cron_hub', 'migrate');
$_SERVER['argc'] = 3;
ob_start();
require $root . '/index.php';
ob_end_clean();

$CI =& get_instance();
$CI->load->helper(array('pricing', 'media'));
$CI->load->library('Ai_tools');
list($uid, $social, $page) = array((int) $probe[0], (string) $probe[1], (string) $probe[2]);
$ctx = array('user_id' => $uid, 'page_id' => $page, 'subscribe_id' => 'probe', 'social_media' => $social, 'subscriber_row_id' => 0);

$scope = pricing_resolve_scope($uid, $social, $page);
$cfg = pricing_get_config($uid, $scope);
$def = null;
foreach ($CI->ai_tools->catalog($ctx) as $t) if ($t['name'] === 'calculate_price') $def = $t;

$out = array(
    'scope' => pricing_scope_key($scope),
    'has_config' => $cfg !== null,
    'rule10' => pricing_prompt_rule($cfg),
    'tool_enum' => $def ? $def['params']['properties']['type']['enum'] : null,
    'tool_description' => $def ? $def['description'] : null,
);
if (isset($probe[3]) && $probe[3] !== '') {
    $args = json_decode(base64_decode($probe[4] ?? ''), true);
    $CI->ai_price_facts = array();
    $CI->ai_media_facts = array();
    $out['result'] = $CI->ai_tools->execute($probe[3], is_array($args) ? $args : array(), $ctx);
    $out['price_facts'] = $CI->ai_price_facts;
    $out['media_facts'] = $CI->ai_media_facts;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
