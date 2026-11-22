<?php
declare(strict_types=1);

namespace Sj4webMargecommande\Mcp;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use Sj4webAgentbridge\Auth\Principal;
use Sj4webAgentbridge\Mcp\AbstractTool;
use Sj4webAgentbridge\Mcp\ToolException;

/**
 * Payment commission of one order, whatever the payment method.
 */
final class PaymentFeeGetTool extends AbstractTool
{
    public function getName(): string
    {
        return 'order_payment_fee_get';
    }

    public function getTitle(): string
    {
        return 'Payment commission of an order';
    }

    public function getDescription(): string
    {
        return 'Payment commission (tax excluded) of one order, whatever the payment method (PayPlug, PayPal...), from '
            . '{prefix}order_fees. status: stored (known), missing (not fetched yet), pending (PayPlug has not published it yet, '
            . 'retried by the daily cron), given_up (still missing after the alert delay), zero_reported (the provider said 0), '
            . 'not_applicable (bank transfer, free order...), not_tracked (payment module without commission tracking). '
            . 'When fetch_available is true, order_payment_fee_fetch can ask the provider now.';
    }

    public function getInputSchema(): array
    {
        return $this->schema(['id_order' => ['type' => 'integer', 'minimum' => 1]], ['id_order']);
    }

    public function execute(array $args, Principal $principal): array
    {
        $state = MargeMcp::feeState(MargeMcp::loadOrder((int) $args['id_order']));
        $state['row_count'] = 1;

        return $state;
    }
}

/**
 * Commissions of many orders (period), with totals per payment method.
 */
final class PaymentFeesListTool extends AbstractTool
{
    public function getName(): string
    {
        return 'payment_fees_list';
    }

    public function getTitle(): string
    {
        return 'Payment commissions over a period';
    }

    public function getDescription(): string
    {
        return 'Valid orders of a period with their payment commission (tax excluded), and totals per payment module. '
            . 'only_missing=true keeps orders paid by a tracked provider (payplug, paypal) that still have no positive '
            . 'commission. Dates are YYYY-MM-DD (order date, inclusive).';
    }

    public function getInputSchema(): array
    {
        return $this->schema([
            'date_from' => ['type' => 'string', 'maxLength' => 10],
            'date_to' => ['type' => 'string', 'maxLength' => 10],
            'payment_module' => ['type' => 'string', 'maxLength' => 64, 'description' => 'orders.module, e.g. payplug, paypal'],
            'only_missing' => ['type' => 'boolean', 'default' => false],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
        ], ['date_from', 'date_to']);
    }

    public function execute(array $args, Principal $principal): array
    {
        $from = MargeMcp::checkDate((string) $args['date_from'], 'date_from');
        $to = MargeMcp::checkDate((string) $args['date_to'], 'date_to');
        if ($from > $to) {
            throw new ToolException('date_from is after date_to.');
        }
        if ((strtotime($to) - strtotime($from)) > 400 * 86400) {
            throw new ToolException('The period is limited to 400 days.');
        }
        $px = _DB_PREFIX_;
        $where = 'o.`valid` = 1 AND o.`date_add` >= \'' . pSQL($from) . ' 00:00:00\' AND o.`date_add` <= \'' . pSQL($to) . ' 23:59:59\'';
        if (isset($args['payment_module']) && $args['payment_module'] !== '') {
            if (preg_match('/^[a-z0-9_]+$/i', (string) $args['payment_module']) !== 1) {
                throw new ToolException('payment_module must be a module name.');
            }
            $where .= ' AND o.`module` = \'' . pSQL(strtolower((string) $args['payment_module'])) . '\'';
        }
        $join = MargeMcp::tableExists(MargeMcp::FEES_TABLE)
            ? 'LEFT JOIN (SELECT `id_order`, SUM(`fee`) AS fee, COUNT(*) AS n FROM `' . $px . 'order_fees` GROUP BY `id_order`) f ON f.`id_order` = o.`id_order`'
            : 'LEFT JOIN (SELECT 0 AS id_order, 0 AS fee, 0 AS n) f ON f.`id_order` = o.`id_order`';
        if (!empty($args['only_missing'])) {
            $where .= ' AND o.`module` IN (\'payplug\', \'paypal\') AND COALESCE(f.`fee`, 0) <= 0';
        }

        $db = Db::getInstance();
        $rows = $db->executeS(
            'SELECT o.`id_order`, o.`reference`, o.`date_add`, o.`module`, o.`total_paid_tax_incl`, COALESCE(f.`fee`, 0) AS fee, COALESCE(f.`n`, 0) AS n
             FROM `' . $px . 'orders` o ' . $join . '
             WHERE ' . $where . '
             ORDER BY o.`id_order` DESC LIMIT ' . (int) $args['offset'] . ', ' . (int) $args['limit']
        );
        $totals = $db->executeS(
            'SELECT o.`module`, COUNT(*) AS orders, SUM(o.`total_paid_tax_incl`) AS paid, SUM(COALESCE(f.`fee`, 0)) AS fees,
                    SUM(CASE WHEN COALESCE(f.`fee`, 0) > 0 THEN 1 ELSE 0 END) AS with_fee
             FROM `' . $px . 'orders` o ' . $join . '
             WHERE ' . $where . ' GROUP BY o.`module` ORDER BY orders DESC'
        );

        $orders = [];
        foreach ((array) $rows as $row) {
            $orders[] = [
                'id_order' => (int) $row['id_order'],
                'reference' => (string) $row['reference'],
                'date_add' => (string) $row['date_add'],
                'payment_module' => (string) $row['module'],
                'total_paid_tax_incl' => round((float) $row['total_paid_tax_incl'], 2),
                'commission_ht' => round((float) $row['fee'], 2),
                'has_fee_row' => (int) $row['n'] > 0,
            ];
        }
        $byModule = [];
        foreach ((array) $totals as $row) {
            $byModule[] = [
                'payment_module' => (string) $row['module'],
                'orders' => (int) $row['orders'],
                'orders_with_commission' => (int) $row['with_fee'],
                'total_paid_tax_incl' => round((float) $row['paid'], 2),
                'commission_ht' => round((float) $row['fees'], 2),
            ];
        }

        return ['orders' => $orders, 'totals_by_payment_module' => $byModule, 'row_count' => count($orders)];
    }
}

/**
 * Margin of one order, computed now with the module's calculator (same as the back-office widget).
 */
final class OrderMarginGetTool extends AbstractTool
{
    public function getName(): string
    {
        return 'order_margin_get';
    }

    public function getTitle(): string
    {
        return 'Margin of an order';
    }

    public function getDescription(): string
    {
        return 'Theoretical margin of one order, computed now (same calculator as the back-office widget), tax excluded: '
            . 'revenue (paid + store credit spent - gift card sales - refunds), purchase cost (supplier price x quantity not '
            . 'refunded), supplier cost (dropshipping fee +/- real shipping paid, per supplier), payment commission, net margin, '
            . 'margin_rate (on cost) and markup_rate (on revenue). cost_incomplete: a line has no purchase price; port_pending: '
            . 'a supplier shipping cost is still estimated. Drop fees and commission are not prorated on refunds.';
    }

    public function getInputSchema(): array
    {
        return $this->schema(['id_order' => ['type' => 'integer', 'minimum' => 1]], ['id_order']);
    }

    public function execute(array $args, Principal $principal): array
    {
        $order = MargeMcp::loadOrder((int) $args['id_order']);
        $margin = \MarginCalculator::computeForOrder($order);
        $margin['commission'] = MargeMcp::feeState($order)['status'];
        $cached = Db::getInstance()->executeS('SELECT `net_margin`, `computed_at` FROM `' . _DB_PREFIX_ . 'sj4web_order_margin` WHERE `id_order` = ' . (int) $order->id);
        $margin['cache'] = $cached ? ['net_margin' => (float) $cached[0]['net_margin'], 'computed_at' => $cached[0]['computed_at']] : null;
        $margin['row_count'] = 1;

        return $margin;
    }
}

/**
 * Margins of many orders, from the cache table (as the back-office list).
 */
final class OrderMarginsListTool extends AbstractTool
{
    private const SORT = ['id_order', 'date_order', 'revenue_ht', 'cost_price_ht', 'drop_cost_ht', 'commission_ht', 'net_margin', 'margin_rate', 'markup_rate', 'commission_percent'];

    public function getName(): string
    {
        return 'order_margins_list';
    }

    public function getTitle(): string
    {
        return 'Order margins over a period';
    }

    public function getDescription(): string
    {
        return 'Cached margins of orders (the back-office "order margins" list): filters on dates (YYYY-MM-DD), payment, '
            . 'net margin and rates; sort and pagination; totals of the filtered orders. Cached values are refreshed on order '
            . 'events; order_margin_get computes one order now.';
    }

    public function getInputSchema(): array
    {
        return $this->schema([
            'date_from' => ['type' => 'string', 'maxLength' => 10],
            'date_to' => ['type' => 'string', 'maxLength' => 10],
            'payment' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Part of the payment label'],
            'margin_min' => ['type' => 'number'],
            'margin_max' => ['type' => 'number'],
            'margin_rate_min' => ['type' => 'number'],
            'margin_rate_max' => ['type' => 'number'],
            'commission_percent_min' => ['type' => 'number'],
            'sort' => ['type' => 'string', 'enum' => self::SORT, 'default' => 'id_order'],
            'order_way' => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
        ]);
    }

    public function execute(array $args, Principal $principal): array
    {
        $filters = [];
        foreach (['date_from', 'date_to'] as $key) {
            if (isset($args[$key]) && $args[$key] !== '') {
                $filters[$key] = MargeMcp::checkDate((string) $args[$key], $key);
            }
        }
        if (isset($args['payment']) && $args['payment'] !== '') {
            $filters['payment'] = addcslashes((string) $args['payment'], '%_');
        }
        foreach (['margin_min', 'margin_max', 'margin_rate_min', 'margin_rate_max', 'commission_percent_min'] as $key) {
            if (isset($args[$key])) {
                $filters[$key] = (float) $args[$key];
            }
        }
        $rows = \OrderMarginRepository::search($filters, (string) $args['sort'], (string) $args['order_way'], (int) $args['offset'], (int) $args['limit']);
        $orders = [];
        foreach ($rows as $row) {
            $orders[] = [
                'id_order' => (int) $row['id_order'],
                'date_order' => $row['date_order'],
                'payment_method' => $row['payment_method'],
                'revenue_ht' => (float) $row['revenue_ht'],
                'cost_price_ht' => (float) $row['cost_price_ht'],
                'drop_cost_ht' => (float) $row['drop_cost_ht'],
                'commission_ht' => (float) $row['commission_ht'],
                'net_margin' => (float) $row['net_margin'],
                'margin_rate' => $row['margin_rate'] === null ? null : (float) $row['margin_rate'],
                'markup_rate' => $row['markup_rate'] === null ? null : (float) $row['markup_rate'],
                'cost_incomplete' => (bool) $row['cost_incomplete'],
                'port_pending' => (bool) $row['port_pending'],
                'computed_at' => $row['computed_at'],
            ];
        }

        return ['orders' => $orders, 'total_matching' => \OrderMarginRepository::count($filters), 'row_count' => count($orders)];
    }
}
