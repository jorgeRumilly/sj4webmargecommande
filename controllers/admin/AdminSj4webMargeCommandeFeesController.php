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
        $sql = $this->getSqlOrderFees(true);
        $nb_orders = (int)Db::getInstance()->getValue($sql);

        $page = max(1, (int)Tools::getValue('submitFiltersj4webmargecommande_fees', 1));
        $limit = (int)Tools::getValue('sj4webmargecommande_fees_pagination', 20);
        $offset = ($page - 1) * $limit;


        // Requête brute : on récupère toutes les commandes
        $sql = $this->getSqlOrderFees(false, $offset, $limit);
        $orders = Db::getInstance()->executeS($sql);

        $data = [];
        foreach ($orders as $orderRow) {
            $id_order = (int)$orderRow['id_order'];

            // Crée un objet Order PrestaShop natif
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
                'reference' => $orderRow['reference'],
                'date_add' => $orderRow['date_add'],
                'total_paid_tax_excl' => $orderRow['total_paid_tax_excl'],
                'total_paid_tax_incl' => $orderRow['total_paid_tax_incl'],
                'total_shipping_tax_excl' => $orderRow['total_shipping_tax_excl'],
                'total_shipping_tax_incl' => $orderRow['total_shipping_tax_incl'],
                'refund_products_ttc' => $refunds['products'],
                'refund_shipping_ttc' => $refunds['shipping'],
                'nb_products' => $nb_products,
                'payment_method' => $orderRow['payment'],
                'commission_ttc' => $commissionTTC,
                'dropshipping_fees' => $dropshippingFees,
                'margin' => $margin,
                'commission_percent' => $commission_percent,
                'margin_rate' => $margin_rate,
                'markup_rate' => $markup_rate,
            ];
        }

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

        $helper = new HelperList();
        $helper->title = 'Marge Commande - Liste des commandes';
        $helper->shopLinkType = '';
        $helper->simple_header = false;
        $helper->identifier = 'id_order';
        $helper->show_toolbar = true;
        $helper->module = $this->module;
        $helper->table = 'sj4webmargecommande_fees';
//        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->module->name;
        $helper->currentIndex = AdminController::$currentIndex;
        $helper->token = Tools::getAdminTokenLite('AdminSj4webMargeCommandeFees');
//        $helper->actions = ['view']; // facultatif si tu veux des actions
        $helper->listTotal = $nb_orders; // ou utilise SQL COUNT pour perf count($data)
        $helper->tpl_vars['pagination'] = [20, 50, 100, 300];
        $helper->tpl_vars['show_toolbar'] = true;
        $helper->tpl_vars['show_pagination'] = true;
        $helper->tpl_vars['show_filters'] = true;
        $helper->no_link = true; // pour éviter les liens automatiques sur les champs

//        $helper->show_filter = true;
        $helper->_default_pagination = 20;
//        $helper->pagination = [20, 50, 100, 300];
        $helper->orderBy = 'id_order';
        $helper->orderWay = 'DESC';

//        return $helper->generateList($data, $fields_list);
        $_before_html = $this->getHtmlCsvButton();

        $this->context->smarty->assign('content', $_before_html . $helper->generateList($data, $fields_list));
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
    public function getSqlOrderFees(bool $count = false, int $offset = 0, int $limit = 50): string
    {
        if ($count) {
            $sql = 'SELECT COUNT(o.id_order) AS total
                    FROM ' . _DB_PREFIX_ . 'orders o';
            return $sql;
        };

        $sql = 'SELECT o.id_order, o.reference, o.date_add,
                       o.total_paid_tax_excl, o.total_paid_tax_incl,
                       o.total_shipping_tax_excl, o.total_shipping_tax_incl,
                       o.payment
                FROM ' . _DB_PREFIX_ . 'orders o
                ORDER BY o.date_add DESC
                LIMIT ' . (int)$offset . ', ' . $limit;
        return $sql;
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

    public function getHtmlCsvButton() {
        return '<a class="btn btn-default" href="'.AdminController::$currentIndex.'&export=1&token='.Tools::getAdminTokenLite('AdminSj4webMargeCommandeFees').'"><i class="icon-download"></i> Export CSV</a>';
    }

}
