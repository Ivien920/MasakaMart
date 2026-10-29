-- MasakaMart — Distance-based delivery + pickup locations
-- Additive only: no existing table is dropped or altered destructively,
-- and every new orders column is nullable so existing rows stay valid.
-- Run once: `mysql -u USER -p DB_NAME < sql/migration_delivery_pickup.sql`

-- Generic admin-editable key/value settings store, reused by this feature
-- and available for future settings without another schema change.
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Distance brackets for the "bracket" pricing model (e.g. 0-3km = 2500).
-- Ignored entirely when the per-km pricing model is selected in settings.
CREATE TABLE IF NOT EXISTS delivery_fee_brackets (
    bracket_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    min_km DECIMAL(6,2) NOT NULL,
    max_km DECIMAL(6,2) NULL, -- NULL = "and beyond" (the open-ended top bracket)
    fee DECIMAL(10,2) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('delivery_origin_name', 'Masaka Main Market'),
    ('delivery_origin_lat', '-0.3392'),
    ('delivery_origin_lng', '31.7341'),
    ('delivery_pricing_model', 'bracket'),          -- 'bracket' or 'per_km'
    ('delivery_base_fee', '1500'),
    ('delivery_price_per_km', '500'),
    ('delivery_max_distance_km', '15'),
    ('delivery_max_distance_enabled', '1'),
    ('delivery_enabled', '1'),
    ('pickup_enabled', '1'),
    ('pickup_location_name', 'MasakaMart Main Pickup Point'),
    ('pickup_location_address', 'Masaka Main Market, Masaka'),
    ('pickup_lat', '-0.3392'),
    ('pickup_lng', '31.7341'),
    ('pickup_instructions', 'Collect your order from the MasakaMart pickup point. Please have your order number ready.'),
    ('pickup_phone', '')
ON DUPLICATE KEY UPDATE setting_key = setting_key; -- no-op if this migration is re-run

INSERT INTO delivery_fee_brackets (min_km, max_km, fee, sort_order) VALUES
    (0,  3,  2500, 1),
    (3,  5,  3500, 2),
    (5,  10, 5000, 3),
    (10, 15, 7000, 4);

-- New order columns — all nullable, so every existing order remains valid
-- and simply shows as a legacy order in the UI (see orders/order_detail.php).
ALTER TABLE orders
    ADD COLUMN delivery_location_text VARCHAR(255) NULL AFTER delivery_address,
    ADD COLUMN delivery_latitude DECIMAL(10,7) NULL AFTER delivery_location_text,
    ADD COLUMN delivery_longitude DECIMAL(10,7) NULL AFTER delivery_latitude,
    ADD COLUMN delivery_distance_km DECIMAL(6,2) NULL AFTER delivery_longitude,
    ADD COLUMN pickup_location_name VARCHAR(150) NULL AFTER delivery_distance_km,
    ADD COLUMN pickup_location_address VARCHAR(255) NULL AFTER pickup_location_name,
    ADD COLUMN pickup_instructions VARCHAR(500) NULL AFTER pickup_location_address,
    ADD COLUMN pickup_phone VARCHAR(30) NULL AFTER pickup_instructions;
