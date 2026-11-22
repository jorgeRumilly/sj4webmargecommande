<?php
/**
 * CLI integration test of the Agent Bridge extension of sj4webmargecommande against the LOCAL shop:
 *   php modules/sj4webmargecommande/tests/integration_agentbridge.php
 *
 * Needs sj4web_agentbridge >= 0.15.0 and sj4webmargecommande >= 2.1.0 installed locally. Goes through the real MCP
 * handler with throwaway tokens. Reads real orders. The fetch operation runs with FAKE providers (no PayPlug / PayPal
 * call): they write a commission row for an order that has none, then undo must remove it. order_fees, the PayPlug
 * ledger and the margin cache rows of the touched orders are compared before/after and put back. Never on production.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}

$_SERVER['HTTP_HOST'] = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : (getenv('SJ4AB_HOST') ?: 'local.compagniedelagrandeourse.com');
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (strpos((string) $_SERVER['HTTP_HOST'], 'local') === false) {
    fwrite(STDERR, "Refusing to run: this test writes data, it is for a local shop.\n");
    exit(2);
}
require dirname(__DIR__, 3) . '/config/config.inc.php';
require_once dirname(__DIR__, 3) . '/app/AppKernel.php';
$kernel = new AppKernel('prod', false);
$kernel->boot();
Context::getContext()->container = $kernel->getContainer();
if (!Context::getContext()->currency) {
    Context::getContext()->currency = Currency::getDefaultCurrency();
}

if (!Module::isInstalled('sj4web_agentbridge') || !Module::isInstalled('sj4webmargecommande')) {
    fwrite(STDERR, "SKIP: sj4web_agentbridge and sj4webmargecommande must both be installed locally.\n");
    exit(2);
}
Module::getInstanceByName('sj4web_agentbridge');
$marge = Module::getInstanceByName('sj4webmargecommande');

use Sj4webAgentbridge\Auth\Scopes;
use Sj4webAgentbridge\Auth\TokenRepository;
use Sj4webAgentbridge\Extension\ExtensionLoader;
use Sj4webAgentbridge\Extension\ExtensionRegistry;
use Sj4webAgentbridge\Http\HandlerFactory;
use Sj4webAgentbridge\Settings;

$failures = 0;
$count = 0;
function check($name, $ok, $detail = '')
{
    global $failures, $count;
    ++$count;
    if (!$ok) {
        ++$failures;
        echo "FAIL: $name" . ($detail !== '' ? " ($detail)" : '') . "\n";
    } else {
        echo "  ok  $name\n";
    }
}

$px = _DB_PREFIX_;
$db = Db::getInstance();
$repo = new TokenRepository();
$created = [];
$previousWrites = Configuration::get(Settings::WRITES_ENABLED);
$db->execute('DELETE FROM `' . $px . 'sj4web_agentbridge_rate`');

// The order used for the fetch: a PayPlug or PayPal order with no commission row at all.
$target = $db->executeS(
    'SELECT o.id_order, o.module FROM `' . $px . 'orders` o
     LEFT JOIN `' . $px . 'order_fees` f ON f.id_order = o.id_order
     WHERE o.module IN (\'payplug\', \'paypal\') AND o.total_paid_tax_incl > 0 AND f.id_order IS NULL
     ORDER BY o.id_order DESC LIMIT 2'
);
$touched = array_map('intval', array_column($target ?: [], 'id_order'));
$snap = static function () use ($db, $px, $touched) {
    $in = $touched ? implode(',', $touched) : '0';
    $ledger = $db->executeS('SHOW TABLES LIKE \'' . $px . 'sj4web_payplug_order_fee\'') ? $db->executeS('SELECT * FROM `' . $px . 'sj4web_payplug_order_fee` WHERE id_order IN (' . $in . ') ORDER BY id_order') : [];

    return [
        'fees' => $db->executeS('SELECT * FROM `' . $px . 'order_fees` WHERE id_order IN (' . $in . ') ORDER BY id_order_fee'),
        'ledger' => $ledger,
        'margin' => $db->executeS('SELECT * FROM `' . $px . 'sj4web_order_margin` WHERE id_order IN (' . $in . ') ORDER BY id_order'),
        'max_fee' => (int) $db->getValue('SELECT COALESCE(MAX(id_order_fee), 0) FROM `' . $px . 'order_fees`'),
    ];
};
$initial = $snap();

$cleanup = static function () use (&$created, $repo, $db, $px, $previousWrites, $initial, $touched) {
    foreach ($created as $id) {
        $db->execute('DELETE FROM `' . $px . 'sj4web_agentbridge_change` WHERE `id_token` = ' . (int) $id);
        $db->execute('DELETE FROM `' . $px . 'sj4web_agentbridge_log` WHERE `id_token` = ' . (int) $id);
        $repo->delete((int) $id);
    }
    // Rows the fake providers may have written beyond the initial maximum, then the margin cache rows exactly as before.
    $db->execute('DELETE FROM `' . $px . 'order_fees` WHERE id_order_fee > ' . (int) $initial['max_fee']);
    if ($touched) {
        $in = implode(',', $touched);
        $db->execute('DELETE FROM `' . $px . 'sj4web_order_margin` WHERE id_order IN (' . $in . ')');
        foreach ($initial['margin'] as $row) {
            $db->insert('sj4web_order_margin', array_map(static function ($v) {
                return $v === null ? null : pSQL((string) $v);
            }, $row), true);
        }
        if ($db->executeS('SHOW TABLES LIKE \'' . $px . 'sj4web_payplug_order_fee\'')) {
            $db->execute('DELETE FROM `' . $px . 'sj4web_payplug_order_fee` WHERE id_order IN (' . $in . ')');
            foreach ($initial['ledger'] as $row) {
                $db->insert('sj4web_payplug_order_fee', array_map(static function ($v) {
                    return $v === null ? null : pSQL((string) $v);
                }, $row), true);
            }
        }
    }
    \Sj4webMargecommande\Mcp\FeeProviders::override(null);
    Configuration::updateValue(Settings::WRITES_ENABLED, $previousWrites === false ? 0 : $previousWrites);
    Scopes::overrideExtra(null);
    ExtensionLoader::reset();
};

try {
    run($marge, $repo, $created, $touched);
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    $cleanup();
}
$final = $snap();
check('order_fees of the touched orders identical after the test', $final['fees'] == $initial['fees']);
check('PayPlug ledger of the touched orders identical after the test', $final['ledger'] == $initial['ledger']);
check('margin cache of the touched orders identical after the test', $final['margin'] == $initial['margin']);
check('no order_fees row left behind', $final['max_fee'] === $initial['max_fee']);
echo $failures === 0 ? "OK: $count checks passed\n" : "$failures of $count checks FAILED\n";
exit($failures === 0 ? 0 : 1);

function run($marge, $repo, array &$created, array $touched)
{
    $db = Db::getInstance();
    $px = _DB_PREFIX_;
    $mk = static function ($label, array $scopes) use ($repo, &$created) {
        $t = $repo->create($label, $scopes, null, '', 0);
        $created[] = $t['id'];

        return $t['token'];
    };

    $registry = ExtensionLoader::collectFrom([
        'sj4webmargecommande' => static function (ExtensionRegistry $r) use ($marge) {
            $marge->hookActionSj4webAgentbridgeCollectExtensions(['registry' => $r]);
        },
    ], HandlerFactory::reservedNames());
    check('the hook registers the extension', array_column($registry->describe(), 'module') === ['sj4webmargecommande'], json_encode($registry->problems()));
    check('scope payment_fee_fetch declared', array_keys($registry->scopes()) === ['payment_fee_fetch']);
    Scopes::overrideExtra($registry->scopes());

    $tokRead = $mk('mc-read', ['read']);
    $tokFetch = $mk('mc-fetch', ['read', 'payment_fee_fetch']);

    $call = static function ($handler, $token, $name, array $args) {
        $r = $handler->handle('POST', ['authorization' => 'Bearer ' . $token], (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]]), '127.0.0.1', true);
        if (isset($r['body']['error'])) {
            return ['error' => 'protocol: ' . $r['body']['error']['message'], 'data' => null];
        }
        $result = $r['body']['result'];
        $text = $result['content'][0]['text'];

        return ['error' => $result['isError'] ? $text : null, 'data' => $result['isError'] ? null : json_decode($text, true)];
    };
    $names = static function ($handler, $token) {
        $r = $handler->handle('POST', ['authorization' => 'Bearer ' . $token], (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']), '127.0.0.1', true);

        return array_column(isset($r['body']['result']['tools']) ? $r['body']['result']['tools'] : [], 'name');
    };

    Configuration::updateValue(Settings::WRITES_ENABLED, 0);
    $handler = HandlerFactory::create('test', $registry);
    $reads = ['order_payment_fee_get', 'payment_fees_list', 'order_margin_get', 'order_margins_list'];
    check('read tools listed', array_diff($reads, $names($handler, $tokRead)) === []);
    check('writes off: fetch not listed', !in_array('order_payment_fee_fetch', $names($handler, $tokFetch), true));
    Configuration::updateValue(Settings::WRITES_ENABLED, 1);
    $handler = HandlerFactory::create('test', $registry);
    check('writes on: fetch listed for payment_fee_fetch', in_array('order_payment_fee_fetch', $names($handler, $tokFetch), true));
    check('writes on: fetch hidden without payment_fee_fetch', !in_array('order_payment_fee_fetch', $names($handler, $tokRead), true));

    // -- Reads on real orders -------------------------------------------------------------------
    foreach (['payplug', 'paypal'] as $module) {
        $row = $db->executeS(
            'SELECT o.id_order, SUM(f.fee) AS fee FROM `' . $px . 'orders` o JOIN `' . $px . 'order_fees` f ON f.id_order = o.id_order
             WHERE o.module = \'' . $module . '\' GROUP BY o.id_order HAVING fee > 0 ORDER BY o.id_order DESC LIMIT 1'
        );
        if (!$row) {
            echo "  skip $module: no order with a commission locally\n";
            continue;
        }
        $r = $call($handler, $tokRead, 'order_payment_fee_get', ['id_order' => (int) $row[0]['id_order']]);
        check($module . ': commission read, status stored', $r['error'] === null && $r['data']['status'] === 'stored' && abs($r['data']['commission_ht'] - round((float) $row[0]['fee'], 2)) < 0.001, (string) $r['error'] . json_encode($r['data']));
        check($module . ': no fetch offered when stored', $r['data']['fetch_available'] === false);
        $f = $call($handler, $tokFetch, 'order_payment_fee_fetch', ['id_order' => (int) $row[0]['id_order']]);
        check($module . ': fetch refused when a commission is stored', $f['error'] !== null && strpos($f['error'], 'already') !== false, (string) $f['error']);
    }
    $wire = $db->executeS('SELECT id_order FROM `' . $px . 'orders` WHERE module = \'ps_wirepayment\' ORDER BY id_order DESC LIMIT 1');
    if ($wire) {
        $r = $call($handler, $tokRead, 'order_payment_fee_get', ['id_order' => (int) $wire[0]['id_order']]);
        check('bank transfer: not_applicable', $r['error'] === null && $r['data']['status'] === 'not_applicable', json_encode($r['data']));
        $f = $call($handler, $tokFetch, 'order_payment_fee_fetch', ['id_order' => (int) $wire[0]['id_order']]);
        check('bank transfer: fetch refused', $f['error'] !== null);
    }
    check('unknown order refused', $call($handler, $tokRead, 'order_payment_fee_get', ['id_order' => 999999999])['error'] !== null);

    $from = date('Y-m-d', strtotime('-90 days'));
    $to = date('Y-m-d');
    $list = $call($handler, $tokRead, 'payment_fees_list', ['date_from' => $from, 'date_to' => $to]);
    check('payment_fees_list returns orders and totals', $list['error'] === null && $list['data']['row_count'] > 0 && $list['data']['totals_by_payment_module'] !== [], (string) $list['error']);
    $sum = (float) $db->getValue('SELECT COALESCE(SUM(f.fee), 0) FROM `' . $px . 'orders` o JOIN `' . $px . 'order_fees` f ON f.id_order = o.id_order WHERE o.valid = 1 AND o.date_add >= \'' . $from . ' 00:00:00\' AND o.date_add <= \'' . $to . ' 23:59:59\'');
    $listed = array_sum(array_column($list['data']['totals_by_payment_module'], 'commission_ht'));
    check('totals equal the database sum', abs($listed - $sum) < 0.05, $listed . ' vs ' . $sum);
    $only = $call($handler, $tokRead, 'payment_fees_list', ['date_from' => $from, 'date_to' => $to, 'only_missing' => true]);
    check('only_missing works', $only['error'] === null && array_filter($only['data']['orders'], static function ($o) {
        return $o['commission_ht'] > 0;
    }) === []);
    $pp = $call($handler, $tokRead, 'payment_fees_list', ['date_from' => $from, 'date_to' => $to, 'payment_module' => 'paypal']);
    check('payment_module filter', $pp['error'] === null && array_unique(array_column($pp['data']['orders'], 'payment_module')) === ($pp['data']['orders'] ? ['paypal'] : []));
    foreach ([
        'bad date' => ['date_from' => '2026-13-01', 'date_to' => $to],
        'injected date' => ['date_from' => "2026-01-01' OR '1'='1", 'date_to' => $to],
        'period too long' => ['date_from' => '2020-01-01', 'date_to' => $to],
        'reversed period' => ['date_from' => $to, 'date_to' => $from],
        'injected module' => ['date_from' => $from, 'date_to' => $to, 'payment_module' => "paypal' OR 1=1 -- "],
    ] as $label => $args) {
        check('payment_fees_list: ' . $label . ' refused', $call($handler, $tokRead, 'payment_fees_list', $args)['error'] !== null);
    }

    $any = (int) $db->getValue('SELECT id_order FROM `' . $px . 'sj4web_order_margin` ORDER BY id_order DESC');
    $m = $call($handler, $tokRead, 'order_margin_get', ['id_order' => $any]);
    check('order_margin_get computes a margin', $m['error'] === null && is_numeric($m['data']['net_margin']) && isset($m['data']['commission']), (string) $m['error']);
    $ml = $call($handler, $tokRead, 'order_margins_list', ['date_from' => $from, 'sort' => 'net_margin', 'order_way' => 'ASC', 'limit' => 5]);
    check('order_margins_list returns rows, sorted', $ml['error'] === null && $ml['data']['row_count'] > 0 && $ml['data']['orders'][0]['net_margin'] <= end($ml['data']['orders'])['net_margin'], (string) $ml['error']);
    check('order_margins_list: unknown sort refused', $call($handler, $tokRead, 'order_margins_list', ['sort' => 'id_order; DROP TABLE x'])['error'] !== null);
    $inj = $call($handler, $tokRead, 'order_margins_list', ['payment' => '" OR 1=1 -- ']);
    check('order_margins_list: quote in payment filter is data', $inj['error'] === null && $inj['data']['row_count'] === 0, (string) $inj['error']);

    // -- Fetch with fake providers ----------------------------------------------------------------
    if (!$touched) {
        echo "  skip fetch: no PayPlug / PayPal order without commission locally\n";

        return;
    }
    $idOrder = $touched[0];
    $order = new Order($idOrder);
    $fake = new class($touched) implements \Sj4webMargecommande\Mcp\PaymentFeeProvider {
        public $module = 'payplug';
        public $reason = null;
        public $calls = 0;
        public $fee = 1.23;
        public $fail = false;
        private $orders;

        public function __construct(array $orders)
        {
            $this->orders = $orders;
        }

        public function module(): string
        {
            return $this->module;
        }

        public function unavailableReason(): ?string
        {
            return $this->reason;
        }

        public function affectedOrders(Order $order): array
        {
            return $this->orders;
        }

        public function describe(Order $order): string
        {
            return 'fake provider';
        }

        public function fetch(Order $order): array
        {
            ++$this->calls;
            if ($this->fail) {
                return ['provider' => 'fake', 'error' => 'simulated outage'];
            }
            Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'order_fees` (id_order, method, fee) VALUES (' . (int) $order->id . ', \'' . pSQL($this->module) . '\', ' . (float) $this->fee . ')');

            return ['provider' => 'fake'];
        }
    };
    $fake->module = strtolower((string) $order->module);
    \Sj4webMargecommande\Mcp\FeeProviders::override([$fake->module => $fake]);
    $feeCount = static function () use ($db, $px, $idOrder) {
        return (int) $db->getValue('SELECT COUNT(*) FROM `' . $px . 'order_fees` WHERE id_order = ' . $idOrder);
    };

    $state = $call($handler, $tokRead, 'order_payment_fee_get', ['id_order' => $idOrder]);
    check('missing commission: fetch offered', $state['error'] === null && $state['data']['fetch_available'] === true && in_array($state['data']['status'], ['missing', 'pending', 'given_up'], true), json_encode($state['data']));
    $fake->reason = 'module disabled';
    $r = $call($handler, $tokFetch, 'order_payment_fee_fetch', ['id_order' => $idOrder]);
    check('provider unavailable: fetch refused with the reason', $r['error'] !== null && strpos($r['error'], 'module disabled') !== false, (string) $r['error']);
    $fake->reason = null;
    check('fetch refused without payment_fee_fetch', $call($handler, $tokRead, 'order_payment_fee_fetch', ['id_order' => $idOrder])['error'] !== null);

    $prep = $call($handler, $tokFetch, 'order_payment_fee_fetch', ['id_order' => $idOrder]);
    check('fetch preview', $prep['error'] === null && isset($prep['data']['change_id']), (string) $prep['error']);
    check('preview lists the orders that may be filled', strpos(json_encode($prep['data']), (string) $idOrder) !== false);
    check('preview calls no provider and writes nothing', $fake->calls === 0 && $feeCount() === 0);
    $ap = $call($handler, $tokFetch, 'apply_change', ['change_id' => $prep['data']['change_id']]);
    check('fetch applied: commission stored', $ap['error'] === null && $fake->calls === 1 && $feeCount() === 1, (string) $ap['error']);
    $after = $call($handler, $tokRead, 'order_payment_fee_get', ['id_order' => $idOrder]);
    check('order_payment_fee_get shows the new commission', $after['data']['status'] === 'stored' && abs($after['data']['commission_ht'] - 1.23) < 0.001);
    $cache = $db->executeS('SELECT commission_ht FROM `' . $px . 'sj4web_order_margin` WHERE id_order = ' . $idOrder);
    check('margin cache recomputed with the commission', $cache && abs((float) $cache[0]['commission_ht'] - 1.23) < 0.001, json_encode($cache));
    $un = $call($handler, $tokFetch, 'undo_change', ['change_id' => $prep['data']['change_id']]);
    check('fetch undone: commission removed', $un['error'] === null && $feeCount() === 0, (string) $un['error']);

    $fake->fail = true;
    $prep = $call($handler, $tokFetch, 'order_payment_fee_fetch', ['id_order' => $idOrder]);
    $ap = $call($handler, $tokFetch, 'apply_change', ['change_id' => $prep['data']['change_id']]);
    check('provider outage: apply reports an error, nothing stored', $ap['error'] !== null && $feeCount() === 0, (string) $ap['error']);
    $fake->fail = false;

    $prep = $call($handler, $tokFetch, 'order_payment_fee_fetch', ['id_order' => $idOrder]);
    $db->execute('INSERT INTO `' . $px . 'order_fees` (id_order, method, fee) VALUES (' . $idOrder . ', \'manual\', 0.5)');
    $ap = $call($handler, $tokFetch, 'apply_change', ['change_id' => $prep['data']['change_id']]);
    check('apply refused when order_fees changed since the preview', $ap['error'] !== null, json_encode($ap['data']));
    $db->execute('DELETE FROM `' . $px . 'order_fees` WHERE id_order = ' . $idOrder . ' AND method = \'manual\'');

    $prep = $call($handler, $tokFetch, 'order_payment_fee_fetch', ['id_order' => $idOrder]);
    $call($handler, $tokFetch, 'apply_change', ['change_id' => $prep['data']['change_id']]);
    $db->execute('UPDATE `' . $px . 'order_fees` SET fee = 9.99 WHERE id_order = ' . $idOrder);
    $un = $call($handler, $tokFetch, 'undo_change', ['change_id' => $prep['data']['change_id']]);
    check('undo refused when the commission was changed after the fetch', $un['error'] !== null, json_encode($un['data']));
    $db->execute('DELETE FROM `' . $px . 'order_fees` WHERE id_order = ' . $idOrder);
}
