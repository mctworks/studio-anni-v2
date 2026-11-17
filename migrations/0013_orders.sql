-- 0013: unified orders (commissions now, gallery sales later) and the commission status/message history.
-- orders.status mirrors commissions.status for kind = 'commission'. Sales use their own status values.

CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    kind ENUM('commission', 'sale') NOT NULL,
    commission_id INT UNSIGNED NULL,
    piece_id INT UNSIGNED NULL,
    status VARCHAR(32) NOT NULL,
    total_cents INT UNSIGNED NOT NULL DEFAULT 0,
    paid_cents INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_orders_commission (commission_id),
    KEY idx_orders_kind_status (kind, status, created_at),
    KEY idx_orders_user (user_id),
    KEY idx_orders_piece (piece_id),
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_orders_commission FOREIGN KEY (commission_id) REFERENCES commissions (id) ON DELETE CASCADE,
    CONSTRAINT fk_orders_piece FOREIGN KEY (piece_id) REFERENCES pieces (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- note is always visible to the customer. kind = status: a status change (note optional). kind = message: customer reply.
CREATE TABLE IF NOT EXISTS commission_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    commission_id INT UNSIGNED NOT NULL,
    actor_user_id INT UNSIGNED NULL,
    kind ENUM('status', 'message') NOT NULL,
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NULL,
    note TEXT NULL,
    emailed TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY idx_commission_events_commission (commission_id, id),
    CONSTRAINT fk_commission_events_commission FOREIGN KEY (commission_id) REFERENCES commissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO orders (user_id, kind, commission_id, status, total_cents, paid_cents, created_at, updated_at)
SELECT user_id, 'commission', id, status, quoted_cents, 0, created_at, updated_at FROM commissions;
