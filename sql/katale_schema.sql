-- =====================================================================
-- KATALE — "Your Local Market, Online."
-- Database schema
-- Engine: MySQL / MariaDB (utf8mb4)
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS katale_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE katale_db;

-- ---------------------------------------------------------------------
-- ADMINS (separate from customers — role-based access)
-- ---------------------------------------------------------------------
CREATE TABLE admins (
  admin_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','admin') NOT NULL DEFAULT 'admin',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CUSTOMERS
-- ---------------------------------------------------------------------
CREATE TABLE users (
  user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  phone VARCHAR(30) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  email_verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_email (email)
) ENGINE=InnoDB;

CREATE TABLE user_addresses (
  address_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  label VARCHAR(50) NOT NULL DEFAULT 'Home',
  address_line VARCHAR(255) NOT NULL,
  landmark VARCHAR(255) NULL,
  delivery_zone_id INT UNSIGNED NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CATEGORIES
-- ---------------------------------------------------------------------
CREATE TABLE categories (
  category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(120) NOT NULL UNIQUE,
  description VARCHAR(500) NULL,
  image_path VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- PRODUCTS
-- ---------------------------------------------------------------------
CREATE TABLE products (
  product_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(180) NOT NULL UNIQUE,
  description TEXT NULL,
  image_path VARCHAR(255) NULL,
  unit VARCHAR(30) NOT NULL DEFAULT 'kg',       -- kg, g, piece, bunch, tray, crate, litre, packet, sack, basket
  regular_price DECIMAL(12,2) NOT NULL,
  stock_quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
  low_stock_threshold DECIMAL(10,2) NOT NULL DEFAULT 5,
  search_keywords VARCHAR(255) NULL,
  is_available TINYINT(1) NOT NULL DEFAULT 1,   -- manual availability toggle
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  view_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(category_id),
  FULLTEXT KEY ft_search (name, description, search_keywords),
  INDEX idx_products_category (category_id),
  INDEX idx_products_status (status)
) ENGINE=InnoDB;

-- Discounts kept separate from products so the regular price is never overwritten
CREATE TABLE product_discounts (
  discount_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  discount_type ENUM('percentage','fixed') NOT NULL,
  discount_value DECIMAL(12,2) NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
  INDEX idx_discount_window (product_id, starts_at, ends_at, is_active)
) ENGINE=InnoDB;

CREATE TABLE product_views (
  view_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  session_id VARCHAR(64) NOT NULL,
  viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
  INDEX idx_view_product (product_id),
  INDEX idx_view_session_product_date (session_id, product_id, viewed_at)
) ENGINE=InnoDB;

CREATE TABLE inventory_logs (
  log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  change_qty DECIMAL(10,2) NOT NULL,        -- positive = stock added, negative = stock deducted
  reason ENUM('restock','order_placed','order_cancelled','manual_adjustment') NOT NULL,
  reference VARCHAR(100) NULL,              -- e.g. order number
  admin_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
  FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CART (guest carts keyed by session_id, merged into user cart on login)
-- ---------------------------------------------------------------------
CREATE TABLE carts (
  cart_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  session_id VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  INDEX idx_cart_session (session_id),
  INDEX idx_cart_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE cart_items (
  cart_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cart_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cart_id) REFERENCES carts(cart_id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
  UNIQUE KEY uniq_cart_product (cart_id, product_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- WISHLIST
-- ---------------------------------------------------------------------
CREATE TABLE password_reset_tokens (
  token_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_password_reset_hash (token_hash),
  INDEX idx_password_reset_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE wishlists (
  wishlist_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  UNIQUE KEY uniq_wishlist_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE wishlist_items (
  wishlist_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wishlist_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (wishlist_id) REFERENCES wishlists(wishlist_id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
  UNIQUE KEY uniq_wishlist_product (wishlist_id, product_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SHOPPING LISTS (customer-created, e.g. "Weekly Shopping")
-- ---------------------------------------------------------------------
CREATE TABLE shopping_lists (
  list_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  list_name VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE shopping_list_items (
  list_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  list_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  FOREIGN KEY (list_id) REFERENCES shopping_lists(list_id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
  UNIQUE KEY uniq_list_product (list_id, product_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- READY-MADE SHOPPING BASKETS (admin-managed bundles)
-- ---------------------------------------------------------------------
CREATE TABLE baskets (
  basket_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(150) NOT NULL UNIQUE,
  description VARCHAR(500) NULL,
  image_path VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE basket_items (
  basket_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  basket_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
  FOREIGN KEY (basket_id) REFERENCES baskets(basket_id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
  UNIQUE KEY uniq_basket_product (basket_id, product_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- DELIVERY ZONES
-- ---------------------------------------------------------------------
CREATE TABLE delivery_zones (
  zone_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  zone_name VARCHAR(120) NOT NULL,
  delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE hero_slides (
  slide_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  subtitle VARCHAR(250) NULL,
  eyebrow VARCHAR(80) NULL,
  button_text VARCHAR(80) NULL,
  button_link VARCHAR(255) NULL,
  media_type ENUM('image','video') NOT NULL DEFAULT 'image',
  media_path VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ORDERS
-- ---------------------------------------------------------------------
CREATE TABLE orders (
  order_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(20) NOT NULL UNIQUE,     -- e.g. KT-10001
  user_id INT UNSIGNED NOT NULL,
  customer_name VARCHAR(120) NOT NULL,
  customer_phone VARCHAR(30) NOT NULL,
  customer_email VARCHAR(150) NOT NULL,
  delivery_method ENUM('delivery','pickup') NOT NULL,
  delivery_zone_id INT UNSIGNED NULL,
  delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  delivery_address VARCHAR(255) NULL,
  delivery_instructions VARCHAR(500) NULL,
  items_subtotal DECIMAL(12,2) NOT NULL,
  discount_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  grand_total DECIMAL(12,2) NOT NULL,
  payment_status ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
  order_status ENUM('received','processing','preparing','ready','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'received',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id),
  FOREIGN KEY (delivery_zone_id) REFERENCES delivery_zones(zone_id),
  INDEX idx_orders_user (user_id),
  INDEX idx_orders_status (order_status)
) ENGINE=InnoDB;

-- Historical line items — price/discount frozen at time of purchase
CREATE TABLE order_items (
  order_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  product_name VARCHAR(150) NOT NULL,     -- snapshot of name at purchase time
  unit VARCHAR(30) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,      -- price actually paid per unit (after discount)
  original_unit_price DECIMAL(12,2) NOT NULL, -- regular price at time of purchase
  line_total DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(product_id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  payment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,                    -- customer (from order) — ownership check
  amount DECIMAL(12,2) NOT NULL,
  currency VARCHAR(3) NOT NULL DEFAULT 'UGX',
  provider VARCHAR(10) NULL,                    -- 'mtn' | 'airtel'
  phone_number VARCHAR(20) NULL,                -- normalized E.164, prompt target
  -- 'cash_on_delivery' | 'card' | 'mobile_money' | legacy sim values
  method VARCHAR(30) NOT NULL,
  transaction_reference VARCHAR(100) NOT NULL UNIQUE,  -- MasakaMart MM-PAY-... reference
  status ENUM('pending','processing','paid','failed','expired','cancelled') NOT NULL DEFAULT 'pending',
  pesajet_transaction_id VARCHAR(100) NULL UNIQUE,     -- PesaJet transactionId
  provider_reference VARCHAR(100) NULL,                -- provider (e.g. MoMo) reference
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  api_response MEDIUMTEXT NULL,                -- sanitized last API response
  webhook_status VARCHAR(40) NULL,             -- last webhook event/status
  webhook_received_at DATETIME NULL,
  failure_reason VARCHAR(255) NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
  INDEX idx_payments_user (user_id)
) ENGINE=InnoDB;

-- Idempotency + audit table for PesaJet webhook deliveries.
-- UNIQUE(transaction_id, event) is the database-level duplicate lock: a
-- retried webhook fails the INSERT and is acknowledged without side effects.
CREATE TABLE IF NOT EXISTS payment_webhook_events (
  webhook_event_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id VARCHAR(100) NOT NULL,         -- PesaJet transaction id
  event VARCHAR(60) NOT NULL,                   -- payment.completed | ...
  status VARCHAR(40) NULL,
  reference VARCHAR(100) NULL,                  -- our MM-PAY / order reference
  payload MEDIUMTEXT NULL,                      -- verified payload (sanitized)
  processed TINYINT(1) NOT NULL DEFAULT 0,
  duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_webhook_txn_event (transaction_id, event),
  INDEX idx_webhook_reference (reference)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- REVIEWS (verified purchase only)
-- ---------------------------------------------------------------------
CREATE TABLE reviews (
  review_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  order_item_id INT UNSIGNED NOT NULL,   -- proof of purchase
  rating TINYINT UNSIGNED NOT NULL,
  comment VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (order_item_id) REFERENCES order_items(order_item_id) ON DELETE CASCADE,
  UNIQUE KEY uniq_review_per_purchase (order_item_id),
  CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SUPPORT TICKETS
-- ---------------------------------------------------------------------
CREATE TABLE support_tickets (
  ticket_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  order_id INT UNSIGNED NULL,
  subject VARCHAR(200) NOT NULL,
  status ENUM('open','responded','closed') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE support_messages (
  message_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  sender_type ENUM('customer','admin') NOT NULL,
  sender_id INT UNSIGNED NOT NULL,   -- user_id or admin_id depending on sender_type
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ticket_id) REFERENCES support_tickets(ticket_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SEARCH LOG (feeds autocomplete / popularity)
-- ---------------------------------------------------------------------
CREATE TABLE searches (
  search_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  search_term VARCHAR(150) NOT NULL,
  user_id INT UNSIGNED NULL,
  session_id VARCHAR(64) NOT NULL,
  results_count INT UNSIGNED NOT NULL DEFAULT 0,
  searched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_search_term (search_term)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ADMIN ACTIVITY LOG
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
  notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  type VARCHAR(60) NOT NULL DEFAULT 'system',
  order_id INT UNSIGNED NULL,
  link VARCHAR(255) NULL,
  channel ENUM('in_app','email','both') NOT NULL DEFAULT 'both',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL,
  INDEX idx_notifications_user_new (user_id, is_read, created_at),
  INDEX idx_notifications_order (order_id),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE notification_campaigns (
  campaign_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  link VARCHAR(255) NULL,
  target_type ENUM('all','users','groups') NOT NULL DEFAULT 'all',
  channel ENUM('in_app','email','both') NOT NULL DEFAULT 'both',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_campaigns_created (created_at),
  FOREIGN KEY (created_by) REFERENCES admins(admin_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE notification_logs (
  log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  notification_id BIGINT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  channel ENUM('in_app','email') NOT NULL,
  status ENUM('queued','smtp_accepted','delivered','failed') NOT NULL DEFAULT 'queued',
  sent_at DATETIME NULL,
  error_message TEXT NULL,
  INDEX idx_notification_logs_user (user_id, sent_at),
  FOREIGN KEY (notification_id) REFERENCES notifications(notification_id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_notification_preferences (
  preference_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  order_updates_email TINYINT(1) NOT NULL DEFAULT 1,
  order_updates_in_app TINYINT(1) NOT NULL DEFAULT 1,
  promotional_email TINYINT(1) NOT NULL DEFAULT 1,
  promotional_in_app TINYINT(1) NOT NULL DEFAULT 1,
  system_email TINYINT(1) NOT NULL DEFAULT 1,
  system_in_app TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_pref (user_id),
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE admin_activity_logs (
  log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NOT NULL,
  action VARCHAR(150) NOT NULL,
  details VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- OPTIONAL DEMO SEED DATA
-- Clearly optional — the application works fully without this.
-- Remove this section for a clean production install.
-- =====================================================================

INSERT INTO categories (name, slug, description, is_active, sort_order) VALUES
('Vegetables', 'vegetables', 'Fresh vegetables sourced daily from local markets.', 1, 1),
('Fruits', 'fruits', 'Seasonal fresh fruits.', 1, 2),
('Grains & Cereals', 'grains-cereals', 'Maize, rice, millet and more.', 1, 3),
('Meat, Poultry & Fish', 'meat-poultry-fish', 'Fresh meat, chicken and fish.', 1, 4),
('Tubers & Roots', 'tubers-roots', 'Matooke, cassava, potatoes and yams.', 1, 5);

INSERT INTO products (category_id, name, slug, description, unit, regular_price, stock_quantity, low_stock_threshold, search_keywords, is_available, status) VALUES
(1, 'Tomatoes', 'tomatoes', 'Fresh, ripe tomatoes selected from local growers.', 'kg', 4000, 50, 5, 'tomato tomatoes vegetable', 1, 'active'),
(1, 'Onions', 'onions', 'Red onions, ideal for everyday cooking.', 'kg', 3500, 40, 5, 'onion onions', 1, 'active'),
(5, 'Matooke (Green Bananas)', 'matooke', 'A bunch of fresh matooke, Uganda staple food.', 'bunch', 15000, 20, 3, 'matooke banana plantain', 1, 'active'),
(5, 'Irish Potatoes', 'irish-potatoes', 'Clean, sorted Irish potatoes.', 'kg', 3000, 60, 8, 'potato irish potatoes', 1, 'active'),
(4, 'Eggs (Tray)', 'eggs-tray', 'A tray of 30 fresh eggs.', 'tray', 15000, 30, 5, 'egg eggs tray', 1, 'active');

INSERT INTO product_discounts (product_id, discount_type, discount_value, starts_at, ends_at, is_active) VALUES
(1, 'percentage', 20, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), 1);

INSERT INTO delivery_zones (zone_name, delivery_fee, is_active) VALUES
('Kampala Central', 5000, 1),
('Nakawa', 6000, 1),
('Makindye', 6000, 1),
('Entebbe', 12000, 1);

INSERT INTO baskets (name, slug, description, is_active) VALUES
('Student Basket', 'student-basket', 'Affordable essentials for a student living alone.', 1),
('Family Basket', 'family-basket', 'A weekly bundle of essentials for a small family.', 1);

INSERT INTO basket_items (basket_id, product_id, quantity) VALUES
(1, 1, 1), (1, 4, 2),
(2, 1, 2), (2, 2, 1), (2, 3, 1), (2, 5, 1);
