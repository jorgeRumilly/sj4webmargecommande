<?php
declare(strict_types=1);

namespace Sj4webMargecommande\Mcp;

if (!defined('_PS_VERSION_')) {
    exit;
}

use DateTime;
use Module;
use Order;

/**
 * Where the commission of a payment module comes from. Each provider only calls the code that already fills
 * {prefix}order_fees for that payment method (it never computes a fee itself), so the MCP and the back office
 * always agree. Tests replace the providers (FeeProviders::override) so no real payment API is called.
 */
interface PaymentFeeProvider
{
    /** Payment module name, as in orders.module (e.g. "payplug"). */
    public function module(): string;

    /** Null when a fetch can run now, otherwise why not (module missing, not configured...). */
    public function unavailableReason(): ?string;

    /**
     * Orders whose order_fees / ledger rows the fetch may write (the order itself, or the whole day for PayPlug).
     *
     * @return int[]
     */
    public function affectedOrders(Order $order): array;

    /** What the user is told before agreeing. */
    public function describe(Order $order): string;

    /**
     * Runs the fetch through the owning module. Returns a short summary (never secret values).
     *
     * @return array<string, mixed>
     */
    public function fetch(Order $order): array;
}

final class FeeProviders
{
    /** @var array<string, PaymentFeeProvider>|null */
    private static $override;

    /**
     * @param array<string, PaymentFeeProvider>|null $providers module => provider; null restores the real ones
     */
    public static function override(?array $providers): void
    {
        self::$override = $providers;
    }

    public static function get(string $module): ?PaymentFeeProvider
    {
        $all = self::$override ?? ['payplug' => new PayplugFeeProvider(), 'paypal' => new PaypalFeeProvider()];

        return $all[strtolower($module)] ?? null;
    }
}

/**
 * PayPlug: sj4web_payplugreport (>= 2.0) reads the fee from a PayPlug accounting report of the order's day.
 * The report covers the whole day: every PayPlug order of that day still missing a fee can be filled too.
 */
final class PayplugFeeProvider implements PaymentFeeProvider
{
    public function module(): string
    {
        return 'payplug';
    }

    public function unavailableReason(): ?string
    {
        if (!Module::isInstalled('sj4web_payplugreport') || !Module::isEnabled('sj4web_payplugreport')) {
            return 'sj4web_payplugreport is not installed or disabled.';
        }
        $this->load();
        if (!class_exists('PayplugFeeSync') || !method_exists('PayplugFeeSync', 'runForOrder')) {
            return 'sj4web_payplugreport is too old (2.0.0 or later is needed).';
        }
        if (!(new \PayplugFeeSync(true, true))->hasCredentials()) {
            return 'The PayPlug API credentials are not available.';
        }

        return null;
    }

    public function affectedOrders(Order $order): array
    {
        $this->load();
        $day = substr((string) $order->date_add, 0, 10);
        $next = (new DateTime($day))->modify('+1 day')->format('Y-m-d');
        $ids = [(int) $order->id];
        foreach ((array) \PayplugFeeRepository::findOrdersMissingFee($day, $next, false) as $row) {
            $ids[] = (int) $row['id_order'];
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    public function describe(Order $order): string
    {
        return 'Creates a PayPlug accounting report for ' . substr((string) $order->date_add, 0, 10)
            . ' (up to ~10 s) and stores the commission of every PayPlug order of that day that still has none. '
            . 'If PayPlug has not published it yet, nothing is stored and the daily cron tries again.';
    }

    public function fetch(Order $order): array
    {
        $this->load();
        $stats = (new \PayplugFeeSync(false, true))->runForOrder((int) $order->id, (string) $order->date_add);

        return [
            'provider' => 'payplug',
            'orders_checked' => (int) ($stats['candidates'] ?? 0),
            'fees_captured' => (int) ($stats['captured'] ?? 0),
            'not_found_yet' => (int) ($stats['not_found'] ?? 0),
            'error' => empty($stats['error']) ? null : mb_substr((string) $stats['error'], 0, 200),
        ];
    }

    private function load(): void
    {
        $dir = _PS_MODULE_DIR_ . 'sj4web_payplugreport/classes/';
        foreach (['PayplugFeeConfig', 'PayplugFeeLock', 'PayplugFeeParser', 'PayplugReportClient', 'PayplugFeeRepository', 'PayplugFeeSync'] as $class) {
            if (!class_exists($class, false) && is_file($dir . $class . '.php')) {
                require_once $dir . $class . '.php';
            }
        }
    }
}

/**
 * PayPal: the shop's override of the paypal module (override/modules/paypal/paypal.php) asks PayPal for the capture
 * and stores paypal_fee in order_fees when the order page is opened in the back office. Same call here.
 */
final class PaypalFeeProvider implements PaymentFeeProvider
{
    public function module(): string
    {
        return 'paypal';
    }

    public function unavailableReason(): ?string
    {
        if (!Module::isInstalled('paypal') || !Module::isEnabled('paypal')) {
            return 'The paypal module is not installed or disabled.';
        }
        $paypal = Module::getInstanceByName('paypal');
        if (!$paypal || !method_exists($paypal, 'hookDisplayAdminOrderTop') || strpos(get_class($paypal), 'Override') === false) {
            return 'The PayPal commission override (override/modules/paypal/paypal.php) is not active.';
        }

        return null;
    }

    public function affectedOrders(Order $order): array
    {
        return [(int) $order->id];
    }

    public function describe(Order $order): string
    {
        return 'Asks PayPal for the capture of this order and stores its paypal_fee (the same call the back-office order page makes).';
    }

    public function fetch(Order $order): array
    {
        $level = ob_get_level();
        ob_start();
        $error = null;
        try {
            Module::getInstanceByName('paypal')->hookDisplayAdminOrderTop(['id_order' => (int) $order->id]);
        } catch (\Throwable $e) {
            // PayPal API error: nothing was stored (the override inserts after a successful call only).
            $error = 'PayPal did not answer (' . get_class($e) . ').';
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return ['provider' => 'paypal', 'error' => $error];
    }
}
