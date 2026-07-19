<?php
namespace App\Models;

class ProductModel
{
    public static function allActive(string $lang, ?int $categoryId = null): array
    {
        $pdo    = Database::getConnection();
        $sql    = '
            SELECT p.id, p.category_id, p.sku, p.price, p.stock_type, p.stock_qty,
                   COALESCE(t.name, p.sku) AS name,
                   t.description,
                   i.filename AS primary_image,
                   (SELECT MIN(price) FROM product_subtypes WHERE product_id = p.id) AS min_subtype_price
            FROM products p
            LEFT JOIN product_t t ON t.product_id = p.id AND t.lang_code = :lang
            LEFT JOIN product_images i ON i.product_id = p.id AND i.is_primary = 1
            WHERE p.is_active = 1
        ';
        $params = ['lang' => $lang];
        if ($categoryId !== null) {
            $sql .= ' AND p.category_id = :cat';
            $params['cat'] = $categoryId;
        }
        $sql .= ' ORDER BY p.sort_order, p.id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function findBySku(string $sku, string $lang): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('
            SELECT p.id, p.category_id, p.sku, p.price, p.stock_type, p.stock_qty,
                   COALESCE(t.name, p.sku) AS name,
                   t.description, t.meta_title, t.meta_desc
            FROM products p
            LEFT JOIN product_t t ON t.product_id = p.id AND t.lang_code = :lang
            WHERE p.sku = :sku AND p.is_active = 1
        ');
        $stmt->execute(['sku' => $sku, 'lang' => $lang]);
        $product = $stmt->fetch();
        if (!$product) {
            return null;
        }
        $imgs = $pdo->prepare('SELECT filename FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
        $imgs->execute([$product['id']]);
        $product['images'] = $imgs->fetchAll(\PDO::FETCH_COLUMN);

        $subStmt = $pdo->prepare(
            'SELECT ps.id, ps.price, st.name
             FROM product_subtypes ps
             JOIN product_subtype_t st ON st.subtype_id = ps.id AND st.lang_code = ?
             WHERE ps.product_id = ?
             ORDER BY ps.sort_order, ps.id'
        );
        $subStmt->execute([$lang, $product['id']]);
        $product['subtypes'] = $subStmt->fetchAll();

        return $product;
    }

    public static function all(string $lang): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT p.*,
                    (SELECT filename FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image,
                    COALESCE(t.name, p.sku) AS name,
                    COALESCE(ct.name, c.slug) AS category_name,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM products p
             LEFT JOIN product_t t ON t.product_id = p.id AND t.lang_code = :lang
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN category_t ct ON ct.category_id = p.category_id AND ct.lang_code = :lang2
             LEFT JOIN users creator ON creator.id = p.created_by
             LEFT JOIN users updater ON updater.id = p.updated_by
             ORDER BY p.id DESC'
        );
        $stmt->execute(['lang' => $lang, 'lang2' => $lang]);
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT p.*,
                    creator.email AS created_by_email,
                    updater.email AS updated_by_email
             FROM products p
             LEFT JOIN users creator ON creator.id = p.created_by
             LEFT JOIN users updater ON updater.id = p.updated_by
             WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        if (!$product) return null;
        $imgs = $pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
        $imgs->execute([$id]);
        $product['images'] = $imgs->fetchAll();
        $product['subtypes'] = self::getSubtypes($id);
        return $product;
    }

    public static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'product';
    }

    public static function uniqueSku(string $candidate): string
    {
        $pdo    = Database::getConnection();
        $stmt   = $pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ?');
        $sku    = $candidate;
        $suffix = 2;
        $stmt->execute([$sku]);
        while ((int) $stmt->fetchColumn() > 0) {
            $sku = $candidate . '-' . $suffix;
            $suffix++;
            $stmt->execute([$sku]);
        }
        return $sku;
    }

    public static function create(array $data, int $userId): int
    {
        $pdo       = Database::getConnection();
        $stockType = ($data['stock_type'] ?? '') === 'limited' ? 'limited' : 'unlimited';
        $stockQty  = $stockType === 'limited' ? max(0, (int) ($data['stock_qty'] ?? 0)) : 0;
        $stmt = $pdo->prepare(
            'INSERT INTO products (sku, price, category_id, is_active, stock_type, stock_qty, sort_order, created_by, updated_by)
             VALUES (:sku, :price, :category_id, :is_active, :stock_type, :stock_qty, 0, :created_by, :updated_by)'
        );
        $stmt->execute([
            'sku'         => $data['sku'],
            'price'       => $data['price'],
            'category_id' => $data['category_id'] ?: 1,
            'is_active'   => (int) ($data['is_active'] ?? 1),
            'stock_type'  => $stockType,
            'stock_qty'   => $stockQty,
            'created_by'  => $userId,
            'updated_by'  => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data, int $userId): void
    {
        $pdo       = Database::getConnection();
        $stockType = ($data['stock_type'] ?? '') === 'limited' ? 'limited' : 'unlimited';
        $stockQty  = $stockType === 'limited' ? max(0, (int) ($data['stock_qty'] ?? 0)) : 0;
        $stmt = $pdo->prepare(
            'UPDATE products SET sku = :sku, price = :price, category_id = :category_id, is_active = :is_active,
                                  stock_type = :stock_type, stock_qty = :stock_qty, updated_by = :updated_by WHERE id = :id'
        );
        $stmt->execute([
            'sku'         => $data['sku'],
            'price'       => $data['price'],
            'category_id' => $data['category_id'] ?: 1,
            'is_active'   => (int) ($data['is_active'] ?? 1),
            'stock_type'  => $stockType,
            'stock_qty'   => $stockQty,
            'updated_by'  => $userId,
            'id'          => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    }

    public static function getTranslations(int $id): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT lang_code, name, description, meta_title, meta_desc FROM product_t WHERE product_id = ?');
        $stmt->execute([$id]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['lang_code']] = $row;
        }
        return $result;
    }

    public static function setTranslations(int $id, array $translations): void
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO product_t (product_id, lang_code, name, description, meta_title, meta_desc)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
                                     meta_title = VALUES(meta_title), meta_desc = VALUES(meta_desc)'
        );
        foreach ($translations as $lang => $t) {
            if (empty($t['name'])) continue;
            $stmt->execute([$id, $lang, $t['name'], $t['description'] ?? '', $t['meta_title'] ?? null, $t['meta_desc'] ?? null]);
        }
    }

    public static function getSubtypes(int $productId): array
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, price, sort_order FROM product_subtypes WHERE product_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$productId]);
        $rows = $stmt->fetchAll();

        $tStmt = $pdo->prepare('SELECT lang_code, name FROM product_subtype_t WHERE subtype_id = ?');
        foreach ($rows as &$row) {
            $tStmt->execute([$row['id']]);
            $row['t'] = [];
            foreach ($tStmt->fetchAll() as $t) {
                $row['t'][$t['lang_code']] = $t['name'];
            }
        }
        unset($row);
        return $rows;
    }

    public static function setSubtypes(int $productId, array $rows): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM product_subtypes WHERE product_id = ?')->execute([$productId]);

        $insertSubtype = $pdo->prepare(
            'INSERT INTO product_subtypes (product_id, price, sort_order) VALUES (?, ?, ?)'
        );
        $insertName = $pdo->prepare(
            'INSERT INTO product_subtype_t (subtype_id, lang_code, name) VALUES (?, ?, ?)'
        );

        foreach (array_values($rows) as $index => $row) {
            $t = array_filter($row['t'] ?? [], fn ($name) => trim((string) $name) !== '');
            if (!$t) continue;

            $insertSubtype->execute([$productId, $row['price'] ?? '0.00', $index]);
            $subtypeId = (int) $pdo->lastInsertId();

            foreach ($t as $lang => $name) {
                $insertName->execute([$subtypeId, $lang, trim((string) $name)]);
            }
        }
    }

    public static function addImage(int $productId, string $filename, bool $isPrimary = false): void
    {
        $pdo = Database::getConnection();
        if (!$isPrimary) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = ?');
            $stmt->execute([$productId]);
            $isPrimary = ((int) $stmt->fetchColumn()) === 0;
        }
        if ($isPrimary) {
            $pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$productId]);
        }
        $pdo->prepare('INSERT INTO product_images (product_id, filename, is_primary, sort_order) VALUES (?, ?, ?, 0)')
            ->execute([$productId, $filename, $isPrimary ? 1 : 0]);
    }

    /** Find product_images rows whose files no longer exist in $uploadsDir. */
    public static function orphanedImages(string $uploadsDir): array
    {
        $rows = Database::getConnection()
            ->query('SELECT id, product_id, filename, is_primary FROM product_images ORDER BY product_id, id')
            ->fetchAll();
        return array_values(array_filter(
            $rows,
            fn (array $row) => !is_file($uploadsDir . '/' . $row['filename'])
        ));
    }

    /**
     * Delete orphaned product_images rows (plus leftover thumb_ files). Products left
     * without a primary image get their first remaining image promoted.
     * Returns ['deleted_images' => filenames, 'promoted_primaries' => product ids].
     */
    public static function cleanupOrphans(string $uploadsDir): array
    {
        $pdo     = Database::getConnection();
        $orphans = self::orphanedImages($uploadsDir);
        $report  = ['deleted_images' => [], 'promoted_primaries' => []];

        $deleteRow = $pdo->prepare('DELETE FROM product_images WHERE id = ?');
        $affected  = [];
        foreach ($orphans as $row) {
            $deleteRow->execute([$row['id']]);
            $thumb = $uploadsDir . '/thumb_' . $row['filename'];
            if (is_file($thumb)) {
                unlink($thumb);
            }
            $report['deleted_images'][] = $row['filename'];
            $affected[(int) $row['product_id']] = true;
        }

        $hasPrimary = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = ? AND is_primary = 1');
        $promote    = $pdo->prepare(
            'UPDATE product_images SET is_primary = 1 WHERE product_id = ? ORDER BY sort_order, id LIMIT 1'
        );
        foreach (array_keys($affected) as $productId) {
            $hasPrimary->execute([$productId]);
            if ((int) $hasPrimary->fetchColumn() === 0) {
                $promote->execute([$productId]);
                if ($promote->rowCount() > 0) {
                    $report['promoted_primaries'][] = $productId;
                }
            }
        }
        return $report;
    }

    public static function deleteImage(int $imageId): ?string
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare('SELECT product_id, filename, is_primary FROM product_images WHERE id = ?');
        $stmt->execute([$imageId]);
        $row  = $stmt->fetch();
        if (!$row) return null;

        $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);

        if ((int) $row['is_primary'] === 1) {
            $pdo->prepare(
                'UPDATE product_images SET is_primary = 1 WHERE product_id = ? ORDER BY sort_order, id LIMIT 1'
            )->execute([$row['product_id']]);
        }

        return $row['filename'];
    }

    public static function clone(int $id, int $userId, ?int $imageId = null): ?int
    {
        $source = self::findById($id);
        if (!$source) {
            return null;
        }

        $movedImage = null;
        if ($imageId !== null) {
            $movedImage = current(array_filter(
                $source['images'],
                fn ($img) => (int) $img['id'] === $imageId
            ));
            if (!$movedImage) {
                return null;
            }
        }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        $sku   = self::uniqueSku($source['sku']);
        $newId = self::create([
            'sku'         => $sku,
            'price'       => $source['price'],
            'category_id' => $source['category_id'],
            'is_active'   => 0,
            'stock_type'  => $source['stock_type'],
            'stock_qty'   => $source['stock_qty'],
        ], $userId);

        $translations = self::getTranslations($id);
        if ($translations) {
            self::setTranslations($newId, $translations);
        }

        if ($movedImage !== null) {
            $pdo->prepare('UPDATE product_images SET product_id = ?, is_primary = 1, sort_order = 0 WHERE id = ?')
                ->execute([$newId, $movedImage['id']]);

            if ((int) $movedImage['is_primary'] === 1) {
                $pdo->prepare(
                    'UPDATE product_images SET is_primary = 1 WHERE product_id = ? ORDER BY sort_order, id LIMIT 1'
                )->execute([$id]);
            }

            if ($source['subtypes']) {
                self::setSubtypes($newId, $source['subtypes']);
            }

            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_id = ?');
            $countStmt->execute([$id]);
            if ((int) $countStmt->fetchColumn() === 0) {
                $pdo->prepare('UPDATE products SET is_active = 0 WHERE id = ?')->execute([$id]);
            }
        }

        $pdo->commit();

        return $newId;
    }
}
