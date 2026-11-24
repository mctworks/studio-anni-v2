-- 0017: guestbook entries and piece comments. Both are held for approval (settings.guestbook_hold_all / comments_hold_all, seeded 1 in 0002).
-- guestbook_entries: one per account (unique user_id). piece_comments: any number. like_count is the public tally;
-- piece_comment_likes only stops one account liking the same comment twice and is never shown.

CREATE TABLE IF NOT EXISTS guestbook_entries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    reviewed_by INT UNSIGNED NULL,
    UNIQUE KEY uq_guestbook_user (user_id),
    KEY idx_guestbook_status (status, created_at),
    CONSTRAINT fk_guestbook_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS piece_comments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    piece_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    like_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    reviewed_by INT UNSIGNED NULL,
    KEY idx_piece_comments_piece (piece_id, status, created_at),
    KEY idx_piece_comments_user (user_id, created_at),
    KEY idx_piece_comments_status (status, created_at),
    CONSTRAINT fk_piece_comments_piece FOREIGN KEY (piece_id) REFERENCES pieces (id) ON DELETE CASCADE,
    CONSTRAINT fk_piece_comments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS piece_comment_likes (
    comment_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (comment_id, user_id),
    KEY idx_piece_comment_likes_user (user_id),
    CONSTRAINT fk_piece_comment_likes_comment FOREIGN KEY (comment_id) REFERENCES piece_comments (id) ON DELETE CASCADE,
    CONSTRAINT fk_piece_comment_likes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
