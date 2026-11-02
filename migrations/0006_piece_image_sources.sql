CREATE TABLE IF NOT EXISTS piece_image_sources (
    image_id INT UNSIGNED NOT NULL PRIMARY KEY,
    base_file VARCHAR(255) NOT NULL,
    original_file VARCHAR(255) NULL,
    watermark TEXT NULL,
    watermarked_at DATETIME NULL,
    CONSTRAINT fk_piece_image_sources_image FOREIGN KEY (image_id) REFERENCES piece_images (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
