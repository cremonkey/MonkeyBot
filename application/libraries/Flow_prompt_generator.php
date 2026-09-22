<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__ . '/Flow_prompt_validator.php';

/**
 * Prompt-to-Flow (master brief §24 / §46 / §51) — turns a plain-language instruction into a
 * DRAFT flow definition for the existing Visual Flow Builder.
 *
 * "The AI generates the workflow definition. It does not become the workflow runtime."
 * Concretely: this class only ever INSERTs a row into visual_flow_builder_campaign, exactly
 * like Visual_flow_builder::duplicate_builder_data() does. It never calls
 * Visual_flow_builder's flowbuilder_submit() — the method that atomically publishes a
 * campaign into the live messenger_bot / messenger_bot_postback rows. Nothing goes live
 * until a human opens the draft in the real editor (visual_flow_builder/edit_builder_data)
 * and clicks Save there.
 *
 * DESIGN (mirrors Automation_engine / Automation_gateway on this branch): everything with
 * a side effect — the AI call itself, and which HTTP API ids this tenant is allowed to
 * reference — is taken through small injectable seams so build_draft() can be exercised by
 * tests/prompt_to_flow/run.php with NO CodeIgniter, NO database and NO network. generate()
 * is the only impure method: build_draft() + one DB insert.
 *
 * SAFETY (brief §51, no silent failure):
 *  - Flow_prompt_validator::validate() — never this class's own judgement — decides accept
 *    or reject. The AI's raw output is never trusted directly.
 *  - On invalid JSON or a failed validation, one retry is sent with the validator's own
 *    error message appended, then a structured failure is returned. Never a thrown
 *    exception, never a half-written campaign row.
 *  - The AI is never allowed to invent a price or a number: an httpApi node is only kept if
 *    its httpApiId is one this tenant actually owns (brief: "never let the AI invent a
 *    number"); otherwise it is downgraded to an editable text placeholder and reported back
 *    to the owner in `substitutions` — never silently.
 *  - "Save the lead" / "create a CRM deal": no such node exists anywhere in Visual Flow
 *    Builder (confirmed by full-codebase grep during design). The system prompt tells the
 *    AI to represent that intent as a labelIds:["Lead"] tag on a reference/postback node.
 *    As a second line of defense, if the AI instead marks a node's data with the
 *    `crm_deal_request` escape hatch documented below, it is converted the same way and
 *    reported in `substitutions`.
 */
class Flow_prompt_generator
{
    /** total attempts at getting valid JSON from the AI, including the first. */
    const MAX_ATTEMPTS = 2;

    /** @var callable|null function($config_row, array $messages, array $overrides): string (raw JSON, Ai_provider::completion() shape) */
    protected $ai_caller;

    /** @var callable|null function(int $user_id): int[] — active settings_http_apis ids owned by the tenant */
    protected $http_api_lookup;

    public function __construct($params = array())
    {
        $this->ai_caller = (isset($params['ai_caller']) && is_callable($params['ai_caller'])) ? $params['ai_caller'] : null;
        $this->http_api_lookup = (isset($params['http_api_lookup']) && is_callable($params['http_api_lookup'])) ? $params['http_api_lookup'] : null;
    }

    // ================================================================== pure

    /**
     * AI call + parse + validate (with one error-correction retry) + safe fallback
     * substitutions + fresh unique ids. No database, no network beyond the injected caller.
     *
     * @param string $instruction   the owner's plain-language instruction
     * @param mixed  $config_row    passed through to the AI caller unchanged (Ai_provider's $config_row)
     * @param int[]  $http_api_ids  active settings_http_apis ids this tenant owns (may be empty)
     * @return array{ok:bool, nodes:array|null, message:string, substitutions:array}
     */
    public function build_draft($instruction, $config_row, array $http_api_ids = array())
    {
        $instruction = trim((string) $instruction);
        if ($instruction === '') {
            return array('ok' => false, 'nodes' => null, 'message' => 'Please describe what you want the bot to do.', 'substitutions' => array());
        }

        $system = $this->system_prompt($http_api_ids);
        $messages = array(array('role' => 'user', 'content' => $instruction));

        $raw = $this->call_ai($config_row, $messages, array(
            'system' => $system, 'max_tokens' => 2200, 'temperature' => 0.3, 'purpose' => 'flow_generation',
        ));
        list($decoded, $err) = $this->parse($raw);
        if ($decoded !== null) list($valid, $err) = Flow_prompt_validator::validate($decoded, null);
        else $valid = false;

        if (!$valid) {
            // one error-correction retry, telling the model exactly what was wrong.
            $messages[] = array('role' => 'assistant', 'content' => (string) $this->extract_text($raw));
            $messages[] = array('role' => 'user', 'content' =>
                "That JSON was rejected: {$err}\nReply again with ONLY corrected JSON matching the schema above. No prose, no markdown fences.");
            $raw2 = $this->call_ai($config_row, $messages, array(
                'system' => $system, 'max_tokens' => 2200, 'temperature' => 0.1, 'purpose' => 'flow_generation',
            ));
            list($decoded, $err2) = $this->parse($raw2);
            if ($decoded !== null) list($valid, $err2) = Flow_prompt_validator::validate($decoded, null);
            else $valid = false;
            if (!$valid) {
                return array(
                    'ok' => false, 'nodes' => null,
                    'message' => 'We could not turn that into a usable flow. ' . $err2 . ' Try rephrasing, or build it manually in the flow editor.',
                    'substitutions' => array(),
                );
            }
        }

        list($nodes, $substitutions) = $this->apply_safe_fallbacks($decoded['nodes'], $http_api_ids);

        // re-validate after the fallback rewrite — it must still be a legal v1 flow.
        list($valid2, $err3) = Flow_prompt_validator::validate(array('nodes' => $nodes), $http_api_ids);
        if (!$valid2) {
            return array('ok' => false, 'nodes' => null, 'message' => 'Generation produced an invalid flow after safety fallbacks: ' . $err3, 'substitutions' => array());
        }

        $nodes = $this->regenerate_unique_ids($nodes);

        return array('ok' => true, 'nodes' => $nodes, 'message' => '', 'substitutions' => $substitutions);
    }

    /**
     * Safety net (brief: "never let the AI invent a number" / "no CRM-deal node exists"):
     *  - a text node whose data.requestedCrmDeal/crm_deal_request marker is truthy is
     *    converted to a labelIds:["Lead"] tag on itself (or, when it is not a
     *    reference/postback node, on nothing — the marker is stripped and reported).
     *  - an httpApi node whose httpApiId is NOT in $http_api_ids is downgraded to the
     *    documented price-placeholder text node, in place, preserving the graph.
     * Every rewrite is appended to $substitutions in owner-readable language — never hidden.
     */
    protected function apply_safe_fallbacks(array $nodes, array $http_api_ids)
    {
        $substitutions = array();
        $port_rewrites = array(); // node id => array(old input port => new input port), for downgraded nodes

        foreach ($nodes as $id => &$node) {
            $data = isset($node['data']) && is_array($node['data']) ? $node['data'] : array();
            $inputs = isset($node['inputs']) && is_array($node['inputs']) ? $node['inputs'] : array();
            $input_keys = array_keys($inputs);
            $port = !empty($input_keys) ? $input_keys[0] : ((string) $id === '1' ? 'referenceInput' : null);
            $type = $port !== null && isset(Flow_prompt_validator::INPUT_PORT_TYPE[$port]) ? Flow_prompt_validator::INPUT_PORT_TYPE[$port] : null;
            if ($port === 'referenceInput' && $type === null) $type = 'reference'; // node "1"

            // --- CRM-deal escape hatch: fold into a labelIds tag instead.
            if (!empty($data['crm_deal_request']) || !empty($data['requestedCrmDeal'])) {
                unset($data['crm_deal_request'], $data['requestedCrmDeal']);
                if (in_array($type, array('reference', 'postback'), true)) {
                    $labels = isset($data['labelIds']) && is_array($data['labelIds']) ? $data['labelIds'] : array();
                    if (!in_array('Lead', $labels, true)) $labels[] = 'Lead';
                    $data['labelIds'] = $labels;
                    $substitutions[] = 'No "create a CRM deal" step exists in the flow builder. Node "' . $id . '" was tagged with the "Lead" label instead — an Automations rule watching that label can create the CRM deal; that rule needs to be set up separately.';
                } else {
                    $substitutions[] = 'A "create a CRM deal" request on node "' . $id . '" could not be safely represented and was dropped. Tag the customer with a label manually where you want the deal created.';
                }
                $node['data'] = $data;
            }

            // --- httpApi ownership fallback: downgrade to the documented placeholder.
            if ($type === 'httpApi') {
                $id_ok = isset($data['httpApiId']) && is_numeric($data['httpApiId']) && in_array((int) $data['httpApiId'], $http_api_ids, true);
                if (!$id_ok) {
                    $substitutions[] = 'Node "' . $id . '" asked for a price/lookup step but no connected HTTP API was available for this account. Replaced with an editable placeholder message — connect it to Products & Pricing or an HTTP API step, then update the copy.';
                    $node['data'] = array(
                        'textMessage' => self::PRICE_PLACEHOLDER,
                        'uniqueId' => '', // filled by regenerate_unique_ids()
                        'delayReplyFor' => 0,
                        'IsTypingOnDisplayChecked' => false,
                    );
                    $node['inputs'] = array('textInput' => isset($inputs['httpApiInput']) ? $inputs['httpApiInput'] : array('connections' => array()));
                    $outputs = isset($node['outputs']) && is_array($node['outputs']) ? $node['outputs'] : array();
                    $old_out = isset($outputs['httpApiOutput']) ? $outputs['httpApiOutput'] : array('connections' => array());
                    $node['outputs'] = array('textOutput' => $old_out);
                    $port_rewrites[$id] = array('httpApiInput' => 'textInput');
                }
            }
        }
        unset($node);

        // fix stale upstream connections: any OTHER node's outputs pointing at a downgraded
        // node with the old port name must now say the new one, or the graph is inconsistent.
        if (!empty($port_rewrites)) {
            foreach ($nodes as &$node) {
                if (!isset($node['outputs']) || !is_array($node['outputs'])) continue;
                foreach ($node['outputs'] as &$port_def) {
                    if (empty($port_def['connections'])) continue;
                    foreach ($port_def['connections'] as &$conn) {
                        if (!isset($conn['node'], $conn['input'])) continue;
                        $target = (string) $conn['node'];
                        if (isset($port_rewrites[$target][$conn['input']])) {
                            $conn['input'] = $port_rewrites[$target][$conn['input']];
                        }
                    }
                    unset($conn);
                }
                unset($port_def);
            }
            unset($node);
        }

        return array($nodes, $substitutions);
    }

    const PRICE_PLACEHOLDER = '[Price - connect this to Products & Pricing / an HTTP API step; team to configure]';

    /**
     * Mirrors Home::updateArrayValues(): walk the whole structure and replace every value of
     * a UNIQUE_FIELDS key. bin2hex(random_bytes(8)) instead of uniqid() — never trust
     * anything the AI chose for these, even if it looked unique (brief: "generate them
     * yourself ... never let the AI choose these").
     */
    protected function regenerate_unique_ids($nodes)
    {
        array_walk_recursive_keys($nodes, Flow_prompt_validator::UNIQUE_FIELDS);
        return $nodes;
    }

    // ================================================================ impure

    /**
     * build_draft() + the one side effect: INSERT into visual_flow_builder_campaign, exactly
     * like Visual_flow_builder::duplicate_builder_data(). Never calls flowbuilder_submit().
     *
     * @return array{status:int, id:int|null, edit_url:string|null, message:string, substitutions:array}
     */
    public function generate($user_id, $page_table_id, $media_type, $instruction, $config_row)
    {
        $http_api_ids = $this->lookup_http_api_ids((int) $user_id);
        $draft = $this->build_draft($instruction, $config_row, $http_api_ids);
        if (!$draft['ok']) {
            return array('status' => 0, 'id' => null, 'edit_url' => null, 'message' => $draft['message'], 'substitutions' => array());
        }

        $nodes = $draft['nodes'];
        $node1 = isset($nodes['1']) ? $nodes['1'] : $nodes[1];
        $reference_name = $this->derive_reference_name($instruction);
        $node1['data']['title'] = $reference_name;
        $nodes['1'] = $node1;

        $json_data = json_encode(array('nodes' => $nodes), JSON_UNESCAPED_UNICODE);
        $unique_id = isset($node1['data']['xitFbpostbackId']) ? $node1['data']['xitFbpostbackId'] : '';

        $CI = &get_instance();
        $insert_data = array(
            'user_id'        => (int) $user_id,
            'is_system'      => '0',
            'action_type'    => 'reply',
            'page_id'        => $page_table_id,
            'json_data'      => $json_data,
            'reference_name' => $reference_name,
            'unique_id'      => $unique_id,
            'media_type'     => $media_type,
        );
        $CI->basic->insert_data('visual_flow_builder_campaign', $insert_data);
        $id = (int) $CI->db->insert_id();

        return array(
            'status'   => 1,
            'id'       => $id,
            'edit_url' => 'visual_flow_builder/edit_builder_data/' . $id . '/1/' . $media_type,
            'message'  => 'Draft ready — nothing is live yet. Open it to review and save.',
            'substitutions' => $draft['substitutions'],
        );
    }

    /** application/helpers/http_api's owning table; ownership check per brief §24 for httpApi nodes. */
    protected function lookup_http_api_ids($user_id)
    {
        if ($this->http_api_lookup !== null) return call_user_func($this->http_api_lookup, $user_id);
        $CI = &get_instance();
        if (!$CI->db->table_exists('settings_http_apis')) return array();
        $rows = $CI->db->select('id')->from('settings_http_apis')->where('user_id', (int) $user_id)->where('status', '1')->get()->result_array();
        return array_map('intval', array_column($rows, 'id'));
    }

    protected function call_ai($config_row, array $messages, array $overrides)
    {
        if ($this->ai_caller !== null) return call_user_func($this->ai_caller, $config_row, $messages, $overrides);
        $CI = &get_instance();
        $CI->load->library('Ai_provider');
        return $CI->ai_provider->completion($config_row, $messages, $overrides);
    }

    protected function parse($raw)
    {
        $text = $this->extract_text($raw);
        if ($text === null) return array(null, 'the AI call failed or returned no text.');
        $text = trim($text);
        // tolerate a ```json ... ``` fence — models do this even when told not to.
        if (strpos($text, '```') !== false) {
            $text = preg_replace('/^```[a-zA-Z]*\s*/m', '', $text);
            $text = str_replace('```', '', $text);
            $text = trim($text);
        }
        $decoded = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return array(null, 'the AI did not return valid JSON (' . json_last_error_msg() . ').');
        }
        return array($decoded, '');
    }

    /** Ai_provider::completion() always normalizes to choices[0].text, whichever provider ran. */
    protected function extract_text($raw)
    {
        $dec = json_decode((string) $raw, true);
        if (!is_array($dec)) return null;
        if (isset($dec['error'])) return null;
        if (isset($dec['choices'][0]['text'])) return (string) $dec['choices'][0]['text'];
        return null;
    }

    protected function derive_reference_name($instruction)
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $instruction));
        if ($name === '') $name = 'AI-generated flow';
        if (mb_strlen($name) > 80) $name = mb_substr($name, 0, 77) . '...';
        return $name;
    }

    /**
     * The v1 vocabulary this AI is allowed to speak, and nothing else. Field names match
     * Flow_prompt_validator / the real Visual Flow Builder exactly — see docs/automations/
     * prompt-to-flow.md for the authoritative, versioned copy of this contract.
     */
    public function system_prompt(array $http_api_ids = array())
    {
        $http_api_line = empty($http_api_ids)
            ? "This account has NO configured HTTP API. If a price or lookup step is needed, do NOT invent an httpApi node. Instead output a text node whose textMessage is EXACTLY: \"" . self::PRICE_PLACEHOLDER . "\""
            : "This account has an HTTP API configured with id " . (int) $http_api_ids[0] . ". If a price or lookup step is clearly needed, you MAY output an httpApi node with data.httpApiId = " . (int) $http_api_ids[0] . ".";

        return <<<PROMPT
You generate a DRAFT chatbot flow for Monkey Bot's Visual Flow Builder from a plain-language
instruction. A human will review and edit this draft before anything goes live — you are not
the runtime, you only produce a structured definition.

Output ONLY a single JSON object, no prose, no markdown code fences. Shape:
{"nodes": {"1": {...}, "2": {...}, ...}}

Every node: {"id": <int matching its own key>, "data": {...fields for its type...},
"inputs": {"<portName>": {"connections": [{"node": "<targetId>", "input": "<targetPortName>"}]}},
"outputs": {"<portName>": {"connections": [{"node": "<targetId>", "input": "<targetPortName>"}]}},
"pos_x": <int>, "pos_y": <int>}

A node's type is which input port it declares — there is no "type" field. Use ONLY these
ports and fields (anything else is rejected):

- reference (node "1" ONLY; input referenceInput; output referenceOutput): data.xitFbpostbackId
  (any placeholder string, it will be regenerated), data.title, data.postbackId, data.triggerKeyword,
  data.triggerMatchingType, data.labelIds (optional array of label names).
- postback (a second entry point reached from a quickReply's new_post_back; input
  newPostbackInput; output newPostbackOutput): data.title, data.postbackId, data.labelIds (optional).
- text (input textInput; outputs textOutput / textOutputButton / textOutputQuickreply):
  data.textMessage, data.uniqueId, data.delayReplyFor (0), data.IsTypingOnDisplayChecked (false).
- quickReply (input quickReplyInput; output quickReplyOutput): data.postbackId, data.buttonText,
  data.replyType ("post_back" or "new_post_back"), data.newPostbackId (only when replyType is
  new_post_back, pointing at a postback node).
- userInputFlow (asks a question, saves it to a custom field; input userInputFlowInput; output
  userInputFlowOutput): data.userInputFlowIdValue MUST be "new" (define questions inline, never
  reference a saved flow). Chain questions via output userInputFlowSingleOutput, each question
  node: data.questionType (e.g. "text", "email", "phone", "number", "date"), data.question,
  data.replyType, data.customField, and on the LAST question data.newPostbackId to exit the chain.
- condition (branch; input conditionInput; outputs conditionOutputTrue / conditionOutputFalse):
  data.all_match (true = AND) or data.any_match (true = OR) — set exactly one to true.
  data.custom_field_variable[], data.custom_field_operator[], data.custom_field_variable_value[]
  are PARALLEL arrays, one rule per index, non-empty. Operator MUST be one of exactly: equal,
  not_equal, less_than, greater_than, less_than_or_equal, greater_than_or_equal, contains,
  in_array, start_with, end_with, has_value.
- httpApi (input httpApiInput; output httpApiOutput): data.httpApiId (integer). {$http_api_line}

Never invent a price, a number, a policy, or a fact the instruction did not give you. Keep all
message copy generic, brief, and obviously editable placeholder text the owner is expected to
personalize before saving.

There is no "create a CRM deal" node anywhere in this product. If the instruction asks you to
save the lead / create a deal / flag someone as a customer, do NOT invent a node for it —
instead set data.labelIds on the relevant reference or postback node to include "Lead" (or a
more specific label the instruction implies, like "Hotel booking").

Every postbackId, xitFbpostbackId, newPostbackId and uniqueId value must be unique within this
flow, but you may use any placeholder string — the system regenerates every one of these
before saving, so do not spend effort making them look random.

A node with no outgoing connection on its main output port is a valid, implicit end of that
branch — there is no explicit "end" node type.
PROMPT;
    }
}

/**
 * Recursively replaces the value of every $keys-named key found anywhere under $arr, in
 * place, with a fresh bin2hex(random_bytes(8)) string — but only where the existing value is
 * a non-empty string (an empty slot the AI left blank stays blank; nothing to regenerate).
 */
if (!function_exists('array_walk_recursive_keys')) {
    function array_walk_recursive_keys(&$arr, array $keys)
    {
        if (!is_array($arr)) return;
        foreach ($arr as $k => &$v) {
            if (is_array($v)) {
                array_walk_recursive_keys($v, $keys);
            } elseif (in_array($k, $keys, true) && $v !== '' && $v !== null) {
                $v = bin2hex(random_bytes(8));
            }
        }
        unset($v);
    }
}
