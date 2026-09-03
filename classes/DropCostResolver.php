<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * Turns an order's product lines into a dropshipping cost, per supplier, using the
 * rules from DropRuleRepository. Category matching for `bucket_flat` uses the
 * category nested-set: a product matches a bucket when one of its categories is a
 * bucket category or a descendant of one.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class DropCostResolver
{
    /**
     * Read the order lines needed for both the drop cost and the purchase cost.
     *
     * @param int $idOrder
     *
     * @return array<int,array{
     *   product_id:int, id_supplier:int, id_category_default:int,
     *   quantity:int, quantity_refunded:int,
     *   purchase_price:float, total_price_tax_excl:float, categories:int[]
     * }>
     */
    public static function fetchOrderLines($idOrder)
    {
        $idOrder = (int) $idOrder;

        $rows = Db::getInstance()->executeS(
            'SELECT od.product_id,
                    od.product_quantity,
                    od.product_quantity_refunded,
                    od.purchase_supplier_price,
                    od.unit_price_tax_excl,
                    od.total_price_tax_excl,
                    p.id_supplier,
                    p.id_category_default
             FROM `' . _DB_PREFIX_ . 'order_detail` od
             LEFT JOIN `' . _DB_PREFIX_ . 'product` p ON p.id_product = od.product_id
             WHERE od.id_order = ' . $idOrder
        ) ?: [];

        if (!$rows) {
            return [];
        }

        $productIds = array_values(array_unique(array_filter(array_map(static function ($r) {
            return (int) $r['product_id'];
        }, $rows))));

        $categoriesByProduct = $productIds ? self::fetchProductCategories($productIds) : [];

        $lines = [];
        foreach ($rows as $r) {
            $pid = (int) $r['product_id'];
            $lines[] = [
                'product_id' => $pid,
                'id_supplier' => (int) $r['id_supplier'],
                'id_category_default' => (int) $r['id_category_default'],
                'quantity' => (int) $r['product_quantity'],
                'quantity_refunded' => (int) $r['product_quantity_refunded'],
                'purchase_price' => (float) $r['purchase_supplier_price'],
                'unit_price_tax_excl' => (float) $r['unit_price_tax_excl'],
                'total_price_tax_excl' => (float) $r['total_price_tax_excl'],
                'categories' => isset($categoriesByProduct[$pid]) ? $categoriesByProduct[$pid] : [],
            ];
        }

        return $lines;
    }

    /**
     * @param int[] $productIds
     *
     * @return array<int,int[]> product_id => list of category ids
     */
    private static function fetchProductCategories(array $productIds)
    {
        $productIds = array_map('intval', $productIds);
        $rows = Db::getInstance()->executeS(
            'SELECT id_product, id_category FROM `' . _DB_PREFIX_ . 'category_product`
             WHERE id_product IN (' . implode(',', $productIds) . ')'
        ) ?: [];

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['id_product']][] = (int) $r['id_category'];
        }

        return $map;
    }

    /**
     * Effective supplier cost for an order = drop fee (rule estimate) +/- real shipping.
     *
     *  - rule "drop_covers_shipping" ON  (e.g. Castex): the amount is all-in.
     *        total = real shipping paid (if entered) else the estimate.
     *  - rule OFF (handling fee only): shipping is billed on top.
     *        total = drop estimate + real shipping paid (0 until entered -> port_pending).
     *
     * @param array $lines        output of fetchOrderLines()
     * @param array $activeRules   DropRuleRepository::getActiveRulesBySupplier()
     * @param array $actuals       OrderDropActualRepository::getForOrder() : id_supplier => ['amount'=>,'note'=>]
     *
     * @return array{
     *   total:float, port_paid:float, port_pending:bool,
     *   by_supplier:array<int,array{
     *     id_supplier:int, label:string, covers_shipping:bool,
     *     drop_estimate:float, port_real:?float, total:float, port_pending:bool, note:string
     *   }>
     * }
     */
    public static function resolve(array $lines, array $activeRules, array $actuals = [])
    {
        $bySupplier = [];
        foreach ($lines as $line) {
            $bySupplier[$line['id_supplier']][] = $line;
        }

        $result = ['total' => 0.0, 'port_paid' => 0.0, 'port_pending' => false, 'by_supplier' => []];

        foreach ($bySupplier as $idSupplier => $supplierLines) {
            $idSupplier = (int) $idSupplier;
            $hasRule = isset($activeRules[$idSupplier]);
            $hasActual = isset($actuals[$idSupplier]);

            if (!$hasRule && !$hasActual) {
                continue;
            }

            $covers = $hasRule ? !empty($activeRules[$idSupplier]['drop_covers_shipping']) : true;
            $dropEstimate = $hasRule ? round(self::applyRule($activeRules[$idSupplier], $supplierLines), 2) : 0.0;
            $portReal = $hasActual ? (float) $actuals[$idSupplier]['amount'] : null;
            $note = $hasActual ? (string) $actuals[$idSupplier]['note'] : '';

            if ($covers) {
                $total = (null !== $portReal) ? $portReal : $dropEstimate;
                $pending = false;
                if (null !== $portReal) {
                    $result['port_paid'] += $portReal;
                }
            } else {
                $total = $dropEstimate + (null !== $portReal ? $portReal : 0.0);
                $pending = (null === $portReal);
                if (null !== $portReal) {
                    $result['port_paid'] += $portReal;
                }
            }

            $total = round($total, 2);
            if ($total <= 0 && !$pending) {
                continue;
            }

            $result['by_supplier'][$idSupplier] = [
                'id_supplier' => $idSupplier,
                'label' => self::supplierName($idSupplier),
                'covers_shipping' => $covers,
                'drop_estimate' => $dropEstimate,
                'port_real' => (null === $portReal) ? null : round($portReal, 2),
                'total' => $total,
                'port_pending' => $pending,
                'note' => $note,
            ];
            $result['total'] += $total;
            $result['port_pending'] = $result['port_pending'] || $pending;
        }

        $result['total'] = round($result['total'], 2);
        $result['port_paid'] = round($result['port_paid'], 2);

        return $result;
    }

    /**
     * Suppliers present in an order (id => name), for the widget entry block.
     *
     * @param array $lines output of fetchOrderLines()
     *
     * @return array<int,string>
     */
    public static function suppliersInOrder(array $lines)
    {
        $out = [];
        foreach ($lines as $line) {
            $idS = (int) $line['id_supplier'];
            if ($idS > 0 && !isset($out[$idS])) {
                $out[$idS] = self::supplierName($idS);
            }
        }

        return $out;
    }

    /**
     * Drop fee for one supplier. Always computed on the **shipped** goods, i.e. on the
     * quantities NOT refunded (returned products carry no dropship fee).
     *
     *  - percent : rule.percent % of the base, where the base is either the purchase
     *              price (default, "purchase") or our HT selling price before coupons
     *              ("sale"). Shipping is never part of either base.
     *  - fixed   : flat amount, only if the supplier still has at least one shipped unit.
     *  - per_quantity : first step whose max_quantity >= shipped quantity.
     *  - bucket_flat  : one flat amount per bucket that has a shipped matching product.
     *
     * @param array $rule
     * @param array $lines lines of one supplier
     *
     * @return float
     */
    private static function applyRule(array $rule, array $lines)
    {
        $shippedQty = 0;
        foreach ($lines as $l) {
            $shippedQty += max(0, $l['quantity'] - $l['quantity_refunded']);
        }

        switch ($rule['rule_type']) {
            case 'percent':
                if ($shippedQty <= 0) {
                    return 0.0;
                }
                $useSale = (isset($rule['percent_base']) && 'sale' === $rule['percent_base']);
                $base = 0.0;
                foreach ($lines as $l) {
                    $eff = max(0, $l['quantity'] - $l['quantity_refunded']);
                    if ($eff <= 0) {
                        continue;
                    }
                    $base += $useSale
                        ? $l['unit_price_tax_excl'] * $eff
                        : $l['purchase_price'] * $eff;
                }

                return $base * ((float) $rule['percent'] / 100);

            case 'fixed':
                return ($shippedQty > 0) ? (float) $rule['fixed_amount'] : 0.0;

            case 'per_quantity':
                if ($shippedQty <= 0) {
                    return 0.0;
                }
                foreach ($rule['steps'] as $step) {
                    if ($shippedQty <= $step['max_quantity']) {
                        return (float) $step['amount'];
                    }
                }
                $last = end($rule['steps']);

                return $last ? (float) $last['amount'] : 0.0;

            case 'bucket_flat':
                $shippedLines = array_values(array_filter($lines, static function ($l) {
                    return ($l['quantity'] - $l['quantity_refunded']) > 0;
                }));

                return self::applyBucketFlat($rule['buckets'], $shippedLines);

            default:
                return 0.0;
        }
    }

    /**
     * Sum the amounts of the buckets that have at least one matching product.
     * Each product is attributed to its first matching bucket (bucket order).
     *
     * @param array $buckets
     * @param array $lines
     *
     * @return float
     */
    private static function applyBucketFlat(array $buckets, array $lines)
    {
        if (!$buckets) {
            return 0.0;
        }

        // Expand every bucket's categories to their descendant set (nested-set), once.
        $descendants = [];
        foreach ($buckets as $i => $bucket) {
            $descendants[$i] = self::expandCategories($bucket['categories']);
        }

        $bucketHit = array_fill(0, count($buckets), false);

        foreach ($lines as $line) {
            $productCats = $line['categories'];
            if (!$productCats && $line['id_category_default']) {
                $productCats = [$line['id_category_default']];
            }
            if (!$productCats) {
                continue;
            }
            foreach ($buckets as $i => $bucket) {
                if (array_intersect($productCats, $descendants[$i])) {
                    $bucketHit[$i] = true;
                    break; // first matching bucket wins for this product
                }
            }
        }

        $total = 0.0;
        foreach ($buckets as $i => $bucket) {
            if ($bucketHit[$i]) {
                $total += (float) $bucket['amount'];
            }
        }

        return $total;
    }

    /**
     * @param int[] $categoryIds
     *
     * @return int[] the given categories plus all their descendants
     */
    private static function expandCategories(array $categoryIds)
    {
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        if (!$categoryIds) {
            return [];
        }

        $ranges = Db::getInstance()->executeS(
            'SELECT nleft, nright FROM `' . _DB_PREFIX_ . 'category`
             WHERE id_category IN (' . implode(',', $categoryIds) . ')'
        ) ?: [];

        if (!$ranges) {
            return $categoryIds;
        }

        $conds = [];
        foreach ($ranges as $r) {
            $conds[] = '(nleft >= ' . (int) $r['nleft'] . ' AND nright <= ' . (int) $r['nright'] . ')';
        }

        $rows = Db::getInstance()->executeS(
            'SELECT id_category FROM `' . _DB_PREFIX_ . 'category` WHERE ' . implode(' OR ', $conds)
        ) ?: [];

        $all = array_map(static function ($x) {
            return (int) $x['id_category'];
        }, $rows);

        return array_values(array_unique(array_merge($categoryIds, $all)));
    }

    /**
     * @param int $idSupplier
     *
     * @return string
     */
    private static function supplierName($idSupplier)
    {
        static $cache = [];
        if (!array_key_exists($idSupplier, $cache)) {
            $cache[$idSupplier] = (string) Db::getInstance()->getValue(
                'SELECT name FROM `' . _DB_PREFIX_ . 'supplier` WHERE id_supplier = ' . (int) $idSupplier
            );
        }

        return $cache[$idSupplier];
    }
}
