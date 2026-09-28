<?php
namespace MonkeyBot\CrmConnectors;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Thin wrapper over application/helpers/crm_sheet_helper.php (Google Sheets).
 * Delegates every call to the existing helper functions unmodified. Leads are
 * enqueued into the same crm_external_log queue as 8xCRM (provider='gsheet'),
 * drained by the same Cron_hub job — see crm_sheet_helper.php's docblock.
 */
class SheetCrmConnector implements CrmConnectorInterface
{
    public function key(): string
    {
        return 'gsheet';
    }

    public function pushLead(int $user_id, array $lead): array
    {
        return $this->enqueue($user_id, $lead);
    }

    public function pushDealUpdate(int $user_id, array $lead): array
    {
        // crm_sheet_enqueue_lead already dedupes on deal_id for provider='gsheet'.
        return $this->enqueue($user_id, $lead);
    }

    private function enqueue(int $user_id, array $lead): array
    {
        if (!function_exists('crm_sheet_enqueue_lead')) {
            return array('ok' => false, 'message' => 'Sheet helper not loaded');
        }
        try {
            $ok = crm_sheet_enqueue_lead($user_id, $lead);
            return $ok
                ? array('ok' => true, 'message' => 'queued')
                : array('ok' => false, 'message' => 'not configured or duplicate deal');
        } catch (\Throwable $e) {
            return array('ok' => false, 'message' => $e->getMessage());
        }
    }

    public function testConnection(int $user_id): array
    {
        if (!function_exists('crm_sheet_get_config') || !function_exists('crm_sheet_test')) {
            return array('ok' => false, 'message' => 'Sheet helper not loaded');
        }
        try {
            $config = crm_sheet_get_config($user_id);
            if (!$config) return array('ok' => false, 'message' => 'Google Sheet sync is not configured/enabled.');
            list($ok, $message) = crm_sheet_test($config);
            return array('ok' => (bool) $ok, 'message' => (string) $message);
        } catch (\Throwable $e) {
            return array('ok' => false, 'message' => $e->getMessage());
        }
    }
}
