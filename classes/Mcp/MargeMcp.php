<?php
declare(strict_types=1);

namespace Sj4webMargecommande\Mcp;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Db;
use Order;
use Sj4webAgentbridge\Mcp\ToolException;
use Validate;

/**
 * Shared helpers of the MCP tools of sj4webmargecommande (loaded from the Agent Bridge hook only).
 */
final class MargeMcp
{
    /** Ask the payment provider for a missing commission (writes order_fees; two steps, undoable). */
    public const SCOPE_FETCH = 'payment_fee_fetch';

    public const FEES_TABLE = 'order_fees';
    public const LEDGER_TABLE = 'sj4web_payplug_order_fee';
    public const FEE_COLUMNS = ['id_order_fee', 'id_order', 'method', 'fee', 'date_add'];
    public const LEDGER_COLUMNS = ['id_order', 'fee', 'captured', 'finalized', 'alerted', 'attempts', 'last_check', 'date_add'];

    /** Payment modules that never carry a commission. */
    public const NO_FEE_MODULES = ['ps_wirepayment', 'ps_checkpayment', 'free_order', 'ps_cashondelivery'];

    public static function loadOrder(int $idOrder): Order
    {
        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            throw new ToolException('Order ' . $idOrder . ' not found.');
        }

        return $order;
    }

    public static function tableExists(string $table): bool
    {
        static $known = [];
        if (!isset($known[$table])) {
            $rows = Db::getInstance()->executeS('SHOW TABLES LIKE \'' . pSQL(_DB_PREFIX_ . $table) . '\'');
            $known[$table] = is_array($rows) && $rows !== [];
        }

        return $known[$table];
    }

    /**
     * @param int[] $ids
     */
    private static function inList(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return $ids === [] ? '0' : implode(', ', $ids);
    }

    /**
     * order_fees rows of some orders.
     *
     * @param int[] $ids
     * @return array<int, array<string, string|null>>
     */
    public static function feeRows(array $ids): array
    {
        if (!self::tableExists(self::FEES_TABLE)) {
            return [];
        }
        $rows = Db::getInstance()->executeS(
            'SELECT `' . implode('`, `', self::FEE_COLUMNS) . '` FROM `' . _DB_PREFIX_ . self::FEES_TABLE . '`
             WHERE `id_order` IN (' . self::inList($ids) . ') ORDER BY `id_order_fee`'
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * PayPlug ledger rows (sj4web_payplugreport) of some orders; empty when the module is absent.
     *
     * @param int[] $ids
     * @return array<int, array<string, string|null>>
     */
    public static function ledgerRows(array $ids): array
    {
        if (!self::tableExists(self::LEDGER_TABLE)) {
            return [];
        }
        $rows = Db::getInstance()->executeS(
            'SELECT `' . implode('`, `', self::LEDGER_COLUMNS) . '` FROM `' . _DB_PREFIX_ . self::LEDGER_TABLE . '`
             WHERE `id_order` IN (' . self::inList($ids) . ') ORDER BY `id_order`'
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Puts back the order_fees and ledger rows of some orders exactly as captured (same ids).
     *
     * @param int[] $ids
     * @param array<int, array<string, string|null>> $fees
     * @param array<int, array<string, string|null>> $ledger
     */
    public static function restore(array $ids, array $fees, array $ledger): void
    {
        $db = Db::getInstance();
        if (self::tableExists(self::FEES_TABLE)) {
            $db->execute('DELETE FROM `' . _DB_PREFIX_ . self::FEES_TABLE . '` WHERE `id_order` IN (' . self::inList($ids) . ')');
            foreach ($fees as $row) {
                self::insert(self::FEES_TABLE, self::FEE_COLUMNS, $row);
            }
        }
        if (self::tableExists(self::LEDGER_TABLE)) {
            $db->execute('DELETE FROM `' . _DB_PREFIX_ . self::LEDGER_TABLE . '` WHERE `id_order` IN (' . self::inList($ids) . ')');
            foreach ($ledger as $row) {
                self::insert(self::LEDGER_TABLE, self::LEDGER_COLUMNS, $row);
            }
        }
    }

    /**
     * Recomputes the cached margins of some orders (derived data: not part of any undo state).
     *
     * @param int[] $ids
     */
    public static function refreshMargins(array $ids): void
    {
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            try {
                \OrderMarginRepository::refreshOrder($id);
            } catch (\Throwable $e) {
                \PrestaShopLogger::addLog('sj4webmargecommande MCP: margin refresh failed: ' . $e->getMessage(), 2, null, 'Order', $id, true);
            }
        }
    }

    /**
     * Commission state of one order.
     *
     * @return array<string, mixed>
     */
    public static function feeState(Order $order): array
    {
        $rows = self::feeRows([(int) $order->id]);
        $total = 0.0;
        $lines = [];
        foreach ($rows as $row) {
            $total += (float) $row['fee'];
            $lines[] = ['method' => $row['method'], 'fee_ht' => round((float) $row['fee'], 2), 'date_add' => $row['date_add']];
        }
        $module = strtolower((string) $order->module);
        $provider = FeeProviders::get($module);
        $ledger = self::ledgerRows([(int) $order->id]);
        $ledger = $ledger[0] ?? null;

        if ($total > 0) {
            $status = 'stored';
        } elseif (in_array($module, self::NO_FEE_MODULES, true) || (float) $order->total_paid_real <= 0 && (float) $order->total_paid_tax_incl <= 0) {
            $status = 'not_applicable';
        } elseif ($provider === null) {
            $status = 'not_tracked';
        } elseif ($rows !== []) {
            $status = 'zero_reported';
        } elseif ($ledger !== null && (int) $ledger['finalized'] === 1) {
            $status = 'given_up';
        } elseif ($ledger !== null && (int) $ledger['attempts'] > 0) {
            $status = 'pending';
        } else {
            $status = 'missing';
        }
        $reason = $provider !== null && in_array($status, ['missing', 'pending', 'given_up'], true) ? $provider->unavailableReason() : null;

        return [
            'id_order' => (int) $order->id,
            'reference' => (string) $order->reference,
            'date_add' => (string) $order->date_add,
            'payment_module' => $module,
            'payment' => (string) $order->payment,
            'total_paid_tax_incl' => round((float) $order->total_paid_tax_incl, 2),
            'commission_ht' => round($total, 2),
            'commission_percent_of_paid' => (float) $order->total_paid_tax_incl > 0 ? round($total / (float) $order->total_paid_tax_incl * 100, 2) : null,
            'status' => $status,
            'fee_rows' => $lines,
            'payplug_tracking' => $ledger === null ? null : [
                'attempts' => (int) $ledger['attempts'],
                'last_check' => $ledger['last_check'],
                'finalized' => (bool) $ledger['finalized'],
            ],
            'fetch_available' => in_array($status, ['missing', 'pending', 'given_up'], true) && $reason === null,
            'fetch_unavailable_reason' => $reason,
        ];
    }

    /**
     * @param string[] $columns
     * @param array<string, string|null> $row
     */
    private static function insert(string $table, array $columns, array $row): void
    {
        $values = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? null;
            $values[] = $value === null ? 'NULL' : '\'' . pSQL((string) $value) . '\'';
        }
        if (!Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . $table . '` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $values) . ')'
        )) {
            throw new ToolException('A row could not be restored in ' . $table . '.');
        }
    }

    /** YYYY-MM-DD check for date filters. */
    public static function checkDate(string $date, string $name): string
    {
        $parsed = \DateTime::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new ToolException($name . ' must be a date YYYY-MM-DD.');
        }

        return $date;
    }
}
