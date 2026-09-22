<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Prompt-to-Flow (brief §24) — the deterministic schema validator.
 *
 * This is the ONLY thing that decides whether an AI-generated flow definition is accepted.
 * The AI's output is never trusted directly (brief §51: no silent failure, and the master
 * brief's "the AI generates the workflow definition, it does not become the workflow
 * runtime" — a validator bug that accepts garbage would let the AI become the runtime by
 * the back door).
 *
 * A node's TYPE is identified by which v1 INPUT port name it declares (exactly like the
 * real Visual Flow Builder editor and application/libraries/Flow_builder.php do — see
 * Flow_builder::$input_output_array), never by an explicit "type" field, because the real
 * json_data shape has none. Node "1" is the only node allowed to be classified by its id
 * alone when its own inputs are empty (it is the entry point; nothing connects into it).
 *
 * v1 vocabulary (confirmed against application/libraries/Flow_builder.php and
 * application/modules/visual_flow_builder/controllers/Visual_flow_builder.php on this
 * branch): reference, postback, text, quickReply, userInputFlow (+ its question chain),
 * condition, httpApi. Everything else (carousel, otn, rcn, sequence, generic template,
 * image/video/audio/file media, openai node, ecommerce, ...) is OUT OF SCOPE for v1 and is
 * rejected — see docs/automations/prompt-to-flow.md for the full out-of-scope list and how
 * to extend this vocabulary later.
 */
class Flow_prompt_validator
{
    /** input port name => v1 node type. The ONLY source of truth for "what type is this node". */
    const INPUT_PORT_TYPE = array(
        'referenceInput'        => 'reference',
        'newPostbackInput'      => 'postback',
        'textInput'             => 'text',
        'quickReplyInput'       => 'quickReply',
        'userInputFlowInput'    => 'userInputFlow',
        'userInputFlowSingleInput' => 'userInputFlowQuestion', // a question inside the chain
        'conditionInput'        => 'condition',
        'httpApiInput'          => 'httpApi',
    );

    /** every port name (input or output side) the v1 vocabulary is allowed to use. */
    const ALLOWED_PORTS = array(
        'referenceInput', 'referenceOutput',
        'newPostbackInput', 'newPostbackOutput',
        'textInput', 'textOutput', 'textOutputButton', 'textOutputQuickreply',
        'quickReplyInput', 'quickReplyOutput',
        'userInputFlowInput', 'userInputFlowOutput',
        'userInputFlowSingleInput', 'userInputFlowSingleOutput',
        'conditionInput', 'conditionOutputTrue', 'conditionOutputFalse',
        'httpApiInput', 'httpApiOutput',
    );

    /** exact operator strings condition_check() (application/helpers/my_helper.php) accepts. */
    const OPERATORS = array(
        'equal', 'not_equal', 'less_than', 'greater_than',
        'less_than_or_equal', 'greater_than_or_equal',
        'contains', 'in_array', 'start_with', 'end_with', 'has_value',
    );

    /** the four fields that must be locally unique within one flow (mirrors Home::updateArrayValues()). */
    const UNIQUE_FIELDS = array('postbackId', 'xitFbpostbackId', 'newPostbackId', 'uniqueId');

    /**
     * @param array      $decoded            json_decode($raw, true) result
     * @param array|null $allowed_http_api_ids  when given, every httpApi node's data.httpApiId
     *                                          must be in this list (tenant ownership). Null skips
     *                                          that check (used for pure structural validation).
     * @return array array(bool $ok, string $error) — $error is '' when $ok is true.
     */
    public static function validate($decoded, $allowed_http_api_ids = null)
    {
        if (!is_array($decoded) || !isset($decoded['nodes']) || !is_array($decoded['nodes']) || empty($decoded['nodes'])) {
            return array(false, 'the flow has no "nodes" object, or it is empty.');
        }
        $nodes = $decoded['nodes'];

        if (!isset($nodes['1']) && !isset($nodes[1])) {
            return array(false, 'node "1" (the entry/reference node) is missing.');
        }
        $node1 = isset($nodes['1']) ? $nodes['1'] : $nodes[1];
        if (!is_array($node1) || !isset($node1['data']) || !is_array($node1['data'])) {
            return array(false, 'node "1" has no data.');
        }
        if (!array_key_exists('xitFbpostbackId', $node1['data']) || !array_key_exists('title', $node1['data'])) {
            return array(false, 'node "1" must look like a reference node (xitFbpostbackId and title).');
        }

        // ---- pass 1: every node has a recognized v1 type, and every port name is allowed.
        $types = array();
        foreach ($nodes as $id => $node) {
            if (!is_array($node)) return array(false, "node \"$id\" is not an object.");
            $inputs = isset($node['inputs']) && is_array($node['inputs']) ? $node['inputs'] : array();
            $outputs = isset($node['outputs']) && is_array($node['outputs']) ? $node['outputs'] : array();

            foreach (array_keys($inputs) as $p) {
                if (!in_array($p, self::ALLOWED_PORTS, true)) return array(false, "node \"$id\" uses an unsupported input port \"$p\" (out of scope for v1).");
            }
            foreach (array_keys($outputs) as $p) {
                if (!in_array($p, self::ALLOWED_PORTS, true)) return array(false, "node \"$id\" uses an unsupported output port \"$p\" (out of scope for v1).");
            }

            $input_keys = array_keys($inputs);
            if (empty($input_keys)) {
                if ((string) $id !== '1') return array(false, "node \"$id\" has no input port, so its type cannot be identified.");
                $type = 'reference';
            } else {
                if (count($input_keys) > 1) return array(false, "node \"$id\" declares more than one input port; a v1 node has exactly one.");
                $port = $input_keys[0];
                if (!isset(self::INPUT_PORT_TYPE[$port])) return array(false, "node \"$id\" has an unknown node type (input port \"$port\").");
                $type = self::INPUT_PORT_TYPE[$port];
            }
            $types[$id] = $type;

            // every connection's declared target port name must also be an allowed v1 port.
            foreach (array_merge($inputs, $outputs) as $port_name => $port_def) {
                if (!is_array($port_def) || empty($port_def['connections'])) continue;
                foreach ($port_def['connections'] as $conn) {
                    if (!is_array($conn)) return array(false, "node \"$id\" port \"$port_name\" has a malformed connection.");
                    if (isset($conn['input']) && !in_array($conn['input'], self::ALLOWED_PORTS, true)) {
                        return array(false, "node \"$id\" connects to unsupported port \"{$conn['input']}\" (out of scope for v1).");
                    }
                    if (!isset($conn['node']) || (!isset($nodes[(string) $conn['node']]) && !isset($nodes[$conn['node']]))) {
                        $target = isset($conn['node']) ? $conn['node'] : '(missing)';
                        return array(false, "node \"$id\" connects to node \"$target\", which does not exist.");
                    }
                }
            }
        }

        // ---- pass 2: per-type structural requirements.
        foreach ($nodes as $id => $node) {
            $data = isset($node['data']) && is_array($node['data']) ? $node['data'] : array();
            switch ($types[$id]) {
                case 'text':
                    if (!array_key_exists('textMessage', $data)) return array(false, "text node \"$id\" is missing textMessage.");
                    if (!array_key_exists('uniqueId', $data)) return array(false, "text node \"$id\" is missing uniqueId.");
                    break;
                case 'quickReply':
                    if (!array_key_exists('buttonText', $data)) return array(false, "quickReply node \"$id\" is missing buttonText.");
                    if (!array_key_exists('replyType', $data)) return array(false, "quickReply node \"$id\" is missing replyType.");
                    if (!in_array(isset($data['replyType']) ? $data['replyType'] : null, array('post_back', 'new_post_back'), true)) {
                        return array(false, "quickReply node \"$id\" has an invalid replyType (must be post_back or new_post_back).");
                    }
                    break;
                case 'postback':
                    if (!array_key_exists('postbackId', $data) || !array_key_exists('title', $data)) {
                        return array(false, "postback node \"$id\" must have postbackId and title.");
                    }
                    break;
                case 'userInputFlow':
                    if (!isset($data['userInputFlowIdValue']) || $data['userInputFlowIdValue'] !== 'new') {
                        return array(false, "userInputFlow node \"$id\" must define its questions inline (userInputFlowIdValue='new'); reusing a saved flow is out of scope for v1.");
                    }
                    break;
                case 'userInputFlowQuestion':
                    if (!array_key_exists('questionType', $data) || !array_key_exists('question', $data)) {
                        return array(false, "userInputFlow question node \"$id\" must have questionType and question.");
                    }
                    break;
                case 'condition':
                    list($cok, $cerr) = self::validate_condition($id, $data);
                    if (!$cok) return array(false, $cerr);
                    break;
                case 'httpApi':
                    if (!isset($data['httpApiId']) || !is_numeric($data['httpApiId'])) {
                        return array(false, "httpApi node \"$id\" must have a numeric httpApiId.");
                    }
                    if ($allowed_http_api_ids !== null && !in_array((int) $data['httpApiId'], $allowed_http_api_ids, true)) {
                        return array(false, "httpApi node \"$id\" references an HTTP API this account does not own.");
                    }
                    break;
            }
            if (isset($data['labelIds']) && !is_array($data['labelIds'])) {
                return array(false, "node \"$id\" labelIds must be an array.");
            }
        }

        // ---- pass 3: uniqueness of the four id fields, within this flow.
        $seen = array();
        self::collect_unique_values($nodes, $seen);
        foreach ($seen as $value => $count) {
            if ($count > 1) return array(false, "duplicate unique-id field value: \"$value\" is used $count times.");
        }

        return array(true, '');
    }

    protected static function validate_condition($id, $data)
    {
        $all_match = !empty($data['all_match']);
        $any_match = !empty($data['any_match']);
        if (!$all_match && !$any_match) return array(false, "condition node \"$id\" must set all_match or any_match.");

        foreach (array('custom_field_variable', 'custom_field_operator', 'custom_field_variable_value') as $k) {
            if (!isset($data[$k]) || !is_array($data[$k]) || empty($data[$k])) {
                return array(false, "condition node \"$id\" has an empty or missing $k.");
            }
        }
        $n = count($data['custom_field_variable']);
        if (count($data['custom_field_operator']) !== $n || count($data['custom_field_variable_value']) !== $n) {
            return array(false, "condition node \"$id\" rule arrays are not parallel (different lengths).");
        }
        foreach ($data['custom_field_operator'] as $op) {
            if (!in_array($op, self::OPERATORS, true)) {
                return array(false, "condition node \"$id\" uses an unknown operator \"$op\".");
            }
        }
        return array(true, '');
    }

    /** Recursively collects counts of every non-empty value under a UNIQUE_FIELDS key. */
    protected static function collect_unique_values($node, array &$seen)
    {
        if (!is_array($node)) return;
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                self::collect_unique_values($value, $seen);
            } elseif (in_array($key, self::UNIQUE_FIELDS, true) && $value !== '' && $value !== null) {
                $seen[$value] = isset($seen[$value]) ? $seen[$value] + 1 : 1;
            }
        }
    }
}
