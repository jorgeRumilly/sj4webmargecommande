<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * Single source of truth for the order margin. Used by the admin order widget (live)
 * and by the cache updater (OrderMarginRepository), so the two always agree.
 *
 * Full P&L including shipping (all HT):
 *   revenue    = total_paid_tax_excl + store_credit_ht - gift_card_sales_ht
 *                - refund_products_ht - refund_shipping_ht
 *                (products + shipping charged, net of *real* discounts and refunds)
 *   cost_price = SUM( purchase_supplier_price * (qty - qty_refunded) )
 *   drop_cost  = SUM per supplier of ( drop fee estimate  +/-  real shipping paid )
 *                see DropCostResolver: rule "drop_covers_shipping" decides +/-
 *   commission = SUM( order_fees.fee )                    (payment commission, HT)
 *   net_margin = revenue - cost_price - drop_cost - commission
 *
 * Notes:
 *  - `total_paid_tax_excl` already reflects every cart rule / voucher.
 *  - Gift cards & refund vouchers are payment means, not discounts: their HT amount
 *    (`store_credit_ht`, from StoreCreditResolver) is added back to the revenue.
 *  - An order that *sells* a gift card is turnover-neutral: those product lines
 *    (`gift_card_sales_ht`, MarginConfig::getGiftCardProductIds()) are removed from
 *    revenue and carry no purchase cost.
 *  - Drop fees and commission are not prorated on refunds (kept conservative).
 *  - `port_pending` is true while a non-"covers_shipping" supplier has no real
 *    shipping value entered yet: the margin is then slightly overstated.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MarginCalculator
{
    /**
     * @param Order $order
     *
     * @return array
     */
    public static function computeForOrder(Order $order)
    {
        $idOrder = (int) $order->id;

        $lines = DropCostResolver::fetchOrderLines($idOrder);
        $giftCardProductIds = MarginConfig::getGiftCardProductIds();

        $costPrice = 0.0;
        $nbProducts = 0;
        $costIncomplete = false;
        $giftCardSalesHt = 0.0;
        $allLinesHt = 0.0;
        foreach ($lines as $line) {
            $allLinesHt += (float) $line['total_price_tax_excl'];

            // A gift-card sale line is a liability, not merchandise: it counts neither
            // as turnover nor as purchase cost, and must not flag "incomplete cost".
            if ($giftCardProductIds && in_array((int) $line['product_id'], $giftCardProductIds, true)) {
                $giftCardSalesHt += $line['total_price_tax_excl'];
                continue;
            }

            $effectiveQty = max(0, $line['quantity'] - $line['quantity_refunded']);
            $costPrice += $line['purchase_price'] * $effectiveQty;
            $nbProducts += $line['quantity'];
            if ($line['quantity'] > 0 && $line['purchase_price'] <= 0) {
                $costIncomplete = true;
            }
        }

        $refunds = self::getRefunds($idOrder);
        $commission = self::getCommission($idOrder);

        $drop = DropCostResolver::resolve(
            $lines,
            DropRuleRepository::getActiveRulesBySupplier(),
            OrderDropActualRepository::getForOrder($idOrder)
        );

        $totalPaidHt = (float) $order->total_paid_tax_excl;
        $totalPaidTtc = (float) $order->total_paid_tax_incl;
        $shippingChargedHt = (float) $order->total_shipping_tax_excl;

        $taxRatio = ($totalPaidHt > 0) ? ($totalPaidTtc / $totalPaidHt) : 1.0;

        // Cap the store-credit add-back at what was *actually* discounted from total_paid
        // (self-consistent with the revenue formula; protects against stale/duplicated
        // order_cart_rule rows on hand-reworked orders).
        $discountApplied = max(0.0, round($allLinesHt + $shippingChargedHt - $totalPaidHt, 2));
        $credit = StoreCreditResolver::getForOrder($idOrder, $taxRatio, $discountApplied);
        $giftCardSalesHt = round($giftCardSalesHt, 2);

        $revenue = $totalPaidHt + $credit['total_ht'] - $giftCardSalesHt
            - $refunds['products_ht'] - $refunds['shipping_ht'];
        $costPrice = round($costPrice, 2);
        $dropCost = (float) $drop['total'];
        $netMargin = round($revenue - $costPrice - $dropCost - $commission, 2);

        return [
            'id_order' => $idOrder,
            'revenue_ht' => round($revenue, 2),
            'cost_price_ht' => $costPrice,
            'drop_cost_ht' => round($dropCost, 2),
            'commission_ht' => round($commission, 2),
            'shipping_charged_ht' => round($shippingChargedHt, 2),
            'port_paid_ht' => round((float) $drop['port_paid'], 2),
            'shipping_delta_ht' => round($shippingChargedHt - $refunds['shipping_ht'] - (float) $drop['port_paid'], 2),
            'refund_products_ht' => round($refunds['products_ht'], 2),
            'refund_shipping_ht' => round($refunds['shipping_ht'], 2),
            'store_credit_ht' => round((float) $credit['total_ht'], 2),
            'store_credit_gift_ht' => round((float) $credit['gift_card_ht'], 2),
            'store_credit_voucher_ht' => round((float) $credit['voucher_ht'], 2),
            'store_credit_capped' => !empty($credit['capped']),
            'store_credit_labels' => $credit['labels'],
            'gift_card_sales_ht' => $giftCardSalesHt,
            'has_store_credit' => ($credit['total_ht'] > 0 || $giftCardSalesHt > 0),
            'net_margin' => $netMargin,
            'margin_rate' => ($costPrice > 0) ? round($netMargin / $costPrice * 100, 2) : null,
            'markup_rate' => ($revenue > 0) ? round($netMargin / $revenue * 100, 2) : null,
            'commission_percent' => ($totalPaidTtc > 0) ? round($commission / $totalPaidTtc * 100, 2) : null,
            'cost_incomplete' => $costIncomplete,
            'port_pending' => (bool) $drop['port_pending'],
            'payment_method' => (string) $order->payment,
            'nb_products' => $nbProducts,
            'date_order' => (string) $order->date_add,
            'drop_by_supplier' => array_values($drop['by_supplier']),
        ];
    }

    /**
     * @param int $idOrder
     *
     * @return array{products_ht:float, shipping_ht:float}
     */
    private static function getRefunds($idOrder)
    {
        $row = Db::getInstance()->executeS(
            'SELECT
                COALESCE(SUM(total_products_tax_excl), 0) AS products_ht,
                COALESCE(SUM(total_shipping_tax_excl), 0) AS shipping_ht
             FROM `' . _DB_PREFIX_ . 'order_slip`
             WHERE id_order = ' . (int) $idOrder
        );

        return [
            'products_ht' => $row ? (float) $row[0]['products_ht'] : 0.0,
            'shipping_ht' => $row ? (float) $row[0]['shipping_ht'] : 0.0,
        ];
    }

    /**
     * @param int $idOrder
     *
     * @return float
     */
    private static function getCommission($idOrder)
    {
        $row = Db::getInstance()->executeS(
            'SELECT COALESCE(SUM(fee), 0) AS total FROM `' . _DB_PREFIX_ . 'order_fees`
             WHERE id_order = ' . (int) $idOrder
        );

        return $row ? (float) $row[0]['total'] : 0.0;
    }
}
