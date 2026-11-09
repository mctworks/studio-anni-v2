-- 0008: customer accounts. Reuses users (role = 'customer'); public profile fields live in customer_profiles.
-- email_codes holds 6-digit codes for email verification and login 2FA (stored as HMAC, never raw).
-- trusted_devices skips the login code for 30 days on a browser the customer chose to trust.
-- piece_owners: a row means the piece was delivered to that user (set by shipping/orders later, or by hand in MIMOSA). Titles derive from the count.

CREATE TABLE IF NOT EXISTS customer_profiles (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    username VARCHAR(24) NOT NULL,
    location VARCHAR(80) NULL,
    profile_public TINYINT(1) NOT NULL DEFAULT 0,
    custom_title VARCHAR(40) NULL,
    UNIQUE KEY uq_customer_profiles_username (username),
    CONSTRAINT fk_customer_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_codes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    purpose ENUM('verify', 'login') NOT NULL,
    code_hash CHAR(64) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_email_codes_user (user_id, purpose, used_at),
    CONSTRAINT fk_email_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trusted_devices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    user_agent VARCHAR(255) NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    UNIQUE KEY uq_trusted_devices_token (token_hash),
    KEY idx_trusted_devices_user (user_id, expires_at),
    CONSTRAINT fk_trusted_devices_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS piece_owners (
    piece_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    delivered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source ENUM('order', 'manual') NOT NULL DEFAULT 'manual',
    PRIMARY KEY (piece_id, user_id),
    KEY idx_piece_owners_user (user_id),
    CONSTRAINT fk_piece_owners_piece FOREIGN KEY (piece_id) REFERENCES pieces (id) ON DELETE CASCADE,
    CONSTRAINT fk_piece_owners_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(32) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rate_events_lookup (action, key_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
