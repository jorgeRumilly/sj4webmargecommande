<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * Token-protected recompute endpoint. Schedule once a day:
 *   curl -s "https://SHOP/module/sj4webmargecommande/cron?token=XXXX"
 *
 * Optional: &scope=missing (default: window) — 'missing' computes only orders absent
 * from the cache; 'window' recomputes valid orders of the last RECOMPUTE_DAYS days.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Sj4webMargeCommandeCronModuleFrontController extends ModuleFrontController
{
    /**
     * @return void
     */
    public function init()
    {
        parent::init();
        $this->display_header = false;
        $this->display_footer = false;
        $this->display_column_left = false;
        $this->display_column_right = false;
    }

    /**
     * @return void
     */
    public function postProcess()
    {
        header('Content-Type: application/json');

        require_once _PS_MODULE_DIR_ . 'sj4webmargecommande/classes/MarginConfig.php';

        $provided = Tools::getValue('token');
        $expected = MarginConfig::getCronToken();

        if (!is_string($provided) || '' === $provided || '' === $expected || !hash_equals($expected, $provided)) {
            http_response_code(403);
            die(json_encode(['success' => false, 'error' => 'Invalid or missing token']));
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            $scope = (Tools::getValue('scope') === 'missing') ? 'missing' : 'window';
            $result = $this->module->runRecompute(['scope' => $scope, 'limit' => 5000]);

            http_response_code(200);
            die(json_encode(['success' => true, 'data' => $result]));
        } catch (Throwable $e) {
            http_response_code(500);
            die(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    /**
     * @return void
     */
    public function display()
    {
        // handled in postProcess()
    }
}
