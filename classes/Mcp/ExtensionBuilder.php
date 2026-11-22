<?php
declare(strict_types=1);

namespace Sj4webMargecommande\Mcp;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Sj4webAgentbridge\Extension\Extension;

/**
 * Agent Bridge extension of sj4webmargecommande (contract: sj4web_agentbridge/EXTENSIONS.md).
 * Only loaded from the hook, i.e. when Agent Bridge is installed: the module works without it.
 */
final class ExtensionBuilder
{
    public const API_VERSION = 1;

    /** Files of classes/Mcp, in loading order. */
    public const FILES = ['MargeMcp', 'FeeProviders', 'ReadTools', 'PaymentFeeFetchOperation', 'ExtensionBuilder'];

    public const GUIDE = <<<'MD'
## sj4webmargecommande (order margin, payment commission)

- **Payment commission** (tax excluded) per order: `{prefix}order_fees` (`id_order`, `method` = payplug / paypal, `fee`). Filled by `sj4web_payplugreport` for PayPlug (accounting report of the day; daily cron, back-office button, opening the order page) and by the shop's override of the paypal module for PayPal (opening the order page). Bank transfer and free orders have none. PayPlug follow-up: `{prefix}sj4web_payplug_order_fee` (`attempts`, `last_check`, `finalized` = given up after the alert delay).
- **Margin** (all tax excluded): revenue = `total_paid_tax_excl` + store credit / gift card spent - gift card sales - refunds; purchase cost = supplier price x (quantity - refunded); supplier cost = dropshipping fee per supplier (rules `{prefix}sj4web_margin_drop_rule`, `_step`, `_bucket`) +/- real shipping paid (`{prefix}sj4web_order_drop_actual`); net margin = revenue - purchase cost - supplier cost - commission. Cache per order: `{prefix}sj4web_order_margin` (refreshed on order events).
- Tools: `order_payment_fee_get` (one order, any payment method, with a status), `payment_fees_list` (period, totals per payment module, `only_missing`), `order_margin_get` (computed now), `order_margins_list` (cache, filters). `order_payment_fee_fetch` (scope payment_fee_fetch, two steps) asks PayPlug / PayPal for a missing commission; never changes a commission already above 0.
MD;

    public const INSTRUCTIONS = 'For a payment commission use order_payment_fee_get (any payment method); if it is missing and fetch_available is true, offer order_payment_fee_fetch (two steps). Margins: order_margin_get, order_margins_list.';

    public static function build(string $module, string $version, string $fetchDescription): Extension
    {
        return new Extension(
            $module,
            $version,
            self::API_VERSION,
            [MargeMcp::SCOPE_FETCH => ['description' => $fetchDescription, 'sensitive' => true]],
            [new PaymentFeeGetTool(), new PaymentFeesListTool(), new OrderMarginGetTool(), new OrderMarginsListTool()],
            [new PaymentFeeFetchOperation()],
            self::GUIDE,
            self::INSTRUCTIONS
        );
    }
}
