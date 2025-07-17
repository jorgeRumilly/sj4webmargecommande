<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Sj4webMargeCommande extends Module
{
    public function __construct()
    {
        $this->name = 'sj4webmargecommande';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'SJ4WEB.FR';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('Marge Commande', [], 'Modules.Sj4webmargecommande.Sj4webmargecommande');
        $this->description = $this->trans('Affiche la marge nette sur la page de commande dans l\'administration.', [], 'Modules.Sj4webmargecommande.Sj4webmargecommande');
        $this->ps_versions_compliancy = array('min' => '8.1', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayAdminOrderSide')
            && $this->registerHook('actionAdminControllerSetMedia') // Pour gérer le téléchargement du fichier
            && $this->installDB();
    }

    public function uninstall()
    {
        return parent::uninstall() && $this->uninstallDB();
    }

    protected function installDB()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'order_fees` (
            `id_order_fee` int(11) NOT NULL AUTO_INCREMENT,
            `id_order` int(11) NOT NULL,
            `method` varchar(50),
            `fee` decimal(10,2) NOT NULL,
            `date_add` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_order_fee`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';
        return Db::getInstance()->execute($sql);
    }

    protected function uninstallDB()
    {
        $sql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'order_fees`';
        return Db::getInstance()->execute($sql);
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitSj4webmargecommande')) {
            $this->processForm();
            $output .= $this->displayConfirmation($this->trans('Settings updated', [], 'Modules.Sj4webmargecommande.Sj4webmargecommande'));
        }
        // Générer un lien de téléchargement pour l'exemple de fichier JSON
        $output .= '<div style="margin-bottom: 20px;">
                    <a class="btn btn-default" href="' . $this->context->link->getAdminLink('AdminModules', true) . '&module_name=sj4webmargecommande&downloadExampleJson=1">' . $this->trans('Download JSON example', [], 'Modules.Sj4webmargecommande.Sj4webmargecommande') . '</a>
                </div>';

        return $output . $this->renderForm();
    }

    protected function processForm()
    {
        $fees = Tools::getValue('SJ4WEB_FEE_LIST');
        Configuration::updateValue('SJ4WEB_FEE_LIST', json_encode($fees));
    }

    protected function renderForm()
    {
        $exampleJson = '
        {
            "1": {"type": "percent", "value": 10},
            "2": {"type": "fixed", "value": 10},
            "3": {"type": "per_quantity", "steps": [{"quantity": 1, "value": 2.40}, {"quantity": 5, "value": 3.50}, {"quantity": 10, "value": 5.70}, {"quantity": 1000, "value": 6.80} ]}
        }';

        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Frais de Marque', [], 'Modules.Sj4webmargecommande.Sj4webmargecommande'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'textarea',
                        'label' => $this->trans('Liste des frais (JSON format)', [], 'Modules.Sj4webmargecommande.Sj4webmargecommande'),
                        'name' => 'SJ4WEB_FEE_LIST',
                        'desc' => $this->trans('Saisir la liste des frais sous forme de JSON.', [], 'Modules.Sj4webmargecommande.Sj4webmargecommande') . '<br><br><strong>' . $this->trans('Exemple :', [], 'Modules.Sj4webmargecommande.Sj4webmargecommande') . '</strong><pre>' . htmlspecialchars($exampleJson) . '</pre>',
                        'autoload_rte' => false,
                        'cols' => 60,
                        'rows' => 10
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Save', [], 'Modules.Sj4webmargecommande.Sj4webmargecommande'),
                    'class' => 'btn btn-default pull-right'
                )
            )
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitSj4webmargecommande';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => array(
                'SJ4WEB_FEE_LIST' => Tools::getValue('SJ4WEB_FEE_LIST', Configuration::get('SJ4WEB_FEE_LIST')),
            ),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form));
    }

    public function hookActionAdminControllerSetMedia()
    {
        if (Tools::getIsset('downloadExampleJson')) {
            $this->downloadExampleJson();
        }
    }

    protected function downloadExampleJson()
    {
        $exampleJson = '
        {
            "1": {"type": "percent", "value": 10},
            "2": {"type": "fixed", "value": 10},
            "3": {"type": "per_quantity", "steps": [{"quantity": 1, "value": 2.40}, {"quantity": 5, "value": 3.50}, {"quantity": 10, "value": 5.70}, {"quantity": 1000, "value": 6.80} ]}
        }';

        header('Content-disposition: attachment; filename=example_fees.json');
        header('Content-type: application/json');
        echo $exampleJson;
        exit;
    }

//    public function hookDisplayAdminOrderMain($params)
    public function hookDisplayAdminOrderSide($params)
    {
        $order = new Order($params['id_order']);
        $orderTotalHT = $order->total_products;
        $orderCostPrice = $this->getOrderCostPrice($order);
        $dropshippingFees = $this->calculateDropshippingFees($order);
        $paymentFees = $this->getPaymentFees($order->id);
        $netMargin = $orderTotalHT - ($orderCostPrice + $dropshippingFees + $paymentFees);

        $this->context->smarty->assign(array(
            'order' => $order,
            'orderTotalHT' => number_format($orderTotalHT, 2),
            'orderCostPrice' => number_format($orderCostPrice, 2),
            'dropshippingFees' => number_format($dropshippingFees, 2),
            'paymentFees' => number_format($paymentFees, 2),
            'netMargin' => number_format($netMargin, 2),
        ));

        return $this->display(__FILE__, 'views/templates/admin/displayAdminOrder.tpl');
    }

    protected function getOrderCostPrice(Order $order)
    {
        $costPrice = 0;
        foreach ($order->getProducts() as $product) {
            $costPrice += $product['purchase_supplier_price'] * $product['product_quantity'];
        }
        return $costPrice;
    }

    protected function calculateDropshippingFees(Order $order)
    {
        // Double désencodage car on json_encode à l'enregistrement en base
        $fees = json_decode(json_decode(Configuration::get('SJ4WEB_FEE_LIST'), true), true);
        $totalFees = 0;

        // Regrouper les produits par fabricant
        $productsByManufacturer = [];

        foreach ($order->getProducts() as $product) {
            $manufacturerId = $product['id_manufacturer'];
            if (!isset($productsByManufacturer[$manufacturerId])) {
                $productsByManufacturer[$manufacturerId] = [
                    'total_quantity' => 0,
                    'total_price_tax_excl' => 0
                ];
            }
            $productsByManufacturer[$manufacturerId]['total_quantity'] += $product['product_quantity'];
            $productsByManufacturer[$manufacturerId]['total_price_tax_excl'] += $product['total_price_tax_excl'];
        }

        // Calculer les frais de dropshipping pour chaque fabricant
        foreach ($productsByManufacturer as $manufacturerId => $productData) {
            if (isset($fees[$manufacturerId])) {
                $fee = $fees[$manufacturerId];

                if ($fee['type'] == 'percent') {
                    // Calculer un pourcentage du montant total HT des produits de ce fabricant
                    $totalFees += ($productData['total_price_tax_excl'] * $fee['value'] / 100);
                } elseif ($fee['type'] == 'fixed') {
                    // Ajouter un montant fixe pour ce fabricant
                    $totalFees += $fee['value'];
                } elseif ($fee['type'] == 'per_quantity') {
                    // Calculer les frais en fonction du nombre total de produits de ce fabricant
                    foreach ($fee['steps'] as $step) {
                        if ($productData['total_quantity'] <= $step['quantity']) {
                            $totalFees += $step['value'];
                            break;
                        }
                    }
                }
            }
        }

        return $totalFees;
    }

    protected function getPaymentFees($orderId)
    {
        $sql = 'SELECT fee FROM ' . _DB_PREFIX_ . 'order_fees WHERE id_order = ' . (int)$orderId;
        return (float)Db::getInstance()->getValue($sql);
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }

}
