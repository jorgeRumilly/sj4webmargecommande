<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * 1.1.0 -> 2.0.0
 *  - new schema (per-supplier drop rules + per-order margin cache)
 *  - migrate the legacy JSON `SJ4WEB_FEE_LIST` (keyed by id_manufacturer) to rules
 *    keyed by id_supplier, matching supplier by name (1:1 on this catalogue)
 *  - register the new hooks + the "Dropshipping cost rules" BO tab
 *
 * The legacy `SJ4WEB_FEE_LIST` value is kept (unused) as a backup.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Sj4webMargeCommande $module
 *
 * @return bool
 */
function upgrade_module_2_0_0($module)
{
    require_once dirname(__DIR__) . '/classes/MarginConfig.php';
    require_once dirname(__DIR__) . '/classes/DropRuleRepository.php';

    include dirname(__DIR__) . '/sql/install.php';

    MarginConfig::installDefaults();

    foreach ([
        'actionValidateOrder', 'actionOrderEdited', 'actionObjectOrderSlipAddAfter',
        'actionOrderStatusPostUpdate', 'actionSj4webOrderFeeCaptured',
    ] as $hook) {
        if (!$module->isRegisteredInHook($hook)) {
            $module->registerHook($hook);
        }
    }

    // No longer used (JSON example download is gone).
    if ($module->isRegisteredInHook('actionAdminControllerSetMedia')) {
        $module->unregisterHook('actionAdminControllerSetMedia');
    }

    $module->installTab();

    _sj4wm_migrate_legacy_fee_list();

    return true;
}

/**
 * @return void
 */
function _sj4wm_migrate_legacy_fee_list()
{
    $raw = Configuration::get('SJ4WEB_FEE_LIST');
    if (!$raw) {
        return;
    }

    // Legacy value was double json_encoded.
    $decoded = json_decode($raw, true);
    if (is_string($decoded)) {
        $decoded = json_decode($decoded, true);
    }
    if (!is_array($decoded)) {
        return;
    }

    foreach ($decoded as $idManufacturer => $fee) {
        $name = Db::getInstance()->getValue(
            'SELECT name FROM ' . _DB_PREFIX_ . 'manufacturer WHERE id_manufacturer = ' . (int) $idManufacturer
        );
        if (!$name) {
            continue;
        }
        $idSupplier = (int) Db::getInstance()->getValue(
            'SELECT id_supplier FROM ' . _DB_PREFIX_ . 'supplier WHERE LOWER(TRIM(name)) = LOWER(TRIM("' . pSQL($name) . '"))'
        );
        if (!$idSupplier) {
            PrestaShopLogger::addLog(
                'sj4webmargecommande upgrade: no supplier matching manufacturer "' . $name . '" (#' . (int) $idManufacturer . '), rule skipped.',
                2
            );
            continue;
        }

        $type = isset($fee['type']) ? (string) $fee['type'] : '';
        $data = ['id_supplier' => $idSupplier, 'rule_type' => $type, 'active' => true, 'steps' => [], 'buckets' => []];

        if ('percent' === $type) {
            $data['percent'] = (float) ($fee['value'] ?? 0);
        } elseif ('fixed' === $type) {
            $data['fixed_amount'] = (float) ($fee['value'] ?? 0);
        } elseif ('per_quantity' === $type && !empty($fee['steps']) && is_array($fee['steps'])) {
            foreach ($fee['steps'] as $step) {
                $data['steps'][] = [
                    'max_quantity' => (int) ($step['quantity'] ?? 0),
                    'amount' => (float) ($step['value'] ?? 0),
                ];
            }
        } else {
            continue;
        }

        try {
            DropRuleRepository::save($data);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('sj4webmargecommande upgrade: ' . $e->getMessage(), 3);
        }
    }
}
