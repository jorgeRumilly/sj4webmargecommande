<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * Idempotent + self-upgrading schema. Included by installDB() (install, upgrade and
 * every getContent() load), so the schema is always brought up to date.
 *
 * Tables:
 *  - sj4web_margin_drop_rule (+ _step / _bucket / _bucket_category) : per-supplier rules
 *  - sj4web_order_drop_actual : real shipping cost paid, per order per supplier
 *  - sj4web_order_margin      : precomputed per-order margin (BO list source)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

$engine = _MYSQL_ENGINE_;
$p = _DB_PREFIX_;
$db = Db::getInstance();

/**
 * Add a column only if it is missing (MySQL 8 has no ADD COLUMN IF NOT EXISTS).
 */
$ensureColumn = static function ($table, $column, $definition) use ($db, $p) {
    $exists = $db->getValue(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = "' . pSQL($p . $table) . '"
           AND COLUMN_NAME = "' . pSQL($column) . '"'
    );
    if (!$exists) {
        $db->execute('ALTER TABLE `' . $p . $table . '` ADD COLUMN ' . $definition);
    }
};

$create = [];

$create[] = "CREATE TABLE IF NOT EXISTS `{$p}sj4web_margin_drop_rule` (
    `id_rule` INT(11) NOT NULL AUTO_INCREMENT,
    `id_supplier` INT(11) UNSIGNED NOT NULL,
    `rule_type` ENUM('percent','fixed','per_quantity','bucket_flat') NOT NULL DEFAULT 'fixed',
    `percent` DECIMAL(6,3) NULL DEFAULT NULL,
    `percent_base` ENUM('purchase','sale') NOT NULL DEFAULT 'purchase',
    `fixed_amount` DECIMAL(10,2) NULL DEFAULT NULL,
    `drop_covers_shipping` TINYINT(1) NOT NULL DEFAULT 0,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_rule`),
    UNIQUE KEY `id_supplier` (`id_supplier`)
) ENGINE={$engine} DEFAULT CHARSET=utf8mb4;";

$create[] = "CREATE TABLE IF NOT EXISTS `{$p}sj4web_margin_drop_step` (
    `id_step` INT(11) NOT NULL AUTO_INCREMENT,
    `id_rule` INT(11) NOT NULL,
    `max_quantity` INT(11) UNSIGNED NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `position` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id_step`),
    KEY `id_rule` (`id_rule`)
) ENGINE={$engine} DEFAULT CHARSET=utf8mb4;";

$create[] = "CREATE TABLE IF NOT EXISTS `{$p}sj4web_margin_drop_bucket` (
    `id_bucket` INT(11) NOT NULL AUTO_INCREMENT,
    `id_rule` INT(11) NOT NULL,
    `label` VARCHAR(128) NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `position` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id_bucket`),
    KEY `id_rule` (`id_rule`)
) ENGINE={$engine} DEFAULT CHARSET=utf8mb4;";

$create[] = "CREATE TABLE IF NOT EXISTS `{$p}sj4web_margin_drop_bucket_category` (
    `id_bucket` INT(11) NOT NULL,
    `id_category` INT(11) UNSIGNED NOT NULL,
    PRIMARY KEY (`id_bucket`, `id_category`)
) ENGINE={$engine} DEFAULT CHARSET=utf8mb4;";

// Real shipping cost paid, entered on the order, per supplier.
$create[] = "CREATE TABLE IF NOT EXISTS `{$p}sj4web_order_drop_actual` (
    `id_order` INT(11) UNSIGNED NOT NULL,
    `id_supplier` INT(11) UNSIGNED NOT NULL,
    `amount_ht` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `note` VARCHAR(255) NULL DEFAULT NULL,
    `id_employee` INT(11) UNSIGNED NULL DEFAULT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_order`, `id_supplier`)
) ENGINE={$engine} DEFAULT CHARSET=utf8mb4;";

$create[] = "CREATE TABLE IF NOT EXISTS `{$p}sj4web_order_margin` (
    `id_order` INT(11) UNSIGNED NOT NULL,
    `revenue_ht` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `cost_price_ht` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `drop_cost_ht` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `commission_ht` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `shipping_charged_ht` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `port_paid_ht` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `refund_products_ht` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `refund_shipping_ht` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `store_credit_ht` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `gift_card_sales_ht` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `has_store_credit` TINYINT(1) NOT NULL DEFAULT 0,
    `net_margin` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `margin_rate` DECIMAL(8,2) NULL DEFAULT NULL,
    `markup_rate` DECIMAL(8,2) NULL DEFAULT NULL,
    `commission_percent` DECIMAL(8,2) NULL DEFAULT NULL,
    `cost_incomplete` TINYINT(1) NOT NULL DEFAULT 0,
    `port_pending` TINYINT(1) NOT NULL DEFAULT 0,
    `payment_method` VARCHAR(255) NULL DEFAULT NULL,
    `nb_products` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `date_order` DATETIME NULL DEFAULT NULL,
    `computed_at` DATETIME NOT NULL,
    PRIMARY KEY (`id_order`),
    KEY `date_order` (`date_order`),
    KEY `net_margin` (`net_margin`),
    KEY `margin_rate` (`margin_rate`)
) ENGINE={$engine} DEFAULT CHARSET=utf8mb4;";

foreach ($create as $q) {
    if (!$db->execute($q)) {
        return false;
    }
}

// Bring older installs of the branch up to date.
$ensureColumn('sj4web_margin_drop_rule', 'percent_base', "`percent_base` ENUM('purchase','sale') NOT NULL DEFAULT 'purchase' AFTER `percent`");
$ensureColumn('sj4web_margin_drop_rule', 'drop_covers_shipping', '`drop_covers_shipping` TINYINT(1) NOT NULL DEFAULT 0 AFTER `fixed_amount`');
$ensureColumn('sj4web_order_margin', 'shipping_charged_ht', '`shipping_charged_ht` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `commission_ht`');
$ensureColumn('sj4web_order_margin', 'port_paid_ht', '`port_paid_ht` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `shipping_charged_ht`');
$ensureColumn('sj4web_order_margin', 'port_pending', '`port_pending` TINYINT(1) NOT NULL DEFAULT 0 AFTER `cost_incomplete`');
$ensureColumn('sj4web_order_margin', 'store_credit_ht', '`store_credit_ht` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `refund_shipping_ht`');
$ensureColumn('sj4web_order_margin', 'gift_card_sales_ht', '`gift_card_sales_ht` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `store_credit_ht`');
$ensureColumn('sj4web_order_margin', 'has_store_credit', '`has_store_credit` TINYINT(1) NOT NULL DEFAULT 0 AFTER `gift_card_sales_ht`');

return true;
