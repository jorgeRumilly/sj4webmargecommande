<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * Colour hints for the admin order widget (and reusable elsewhere). Kept out of the
 * calculator so the numbers stay presentation-free.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MarginPresenter
{
    const GOOD = '#00994d';
    const WARN = '#e67e00';
    const BAD = '#cc0000';
    const INFO = '#1a6fb0';
    const MUTED = '#888';

    /**
     * Net margin (€) vs the configured green / orange thresholds.
     *
     * @param float $value
     *
     * @return string hex colour
     */
    public static function netMarginColor($value)
    {
        $value = (float) $value;
        if ($value >= MarginConfig::getMarginGreen()) {
            return self::GOOD;
        }
        if ($value >= MarginConfig::getMarginOrange()) {
            return self::WARN;
        }

        return self::BAD;
    }

    /**
     * Markup / margin rate (%).
     *
     * @param float|null $rate
     *
     * @return string
     */
    public static function rateColor($rate)
    {
        if (null === $rate) {
            return self::MUTED;
        }
        $rate = (float) $rate;
        if ($rate >= 25) {
            return self::GOOD;
        }
        if ($rate >= 12) {
            return self::WARN;
        }

        return self::BAD;
    }

    /**
     * Payment commission as a share of the paid amount (%).
     *
     * @param float|null $pct
     *
     * @return string
     */
    public static function commissionPctColor($pct)
    {
        if (null === $pct) {
            return self::MUTED;
        }
        $pct = (float) $pct;
        if ($pct >= 15) {
            return self::BAD;
        }
        if ($pct >= 10) {
            return self::WARN;
        }
        if ($pct >= 5) {
            return self::INFO;
        }

        return 'inherit';
    }

    /**
     * @param array $m MarginCalculator::computeForOrder() output
     *
     * @return array<string,string> colour per widget field
     */
    public static function colors(array $m)
    {
        return [
            'net_margin' => self::netMarginColor($m['net_margin']),
            'markup_rate' => self::rateColor($m['markup_rate']),
            'margin_rate' => self::rateColor($m['margin_rate']),
            'commission' => self::commissionPctColor($m['commission_percent']),
            'cost' => !empty($m['cost_incomplete']) ? self::WARN : 'inherit',
        ];
    }
}
