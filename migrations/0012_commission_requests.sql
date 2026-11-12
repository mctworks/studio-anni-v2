-- commissions had no write path before this migration, so it is empty and is rebuilt to match the outline's status flow and terms columns.
DROP TABLE IF EXISTS commission_files;

DROP TABLE IF EXISTS commissions;

CREATE TABLE commissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    subject_type ENUM('pet', 'human') NOT NULL,
    canvas_size_id SMALLINT UNSIGNED NOT NULL,
    pet_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    person_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    description TEXT NOT NULL,
    delivery ENUM('ship', 'pickup') NOT NULL DEFAULT 'ship',
    postal_code VARCHAR(10) NULL,
    status ENUM('submitted', 'info_requested', 'customer_replied', 'quoted', 'deposit_paid', 'in_progress', 'completed', 'awaiting_balance', 'shipped', 'denied', 'abandoned', 'stopped', 'refunded') NOT NULL DEFAULT 'submitted',
    quoted_cents INT UNSIGNED NOT NULL,
    terms_version VARCHAR(20) NOT NULL,
    terms_accepted_at DATETIME NOT NULL,
    terms_accepted_ip VARBINARY(16) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_commissions_status (status, created_at),
    KEY idx_commissions_user (user_id),
    CONSTRAINT fk_commissions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_commissions_size FOREIGN KEY (canvas_size_id) REFERENCES canvas_sizes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE commission_files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    commission_id INT UNSIGNED NOT NULL,
    stored_name VARCHAR(40) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime VARCHAR(40) NOT NULL,
    bytes INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_commission_files_commission (commission_id),
    CONSTRAINT fk_commission_files_commission FOREIGN KEY (commission_id) REFERENCES commissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE commission_prices MODIFY updated_at DATETIME NULL DEFAULT NULL;
