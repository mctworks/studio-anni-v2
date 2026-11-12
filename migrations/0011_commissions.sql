-- 0011: commission pricing (editable in MIMOSA), commission fees/availability settings, and the commissions table.
-- Money is integer cents. A size with no row for a subject type is not commissionable for that type.
-- Seeded prices are the current V1 list. Re-running this file never overwrites edited prices (INSERT IGNORE).

CREATE TABLE IF NOT EXISTS commission_prices (
    subject_type ENUM('pet', 'human') NOT NULL,
    canvas_size_id SMALLINT UNSIGNED NOT NULL,
    price_cents INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (subject_type, canvas_size_id),
    CONSTRAINT fk_commission_prices_size FOREIGN KEY (canvas_size_id) REFERENCES canvas_sizes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO commission_prices (subject_type, canvas_size_id, price_cents)
SELECT t.subject_type, cs.id, t.price_cents
FROM canvas_sizes cs
JOIN (
    SELECT 'pet' AS subject_type, 7 AS long_in, 5 AS short_in, 10000 AS price_cents
    UNION ALL SELECT 'pet', 6, 6, 10000
    UNION ALL SELECT 'pet', 8, 8, 14500
    UNION ALL SELECT 'pet', 10, 8, 17000
    UNION ALL SELECT 'pet', 12, 9, 20000
    UNION ALL SELECT 'pet', 12, 12, 22000
    UNION ALL SELECT 'pet', 14, 11, 30000
    UNION ALL SELECT 'pet', 16, 12, 34000
    UNION ALL SELECT 'pet', 14, 14, 34000
    UNION ALL SELECT 'pet', 16, 16, 37000
    UNION ALL SELECT 'pet', 20, 16, 40000
    UNION ALL SELECT 'pet', 24, 18, 65000
    UNION ALL SELECT 'human', 7, 5, 13000
    UNION ALL SELECT 'human', 6, 6, 13000
    UNION ALL SELECT 'human', 8, 8, 18000
    UNION ALL SELECT 'human', 10, 8, 22000
    UNION ALL SELECT 'human', 12, 9, 26000
    UNION ALL SELECT 'human', 12, 12, 28500
    UNION ALL SELECT 'human', 14, 11, 39000
    UNION ALL SELECT 'human', 16, 12, 44000
    UNION ALL SELECT 'human', 14, 14, 44000
    UNION ALL SELECT 'human', 16, 16, 48000
    UNION ALL SELECT 'human', 20, 16, 52000
    UNION ALL SELECT 'human', 24, 18, 85000
) t ON t.long_in = cs.long_in AND t.short_in = cs.short_in;

-- extra_pet: each pet after the first on a pet portrait. extra_person: each person after the first on a human portrait.
-- human_pet: each pet added to a human portrait. availability: open | delays | closed | unknown. deposit_percent: 1-100.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('commission_fee_extra_pet_cents', '5000'),
    ('commission_fee_extra_person_cents', '6500'),
    ('commission_fee_human_pet_cents', '5000'),
    ('commission_deposit_percent', '50'),
    ('commission_availability', 'unknown');

-- quoted_cents is the price snapshot at request time, shipping excluded. Source images, the message thread and
-- payment records get their own tables with the request flow.
CREATE TABLE IF NOT EXISTS commissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    subject_type ENUM('pet', 'human') NOT NULL,
    canvas_size_id SMALLINT UNSIGNED NOT NULL,
    pet_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    person_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    notes TEXT NULL,
    status ENUM('requested', 'info_requested', 'approved', 'in_progress', 'finished', 'shipped', 'denied', 'cancelled') NOT NULL DEFAULT 'requested',
    quoted_cents INT UNSIGNED NOT NULL,
    payment_plan ENUM('deposit', 'full') NULL,
    paid_cents INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_commissions_status (status, created_at),
    KEY idx_commissions_user (user_id),
    CONSTRAINT fk_commissions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_commissions_size FOREIGN KEY (canvas_size_id) REFERENCES canvas_sizes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
