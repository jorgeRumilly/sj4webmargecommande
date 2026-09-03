<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * 2.0.1 -> 2.0.2
 *  - Gift cards & refund vouchers are treated as payment means, not discounts:
 *    their HT amount is added back to the order revenue.
 *  - An order that *sells* a gift card is turnover-neutral (those lines are excluded
 *    from revenue and cost) — driven by config key SJ4WEB_MARGIN_GIFTCARD_PRODUCTS.
 *  - New columns on `sj4web_order_margin`: store_credit_ht, gift_card_sales_ht,
 *    has_store_credit.
 *
 * DDL lives in sql/install.php (idempotent, conditional ADD COLUMN for MySQL 8), so
 * this upgrade replays it and seeds the new config default.
 *
 * ⚠ Rebuild the margin cache ("Rebuild all") after this upgrade: the formula changed.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Sj4webMargeCommande $module
 *
 * @return bool
 */
function upgrade_module_2_0_2($module)
{
    require_once dirname(__DIR__) . '/classes/MarginConfig.php';

    include dirname(__DIR__) . '/sql/install.php';

    MarginConfig::installDefaults();

    return true;
}
