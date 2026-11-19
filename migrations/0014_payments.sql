-- 0014: carts, sale order lines and shipments, payments (sales, commission deposits and balances) and the Stripe event log.
-- Money is integer cents. A payment's status is the local record; Stripe is verified before a payment is marked succeeded.

CREATE TABLE IF NOT EXISTS cart_items (
    user_id INT UNSIGNED NOT NULL,
    piece_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, piece_id),
    KEY idx_cart_items_piece (piece_id),
    CONSTRAINT fk_cart_items_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_items_piece FOREIGN KEY (piece_id) REFERENCES pieces (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_shipments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    method ENUM('ship', 'pickup') NOT NULL,
    dest_key CHAR(32) NOT NULL,
    recipient VARCHAR(120) NULL,
    line1 VARCHAR(120) NULL,
    line2 VARCHAR(120) NULL,
    city VARCHAR(80) NULL,
    region CHAR(2) NULL,
    postal_code VARCHAR(10) NOT NULL,
    notes VARCHAR(500) NULL,
    postage_cents INT UNSIGNED NOT NULL DEFAULT 0,
    insurance_cents INT UNSIGNED NOT NULL DEFAULT 0,
    tax_rate_ppm INT UNSIGNED NOT NULL DEFAULT 0,
    shipping_taxable TINYINT(1) NOT NULL DEFAULT 0,
    tax_cents INT UNSIGNED NOT NULL DEFAULT 0,
    packages TEXT NULL,
    quoted_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_order_shipments_order (order_id),
    CONSTRAINT fk_order_shipments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    shipment_id INT UNSIGNED NOT NULL,
    piece_id INT UNSIGNED NOT NULL,
    price_cents INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_order_items_piece (order_id, piece_id),
    KEY idx_order_items_piece (piece_id),
    KEY idx_order_items_shipment (shipment_id),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_shipment FOREIGN KEY (shipment_id) REFERENCES order_shipments (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_piece FOREIGN KEY (piece_id) REFERENCES pieces (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    kind ENUM('sale', 'deposit', 'balance') NOT NULL,
    status ENUM('pending', 'succeeded', 'failed', 'canceled', 'refunded') NOT NULL DEFAULT 'pending',
    base_cents INT UNSIGNED NOT NULL,
    shipping_cents INT UNSIGNED NOT NULL DEFAULT 0,
    tax_cents INT UNSIGNED NOT NULL DEFAULT 0,
    amount_cents INT UNSIGNED NOT NULL,
    stripe_payment_intent_id VARCHAR(64) NULL,
    terms_version VARCHAR(20) NULL,
    terms_accepted_at DATETIME NULL,
    terms_accepted_ip VARBINARY(16) NULL,
    expires_at DATETIME NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_payments_intent (stripe_payment_intent_id),
    KEY idx_payments_order (order_id, status),
    KEY idx_payments_expiry (status, expires_at),
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_events (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    type VARCHAR(100) NOT NULL,
    received_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
