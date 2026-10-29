-- MasakaMart - record the policy version accepted for each submitted order.
-- Run once per environment:
--   mysql -u USER -p DB_NAME < sql/migrations/2026-10-29_terms_acceptance.sql

ALTER TABLE orders
    ADD COLUMN terms_version VARCHAR(20) NULL AFTER order_status,
    ADD COLUMN terms_accepted_at DATETIME NULL AFTER terms_version;

-- Existing orders intentionally remain NULL because no historical acceptance
-- event can be reconstructed safely.