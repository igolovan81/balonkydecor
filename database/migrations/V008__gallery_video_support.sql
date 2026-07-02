ALTER TABLE gallery_images
  ADD COLUMN media_type ENUM('image','video') NOT NULL DEFAULT 'image' AFTER filename;
