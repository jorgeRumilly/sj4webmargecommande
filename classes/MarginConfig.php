<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * Configuration keys / defaults for the margin module.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MarginConfig
{
    const CRON_TOKEN = 'SJ4WEB_MARGIN_CRON_TOKEN';
    const RECOMPUTE_DAYS = 'SJ4WEB_MARGIN_RECOMPUTE_DAYS';
    const MARGIN_GREEN = 'SJ4WEB_MARGIN_COLOR_GREEN';
    const MARGIN_ORANGE = 'SJ4WEB_MARGIN_COLOR_ORANGE';
    const GIFTCARD_PRODUCTS = 'SJ4WEB_MARGIN_GIFTCARD_PRODUCTS';

    /** Rolling window (days) recomputed by the cron to absorb late changes (fees, refunds). */
    const DEFAULT_RECOMPUTE_DAYS = 30;

    /** Net-margin (€) thresholds for the coloured cell in the BO list. */
    const DEFAULT_MARGIN_GREEN = 50;
    const DEFAULT_MARGIN_ORANGE = 10;

    /**
     * Product ids sold as gift cards (turnover-neutral: excluded from revenue and cost
     * on the order that sells them). 3391061 = active `gift_card_EUR`; the rest are the
     * legacy "carte cadeau virtuelle/digitale" products kept for historical orders.
     */
    const DEFAULT_GIFTCARD_PRODUCTS = '3391061,5590,5597,6079,6080,6081,9038,53437';

    /**
     * @return bool
     */
    public static function installDefaults()
    {
        $ok = true;
        if (!Configuration::get(self::CRON_TOKEN)) {
            $ok = $ok && Configuration::updateValue(self::CRON_TOKEN, Tools::passwdGen(48));
        }
        $ok = $ok && Configuration::updateValue(self::RECOMPUTE_DAYS, self::DEFAULT_RECOMPUTE_DAYS);
        $ok = $ok && Configuration::updateValue(self::MARGIN_GREEN, self::DEFAULT_MARGIN_GREEN);
        $ok = $ok && Configuration::updateValue(self::MARGIN_ORANGE, self::DEFAULT_MARGIN_ORANGE);
        if (!Configuration::hasKey(self::GIFTCARD_PRODUCTS)) {
            $ok = $ok && Configuration::updateValue(self::GIFTCARD_PRODUCTS, self::DEFAULT_GIFTCARD_PRODUCTS);
        }

        return $ok;
    }

    /**
     * @return bool
     */
    public static function deleteAll()
    {
        $ok = true;
        foreach ([self::CRON_TOKEN, self::RECOMPUTE_DAYS, self::MARGIN_GREEN, self::MARGIN_ORANGE, self::GIFTCARD_PRODUCTS] as $k) {
            $ok = Configuration::deleteByName($k) && $ok;
        }

        return $ok;
    }

    /**
     * @return int
     */
    public static function getRecomputeDays()
    {
        return max(1, (int) Configuration::get(self::RECOMPUTE_DAYS) ?: self::DEFAULT_RECOMPUTE_DAYS);
    }

    /**
     * @return float
     */
    public static function getMarginGreen()
    {
        $v = Configuration::get(self::MARGIN_GREEN);

        return is_numeric($v) ? (float) $v : self::DEFAULT_MARGIN_GREEN;
    }

    /**
     * @return float
     */
    public static function getMarginOrange()
    {
        $v = Configuration::get(self::MARGIN_ORANGE);

        return is_numeric($v) ? (float) $v : self::DEFAULT_MARGIN_ORANGE;
    }

    /**
     * @return string
     */
    public static function getCronToken()
    {
        return (string) Configuration::get(self::CRON_TOKEN);
    }

    /**
     * @return string raw config value (comma/space separated ids)
     */
    public static function getGiftCardProductsRaw()
    {
        $v = Configuration::get(self::GIFTCARD_PRODUCTS);

        return (false === $v) ? self::DEFAULT_GIFTCARD_PRODUCTS : (string) $v;
    }

    /**
     * @return int[] product ids sold as gift cards (may be empty)
     */
    public static function getGiftCardProductIds()
    {
        $raw = trim(self::getGiftCardProductsRaw());
        if ('' === $raw) {
            return [];
        }

        $ids = [];
        foreach (preg_split('/[\s,;]+/', $raw) as $token) {
            $id = (int) $token;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
