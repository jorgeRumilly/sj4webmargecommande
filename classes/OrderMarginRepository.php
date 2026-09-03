<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * Read/write the precomputed per-order margin cache `sj4web_order_margin`, and serve
 * the back-office list straight from it (SQL filtering / sorting / paging).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OrderMarginRepository
{
    const TABLE = 'sj4web_order_margin';

    /** Columns the BO list may sort on (all live in the cache table). */
    const SORTABLE = [
        'id_order', 'date_order', 'revenue_ht', 'cost_price_ht', 'drop_cost_ht',
        'commission_ht', 'shipping_charged_ht', 'port_paid_ht', 'refund_products_ht',
        'store_credit_ht', 'net_margin', 'margin_rate', 'markup_rate', 'commission_percent',
        'nb_products', 'payment_method',
    ];

    /**
     * @return Db
     */
    private static function db()
    {
        return Db::getInstance();
    }

    /**
     * @return bool
     */
    public static function tableExists()
    {
        return (bool) self::db()->executeS(
            "SHOW TABLES LIKE '" . pSQL(_DB_PREFIX_ . self::TABLE) . "'"
        );
    }

    /**
     * Recompute one order and store the result.
     *
     * @param int $idOrder
     *
     * @return array|null the computed row, or null when the order is invalid
     */
    public static function refreshOrder($idOrder)
    {
        $order = new Order((int) $idOrder);
        if (!Validate::isLoadedObject($order)) {
            self::delete((int) $idOrder);

            return null;
        }

        $data = MarginCalculator::computeForOrder($order);
        self::upsert($data);

        return $data;
    }

    /**
     * @param array $data output of MarginCalculator::computeForOrder()
     *
     * @return void
     */
    public static function upsert(array $data)
    {
        $row = [
            'id_order' => (int) $data['id_order'],
            'revenue_ht' => (float) $data['revenue_ht'],
            'cost_price_ht' => (float) $data['cost_price_ht'],
            'drop_cost_ht' => (float) $data['drop_cost_ht'],
            'commission_ht' => (float) $data['commission_ht'],
            'shipping_charged_ht' => (float) $data['shipping_charged_ht'],
            'port_paid_ht' => (float) $data['port_paid_ht'],
            'refund_products_ht' => (float) $data['refund_products_ht'],
            'refund_shipping_ht' => (float) $data['refund_shipping_ht'],
            'store_credit_ht' => (float) ($data['store_credit_ht'] ?? 0),
            'gift_card_sales_ht' => (float) ($data['gift_card_sales_ht'] ?? 0),
            'has_store_credit' => empty($data['has_store_credit']) ? 0 : 1,
            'net_margin' => (float) $data['net_margin'],
            'margin_rate' => (null === $data['margin_rate']) ? null : (float) $data['margin_rate'],
            'markup_rate' => (null === $data['markup_rate']) ? null : (float) $data['markup_rate'],
            'commission_percent' => (null === $data['commission_percent']) ? null : (float) $data['commission_percent'],
            'cost_incomplete' => empty($data['cost_incomplete']) ? 0 : 1,
            'port_pending' => empty($data['port_pending']) ? 0 : 1,
            'payment_method' => pSQL((string) $data['payment_method']),
            'nb_products' => (int) $data['nb_products'],
            'date_order' => pSQL((string) $data['date_order']),
            'computed_at' => date('Y-m-d H:i:s'),
        ];

        $exists = (bool) self::db()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_order = ' . (int) $row['id_order']
        );

        if ($exists) {
            self::db()->update(self::TABLE, $row, 'id_order = ' . (int) $row['id_order']);
        } else {
            self::db()->insert(self::TABLE, $row);
        }
    }

    /**
     * @param int $idOrder
     *
     * @return void
     */
    public static function delete($idOrder)
    {
        self::db()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_order = ' . (int) $idOrder
        );
    }

    /**
     * Valid orders missing from the cache (or, when $sinceDate is given, valid orders
     * placed since that date regardless of cache state — used by the rolling cron).
     *
     * @param string|null $sinceDate Y-m-d or null
     * @param int         $limit
     *
     * @return int[] order ids
     */
    public static function findOrdersToCompute($sinceDate = null, $limit = 500)
    {
        $sql = 'SELECT o.id_order
                FROM `' . _DB_PREFIX_ . 'orders` o
                LEFT JOIN `' . _DB_PREFIX_ . self::TABLE . '` m ON m.id_order = o.id_order
                WHERE o.valid = 1';

        if ($sinceDate) {
            $sql .= ' AND o.date_add >= "' . pSQL($sinceDate) . ' 00:00:00"';
        } else {
            $sql .= ' AND m.id_order IS NULL';
        }
        $sql .= ' ORDER BY o.id_order DESC LIMIT ' . (int) $limit;

        $rows = self::db()->executeS($sql) ?: [];

        return array_map(static function ($r) {
            return (int) $r['id_order'];
        }, $rows);
    }

    /**
     * Recompute a batch of orders.
     *
     * @param int[] $orderIds
     *
     * @return array{done:int, skipped:int}
     */
    public static function recomputeBatch(array $orderIds)
    {
        $done = 0;
        $skipped = 0;
        foreach ($orderIds as $idOrder) {
            $res = self::refreshOrder((int) $idOrder);
            if (null === $res) {
                $skipped++;
            } else {
                $done++;
            }
        }

        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * Rows for the BO list.
     *
     * @param array  $filters  see buildWhere()
     * @param string $orderBy
     * @param string $orderWay 'ASC'|'DESC'
     * @param int    $offset
     * @param int    $limit
     *
     * @return array
     */
    public static function search(array $filters, $orderBy, $orderWay, $offset, $limit)
    {
        $orderBy = in_array($orderBy, self::SORTABLE, true) ? $orderBy : 'id_order';
        $orderWay = (Tools::strtoupper($orderWay) === 'ASC') ? 'ASC' : 'DESC';
        $where = self::buildWhere($filters);

        $sql = 'SELECT m.*
                FROM `' . _DB_PREFIX_ . self::TABLE . '` m
                WHERE ' . $where . '
                ORDER BY m.`' . bqSQL($orderBy) . '` ' . $orderWay . '
                LIMIT ' . (int) $offset . ', ' . (int) $limit;

        return self::db()->executeS($sql) ?: [];
    }

    /**
     * @param array $filters
     *
     * @return int
     */
    public static function count(array $filters)
    {
        return (int) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` m WHERE ' . self::buildWhere($filters)
        );
    }

    /**
     * @param array $filters [
     *   'id_order'?, 'payment'?, 'date_from'?, 'date_to'?, 'has_store_credit'?,
     *   'margin_min'?, 'margin_max'?, 'margin_rate_min'?, 'margin_rate_max'?,
     *   'commission_percent_min'?, 'commission_percent_max'?,
     *   'markup_rate_min'?, 'markup_rate_max'?
     * ]
     *
     * @return string
     */
    private static function buildWhere(array $filters)
    {
        $c = ['1'];

        if (!empty($filters['id_order'])) {
            $c[] = 'm.id_order = ' . (int) $filters['id_order'];
        }
        if (isset($filters['payment']) && '' !== $filters['payment']) {
            $c[] = 'm.payment_method LIKE "%' . pSQL($filters['payment']) . '%"';
        }
        if (!empty($filters['date_from'])) {
            $c[] = 'm.date_order >= "' . pSQL($filters['date_from']) . ' 00:00:00"';
        }
        if (!empty($filters['date_to'])) {
            $c[] = 'm.date_order <= "' . pSQL($filters['date_to']) . ' 23:59:59"';
        }
        if (isset($filters['has_store_credit']) && '' !== $filters['has_store_credit']) {
            $c[] = 'm.has_store_credit = ' . ((int) $filters['has_store_credit'] ? 1 : 0);
        }

        foreach ([
            'margin_min' => ['net_margin', '>='],
            'margin_max' => ['net_margin', '<='],
            'margin_rate_min' => ['margin_rate', '>='],
            'margin_rate_max' => ['margin_rate', '<='],
            'markup_rate_min' => ['markup_rate', '>='],
            'markup_rate_max' => ['markup_rate', '<='],
            'commission_percent_min' => ['commission_percent', '>='],
            'commission_percent_max' => ['commission_percent', '<='],
        ] as $key => $def) {
            if (isset($filters[$key]) && '' !== $filters[$key] && is_numeric($filters[$key])) {
                $c[] = 'm.`' . $def[0] . '` ' . $def[1] . ' ' . (float) $filters[$key];
            }
        }

        return implode(' AND ', $c);
    }

    /**
     * @return array{cached:int, orders_valid:int, missing:int, cost_incomplete:int}
     */
    public static function getStats()
    {
        $cached = (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '`');
        $valid = (int) self::db()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders` WHERE valid = 1');
        $incomplete = (int) self::db()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE cost_incomplete = 1'
        );

        return [
            'cached' => $cached,
            'orders_valid' => $valid,
            'missing' => max(0, $valid - $cached),
            'cost_incomplete' => $incomplete,
        ];
    }
}
