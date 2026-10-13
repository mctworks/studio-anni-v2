-- 0002: reference data. Idempotent (INSERT IGNORE against unique keys).

-- Boxes and packed weights: from V1 shipping_utils.php (4/15/2026). Weights are used as the shipped weight.
INSERT IGNORE INTO boxes (label, long_in, short_in, depth_in, packed_weight_oz, sort_order) VALUES
    ('10x17x2', 17, 10, 2, 4.50, 1),
    ('13x16x3', 16, 13, 3, 9.00, 2),
    ('14x18x5', 18, 14, 5, 12.00, 3),
    ('21x23x4', 23, 21, 4, 22.00, 4),
    ('21x31x4', 31, 21, 4, 23.00, 5);

-- Sizes offered today: the commission price list plus sizes currently in stock (all fit a box above).
INSERT IGNORE INTO canvas_sizes (long_in, short_in, label, offered) VALUES
    (7, 5, '5" x 7"', 1),
    (6, 6, '6" x 6"', 1),
    (8, 8, '8" x 8"', 1),
    (10, 8, '8" x 10"', 1),
    (12, 9, '9" x 12"', 1),
    (10, 10, '10" x 10"', 1),
    (14, 11, '11" x 14"', 1),
    (12, 12, '12" x 12"', 1),
    (16, 12, '12" x 16"', 1),
    (14, 14, '14" x 14"', 1),
    (16, 16, '16" x 16"', 1),
    (20, 16, '16" x 20"', 1),
    (20, 20, '20" x 20"', 1),
    (24, 12, '12" x 24"', 1),
    (24, 18, '18" x 24"', 1),
    (24, 20, '20" x 24"', 1);

INSERT IGNORE INTO categories (slug, name, sort_order) VALUES
    ('pets-animals', 'Pets & Animals', 1),
    ('space-nature', 'Space & Nature', 2),
    ('people', 'People', 3),
    ('fantasy', 'Fantasy', 4),
    ('land-cityscapes', 'Land & Cityscapes', 5);

INSERT IGNORE INTO categories (parent_id, slug, name, sort_order)
SELECT id, 'hands', 'Hands', 1 FROM categories WHERE slug = 'people';

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('discount_threshold_cents', '10000'),
    ('canvas_thickness_in', '1'),
    ('comments_hold_all', '1'),
    ('guestbook_hold_all', '1'),
    ('prints_enabled', '0');
