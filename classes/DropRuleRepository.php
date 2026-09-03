<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * Persistence + CRUD for the per-supplier dropshipping-cost rules
 * (`sj4web_margin_drop_rule` and its child tables).
 *
 * A rule has one `rule_type`:
 *  - percent       : percent of the supplier's product total (HT) in the order
 *  - fixed         : flat amount per order as soon as the supplier is present
 *  - per_quantity  : first step whose max_quantity >= supplier qty in the order
 *  - bucket_flat   : sum of the amounts of the buckets present in the order; a bucket
 *                    is "present" when one of its categories (or a descendant) matches
 *                    one of the supplier's products in the order
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class DropRuleRepository
{
    const T_RULE = 'sj4web_margin_drop_rule';
    const T_STEP = 'sj4web_margin_drop_step';
    const T_BUCKET = 'sj4web_margin_drop_bucket';
    const T_BUCKET_CAT = 'sj4web_margin_drop_bucket_category';

    const TYPES = ['percent', 'fixed', 'per_quantity', 'bucket_flat'];

    /**
     * @return Db
     */
    private static function db()
    {
        return Db::getInstance();
    }

    /**
     * All active rules keyed by id_supplier, each fully hydrated (steps + buckets).
     *
     * @return array<int,array>
     */
    public static function getActiveRulesBySupplier()
    {
        $rows = self::db()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::T_RULE . '` WHERE active = 1'
        ) ?: [];

        $rules = [];
        foreach ($rows as $row) {
            $rules[(int) $row['id_supplier']] = self::hydrate($row);
        }

        return $rules;
    }

    /**
     * @return array<int,array> every rule (active or not), keyed by id_rule
     */
    public static function getAllRules()
    {
        $rows = self::db()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::T_RULE . '` ORDER BY id_supplier'
        ) ?: [];

        $rules = [];
        foreach ($rows as $row) {
            $rules[(int) $row['id_rule']] = self::hydrate($row);
        }

        return $rules;
    }

    /**
     * @param int $idRule
     *
     * @return array|null
     */
    public static function getRule($idRule)
    {
        $row = self::db()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::T_RULE . '` WHERE id_rule = ' . (int) $idRule
        );

        return $row ? self::hydrate($row[0]) : null;
    }

    /**
     * @param array $row raw rule row
     *
     * @return array
     */
    private static function hydrate(array $row)
    {
        $idRule = (int) $row['id_rule'];

        $steps = self::db()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::T_STEP . '`
             WHERE id_rule = ' . $idRule . ' ORDER BY position ASC, max_quantity ASC'
        ) ?: [];

        $buckets = self::db()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::T_BUCKET . '`
             WHERE id_rule = ' . $idRule . ' ORDER BY position ASC, id_bucket ASC'
        ) ?: [];

        foreach ($buckets as &$bucket) {
            $cats = self::db()->executeS(
                'SELECT id_category FROM `' . _DB_PREFIX_ . self::T_BUCKET_CAT . '`
                 WHERE id_bucket = ' . (int) $bucket['id_bucket']
            ) ?: [];
            $bucket['categories'] = array_map('intval', array_column($cats, 'id_category'));
        }
        unset($bucket);

        return [
            'id_rule' => $idRule,
            'id_supplier' => (int) $row['id_supplier'],
            'rule_type' => (string) $row['rule_type'],
            'percent' => (null === $row['percent']) ? null : (float) $row['percent'],
            'percent_base' => ('sale' === ($row['percent_base'] ?? 'purchase')) ? 'sale' : 'purchase',
            'fixed_amount' => (null === $row['fixed_amount']) ? null : (float) $row['fixed_amount'],
            'drop_covers_shipping' => !empty($row['drop_covers_shipping']),
            'active' => (bool) $row['active'],
            'steps' => array_map(static function ($s) {
                return ['max_quantity' => (int) $s['max_quantity'], 'amount' => (float) $s['amount']];
            }, $steps),
            'buckets' => array_map(static function ($b) {
                return [
                    'id_bucket' => (int) $b['id_bucket'],
                    'label' => (string) $b['label'],
                    'amount' => (float) $b['amount'],
                    'categories' => $b['categories'],
                ];
            }, $buckets),
        ];
    }

    /**
     * Create or update a rule and its children.
     *
     * @param array $data [
     *     'id_rule'?     => int,
     *     'id_supplier'  => int,
     *     'rule_type'    => string,
     *     'percent'      => float|null,
     *     'fixed_amount' => float|null,
     *     'active'       => bool,
     *     'steps'        => [['max_quantity'=>int,'amount'=>float], ...],
     *     'buckets'      => [['label'=>string,'amount'=>float,'categories'=>int[]], ...],
     * ]
     *
     * @return int id_rule
     *
     * @throws Exception on validation error
     */
    public static function save(array $data)
    {
        $idSupplier = (int) ($data['id_supplier'] ?? 0);
        $type = (string) ($data['rule_type'] ?? '');
        if ($idSupplier <= 0) {
            throw new Exception('id_supplier is required.');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new Exception('Invalid rule_type.');
        }

        $now = date('Y-m-d H:i:s');
        $ruleRow = [
            'id_supplier' => $idSupplier,
            'rule_type' => pSQL($type),
            'percent' => ('percent' === $type && isset($data['percent'])) ? (float) $data['percent'] : null,
            'percent_base' => (isset($data['percent_base']) && 'sale' === $data['percent_base']) ? 'sale' : 'purchase',
            'fixed_amount' => ('fixed' === $type && isset($data['fixed_amount'])) ? (float) $data['fixed_amount'] : null,
            'drop_covers_shipping' => empty($data['drop_covers_shipping']) ? 0 : 1,
            'active' => empty($data['active']) ? 0 : 1,
            'date_upd' => pSQL($now),
        ];

        $idRule = (int) ($data['id_rule'] ?? 0);
        if (!$idRule) {
            // A supplier may already have a (previously created) rule row.
            $idRule = (int) self::db()->getValue(
                'SELECT id_rule FROM `' . _DB_PREFIX_ . self::T_RULE . '` WHERE id_supplier = ' . $idSupplier
            );
        }

        if ($idRule) {
            // 3rd arg true: honour NULL for percent / fixed_amount not used by this type.
            self::db()->update(self::T_RULE, $ruleRow, 'id_rule = ' . $idRule, 0, true);
        } else {
            $ruleRow['date_add'] = pSQL($now);
            self::db()->insert(self::T_RULE, $ruleRow, true);
            $idRule = (int) self::db()->Insert_ID();
        }

        self::replaceSteps($idRule, ('per_quantity' === $type) ? ($data['steps'] ?? []) : []);
        self::replaceBuckets($idRule, ('bucket_flat' === $type) ? ($data['buckets'] ?? []) : []);

        return $idRule;
    }

    /**
     * @param int $idRule
     *
     * @return void
     */
    public static function delete($idRule)
    {
        $idRule = (int) $idRule;
        $bucketIds = array_map('intval', array_column(
            self::db()->executeS('SELECT id_bucket FROM `' . _DB_PREFIX_ . self::T_BUCKET . '` WHERE id_rule = ' . $idRule) ?: [],
            'id_bucket'
        ));
        if ($bucketIds) {
            self::db()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . self::T_BUCKET_CAT . '` WHERE id_bucket IN (' . implode(',', $bucketIds) . ')'
            );
        }
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . self::T_BUCKET . '` WHERE id_rule = ' . $idRule);
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . self::T_STEP . '` WHERE id_rule = ' . $idRule);
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . self::T_RULE . '` WHERE id_rule = ' . $idRule);
    }

    /**
     * @param int   $idRule
     * @param array $steps
     *
     * @return void
     */
    private static function replaceSteps($idRule, array $steps)
    {
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . self::T_STEP . '` WHERE id_rule = ' . (int) $idRule);

        $position = 0;
        foreach ($steps as $step) {
            $maxQty = (int) ($step['max_quantity'] ?? 0);
            $amount = (float) ($step['amount'] ?? 0);
            if ($maxQty <= 0) {
                continue;
            }
            self::db()->insert(self::T_STEP, [
                'id_rule' => (int) $idRule,
                'max_quantity' => $maxQty,
                'amount' => $amount,
                'position' => $position++,
            ]);
        }
    }

    /**
     * @param int   $idRule
     * @param array $buckets
     *
     * @return void
     */
    private static function replaceBuckets($idRule, array $buckets)
    {
        $oldIds = array_map('intval', array_column(
            self::db()->executeS('SELECT id_bucket FROM `' . _DB_PREFIX_ . self::T_BUCKET . '` WHERE id_rule = ' . (int) $idRule) ?: [],
            'id_bucket'
        ));
        if ($oldIds) {
            self::db()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . self::T_BUCKET_CAT . '` WHERE id_bucket IN (' . implode(',', $oldIds) . ')'
            );
        }
        self::db()->execute('DELETE FROM `' . _DB_PREFIX_ . self::T_BUCKET . '` WHERE id_rule = ' . (int) $idRule);

        $position = 0;
        foreach ($buckets as $bucket) {
            $label = trim((string) ($bucket['label'] ?? ''));
            $amount = (float) ($bucket['amount'] ?? 0);
            $categories = array_values(array_unique(array_filter(array_map('intval', (array) ($bucket['categories'] ?? [])))));
            if ('' === $label || !$categories) {
                continue;
            }
            self::db()->insert(self::T_BUCKET, [
                'id_rule' => (int) $idRule,
                'label' => pSQL($label),
                'amount' => $amount,
                'position' => $position++,
            ]);
            $idBucket = (int) self::db()->Insert_ID();
            foreach ($categories as $idCategory) {
                self::db()->insert(self::T_BUCKET_CAT, [
                    'id_bucket' => $idBucket,
                    'id_category' => $idCategory,
                ], false, true, Db::INSERT_IGNORE);
            }
        }
    }
}
