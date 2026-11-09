-- 0009: customer avatar. File name only; the 256px JPEG lives in public/media/avatars/.

ALTER TABLE customer_profiles ADD COLUMN avatar_file VARCHAR(40) NULL;
