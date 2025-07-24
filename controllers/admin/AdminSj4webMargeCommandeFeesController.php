<?php

class AdminSj4webMargeCommandeFeesController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        parent::initContent();

        $this->meta_title = $this->trans('Marge Commande - Liste des commandes', [], 'Modules.Sj4webMargeCommande.Admin');

        // Requête brute : on récupère toutes les commandes
        $sql = $this->getSqlOrderFees(true, false);
        $nb_orders = (int)Db::getInstance()->getValue($sql);

        $data = $this->getFilteredOrders();

        $fields_list = [
            'id_order' => ['title' => 'ID', 'filter_key' => 'o!id_order', 'type' => 'int', 'callback' => 'renderLinkToOrder'],
            'date_add' => ['title' => 'Date', 'type' => 'datetime', 'filter_key' => 'o!date_add'],
            'total_paid_tax_excl' => ['title' => 'Total HT', 'type' => 'price', 'currency' => true, 'search' => false, 'filter' => false],
            'total_paid_tax_incl' => ['title' => 'Total TTC', 'type' => 'price', 'currency' => true, 'search' => false, 'filter' => false],
            'total_shipping_tax_excl' => ['title' => 'Livraison HT', 'type' => 'price', 'currency' => true, 'search' => false, 'filter' => false],
            'total_shipping_tax_incl' => ['title' => 'Livraison TTC', 'type' => 'price', 'currency' => true, 'search' => false, 'filter' => false],
            'refund_products_ttc' => ['title' => 'Remb. produits TTC', 'type' => 'price', 'currency' => true, 'search' => false, 'filter' => false],
            'refund_shipping_ttc' => ['title' => 'Remb. livraison TTC', 'type' => 'price', 'currency' => true, 'search' => false, 'filter' => false],
            'nb_products' => ['title' => 'Nb Produits', 'search' => false, 'filter' => false],
            'payment_method' => ['title' => 'Moyen paiement', 'type' => 'text', 'filter_key' => 'o!payment'],
            'commission_ttc' => ['title' => 'Commission TTC', 'type' => 'price', 'currency' => true, 'search' => false, 'filter' => false, 'callback' => 'renderCommissionColor'],
            'commission_percent' => ['title' => '% Commission', 'suffix' => '%', 'search' => false, 'filter' => false,],
            'dropshipping_fees' => ['title' => 'Coût dropshipping', 'type' => 'price', 'currency' => true, 'search' => false, 'filter' => false],
            'margin' => ['title' => 'Marge nette', 'type' => 'price', 'currency' => true, 'search' => false, 'filter' => false, 'callback' => 'renderMarginColor'],
            'margin_rate' => ['title' => 'Taux de marge', 'suffix' => '%', 'search' => false, 'filter' => false,],
            'markup_rate' => ['title' => 'Taux de marque', 'suffix' => '%', 'search' => false, 'filter' => false,],
        ];

        $baseIndex = AdminController::$currentIndex;
        $token = Tools::getAdminTokenLite('AdminSj4webMargeCommandeFees');
        // Conserve les paramètres actifs
//        $params = [];
//        if (Tools::getValue('sj4webmargecommande_feesOrderby')) {
//            $params['sj4webmargecommande_feesOrderby'] = Tools::getValue('sj4webmargecommande_feesOrderby');
//        }
//        if (Tools::getValue('sj4webmargecommande_feesOrderway')) {
//            $params['sj4webmargecommande_feesOrderway'] = Tools::getValue('sj4webmargecommande_feesOrderway');
//        }
//        if (Tools::getValue('sj4webmargecommande_fees_pagination')) {
//            $params['sj4webmargecommande_fees_pagination'] = Tools::getValue('sj4webmargecommande_fees_pagination');
//        }

        // Reconstruire currentIndex avec les paramètres persistés
        $params = $this->getCurrentParams();
        $queryString = http_build_query($params);
        $currentIndex = $baseIndex . ($queryString ? '&' . $queryString : '');

        $helper = new HelperList();
        $helper->title = 'Marge Commande - Liste des commandes';
        $helper->shopLinkType = '';
        $helper->simple_header = false;
        $helper->identifier = 'id_order';
        $helper->show_toolbar = true;
        $helper->module = $this->module;
        $helper->table = 'sj4webmargecommande_fees';
        $helper->currentIndex = $currentIndex;
//        $helper->token = Tools::getAdminTokenLite('AdminSj4webMargeCommandeFees');
        $helper->token = $token;
        $helper->listTotal = $nb_orders; // ou utilise SQL COUNT pour perf count($data)
        $helper->tpl_vars['pagination'] = [20, 50, 100, 300];
        $helper->tpl_vars['show_toolbar'] = true;
        $helper->tpl_vars['show_pagination'] = true;
        $helper->tpl_vars['show_filters'] = true;
        $helper->no_link = true; // pour éviter les liens automatiques sur les champs
        $helper->_default_pagination = 20;
        $helper->orderBy = 'id_order';
        $helper->orderWay = 'DESC';
        $button_exel = $this->getHtmlCsvButton();
        $this->context->smarty->assign(['button_exel' => $button_exel]);
        $this->context->smarty->assign('content', $helper->generateList($data, $fields_list));
    }

    protected function getCurrentParams(): array
    {
        $keys = [
            'sj4webmargecommande_feesFilter_o!id_order',
            'sj4webmargecommande_feesFilter_o!payment',
            'sj4webmargecommande_feesFilter_o!date_add',
            'sj4webmargecommande_feesOrderby',
            'sj4webmargecommande_feesOrderway',
            'sj4webmargecommande_fees_pagination',
            'submitFiltersj4webmargecommande_fees',
        ];

        $params = [];
        foreach ($keys as $key) {
            if (Tools::getIsset($key)) {
                $params[$key] = Tools::getValue($key);
            }
        }

        return $params;
    }


    public function renderList()
    {
        return parent::renderList();
    }

    protected function getOrderNbProducts($orderId): int
    {
        $sql = 'SELECT SUM(product_quantity)
                FROM ' . _DB_PREFIX_ . 'order_detail
                WHERE id_order = ' . (int)$orderId;
        return (int)Db::getInstance()->getValue($sql);
    }

    protected function getOrderRefunds($orderId): array
    {
        $sql = 'SELECT
                    SUM(total_products_tax_incl) as refund_products,
                    SUM(total_shipping_tax_incl) as refund_shipping
                FROM ' . _DB_PREFIX_ . 'order_slip
                WHERE id_order = ' . (int)$orderId;

        $result = Db::getInstance()->getRow($sql);

        return [
            'products' => $result['refund_products'] ?? 0,
            'shipping' => $result['refund_shipping'] ?? 0,
        ];
    }

    /**
     * @param int $offset
     * @param int $limit
     * @return string
     */
    public function getSqlOrderFees(bool $count = false, $useLimits = true, int $offset = 0, int $limit = 50): string
    {
        $whereClause = $this->getWhereclause();
        $sql_select = 'SELECT ';
        if ($count) {
            $sql_select .= 'COUNT(o.id_order) AS total';
        } else {
            $sql_select .= 'o.id_order, o.reference, o.date_add, 
                           o.total_paid_tax_excl, o.total_paid_tax_incl,
                           o.total_shipping_tax_excl, o.total_shipping_tax_incl,
                           o.payment AS payment_method';
        }
        $sql_from = ' FROM ' . _DB_PREFIX_ . 'orders o ';
        $sql_where = (count($whereClause) ? ' WHERE ' . implode(' AND ', $whereClause) : '');
        $sql_order = $this->getOrderClause();
        $sql_order = (($sql_order) ?: ' ORDER BY o.date_add DESC ');
        $sql_limit = ($useLimits ? ' LIMIT ' . (int)$offset . ', ' . (int)$limit : '');
        return $sql_select . $sql_from . $sql_where . $sql_order . $sql_limit;
    }

    public static function renderLinkToOrder($id_order, $row)
    {
        $link = Context::getContext()->link->getAdminLink('AdminOrders', true, [], [
            'id_order' => $id_order,
            'vieworder' => 1
        ]);

        return '<a href="' . $link . '" target="_blank">' . (int)$id_order . '</a>';
    }

    public static function renderMarginColor($value, $row)
    {
        $color = 'red';
        if ($value >= 50) {
            $color = 'green';
        } elseif ($value >= 10) {
            $color = 'orange';
        }

        return '<span style="color:' . $color . '; font-weight:bold;">' . Tools::displayPrice($value) . '</span>';
    }

    public static function renderCommissionColor($value, $row)
    {
        $color = 'inherit';
        if ($value >= 15) {
            $color = 'red';
        } elseif ($value >= 10) {
            $color = 'orange';
        } elseif ($value >= 5) {
            $color = 'blue';
        }

        return '<span style="color:' . $color . '; font-weight:bold;">' . Tools::displayPrice($value) . '</span>';
    }

    public function getHtmlCsvButton()
    {
        $params = array_merge(
            $this->getCurrentParams(),
            ['export' => 1]
        );
        $query = http_build_query($params);
        $url = AdminController::$currentIndex . '&' . $query . '&token=' . Tools::getAdminTokenLite('AdminSj4webMargeCommandeFees');

        return '<a class="btn btn-default" href="' . $url . '"><i class="icon-download"></i> Export CSV</a>';
    }

    public function postProcess()
    {
        if (Tools::getIsset('export')) {
            $this->exportCsv();
        }
        if (Tools::isSubmit('submitResetsj4webmargecommande_fees')) {
            $_GET = array_filter($_GET, function ($key) {
                return strpos($key, 'sj4webmargecommande_feesFilter_') === false;
            }, ARRAY_FILTER_USE_KEY);
            $_POST = array_filter($_POST, function ($key) {
                return strpos($key, 'sj4webmargecommande_feesFilter_') === false;
            }, ARRAY_FILTER_USE_KEY);
        }
    }

    protected function exportCsv()
    {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="marge_commandes.csv"');

        $output = fopen('php://output', 'w');

        // Définis les entêtes CSV
        $headers = [
            'ID commande',
            'Date',
            'Total HT',
            'Total TTC',
            'Livraison HT',
            'Livraison TTC',
            'Remb. produits',
            'Remb. livraison',
            'Nb produits',
            'Moyen paiement',
            'Commission TTC',
            '% Commission',
            'Coût drop',
            'Marge HT',
            '% Marge',
            '% Marque',
        ];
        fputcsv($output, $headers);

        $data = $this->getFilteredOrders(true);

        foreach ($data as $row) {
            fputcsv($output, [
                $row['id_order'],
                $row['date_add'],
                $row['total_paid_tax_excl'],
                $row['total_paid_tax_incl'],
                $row['total_shipping_tax_excl'],
                $row['total_shipping_tax_incl'],
                $row['refund_products_ttc'],
                $row['refund_shipping_ttc'],
                $row['nb_products'],
                $row['payment_method'],
                $row['commission_ttc'],
                $row['commission_percent'],
                $row['dropshipping_fees'],
                $row['margin'],
                $row['margin_rate'],
                $row['markup_rate'],
            ]);
        }

        fclose($output);
        exit;
    }


    /**
     * Get filtered orders based on the current filters and pagination.
     * @param bool $useLimits
     * @return array
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function getFilteredOrders(bool $useLimits = true): array
    {

        $page = max(1, (int)Tools::getValue('submitFiltersj4webmargecommande_fees', 1));
        $limit = (int)Tools::getValue('sj4webmargecommande_fees_pagination', 20);
        $offset = ($page - 1) * $limit;

        // Requête brute : on récupère toutes les commandes
        $sql = $this->getSqlOrderFees(false, $useLimits, $offset, $limit);
        $orders = Db::getInstance()->executeS($sql);
        $data = [];

        foreach ($orders as $orderRow) {
            $id_order = (int)$orderRow['id_order'];
            $order = new Order($id_order);
            // Nb produits
            $nb_products = $this->getOrderNbProducts($id_order);
            // Coût dropshipping via ton module existant
            $dropshippingFees = method_exists($this->module, 'calculateDropshippingFees')
                ? $this->module->calculateDropshippingFees($order)
                : 0;
            // Coût d'achat total
            $costPrice = method_exists($this->module, 'getOrderCostPrice')
                ? $this->module->getOrderCostPrice($order)
                : 0;
            // Commission TTC enregistrée
            $commissionTTC = method_exists($this->module, 'getPaymentFees')
                ? $this->module->getPaymentFees($id_order)
                : 0;
            // Montants remboursements
            $refunds = $this->getOrderRefunds($id_order);
            // Marge nette
            $margin = $order->total_paid_tax_excl - $order->total_shipping_tax_excl
                - $costPrice
                - $dropshippingFees
                - $commissionTTC;
            $commission_percent = $order->total_paid_tax_incl > 0
                ? round($commissionTTC / $order->total_paid_tax_incl * 100, 2)
                : 0;
            $margin_rate = $costPrice > 0
                ? round($margin / $costPrice * 100, 2)
                : 0;
            $markup_rate = ($order->total_paid_tax_excl - $order->total_shipping_tax_excl) > 0
                ? round($margin / ($order->total_paid_tax_excl - $order->total_shipping_tax_excl) * 100, 2)
                : 0;

            $data[] = [
                'id_order' => $id_order,
                'date_add' => $orderRow['date_add'],
                'total_paid_tax_excl' => $orderRow['total_paid_tax_excl'],
                'total_paid_tax_incl' => $orderRow['total_paid_tax_incl'],
                'total_shipping_tax_excl' => $orderRow['total_shipping_tax_excl'],
                'total_shipping_tax_incl' => $orderRow['total_shipping_tax_incl'],
                'refund_products_ttc' => $refunds['products'],
                'refund_shipping_ttc' => $refunds['shipping'],
                'nb_products' => $nb_products,
                'payment_method' => $orderRow['payment_method'],
                'commission_ttc' => $commissionTTC,
                'dropshipping_fees' => $dropshippingFees,
                'margin' => $margin,
                'commission_percent' => $commission_percent,
                'margin_rate' => $margin_rate,
                'markup_rate' => $markup_rate,
            ];
        }

        // Tri des entrées
        return $this->sortEntries($data);
    }

    /**
     * @return array
     */
    public function getWhereclause(): array
    {
        $whereclause = [];
        if (Tools::getIsset('sj4webmargecommande_feesFilter_o!payment') && Tools::getValue('sj4webmargecommande_feesFilter_o!payment') !== '') {
            $whereclause[] = 'o.payment LIKE \'%' . pSQL(Tools::getValue('sj4webmargecommande_feesFilter_o!payment')) . '%\'';
        }
        if (Tools::getIsset('sj4webmargecommande_feesFilter_o!date_add')) {
            $date_add = Tools::getValue('sj4webmargecommande_feesFilter_o!date_add');
            if (is_array($date_add) && count($date_add) > 0) {
                if (!empty($date_add[0])) {
                    $whereclause[] = 'o.date_add >= \'' . pSQL($date_add[0]) . '\'';
                }
                if (!empty($date_add[1])) {
                    $whereclause[] = 'o.date_add <= \'' . pSQL($date_add[1]) . '\'';
                }
            }
        }
        if (Tools::getIsset('sj4webmargecommande_feesFilter_o!id_order') && Tools::getValue('sj4webmargecommande_feesFilter_o!id_order') !== '') {
            $whereclause[] = 'o.id_order = ' . (int)Tools::getValue('sj4webmargecommande_feesFilter_o!id_order');
        }
        return $whereclause;
    }

    /**
     * Get the SQL ORDER BY clause based on user input.
     * @return string
     */
    public function getOrderClause()
    {
        $orderby = Tools::getValue('sj4webmargecommande_feesOrderby');
        $orderway = strtolower(Tools::getValue('sj4webmargecommande_feesOrderway', 'DESC')) === 'desc' ? SORT_DESC : SORT_ASC;
        if (!$orderby) {
            return '';
        }
        if (!in_array($orderby, ['id_order', 'date_add', 'total_paid_tax_excl', 'total_paid_tax_incl', 'total_shipping_tax_excl', 'total_shipping_tax_incl', 'payment_method'])) {
            return '';
        }
        if (strtolower($orderby) === 'payment_method') {
            $orderby = 'payment';
        }

        return ' ORDER BY o.' . pSQL($orderby) . ' ' . ($orderway === SORT_DESC ? 'DESC' : 'ASC');
    }

    /**
     * Sort the entries based on user input.
     * @param array $entries
     * @return array
     */
    public function sortEntries(array $entries): array
    {
        $orderby = Tools::getValue('sj4webmargecommande_feesOrderby');
        $orderway = strtolower(Tools::getValue('sj4webmargecommande_feesOrderway', 'DESC')) === 'desc' ? SORT_DESC : SORT_ASC;
        if (!$orderby) {
            return $entries;
        }
        if (!in_array($orderby, ['refund_products_ttc', 'refund_shipping_ttc', 'nb_products', 'commission_ttc', 'commission_percent', 'dropshipping_fees', 'margin', 'margin_rate', 'markup_rate'])) {
            return $entries;
        }

        usort($entries, function ($a, $b) use ($orderby, $orderway) {
            $valA = $a[$orderby];
            $valB = $b[$orderby];

            if (is_numeric($valA) && is_numeric($valB)) {
                return $orderway === SORT_DESC ? $valB <=> $valA : $valA <=> $valB;
            } else {
                // Suppression des espaces, € et % pour comparer les valeurs formatées
                $cleanA = str_replace(['€', '%', ' ', ','], ['', '', '', '.'], $valA);
                $cleanB = str_replace(['€', '%', ' ', ','], ['', '', '', '.'], $valB);
                if (is_numeric($cleanA) && is_numeric($cleanB)) {
                    return $orderway === SORT_DESC ? $cleanB <=> $cleanA : $cleanA <=> $cleanB;
                }
                return $orderway === SORT_DESC ? strcmp($valB, $valA) : strcmp($valA, $valB);
            }

        });

        return $entries;

    }

}
