<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * Drops the module's own tables. `order_fees` is intentionally NOT dropped: it is
 * shared infrastructure also written by sj4web_payplugreport.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

$p = _DB_PREFIX_;

foreach ([
    'sj4web_margin_drop_bucket_category',
    'sj4web_margin_drop_bucket',
    'sj4web_margin_drop_step',
    'sj4web_margin_drop_rule',
    'sj4web_order_drop_actual',
    'sj4web_order_margin',
] as $table) {
    Db::getInstance()->execute("DROP TABLE IF EXISTS `{$p}{$table}`;");
}

return true;
