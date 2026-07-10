-- Data repair: products whose primary image was deleted via the admin "delete image"
-- button (before ProductModel::deleteImage() promoted a replacement) were left with no
-- product_images row flagged is_primary, hiding their thumbnail on the shop listing.
-- Idempotent: only touches products with images but none flagged primary.
UPDATE product_images pi
JOIN (
    SELECT id, product_id, ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY sort_order, id) AS rn
    FROM product_images
) ranked ON ranked.id = pi.id
LEFT JOIN (
    SELECT product_id FROM product_images WHERE is_primary = 1
) has_primary ON has_primary.product_id = ranked.product_id
SET pi.is_primary = 1
WHERE ranked.rn = 1 AND has_primary.product_id IS NULL;
