-- 0015: contact form log (90 days) and the sender block list managed in MIMOSA.
-- blocked_senders.value: lowercase email, lowercase domain (also matches its subdomains), or an IP in canonical text form.

CREATE TABLE IF NOT EXISTS contact_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    email VARCHAR(254) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    source VARCHAR(20) NOT NULL,
    excerpt VARCHAR(300) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_contact_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blocked_senders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('email', 'domain', 'ip') NOT NULL,
    value VARCHAR(254) NOT NULL,
    reason VARCHAR(200) NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    last_hit_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_blocked_senders (kind, value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
