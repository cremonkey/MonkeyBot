<?php
namespace MonkeyBot\CrmConnectors;

defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * One connector per outbound CRM destination (Task 25 / C10, brief §28).
 *
 * These wrap the two queues that already exist — external_crm_helper.php (8xCRM,
 * crm_external_log) and crm_sheet_helper.php (Google Sheets, crm_sheet_config +
 * the same crm_external_log queue with provider='gsheet') — without changing
 * either helper's behaviour, queries or tables. A connector is a thin adapter:
 * it enqueues, it never pushes inline (the reply path must never wait on a
 * CRM), and it never talks to a provider's API directly.
 *
 * Contract:
 * - pushLead()/pushDealUpdate() never throw. A failure to enqueue returns
 *   array('ok' => false, 'message' => <reason>), never a fake success.
 * - testConnection() may do a real network call (it is invoked from an admin
 *   settings screen, not a reply path) but must still never throw.
 */
interface CrmConnectorInterface
{
    /** '8xcrm' | 'gsheet' | ... */
    public function key(): string;

    /**
     * Queue a newly captured lead for this destination.
     * $lead keys mirror what Ai_tools::t_save_lead already builds: source, name,
     * phone, email, summary, profile, status, deal_id, lead_status.
     *
     * @return array{ok: bool, message: string}
     */
    public function pushLead(int $user_id, array $lead): array;

    /**
     * Queue an update to a deal already pushed (e.g. stage/status changed).
     * Both existing helpers dedupe on deal_id inside the same queue table, so
     * this is safe to call again for the same deal.
     *
     * @return array{ok: bool, message: string}
     */
    public function pushDealUpdate(int $user_id, array $lead): array;

    /**
     * Validate the tenant's configuration for this destination end to end
     * (mint a token / read the target). Never throws.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(int $user_id): array;
}
