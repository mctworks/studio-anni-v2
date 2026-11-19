CREATE TABLE IF NOT EXISTS commission_external_payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    commission_id INT UNSIGNED NOT NULL,
    kind ENUM('deposit', 'full', 'balance') NOT NULL,
    method ENUM('paypal', 'venmo', 'other') NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    reference VARCHAR(120) NULL,
    recorded_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_commission_external_payments_commission (commission_id, kind),
    CONSTRAINT fk_commission_external_payments_commission FOREIGN KEY (commission_id) REFERENCES commissions (id) ON DELETE CASCADE,
    CONSTRAINT fk_commission_external_payments_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
