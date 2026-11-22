<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * 2.0.2 -> 2.1.0: Agent Bridge (MCP) extension (payment commission and margin tools).
 * Subscribes to the extension hook (idempotent). No schema change.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Sj4webMargeCommande $module
 *
 * @return bool
 */
function upgrade_module_2_1_0($module)
{
    return $module->isRegisteredInHook('actionSj4webAgentbridgeCollectExtensions')
        || $module->registerHook('actionSj4webAgentbridgeCollectExtensions');
}
