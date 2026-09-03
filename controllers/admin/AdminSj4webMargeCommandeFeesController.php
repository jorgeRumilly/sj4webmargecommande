<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * Order margins list. Reads the precomputed cache `sj4web_order_margin` only:
 * filtering / sorting / pagination all happen in SQL, so it scales.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSj4webMargeCommandeFeesController extends ModuleAdminController
{
    /** Advanced numeric range filters persisted in the employee cookie. */
    const RANGE_FILTERS = [
        'margin_min', 'margin_max',
        'margin_rate_min', 'margin_rate_max',
        'markup_rate_min', 'markup_rate_max',
        'commission_percent_min', 'commission_percent_max',
    ];

    /** Advanced tri-state select filters ('' = any, '1' = with, '0' = without), same cookie mechanism. */
    const SELECT_FILTERS = ['has_store_credit'];

    public function __construct()
    {
        $this->bootstrap = true;
        $this->context = Context::getContext();
        parent::__construct();

        require_once _PS_MODULE_DIR_ . 'sj4webmargecommande/classes/MarginConfig.php';
        require_once _PS_MODULE_DIR_ . 'sj4webmargecommande/classes/OrderMarginRepository.php';
    }

    /**
     * @return void
     */
    /** Native column filter keys persisted in the employee cookie. */
    const NATIVE_FILTERS = ['sj4wmfeesFilter_m!id_order', 'sj4wmfeesFilter_m!payment_method', 'sj4wmfeesFilter_m!date_order'];

    public function postProcess()
    {
        if (Tools::isSubmit('sj4web_margin_apply_ranges')) {
            foreach (array_merge(self::RANGE_FILTERS, self::SELECT_FILTERS) as $k) {
                $this->context->cookie->{'sj4wm_' . $k} = (string) Tools::getValue($k, '');
            }
        }
        if (Tools::isSubmit('sj4web_margin_reset_ranges')) {
            foreach (array_merge(self::RANGE_FILTERS, self::SELECT_FILTERS) as $k) {
                $this->context->cookie->__unset('sj4wm_' . $k);
            }
        }

        // Persist native column filters / sort so they survive pagination.
        if (Tools::isSubmit('submitFiltersj4wmfees') || Tools::isSubmit('submitResetsj4wmfees')) {
            $reset = Tools::isSubmit('submitResetsj4wmfees');
            foreach (self::NATIVE_FILTERS as $k) {
                if ($reset) {
                    $this->context->cookie->__unset($k);
                } else {
                    $v = Tools::getValue($k, null);
                    $this->context->cookie->$k = is_array($v) ? json_encode($v) : (string) $v;
                }
            }
        }
        foreach (['sj4wmfeesOrderby', 'sj4wmfeesOrderway'] as $k) {
            if (Tools::getIsset($k)) {
                $this->context->cookie->$k = (string) Tools::getValue($k);
            }
        }

        if (Tools::getIsset('export')) {
            $this->exportCsv();
        }

        parent::postProcess();
    }

    /**
     * @param string $key
     *
     * @return mixed cookie-persisted value for a native filter
     */
    private function nativeFilter($key)
    {
        $cookie = isset($this->context->cookie->$key) ? $this->context->cookie->$key : null;
        $value = Tools::getValue($key, $cookie);
        if (is_string($value) && '' !== $value && '[' === $value[0]) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : $value;
        }

        return $value;
    }

    /**
     * @return void
     */
    public function initContent()
    {
        parent::initContent();

        $filters = $this->collectFilters();
        $orderBy = Tools::getValue('sj4wmfeesOrderby', $this->context->cookie->sj4wmfeesOrderby ?? 'id_order');
        $orderWay = Tools::getValue('sj4wmfeesOrderway', $this->context->cookie->sj4wmfeesOrderway ?? 'DESC');

        $page = max(1, (int) Tools::getValue('submitFiltersj4wmfees', 1));
        $limit = (int) Tools::getValue('sj4wmfees_pagination', $this->context->cookie->sj4wmfees_pagination ?? 50);
        if (Tools::getIsset('sj4wmfees_pagination')) {
            $this->context->cookie->sj4wmfees_pagination = (int) $limit;
        }
        $offset = ($page - 1) * $limit;

        $total = OrderMarginRepository::count($filters);
        $rows = OrderMarginRepository::search($filters, $orderBy, $orderWay, $offset, $limit);
        $data = array_map([$this, 'decorateRow'], $rows);

        $helper = new HelperList();
        $helper->simple_header = false;
        $helper->identifier = 'id_order';
        $helper->show_toolbar = true;
        $helper->module = $this->module;
        $helper->title = 'Marges commandes';
        $helper->table = 'sj4wmfees';
        $helper->list_id = 'sj4wmfees';
        $helper->no_link = true;
        $helper->currentIndex = AdminController::$currentIndex;
        $helper->token = Tools::getAdminTokenLite('AdminSj4webMargeCommandeFees');
        $helper->listTotal = $total;
        $helper->_default_pagination = 50;
        $helper->_pagination = [20, 50, 100, 300];
        $helper->orderBy = $orderBy;
        $helper->orderWay = $orderWay;

        $this->context->smarty->assign([
            'button_exel' => $this->renderExportButton(),
            'custom_filters' => array_merge($this->currentRangeValues(), $this->currentSelectValues()),
        ]);

        $this->context->smarty->assign('content', $helper->generateList($data, $this->fieldsList()));
    }

    /**
     * @return array
     */
    private function fieldsList()
    {
        return [
            'id_order' => ['title' => 'ID', 'filter_key' => 'm!id_order', 'callback' => 'renderLinkToOrder'],
            'date_order' => ['title' => 'Date', 'type' => 'datetime', 'filter_key' => 'm!date_order'],
            'revenue_ht' => ['title' => 'Revenu HT', 'type' => 'price', 'currency' => true, 'search' => false],
            'cost_price_ht' => ['title' => "Coût d'achat HT", 'type' => 'price', 'currency' => true, 'search' => false],
            'drop_cost_ht' => ['title' => 'Coût fourn. HT', 'search' => false, 'callback' => 'renderDropCost'],
            'shipping_charged_ht' => ['title' => 'Port facturé', 'type' => 'price', 'currency' => true, 'search' => false],
            'port_paid_ht' => ['title' => 'Port payé', 'type' => 'price', 'currency' => true, 'search' => false],
            'commission_ht' => ['title' => 'Commission HT', 'search' => false, 'callback' => 'renderCommissionColor'],
            'commission_percent' => ['title' => '% Comm', 'suffix' => '%', 'search' => false],
            'refund_products_ht' => ['title' => 'Remb. produits HT', 'type' => 'price', 'currency' => true, 'search' => false],
            'nb_products' => ['title' => 'Nb prod.', 'search' => false],
            'payment_method' => ['title' => 'Paiement', 'filter_key' => 'm!payment_method'],
            'has_store_credit' => ['title' => 'Avoir/CC', 'search' => false, 'callback' => 'renderStoreCredit', 'align' => 'center'],
            'net_margin' => ['title' => 'Marge nette', 'search' => false, 'callback' => 'renderMarginColor'],
            'markup_rate' => ['title' => 'Taux marque', 'suffix' => '%', 'search' => false],
            'margin_rate' => ['title' => 'Taux marge', 'suffix' => '%', 'search' => false],
        ];
    }

    /**
     * @param array $row
     *
     * @return array
     */
    public function decorateRow(array $row)
    {
        $row['id_order'] = (int) $row['id_order'];
        if (!empty($row['cost_incomplete'])) {
            $row['payment_method'] = trim($row['payment_method'] . ' ⚠');
        }

        return $row;
    }

    /**
     * Build the SQL filter set from BO native filters + the advanced range form.
     *
     * @return array
     */
    private function collectFilters()
    {
        $f = [];

        $idOrder = $this->nativeFilter('sj4wmfeesFilter_m!id_order');
        if (is_numeric($idOrder)) {
            $f['id_order'] = (int) $idOrder;
        }
        $payment = $this->nativeFilter('sj4wmfeesFilter_m!payment_method');
        if (is_string($payment) && '' !== $payment) {
            $f['payment'] = $payment;
        }
        $date = $this->nativeFilter('sj4wmfeesFilter_m!date_order');
        if (is_array($date)) {
            if (!empty($date[0])) {
                $f['date_from'] = $date[0];
            }
            if (!empty($date[1])) {
                $f['date_to'] = $date[1];
            }
        }

        foreach ($this->currentRangeValues() as $k => $v) {
            if ('' !== $v && is_numeric(str_replace(',', '.', $v))) {
                $f[$k] = (float) str_replace(',', '.', $v);
            }
        }

        foreach ($this->currentSelectValues() as $k => $v) {
            if ('1' === $v || '0' === $v) {
                $f[$k] = (int) $v;
            }
        }

        return $f;
    }

    /**
     * @return array
     */
    private function currentRangeValues()
    {
        $out = [];
        foreach (self::RANGE_FILTERS as $k) {
            $out[$k] = (string) Tools::getValue($k, $this->context->cookie->{'sj4wm_' . $k} ?? '');
        }

        return $out;
    }

    /**
     * @return array
     */
    private function currentSelectValues()
    {
        $out = [];
        foreach (self::SELECT_FILTERS as $k) {
            $out[$k] = (string) Tools::getValue($k, $this->context->cookie->{'sj4wm_' . $k} ?? '');
        }

        return $out;
    }

    /**
     * @return string
     */
    private function renderExportButton()
    {
        $url = AdminController::$currentIndex . '&export=1&token=' . Tools::getAdminTokenLite('AdminSj4webMargeCommandeFees');

        return '<a class="btn btn-default" href="' . $url . '"><i class="icon-download"></i> Export CSV</a>';
    }

    /**
     * @return void
     */
    private function exportCsv()
    {
        $filters = $this->collectFilters();
        $orderBy = Tools::getValue('sj4wmfeesOrderby', 'id_order');
        $orderWay = Tools::getValue('sj4wmfeesOrderway', 'DESC');
        $rows = OrderMarginRepository::search($filters, $orderBy, $orderWay, 0, 100000);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="marge_commandes.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'id_order', 'date', 'revenue_ht', 'cost_price_ht', 'drop_cost_ht', 'commission_ht',
            'shipping_charged_ht', 'port_paid_ht', 'commission_percent', 'refund_products_ht',
            'refund_shipping_ht', 'store_credit_ht', 'gift_card_sales_ht', 'has_store_credit',
            'nb_products', 'payment_method', 'cost_incomplete', 'port_pending',
            'net_margin', 'markup_rate', 'margin_rate',
        ]);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id_order'], $r['date_order'], $r['revenue_ht'], $r['cost_price_ht'], $r['drop_cost_ht'],
                $r['commission_ht'], $r['shipping_charged_ht'], $r['port_paid_ht'], $r['commission_percent'],
                $r['refund_products_ht'], $r['refund_shipping_ht'], $r['store_credit_ht'] ?? 0,
                $r['gift_card_sales_ht'] ?? 0, $r['has_store_credit'] ?? 0,
                $r['nb_products'], $r['payment_method'],
                $r['cost_incomplete'], $r['port_pending'], $r['net_margin'], $r['markup_rate'], $r['margin_rate'],
            ]);
        }
        fclose($out);
        exit;
    }

    // ---- HelperList cell callbacks -------------------------------------------

    /**
     * @param int   $idOrder
     * @param array $row
     *
     * @return string
     */
    public static function renderLinkToOrder($idOrder, $row)
    {
        $link = Context::getContext()->link->getAdminLink('AdminOrders', true, [], ['id_order' => (int) $idOrder, 'vieworder' => 1]);

        return '<a href="' . $link . '" target="_blank">' . (int) $idOrder . '</a>';
    }

    /**
     * @param mixed $value
     * @param array $row
     *
     * @return string
     */
    public static function renderMarginColor($value, $row)
    {
        require_once _PS_MODULE_DIR_ . 'sj4webmargecommande/classes/MarginConfig.php';
        $green = MarginConfig::getMarginGreen();
        $orange = MarginConfig::getMarginOrange();
        $color = ((float) $value >= $green) ? '#00994d' : (((float) $value >= $orange) ? '#e67e00' : '#cc0000');

        return '<strong style="color:' . $color . ';">' . Tools::displayPrice((float) $value) . '</strong>';
    }

    /**
     * @param mixed $value
     * @param array $row
     *
     * @return string
     */
    public static function renderCommissionColor($value, $row)
    {
        $pct = isset($row['commission_percent']) ? (float) $row['commission_percent'] : 0;
        $color = ($pct >= 15) ? '#cc0000' : (($pct >= 10) ? '#e67e00' : (($pct >= 5) ? '#1a6fb0' : 'inherit'));

        return '<span style="color:' . $color . ';font-weight:bold;">' . Tools::displayPrice((float) $value) . '</span>';
    }

    /**
     * @param mixed $value
     * @param array $row
     *
     * @return string
     */
    public static function renderDropCost($value, $row)
    {
        $html = Tools::displayPrice((float) $value);
        if (!empty($row['port_pending'])) {
            $html .= ' <span style="color:#e67e00;" title="Port réel non renseigné">⚠</span>';
        }

        return $html;
    }

    /**
     * @param mixed $value m.has_store_credit
     * @param array $row
     *
     * @return string
     */
    public static function renderStoreCredit($value, $row)
    {
        if (empty($value) || '0' === (string) $value) {
            return '';
        }

        $parts = [];
        if ((float) ($row['gift_card_sales_ht'] ?? 0) > 0) {
            $parts[] = 'vente carte cadeau ' . Tools::displayPrice((float) $row['gift_card_sales_ht']);
        }
        if ((float) ($row['store_credit_ht'] ?? 0) > 0) {
            $parts[] = 'avoir / carte cadeau ' . Tools::displayPrice((float) $row['store_credit_ht']);
        }
        $title = htmlspecialchars(implode(' · ', $parts), ENT_QUOTES, 'UTF-8');

        return '<span class="badge badge-info" title="' . $title . '">&#127873;</span>';
    }
}
