CREATE TABLE IF NOT EXISTS piece_image_watermark_reviews (
    image_id INT UNSIGNED NOT NULL PRIMARY KEY,
    has_watermark TINYINT(1) NOT NULL,
    reviewed_at DATETIME NOT NULL,
    CONSTRAINT fk_piece_image_watermark_reviews_image FOREIGN KEY (image_id) REFERENCES piece_images (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;