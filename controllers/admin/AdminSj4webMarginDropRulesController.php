<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * CRUD for the per-supplier dropshipping-cost rules.
 * One rule per supplier; rule_type = percent | fixed | per_quantity | bucket_flat.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSj4webMarginDropRulesController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->context = Context::getContext();
        parent::__construct();

        foreach ([
            'MarginConfig', 'DropRuleRepository', 'OrderDropActualRepository',
            'DropCostResolver', 'MarginCalculator', 'OrderMarginRepository',
        ] as $class) {
            require_once _PS_MODULE_DIR_ . 'sj4webmargecommande/classes/' . $class . '.php';
        }
    }

    /**
     * AJAX (from the admin order widget) : save the real shipping paid per supplier
     * for an order, then recompute that order's margin cache.
     *
     * @return void
     */
    public function ajaxProcessSaveOrderDrop()
    {
        header('Content-Type: application/json');

        $idOrder = (int) Tools::getValue('id_order');
        if (!$idOrder) {
            die(json_encode(['success' => false, 'error' => 'missing id_order']));
        }

        $ids = (array) Tools::getValue('sup_id', []);
        $amounts = (array) Tools::getValue('sup_amount', []);
        $notes = (array) Tools::getValue('sup_note', []);
        $idEmployee = (int) $this->context->employee->id ?: null;

        try {
            foreach ($ids as $i => $idSupplier) {
                $idSupplier = (int) $idSupplier;
                if ($idSupplier <= 0) {
                    continue;
                }
                $raw = isset($amounts[$i]) ? trim(str_replace(',', '.', (string) $amounts[$i])) : '';
                $amount = ('' === $raw || !is_numeric($raw)) ? null : (float) $raw;
                $note = isset($notes[$i]) ? (string) $notes[$i] : '';
                OrderDropActualRepository::save($idOrder, $idSupplier, $amount, $note, $idEmployee);
            }

            $m = OrderMarginRepository::refreshOrder($idOrder);
            die(json_encode(['success' => true, 'margin' => $m]));
        } catch (Throwable $e) {
            die(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    /**
     * @return void
     */
    public function initContent()
    {
        parent::initContent();

        if (Tools::getIsset('editsupplier')) {
            $this->renderEditForm((int) Tools::getValue('editsupplier'));

            return;
        }

        $this->renderRulesList();
    }

    /**
     * @return void
     */
    public function postProcess()
    {
        if (Tools::isSubmit('submitDropRule')) {
            if (!$this->hasValidToken()) {
                $this->errors[] = $this->l('Invalid security token.');

                return;
            }
            try {
                $this->saveFromRequest();
                $this->confirmations[] = $this->l('Rule saved.');
                $this->recomputeAffected();
            } catch (Exception $e) {
                $this->errors[] = $this->l('Save failed:') . ' ' . $e->getMessage();
            }
        }

        if (Tools::isSubmit('deleteDropRule')) {
            if (!$this->hasValidToken()) {
                $this->errors[] = $this->l('Invalid security token.');

                return;
            }
            $idRule = (int) Tools::getValue('id_rule');
            if ($idRule) {
                DropRuleRepository::delete($idRule);
                $this->confirmations[] = $this->l('Rule deleted.');
            }
        }

        parent::postProcess();
    }

    /**
     * @return bool
     */
    private function hasValidToken()
    {
        $provided = Tools::getValue('token');

        return is_string($provided) && '' !== $provided
            && hash_equals((string) Tools::getAdminTokenLite('AdminSj4webMarginDropRules'), $provided);
    }

    /**
     * @return void
     */
    private function saveFromRequest()
    {
        $type = (string) Tools::getValue('rule_type');

        $steps = [];
        foreach ((array) Tools::getValue('step_max_qty', []) as $i => $maxQty) {
            $amount = Tools::getValue('step_amount', [])[$i] ?? null;
            if ('' !== (string) $maxQty && '' !== (string) $amount) {
                $steps[] = ['max_quantity' => (int) $maxQty, 'amount' => (float) str_replace(',', '.', $amount)];
            }
        }

        $buckets = [];
        foreach ((array) Tools::getValue('bucket_label', []) as $i => $label) {
            $amount = Tools::getValue('bucket_amount', [])[$i] ?? null;
            $cats = Tools::getValue('bucket_categories', [])[$i] ?? [];
            if ('' !== trim((string) $label)) {
                $buckets[] = [
                    'label' => trim((string) $label),
                    'amount' => (float) str_replace(',', '.', (string) $amount),
                    'categories' => array_map('intval', (array) $cats),
                ];
            }
        }

        DropRuleRepository::save([
            'id_rule' => (int) Tools::getValue('id_rule'),
            'id_supplier' => (int) Tools::getValue('id_supplier'),
            'rule_type' => $type,
            'percent' => Tools::getValue('percent') !== false ? (float) str_replace(',', '.', Tools::getValue('percent')) : null,
            'percent_base' => (Tools::getValue('percent_base') === 'sale') ? 'sale' : 'purchase',
            'fixed_amount' => Tools::getValue('fixed_amount') !== false ? (float) str_replace(',', '.', Tools::getValue('fixed_amount')) : null,
            'drop_covers_shipping' => (bool) Tools::getValue('drop_covers_shipping', 0),
            'active' => (bool) Tools::getValue('active', 1),
            'steps' => $steps,
            'buckets' => $buckets,
        ]);
    }

    /**
     * Recompute the cache for orders of the edited supplier (bounded).
     *
     * @return void
     */
    private function recomputeAffected()
    {
        require_once _PS_MODULE_DIR_ . 'sj4webmargecommande/classes/MarginConfig.php';
        require_once _PS_MODULE_DIR_ . 'sj4webmargecommande/classes/MarginCalculator.php';
        require_once _PS_MODULE_DIR_ . 'sj4webmargecommande/classes/DropCostResolver.php';
        require_once _PS_MODULE_DIR_ . 'sj4webmargecommande/classes/OrderMarginRepository.php';

        $idSupplier = (int) Tools::getValue('id_supplier');
        if (!$idSupplier) {
            return;
        }

        @set_time_limit(0);
        $rows = Db::getInstance()->executeS(
            'SELECT DISTINCT od.id_order
             FROM ' . _DB_PREFIX_ . 'order_detail od
             JOIN ' . _DB_PREFIX_ . 'product p ON p.id_product = od.product_id
             JOIN ' . _DB_PREFIX_ . 'orders o ON o.id_order = od.id_order AND o.valid = 1
             WHERE p.id_supplier = ' . $idSupplier . '
             ORDER BY od.id_order DESC
             LIMIT 5000'
        ) ?: [];

        foreach ($rows as $r) {
            try {
                OrderMarginRepository::refreshOrder((int) $r['id_order']);
            } catch (Throwable $e) {
                // skip
            }
        }
    }

    /**
     * @return void
     */
    private function renderRulesList()
    {
        $suppliers = Db::getInstance()->executeS(
            'SELECT id_supplier, name FROM ' . _DB_PREFIX_ . 'supplier ORDER BY name'
        ) ?: [];
        $rulesBySupplier = [];
        foreach (DropRuleRepository::getAllRules() as $rule) {
            $rulesBySupplier[$rule['id_supplier']] = $rule;
        }

        $baseUrl = AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminSj4webMarginDropRules');
        $lines = [];
        foreach ($suppliers as $s) {
            $idS = (int) $s['id_supplier'];
            $rule = $rulesBySupplier[$idS] ?? null;
            $lines[] = [
                'id_supplier' => $idS,
                'name' => $s['name'],
                'summary' => $rule ? $this->ruleSummary($rule) : $this->l('— no rule —'),
                'active' => $rule ? $rule['active'] : false,
                'edit_url' => $baseUrl . '&editsupplier=' . $idS,
            ];
        }

        $this->context->smarty->assign([
            'sj4wm_suppliers' => $lines,
            'sj4wm_back_url' => $this->context->link->getAdminLink('AdminModules') . '&configure=sj4webmargecommande',
        ]);
        $this->context->smarty->assign('content',
            $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'sj4webmargecommande/views/templates/admin/drop_rules_list.tpl'));
    }

    /**
     * @param array $rule
     *
     * @return string
     */
    private function ruleSummary(array $rule)
    {
        switch ($rule['rule_type']) {
            case 'percent':
                $base = ('sale' === ($rule['percent_base'] ?? 'purchase'))
                    ? $this->l('on selling price')
                    : $this->l('on purchase price');

                return sprintf('%s %s%% (%s)', $this->l('Percent'), $rule['percent'], $base);
            case 'fixed':
                return sprintf('%s %.2f €', $this->l('Fixed'), $rule['fixed_amount']);
            case 'per_quantity':
                return sprintf('%s (%d %s)', $this->l('Per quantity'), count($rule['steps']), $this->l('steps'));
            case 'bucket_flat':
                $parts = [];
                foreach ($rule['buckets'] as $b) {
                    $parts[] = sprintf('%s %.2f €', $b['label'], $b['amount']);
                }
                $s = $this->l('Buckets:') . ' ' . implode(' · ', $parts);

                return $s . (!empty($rule['drop_covers_shipping']) ? ' — ' . $this->l('shipping incl.') : '');
            default:
                return $rule['rule_type'];
        }
    }

    /**
     * @param int $idSupplier
     *
     * @return void
     */
    private function renderEditForm($idSupplier)
    {
        $supplier = new Supplier($idSupplier);
        if (!Validate::isLoadedObject($supplier)) {
            $this->errors[] = $this->l('Unknown supplier.');

            return;
        }

        $rules = DropRuleRepository::getAllRules();
        $rule = null;
        foreach ($rules as $r) {
            if ($r['id_supplier'] === (int) $idSupplier) {
                $rule = $r;

                break;
            }
        }

        $this->context->smarty->assign([
            'sj4wm_supplier' => ['id' => (int) $idSupplier, 'name' => $supplier->name],
            'sj4wm_rule' => $rule,
            'sj4wm_types' => DropRuleRepository::TYPES,
            'sj4wm_categories' => $this->categoryOptions(),
            'sj4wm_form_action' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminSj4webMarginDropRules'),
            'sj4wm_list_url' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminSj4webMarginDropRules'),
        ]);
        $this->context->smarty->assign('content',
            $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'sj4webmargecommande/views/templates/admin/drop_rule_edit.tpl'));
    }

    /**
     * @return array [['id_category'=>int,'label'=>string], ...] indented by depth
     */
    private function categoryOptions()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT c.id_category, c.level_depth, cl.name
             FROM ' . _DB_PREFIX_ . 'category c
             JOIN ' . _DB_PREFIX_ . 'category_lang cl
               ON cl.id_category = c.id_category AND cl.id_lang = ' . (int) $this->context->language->id . '
              AND cl.id_shop = ' . (int) $this->context->shop->id . '
             WHERE c.level_depth >= 2
             ORDER BY c.nleft ASC'
        ) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id_category' => (int) $r['id_category'],
                'label' => str_repeat('— ', max(0, (int) $r['level_depth'] - 2)) . $r['name'] . ' (#' . (int) $r['id_category'] . ')',
            ];
        }

        return $out;
    }
}
