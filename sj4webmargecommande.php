<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * Order margin (theoretical) for a dropshipping catalogue:
 *   net revenue (HT, after vouchers, minus refunds) - purchase cost - dropshipping
 *   cost (per-supplier rules) - payment commission.
 *
 * The BO list reads a precomputed cache (`sj4web_order_margin`) so it filters / sorts
 * / paginates in SQL. The admin order widget computes live through the same
 * MarginCalculator, so both always agree.
 *
 * @author  SJ4WEB.FR
 * @version 2.0.2
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Sj4webMargeCommande extends Module
{
    const T_DOMAIN = 'Modules.Sj4webmargecommande.Admin';

    public function __construct()
    {
        $this->name = 'sj4webmargecommande';
        $this->tab = 'administration';
        $this->version = '2.0.2';
        $this->author = 'SJ4WEB.FR';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('SJ4WEB - Order margin', [], self::T_DOMAIN);
        $this->description = $this->trans('Theoretical order margin: revenue - purchase cost - dropshipping cost - payment commission.', [], self::T_DOMAIN);
        $this->confirmUninstall = $this->trans('Uninstall? The margin rules and cache are removed; order_fees is kept.', [], self::T_DOMAIN);
        $this->ps_versions_compliancy = ['min' => '8.1', 'max' => _PS_VERSION_];
    }

    /**
     * @return void
     */
    private function loadClasses()
    {
        foreach ([
            'MarginConfig', 'MarginLock', 'DropRuleRepository', 'OrderDropActualRepository',
            'DropCostResolver', 'StoreCreditResolver', 'MarginCalculator', 'OrderMarginRepository',
            'MarginPresenter',
        ] as $class) {
            require_once __DIR__ . '/classes/' . $class . '.php';
        }
    }

    /**
     * @return bool
     */
    public function install()
    {
        $this->loadClasses();

        return parent::install()
            && $this->registerHook('displayAdminOrderSide')
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionOrderEdited')
            && $this->registerHook('actionObjectOrderSlipAddAfter')
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->registerHook('actionSj4webOrderFeeCaptured')
            && $this->installDB()
            && MarginConfig::installDefaults()
            && $this->installTab();
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        $this->loadClasses();

        return $this->uninstallTab()
            && $this->uninstallDB()
            && MarginConfig::deleteAll()
            && parent::uninstall();
    }

    /**
     * @return bool
     */
    protected function installDB()
    {
        include __DIR__ . '/sql/install.php';

        return true;
    }

    /**
     * @return bool
     */
    protected function uninstallDB()
    {
        include __DIR__ . '/sql/uninstall.php';

        return true;
    }

    /**
     * @return bool
     */
    public function installTab()
    {
        foreach ([
            'AdminSj4webMargeCommandeFees' => $this->trans('Order margins', [], self::T_DOMAIN),
            'AdminSj4webMarginDropRules' => $this->trans('Dropshipping cost rules', [], self::T_DOMAIN),
        ] as $className => $label) {
            if (Tab::getIdFromClassName($className)) {
                continue;
            }
            $tab = new Tab();
            $tab->class_name = $className;
            $tab->module = $this->name;
            $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentOrders');
            $tab->name = [];
            foreach (Language::getLanguages(true) as $lang) {
                $tab->name[$lang['id_lang']] = $label;
            }
            if (!$tab->add()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return bool
     */
    protected function uninstallTab()
    {
        foreach (['AdminSj4webMargeCommandeFees', 'AdminSj4webMarginDropRules'] as $className) {
            $idTab = (int) Tab::getIdFromClassName($className);
            if ($idTab) {
                $tab = new Tab($idTab);
                $tab->delete();
            }
        }

        return true;
    }

    // ---------------------------------------------------------------------------
    // Cache freshness hooks (thin: they only enqueue a recompute of one order)
    // ---------------------------------------------------------------------------

    /**
     * @param array $params
     *
     * @return void
     */
    public function hookActionValidateOrder($params)
    {
        $this->refreshOrderSafe(isset($params['order']) ? (int) $params['order']->id : 0);
    }

    /**
     * @param array $params
     *
     * @return void
     */
    public function hookActionOrderEdited($params)
    {
        $this->refreshOrderSafe(isset($params['order']) ? (int) $params['order']->id : 0);
    }

    /**
     * @param array $params
     *
     * @return void
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        $this->refreshOrderSafe(isset($params['id_order']) ? (int) $params['id_order'] : 0);
    }

    /**
     * @param array $params
     *
     * @return void
     */
    public function hookActionObjectOrderSlipAddAfter($params)
    {
        $slip = isset($params['object']) ? $params['object'] : null;
        $this->refreshOrderSafe($slip ? (int) $slip->id_order : 0);
    }

    /**
     * Fired by sj4web_payplugreport when a commission is captured after the fact.
     *
     * @param array $params ['id_order' => int]
     *
     * @return void
     */
    public function hookActionSj4webOrderFeeCaptured($params)
    {
        $this->refreshOrderSafe(isset($params['id_order']) ? (int) $params['id_order'] : 0);
    }

    /**
     * @param int $idOrder
     *
     * @return void
     */
    private function refreshOrderSafe($idOrder)
    {
        if ($idOrder <= 0) {
            return;
        }
        try {
            $this->loadClasses();
            OrderMarginRepository::refreshOrder($idOrder);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('sj4webmargecommande: ' . $e->getMessage(), 2, null, 'Order', $idOrder, true);
        }
    }

    // ---------------------------------------------------------------------------
    // Admin order widget
    // ---------------------------------------------------------------------------

    /**
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayAdminOrderSide($params)
    {
        $this->loadClasses();

        $order = new Order((int) $params['id_order']);
        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        try {
            $m = MarginCalculator::computeForOrder($order);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('sj4webmargecommande: ' . $e->getMessage(), 2, null, 'Order', (int) $order->id, true);

            return '';
        }

        // Keep the cache in sync opportunistically.
        try {
            OrderMarginRepository::upsert($m);
        } catch (Throwable $e) {
            // non blocking
        }

        $this->context->smarty->assign([
            'm' => $m,
            'c' => MarginPresenter::colors($m),
            'sj4wm_suppliers' => $this->buildSupplierEntryRows((int) $order->id),
            'sj4wm_ajax_url' => $this->context->link->getAdminLink('AdminSj4webMarginDropRules'),
            'sj4wm_id_order' => (int) $order->id,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/displayAdminOrder.tpl');
    }

    /**
     * Rows for the "real shipping paid" entry block in the widget: one per supplier
     * present in the order, prefilled with the rule estimate and any saved value.
     *
     * @param int $idOrder
     *
     * @return array<int,array>
     */
    private function buildSupplierEntryRows($idOrder)
    {
        $lines = DropCostResolver::fetchOrderLines($idOrder);
        $suppliers = DropCostResolver::suppliersInOrder($lines);
        $rules = DropRuleRepository::getActiveRulesBySupplier();
        $actuals = OrderDropActualRepository::getForOrder($idOrder);

        $resolved = DropCostResolver::resolve($lines, $rules, $actuals);
        $bySupplier = [];
        foreach ($resolved['by_supplier'] as $r) {
            $bySupplier[$r['id_supplier']] = $r;
        }

        $rows = [];
        foreach ($suppliers as $idSupplier => $name) {
            $r = $bySupplier[$idSupplier] ?? null;
            $rows[] = [
                'id_supplier' => (int) $idSupplier,
                'label' => $name,
                'covers_shipping' => $r ? $r['covers_shipping'] : (isset($rules[$idSupplier]) ? !empty($rules[$idSupplier]['drop_covers_shipping']) : false),
                'drop_estimate' => $r ? $r['drop_estimate'] : 0.0,
                'port_real' => isset($actuals[$idSupplier]) ? (float) $actuals[$idSupplier]['amount'] : null,
                'note' => isset($actuals[$idSupplier]) ? (string) $actuals[$idSupplier]['note'] : '',
                'total' => $r ? $r['total'] : 0.0,
                'port_pending' => $r ? $r['port_pending'] : false,
            ];
        }

        return $rows;
    }

    // ---------------------------------------------------------------------------
    // Cron entry point (controllers/front/cron.php)
    // ---------------------------------------------------------------------------

    /**
     * @param array $options ['scope' => 'missing'|'window', 'limit' => int]
     *
     * @return array
     */
    public function runRecompute(array $options = [])
    {
        $this->loadClasses();

        $lock = new MarginLock();
        if (!$lock->acquire()) {
            return ['skipped' => true, 'reason' => 'already running'];
        }

        try {
            $scope = isset($options['scope']) ? (string) $options['scope'] : 'window';
            $limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 1000;

            if ('missing' === $scope) {
                $ids = OrderMarginRepository::findOrdersToCompute(null, $limit);
            } else {
                $since = date('Y-m-d', strtotime('-' . MarginConfig::getRecomputeDays() . ' day'));
                $ids = OrderMarginRepository::findOrdersToCompute($since, $limit);
            }

            $res = OrderMarginRepository::recomputeBatch($ids);
            $res['scope'] = $scope;
            $res['picked'] = count($ids);

            return $res;
        } finally {
            $lock->release();
        }
    }

    // ---------------------------------------------------------------------------
    // Configuration screen
    // ---------------------------------------------------------------------------

    /**
     * @return string
     */
    public function getContent()
    {
        $this->loadClasses();
        $this->installDB();

        $output = '';

        $isAction = Tools::isSubmit('submit' . $this->name)
            || Tools::isSubmit('sj4web_margin_recompute')
            || Tools::isSubmit('sj4web_margin_regen_token');

        if ($isAction && !$this->isValidAdminToken()) {
            $output .= $this->displayError($this->trans('Invalid security token.', [], self::T_DOMAIN));
        } elseif (Tools::isSubmit('submit' . $this->name)) {
            $output .= $this->postProcessSettings();
        } elseif (Tools::isSubmit('sj4web_margin_recompute')) {
            $output .= $this->postProcessRecompute();
        } elseif (Tools::isSubmit('sj4web_margin_regen_token')) {
            Configuration::updateValue(MarginConfig::CRON_TOKEN, Tools::passwdGen(48));
            $output .= $this->displayConfirmation($this->trans('Cron token regenerated.', [], self::T_DOMAIN));
        }

        return $output . $this->renderSettingsForm() . $this->renderPanel();
    }

    /**
     * @return bool
     */
    private function isValidAdminToken()
    {
        $provided = Tools::getValue('token');

        return is_string($provided) && '' !== $provided
            && hash_equals((string) Tools::getAdminTokenLite('AdminModules'), $provided);
    }

    /**
     * @return string
     */
    private function postProcessSettings()
    {
        $days = (int) Tools::getValue(MarginConfig::RECOMPUTE_DAYS);
        $green = Tools::getValue(MarginConfig::MARGIN_GREEN);
        $orange = Tools::getValue(MarginConfig::MARGIN_ORANGE);
        $giftCards = (string) Tools::getValue(MarginConfig::GIFTCARD_PRODUCTS, '');

        if ($days < 1 || !is_numeric($green) || !is_numeric($orange)) {
            return $this->displayError($this->trans('Invalid values.', [], self::T_DOMAIN));
        }
        if ('' !== trim($giftCards) && !preg_match('/^[\d\s,;]+$/', $giftCards)) {
            return $this->displayError($this->trans('Invalid values.', [], self::T_DOMAIN));
        }

        Configuration::updateValue(MarginConfig::RECOMPUTE_DAYS, $days);
        Configuration::updateValue(MarginConfig::MARGIN_GREEN, (float) $green);
        Configuration::updateValue(MarginConfig::MARGIN_ORANGE, (float) $orange);
        Configuration::updateValue(MarginConfig::GIFTCARD_PRODUCTS, trim($giftCards));

        return $this->displayConfirmation($this->trans('Settings updated.', [], self::T_DOMAIN));
    }

    /**
     * @return string
     */
    private function postProcessRecompute()
    {
        @set_time_limit(0);
        $full = (Tools::getValue('scope') === 'all');

        try {
            if ($full) {
                Db::getInstance()->execute('TRUNCATE `' . _DB_PREFIX_ . OrderMarginRepository::TABLE . '`');
            }

            $r = ['done' => 0, 'skipped' => 0];
            do {
                $ids = OrderMarginRepository::findOrdersToCompute(null, 1000);
                $batch = OrderMarginRepository::recomputeBatch($ids);
                $r['done'] += $batch['done'];
                $r['skipped'] += $batch['skipped'];
            } while (count($ids) === 1000);
        } catch (Throwable $e) {
            return $this->displayError($this->trans('Recompute failed:', [], self::T_DOMAIN) . ' ' . $e->getMessage());
        }

        return $this->displayConfirmation(sprintf(
            $this->trans('Recompute done: %d orders, %d skipped.', [], self::T_DOMAIN),
            (int) ($r['done'] ?? 0),
            (int) ($r['skipped'] ?? 0)
        ));
    }

    /**
     * @return string
     */
    private function renderSettingsForm()
    {
        $form[0]['form'] = [
            'legend' => ['title' => $this->trans('Settings', [], self::T_DOMAIN), 'icon' => 'icon-cogs'],
            'input' => [
                [
                    'type' => 'text', 'name' => MarginConfig::RECOMPUTE_DAYS, 'class' => 'fixed-width-sm',
                    'label' => $this->trans('Cron rolling window (days)', [], self::T_DOMAIN),
                    'desc' => $this->trans('Each cron run recomputes valid orders placed within this many days (absorbs late fees / refunds).', [], self::T_DOMAIN),
                ],
                [
                    'type' => 'text', 'name' => MarginConfig::MARGIN_GREEN, 'class' => 'fixed-width-sm', 'suffix' => '€',
                    'label' => $this->trans('Net margin: green threshold', [], self::T_DOMAIN),
                ],
                [
                    'type' => 'text', 'name' => MarginConfig::MARGIN_ORANGE, 'class' => 'fixed-width-sm', 'suffix' => '€',
                    'label' => $this->trans('Net margin: orange threshold', [], self::T_DOMAIN),
                ],
                [
                    'type' => 'text', 'name' => MarginConfig::GIFTCARD_PRODUCTS,
                    'label' => $this->trans('Gift card product IDs', [], self::T_DOMAIN),
                    'desc' => $this->trans('Comma-separated product IDs treated as gift-card sales (excluded from revenue and cost).', [], self::T_DOMAIN),
                ],
            ],
            'submit' => ['title' => $this->trans('Save', [], self::T_DOMAIN)],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submit' . $this->name;
        $helper->show_toolbar = false;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = [
            MarginConfig::RECOMPUTE_DAYS => MarginConfig::getRecomputeDays(),
            MarginConfig::MARGIN_GREEN => MarginConfig::getMarginGreen(),
            MarginConfig::MARGIN_ORANGE => MarginConfig::getMarginOrange(),
            MarginConfig::GIFTCARD_PRODUCTS => MarginConfig::getGiftCardProductsRaw(),
        ];

        return $helper->generateForm($form);
    }

    /**
     * @return string
     */
    private function renderPanel()
    {
        $baseUrl = $this->context->link->getModuleLink($this->name, 'cron', [], true);
        $sep = (false === strpos($baseUrl, '?')) ? '?' : '&';
        $token = MarginConfig::getCronToken();
        $adminToken = Tools::getAdminTokenLite('AdminModules');
        $cfgIndex = AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . $adminToken;

        $this->context->smarty->assign([
            'stats' => OrderMarginRepository::getStats(),
            'rules_url' => $this->context->link->getAdminLink('AdminSj4webMarginDropRules'),
            'list_url' => $this->context->link->getAdminLink('AdminSj4webMargeCommandeFees'),
            'cron_url' => $baseUrl . $sep . 'token=' . $token,
            'form_action' => $cfgIndex,
            'regen_url' => $cfgIndex . '&sj4web_margin_regen_token=1',
        ]);

        return $this->display(__FILE__, 'views/templates/admin/config_panel.tpl');
    }
}
