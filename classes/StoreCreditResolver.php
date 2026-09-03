<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * Store-credit instruments used on an order (gift cards, refund vouchers / exchange
 * credits). Economically these are payment means, not commercial discounts: the cash
 * was already collected when the card / voucher was issued. So on the order where the
 * instrument is *spent*, its HT amount must be added back to the revenue (PrestaShop
 * has subtracted it from `total_paid_tax_excl` like any cart rule).
 *
 * Detection (this shop's naming conventions):
 *  - gift card : `order_cart_rule.name` = "La carte cadeau"
 *  - avoir     : `cart_rule.code` LIKE "REFUND-%"
 *                OR `order_cart_rule.name` LIKE "Remboursement commande%"
 *                OR `order_cart_rule.name` LIKE "Bon d'achat%"   (exchange credit)
 *
 * Left untouched (real discounts, they legitimately reduce the margin): sales,
 * "-5% / -3% CB ou Virement", promo coupons, and "Remboursement Frais de Retour" /
 * REMFDR_* (that one is our own money compensating the customer's return postage).
 *
 * The gift-card *sale* side (an order that sells a gift card) is handled in
 * MarginCalculator via MarginConfig::getGiftCardProductIds().
 *
 * Robustness on hand-reworked orders: `order_cart_rule` rows accumulate (a voucher can
 * be recorded twice, "Frais de port retour" repeated, etc.) and no longer match what
 * was actually subtracted from `total_paid`. Two guards:
 *  - dedupe store-credit rows that share the same rounded HT amount within one order;
 *  - never add back more than what was actually discounted from total_paid
 *    (lines + shipping - total_paid_tax_excl), passed in as $discountCap.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class StoreCreditResolver
{
    /**
     * @param int        $idOrder
     * @param float      $taxRatio    total_paid_tax_incl / total_paid_tax_excl, used only
     *                                to bring legacy rows to HT
     * @param float|null $discountCap amount actually discounted from total_paid
     *                                (lines + shipping - total_paid_tax_excl); the
     *                                add-back is clamped to it (null / <=0 = no clamp)
     *
     * @return array{gift_card_ht:float, voucher_ht:float, total_ht:float, labels:string[], capped:bool}
     */
    public static function getForOrder($idOrder, $taxRatio, $discountCap = null)
    {
        $idOrder = (int) $idOrder;
        $taxRatio = ($taxRatio > 0) ? (float) $taxRatio : 1.0;

        $rows = Db::getInstance()->executeS(
            'SELECT ocr.name AS ocr_name, ocr.value, ocr.value_tax_excl,
                    cr.code, cr.reduction_amount, cr.reduction_tax
             FROM `' . _DB_PREFIX_ . 'order_cart_rule` ocr
             LEFT JOIN `' . _DB_PREFIX_ . 'cart_rule` cr ON cr.id_cart_rule = ocr.id_cart_rule
             WHERE ocr.id_order = ' . $idOrder
        ) ?: [];

        $giftHt = 0.0;
        $voucherHt = 0.0;
        $labels = [];
        $seen = []; // rounded HT amount -> already counted once (dedupe hand-reworked orders)

        foreach ($rows as $r) {
            $kind = self::classify($r);
            if (null === $kind) {
                continue;
            }

            $ht = self::monetaryHt($r, $taxRatio);
            if ($ht <= 0) {
                continue;
            }

            $key = $kind . ':' . number_format($ht, 2, '.', '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            if ('gift_card' === $kind) {
                $giftHt += $ht;
            } else {
                $voucherHt += $ht;
            }

            $name = trim((string) $r['ocr_name']);
            if ('' !== $name) {
                $labels[$name] = true;
            }
        }

        $totalHt = $giftHt + $voucherHt;
        $capped = false;
        if (null !== $discountCap && $discountCap > 0 && $totalHt > $discountCap + 0.01) {
            $scale = $discountCap / $totalHt;
            $giftHt *= $scale;
            $voucherHt *= $scale;
            $totalHt = $discountCap;
            $capped = true;
        }

        return [
            'gift_card_ht' => round($giftHt, 2),
            'voucher_ht' => round($voucherHt, 2),
            'total_ht' => round($totalHt, 2),
            'labels' => array_keys($labels),
            'capped' => $capped,
        ];
    }

    /**
     * @param array $r one order_cart_rule row (+ joined cart_rule)
     *
     * @return string|null 'gift_card' | 'voucher' | null (null = real discount, ignore)
     */
    private static function classify(array $r)
    {
        $name = trim((string) $r['ocr_name']);
        $code = trim((string) $r['code']);

        if ('La carte cadeau' === $name) {
            return 'gift_card';
        }
        if ('' !== $code && 0 === stripos($code, 'REFUND-')) {
            return 'voucher';
        }
        if (0 === stripos($name, 'Remboursement commande')) {
            return 'voucher';
        }
        if (0 === stripos($name, "Bon d'achat")) {
            return 'voucher';
        }

        return null;
    }

    /**
     * Monetary part of the instrument, HT, excluding any free-shipping component.
     *
     *  order_cart_rule.value       = amount consumed on this order, incl. any free-ship part
     *  cart_rule.reduction_amount  = nominal monetary reduction, no shipping (TTC if reduction_tax=1)
     *  free-shipping part (TTC)    = max(0, value - reduction_amount)
     *  => monetary TTC on order    = min(value, reduction_amount)
     *  => monetary HT              = value_tax_excl prorated by (monetary TTC / value)
     *
     * @param array $r
     * @param float $taxRatio
     *
     * @return float
     */
    private static function monetaryHt(array $r, $taxRatio)
    {
        $rawTtc = (float) $r['value'];
        if ($rawTtc <= 0) {
            return 0.0;
        }

        $face = (null === $r['reduction_amount']) ? 0.0 : (float) $r['reduction_amount'];
        if ($face > 0 && !((int) $r['reduction_tax'])) {
            // reduction_amount stored HT -> put it on the same TTC basis as `value`
            $face *= $taxRatio;
        }
        $monetaryTtc = ($face > 0) ? min($rawTtc, $face) : $rawTtc;

        $valueHt = (float) $r['value_tax_excl'];
        if ($valueHt > 0) {
            return $valueHt * ($monetaryTtc / $rawTtc);
        }

        // legacy rows with no value_tax_excl: derive HT from the order tax ratio
        return ($taxRatio > 0) ? ($monetaryTtc / $taxRatio) : $monetaryTtc;
    }
}
