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

        $result = $this->getFilteredOrders();
        $data = $result['data'];
        $nb_orders = (int)$result['nb_orders'];

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

        // Reconstruire currentIndex avec les paramètres persistés
//        $params = $this->getCurrentParams();
//        $queryString = http_build_query($params);
//        $currentIndex = $baseIndex . ($queryString ? '&' . $queryString : '');

        $helper = new HelperList();
        $helper->title = 'Marge Commande - Liste des commandes';
        $helper->shopLinkType = '';
        $helper->simple_header = false;
        $helper->identifier = 'id_order';
        $helper->show_toolbar = true;
        $helper->module = $this->module;
        $helper->table = 'sj4webmargecommande_fees';
//        $helper->currentIndex = $currentIndex;
        $helper->currentIndex = $baseIndex;
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
        $this->context->smarty->assign('custom_filters', [
            'filter_min_total_ttc' => Tools::getValue('filter_min_total_ttc', $this->context->cookie->{'filter_min_total_ttc'} ?? null),
            'filter_max_total_ttc' => Tools::getValue('filter_max_total_ttc', $this->context->cookie->{'filter_max_total_ttc'} ?? null),
            'filter_min_margin_rate' => Tools::getValue('filter_min_margin_rate', $this->context->cookie->{'filter_min_margin_rate'} ?? null),
            'filter_max_margin_rate' => Tools::getValue('filter_max_margin_rate', $this->context->cookie->{'filter_max_margin_rate'} ?? null),
            'filter_min_commission_percent' => Tools::getValue('filter_min_commission_percent', $this->context->cookie->{'filter_min_commission_percent'} ?? null),
            'filter_max_commission_percent' => Tools::getValue('filter_max_commission_percent', $this->context->cookie->{'filter_max_commission_percent'} ?? null),
            'filter_min_fees_rate' => Tools::getValue('filter_min_fees_rate', $this->context->cookie->{'filter_min_fees_rate'} ?? null),
            'filter_max_fees_rate' => Tools::getValue('filter_max_fees_rate', $this->context->cookie->{'filter_max_fees_rate'} ?? null),
        ]);
        $this->context->smarty->assign('content', $helper->generateList($data, $fields_list));
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

        if(Tools::getIsset('sj4webmargecommande_feesOrderby') && Tools::getIsset('sj4webmargecommande_feesOrderway')) {
            $this->context->cookie->sj4webmargecommande_feesOrderby = Tools::getValue('sj4webmargecommande_feesOrderby');
            $this->context->cookie->sj4webmargecommande_feesOrderway = Tools::getValue('sj4webmargecommande_feesOrderway');
        } else {
            // Si pas de tri, on remet les valeurs par défaut
            $this->context->cookie->sj4webmargecommande_feesOrderby = 'id_order';
            $this->context->cookie->sj4webmargecommande_feesOrderway = 'DESC';
        }

        if (Tools::isSubmit('submitFiltersj4webmargecommande_fees') ||
            Tools::isSubmit('submitFilterButtonsj4webmargecommande_fees')) {
            $filters = Tools::getAllValues();
            $allowedKeys = $this->getFilterParamKeys();
            foreach ($allowedKeys as $key) {
                if (isset($filters[$key])) {
                    $value = $filters[$key];
                    if (is_array($value)) {
                        $value = json_encode($value); // conversion en chaîne JSON
                    }
                    $this->context->cookie->__set($key, $value);
                }
            }
        }

        if (Tools::isSubmit('submitResetsj4webmargecommande_fees')) {
            foreach ($this->getFilterParamKeys() as $key) {
                $this->context->cookie->__unset($key);
                // unset($this->context->cookie->$key);
            $_GET = array_filter($_GET, function ($searchkey) use ($key) {
                return strpos($searchkey, $key) === false;
            }, ARRAY_FILTER_USE_KEY);
            $_POST = array_filter($_POST, function ($searchkey) use($key) {
                return strpos($searchkey, $key) === false;
            }, ARRAY_FILTER_USE_KEY);
            }
        }

        if (Tools::getIsset('export')) {
            $this->exportCsv();
        }

    }

    protected function getCurrentParams(): array
    {
        $params = [];
        foreach ($this->getFilterParamKeys() as $key) {
            $val = Tools::getValue($key, $this->context->cookie->$key ?? null);

            // Désérialisation JSON si applicable
            if (is_string($val) && $this->looksLikeJsonArray($val)) {
                $decoded = json_decode($val, true);
                $params[$key] = is_array($decoded) ? $decoded : $val;
            } else {
                $params[$key] = $val;
            }
        }

        return $params;
    }

    /**
     * Check if a string looks like a JSON array.
     * @param string $str
     * @return bool
     */
    protected function getFilterParamKeys(): array
    {
        return [
            // Filtres BO natifs
            'sj4webmargecommande_feesFilter_o!id_order',
            'sj4webmargecommande_feesFilter_o!payment',
            'sj4webmargecommande_feesFilter_o!date_add',

            // Filtres avancés
            'filter_min_total_ttc',
            'filter_max_total_ttc',
            'filter_min_margin_rate',
            'filter_max_margin_rate',
            'filter_min_commission_percent',
            'filter_max_commission_percent',
            'filter_min_fees_rate',
            'filter_max_fees_rate',

            // Tri / pagination
            'sj4webmargecommande_feesOrderby',
            'sj4webmargecommande_feesOrderway',
            'sj4webmargecommande_fees_pagination',

            // Flag de soumission
            'submitFiltersj4webmargecommande_fees',
            'submitFilterButtonsj4webmargecommande_fees',
        ];

    }

    /**
     * Check if a string looks like a JSON array.
     * @param mixed $val
     * @return bool
     */
    protected function looksLikeJsonArray($val): bool
    {
        return is_string($val) && strlen($val) > 2 && $val[0] === '[' && $val[strlen($val) - 1] === ']';
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

        $data = $this->getFilteredOrders(false);

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

        $page = max(1, (int)Tools::getValue('submitFiltersj4webmargecommande_fees', $this->context->cookie->{'submitFiltersj4webmargecommande_fees'} ?? 1));
        $limit = (int)Tools::getValue('sj4webmargecommande_fees_pagination', $this->context->cookie->{'sj4webmargecommande_fees_pagination'} ?? 20);
        $offset = ($page - 1) * $limit;

        // Requête brute : on récupère toutes les commandes
        $disableLimit = $this->isSortOnComputedField() || $this->hasFilteredComputedFields();
        if (!$disableLimit) {
            $sql = $this->getSqlOrderFees(true, false);
            $nb_orders = (int)Db::getInstance()->getValue($sql);
        }

        $sql = $this->getSqlOrderFees(false, !$disableLimit, $offset, $limit);
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

        // selection des champs calculés
        $data = $this->filterEntries($data);
        if ($disableLimit) {
            $nb_orders = count($data);
        }
        // Tri des entrées
        $data = $this->sortEntries($data);
        if ($disableLimit) {
            /* Pagination dans le cas de tri sur les champs calculés */
            $data = array_slice($data, $offset, $limit);
        }
        return ['nb_orders' => $nb_orders ?? count($data), // nombre total de commandes
            'data' => $data, // données filtrées et triées
        ];
    }

    /**
     * Get the WHERE clause for filtering orders.
     * @return array
     */
    public function getWhereclause(): array
    {
        $whereclause = [];

        $whereclause[] = 'o.valid = 1'; // commandes validées uniquement

        // Moyen de paiement
        $payment = Tools::getValue('sj4webmargecommande_feesFilter_o!payment', $this->context->cookie->{'sj4webmargecommande_feesFilter_o!payment'} ?? null);
        if ($payment !== null && $payment !== '') {
            $whereclause[] = 'o.payment LIKE \'%' . pSQL($payment) . '%\'';
        }

        // Date ajout
        $date_add = Tools::getValue('sj4webmargecommande_feesFilter_o!date_add', json_decode($this->context->cookie->{'sj4webmargecommande_feesFilter_o!date_add'} ?? '[]', true));
        if (is_array($date_add)) {
            if (!empty($date_add[0])) {
                $whereclause[] = 'o.date_add >= \'' . pSQL($date_add[0]) . '\'';
            }
            if (!empty($date_add[1])) {
                $whereclause[] = 'o.date_add <= \'' . pSQL($date_add[1]) . '\'';
            }
        }

        // ID commande
        $id_order = Tools::getValue('sj4webmargecommande_feesFilter_o!id_order', $this->context->cookie->{'sj4webmargecommande_feesFilter_o!id_order'} ?? null);
        if (!empty($id_order)) {
            $whereclause[] = 'o.id_order = ' . (int)$id_order;
        }

        // Total TTC min
        $minTotal = Tools::getValue('filter_min_total_ttc', $this->context->cookie->{'filter_min_total_ttc'} ?? null);
        if ($minTotal !== null && $minTotal !== '') {
            $whereclause[] = 'o.total_paid_tax_incl >= ' . (float)$minTotal;
        }

        // Total TTC max
        $maxTotal = Tools::getValue('filter_max_total_ttc', $this->context->cookie->{'filter_max_total_ttc'} ?? null);
        if ($maxTotal !== null && $maxTotal !== '') {
            $whereclause[] = 'o.total_paid_tax_incl <= ' . (float)$maxTotal;
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
        $orderby = Tools::getValue('sj4webmargecommande_feesOrderby', $this->context->cookie->{'sj4webmargecommande_feesOrderby'} ?? null);
        $orderway = strtolower(Tools::getValue('sj4webmargecommande_feesOrderway', $this->context->cookie->{'sj4webmargecommande_feesOrderway'} ?? 'DESC')) === 'desc' ? SORT_DESC : SORT_ASC;
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

    /**
     * Check if the current sort is on a computed field.
     * @return bool
     */
    protected function isSortOnComputedField(): bool
    {
        $orderby = Tools::getValue('sj4webmargecommande_feesOrderby');
        return in_array($orderby, [
            'refund_products_ttc',
            'refund_shipping_ttc',
            'nb_products',
            'commission_ttc',
            'commission_percent',
            'dropshipping_fees',
            'margin',
            'margin_rate',
            'markup_rate'
        ]);
    }

    /**
     * Filter the entries based on user input.
     * @param array $data
     * @return array
     */
    public function filterEntries(array $data): array
    {
        $filters = $this->getAdvancedCalculatedFilters();

        // Si aucun filtre actif, on retourne directement les données
        $allDefaults = array_reduce($filters, function ($carry, $f) {
            return $carry && $f['min'] === -1000 && $f['max'] === 1000;
        }, true);

        // si c que les valeurs par défaut, on retourne les données sans filtrage
        if ($allDefaults) {
            return $data;
        }

        // Pour chaque entrée, on vérifie si toutes les valeurs des champs calculés respectent les plages de filtres définies. Si oui, on conserve l'entrée dans le tableau filtré.
        $filteredData = [];
        foreach ($data as $row) {
            $isValid = true;

            foreach ($filters as $key => $range) {
                $value = $this->parsePercentage($row[$key] ?? '0');
                if ($value < $range['min'] || $value > $range['max']) {
                    $isValid = false;
                    break;
                }
            }

            if ($isValid) {
                $filteredData[] = $row;
            }
        }

        return $filteredData;
    }

    /**
     * Get advanced calculated filters based on user input.
     * @return array[]
     */
    public function getAdvancedCalculatedFilters(): array
    {
        $filters = [
            'margin_rate' => [
                'min' => $this->getFilteredValue('filter_min_margin_rate', -1000),
                'max' => $this->getFilteredValue('filter_max_margin_rate', 1000),
            ],
            'commission_percent' => [
                'min' => $this->getFilteredValue('filter_min_commission_percent', -1000),
                'max' => $this->getFilteredValue('filter_max_commission_percent', 1000),
            ],
            'markup_rate' => [
                'min' => $this->getFilteredValue('filter_min_fees_rate', -1000),
                'max' => $this->getFilteredValue('filter_max_fees_rate', 1000),
            ],
        ];
        return $filters;
    }

    /**
     * Get a filtered float value from user input.
     * @param string $key
     * @param float $default
     * @param string $type
     * @return float|int|bool
     */
    private function getFilteredValue(string $key, $default, string $type = 'float')
    {
        $value = Tools::getValue($key, $this->context->cookie->{$key} ?? null);
        if ($value === '' || $value === null) {
            return $default;
        }

        switch ($type) {
            case 'int':
                return is_numeric($value) ? (int)$value : $default;
            case 'bool':
                return (bool)$value;
            case 'float':
            default:
                $value = str_replace(['%', ' ', ','], ['', '', '.'], $value);
                return is_numeric($value) ? (float)$value : $default;
        }
    }

    public function hasFilteredComputedFields(): bool
    {
        $filters = $this->getAdvancedCalculatedFilters();
        foreach ($filters as $range) {
            if ($range['min'] !== -1000 || $range['max'] !== 1000) {
                return true; // Au moins un filtre actif
            }
        }
        return false; // Aucun filtre actif
    }


    /**
     * Parse a percentage value from a string.
     * @param string $value
     * @return float
     */
    private function parsePercentage($value): float
    {
        return floatval(str_replace(['%', ' ', ','], ['', '', '.'], $value));
    }

    /**
     * Check if the current request is an initial load (no filters applied).
     * @return bool
     */
    protected function isInitialLoad(): bool
    {
        $keys = [
            'submitFiltersj4webmargecommande_fees',
            'submitFilterButtonsj4webmargecommande_fees',
            'sj4webmargecommande_feesOrderby',
            'sj4webmargecommande_feesOrderway',
            'sj4webmargecommande_fees_pagination',
        ];

        foreach ($keys as $key) {
            if (Tools::getIsset($key)) {
                return false;
            }
        }

        // Aucun paramètre de filtre actif → c’est un accès "vierge"
        return true;
    }


}
