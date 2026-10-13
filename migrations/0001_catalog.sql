-- 0001: catalog tables (pieces, sizes, boxes, categories, settings).
-- Users, orders, commissions, comments and owners come AFTER the security design (docs/SECURITY.md).
-- Written for both MariaDB and MySQL 8: explicit utf8mb4_unicode_ci, no MySQL-8-only features.
-- All money is integer cents.

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shipping boxes. packed_weight_oz is the shipped weight used for USPS quotes (editable in the CMS).
CREATE TABLE IF NOT EXISTS boxes (
    id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(20) NOT NULL,
    long_in DECIMAL(5,2) NOT NULL,
    short_in DECIMAL(5,2) NOT NULL,
    depth_in DECIMAL(5,2) NOT NULL,
    packed_weight_oz DECIMAL(6,2) NOT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_boxes_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Canvas sizes, always stored long side first. offered = 1 means sold from stock or commissionable
-- today, so the size must fit a box. Retired sizes (offered = 0) only exist for display on past work.
CREATE TABLE IF NOT EXISTS canvas_sizes (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    long_in DECIMAL(5,2) NOT NULL,
    short_in DECIMAL(5,2) NOT NULL,
    label VARCHAR(20) NOT NULL,
    offered TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_canvas_sizes_dims (long_in, short_in)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Discounted is NOT a category: it is derived from price / active sale (see settings.discount_threshold_cents).
CREATE TABLE IF NOT EXISTS categories (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    parent_id SMALLINT UNSIGNED NULL,
    slug VARCHAR(40) NOT NULL,
    name VARCHAR(60) NOT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_categories_slug (slug),
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- id is the V1 pieceID (kept on import so old piece.php?id=N URLs map one-to-one).
CREATE TABLE IF NOT EXISTS pieces (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(120) NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    medium VARCHAR(100) NULL,
    canvas_size_id SMALLINT UNSIGNED NOT NULL,
    price_cents INT UNSIGNED NOT NULL,
    sale_price_cents INT UNSIGNED NULL,
    sale_ends_at DATETIME NULL,
    status ENUM('available', 'sold', 'in_studio', 'on_display') NOT NULL DEFAULT 'available',
    status_note VARCHAR(255) NULL,
    origin ENUM('original', 'commission') NOT NULL DEFAULT 'original',
    purchase_mode ENUM('buy', 'inquire') NOT NULL DEFAULT 'buy',
    print_url VARCHAR(500) NULL,
    painted_on DATE NULL,
    listed_at DATETIME NULL,
    sold_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_pieces_slug (slug),
    KEY idx_pieces_status (status, deleted_at),
    KEY idx_pieces_sold_at (sold_at),
    CONSTRAINT fk_pieces_canvas_size FOREIGN KEY (canvas_size_id) REFERENCES canvas_sizes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS piece_images (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    piece_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    kind ENUM('main', 'angle', 'detail') NOT NULL DEFAULT 'main',
    alt_text VARCHAR(255) NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    KEY idx_piece_images_piece (piece_id, sort_order),
    CONSTRAINT fk_piece_images_piece FOREIGN KEY (piece_id) REFERENCES pieces (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS piece_categories (
    piece_id INT UNSIGNED NOT NULL,
    category_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (piece_id, category_id),
    CONSTRAINT fk_piece_categories_piece FOREIGN KEY (piece_id) REFERENCES pieces (id) ON DELETE CASCADE,
    CONSTRAINT fk_piece_categories_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
