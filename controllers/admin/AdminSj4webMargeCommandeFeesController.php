<?php

class AdminSj4webMargeCommandeFeesController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function renderList()
    {
        // Requête brute : on récupère toutes les commandes
        $sql = 'SELECT o.id_order, o.reference, o.date_add,
                       o.total_paid_tax_excl, o.total_paid_tax_incl,
                       o.total_shipping_tax_excl, o.total_shipping_tax_incl,
                       o.payment
                FROM ' . _DB_PREFIX_ . 'orders o
                ORDER BY o.date_add DESC
                LIMIT 200';

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
            'id_order' => ['title' => 'ID'],
            'reference' => ['title' => 'Référence'],
            'date_add' => ['title' => 'Date'],
            'total_paid_tax_excl' => ['title' => 'Total HT', 'type' => 'price', 'currency' => true],
            'total_paid_tax_incl' => ['title' => 'Total TTC', 'type' => 'price', 'currency' => true],
            'total_shipping_tax_excl' => ['title' => 'Livraison HT', 'type' => 'price', 'currency' => true],
            'total_shipping_tax_incl' => ['title' => 'Livraison TTC', 'type' => 'price', 'currency' => true],
            'refund_products_ttc' => ['title' => 'Remb. produits TTC', 'type' => 'price', 'currency' => true],
            'refund_shipping_ttc' => ['title' => 'Remb. livraison TTC', 'type' => 'price', 'currency' => true],
            'nb_products' => ['title' => 'Nb Produits'],
            'payment_method' => ['title' => 'Moyen paiement'],
            'commission_ttc' => ['title' => 'Commission TTC', 'type' => 'price', 'currency' => true],
            'dropshipping_fees' => ['title' => 'Coût dropshipping', 'type' => 'price', 'currency' => true],
            'margin' => ['title' => 'Marge nette', 'type' => 'price', 'currency' => true],
        ];

        $helper = new HelperList();
        $helper->title = 'Marge Commande - Liste des commandes';
        $helper->shopLinkType = '';
        $helper->simple_header = true;
        $helper->identifier = 'id_order';
        $helper->show_toolbar = false;
        $helper->module = $this->module;
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->module->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        return $helper->generateList($data, $fields_list);
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
}
