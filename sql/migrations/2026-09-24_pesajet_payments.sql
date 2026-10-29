-- ---------------------------------------------------------------------
-- PesaJet real mobile-money integration — payments table extension
-- MasakaMart / katale_db
--
-- Extends the existing `payments` table (no duplicate tables) and adds the
-- `payment_webhook_events` idempotency/audit table used to make webhook
-- processing duplicate-safe.
--
-- MySQL 8 does not support "ADD COLUMN IF NOT EXISTS", so this migration
-- is intended to be run ONCE per environment (it is safe to re-run only
-- after the columns exist — check before applying a second time).
-- ---------------------------------------------------------------------

ALTER TABLE payments
  -- The old ENUM did not contain the values the checkout actually writes
  -- ('mobile_money') and cannot hold PesaJet provider data.
  MODIFY method VARCHAR(30) NOT NULL,
  -- Internal statuses: PENDING | PROCESSING | PAID | FAILED | EXPIRED | CANCELLED
  MODIFY status ENUM('pending','processing','paid','failed','expired','cancelled')
       NOT NULL DEFAULT 'pending';

ALTER TABLE payments
  ADD COLUMN user_id INT UNSIGNED NULL AFTER order_id,
  ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'UGX' AFTER amount,
  ADD COLUMN provider VARCHAR(10) NULL AFTER currency,           -- 'mtn' | 'airtel'
  ADD COLUMN phone_number VARCHAR(20) NULL AFTER provider,       -- E.164, normalized
  ADD COLUMN pesajet_transaction_id VARCHAR(100) NULL,           -- PesaJet txn id
  ADD COLUMN provider_reference VARCHAR(100) NULL,               -- e.g. MTN MoMo ref
  ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN api_response MEDIUMTEXT NULL,                      -- sanitized JSON
  ADD COLUMN webhook_status VARCHAR(40) NULL,                   -- last webhook event/status
  ADD COLUMN webhook_received_at DATETIME NULL,
  ADD COLUMN failure_reason VARCHAR(255) NULL,
  ADD COLUMN paid_at DATETIME NULL,
  ADD UNIQUE KEY uniq_payments_pesajet_txn (pesajet_transaction_id),
  ADD INDEX idx_payments_user (user_id);

-- Backfill user ownership for pre-existing rows (order -> customer).
UPDATE payments p
  JOIN orders o ON o.order_id = p.order_id
   SET p.user_id = o.user_id
 WHERE p.user_id IS NULL;

-- Idempotency + audit trail for webhook deliveries. UNIQUE(transaction_id,
-- event) is the database-level duplicate-webhook lock: a retried delivery
-- fails the INSERT and is acknowledged without touching any order.
CREATE TABLE IF NOT EXISTS payment_webhook_events (
  webhook_event_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id VARCHAR(100) NOT NULL,          -- PesaJet transaction id
  event VARCHAR(60) NOT NULL,                    -- payment.completed | ...
  status VARCHAR(40) NULL,                       -- status inside the payload
  reference VARCHAR(100) NULL,                   -- our MM-PAY / order reference
  payload MEDIUMTEXT NULL,                       -- verified payload (sanitized)
  processed TINYINT(1) NOT NULL DEFAULT 0,
  duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_webhook_txn_event (transaction_id, event),
  INDEX idx_webhook_reference (reference)
) ENGINE=InnoDB;
