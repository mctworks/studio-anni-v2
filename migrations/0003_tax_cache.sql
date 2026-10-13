-- 0003: Ziptax lookup cache. Keyed by a hash of the normalized destination address.
-- rate_ppm is the combined rate in parts per million (0.07 = 70000) so tax math stays integer.

CREATE TABLE IF NOT EXISTS tax_cache (
    address_hash CHAR(64) NOT NULL PRIMARY KEY,
    zip CHAR(5) NOT NULL,
    rate_ppm INT UNSIGNED NOT NULL,
    shipping_taxable TINYINT(1) NOT NULL,
    fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tax_cache_zip (zip, fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
