-- 0005: accounts, sessions, login throttling, password reset, recovery codes, audit log.
-- Written against docs/SECURITY.md. MariaDB and MySQL 8 compatible, idempotent, no JSON column type.
-- IPs are stored as VARBINARY(16) (inet_pton output). Session IDs and reset tokens are stored hashed.

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL,
    display_name VARCHAR(80) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('owner', 'admin', 'customer') NOT NULL DEFAULT 'customer',
    status ENUM('active', 'disabled', 'banned') NOT NULL DEFAULT 'active',
    email_verified_at DATETIME NULL,
    totp_secret_enc VARCHAR(512) NULL,
    totp_enrolled_at DATETIME NULL,
    totp_last_step BIGINT UNSIGNED NULL,
    password_changed_at DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- id_hash is the SHA-256 hex of the PHP session ID, so a database leak does not hand out live sessions.
CREATE TABLE IF NOT EXISTS sessions (
    id_hash CHAR(64) NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    data MEDIUMBLOB NOT NULL,
    ip VARBINARY(16) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sessions_user (user_id),
    KEY idx_sessions_activity (last_activity),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- identifier_hash is SHA-256 hex of the lowercased submitted email (no raw emails, works for unknown accounts too).
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    identifier_hash CHAR(64) NOT NULL,
    ip VARBINARY(16) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_identifier (identifier_hash, attempted_at),
    KEY idx_login_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- token_hash is SHA-256 hex of a 32-byte random token; the raw token only ever lives in the emailed link.
CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    requested_ip VARBINARY(16) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id, expires_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- code_hash is hashed with the same password hashing as passwords. Codes are shown once at enrolment.
CREATE TABLE IF NOT EXISTS recovery_codes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_recovery_codes_user (user_id, used_at),
    CONSTRAINT fk_recovery_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No foreign key on user_id: rows must survive account deletion. details is plain text (JSON written by the app); never secrets.
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id INT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    target_type VARCHAR(32) NULL,
    target_id VARCHAR(64) NULL,
    ip VARBINARY(16) NULL,
    user_agent VARCHAR(255) NULL,
    details TEXT NULL,
    KEY idx_audit_log_created (created_at),
    KEY idx_audit_log_user (user_id, created_at),
    KEY idx_audit_log_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
