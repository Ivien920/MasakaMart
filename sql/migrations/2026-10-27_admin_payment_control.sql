-- MasakaMart — administrator payment control, provider verification & reconciliation
-- Run ONCE per environment:
--   mysql -u USER -p DB_NAME < sql/migrations/2026-10-27_admin_payment_control.sql
-- (The app also applies these changes automatically the first time a payment
-- is processed — see katale_ensure_payment_control_schema() — so running this
-- file by hand is optional but recommended on production.)
-- Requires the earlier 2026-09-24 and 2026-10-24 migrations. Rollback at bottom.

ALTER TABLE payments
    ADD COLUMN admin_decision ENUM('paid','failed','pending') NULL AFTER manual_note,
    ADD COLUMN admin_decision_by INT UNSIGNED NULL AFTER admin_decision,
    ADD COLUMN admin_decision_at DATETIME NULL AFTER admin_decision_by,
    ADD COLUMN admin_decision_note VARCHAR(500) NULL AFTER admin_decision_at,
    ADD COLUMN provider_status VARCHAR(40) NULL AFTER admin_decision_note,
    ADD COLUMN provider_verified_at DATETIME NULL AFTER provider_status,
    ADD COLUMN provider_verification_result VARCHAR(40) NULL AFTER provider_verified_at,
    ADD COLUMN needs_reconciliation TINYINT(1) NOT NULL DEFAULT 0 AFTER provider_verification_result,
    ADD COLUMN reconciliation_note VARCHAR(500) NULL AFTER needs_reconciliation,
    ADD COLUMN reconciliation_flagged_at DATETIME NULL AFTER reconciliation_note,
    ADD COLUMN reconciliation_resolved_at DATETIME NULL AFTER reconciliation_flagged_at,
    ADD COLUMN reconciliation_resolved_by INT UNSIGNED NULL AFTER reconciliation_resolved_at,
    ADD INDEX idx_payments_reconcile (needs_reconciliation),
    ADD INDEX idx_payments_status (status);

-- Every status change, from any source (webhook, poll, cron, admin), with the
-- administrator when there is one. This is the "payment history" shown on the
-- admin payment details page. order_admin_actions remains the admin audit log.
CREATE TABLE IF NOT EXISTS payment_status_history (
    history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    previous_status VARCHAR(20) NULL,
    new_status VARCHAR(20) NOT NULL,
    source VARCHAR(30) NOT NULL,            -- webhook | status_poll | expiry_poll | admin_decision | admin_verify | ...
    admin_id INT UNSIGNED NULL,
    provider_status VARCHAR(40) NULL,
    note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_psh_payment (payment_id, created_at),
    INDEX idx_psh_order (order_id),
    FOREIGN KEY (payment_id) REFERENCES payments(payment_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rollback (manual):
-- DROP TABLE IF EXISTS payment_status_history;
-- ALTER TABLE payments DROP INDEX idx_payments_status, DROP INDEX idx_payments_reconcile,
--   DROP COLUMN reconciliation_resolved_by, DROP COLUMN reconciliation_resolved_at,
--   DROP COLUMN reconciliation_flagged_at, DROP COLUMN reconciliation_note,
--   DROP COLUMN needs_reconciliation, DROP COLUMN provider_verification_result,
--   DROP COLUMN provider_verified_at, DROP COLUMN provider_status,
--   DROP COLUMN admin_decision_note, DROP COLUMN admin_decision_at,
--   DROP COLUMN admin_decision_by, DROP COLUMN admin_decision;
