-- MasakaMart — Web Push subscriptions
-- Run this once against the existing database (phpMyAdmin > SQL tab, or
-- `mysql -u USER -p DB_NAME < sql/migration_push_subscriptions.sql`).
-- Safe to run on an existing production database: it only adds a new table
-- and does not touch any existing table or data.

CREATE TABLE IF NOT EXISTS push_subscriptions (
    push_subscription_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    endpoint VARCHAR(512) NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth VARCHAR(255) NOT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT NULL,
    PRIMARY KEY (push_subscription_id),
    UNIQUE KEY uniq_endpoint (endpoint(191)),
    KEY idx_user (user_id),
    CONSTRAINT fk_push_subscriptions_user
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
