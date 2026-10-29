-- MasakaMart — inventory idempotency, payment verification source, admin audit
-- Run once on production/WAMP: mysql -u USER -p DB_NAME < sql/migrations/2026-10-24_inventory_payment_audit.sql
-- Rollback plan at bottom of this file.

ALTER TABLE orders
    ADD COLUMN inventory_state ENUM('none','deducted','restored') NOT NULL DEFAULT 'deducted' AFTER order_status,
    ADD COLUMN stock_deducted_at DATETIME NULL AFTER inventory_state,
    ADD COLUMN stock_restored_at DATETIME NULL AFTER stock_deducted_at,
    ADD COLUMN needs_admin_review TINYINT(1) NOT NULL DEFAULT 0 AFTER stock_restored_at;

-- Existing rows already had stock deducted at checkout.
UPDATE orders SET inventory_state = 'deducted' WHERE inventory_state = 'deducted' AND stock_deducted_at IS NULL;

ALTER TABLE payments
    ADD COLUMN verification_source ENUM('gateway','manual') NULL AFTER paid_at,
    ADD COLUMN manual_verified_by INT UNSIGNED NULL AFTER verification_source,
    ADD COLUMN manual_verified_at DATETIME NULL AFTER manual_verified_by,
    ADD COLUMN manual_note VARCHAR(500) NULL AFTER manual_verified_at;

CREATE TABLE IF NOT EXISTS order_admin_actions (
    action_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    payment_id INT UNSIGNED NULL,
    admin_id INT UNSIGNED NOT NULL,
    action_type VARCHAR(40) NOT NULL,
    previous_order_status VARCHAR(40) NULL,
    new_order_status VARCHAR(40) NULL,
    previous_payment_status VARCHAR(40) NULL,
    new_payment_status VARCHAR(40) NULL,
    note VARCHAR(500) NULL,
    transaction_reference VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
    FOREIGN KEY (payment_id) REFERENCES payments(payment_id) ON DELETE SET NULL,
    FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE CASCADE,
    INDEX idx_order_admin_actions_order (order_id),
    INDEX idx_order_admin_actions_type (action_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extend inventory log reasons (MySQL 8+ / MariaDB 10.11+ IF NOT EXISTS on enum is unsupported — use procedure-safe ALTER)
ALTER TABLE inventory_logs
    MODIFY COLUMN reason ENUM('restock','order_placed','order_cancelled','order_failed','manual_adjustment') NOT NULL;

-- Rollback (manual):
-- ALTER TABLE orders DROP COLUMN needs_admin_review, DROP COLUMN stock_restored_at, DROP COLUMN stock_deducted_at, DROP COLUMN inventory_state;
-- ALTER TABLE payments DROP COLUMN manual_note, DROP COLUMN manual_verified_at, DROP COLUMN manual_verified_by, DROP COLUMN verification_source;
-- DROP TABLE IF EXISTS order_admin_actions;
-- ALTER TABLE inventory_logs MODIFY COLUMN reason ENUM('restock','order_placed','order_cancelled','manual_adjustment') NOT NULL;
