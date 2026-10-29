-- MasakaMart — Human-readable delivery locations (reverse geocoding)
-- Additive only: no existing column is renamed, dropped or altered, and
-- every new orders column is nullable so existing rows stay valid.
-- Run once: `mysql -u USER -p DB_NAME < sql/migrations/2026-10-01_readable_delivery_locations.sql`
--
-- NOTE: the application also self-applies these changes at bootstrap via
-- ensure_geocode_tables() (same convention as the notification tables), so
-- running this file is belt-and-braces for hosts where bootstrap DDL is
-- restricted.

-- Short readable place/area name for the delivery location, e.g. "Nyendo".
-- The full readable address is stored in the existing delivery_address /
-- delivery_location_text columns; coordinates stay in delivery_latitude /
-- delivery_longitude and remain the source of truth for distance + fees.
ALTER TABLE orders
    ADD COLUMN delivery_location_name VARCHAR(150) NULL AFTER delivery_location_text;

-- Customer-provided landmark, e.g. "Near Nyendo Market" (displayed as
-- "📍 Nyendo, Masaka, Uganda — Near Nyendo Market" and on the admin order
-- view). Complements — never replaces — the existing delivery_instructions.
ALTER TABLE orders
    ADD COLUMN delivery_landmark VARCHAR(255) NULL AFTER delivery_instructions;

-- Cache for reverse/forward geocoding lookups (OpenStreetMap Nominatim is
-- keyless; caching keeps us within its 1-request-per-second usage policy
-- and lets one lookup serve the quote, the order write and every later
-- order view). Failures are cached too, with a short TTL handled in code.
CREATE TABLE IF NOT EXISTS geocode_cache (
    cache_key VARCHAR(64) NOT NULL PRIMARY KEY,
    kind ENUM('reverse','forward') NOT NULL,
    result_json TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_geocode_cache_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;