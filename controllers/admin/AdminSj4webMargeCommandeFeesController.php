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
            $margin = $order->total_paid_tax_incl
                - $costPrice
                - $dropshippingFees
                - $commissionTTC;

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
            ];
        }

        $fields_list = [
            'id_order' => ['title' => 'ID', 'filter_key' => 'o!id_order', 'type' => 'int'],
            'reference' => ['title' => 'Référence', 'filter_key' => 'o!reference', 'type' => 'text'],
            'date_add' => ['title' => 'Date', 'type' => 'datetime', 'filter_key' => 'o!date_add'],
            'total_paid_tax_excl' => ['title' => 'Total HT', 'type' => 'price', 'currency' => true, 'search' => false, 'filter' => false],
            'total_paid_tax_incl' => ['title' => 'Total TTC', 'type' => 'price', 'currency' => true, 'search' => false, 'filter' => false],
            'total_shipping_tax_excl' => ['title' => 'Livraison HT', 'type' => 'price', 'currency' => true, 'search' => false, 'filter' => false],
            'total_shipping_tax_incl' => ['title' => 'Livraison TTC', 'type' => 'price', 'currency' => true, 'search' => false, 'filter' => false],
            'refund_products_ttc' => ['title' => 'Remb. produits TTC', 'type' => 'price', 'currency' => true, 'search' => false, 'filter' => false],
            'refund_shipping_ttc' => ['title' => 'Remb. livraison TTC', 'type' => 'price', 'currency' => true, 'search' => false, 'filter' => false],
            'nb_products' => ['title' => 'Nb Produits', 'search' => false, 'filter' => false],
            'payment_method' => ['title' => 'Moyen paiement', 'type' => 'text', 'filter_key' => 'o!payment'],
            'commission_ttc' => ['title' => 'Commission TTC', 'type' => 'price', 'currency' => true],
            'dropshipping_fees' => ['title' => 'Coût dropshipping', 'type' => 'price', 'currency' => true],
            'margin' => ['title' => 'Marge nette', 'type' => 'price', 'currency' => true],
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

//        $helper->show_filter = true;
//        $helper->default_pagination = 50;
//        $helper->pagination = [20, 50, 100, 300];
        $helper->orderBy = 'id_order';
        $helper->orderWay = 'DESC';

//        return $helper->generateList($data, $fields_list);
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
}
