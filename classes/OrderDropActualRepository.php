<?php
/**
 * SJ4WEB.FR - Marge Commande
 *
 * Real shipping cost paid, entered on an order, per supplier
 * (`sj4web_order_drop_actual`).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class OrderDropActualRepository
{
    const TABLE = 'sj4web_order_drop_actual';

    /**
     * @return Db
     */
    private static function db()
    {
        return Db::getInstance();
    }

    /**
     * @param int $idOrder
     *
     * @return array<int,array{amount:float,note:string}> keyed by id_supplier
     */
    public static function getForOrder($idOrder)
    {
        $rows = self::db()->executeS(
            'SELECT id_supplier, amount_ht, note FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE id_order = ' . (int) $idOrder
        ) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id_supplier']] = [
                'amount' => (float) $r['amount_ht'],
                'note' => (string) $r['note'],
            ];
        }

        return $out;
    }

    /**
     * Insert / update / delete one supplier line for an order.
     * A null or negative amount removes the line (back to "estimate only").
     *
     * @param int         $idOrder
     * @param int         $idSupplier
     * @param float|null  $amount
     * @param string      $note
     * @param int|null    $idEmployee
     *
     * @return void
     */
    public static function save($idOrder, $idSupplier, $amount, $note = '', $idEmployee = null)
    {
        $idOrder = (int) $idOrder;
        $idSupplier = (int) $idSupplier;

        if (null === $amount || $amount < 0) {
            self::db()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '`
                 WHERE id_order = ' . $idOrder . ' AND id_supplier = ' . $idSupplier
            );

            return;
        }

        $note = Tools::substr((string) $note, 0, 255);

        self::db()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '` (id_order, id_supplier, amount_ht, note, id_employee, date_upd)
             VALUES (' . $idOrder . ', ' . $idSupplier . ', ' . sprintf('%.2F', (float) $amount) . ', "' . pSQL($note) . '", '
                . (null === $idEmployee ? 'NULL' : (int) $idEmployee) . ', NOW())
             ON DUPLICATE KEY UPDATE
                amount_ht = ' . sprintf('%.2F', (float) $amount) . ',
                note = "' . pSQL($note) . '",
                id_employee = ' . (null === $idEmployee ? 'NULL' : (int) $idEmployee) . ',
                date_upd = NOW()'
        );
    }

    /**
     * @param int $idOrder
     *
     * @return void
     */
    public static function deleteForOrder($idOrder)
    {
        self::db()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE id_order = ' . (int) $idOrder
        );
    }
}
