<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * 2.0.0 -> 2.0.1
 *  - column `percent_base` on `sj4web_margin_drop_rule` (base of the % drop fee:
 *    'purchase' default, or 'sale')
 *
 * The DDL itself lives in sql/install.php (idempotent, conditional ADD COLUMN for
 * MySQL 8), so this upgrade just replays it.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Sj4webMargeCommande $module
 *
 * @return bool
 */
function upgrade_module_2_0_1($module)
{
    include dirname(__DIR__) . '/sql/install.php';

    return true;
}
