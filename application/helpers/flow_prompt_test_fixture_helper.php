<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * TEST-ONLY escape hatch for docker/scripts/staging_prompt_to_flow_tests.sh (brief §24).
 *
 * This file is only ever loaded from Flow_prompt::generate() when
 * $config['prompt_to_flow_test_fixture'] is truthy, and that config item defaults to FALSE
 * in application/config/config.php and is never set in production. It exists so the staging
 * HTTP+DB suite can exercise the real endpoint (permission check, CSRF, tenant scoping,
 * DB insert, "no messenger_bot row" proof) WITHOUT a real AI provider API key — the same
 * seam tests/prompt_to_flow/run.php uses via Flow_prompt_generator's injectable ai_caller,
 * just reached through a config flag instead of a constructor argument because the HTTP
 * request/response cycle has no other place to inject a PHP callable from outside.
 *
 * Returns the flow JSON TEXT an AI provider would have returned (i.e. what would appear at
 * choices[0].text) — the caller still goes through Flow_prompt_generator's normal parse +
 * Flow_prompt_validator::validate() + fallback pipeline, so this only fakes the network call,
 * nothing about the safety pipeline itself.
 */
function flow_prompt_test_fixture($name)
{
    switch ($name) {
        case 'hotel_text_fallback':
            return json_encode(array('nodes' => array(
                '1' => array('id' => 1, 'data' => array(
                    'xitFbpostbackId' => 'FIXREF', 'title' => 'Hotel booking', 'postbackId' => 'FIXREFP',
                    'triggerKeyword' => 'hotel', 'triggerMatchingType' => 'contains', 'labelIds' => array(),
                ), 'inputs' => array('referenceInput' => array('connections' => array())),
                   'outputs' => array('referenceOutput' => array('connections' => array(array('node' => '2', 'input' => 'userInputFlowInput')))),
                   'pos_x' => 0, 'pos_y' => 0),
                '2' => array('id' => 2, 'data' => array('userInputFlowIdValue' => 'new'),
                    'inputs' => array('userInputFlowInput' => array('connections' => array(array('node' => '1', 'input' => 'referenceOutput')))),
                    'outputs' => array('userInputFlowOutput' => array('connections' => array(array('node' => '3', 'input' => 'userInputFlowSingleInput')))),
                    'pos_x' => 100, 'pos_y' => 0),
                '3' => array('id' => 3, 'data' => array(
                    'questionType' => 'number', 'question' => 'How many guests?', 'replyType' => 'text', 'customField' => 1, 'uniqueId' => 'FIXQ1', 'newPostbackId' => 'FIXEXIT',
                ), 'inputs' => array('userInputFlowSingleInput' => array('connections' => array(array('node' => '2', 'input' => 'userInputFlowOutput')))),
                   'outputs' => array('userInputFlowSingleOutput' => array('connections' => array(array('node' => '4', 'input' => 'textInput')))),
                   'pos_x' => 200, 'pos_y' => 0),
                '4' => array('id' => 4, 'data' => array(
                    'textMessage' => 'Thanks! Our team will confirm your price shortly.', 'uniqueId' => 'FIXU4', 'delayReplyFor' => 0, 'IsTypingOnDisplayChecked' => false,
                ), 'inputs' => array('textInput' => array('connections' => array(array('node' => '3', 'input' => 'userInputFlowSingleOutput')))),
                   'outputs' => array('textOutput' => array('connections' => array())),
                   'pos_x' => 300, 'pos_y' => 0),
            )));

        case 'invalid_json':
            return 'not actually json, on purpose';

        case 'minimal':
        default:
            return json_encode(array('nodes' => array(
                '1' => array('id' => 1, 'data' => array(
                    'xitFbpostbackId' => 'FIXREF', 'title' => 'Greeting', 'postbackId' => 'FIXREFP',
                    'triggerKeyword' => 'hello', 'triggerMatchingType' => 'contains',
                ), 'inputs' => array('referenceInput' => array('connections' => array())),
                   'outputs' => array('referenceOutput' => array('connections' => array(array('node' => '2', 'input' => 'textInput')))),
                   'pos_x' => 0, 'pos_y' => 0),
                '2' => array('id' => 2, 'data' => array(
                    'textMessage' => 'Hi there!', 'uniqueId' => 'FIXU1', 'delayReplyFor' => 0, 'IsTypingOnDisplayChecked' => false,
                ), 'inputs' => array('textInput' => array('connections' => array(array('node' => '1', 'input' => 'referenceOutput')))),
                   'outputs' => array('textOutput' => array('connections' => array())),
                   'pos_x' => 100, 'pos_y' => 0),
            )));
    }
}
