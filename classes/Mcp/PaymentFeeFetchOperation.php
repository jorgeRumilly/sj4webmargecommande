<?php
declare(strict_types=1);

namespace Sj4webMargecommande\Mcp;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Sj4webAgentbridge\Auth\Principal;
use Sj4webAgentbridge\Mcp\ToolException;
use Sj4webAgentbridge\Write\OperationInterface;

/**
 * Asks the payment provider for a missing commission, through the module that already does it for the back office,
 * then refreshes the cached margins. Undo puts order_fees and the PayPlug ledger back exactly (same ids) for every
 * order the fetch could touch, then refreshes the margins again.
 */
final class PaymentFeeFetchOperation implements OperationInterface
{
    public function getName(): string
    {
        return 'order_payment_fee_fetch';
    }

    public function getTitle(): string
    {
        return 'Fetch a missing payment commission from the provider';
    }

    public function getDescription(): string
    {
        return 'Asks the payment provider (PayPlug, PayPal) for the commission of an order whose commission is missing '
            . '(see order_payment_fee_get: fetch_available). For PayPlug the report covers the whole day of the order, so other '
            . 'PayPlug orders of that day without commission may be filled too (listed in the preview). A commission already '
            . 'above 0 is never changed. Margins are recomputed. Returns a preview and a change_id: apply with apply_change after '
            . 'the user agrees; undo_change removes what was stored.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id_order' => ['type' => 'integer', 'minimum' => 1]],
            'required' => ['id_order'],
            'additionalProperties' => false,
        ];
    }

    public function getRequiredScopes(): array
    {
        return [MargeMcp::SCOPE_FETCH];
    }

    public function prepare(array $args, Principal $principal): array
    {
        $order = MargeMcp::loadOrder((int) $args['id_order']);
        $state = MargeMcp::feeState($order);
        if ($state['status'] === 'stored') {
            throw new ToolException('This order already has a commission (' . $state['commission_ht'] . ' HT): nothing to fetch.');
        }
        if (!in_array($state['status'], ['missing', 'pending', 'given_up'], true)) {
            throw new ToolException('No commission can be fetched for this order (status: ' . $state['status'] . ').');
        }
        $provider = FeeProviders::get((string) $order->module);
        $reason = $provider ? $provider->unavailableReason() : 'No provider.';
        if ($provider === null || $reason !== null) {
            throw new ToolException('The commission cannot be fetched now: ' . $reason);
        }
        $affected = $provider->affectedOrders($order);
        if (!in_array((int) $order->id, $affected, true)) {
            $affected[] = (int) $order->id;
        }
        if (count($affected) > 500) {
            throw new ToolException('Too many orders would be touched (' . count($affected) . '): use the back-office button of the provider module.');
        }

        return [
            'payload' => ['id_order' => (int) $order->id, 'module' => $provider->module(), 'orders' => $affected],
            'preview' => [
                'id_order' => (int) $order->id,
                'reference' => (string) $order->reference,
                'provider' => $provider->module(),
                'current_status' => $state['status'],
                'what_happens' => $provider->describe($order),
                'orders_that_may_be_filled' => $affected,
                'warnings' => ['Calls the payment provider API. Only commissions that are missing are written; margins are recomputed.'],
            ],
        ];
    }

    public function capture(array $payload): array
    {
        return $this->read((array) $payload['orders']);
    }

    public function inspect(array $state): array
    {
        return $this->read((array) $state['orders']);
    }

    public function apply(array $payload): array
    {
        $order = MargeMcp::loadOrder((int) $payload['id_order']);
        $provider = FeeProviders::get((string) $payload['module']);
        if ($provider === null || strtolower((string) $order->module) !== $provider->module()) {
            throw new ToolException('The payment module of the order changed since the preview.');
        }
        $reason = $provider->unavailableReason();
        if ($reason !== null) {
            throw new ToolException('The commission cannot be fetched now: ' . $reason);
        }
        $summary = $provider->fetch($order);
        if (!empty($summary['error'])) {
            // Nothing was written by the provider when its call failed: report it (the change is marked failed).
            throw new ToolException('The provider call failed: ' . $summary['error']);
        }
        MargeMcp::refreshMargins((array) $payload['orders']);

        // Only re-readable data here: the engine compares this state with inspect() before an undo.
        // order_payment_fee_get tells the agent what was stored.
        return $this->read((array) $payload['orders']);
    }

    public function undo(array $before, array $after): void
    {
        $orders = (array) $before['orders'];
        MargeMcp::restore($orders, (array) $before['fees'], (array) $before['ledger']);
        MargeMcp::refreshMargins($orders);
    }

    /**
     * @param array<int, int|string> $orders
     * @return array{orders: int[], fees: array<int, array<string, string|null>>, ledger: array<int, array<string, string|null>>}
     */
    private function read(array $orders): array
    {
        $ids = array_values(array_unique(array_map('intval', $orders)));
        sort($ids);

        return ['orders' => $ids, 'fees' => MargeMcp::feeRows($ids), 'ledger' => MargeMcp::ledgerRows($ids)];
    }
}
