<?php
/**
 * Order inventory — deduct on commit, idempotent restore on failure/cancel.
 */

if (!function_exists('ensure_inventory_order_columns')) {
    function ensure_inventory_order_columns(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $columns = [
            "ALTER TABLE orders ADD COLUMN inventory_state ENUM('none','deducted','restored') NOT NULL DEFAULT 'deducted' AFTER order_status",
            'ALTER TABLE orders ADD COLUMN stock_deducted_at DATETIME NULL AFTER inventory_state',
            'ALTER TABLE orders ADD COLUMN stock_restored_at DATETIME NULL AFTER stock_deducted_at',
            'ALTER TABLE orders ADD COLUMN needs_admin_review TINYINT(1) NOT NULL DEFAULT 0 AFTER stock_restored_at',
        ];
        foreach ($columns as $sql) {
            try {
                $db->exec($sql);
            } catch (PDOException $e) {
                if (stripos($e->getMessage(), 'Duplicate column') === false) {
                    error_log('[inventory] DDL skipped: ' . $e->getMessage());
                }
            }
        }

        try {
            $db->exec(
                "ALTER TABLE inventory_logs MODIFY COLUMN reason ENUM('restock','order_placed','order_cancelled','order_failed','manual_adjustment') NOT NULL"
            );
        } catch (PDOException $e) {
            error_log('[inventory] reason enum DDL skipped: ' . $e->getMessage());
        }
    }
}

if (!function_exists('katale_inventory_log_reason')) {
    function katale_inventory_log_reason(string $context): string
    {
        return in_array($context, ['order_cancelled', 'order_failed'], true) ? $context : 'order_cancelled';
    }
}

if (!function_exists('katale_deduct_order_stock')) {
    /**
     * Deduct stock for order line items inside an open transaction.
     *
     * @param array<int,array<string,mixed>> $items Cart/pricing rows with product_id, quantity, name
     */
    function katale_deduct_order_stock(PDO $db, int $orderId, string $orderNumber, array $items): void
    {
        $stockStmt = $db->prepare(
            "UPDATE products SET stock_quantity = stock_quantity - :qty WHERE product_id = :pid AND stock_quantity >= :qty2"
        );
        $logStmt = $db->prepare(
            "INSERT INTO inventory_logs (product_id, change_qty, reason, reference) VALUES (:pid, :chg, 'order_placed', :ref)"
        );

        foreach ($items as $item) {
            $qty = (float)$item['quantity'];
            if ($qty <= 0) {
                throw new RuntimeException('Invalid quantity for ' . ($item['name'] ?? 'product'));
            }
            $stockStmt->execute([
                'qty' => $qty,
                'pid' => (int)$item['product_id'],
                'qty2' => $qty,
            ]);
            if ($stockStmt->rowCount() === 0) {
                throw new RuntimeException('Insufficient stock for ' . ($item['name'] ?? 'product') . ' — please try again.');
            }
            $logStmt->execute([
                'pid' => (int)$item['product_id'],
                'chg' => -$qty,
                'ref' => $orderNumber,
            ]);
        }

        $db->prepare(
            "UPDATE orders SET inventory_state = 'deducted', stock_deducted_at = COALESCE(stock_deducted_at, NOW()) WHERE order_id = :oid"
        )->execute(['oid' => $orderId]);
    }
}

if (!function_exists('katale_restore_order_stock')) {
    /**
     * Restore stock exactly once per order (idempotent).
     *
     * @return bool true when stock was restored in this call
     */
    function katale_restore_order_stock(PDO $db, int $orderId, string $context = 'order_cancelled', ?int $adminId = null): bool
    {
        ensure_inventory_order_columns($db);
        $logReason = katale_inventory_log_reason($context);

        try {
            $db->beginTransaction();

            $lock = $db->prepare("SELECT order_id, order_number, inventory_state FROM orders WHERE order_id = :oid FOR UPDATE");
            $lock->execute(['oid' => $orderId]);
            $order = $lock->fetch();
            if (!$order) {
                $db->rollBack();
                return false;
            }

            $state = (string)($order['inventory_state'] ?? 'deducted');
            if ($state === 'restored' || $state === 'none') {
                $db->commit();
                return false;
            }

            $itemsStmt = $db->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = :oid");
            $itemsStmt->execute(['oid' => $orderId]);
            $items = $itemsStmt->fetchAll();
            if (empty($items)) {
                $db->prepare(
                    "UPDATE orders SET inventory_state = 'restored', stock_restored_at = NOW() WHERE order_id = :oid"
                )->execute(['oid' => $orderId]);
                $db->commit();
                return false;
            }

            $stockStmt = $db->prepare(
                "UPDATE products SET stock_quantity = stock_quantity + :qty WHERE product_id = :pid"
            );
            $logStmt = $db->prepare(
                "INSERT INTO inventory_logs (product_id, change_qty, reason, reference, admin_id) VALUES (:pid, :chg, :reason, :ref, :aid)"
            );
            $ref = (string)$order['order_number'];

            foreach ($items as $item) {
                $qty = (float)$item['quantity'];
                if ($qty <= 0) {
                    continue;
                }
                $stockStmt->execute(['qty' => $qty, 'pid' => (int)$item['product_id']]);
                $logStmt->execute([
                    'pid' => (int)$item['product_id'],
                    'chg' => $qty,
                    'reason' => $logReason,
                    'ref' => $ref,
                    'aid' => $adminId,
                ]);
            }

            $db->prepare(
                "UPDATE orders SET inventory_state = 'restored', stock_restored_at = NOW() WHERE order_id = :oid"
            )->execute(['oid' => $orderId]);

            $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[inventory] restore failed order_id=' . $orderId . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('katale_order_should_release_stock')) {
    function katale_order_should_release_stock(string $paymentStatus, string $orderStatus): bool
    {
        if ($orderStatus === 'cancelled') {
            return true;
        }
        return in_array($paymentStatus, ['failed', 'cancelled'], true);
    }
}
