<?php
namespace MonkeyBot\CrmConnectors;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Thin wrapper over application/helpers/external_crm_helper.php (8xCRM, SPEC-23).
 * Delegates every call to the existing helper functions unmodified — see that
 * file's docblock for why the push is queued (crm_external_log) rather than
 * inline, and for the token/retry rules.
 */
class ExternalCrmConnector implements CrmConnectorInterface
{
    public function key(): string
    {
        return '8xcrm';
    }

    public function pushLead(int $user_id, array $lead): array
    {
        return $this->enqueue($user_id, $lead);
    }

    public function pushDealUpdate(int $user_id, array $lead): array
    {
        // xcrm_store_lead already dedupes on deal_id (skips a deal already
        // pending/sent), which is exactly the "don't resend an update" rule.
        return $this->enqueue($user_id, $lead);
    }

    private function enqueue(int $user_id, array $lead): array
    {
        if (!function_exists('xcrm_store_lead')) {
            return array('ok' => false, 'message' => '8xCRM helper not loaded');
        }
        try {
            $ok = xcrm_store_lead($user_id, $lead);
            return $ok
                ? array('ok' => true, 'message' => 'queued')
                : array('ok' => false, 'message' => 'not configured, unconfigured contact, or duplicate deal');
        } catch (\Throwable $e) {
            return array('ok' => false, 'message' => $e->getMessage());
        }
    }

    public function testConnection(int $user_id): array
    {
        if (!function_exists('xcrm_get_config') || !function_exists('xcrm_token')) {
            return array('ok' => false, 'message' => '8xCRM helper not loaded');
        }
        try {
            $config = xcrm_get_config($user_id);
            if (!$config) return array('ok' => false, 'message' => 'External CRM is not configured/enabled.');
            $auth = xcrm_token($config, true);
            return $auth !== null
                ? array('ok' => true, 'message' => 'Connected: token issued.')
                : array('ok' => false, 'message' => $config['last_error'] ?? 'Authentication failed.');
        } catch (\Throwable $e) {
            return array('ok' => false, 'message' => $e->getMessage());
        }
    }
}
