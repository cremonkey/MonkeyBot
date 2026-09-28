<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Loads the CRM connector layer (CI3 has no autoloader for namespaced
 * libraries — same approach as libraries/Channels/bootstrap.php). Safe to
 * include more than once. Also requires the two wrapped helpers directly so
 * their functions exist regardless of whether $this->load->helper() ran first.
 */
$__mb_crm_connectors = __DIR__;

require_once $__mb_crm_connectors . '/../../helpers/external_crm_helper.php';
require_once $__mb_crm_connectors . '/../../helpers/crm_sheet_helper.php';

foreach (array(
    'CrmConnectorInterface.php',
    'ExternalCrmConnector.php',
    'SheetCrmConnector.php',
    'ConnectorRegistry.php',
) as $__mb_file) {
    require_once $__mb_crm_connectors . '/' . $__mb_file;
}
unset($__mb_crm_connectors, $__mb_file);
